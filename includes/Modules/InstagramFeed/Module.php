<?php
/**
 * Instagram Feed module - direct Instagram Graph API integration with OAuth
 * connection management, media sideloading, cron refresh and themed feeds.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\InstagramFeed;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy ux1 instagram-feed module. The legacy module proxied
 * every Meta call through a central "broker" app (it stored no Meta
 * credentials on the site). This rewrite is deliberately STANDALONE: it talks
 * to the Instagram Graph API directly, so it has no runtime dependency on the
 * Content Sync broker or a central application. The Instagram app secret and
 * the long-lived access token are stored encrypted via UxStudio\Core\Security
 * and never leave the server (only has_* booleans are exposed over REST).
 *
 * Feature-detected gracefully: with no token configured nothing fatals - the
 * SPA shows a "connect" state and the shortcode renders an admin-only notice.
 */
final class Module extends BaseModule {

	private const SECRET_APP_SECRET = 'uxstudio_secret_instagram_app_secret';
	private const SECRET_TOKEN      = 'uxstudio_secret_instagram_token';
	private const OPTION_CONNECTION = 'uxstudio_instagram_connection';
	private const CRON_HOOK         = 'uxstudio_instagram_feed_cron';
	private const OAUTH_ACTION      = 'uxstudio_ig_oauth_return';
	private const STATE_PREFIX      = 'uxstudio_ig_oauth_state_';
	private const STATE_TTL         = 900;
	private const META_IG_ID        = '_uxstudio_ig_media_id';
	private const META_SOURCE       = '_uxstudio_ig_source_url';
	private const MAX_BYTES         = 52428800; // 50 MB.

	/** Display themes (key => label). */
	public const THEMES = array(
		'grid'      => 'Grid',
		'masonry'   => 'Masonry',
		'carousel'  => 'Carousel',
		'slider'    => 'Slider',
		'highlight' => 'Highlight',
		'showcase'  => 'Showcase',
	);

	/** Whether the frontend <style> block has already been printed this request. */
	private static bool $style_printed = false;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_post_' . self::OAUTH_ACTION, array( $this, 'handle_oauth_return' ) );
		add_action( self::CRON_HOOK, array( $this, 'cron_run' ) );
		add_shortcode( 'uxstudio_instagram', array( $this, 'render_shortcode' ) );

		$this->ensure_schedule();

		\UxStudio\Core\DB::ensure_module_tables(
			'instagram-feed',
			2,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				// Feeds: display definitions. Config is stored as JSON in `settings`.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_instagram_feeds (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						name VARCHAR(255) NOT NULL DEFAULT '',
						settings LONGTEXT NULL,
						PRIMARY KEY  (id)
					) {$charset};"
				);
				// Media cache. New columns (instagram_id, media_type, ...) are added
				// on upgrade by dbDelta; legacy columns (feed_id) are preserved.
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_instagram_media (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						feed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						instagram_id VARCHAR(64) NOT NULL DEFAULT '',
						media_type VARCHAR(32) NOT NULL DEFAULT 'IMAGE',
						media_url VARCHAR(1000) NOT NULL DEFAULT '',
						thumbnail_url VARCHAR(1000) NOT NULL DEFAULT '',
						permalink VARCHAR(1000) NOT NULL DEFAULT '',
						caption TEXT NULL,
						hashtags TEXT NULL,
						attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						video_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
						media_timestamp DATETIME NULL,
						like_count INT NULL,
						comments_count INT NULL,
						is_hidden TINYINT(1) NOT NULL DEFAULT 0,
						synced_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						KEY instagram_id (instagram_id),
						KEY media_timestamp (media_timestamp)
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
	 * Settings schema for the generic renderer / embedded Settings tab. The app
	 * secret is write-only (routed to Security::store_secret in save_settings).
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'app_id',
				'type'    => 'text',
				'label'   => __( 'Instagram app ID', 'ux-studio' ),
				'help'    => __( 'The client_id of your Instagram app (Meta App Dashboard → Instagram → API setup with Instagram login).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'app_secret',
				'type'    => 'text',
				'label'   => __( 'Instagram app secret', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'sync_interval',
				'type'    => 'select',
				'label'   => __( 'Sync interval', 'ux-studio' ),
				'help'    => __( 'How often media is refreshed from Instagram via WP-Cron.', 'ux-studio' ),
				'default' => 'hourly',
				'options' => array(
					'hourly'     => __( 'Hourly', 'ux-studio' ),
					'twicedaily' => __( 'Twice daily', 'ux-studio' ),
					'daily'      => __( 'Daily', 'ux-studio' ),
				),
			),
			array(
				'key'     => 'sync_limit',
				'type'    => 'number',
				'label'   => __( 'Items per sync', 'ux-studio' ),
				'help'    => __( 'Maximum number of recent posts pulled per sync (1-100).', 'ux-studio' ),
				'default' => 25,
			),
		);
	}

	/**
	 * Intercept the app secret before it reaches the plain settings option;
	 * reschedule cron if the interval changed. Everything else goes through the
	 * normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'app_secret', $input ) && '' !== (string) $input['app_secret'] ) {
			Security::store_secret( self::SECRET_APP_SECRET, (string) $input['app_secret'] );
		}
		unset( $input['app_secret'] );

		$values = parent::save_settings( $input );

		$this->reschedule();

		return $values;
	}

	/**
	 * Never leak secrets back to the client; expose only booleans.
	 */
	public function settings_values(): array {
		$values                   = parent::settings_values();
		$values['app_secret']     = '';
		$values['has_app_secret'] = '' !== Security::get_secret( self::SECRET_APP_SECRET );
		return $values;
	}

	// ─── Connection / OAuth ─────────────────────────────────────────────

	/**
	 * Connection + module status for the SPA header.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$connection = $this->get_connection();
		$app_id     = (string) $this->settings->get( 'app_id', '' );

		return array(
			'connected'             => '' !== $this->get_token() && ! empty( $connection ),
			'username'              => (string) ( $connection['username'] ?? '' ),
			'account_type'          => (string) ( $connection['account_type'] ?? '' ),
			'connected_at'          => (string) ( $connection['connected_at'] ?? '' ),
			'token_expires_at'      => (string) ( $connection['token_expires_at'] ?? '' ),
			'has_app_id'            => '' !== $app_id,
			'has_app_secret'        => '' !== Security::get_secret( self::SECRET_APP_SECRET ),
			'media_count'           => $this->media_count(),
			'redirect_uri'          => $this->redirect_uri(),
		);
	}

	/**
	 * Build the browser authorization URL. Requires app id + secret configured.
	 *
	 * @return array{auth_url:string}|WP_Error
	 */
	public function build_auth_url() {
		$app_id     = (string) $this->settings->get( 'app_id', '' );
		$app_secret = Security::get_secret( self::SECRET_APP_SECRET );

		if ( '' === $app_id || '' === $app_secret ) {
			return new WP_Error(
				'uxstudio_ig_not_configured',
				__( 'Set the Instagram app ID and app secret in Settings before connecting.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$state = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::STATE_PREFIX . $state,
			array( 'user_id' => get_current_user_id() ),
			self::STATE_TTL
		);

		$url = ( new InstagramClient() )->authorize_url( $app_id, $this->redirect_uri(), $state );

		return array( 'auth_url' => $url );
	}

	/**
	 * OAuth redirect handler (admin_post). Verifies state, exchanges the code
	 * for a long-lived token, stores it encrypted and records the connection,
	 * then redirects back to the SPA. Never fatals.
	 */
	public function handle_oauth_return(): void {
		$redirect_admin = admin_url( 'admin.php?page=ux-studio' ) . '#/module?id=instagram-feed';

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ux-studio' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['error'] ) ) : '';

		if ( '' !== $error ) {
			$this->oauth_fail( $redirect_admin, $error );
		}
		if ( '' === $state || '' === $code ) {
			$this->oauth_fail( $redirect_admin, 'missing_params' );
		}

		$stored = get_transient( self::STATE_PREFIX . $state );
		if ( ! is_array( $stored ) ) {
			$this->oauth_fail( $redirect_admin, 'bad_state' );
		}
		delete_transient( self::STATE_PREFIX . $state );

		if ( (int) ( $stored['user_id'] ?? 0 ) !== get_current_user_id() ) {
			$this->oauth_fail( $redirect_admin, 'user_mismatch' );
		}

		$app_id     = (string) $this->settings->get( 'app_id', '' );
		$app_secret = Security::get_secret( self::SECRET_APP_SECRET );
		$client     = new InstagramClient();

		$short = $client->exchange_code( $app_id, $app_secret, $this->redirect_uri(), $code );
		if ( $short instanceof WP_Error ) {
			$this->oauth_fail( $redirect_admin, $short->get_error_code() );
		}

		$long = $client->exchange_long_lived( $app_secret, $short['access_token'] );
		// Long-lived exchange failing is non-fatal - fall back to the short token.
		$token      = $long instanceof WP_Error ? $short['access_token'] : $long['access_token'];
		$expires_in = $long instanceof WP_Error ? 0 : $long['expires_in'];

		Security::store_secret( self::SECRET_TOKEN, $token );

		$profile = $client->fetch_profile( $token );
		$profile = $profile instanceof WP_Error ? array() : $profile;

		$this->set_connection(
			array(
				'ig_user_id'       => (string) ( $profile['id'] ?? $short['user_id'] ),
				'username'         => (string) ( $profile['username'] ?? '' ),
				'account_type'     => (string) ( $profile['account_type'] ?? '' ),
				'connected_at'     => current_time( 'mysql', true ),
				'token_expires_at' => $expires_in > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expires_in ) : '',
			)
		);

		ActivityLog::log( 'instagram-feed', 'connected', 'account', 0, array( 'username' => (string) ( $profile['username'] ?? '' ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ux-studio&ig_connected=1' ) . '#/module?id=instagram-feed' );
		exit;
	}

	/**
	 * Redirect back to the SPA with an error code and stop. Never returns.
	 *
	 * @param string $redirect_admin Base admin URL (with fragment).
	 * @param string $code           Short error code.
	 */
	private function oauth_fail( string $redirect_admin, string $code ): void {
		unset( $redirect_admin );
		wp_safe_redirect( admin_url( 'admin.php?page=ux-studio&ig_error=' . rawurlencode( $code ) ) . '#/module?id=instagram-feed' );
		exit;
	}

	/**
	 * Disconnect: forget the token + connection and clear cached media.
	 *
	 * @return array{connected:bool}
	 */
	public function disconnect(): array {
		Security::store_secret( self::SECRET_TOKEN, '' );
		delete_option( self::OPTION_CONNECTION );

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}uxstudio_instagram_media" );

		ActivityLog::log( 'instagram-feed', 'disconnected', 'account', 0 );

		return array( 'connected' => false );
	}

	// ─── Sync ────────────────────────────────────────────────────────────

	/**
	 * Pull recent media from Instagram and upsert/sideload it locally.
	 *
	 * @return array{fetched:int,new:int,updated:int,failed:int}|WP_Error
	 */
	public function sync() {
		$token = $this->get_token();
		if ( '' === $token ) {
			return new WP_Error(
				'uxstudio_ig_not_connected',
				__( 'No Instagram account connected. Connect an account first.', 'ux-studio' ),
				array( 'status' => 400 )
			);
		}

		$limit = max( 1, min( 100, (int) $this->settings->get( 'sync_limit', 25 ) ) );
		$items = ( new InstagramClient() )->fetch_media( $token, $limit );
		if ( $items instanceof WP_Error ) {
			ActivityLog::log( 'instagram-feed', 'sync_error', 'account', 0, array( 'message' => $items->get_error_message() ) );
			return $items;
		}

		$stats = array(
			'fetched' => count( $items ),
			'new'     => 0,
			'updated' => 0,
			'failed'  => 0,
		);

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				++$stats['failed'];
				continue;
			}
			try {
				$result = $this->upsert_media( $item );
				if ( 'new' === $result ) {
					++$stats['new'];
				} elseif ( 'updated' === $result ) {
					++$stats['updated'];
				}
			} catch ( \Throwable $e ) {
				++$stats['failed'];
			}
		}

		ActivityLog::log( 'instagram-feed', 'sync', 'account', 0, $stats );

		return $stats;
	}

	/**
	 * Cron callback: refresh a soon-to-expire token, then sync.
	 */
	public function cron_run(): void {
		$this->maybe_refresh_token();
		$result = $this->sync();
		unset( $result );
	}

	/**
	 * Refresh the long-lived token when it is within 14 days of expiry.
	 */
	private function maybe_refresh_token(): void {
		$token = $this->get_token();
		if ( '' === $token ) {
			return;
		}
		$connection = $this->get_connection();
		$expires    = (string) ( $connection['token_expires_at'] ?? '' );
		if ( '' === $expires ) {
			return;
		}
		$expires_ts = strtotime( $expires . ' UTC' );
		if ( false === $expires_ts || $expires_ts - time() > 14 * DAY_IN_SECONDS ) {
			return;
		}

		$refreshed = ( new InstagramClient() )->refresh( $token );
		if ( $refreshed instanceof WP_Error ) {
			return;
		}
		Security::store_secret( self::SECRET_TOKEN, $refreshed['access_token'] );
		$connection['token_expires_at'] = $refreshed['expires_in'] > 0
			? gmdate( 'Y-m-d H:i:s', time() + $refreshed['expires_in'] )
			: $expires;
		$this->set_connection( $connection );
	}

	// ─── Media persistence + sideload ─────────────────────────────────────

	/**
	 * Upsert one Graph media item by its instagram id, sideloading assets.
	 *
	 * @param array $item Raw Graph API item.
	 * @return 'new'|'updated'|'skip'
	 */
	private function upsert_media( array $item ): string {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_instagram_media";

		$instagram_id = (string) ( $item['id'] ?? '' );
		if ( '' === $instagram_id ) {
			return 'skip';
		}

		$type          = (string) ( $item['media_type'] ?? 'IMAGE' );
		$media_url     = (string) ( $item['media_url'] ?? '' );
		$thumbnail_url = (string) ( $item['thumbnail_url'] ?? '' );
		$permalink     = (string) ( $item['permalink'] ?? '' );
		$caption       = isset( $item['caption'] ) ? (string) $item['caption'] : '';
		$hashtags      = $this->extract_hashtags( $caption );

		$timestamp = isset( $item['timestamp'] ) ? strtotime( (string) $item['timestamp'] ) : false;
		$mysql_ts  = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : null;

		$existing = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, attachment_id, video_attachment_id FROM {$table} WHERE instagram_id = %s", $instagram_id ),
			ARRAY_A
		);
		$is_new = null === $existing;

		// Sideload only when we do not yet have a local attachment.
		$attachment_id       = (int) ( $existing['attachment_id'] ?? 0 );
		$video_attachment_id = (int) ( $existing['video_attachment_id'] ?? 0 );

		if ( 0 === $attachment_id ) {
			if ( ( 'VIDEO' === $type || 'REELS' === $type ) && '' !== $thumbnail_url ) {
				$attachment_id = (int) $this->sideload( $thumbnail_url, $instagram_id . '-thumb' );
			} elseif ( '' !== $media_url ) {
				$attachment_id = (int) $this->sideload( $media_url, $instagram_id );
			}
		}
		if ( 0 === $video_attachment_id && ( 'VIDEO' === $type || 'REELS' === $type ) && '' !== $media_url ) {
			$video_attachment_id = (int) $this->sideload( $media_url, $instagram_id . '-video' );
		}

		$row = array(
			'instagram_id'        => $instagram_id,
			'media_type'          => $type,
			'media_url'           => esc_url_raw( $media_url ),
			'thumbnail_url'       => esc_url_raw( $thumbnail_url ),
			'permalink'           => esc_url_raw( $permalink ),
			'caption'             => '' !== $caption ? $caption : null,
			'hashtags'            => empty( $hashtags ) ? null : wp_json_encode( $hashtags ),
			'attachment_id'       => $attachment_id,
			'video_attachment_id' => $video_attachment_id,
			'media_timestamp'     => $mysql_ts,
			'like_count'          => isset( $item['like_count'] ) ? (int) $item['like_count'] : null,
			'comments_count'      => isset( $item['comments_count'] ) ? (int) $item['comments_count'] : null,
			'synced_at'           => current_time( 'mysql', true ),
		);

		if ( $is_new ) {
			$wpdb->insert( $table, $row );
			return 'new';
		}

		$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) );
		return 'updated';
	}

	/**
	 * Download a remote URL into the media library once (deduped by slug meta),
	 * returning its attachment id or 0 on failure.
	 *
	 * @param string $url  Remote URL.
	 * @param string $slug Stable per-item slug.
	 */
	private function sideload( string $url, string $slug ): int {
		if ( '' === $url ) {
			return 0;
		}

		global $wpdb;
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_IG_ID,
				$slug
			)
		);
		if ( $existing ) {
			return (int) $existing;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		if ( (int) filesize( $tmp ) > self::MAX_BYTES ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext || ! preg_match( '/^[a-z0-9]{2,5}$/', $ext ) ) {
			$ext = 'jpg';
		}
		$file = array(
			'name'     => 'ig-' . preg_replace( '/[^a-z0-9\-_]/i', '', $slug ) . '.' . $ext,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		update_post_meta( $attachment_id, self::META_IG_ID, $slug );
		update_post_meta( $attachment_id, self::META_SOURCE, esc_url_raw( $url ) );

		return (int) $attachment_id;
	}

	// ─── Media queries (admin + frontend) ─────────────────────────────────

	/**
	 * Cached media rows, newest first.
	 *
	 * @param int  $limit          Max rows.
	 * @param bool $include_hidden Include rows flagged hidden.
	 * @return array<int, array<string, mixed>>
	 */
	public function list_media( int $limit = 60, bool $include_hidden = true ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_instagram_media";
		$limit = max( 1, min( 200, $limit ) );

		$where = $include_hidden ? '1=1' : 'is_hidden = 0';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY media_timestamp DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'format_media_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Total cached media count.
	 */
	private function media_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_instagram_media" );
	}

	/**
	 * Toggle a cached media item's hidden flag.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function toggle_hidden( int $id ) {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_instagram_media";
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Media item not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$wpdb->update( $table, array( 'is_hidden' => (int) $row['is_hidden'] ? 0 : 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $this->format_media_row( (array) $row );
	}

	// ─── Feeds (display definitions) ───────────────────────────────────────

	/**
	 * All feed definitions, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_feeds(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, name, settings FROM {$wpdb->prefix}uxstudio_instagram_feeds ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'format_feed_row' ), $rows );
	}

	/**
	 * Create a feed.
	 *
	 * @param string $name   Feed label.
	 * @param array  $config Raw display config.
	 * @return array<string, mixed>
	 */
	public function create_feed( string $name, array $config ): array {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_instagram_feeds",
			array(
				'created_at' => current_time( 'mysql', true ),
				'name'       => mb_substr( sanitize_text_field( $name ), 0, 255 ),
				'settings'   => wp_json_encode( $this->normalize_config( $config ) ),
			),
			array( '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		ActivityLog::log( 'instagram-feed', 'feed_created', 'feed', $id );
		return (array) $this->get_feed( $id );
	}

	/**
	 * Update a feed.
	 *
	 * @param int    $id     Feed id.
	 * @param string $name   Feed label.
	 * @param array  $config Raw display config.
	 * @return array<string, mixed>|WP_Error
	 */
	public function update_feed( int $id, string $name, array $config ) {
		if ( null === $this->get_feed( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Feed not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}uxstudio_instagram_feeds",
			array(
				'name'     => mb_substr( sanitize_text_field( $name ), 0, 255 ),
				'settings' => wp_json_encode( $this->normalize_config( $config ) ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		ActivityLog::log( 'instagram-feed', 'feed_updated', 'feed', $id );
		return (array) $this->get_feed( $id );
	}

	/**
	 * Delete a feed.
	 *
	 * @param int $id Feed id.
	 * @return array{deleted:int}|WP_Error
	 */
	public function delete_feed( int $id ) {
		if ( null === $this->get_feed( $id ) ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Feed not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_instagram_feeds", array( 'id' => $id ), array( '%d' ) );
		ActivityLog::log( 'instagram-feed', 'feed_deleted', 'feed', $id );
		return array( 'deleted' => $id );
	}

	/**
	 * One feed by id.
	 *
	 * @param int $id Feed id.
	 */
	public function get_feed( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, created_at, name, settings FROM {$wpdb->prefix}uxstudio_instagram_feeds WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->format_feed_row( $row ) : null;
	}

	// ─── Shortcode / rendering ─────────────────────────────────────────────

	/**
	 * Frontend shortcode: [uxstudio_instagram id="1" theme="grid" limit="12"].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'               => 0,
				'theme'            => '',
				'limit'            => 0,
				'cols'             => 0,
				'gap'              => -1,
				'show_caption'     => '',
				'include_hashtags' => '',
				'exclude_hashtags' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'uxstudio_instagram'
		);

		// Resolve the feed config: explicit id, else the first feed, else defaults.
		$config = null;
		$id     = (int) $atts['id'];
		if ( $id > 0 ) {
			$feed = $this->get_feed( $id );
			$config = $feed ? $feed['config'] : null;
		} else {
			$feeds  = $this->list_feeds();
			$config = ! empty( $feeds ) ? $feeds[0]['config'] : $this->normalize_config( array() );
		}

		if ( null === $config ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<div class="uxstudio-ig-empty">' . esc_html__( 'Instagram feed not found. Create one in the Instagram Feed screen.', 'ux-studio' ) . '</div>';
			}
			return '';
		}

		// Inline overrides.
		if ( '' !== $atts['theme'] && isset( self::THEMES[ $atts['theme'] ] ) ) {
			$config['theme'] = (string) $atts['theme'];
		}
		if ( (int) $atts['limit'] > 0 ) {
			$config['item_limit'] = (int) $atts['limit'];
		}
		if ( (int) $atts['cols'] > 0 ) {
			$config['cols_desktop'] = (int) $atts['cols'];
		}
		if ( (int) $atts['gap'] >= 0 ) {
			$config['gap_px'] = (int) $atts['gap'];
		}
		if ( '' !== $atts['show_caption'] ) {
			$config['show_caption'] = (bool) filter_var( $atts['show_caption'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( '' !== $atts['include_hashtags'] ) {
			$config['include_hashtags'] = (string) $atts['include_hashtags'];
		}
		if ( '' !== $atts['exclude_hashtags'] ) {
			$config['exclude_hashtags'] = (string) $atts['exclude_hashtags'];
		}

		return $this->render_feed( $config );
	}

	/**
	 * Render a feed config to HTML from the local media cache.
	 *
	 * @param array $config Normalized feed config.
	 */
	private function render_feed( array $config ): string {
		$items = $this->query_feed_items( $config );

		if ( empty( $items ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				if ( '' === $this->get_token() ) {
					return '<div class="uxstudio-ig-empty">' . esc_html__( 'No Instagram account connected. Connect one in the Instagram Feed screen.', 'ux-studio' ) . '</div>';
				}
				return '<div class="uxstudio-ig-empty">' . esc_html__( 'No media yet. Run a sync in the Instagram Feed screen.', 'ux-studio' ) . '</div>';
			}
			return '';
		}

		$theme = isset( self::THEMES[ $config['theme'] ] ) ? (string) $config['theme'] : 'grid';
		$style = $this->style_block();

		$css_vars = sprintf(
			'--uxs-ig-cols-d:%d;--uxs-ig-cols-t:%d;--uxs-ig-cols-m:%d;--uxs-ig-gap:%dpx;',
			(int) $config['cols_desktop'],
			(int) $config['cols_tablet'],
			(int) $config['cols_mobile'],
			(int) $config['gap_px']
		);

		$html  = $style;
		$html .= '<div class="uxstudio-ig uxstudio-ig--' . esc_attr( $theme ) . '" style="' . esc_attr( $css_vars ) . '">';
		$html .= '<div class="uxstudio-ig__grid">';

		$target = '_self' === $config['link_target'] ? '_self' : '_blank';
		$rel    = '_blank' === $target ? ' rel="noopener noreferrer"' : '';

		foreach ( $items as $item ) {
			$img     = $this->item_image_url( $item );
			$caption = $this->truncate( (string) ( $item['caption'] ?? '' ), (int) $config['caption_length'] );
			$is_video = in_array( (string) $item['media_type'], array( 'VIDEO', 'REELS' ), true );
			$is_album = 'CAROUSEL_ALBUM' === (string) $item['media_type'];

			$html .= '<a class="uxstudio-ig__item" href="' . esc_url( (string) $item['permalink'] ) . '" target="' . esc_attr( $target ) . '"' . $rel . '>';
			$html .= '<span class="uxstudio-ig__media">';
			if ( '' !== $img ) {
				$html .= '<img class="uxstudio-ig__image" src="' . esc_url( $img ) . '" loading="lazy" alt="' . esc_attr( $caption ) . '" />';
			}
			if ( $is_video ) {
				$html .= '<span class="uxstudio-ig__badge" aria-hidden="true">&#9658;</span>';
			} elseif ( $is_album ) {
				$html .= '<span class="uxstudio-ig__badge" aria-hidden="true">&#9635;</span>';
			}
			$html .= '</span>';
			if ( ! empty( $config['show_caption'] ) && '' !== $caption ) {
				$html .= '<span class="uxstudio-ig__caption">' . esc_html( $caption ) . '</span>';
			}
			$html .= '</a>';
		}

		$html .= '</div>';

		$connection = $this->get_connection();
		if ( ! empty( $config['show_follow'] ) && ! empty( $connection['username'] ) ) {
			$html .= '<div class="uxstudio-ig__follow-wrap"><a class="uxstudio-ig__follow" target="_blank" rel="noopener noreferrer" href="'
				. esc_url( 'https://www.instagram.com/' . $connection['username'] ) . '">@'
				. esc_html( (string) $connection['username'] ) . '</a></div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Query + filter media rows for a feed config (media type + hashtags).
	 *
	 * @param array $config Normalized feed config.
	 * @return array<int, array<string, mixed>>
	 */
	private function query_feed_items( array $config ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_instagram_media";

		$where  = array( 'is_hidden = 0' );
		$params = array();

		$types = array_values( array_filter( array_map( 'trim', explode( ',', (string) $config['media_types'] ) ) ) );
		if ( ! empty( $types ) ) {
			$where[]  = 'media_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params   = array_merge( $params, $types );
		}

		$limit    = max( 1, min( 200, (int) $config['item_limit'] ) );
		$params[] = $limit;

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY media_timestamp DESC, id DESC LIMIT %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = array_map( array( $this, 'format_media_row' ), is_array( $rows ) ? $rows : array() );

		$include = $this->normalize_tags( (string) $config['include_hashtags'] );
		$exclude = $this->normalize_tags( (string) $config['exclude_hashtags'] );
		if ( empty( $include ) && empty( $exclude ) ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( array $item ) use ( $include, $exclude ): bool {
					$tags = array_map( 'strtolower', (array) $item['hashtags'] );
					if ( ! empty( $exclude ) && array_intersect( $tags, $exclude ) ) {
						return false;
					}
					if ( ! empty( $include ) && ! array_intersect( $tags, $include ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * Best display URL for an item: local attachment, else CDN thumbnail/url.
	 *
	 * @param array $item Formatted media row.
	 */
	private function item_image_url( array $item ): string {
		$attachment_id = (int) $item['attachment_id'];
		if ( $attachment_id > 0 ) {
			$src = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( is_string( $src ) && '' !== $src ) {
				return $src;
			}
		}
		if ( '' !== (string) $item['thumbnail_url'] ) {
			return (string) $item['thumbnail_url'];
		}
		return (string) $item['media_url'];
	}

	/**
	 * The scoped frontend stylesheet, printed inline once per request.
	 */
	private function style_block(): string {
		if ( self::$style_printed ) {
			return '';
		}
		self::$style_printed = true;

		$css = '.uxstudio-ig__grid{display:grid;grid-template-columns:repeat(var(--uxs-ig-cols-d,4),1fr);gap:var(--uxs-ig-gap,8px);}'
			. '.uxstudio-ig--masonry .uxstudio-ig__grid{display:block;column-count:var(--uxs-ig-cols-d,4);column-gap:var(--uxs-ig-gap,8px);}'
			. '.uxstudio-ig--masonry .uxstudio-ig__item{display:inline-block;width:100%;margin:0 0 var(--uxs-ig-gap,8px);}'
			. '.uxstudio-ig--carousel .uxstudio-ig__grid,.uxstudio-ig--slider .uxstudio-ig__grid{display:flex;grid-template-columns:none;overflow-x:auto;scroll-snap-type:x mandatory;}'
			. '.uxstudio-ig--carousel .uxstudio-ig__item,.uxstudio-ig--slider .uxstudio-ig__item{flex:0 0 calc((100% - (var(--uxs-ig-cols-d,4) - 1) * var(--uxs-ig-gap,8px)) / var(--uxs-ig-cols-d,4));scroll-snap-align:start;}'
			. '.uxstudio-ig__item{position:relative;display:block;text-decoration:none;color:inherit;}'
			. '.uxstudio-ig__media{position:relative;display:block;aspect-ratio:1/1;overflow:hidden;border-radius:6px;background:#f0f0f1;}'
			. '.uxstudio-ig__image{width:100%;height:100%;object-fit:cover;display:block;}'
			. '.uxstudio-ig__badge{position:absolute;top:8px;right:8px;color:#fff;font-size:14px;line-height:1;text-shadow:0 1px 2px rgba(0,0,0,.5);}'
			. '.uxstudio-ig__caption{display:block;margin-top:6px;font-size:13px;line-height:1.4;}'
			. '.uxstudio-ig__follow-wrap{margin-top:12px;text-align:center;}'
			. '.uxstudio-ig__follow{display:inline-block;padding:8px 16px;border-radius:6px;background:#0095f6;color:#fff;text-decoration:none;font-weight:600;}'
			. '@media(max-width:1024px){.uxstudio-ig__grid{grid-template-columns:repeat(var(--uxs-ig-cols-t,3),1fr);}.uxstudio-ig--masonry .uxstudio-ig__grid{column-count:var(--uxs-ig-cols-t,3);}}'
			. '@media(max-width:640px){.uxstudio-ig__grid{grid-template-columns:repeat(var(--uxs-ig-cols-m,2),1fr);}.uxstudio-ig--masonry .uxstudio-ig__grid{column-count:var(--uxs-ig-cols-m,2);}}';

		return '<style id="uxstudio-instagram-css">' . $css . '</style>';
	}

	// ─── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Normalize + clamp a raw display config.
	 *
	 * @param array $data Raw config.
	 * @return array<string, mixed>
	 */
	private function normalize_config( array $data ): array {
		$theme = (string) ( $data['theme'] ?? 'grid' );
		if ( ! isset( self::THEMES[ $theme ] ) ) {
			$theme = 'grid';
		}

		$types = $data['media_types'] ?? 'IMAGE,VIDEO,CAROUSEL_ALBUM';
		if ( is_array( $types ) ) {
			$types = implode( ',', $types );
		}
		$types = (string) preg_replace( '/[^A-Z,_]/', '', strtoupper( (string) $types ) );
		if ( '' === $types ) {
			$types = 'IMAGE,VIDEO,CAROUSEL_ALBUM';
		}

		return array(
			'theme'            => $theme,
			'item_limit'       => $this->clamp( $data['item_limit'] ?? 12, 1, 200 ),
			'cols_desktop'     => $this->clamp( $data['cols_desktop'] ?? 4, 1, 12 ),
			'cols_tablet'      => $this->clamp( $data['cols_tablet'] ?? 3, 1, 12 ),
			'cols_mobile'      => $this->clamp( $data['cols_mobile'] ?? 2, 1, 12 ),
			'gap_px'           => $this->clamp( $data['gap_px'] ?? 8, 0, 100 ),
			'show_caption'     => ! empty( $data['show_caption'] ),
			'show_follow'      => ! empty( $data['show_follow'] ),
			'caption_length'   => $this->clamp( $data['caption_length'] ?? 120, 0, 1000 ),
			'link_target'      => '_self' === ( $data['link_target'] ?? '_blank' ) ? '_self' : '_blank',
			'media_types'      => $types,
			'include_hashtags' => $this->tags_to_string( $data['include_hashtags'] ?? '' ),
			'exclude_hashtags' => $this->tags_to_string( $data['exclude_hashtags'] ?? '' ),
		);
	}

	/**
	 * Extract lowercased, unique hashtags from a caption.
	 *
	 * @param string $caption Caption text.
	 * @return array<int, string>
	 */
	private function extract_hashtags( string $caption ): array {
		if ( '' === $caption ) {
			return array();
		}
		preg_match_all( '/#([\p{L}0-9_]+)/u', $caption, $matches );
		return array_values( array_unique( array_map( 'mb_strtolower', $matches[1] ?? array() ) ) );
	}

	/**
	 * Normalize a comma list of hashtags for comparison.
	 *
	 * @param string $raw Raw comma list.
	 * @return array<int, string>
	 */
	private function normalize_tags( string $raw ): array {
		$parts = array_filter( array_map( static fn ( $t ) => strtolower( ltrim( trim( (string) $t ), '#' ) ), explode( ',', $raw ) ) );
		return array_values( $parts );
	}

	/**
	 * Sanitize a comma list of hashtags for storage.
	 *
	 * @param mixed $raw Raw value (string or array).
	 */
	private function tags_to_string( $raw ): string {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', $raw );
		}
		$parts = array_filter( array_map( static fn ( $t ) => ltrim( trim( (string) $t ), '#' ), explode( ',', (string) $raw ) ) );
		return implode( ',', $parts );
	}

	/**
	 * Truncate a caption to a character budget.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max length (0 = unlimited).
	 */
	private function truncate( string $text, int $max ): string {
		$text = trim( $text );
		if ( '' === $text || 0 === $max || mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max ) ) . '…';
	}

	/**
	 * Clamp a value to an int range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 */
	private function clamp( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Format a raw media DB row for output.
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed>
	 */
	private function format_media_row( array $row ): array {
		$hashtags = array();
		if ( ! empty( $row['hashtags'] ) ) {
			$decoded  = json_decode( (string) $row['hashtags'], true );
			$hashtags = is_array( $decoded ) ? $decoded : array();
		}
		return array(
			'id'                  => (int) ( $row['id'] ?? 0 ),
			'instagram_id'        => (string) ( $row['instagram_id'] ?? '' ),
			'media_type'          => (string) ( $row['media_type'] ?? 'IMAGE' ),
			'media_url'           => (string) ( $row['media_url'] ?? '' ),
			'thumbnail_url'       => (string) ( $row['thumbnail_url'] ?? '' ),
			'permalink'           => (string) ( $row['permalink'] ?? '' ),
			'caption'             => isset( $row['caption'] ) ? (string) $row['caption'] : '',
			'hashtags'            => $hashtags,
			'attachment_id'       => (int) ( $row['attachment_id'] ?? 0 ),
			'video_attachment_id' => (int) ( $row['video_attachment_id'] ?? 0 ),
			'media_timestamp'     => (string) ( $row['media_timestamp'] ?? '' ),
			'like_count'          => isset( $row['like_count'] ) ? (int) $row['like_count'] : null,
			'comments_count'      => isset( $row['comments_count'] ) ? (int) $row['comments_count'] : null,
			'is_hidden'           => ! empty( $row['is_hidden'] ),
			'synced_at'           => (string) ( $row['synced_at'] ?? '' ),
		);
	}

	/**
	 * Format a raw feed DB row for output (decode config).
	 *
	 * @param array $row Raw row.
	 * @return array<string, mixed>
	 */
	private function format_feed_row( array $row ): array {
		$config = array();
		if ( ! empty( $row['settings'] ) ) {
			$decoded = json_decode( (string) $row['settings'], true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}
		return array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'config'     => $this->normalize_config( $config ),
		);
	}

	/**
	 * Read the connection metadata (non-secret).
	 *
	 * @return array<string, mixed>
	 */
	private function get_connection(): array {
		$raw = get_option( self::OPTION_CONNECTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist connection metadata (non-secret).
	 *
	 * @param array $data Connection fields.
	 */
	private function set_connection( array $data ): void {
		update_option( self::OPTION_CONNECTION, $data, false );
	}

	/**
	 * Read the decrypted access token, if any.
	 */
	private function get_token(): string {
		return Security::get_secret( self::SECRET_TOKEN );
	}

	/**
	 * The OAuth redirect URI (must be registered in the Meta app).
	 */
	private function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::OAUTH_ACTION );
	}

	/**
	 * Schedule the sync cron if not already scheduled.
	 */
	private function ensure_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$interval = $this->cron_interval();
			wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
		}
	}

	/**
	 * Reschedule the cron (called after the interval setting changes).
	 */
	private function reschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
		wp_schedule_event( time() + 300, $this->cron_interval(), self::CRON_HOOK );
	}

	/**
	 * Validated WP-Cron schedule slug from settings.
	 */
	private function cron_interval(): string {
		$interval = (string) $this->settings->get( 'sync_interval', 'hourly' );
		return in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $interval : 'hourly';
	}
}
