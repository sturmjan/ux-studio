<?php
/**
 * Review Aggregator module - reviews pulled through the Content Sync broker.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ReviewAggregator;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Broker;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\AiAssistant\ProviderFactory;
use UxStudio\Modules\AiAssistant\UsageTracker;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy review-aggregator module (free+pro
 * merged) as a group-C module with its own SPA screen. Unlike the legacy
 * module there is no local scraping (Google/Facebook/Mapy.cz scrapers) and
 * no API keys on this site - review fetching is delegated entirely to the
 * central app via the content-sync broker (see UxStudio\Core\Broker and
 * UxStudio\Modules\ContentSync\Module).
 *
 * IMPORTANT: the uxstudio_reviews table is never dropped, including on
 * module deactivation - the legacy module lost data this way. There is
 * deliberately no deactivation hook in this module.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_shortcode( 'uxstudio_reviews', array( $this, 'render_shortcode' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'review-aggregator',
			2,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				// v2 adds dedup_hash + a unique key on it, so repeated fetch()
				// calls upsert instead of duplicating every review already
				// imported (see Module::fetch()). dbDelta() diffs this full
				// definition against the existing table on upgrade too.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_reviews (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						source VARCHAR(32) NOT NULL DEFAULT '',
						external_id VARCHAR(191) NOT NULL DEFAULT '',
						dedup_hash VARCHAR(64) NOT NULL DEFAULT '',
						author VARCHAR(255) NOT NULL DEFAULT '',
						rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
						text LONGTEXT NULL,
						review_date DATETIME NULL,
						imported_at DATETIME NOT NULL,
						visible TINYINT(1) NOT NULL DEFAULT 1,
						PRIMARY KEY  (id),
						UNIQUE KEY dedup_hash (dedup_hash),
						KEY source (source),
						KEY visible (visible)
					) {$charset};"
				);

				if ( $from < 2 ) {
					// Backfill dedup_hash for any rows imported before v2 existed,
					// so the new unique key doesn't collide on empty strings.
					$wpdb->query(
						"UPDATE {$wpdb->prefix}uxstudio_reviews
						 SET dedup_hash = SHA1(CONCAT(source, '|', author, '|', COALESCE(review_date, ''), '|', COALESCE(text, ''), '|', id))
						 WHERE dedup_hash = ''"
					);
				}
			}
		);
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'min_rating',
				'type'    => 'number',
				'label'   => __( 'Minimum rating to display', 'ux-studio' ),
				'help'    => __( 'Reviews below this rating are still stored but hidden from the shortcode output.', 'ux-studio' ),
				'default' => 1,
			),
			array(
				'key'     => 'auto_fetch',
				'type'    => 'toggle',
				'label'   => __( 'Auto-fetch new reviews', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'central_profile_id',
				'type'    => 'text',
				'label'   => __( 'Central app profile ID', 'ux-studio' ),
				'help'    => __( 'Identifies which review profile the central app should fetch for this site.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * List reviews, optionally filtered.
	 *
	 * @param array{source?:string,visible?:bool,min_rating?:int} $filters Optional filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_reviews( array $filters = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = (string) $filters['source'];
		}
		if ( array_key_exists( 'visible', $filters ) ) {
			$where[]  = 'visible = %d';
			$params[] = $filters['visible'] ? 1 : 0;
		}
		if ( ! empty( $filters['min_rating'] ) ) {
			$where[]  = 'rating >= %d';
			$params[] = (int) $filters['min_rating'];
		}

		$sql = "SELECT id, source, author, rating, text, review_date, imported_at, visible FROM {$wpdb->prefix}uxstudio_reviews WHERE " . implode( ' AND ', $where ) . ' ORDER BY review_date DESC, id DESC LIMIT 200';

		$rows = empty( $params )
			? $wpdb->get_results( $sql, ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_row' ), $rows );
	}

	/**
	 * One review by id.
	 *
	 * @param int $id Row id.
	 */
	public function get_review( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source, author, rating, text, review_date, imported_at, visible FROM {$wpdb->prefix}uxstudio_reviews WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_row( $row ) : null;
	}

	/**
	 * Toggle a review's visibility.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function toggle_visibility( int $id ) {
		global $wpdb;

		$existing = $this->get_review( $id );
		if ( null === $existing ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Review not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$wpdb->update(
			"{$wpdb->prefix}uxstudio_reviews",
			array( 'visible' => $existing['visible'] ? 0 : 1 ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		ActivityLog::log( 'review-aggregator', 'toggle_visibility', 'review', $id );

		return (array) $this->get_review( $id );
	}

	/**
	 * Aggregate stats: total, average rating, per-source counts.
	 *
	 * @return array<string, mixed>
	 */
	public function stats(): array {
		global $wpdb;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_reviews" );
		$avg   = (float) $wpdb->get_var( "SELECT AVG(rating) FROM {$wpdb->prefix}uxstudio_reviews" );

		$per_source = $wpdb->get_results(
			"SELECT source, COUNT(*) as count, AVG(rating) as avg_rating FROM {$wpdb->prefix}uxstudio_reviews GROUP BY source",
			ARRAY_A
		);
		$per_source = is_array( $per_source ) ? $per_source : array();

		return array(
			'total'       => $total,
			'average'     => round( $avg, 2 ),
			'by_source'   => array_map(
				static function ( array $row ): array {
					return array(
						'source'     => $row['source'],
						'count'      => (int) $row['count'],
						'average'    => round( (float) $row['avg_rating'], 2 ),
					);
				},
				$per_source
			),
		);
	}

	/**
	 * Ask the central app (via the content-sync broker) for the latest
	 * reviews for the configured profile and store them locally.
	 *
	 * @return array{fetched:int}|WP_Error
	 */
	public function fetch() {
		list( $central_url, $secret ) = $this->broker_credentials();

		$profile_id = (string) $this->settings->get( 'central_profile_id', '' );

		$result = Broker::call(
			$central_url,
			$secret,
			'/api/reviews/fetch',
			array(
				'site'       => home_url( '/' ),
				'profile_id' => $profile_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			ContentSyncModule::log_sync( 'review-aggregator:fetch', 'error' );
			return $result;
		}

		$items = is_array( $result['reviews'] ?? null ) ? $result['reviews'] : array();

		global $wpdb;
		$fetched = 0;
		$updated = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['source'] ) ) {
				continue;
			}

			$source      = sanitize_text_field( (string) $item['source'] );
			$external_id = sanitize_text_field( (string) ( $item['external_id'] ?? '' ) );
			$author      = sanitize_text_field( (string) ( $item['author'] ?? '' ) );
			$text        = isset( $item['text'] ) ? sanitize_textarea_field( (string) $item['text'] ) : '';
			$review_date = ! empty( $item['review_date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $item['review_date'] ) ?: time() ) : '';

			// Stable dedup key: prefer external_id from the source (same
			// approach as the central app's own Review model), fall back to
			// a content hash when the broker doesn't provide one so repeated
			// fetches of the same review never insert a duplicate row.
			$dedup_hash = sha1(
				$source . '|' . ( '' !== $external_id ? 'ext:' . $external_id : 'sig:' . $author . '|' . $review_date . '|' . $text )
			);

			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uxstudio_reviews WHERE dedup_hash = %s", $dedup_hash )
			);

			$row = array(
				'source'      => $source,
				'external_id' => $external_id,
				'dedup_hash'  => $dedup_hash,
				'author'      => $author,
				'rating'      => max( 0, min( 5, absint( $item['rating'] ?? 0 ) ) ),
				'text'        => '' !== $text ? $text : null,
				'review_date' => '' !== $review_date ? $review_date : null,
				'imported_at' => current_time( 'mysql' ),
			);
			$formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );

			if ( $existing_id > 0 ) {
				// Update content/rating (owner replies, edits) but never
				// touch `visible` - that is a local moderation decision.
				$wpdb->update( "{$wpdb->prefix}uxstudio_reviews", $row, array( 'id' => $existing_id ), $formats, array( '%d' ) );
				++$updated;
			} else {
				$row['visible'] = 1;
				$formats[]      = '%d';
				$wpdb->insert( "{$wpdb->prefix}uxstudio_reviews", $row, $formats );
				++$fetched;
			}
		}

		ContentSyncModule::log_sync( 'review-aggregator:fetch', 'success' );

		return array( 'fetched' => $fetched, 'updated' => $updated );
	}

	/**
	 * AI-drafted reply suggestion for one review (Rank Math Content AI's
	 * "Comment Reply"/"Testimonial" tools, applied to an imported review).
	 * This module has no write-back channel to Google/Facebook/etc., so the
	 * result is a draft for the admin to copy - never posted automatically.
	 *
	 * @return array{suggestion:string}|WP_Error
	 */
	public function suggest_reply( int $id ) {
		if ( ! class_exists( ProviderFactory::class ) ) {
			return new WP_Error( 'uxstudio_ai_unavailable', __( 'AI Assistant module is required for reply suggestions.', 'ux-studio' ), array( 'status' => 424 ) );
		}

		$review = $this->get_review( $id );
		if ( null === $review ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Review not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$stars = str_repeat( '★', max( 0, (int) $review['rating'] ) ) . str_repeat( '☆', 5 - max( 0, (int) $review['rating'] ) );
		$system_prompt = "You write short, genuine-sounding public replies to customer reviews on behalf of \"" . get_bloginfo( 'name' ) . "\".\n"
			. "Rules: 2-4 sentences, match the review's language, thank the author by name if given, address their specific point, "
			. "never invent facts not present in the review, stay professional even for negative reviews, no generic filler.\n"
			. "Reply with the reply text only - no preamble, no quotes around it.";
		$user_prompt = sprintf(
			"Source: %s\nRating: %s (%d/5)\nAuthor: %s\nReview text:\n%s",
			$review['source'],
			$stars,
			(int) $review['rating'],
			'' !== $review['author'] ? $review['author'] : __( 'Anonymous', 'ux-studio' ),
			'' !== (string) $review['text'] ? (string) $review['text'] : __( '(no text, rating only)', 'ux-studio' )
		);

		try {
			$provider    = ProviderFactory::create();
			$provider_id = $provider->get_id();
			$settings    = new Settings( 'uxstudio_ai_assistant' );
			$model       = (string) $settings->get( $provider_id . '_model', '' );
			if ( '' === $model ) {
				$model = array_key_first( $provider->get_models() );
			}

			$result = $provider->generate_content(
				$system_prompt,
				$user_prompt,
				$model,
				array( 'max_tokens' => 400, 'temperature' => 0.6 )
			);

			$usage = $result['usage'] ?? array( 'input_tokens' => 0, 'output_tokens' => 0 );
			if ( class_exists( UsageTracker::class ) ) {
				UsageTracker::log( $provider_id, $model, 'review_reply', (int) ( $usage['input_tokens'] ?? 0 ), (int) ( $usage['output_tokens'] ?? 0 ) );
			}

			ActivityLog::log( 'review-aggregator', 'suggest_reply', 'review', $id );

			return array( 'suggestion' => trim( (string) ( $result['content'] ?? '' ) ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'uxstudio_ai_error', $e->getMessage(), array( 'status' => 502 ) );
		}
	}

	/**
	 * Frontend shortcode: visible reviews at/above the configured minimum
	 * rating, rendered as a simple list.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		$min_rating = (int) $this->settings->get( 'min_rating', 1 );
		$reviews    = $this->list_reviews(
			array(
				'visible'    => true,
				'min_rating' => $min_rating,
			)
		);

		if ( empty( $reviews ) ) {
			return '';
		}

		$html = '<div class="uxstudio-reviews">';
		foreach ( $reviews as $review ) {
			$html .= sprintf(
				'<div class="uxstudio-reviews__item"><strong>%1$s</strong> <span class="uxstudio-reviews__rating">%2$s</span><p>%3$s</p></div>',
				esc_html( (string) $review['author'] ),
				esc_html( str_repeat( '★', (int) $review['rating'] ) ),
				esc_html( (string) ( $review['text'] ?? '' ) )
			);
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Read the shared broker credentials from the content-sync module's
	 * settings (see the class-level contract documented on
	 * UxStudio\Modules\ContentSync\Module). This module never stores its
	 * own copy of the URL or secret and holds no API keys of its own.
	 *
	 * @return array{0:string,1:string} [central_app_url, hmac_secret].
	 */
	private function broker_credentials(): array {
		$content_sync_settings = new Settings( 'uxstudio_content_sync' );
		$central_url            = (string) $content_sync_settings->get( 'central_app_url', '' );
		$hmac_secret             = Security::get_secret( ContentSyncModule::SECRET_HMAC );
		return array( $central_url, $hmac_secret );
	}

	/**
	 * Normalize a raw DB row for REST output (types).
	 *
	 * @param array $row Raw row from $wpdb.
	 * @return array<string, mixed>
	 */
	private function format_row( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'source'      => $row['source'],
			'author'      => $row['author'],
			'rating'      => (int) $row['rating'],
			'text'        => $row['text'],
			'review_date' => $row['review_date'],
			'imported_at' => $row['imported_at'],
			'visible'     => (bool) $row['visible'],
		);
	}
}
