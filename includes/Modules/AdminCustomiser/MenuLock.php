<?php
/**
 * Menu Organizer access lock: a page hidden from a user's role is also refused when its address is typed in.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser;

use UxStudio\Core\Migration\MenuSimulator;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default (existing sites keep the "hide only" behaviour); the setting `menu_lock_enabled` of the module turns
 * it on, and it needs the Menu Organizer to be on too. What is refused and what never is lives in
 * `MenuSimulator::request_blocked()` (pure, tested), so the migration check and the runtime agree.
 *
 * Rescue switch: `define( 'UXSTUDIO_DISABLE_MENU_LOCK', true );` in wp-config.php switches the whole lock off.
 *
 * The lock only adds a refusal on top of WordPress' own capability checks, it never grants anything. It covers the
 * admin screens (a request to `wp-admin/<file>.php`), not AJAX, REST, admin-post or cron.
 */
final class MenuLock {

	public const KILL_SWITCH = 'UXSTUDIO_DISABLE_MENU_LOCK';

	/**
	 * Is the rescue constant defined?
	 */
	public static function killed(): bool {
		return defined( self::KILL_SWITCH ) && constant( self::KILL_SWITCH );
	}

	/**
	 * Hook it up. Called once from Module::boot().
	 */
	public static function register(): void {
		// After the Organizer has recorded the original menu (admin_menu, 99999), before WordPress checks the page (after admin_init).
		add_action( 'admin_init', array( self::class, 'enforce' ), 1 );
	}

	/**
	 * Is the lock switched on (setting, Organizer on, no rescue constant)?
	 */
	public static function active(): bool {
		if ( self::killed() ) {
			return false;
		}
		$settings = new Settings( 'uxstudio_admin_customiser' );
		return (bool) $settings->get( 'menu_lock_enabled', false ) && (bool) $settings->get( 'menu_organizer_enabled', false );
	}

	/**
	 * Refuse the current admin request when it belongs to a menu item hidden from the user.
	 */
	public static function enforce(): void {
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! self::active() ) {
			return;
		}
		$organizer = MenuOrganizerBootstrap::organizer();
		$structure = $organizer->menu_structure();
		if ( ! $structure ) {
			return;
		}

		global $pagenow;
		$user = wp_get_current_user();
		$out  = MenuSimulator::request_blocked(
			$organizer->get_config(),
			(string) $pagenow,
			self::request_args( (string) $pagenow ),
			$structure['tops'],
			$structure['subs'],
			array_map( 'strval', (array) $user->roles ),
			current_user_can( 'manage_options' ),
			MenuSimulator::current_ctx()
		);
		if ( ! $out['blocked'] ) {
			return;
		}

		/**
		 * Fires when the Menu Organizer lock refuses a request.
		 *
		 * @param string $top  Top-level menu slug the request belongs to.
		 * @param int    $user Current user id.
		 */
		do_action( 'uxstudio_menu_lock_blocked', (string) $out['top'], (int) $user->ID );

		// Exactly what WordPress shows (wp-admin/includes/menu.php) for a page the user may not open.
		// phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ), 403 );
	}

	/**
	 * The query args that decide which menu entry a request is (read only, compared, never output).
	 *
	 * On `post.php` the post type is NEVER taken from the request: it is the type of the post being
	 * opened or saved (`post_ID` / `post`). Otherwise `post.php?post=<hidden page>&post_type=post` would
	 * be filed under Posts and slip past a lock on Pages. On `edit.php`, `post-new.php` and the like the
	 * `post_type` argument does name the screen, so it is kept - but only when it is a registered type.
	 *
	 * @param string                    $file    Admin file of the request.
	 * @param array<string, mixed>|null $request Request values (tests); null reads $_REQUEST.
	 * @return array<string, string>
	 */
	public static function request_args( string $file, ?array $request = null ): array {
		$args = array();
		// $_REQUEST like WordPress itself ($typenow): saving a post sends post_type / post_ID in the body.
		// phpcs:disable WordPress.Security.NonceVerification
		$request = $request ?? $_REQUEST;
		$get     = static function ( string $key ) use ( $request ): string {
			return isset( $request[ $key ] ) && is_string( $request[ $key ] ) ? sanitize_text_field( wp_unslash( $request[ $key ] ) ) : '';
		};
		foreach ( array( 'page', 'taxonomy' ) as $key ) {
			if ( '' !== $get( $key ) ) {
				$args[ $key ] = $get( $key );
			}
		}
		if ( 'post.php' === $file ) {
			// The saved post (post_ID, a POST body) wins over the opened one (post); WordPress itself dies on a mismatch.
			$raw = '' !== $get( 'post_ID' ) ? $get( 'post_ID' ) : $get( 'post' );
			$id  = absint( $raw );
			if ( $id > 0 ) {
				$type = get_post_type( $id );
				if ( $type ) {
					$args['post_type'] = (string) $type;
				}
			}
		} elseif ( '' !== $get( 'post_type' ) && post_type_exists( $get( 'post_type' ) ) ) {
			$args['post_type'] = $get( 'post_type' );
		}
		// phpcs:enable
		return $args;
	}
}
