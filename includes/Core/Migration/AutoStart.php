<?php
/**
 * Offers the migration wizard once, right after UX Studio is activated from the Plugins screen.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\Onboarding\StarterPack;

defined( 'ABSPATH' ) || exit;

/**
 * Two moments, both decided by pure functions (tested without WordPress):
 *
 *  1. The activation hook runs inside `plugins.php?action=activate&plugin=...`. It only queues the offer
 *     (option flag `pending`) when this really is somebody clicking "Activate" on one plugin: not WP-CLI,
 *     not a bulk activation, not a network-wide one. Like core plugins do, it never redirects there.
 *  2. The next admin request (the Plugins screen the activation redirects to) looks at the flag, scans the
 *     site and sends the administrator to `#/wizard` ONCE, when at least one plugin can be taken over, or to
 *     `#/wizard?mode=new` (the starter pack) on a site with nothing to take over. The flag is spent on that
 *     request whatever the answer is, so the redirect never repeats.
 *
 * When the activation also took over from the legacy UX1 plugin (Handoff), the wizard does not jump in front of
 * its "delete the old plugin" notice: the redirect is skipped and the notice links to the wizard instead.
 *
 * The same scan feeds a small dismissible hint on the Modules screen for sites that update (no activation) or
 * where the offer was spent, as long as no migration ever ran.
 */
final class AutoStart {

	public const FLAG = 'uxstudio_wizard_autostart';

	public const WIZARD_PATH = 'admin.php?page=ux-studio#/wizard';

	/** The wizard in its "set up a new site" mode (nothing to migrate: the starter pack). */
	public const NEW_PATH = 'admin.php?page=ux-studio#/wizard?mode=new';

	private const DISMISS_META = 'uxstudio_wizard_hint_dismissed';

	/** Option the Handoff sets when it just took over from UX1 (kept in sync with Core\Handoff::OFFER_OPTION). */
	private const HANDOFF_OPTION = 'uxstudio_handoff_offer_delete';

	/**
	 * Wire the runtime hook.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ), 20 );
		// The cached hint count goes stale when plugins come and go.
		foreach ( array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush_hint_cache' ) );
		}
	}

	/** Transient with the count behind the Modules screen hint. */
	public const HINT_TRANSIENT = 'uxstudio_wizard_hint_count';

	/** How long the count is reused. */
	private const HINT_TTL = HOUR_IN_SECONDS;

	/** @var callable|null Test seam: replaces SourceRegistry::scan() for the hint. */
	private static $scanner = null;

	/**
	 * Replace the scan used by hint() (tests only).
	 *
	 * @param callable|null $scanner Returns the rows of a scan.
	 */
	public static function set_scanner( ?callable $scanner ): void {
		self::$scanner = $scanner;
	}

	/**
	 * Forget the cached count (a plugin was (de)activated or deleted, or a migration ran).
	 */
	public static function flush_hint_cache(): void {
		delete_transient( self::HINT_TRANSIENT );
	}

	/* ── Pure decisions ── */

	/**
	 * Should the activation queue the offer?
	 *
	 * @param array<string, mixed> $ctx network_wide, cli, ajax, action, plugin, self (basename of this plugin), flag (stored value).
	 * @return string `queue` or the reason it does not: `already`, `network`, `cli`, `ajax`, `bulk`, `not_ui`.
	 */
	public static function decide_activation( array $ctx ): string {
		if ( '' !== (string) ( $ctx['flag'] ?? '' ) ) {
			return 'already';
		}
		if ( ! empty( $ctx['network_wide'] ) ) {
			return 'network';
		}
		if ( ! empty( $ctx['cli'] ) ) {
			return 'cli';
		}
		if ( ! empty( $ctx['ajax'] ) ) {
			return 'ajax';
		}
		$action = (string) ( $ctx['action'] ?? '' );
		if ( 'activate-selected' === $action || 'activate-multi' === $action ) {
			return 'bulk';
		}
		$plugin = (string) ( $ctx['plugin'] ?? '' );
		$self   = (string) ( $ctx['self'] ?? '' );
		// Only the single "Activate" link of this very plugin counts (folder may be a symlink, so compare the file name too).
		if ( 'activate' !== $action || '' === $plugin || '' === $self || ( $plugin !== $self && basename( $plugin ) !== basename( $self ) ) ) {
			return 'not_ui';
		}
		return 'queue';
	}

	/**
	 * What the next admin request does about a queued offer.
	 *
	 * @param array<string, mixed> $ctx flag, can_manage, ajax, cron, rest, cli, network_admin, same_user, activate_multi,
	 *                                  handoff (bool), ran (bool: a migration ever ran), count (int|callable: sources that can be taken over).
	 * @return string `none` (nothing queued), `wait` (not a suitable request, keep the offer), `drop` (spend it without redirect),
	 *                `go` (migration wizard) or `new` (nothing to migrate: the starter pack for a new site).
	 */
	public static function decide_redirect( array $ctx ): string {
		if ( 'pending' !== (string) ( $ctx['flag'] ?? '' ) ) {
			return 'none';
		}
		foreach ( array( 'ajax', 'cron', 'rest', 'cli', 'network_admin' ) as $key ) {
			if ( ! empty( $ctx[ $key ] ) ) {
				return 'wait';
			}
		}
		// Only the administrator who clicked "Activate" gets the redirect (another one signing in later does not).
		if ( empty( $ctx['can_manage'] ) || ( array_key_exists( 'same_user', $ctx ) && ! $ctx['same_user'] ) ) {
			return 'wait';
		}
		if ( ! empty( $ctx['activate_multi'] ) || ! empty( $ctx['handoff'] ) || ! empty( $ctx['ran'] ) || ! empty( $ctx['starter_applied'] ) ) {
			return 'drop';
		}
		$count = is_callable( $ctx['count'] ?? null ) ? (int) $ctx['count']() : (int) ( $ctx['count'] ?? 0 );
		return $count > 0 ? 'go' : 'new';
	}

	/**
	 * How many scan rows can be taken over: an active plugin, or one that still holds data.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows of SourceRegistry::scan().
	 */
	public static function count_migratable( array $rows ): int {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( 'active' === ( $row['status'] ?? '' ) || ! empty( $row['has_data'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/* ── WordPress glue ── */

	/**
	 * Activation hook (also gets `$network_wide` from WordPress).
	 *
	 * @param bool $network_wide Whether activated for the whole network.
	 */
	public static function on_activation( $network_wide = false ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- reading the request only to tell how the plugin was activated; nothing is acted on.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( '' === $action && isset( $_REQUEST['action2'] ) ) {
			$action = sanitize_key( wp_unslash( (string) $_REQUEST['action2'] ) );
		}
		$plugin = isset( $_GET['plugin'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['plugin'] ) ) : '';
		// phpcs:enable
		$decision = self::decide_activation(
			array(
				'network_wide' => (bool) $network_wide,
				'cli'          => defined( 'WP_CLI' ) && WP_CLI,
				'ajax'         => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
				'action'       => $action,
				'plugin'       => $plugin,
				'self'         => defined( 'UXSTUDIO_FILE' ) ? plugin_basename( UXSTUDIO_FILE ) : '',
				'flag'         => (string) get_option( self::FLAG, '' ),
			)
		);
		if ( 'queue' === $decision ) {
			update_option( self::FLAG, 'pending', false );
			update_option( self::FLAG . '_user', get_current_user_id(), false );
		}
	}

	/**
	 * admin_init: spend a queued offer.
	 */
	public static function maybe_redirect(): void {
		if ( 'pending' !== (string) get_option( self::FLAG, '' ) ) {
			return;
		}
		$decision = self::decide_redirect(
			array(
				'flag'           => 'pending',
				'can_manage'     => current_user_can( 'manage_options' ),
				'same_user'      => (int) get_option( self::FLAG . '_user', 0 ) === get_current_user_id(),
				'ajax'           => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
				'cron'           => function_exists( 'wp_doing_cron' ) && wp_doing_cron(),
				'rest'           => defined( 'REST_REQUEST' ) && REST_REQUEST,
				'cli'            => defined( 'WP_CLI' ) && WP_CLI,
				'network_admin'  => function_exists( 'is_network_admin' ) && is_network_admin(),
				'activate_multi' => isset( $_GET['activate-multi'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'handoff'        => '1' === get_option( self::HANDOFF_OPTION ),
				'ran'            => array() !== History::all(),
				'starter_applied' => false !== get_option( StarterPack::APPLIED_OPTION, false ),
				'count'          => static fn(): int => self::count_migratable( SourceRegistry::scan() ),
			)
		);
		if ( 'wait' === $decision || 'none' === $decision ) {
			return;
		}
		// Spent now, whatever happens next: it must never repeat.
		update_option( self::FLAG, in_array( $decision, array( 'go', 'new' ), true ) ? 'done' : 'skipped', false );
		delete_option( self::FLAG . '_user' );
		if ( 'go' === $decision || 'new' === $decision ) {
			wp_safe_redirect( admin_url( 'new' === $decision ? self::NEW_PATH : self::WIZARD_PATH ) );
			exit;
		}
	}

	/**
	 * Hint for the Modules screen: how many plugins can be taken over, when no migration ever ran, and whether
	 * to offer "Set up new site" (the starter pack was never applied; offered after a migration too).
	 *
	 * @param int $user_id User who looks at the screen.
	 * @return array{show:bool,count:int,starter:bool}
	 */
	public static function hint( int $user_id ): array {
		$dismissed = '' !== (string) get_user_meta( $user_id, self::DISMISS_META, true );
		$starter   = ! $dismissed && false === get_option( StarterPack::APPLIED_OPTION, false );
		if ( array() !== History::all() || $dismissed ) {
			return array(
				'show'    => false,
				'count'   => 0,
				'starter' => $starter,
			);
		}
		// The full scan reads the data of every known foreign plugin; the Modules screen asks on every visit, so
		// the answer is reused for an hour (flushed when plugins change or a migration runs).
		$count = get_transient( self::HINT_TRANSIENT );
		if ( false === $count ) {
			$count = self::count_migratable( null !== self::$scanner ? ( self::$scanner )() : SourceRegistry::scan() );
			set_transient( self::HINT_TRANSIENT, $count, self::HINT_TTL );
		}
		$count = (int) $count;
		return array(
			'show'    => $count > 0,
			'count'   => $count,
			'starter' => $starter,
		);
	}

	/**
	 * The user closed the hint.
	 *
	 * @param int $user_id User.
	 */
	public static function dismiss_hint( int $user_id ): void {
		update_user_meta( $user_id, self::DISMISS_META, (string) time() );
	}
}
