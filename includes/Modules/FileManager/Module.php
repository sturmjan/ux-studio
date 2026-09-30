<?php
/**
 * File Manager module - thin wrapper around the unmodified legacy Tiny File
 * Manager (TFM) third-party library.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\FileManager;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Settings;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * SECURITY: this module exposes the entire server filesystem through the
 * browser. The legacy security model is preserved 1:1 during the port:
 *
 * - legacy/tinyfilemanager.php and legacy/config.php are carried over
 *   unmodified (third-party MIT code, github.com/prasathmani/tinyfilemanager
 *   v2.6), except for the UXSTUDIO_FM_EMBEDDED marker rename in config.php.
 * - TFM's own authentication ($use_auth) is disabled; WordPress is the only
 *   auth boundary (this file).
 * - Both the standalone route and TFM itself refuse to run unless the
 *   UXSTUDIO_FM_EMBEDDED marker is defined by loadTinyFileManager() below,
 *   which only happens after is_user_logged_in() + the allowed_users
 *   whitelist have both been checked.
 * - Logged-out visitors are sent to the site's regular login page (no own
 *   login form - CAPTCHA/lockout/custom login URL stay in force).
 * - DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS switch the route off; on
 *   multisite only super admins (edit_plugins) get in.
 * - legacy/.htaccess denies direct HTTP access to the legacy/ folder.
 */
final class Module extends BaseModule {

	private const ROUTE_SLUG = 'spravce-souboru';

	private const OPTION = 'uxstudio_file_manager';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Must run on init (not admin_init) - this route lives on the public
		// frontend (/spravce-souboru/), same timing as the legacy module.
		add_action( 'init', array( $this, 'handle_standalone_route' ) );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		}
	}

	/**
	 * No schema-driven settings - this module manages its own state
	 * (allowed_users, root_path, readonly_mode) through its dedicated admin
	 * page form, not the generic SPA settings renderer.
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Managing this module requires an admin who may edit plugin code:
	 * edit_plugins is denied by core under DISALLOW_FILE_EDIT/MODS and is
	 * limited to super admins on multisite.
	 */
	public function capability(): string {
		return 'edit_plugins';
	}

	/**
	 * Non-empty (a human-readable reason) when wp-config.php disallows file
	 * edits/mods - a browser file manager must not become a way around them.
	 */
	public static function file_mods_block_reason(): string {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'DISALLOW_FILE_MODS is set in wp-config.php, so the File Manager is disabled.', 'ux-studio' );
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'DISALLOW_FILE_EDIT is set in wp-config.php, so the File Manager is disabled.', 'ux-studio' );
		}
		return '';
	}

	/**
	 * Administrator (manage_options) who may also edit plugin files
	 * (edit_plugins: super admins only on multisite).
	 */
	private static function user_has_caps(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'edit_plugins' );
	}

	// ═══════════════════════════════════════════════════
	//  ADMIN PAGE
	// ═══════════════════════════════════════════════════

	/**
	 * Own dedicated wp-admin page (not part of the React SPA).
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'ux-studio',
			__( 'File Manager', 'ux-studio' ),
			__( 'File Manager', 'ux-studio' ),
			'manage_options',
			'ux-studio-file-manager',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the launcher (popup/iframe) + settings form.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ux-studio' ) );
		}

		$this->handle_settings_save();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'File Manager', 'ux-studio' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		settings_errors( 'uxstudio_fm_notices' );

		$popup_url  = home_url( '/' . self::ROUTE_SLUG . '/' );
		$blocked    = self::file_mods_block_reason();
		$is_allowed = '' === $blocked && self::user_has_caps() && $this->is_user_allowed( get_current_user_id() );
		if ( '' !== $blocked ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $blocked ) );
		} elseif ( ! self::user_has_caps() ) {
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html__( 'Only administrators allowed to edit plugin files (super admins on multisite) can use the File Manager.', 'ux-studio' ) );
		}
		?>
		<style>
			.uxs-fm-path-warning { color: #d63638; font-size: 13px; margin-top: 4px; }
			.uxs-fm-launch-wrap { margin-top: 10px; }
			.uxs-fm-settings-table th { width: 240px; }
			.uxs-fm-users-list { max-height: 220px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 12px; max-width: 420px; background: #fff; }
			.uxs-fm-users-list label { display: block; padding: 2px 0; }
		</style>
		<div class="uxs-fm-launch-wrap">
			<?php if ( $is_allowed ) : ?>
				<button type="button" class="button button-primary button-hero" id="uxs-fm-open-popup">
					<span class="dashicons dashicons-open-folder" style="margin-top:4px;margin-right:4px;"></span>
					<?php esc_html_e( 'Open File Manager', 'ux-studio' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="button button-hero" disabled>
					<span class="dashicons dashicons-open-folder" style="margin-top:4px;margin-right:4px;"></span>
					<?php esc_html_e( 'Open File Manager', 'ux-studio' ); ?>
				</button>
				<p class="description" style="margin-top:8px;color:#d63638;">
					<?php esc_html_e( 'You do not have access to the File Manager. Add your account to the allowed users list below.', 'ux-studio' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( $is_allowed ) : ?>
		<div id="uxs-fm-overlay" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.6);">
			<div style="position:absolute;inset:20px;background:#fff;border-radius:8px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.3);">
				<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 16px;background:#1d2327;color:#fff;flex-shrink:0;">
					<span style="font-size:14px;font-weight:600;">
						<span class="dashicons dashicons-open-folder" style="margin-right:4px;font-size:16px;line-height:20px;"></span>
						<?php esc_html_e( 'File Manager', 'ux-studio' ); ?>
					</span>
					<button type="button" id="uxs-fm-close-overlay" style="background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:0 4px;line-height:1;" title="<?php esc_attr_e( 'Close', 'ux-studio' ); ?>">&times;</button>
				</div>
				<iframe id="uxs-fm-iframe" title="<?php esc_attr_e( 'File Manager', 'ux-studio' ); ?>" style="flex:1;border:none;width:100%;"></iframe>
			</div>
		</div>
		<script>
		(function(){
			var overlay = document.getElementById('uxs-fm-overlay');
			var iframe = document.getElementById('uxs-fm-iframe');
			function closeOverlay() {
				overlay.style.display = 'none';
				iframe.src = '';
				document.body.style.overflow = '';
			}
			document.getElementById('uxs-fm-open-popup').addEventListener('click', function() {
				iframe.src = <?php echo wp_json_encode( $popup_url ); ?>;
				overlay.style.display = '';
				document.body.style.overflow = 'hidden';
			});
			document.getElementById('uxs-fm-close-overlay').addEventListener('click', closeOverlay);
			overlay.addEventListener('click', function(e) {
				if (e.target === overlay) {
					closeOverlay();
				}
			});
			document.addEventListener('keydown', function(e) {
				if (e.key === 'Escape' && overlay.style.display !== 'none') {
					closeOverlay();
				}
			});
		})();
		</script>
		<?php endif; ?>

		<h2 style="margin-top:30px;"><?php esc_html_e( 'Settings', 'ux-studio' ); ?></h2>
		<?php
		$this->render_settings();

		echo '</div>';
	}

	// ═══════════════════════════════════════════════════
	//  SETTINGS
	// ═══════════════════════════════════════════════════

	/**
	 * Render the settings form (allowed users, root path, readonly mode).
	 */
	private function render_settings(): void {
		$allowed_raw = (string) $this->settings->get( 'allowed_users', '' );
		$allowed_ids = '' !== $allowed_raw ? array_map( 'intval', explode( ',', $allowed_raw ) ) : array();
		$root_path   = (string) $this->settings->get( 'root_path', ABSPATH );
		$readonly    = (string) $this->settings->get( 'readonly_mode', '' );

		$admin_users = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'orderby'  => 'display_name',
			)
		);
		?>
		<form method="post">
			<?php wp_nonce_field( 'uxstudio_fm_settings_nonce' ); ?>
			<table class="form-table uxs-fm-settings-table" role="presentation">
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Allowed users', 'ux-studio' ); ?></label>
						<p class="description" style="font-weight:normal;margin-top:4px;">
							<?php esc_html_e( 'Choose which administrators can access the File Manager.', 'ux-studio' ); ?>
						</p>
					</th>
					<td>
						<?php if ( empty( $admin_users ) ) : ?>
							<p class="description"><?php esc_html_e( 'No administrators found.', 'ux-studio' ); ?></p>
						<?php else : ?>
							<div class="uxs-fm-users-list">
								<?php foreach ( $admin_users as $admin_user ) : ?>
									<label>
										<input type="checkbox" name="uxstudio_fm_allowed_users[]"
											value="<?php echo esc_attr( $admin_user->ID ); ?>"
											<?php checked( in_array( $admin_user->ID, $allowed_ids, true ) ); ?>>
										<?php echo esc_html( $admin_user->display_name ); ?> (<?php echo esc_html( $admin_user->user_login ); ?>)
									</label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="uxstudio_fm_root_path"><?php esc_html_e( 'Root directory', 'ux-studio' ); ?></label>
					</th>
					<td>
						<input type="text" name="uxstudio_fm_root_path" id="uxstudio_fm_root_path" class="regular-text code"
							value="<?php echo esc_attr( $root_path ); ?>">
						<p class="description">
							<?php echo esc_html__( 'Default:', 'ux-studio' ) . ' <code>' . esc_html( ABSPATH ) . '</code>'; ?>
						</p>
						<?php if ( '' !== $root_path && ! is_dir( $root_path ) ) : ?>
							<p class="uxs-fm-path-warning">
								<?php esc_html_e( 'This directory does not exist!', 'ux-studio' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="uxstudio_fm_readonly_mode"><?php esc_html_e( 'Read-only', 'ux-studio' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="uxstudio_fm_readonly_mode" id="uxstudio_fm_readonly_mode" value="1"
								<?php checked( $readonly, '1' ); ?>>
							<?php esc_html_e( 'Disable editing, deleting and uploading files', 'ux-studio' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'ux-studio' ), 'primary', 'uxstudio_fm_save_settings' ); ?>
		</form>
		<?php
	}

	/**
	 * Persist the settings form (own custom form, not the generic REST
	 * settings pipeline - see settings_schema()).
	 */
	private function handle_settings_save(): void {
		if ( ! isset( $_POST['uxstudio_fm_save_settings'] ) ) {
			return;
		}
		check_admin_referer( 'uxstudio_fm_settings_nonce' );
		if ( ! self::user_has_caps() || '' !== self::file_mods_block_reason() ) {
			return;
		}

		$allowed_users = isset( $_POST['uxstudio_fm_allowed_users'] ) && is_array( $_POST['uxstudio_fm_allowed_users'] )
			? implode( ',', array_map( 'absint', wp_unslash( $_POST['uxstudio_fm_allowed_users'] ) ) )
			: '';

		$root_path = isset( $_POST['uxstudio_fm_root_path'] ) ? sanitize_text_field( wp_unslash( $_POST['uxstudio_fm_root_path'] ) ) : ABSPATH;
		if ( ! is_dir( $root_path ) ) {
			$root_path = ABSPATH;
		}
		$root_path = rtrim( $root_path, '/\\' ) . '/';

		$readonly = isset( $_POST['uxstudio_fm_readonly_mode'] ) ? '1' : '';

		$stored                    = (array) get_option( self::OPTION, array() );
		$stored['allowed_users']   = $allowed_users;
		$stored['root_path']       = $root_path;
		$stored['readonly_mode']   = $readonly;
		update_option( self::OPTION, $stored );

		// Reset the lazily-cached Settings values so render_settings() (called
		// right after this, in the same request) reads back what was just saved.
		$this->settings = new Settings( self::OPTION );

		ActivityLog::log( 'file-manager', 'settings_saved' );

		add_settings_error(
			'uxstudio_fm_notices',
			'uxstudio_fm_saved',
			__( 'Settings saved.', 'ux-studio' ),
			'success'
		);
	}

	// ═══════════════════════════════════════════════════
	//  STANDALONE MODE  (/spravce-souboru/)
	// ═══════════════════════════════════════════════════

	/**
	 * Intercept the standalone frontend route before anything else renders.
	 */
	public function handle_standalone_route(): void {
		$path     = trim( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		$base     = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$relative = '' !== $base ? preg_replace( '#^' . preg_quote( $base, '#' ) . '/#', '', $path ) : $path;

		if ( self::ROUTE_SLUG !== $relative ) {
			return;
		}

		$route_url = home_url( '/' . self::ROUTE_SLUG . '/' );

		// Logout: GET must carry a log-out nonce (wp_logout_url() style), so a
		// third-party page can't log the user out by embedding this URL.
		if ( isset( $_GET['logout'] ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'log-out' ) ) {
				wp_nonce_ays( 'log-out' );
			}
			wp_logout();
			wp_safe_redirect( $route_url );
			exit;
		}

		// File editing disallowed site-wide (or not a super admin on multisite):
		// this route exposes the filesystem, so it refuses before any login.
		$blocked = self::file_mods_block_reason();
		if ( '' !== $blocked ) {
			wp_die( esc_html( $blocked ), esc_html__( 'Access denied', 'ux-studio' ), array( 'response' => 403 ) );
		}

		// Auto-login from ?auth=user:pass.
		// Deliberately preserved from the legacy module even though it puts a
		// password in the URL/server logs - explicitly confirmed by the site
		// owner as a required convenience feature. Do not "fix" this without
		// checking with them first. It runs through the regular authenticate
		// filter chain (lockout / CAPTCHA / blocked usernames) and only ever
		// logs in a whitelisted user - anyone else gets no session at all.
		$auth = isset( $_GET['auth'] ) ? sanitize_text_field( wp_unslash( $_GET['auth'] ) ) : '';
		if ( $auth && ! is_user_logged_in() ) {
			$parts = explode( ':', $auth, 2 );
			if ( 2 === count( $parts ) ) {
				$this->auth_from_query( $parts[0], $parts[1], $route_url );
			}
		}

		// Must be logged in - through the site's regular login page (CAPTCHA,
		// lockout and a custom login URL all apply there).
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $route_url ) );
			exit;
		}

		// Whitelist check (+ administrator who may edit plugin files).
		if ( ! $this->is_user_allowed( get_current_user_id() ) || ! self::user_has_caps() ) {
			wp_die(
				esc_html__( 'You do not have access to the File Manager. Ask an administrator to add you to the allowed users list.', 'ux-studio' ),
				esc_html__( 'Access denied', 'ux-studio' ),
				array( 'response' => 403 )
			);
		}

		ActivityLog::log( 'file-manager', 'open' );

		$this->load_tinyfilemanager();
		exit;
	}

	/**
	 * ?auth=user:pass login: wp_authenticate() (the full `authenticate` filter
	 * chain, `wp_login_failed` on failure), then the whitelist BEFORE any auth
	 * cookie is set.
	 *
	 * @param string $login     Username or e-mail.
	 * @param string $password  Password.
	 * @param string $route_url Where to go after login.
	 */
	private function auth_from_query( string $login, string $password, string $route_url ): void {
		/** Same pre-auth action wp_signon() fires (some CAPTCHA/lockout plugins hook it). */
		do_action_ref_array( 'wp_authenticate', array( &$login, &$password ) );

		$user = wp_authenticate( $login, $password );
		if ( is_wp_error( $user ) ) {
			// Generic message: the core one tells apart unknown user / wrong password.
			wp_die(
				esc_html__( 'Login failed:', 'ux-studio' ) . ' ' . esc_html__( 'Invalid credentials.', 'ux-studio' ),
				esc_html__( 'Error', 'ux-studio' ),
				array( 'response' => 403 )
			);
		}

		if ( ! $this->is_user_allowed( (int) $user->ID ) || ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'edit_plugins' ) ) {
			ActivityLog::log( 'file-manager', 'auth_denied', 'user', (int) $user->ID );
			wp_die(
				esc_html__( 'You do not have access to the File Manager. Ask an administrator to add you to the allowed users list.', 'ux-studio' ),
				esc_html__( 'Access denied', 'ux-studio' ),
				array( 'response' => 403 )
			);
		}

		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		wp_set_current_user( $user->ID );
		/** Core login action (resets lockout counters, login logs...). */
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( $route_url );
		exit;
	}

	/**
	 * @param int $user_id WP user id.
	 */
	private function is_user_allowed( int $user_id ): bool {
		$raw = (string) $this->settings->get( 'allowed_users', '' );
		if ( '' === $raw ) {
			return false;
		}
		$allowed = array_map( 'intval', explode( ',', $raw ) );
		return in_array( $user_id, $allowed, true );
	}

	/**
	 * Load the legacy Tiny File Manager library. Only ever reached after
	 * is_user_logged_in() + is_user_allowed() both passed above.
	 */
	private function load_tinyfilemanager(): void {
		// TFM variables MUST be in global scope - TFM classes/functions read
		// them via the 'global' keyword. Declaring them here ensures 'require'
		// places values in global scope, not local method scope.
		global $uxstudio_fm_root_path, $uxstudio_fm_readonly,
			$CONFIG, $use_auth, $auth_users, $readonly_users,
			$root_path, $root_url, $global_readonly,
			$lang, $datetime_format, $iconv_input_encoding,
			$exclude_items, $max_upload_size_bytes,
			$ip_ruleset, $ip_whitelist, $ip_blacklist,
			$highlightjs_style, $online_viewer, $sticky_navbar,
			$show_hidden_files, $hide_Cols, $theme, $path_display_mode,
			$external, $config_file, $favicon_path,
			$report_errors, $lang_list, $cfg, $editFile;

		$uxstudio_fm_root_path = (string) $this->settings->get( 'root_path', ABSPATH );
		if ( ! is_dir( $uxstudio_fm_root_path ) ) {
			// Fail-closed: never fall back to DOCUMENT_ROOT (= entire host).
			$uxstudio_fm_root_path = ABSPATH;
		}

		$uxstudio_fm_readonly = '1' === (string) $this->settings->get( 'readonly_mode', '' );

		// Define session ID before TFM to avoid collision with other sessions.
		if ( ! defined( 'FM_SESSION_ID' ) ) {
			define( 'FM_SESSION_ID', 'uxstudio_file_manager' );
		}

		// Fix FM_SELF_URL - in WordPress, $_SERVER['PHP_SELF'] is always
		// index.php, which causes TFM to redirect to the homepage instead of
		// the standalone route.
		if ( ! defined( 'FM_SELF_URL' ) ) {
			$is_https = isset( $_SERVER['HTTPS'] ) && ( 'on' === strtolower( $_SERVER['HTTPS'] ) || '1' === (string) $_SERVER['HTTPS'] );
			define(
				'FM_SELF_URL',
				( $is_https ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . '/' . trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) . '/' . self::ROUTE_SLUG . '/'
			);
		}

		// SECURITY: marker for config.php/tinyfilemanager.php confirming we
		// run inside WordPress via this authorized route. Direct HTTP access
		// to the raw legacy files never sets this marker, so config.php
		// fail-closes (404) and refuses to run.
		if ( ! defined( 'UXSTUDIO_FM_EMBEDDED' ) ) {
			define( 'UXSTUDIO_FM_EMBEDDED', true );
		}

		require __DIR__ . '/legacy/tinyfilemanager.php';
	}
}
