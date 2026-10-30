<?php
/**
 * Safe preview: starts and ends it, keeps its state, installs the mu-plugin that does the work.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.10. A preview lets ONE logged-in admin (cookie) or the server itself (loopback
 * header) see the site without the old plugins and with the target modules on, while every
 * other visitor keeps getting the old plugins. The early part (PreviewBoot) runs from the mu-plugin
 * `uxstudio-preview.php`, which this class writes when a preview starts and deletes when the
 * last one ends.
 *
 * State, option `uxstudio_migration_preview` (autoloaded, tiny):
 *   runs[run id] = { run_id, user_id, sources[], plugins[] (basenames removed in preview),
 *                    modules[] (added in preview), started, expires, results{} }
 *
 * Durable test results (they must outlive the 30 minutes) go to the run history (`preview`).
 */
final class Preview {

	public const MU_FILE    = 'uxstudio-preview.php';
	public const MU_VERSION = 1;

	/** Preview results older than this no longer allow the switch. */
	public const RESULT_MAX_AGE = DAY_IN_SECONDS;

	/* ── Seen from the engines of the target modules ── */

	/**
	 * Is this request a preview request?
	 */
	public static function is_active(): bool {
		return PreviewBoot::is_active();
	}

	/**
	 * Ids of data staged for the run (inactive redirect rules, snippets ...) that a preview request
	 * must treat as active. Empty outside a preview.
	 *
	 * @param string $type Kind a source registered (`redirect`, `snippet` ...).
	 * @return string[]
	 */
	public static function ids( string $type ): array {
		return PreviewBoot::is_active() ? PreviewBoot::ids( $type ) : array();
	}

	/**
	 * Should the preview treat this staged object as active?
	 *
	 * @param string     $type Kind.
	 * @param int|string $id   Object id.
	 */
	public static function includes( string $type, $id ): bool {
		return in_array( (string) $id, self::ids( $type ), true );
	}

	/* ── State ── */

	/**
	 * All previews.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function runs(): array {
		$state = get_option( PreviewBoot::STATE_OPTION, array() );
		$runs  = is_array( $state ) && is_array( $state['runs'] ?? null ) ? $state['runs'] : array();
		return array_filter( $runs, 'is_array' );
	}

	/**
	 * One preview.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function entry( string $run_id ): ?array {
		return self::runs()[ Snapshot::clean_id( $run_id ) ] ?? null;
	}

	/**
	 * Store the previews (removes the option when none is left).
	 *
	 * @param array<string, array<string, mixed>> $runs Previews.
	 */
	private static function save( array $runs ): void {
		if ( ! $runs ) {
			delete_option( PreviewBoot::STATE_OPTION );
			return;
		}
		update_option( PreviewBoot::STATE_OPTION, array( 'runs' => $runs ), true );
	}

	/**
	 * Previews that have not expired.
	 *
	 * @param int|null $now Test seam.
	 * @return array<string, array<string, mixed>>
	 */
	public static function live_runs( ?int $now = null ): array {
		$now = $now ?? time();
		return array_filter( self::runs(), static fn( array $e ): bool => (int) ( $e['expires'] ?? 0 ) > $now );
	}

	/* ── Availability ── */

	/**
	 * Can a preview run on this server? The mu-plugin has to be installed for it.
	 *
	 * @return array{ok:bool,reason:string,path:string}
	 */
	public static function availability(): array {
		$path = self::mu_dir();
		if ( '' === PreviewBoot::salt() ) {
			return array(
				'ok'     => false,
				'reason' => __( 'The site has no security keys (AUTH_KEY and AUTH_SALT in wp-config.php), so the preview cannot be protected with a signed token.', 'ux-studio' ),
				'path'   => $path,
			);
		}
		if ( '' === $path ) {
			return array(
				'ok'     => false,
				'reason' => __( 'The folder for must-use plugins is not defined on this site.', 'ux-studio' ),
				'path'   => '',
			);
		}
		if ( is_dir( $path ) ) {
			$writable = is_writable( $path );
		} else {
			$writable = is_dir( dirname( $path ) ) && is_writable( dirname( $path ) );
		}
		/**
		 * Lets a host (or a test) say that the must-use folder is not writable although PHP thinks so.
		 *
		 * @param bool   $writable Whether the folder (or its parent, if it does not exist yet) is writable.
		 * @param string $path     The must-use plugins folder.
		 */
		$writable = (bool) apply_filters( 'uxstudio_preview_mu_writable', $writable, $path );
		if ( ! $writable ) {
			return array(
				'ok'     => false,
				'reason' => sprintf(
					/* translators: %s: folder path. */
					__( 'The folder %s is not writable, so the preview cannot install the small file that switches the old plugins off for your browser only.', 'ux-studio' ),
					$path
				),
				'path'   => $path,
			);
		}
		return array(
			'ok'     => true,
			'reason' => '',
			'path'   => $path,
		);
	}

	/**
	 * Folder of must-use plugins.
	 */
	public static function mu_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '';
	}

	/**
	 * Absolute path of the preview mu-plugin.
	 */
	public static function mu_path(): string {
		return rtrim( self::mu_dir(), '/\\' ) . '/' . self::MU_FILE;
	}

	/**
	 * Source of the mu-plugin. Tiny and inert: it does nothing without a preview cookie / header,
	 * and when UX Studio is not active (or its file is gone) it does nothing at all, so a leftover
	 * file can never break a site.
	 *
	 * @param string $basename Plugin basename of UX Studio (`folder/ux-studio.php`).
	 */
	public static function mu_source( string $basename ): string {
		$folder = dirname( $basename );
		return "<?php\n"
			. "/**\n"
			. " * Plugin Name: UX Studio Safe Preview\n"
			. " * Description: Lets one logged-in administrator preview the site without the old plugins during a UX Studio migration. Inert without a signed preview token; removed automatically when the preview ends.\n"
			. " * Author: UX Studio\n"
			. ' * Version: ' . self::MU_VERSION . "\n"
			. " */\n\n"
			. "defined( 'ABSPATH' ) || exit;\n\n"
			. "( static function () {\n"
			. "\tif ( empty( \$_COOKIE['" . PreviewBoot::COOKIE . "'] ) && empty( \$_SERVER['" . PreviewBoot::SERVER_HEADER . "'] ) ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\t\$plugin = " . var_export( $basename, true ) . ";\n"
			. "\t\$boot   = WP_PLUGIN_DIR . '/' . " . var_export( $folder . '/includes/Core/Migration/PreviewBoot.php', true ) . ";\n"
			. "\tif ( ! is_file( \$boot ) ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\t\$active = in_array( \$plugin, (array) get_option( 'active_plugins', array() ), true );\n"
			. "\tif ( ! \$active && is_multisite() ) {\n"
			. "\t\t\$active = isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ \$plugin ] );\n"
			. "\t}\n"
			. "\tif ( ! \$active ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\trequire_once \$boot;\n"
			. "\t\\UxStudio\\Core\\Migration\\PreviewBoot::init();\n"
			. "} )();\n";
	}

	/**
	 * Install (or refresh) the mu-plugin.
	 *
	 * @return true|\WP_Error
	 */
	public static function install_mu_plugin() {
		$avail = self::availability();
		if ( ! $avail['ok'] ) {
			return new \WP_Error( 'uxstudio_preview_unavailable', $avail['reason'], array( 'status' => 409 ) );
		}
		$dir = self::mu_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$source = self::mu_source( plugin_basename( UXSTUDIO_FILE ) );
		$path   = self::mu_path();
		if ( is_file( $path ) && (string) file_get_contents( $path ) === $source ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return true;
		}
		$tmp = $path . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a mu-plugin cannot be written through WP_Filesystem reliably, and the swap must be atomic.
		if ( false === file_put_contents( $tmp, $source ) || ! rename( $tmp, $path ) ) {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new \WP_Error(
				'uxstudio_preview_unavailable',
				sprintf(
					/* translators: %s: file path. */
					__( 'The preview file %s could not be written.', 'ux-studio' ),
					$path
				),
				array( 'status' => 409 )
			);
		}
		return true;
	}

	/**
	 * Delete the mu-plugin when no preview is left.
	 */
	public static function remove_mu_plugin_if_unused(): void {
		if ( self::runs() ) {
			return;
		}
		$path = self::mu_path();
		if ( '' !== self::mu_dir() && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/* ── Start / stop ── */

	/**
	 * Run ids' sources that migrated cleanly.
	 *
	 * @param array<string, mixed> $record History record.
	 * @return string[]
	 */
	public static function sources_of( array $record ): array {
		$ids = array();
		foreach ( (array) ( $record['sources'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && 'done' === ( $row['status'] ?? '' ) ) {
				$ids[] = (string) $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * Active basenames of the plugins the sources stand for and the modules that take over.
	 *
	 * @param string[] $source_ids Source ids.
	 * @param string   $run_id     Run id (for the modules that only have staged values).
	 * @return array{plugins:string[],modules:string[]}
	 */
	public static function targets( array $source_ids, string $run_id ): array {
		$plugins = array();
		$modules = array();
		foreach ( $source_ids as $id ) {
			$source = SourceRegistry::get( (string) $id );
			if ( ! $source ) {
				continue;
			}
			foreach ( $source->plugin_slugs() as $slug ) {
				$info = PluginInfo::find( (string) $slug );
				if ( $info && $info['active'] ) {
					$plugins[] = $info['basename'];
				}
			}
			$module = $source->target_module();
			if ( '' !== $module && Catalog::ANY_MODULE !== $module ) {
				$modules[] = $module;
			}
			if ( method_exists( $source, 'extra_modules' ) ) {
				foreach ( (array) $source->extra_modules() as $extra ) {
					$modules[] = (string) $extra;
				}
			}
		}
		foreach ( Staging::summary( $run_id )['modules'] as $module ) {
			if ( '' !== $module && Catalog::ANY_MODULE !== $module ) {
				$modules[] = $module;
			}
		}
		return array(
			'plugins' => array_values( array_unique( $plugins ) ),
			'modules' => array_values( array_unique( $modules ) ),
		);
	}

	/**
	 * Start a preview of a run for a user.
	 *
	 * @param string $run_id  Run id.
	 * @param int    $user_id Administrator who will look at it.
	 * @return array<string, mixed>|\WP_Error The preview entry.
	 */
	public static function start( string $run_id, int $user_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$record = History::find( $run_id );
		if ( ! $record ) {
			return new \WP_Error( 'uxstudio_migration_unknown_run', __( 'This migration run does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $record['rolled_back'] ) ) {
			return new \WP_Error( 'uxstudio_migration_rolled_back', __( 'This run was already rolled back.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $record['switched'] ) ) {
			return new \WP_Error( 'uxstudio_migration_switched', __( 'This run is already turned on. Undo the switch to preview it again.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$sources = self::sources_of( $record );
		if ( ! $sources ) {
			return new \WP_Error( 'uxstudio_preview_nothing', __( 'No plugin of this run was migrated, so there is nothing to preview.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		$installed = self::install_mu_plugin();
		if ( is_wp_error( $installed ) ) {
			return $installed;
		}
		PluginInfo::flush();
		$targets = self::targets( $sources, $run_id );
		$now     = time();
		$runs    = self::runs();

		$runs[ $run_id ] = array(
			'run_id'  => $run_id,
			'user_id' => $user_id,
			'sources' => $sources,
			'plugins' => $targets['plugins'],
			'modules' => $targets['modules'],
			'started' => $now,
			'expires' => $now + PreviewBoot::TTL,
			'results' => array(),
		);
		self::save( $runs );
		ActivityLog::log( 'core', 'migration_preview_started', 'migration', 0, array( 'run' => $run_id, 'plugins' => $targets['plugins'], 'modules' => $targets['modules'] ) );
		return $runs[ $run_id ];
	}

	/**
	 * End a preview: its entry goes, the mu-plugin goes when it was the last one. The test
	 * results stay in the run history.
	 *
	 * @param string $run_id Run id.
	 */
	public static function stop( string $run_id ): void {
		$run_id = Snapshot::clean_id( $run_id );
		$runs   = self::runs();
		if ( isset( $runs[ $run_id ] ) ) {
			unset( $runs[ $run_id ] );
			self::save( $runs );
			ActivityLog::log( 'core', 'migration_preview_ended', 'migration', 0, array( 'run' => $run_id ) );
		}
		self::remove_mu_plugin_if_unused();
	}

	/**
	 * End every preview and remove the mu-plugin (plugin deactivation).
	 */
	public static function stop_all(): void {
		delete_option( PreviewBoot::STATE_OPTION );
		self::remove_mu_plugin_if_unused();
	}

	/**
	 * Drop expired previews (and the mu-plugin when none is left). Cheap when nothing is stored.
	 *
	 * @param int|null $now Test seam.
	 * @return string[] Run ids dropped.
	 */
	public static function purge_expired( ?int $now = null ): array {
		$runs = self::runs();
		if ( ! $runs ) {
			// A leftover file without any state is harmless but pointless: remove it.
			self::remove_mu_plugin_if_unused();
			return array();
		}
		$now  = $now ?? time();
		$gone = array();
		foreach ( $runs as $id => $entry ) {
			if ( (int) ( $entry['expires'] ?? 0 ) <= $now ) {
				unset( $runs[ $id ] );
				$gone[] = (string) $id;
			}
		}
		if ( $gone ) {
			self::save( $runs );
			self::remove_mu_plugin_if_unused();
		}
		return $gone;
	}

	/* ── Test results ── */

	/**
	 * Remember the outcome of one check, in the preview and (durably) in the run history.
	 *
	 * @param string               $run_id Run id.
	 * @param array<string, mixed> $result Result: id, label, status, detail, data?.
	 */
	public static function record_result( string $run_id, array $result ): void {
		$run_id = Snapshot::clean_id( $run_id );
		$runs   = self::runs();
		if ( isset( $runs[ $run_id ] ) ) {
			$runs[ $run_id ]['results'][ (string) $result['id'] ] = $result;
			self::save( $runs );
		}
		$record  = History::find( $run_id );
		$summary = (array) ( $record['preview'] ?? array() );
		$all     = (array) ( $summary['results'] ?? array() );
		// A new test session starts clean: results older than the previous session do not mix in.
		if ( (int) ( $summary['at'] ?? 0 ) < time() - PreviewBoot::TTL ) {
			$all = array();
		}
		$all[ (string) $result['id'] ] = $result;
		History::update(
			$run_id,
			array(
				'preview' => array(
					'at'      => time(),
					'results' => $all,
					'counts'  => self::counts( $all ),
				),
			)
		);
	}

	/**
	 * ok / warn / fail / info counts.
	 *
	 * @param array<string, array<string, mixed>> $results Results by check id.
	 * @return array{ok:int,warn:int,fail:int,info:int}
	 */
	public static function counts( array $results ): array {
		$out = array(
			'ok'   => 0,
			'warn' => 0,
			'fail' => 0,
			'info' => 0,
		);
		foreach ( $results as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $out[ $status ] ) ) {
				++$out[ $status ];
			}
		}
		return $out;
	}

	/* ── Browser ── */

	/**
	 * Send the preview cookie for the current logged-in session.
	 *
	 * @param string $run_id Run id.
	 * @return true|\WP_Error
	 */
	public static function set_cookie( string $run_id ) {
		$entry = self::entry( $run_id );
		$user  = (int) get_current_user_id();
		if ( ! $entry || (int) $entry['user_id'] !== $user || (int) $entry['expires'] <= time() ) {
			return new \WP_Error( 'uxstudio_preview_expired', __( 'The preview has expired. Start it again.', 'ux-studio' ), array( 'status' => 410 ) );
		}
		$bind  = PreviewBoot::session_bind( function_exists( 'wp_unslash' ) ? (array) wp_unslash( $_COOKIE ) : $_COOKIE ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$token = PreviewBoot::make_token( $entry['run_id'], $user, (int) $entry['expires'], 'c', $bind );
		if ( '' === $token ) {
			return new \WP_Error( 'uxstudio_preview_unavailable', __( 'The preview needs a normal browser login session.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		self::send_cookie( $token, (int) $entry['expires'] );
		return true;
	}

	/**
	 * Remove the preview cookie from the browser.
	 */
	public static function clear_cookie(): void {
		self::send_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry.
	 */
	private static function send_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			PreviewBoot::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * URL that opens the site in the preview (sets the cookie, then redirects to the home page).
	 *
	 * @param string $run_id Run id.
	 */
	public static function enter_url( string $run_id ): string {
		return self::action_url( PreviewBoot::ACTION_ENTER, $run_id );
	}

	/**
	 * URL that ends the preview from the banner.
	 *
	 * @param string $run_id Run id.
	 */
	public static function exit_url( string $run_id ): string {
		return self::action_url( PreviewBoot::ACTION_EXIT, $run_id );
	}

	/**
	 * admin-post URL with a nonce (plain `&`, it goes into JSON as well as into HTML that escapes it).
	 *
	 * @param string $action Action.
	 * @param string $run_id Run id.
	 */
	private static function action_url( string $action, string $run_id ): string {
		return add_query_arg(
			array(
				'action'   => $action,
				'run'      => $run_id,
				'_wpnonce' => wp_create_nonce( $action . $run_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Wizard address a browser returns to after the preview.
	 */
	public static function wizard_url(): string {
		return admin_url( 'admin.php?page=ux-studio#/wizard' );
	}

	/* ── Hooks ── */

	/**
	 * Register the enter / exit actions and, in a preview request, the banner.
	 */
	public static function register(): void {
		add_action( 'admin_post_' . PreviewBoot::ACTION_ENTER, array( self::class, 'handle_enter' ) );
		add_action( 'admin_post_' . PreviewBoot::ACTION_EXIT, array( self::class, 'handle_exit' ) );
		if ( self::is_active() && 'c' === ( PreviewBoot::context()['scope'] ?? '' ) ) {
			add_action( 'wp_footer', array( self::class, 'render_banner' ), 9999 );
			add_action( 'admin_footer', array( self::class, 'render_banner' ), 9999 );
			add_action( 'login_footer', array( self::class, 'render_banner' ), 9999 );
		}
	}

	/**
	 * admin-post: set the cookie and open the site.
	 */
	public static function handle_enter(): void {
		$run = Snapshot::clean_id( (string) ( $_GET['run'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next line.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'ux-studio' ) );
		}
		check_admin_referer( PreviewBoot::ACTION_ENTER . $run );
		$result = self::set_cookie( $run );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * admin-post: end the preview from the banner.
	 */
	public static function handle_exit(): void {
		$run = Snapshot::clean_id( (string) ( $_GET['run'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked next line.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this module.', 'ux-studio' ) );
		}
		check_admin_referer( PreviewBoot::ACTION_EXIT . $run );
		self::clear_cookie();
		self::stop( $run );
		wp_safe_redirect( self::wizard_url() );
		exit;
	}

	/**
	 * The bar every preview page shows.
	 */
	public static function render_banner(): void {
		static $done = false;
		$ctx = PreviewBoot::context();
		if ( $done || ! $ctx ) {
			return;
		}
		$done = true;
		$text = sprintf(
			/* translators: %s: time the preview ends (HH:MM). */
			__( 'Preview without the old plugins - ends at %s', 'ux-studio' ),
			wp_date( 'H:i', (int) $ctx['expires'] )
		);
		printf(
			'<div id="uxs-preview-bar" role="status" style="position:fixed;left:50%%;bottom:14px;transform:translateX(-50%%);z-index:2147483647;display:flex;gap:14px;align-items:center;padding:10px 16px;border-radius:999px;background:#1e1b4b;color:#fff;font:600 13px/1.3 system-ui,-apple-system,Segoe UI,sans-serif;box-shadow:0 8px 28px rgba(0,0,0,.35);max-width:calc(100vw - 24px)"><span>%s</span><a href="%s" style="color:#c7d2fe;text-decoration:underline;white-space:nowrap">%s</a></div>',
			esc_html( $text ),
			esc_url( self::exit_url( (string) $ctx['run'] ) ),
			esc_html__( 'End preview', 'ux-studio' )
		);
	}
}
