<?php
/**
 * Popup Manager module - general-purpose on-site popups with delay/scroll/
 * page-load triggers, page/role targeting and impression/click tracking.
 *
 * Not to be confused with the separate exit-intent popup module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\PopupManager;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy spm_popup module. Uses a brand-new CPT
 * (uxstudio_popup) - legacy popup content/data is intentionally NOT migrated.
 */
final class Module extends BaseModule {

	public const POST_TYPE = 'uxstudio_popup';

	private const META_CONTENT   = '_uxstudio_popup_content';
	private const META_TRIGGER   = '_uxstudio_popup_trigger';
	private const META_TARGETING = '_uxstudio_popup_targeting';

	private const TRIGGER_TYPES = array( 'delay', 'scroll', 'exit', 'immediate' );
	private const EVENT_TYPES   = array( 'shown', 'closed', 'clicked' );

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'popup-manager',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_popup_stats (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						popup_id BIGINT UNSIGNED NOT NULL,
						event VARCHAR(20) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at),
						KEY popup_id (popup_id)
					) {$charset};"
				);
			}
		);

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_footer', array( $this, 'maybe_output_popups' ) );
	}

	/**
	 * Register the uxstudio_popup CPT and its JSON meta fields. The admin UI
	 * for this module is the SPA, not wp-admin - show_ui/show_in_rest are off
	 * and the module's own REST controller owns reads/writes.
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Popups', 'ux-studio' ),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'supports'        => array( 'title' ),
			)
		);

		$meta_args = array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => false,
		);
		register_post_meta( self::POST_TYPE, self::META_CONTENT, $meta_args );
		register_post_meta( self::POST_TYPE, self::META_TRIGGER, $meta_args );
		register_post_meta( self::POST_TYPE, self::META_TARGETING, $meta_args );
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
	 * Capability required to manage popups.
	 */
	public function capability(): string {
		return 'manage_options';
	}

	// =====================================================================
	// CRUD
	// =====================================================================

	/**
	 * All popups, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_popups(): array {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		return array_map( array( $this, 'to_array' ), $query->posts );
	}

	/**
	 * Single popup by id.
	 *
	 * @param int $id Post id.
	 * @return array<string, mixed>|null
	 */
	public function get_popup( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		return $this->to_array( $post );
	}

	/**
	 * Create a popup.
	 *
	 * @param array $data Raw input (title, status, content, trigger, targeting).
	 * @return array<string, mixed>|null
	 */
	public function create_popup( array $data ): ?array {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'post_status' => $this->sanitize_status( $data['status'] ?? 'draft' ),
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return null;
		}

		$this->write_meta( (int) $post_id, $data );

		ActivityLog::log( 'popup-manager', 'create', 'popup', (int) $post_id );

		return $this->get_popup( (int) $post_id );
	}

	/**
	 * Update a popup.
	 *
	 * @param int   $id   Post id.
	 * @param array $data Raw input.
	 * @return array<string, mixed>|null
	 */
	public function update_popup( int $id, array $data ): ?array {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$update = array( 'ID' => $id );
		if ( array_key_exists( 'title', $data ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$update['post_status'] = $this->sanitize_status( $data['status'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		$this->write_meta( $id, $data );

		return $this->get_popup( $id );
	}

	/**
	 * Delete a popup permanently.
	 *
	 * @param int $id Post id.
	 */
	public function delete_popup( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$deleted = wp_delete_post( $id, true );
		if ( ! $deleted ) {
			return false;
		}

		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_popup_stats", array( 'popup_id' => $id ), array( '%d' ) );

		ActivityLog::log( 'popup-manager', 'delete', 'popup', $id );

		return true;
	}

	// =====================================================================
	// Tracking / stats
	// =====================================================================

	/**
	 * Record one tracking event for a popup. Callers (REST) are responsible
	 * for validating popup_id/event and for rate limiting; this only inserts.
	 *
	 * @param int    $popup_id Popup post id.
	 * @param string $event    One of shown|closed|clicked.
	 */
	public function track_event( int $popup_id, string $event ): void {
		if ( ! in_array( $event, self::EVENT_TYPES, true ) ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_popup_stats",
			array(
				'created_at' => current_time( 'mysql' ),
				'popup_id'   => $popup_id,
				'event'      => $event,
			),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * Aggregated event counts for a single popup.
	 *
	 * @param int $popup_id Popup post id.
	 * @return array{shown:int,closed:int,clicked:int}
	 */
	public function get_stats( int $popup_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event, COUNT(*) AS cnt FROM {$wpdb->prefix}uxstudio_popup_stats WHERE popup_id = %d GROUP BY event",
				$popup_id
			),
			ARRAY_A
		);

		$stats = array(
			'shown'   => 0,
			'closed'  => 0,
			'clicked' => 0,
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$event = (string) $row['event'];
				if ( isset( $stats[ $event ] ) ) {
					$stats[ $event ] = (int) $row['cnt'];
				}
			}
		}

		return $stats;
	}

	// =====================================================================
	// Front-end delivery
	// =====================================================================

	/**
	 * Minimal, self-contained front-end popup renderer: queries published
	 * popups, filters by simple page/role targeting and echoes inline
	 * HTML/CSS/JS implementing the delay/scroll/immediate triggers and
	 * calling the track endpoint on show/close/click. A full templating
	 * system is out of scope - this only needs to work end-to-end.
	 */
	public function maybe_output_popups(): void {
		if ( is_admin() ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return;
		}

		$current_page_id = (int) get_queried_object_id();
		$user_roles      = array();
		if ( is_user_logged_in() ) {
			$user      = wp_get_current_user();
			$user_roles = (array) $user->roles;
		}

		$matching = array();
		foreach ( $query->posts as $post ) {
			$targeting = $this->get_meta_json( $post->ID, self::META_TARGETING );
			$pages     = array_map( 'intval', (array) ( $targeting['pages'] ?? array() ) );
			$roles     = array_map( 'sanitize_key', (array) ( $targeting['roles'] ?? array() ) );

			if ( ! empty( $pages ) && ! in_array( $current_page_id, $pages, true ) ) {
				continue;
			}
			if ( ! empty( $roles ) && empty( array_intersect( $roles, $user_roles ) ) ) {
				continue;
			}

			$matching[] = $post;
		}

		if ( empty( $matching ) ) {
			return;
		}

		$track_url = esc_url_raw( rest_url( 'uxstudio/v1/popup-manager/track' ) );
		?>
		<style>
			.uxs-popup{position:fixed;inset:0;display:none;align-items:center;justify-content:center;z-index:99999;}
			.uxs-popup.is-visible{display:flex;}
			.uxs-popup__overlay{position:absolute;inset:0;background:rgba(0,0,0,.5);}
			.uxs-popup__box{position:relative;background:#fff;color:#1f2937;max-width:480px;width:calc(100% - 32px);padding:24px;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,.2);}
			.uxs-popup__close{position:absolute;top:8px;right:12px;background:none;border:0;font-size:20px;line-height:1;cursor:pointer;}
			.uxs-popup__headline{margin:0 0 8px;font-size:1.25em;}
			.uxs-popup__body{margin:0 0 16px;}
			.uxs-popup__cta{display:inline-block;padding:10px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:4px;}
		</style>
		<?php
		foreach ( $matching as $post ) {
			$this->render_popup_markup( $post, $track_url );
		}
	}

	/**
	 * Echo one popup's markup + trigger script. All dynamic content is escaped.
	 *
	 * @param WP_Post $post      Popup post.
	 * @param string  $track_url Absolute REST track endpoint URL.
	 */
	private function render_popup_markup( WP_Post $post, string $track_url ): void {
		$id      = (int) $post->ID;
		$content = $this->get_meta_json( $post->ID, self::META_CONTENT );
		$trigger = $this->get_meta_json( $post->ID, self::META_TRIGGER );

		$headline = (string) ( $content['headline'] ?? '' );
		$body     = (string) ( $content['body'] ?? '' );
		$cta_text = (string) ( $content['cta_text'] ?? '' );
		$cta_url  = (string) ( $content['cta_url'] ?? '' );

		$trigger_type = (string) ( $trigger['type'] ?? 'delay' );
		if ( ! in_array( $trigger_type, self::TRIGGER_TYPES, true ) ) {
			$trigger_type = 'delay';
		}
		$delay_ms      = max( 0, (int) ( $trigger['delay_ms'] ?? 4000 ) );
		$scroll_percent = min( 100, max( 0, (int) ( $trigger['scroll_percent'] ?? 50 ) ) );

		printf(
			'<div class="uxs-popup" id="uxs-popup-%1$d" data-popup-id="%1$d">',
			$id
		);
		echo '<div class="uxs-popup__overlay" data-uxs-dismiss></div>';
		echo '<div class="uxs-popup__box" role="dialog" aria-modal="true">';
		echo '<button type="button" class="uxs-popup__close" data-uxs-dismiss aria-label="' . esc_attr__( 'Close', 'ux-studio' ) . '">&times;</button>';
		if ( '' !== $headline ) {
			echo '<h2 class="uxs-popup__headline">' . esc_html( $headline ) . '</h2>';
		}
		if ( '' !== $body ) {
			echo '<div class="uxs-popup__body">' . esc_html( $body ) . '</div>';
		}
		if ( '' !== $cta_text && '' !== $cta_url ) {
			printf(
				'<a class="uxs-popup__cta" href="%s" data-uxs-cta>%s</a>',
				esc_url( $cta_url ),
				esc_html( $cta_text )
			);
		}
		echo '</div></div>';

		$config = array(
			'id'            => $id,
			'trigger'       => $trigger_type,
			'delayMs'       => $delay_ms,
			'scrollPercent' => $scroll_percent,
			'trackUrl'      => $track_url,
		);
		?>
		<script>
		( function () {
			var cfg = <?php echo wp_json_encode( $config ); ?>;
			var el = document.getElementById( 'uxs-popup-' + cfg.id );
			if ( ! el ) { return; }
			var shown = false;

			function track( event ) {
				try {
					fetch( cfg.trackUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { popup_id: cfg.id, event: event } ),
						keepalive: true,
					} );
				} catch ( e ) {}
			}

			function show() {
				if ( shown ) { return; }
				shown = true;
				el.classList.add( 'is-visible' );
				track( 'shown' );
			}

			function hide() {
				if ( ! shown ) { return; }
				el.classList.remove( 'is-visible' );
				track( 'closed' );
			}

			el.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '[data-uxs-dismiss]' ) ) {
					e.preventDefault();
					hide();
				} else if ( e.target.closest( '[data-uxs-cta]' ) ) {
					track( 'clicked' );
				}
			} );

			if ( cfg.trigger === 'immediate' ) {
				show();
			} else if ( cfg.trigger === 'delay' ) {
				setTimeout( show, cfg.delayMs );
			} else if ( cfg.trigger === 'scroll' ) {
				var onScroll = function () {
					var doc = document.documentElement;
					var scrolled = ( doc.scrollTop || document.body.scrollTop ) / ( ( doc.scrollHeight || document.body.scrollHeight ) - doc.clientHeight ) * 100;
					if ( scrolled >= cfg.scrollPercent ) {
						show();
						window.removeEventListener( 'scroll', onScroll );
					}
				};
				window.addEventListener( 'scroll', onScroll, { passive: true } );
			} else if ( cfg.trigger === 'exit' ) {
				var onLeave = function ( e ) {
					if ( e.clientY <= 0 ) {
						show();
						document.removeEventListener( 'mouseout', onLeave );
					}
				};
				document.addEventListener( 'mouseout', onLeave );
			}
		} )();
		</script>
		<?php
	}

	// =====================================================================
	// Internal helpers
	// =====================================================================

	/**
	 * Serialize a popup post + its JSON meta into a plain array for REST.
	 *
	 * @param WP_Post $post Popup post.
	 * @return array<string, mixed>
	 */
	private function to_array( WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => $post->post_title,
			'status'    => $post->post_status,
			'created_at' => $post->post_date,
			'content'   => $this->get_meta_json( $post->ID, self::META_CONTENT ),
			'trigger'   => $this->get_meta_json( $post->ID, self::META_TRIGGER ),
			'targeting' => $this->get_meta_json( $post->ID, self::META_TARGETING ),
		);
	}

	/**
	 * Read + JSON-decode one meta key, defaulting to an empty object.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Meta key.
	 * @return array<string, mixed>
	 */
	private function get_meta_json( int $post_id, string $key ): array {
		$raw = get_post_meta( $post_id, $key, true );
		if ( '' === $raw || ! is_string( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sanitize + persist the content/trigger/targeting meta from raw input.
	 * Only touches keys present in $data, so partial updates don't clobber
	 * the other blocks.
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    Raw input.
	 */
	private function write_meta( int $post_id, array $data ): void {
		if ( array_key_exists( 'content', $data ) && is_array( $data['content'] ) ) {
			update_post_meta( $post_id, self::META_CONTENT, wp_json_encode( $this->sanitize_content( $data['content'] ) ) );
		}
		if ( array_key_exists( 'trigger', $data ) && is_array( $data['trigger'] ) ) {
			update_post_meta( $post_id, self::META_TRIGGER, wp_json_encode( $this->sanitize_trigger( $data['trigger'] ) ) );
		}
		if ( array_key_exists( 'targeting', $data ) && is_array( $data['targeting'] ) ) {
			update_post_meta( $post_id, self::META_TARGETING, wp_json_encode( $this->sanitize_targeting( $data['targeting'] ) ) );
		}
	}

	/**
	 * Sanitize the {headline, body, cta_text, cta_url} content block. This
	 * renders on the live front-end, so every string is stripped of markup
	 * and the URL is validated.
	 *
	 * @param array $content Raw content block.
	 * @return array{headline:string,body:string,cta_text:string,cta_url:string}
	 */
	private function sanitize_content( array $content ): array {
		$cta_url = isset( $content['cta_url'] ) ? esc_url_raw( (string) $content['cta_url'] ) : '';

		return array(
			'headline' => sanitize_text_field( (string) ( $content['headline'] ?? '' ) ),
			'body'     => sanitize_textarea_field( (string) ( $content['body'] ?? '' ) ),
			'cta_text' => sanitize_text_field( (string) ( $content['cta_text'] ?? '' ) ),
			'cta_url'  => $cta_url,
		);
	}

	/**
	 * Sanitize the {type, delay_ms, scroll_percent} trigger block. Type is
	 * whitelisted against the four supported trigger kinds.
	 *
	 * @param array $trigger Raw trigger block.
	 * @return array{type:string,delay_ms:int,scroll_percent:int}
	 */
	private function sanitize_trigger( array $trigger ): array {
		$type = (string) ( $trigger['type'] ?? 'delay' );
		if ( ! in_array( $type, self::TRIGGER_TYPES, true ) ) {
			$type = 'delay';
		}

		return array(
			'type'           => $type,
			'delay_ms'       => max( 0, min( 600000, (int) ( $trigger['delay_ms'] ?? 4000 ) ) ),
			'scroll_percent' => max( 0, min( 100, (int) ( $trigger['scroll_percent'] ?? 50 ) ) ),
		);
	}

	/**
	 * Sanitize the {pages, roles} targeting block. Pages are coerced to
	 * positive ints, roles are restricted to keys registered in wp_roles().
	 *
	 * @param array $targeting Raw targeting block.
	 * @return array{pages:int[],roles:string[]}
	 */
	private function sanitize_targeting( array $targeting ): array {
		$pages = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', (array) ( $targeting['pages'] ?? array() ) ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		$known_roles = array_keys( wp_roles()->roles );
		$roles       = array_values(
			array_unique(
				array_intersect(
					array_map( 'sanitize_key', (array) ( $targeting['roles'] ?? array() ) ),
					$known_roles
				)
			)
		);

		return array(
			'pages' => $pages,
			'roles' => $roles,
		);
	}

	/**
	 * Whitelist post_status to publish|draft.
	 *
	 * @param mixed $status Raw status.
	 */
	private function sanitize_status( $status ): string {
		$status = (string) $status;
		return 'publish' === $status ? 'publish' : 'draft';
	}
}
