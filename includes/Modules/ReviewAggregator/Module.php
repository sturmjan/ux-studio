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
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_reviews (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						source VARCHAR(32) NOT NULL DEFAULT '',
						author VARCHAR(255) NOT NULL DEFAULT '',
						rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
						text LONGTEXT NULL,
						review_date DATETIME NULL,
						imported_at DATETIME NOT NULL,
						visible TINYINT(1) NOT NULL DEFAULT 1,
						PRIMARY KEY  (id),
						KEY source (source),
						KEY visible (visible)
					) {$charset};"
				);
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
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['source'] ) ) {
				continue;
			}
			$wpdb->insert(
				"{$wpdb->prefix}uxstudio_reviews",
				array(
					'source'      => sanitize_text_field( (string) $item['source'] ),
					'author'      => sanitize_text_field( (string) ( $item['author'] ?? '' ) ),
					'rating'      => max( 0, min( 5, absint( $item['rating'] ?? 0 ) ) ),
					'text'        => isset( $item['text'] ) ? sanitize_textarea_field( (string) $item['text'] ) : null,
					'review_date' => ! empty( $item['review_date'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $item['review_date'] ) ?: time() ) : null,
					'imported_at' => current_time( 'mysql' ),
					'visible'     => 1,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
			);
			++$fetched;
		}

		ContentSyncModule::log_sync( 'review-aggregator:fetch', 'success' );

		return array( 'fetched' => $fetched );
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
