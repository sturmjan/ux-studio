<?php
/**
 * Instagram Feed module - frontend gallery synchronized through the
 * Content Sync broker.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\InstagramFeed;

use UxStudio\Core\Broker;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;
use UxStudio\Modules\BaseModule;
use UxStudio\Modules\ContentSync\Module as ContentSyncModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy instagram-feed module (free+pro merged)
 * as a group-C module with its own SPA screen. Unlike the legacy module,
 * this site never talks to the Meta API directly and holds no Meta API
 * credentials: every sync goes through the central app via the content-sync
 * broker (see the class docblock on UxStudio\Core\Broker and
 * UxStudio\Modules\ContentSync\Module).
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_shortcode( 'uxstudio_instagram', array( $this, 'render_shortcode' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'instagram-feed',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_instagram_feeds (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						name VARCHAR(255) NOT NULL DEFAULT '',
						settings LONGTEXT NULL,
						PRIMARY KEY  (id)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_instagram_media (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						media_url VARCHAR(1000) NOT NULL DEFAULT '',
						permalink VARCHAR(1000) NOT NULL DEFAULT '',
						caption TEXT NULL,
						synced_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						KEY feed_id (feed_id)
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
				'key'     => 'sync_interval_hours',
				'type'    => 'number',
				'label'   => __( 'Sync interval (hours)', 'ux-studio' ),
				'help'    => __( 'How often the feed is refreshed from the central app.', 'ux-studio' ),
				'default' => 6,
			),
			array(
				'key'     => 'display_count',
				'type'    => 'number',
				'label'   => __( 'Number of posts to display', 'ux-studio' ),
				'default' => 9,
			),
		);
	}

	/**
	 * Cached media, newest first, limited to the configured display count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_media(): array {
		global $wpdb;
		$limit = max( 1, (int) $this->settings->get( 'display_count', 9 ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, feed_id, media_url, permalink, caption, synced_at FROM {$wpdb->prefix}uxstudio_instagram_media ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map(
			static function ( array $row ): array {
				$row['id']      = (int) $row['id'];
				$row['feed_id'] = (int) $row['feed_id'];
				return $row;
			},
			$rows
		);
	}

	/**
	 * Ask the central app (via the content-sync broker) for the latest media
	 * and cache it locally.
	 *
	 * @return array{synced:int}|WP_Error
	 */
	public function sync() {
		list( $central_url, $secret ) = $this->broker_credentials();

		$result = Broker::call( $central_url, $secret, '/api/instagram/media', array( 'site' => home_url( '/' ) ) );

		if ( is_wp_error( $result ) ) {
			ContentSyncModule::log_sync( 'instagram-feed:sync', 'error' );
			return $result;
		}

		$items = is_array( $result['media'] ?? null ) ? $result['media'] : array();

		global $wpdb;
		$synced = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['media_url'] ) ) {
				continue;
			}
			$wpdb->insert(
				"{$wpdb->prefix}uxstudio_instagram_media",
				array(
					'feed_id'   => absint( $item['feed_id'] ?? 0 ),
					'media_url' => esc_url_raw( (string) $item['media_url'] ),
					'permalink' => esc_url_raw( (string) ( $item['permalink'] ?? '' ) ),
					'caption'   => isset( $item['caption'] ) ? sanitize_textarea_field( (string) $item['caption'] ) : null,
					'synced_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
			++$synced;
		}

		ContentSyncModule::log_sync( 'instagram-feed:sync', 'success' );

		return array( 'synced' => $synced );
	}

	/**
	 * Frontend shortcode: a simple grid gallery rendered from the local cache.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		$media = $this->list_media();
		if ( empty( $media ) ) {
			return '';
		}

		$html = '<div class="uxstudio-instagram-grid">';
		foreach ( $media as $item ) {
			$html .= sprintf(
				'<a class="uxstudio-instagram-grid__item" href="%1$s" target="_blank" rel="noopener noreferrer"><img src="%2$s" alt="%3$s" loading="lazy" /></a>',
				esc_url( (string) $item['permalink'] ),
				esc_url( (string) $item['media_url'] ),
				esc_attr( (string) ( $item['caption'] ?? '' ) )
			);
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Read the shared broker credentials from the content-sync module's
	 * settings (see the class-level contract documented on
	 * UxStudio\Modules\ContentSync\Module). This module never stores its
	 * own copy of the URL or secret.
	 *
	 * @return array{0:string,1:string} [central_app_url, hmac_secret].
	 */
	private function broker_credentials(): array {
		$content_sync_settings = new Settings( 'uxstudio_content_sync' );
		$central_url            = (string) $content_sync_settings->get( 'central_app_url', '' );
		$hmac_secret             = Security::get_secret( ContentSyncModule::SECRET_HMAC );
		return array( $central_url, $hmac_secret );
	}
}
