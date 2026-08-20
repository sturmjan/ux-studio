<?php
/**
 * Security - Access Control module: login protection, IP bans, country
 * blocklist, custom login URL, .htaccess hardening and security headers.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy security-optimization module - "access control"
 * slice only (login rate limiting, IP/country bans, custom login URL,
 * .htaccess hardening, security headers, block-usernames, basic hardening
 * toggles). Upload Guard (malware scanner) and CSP violation logging are
 * owned by a different concurrent port and live in separate files.
 *
 * Table names/columns for login attempts and IP bans are fixed by the
 * migration map (Migrator.php) - do not rename them.
 */
final class Module extends BaseModule {

	private const SECRET_CAPTCHA = 'uxstudio_secret_security_optimization_captcha_secret_key';

	/** Kill-switch for the custom login URL feature, settable in wp-config.php:
	 *  define( 'UXSTUDIO_DISABLE_LOGIN_LOCK', true );
	 */
	private const LOGIN_LOCK_CONST = 'UXSTUDIO_DISABLE_LOGIN_LOCK';

	private ?AttemptsHandler $attempts       = null;
	private ?IpBanStore $ip_ban_store        = null;
	private ?IpBanManager $ip_ban_manager    = null;
	private ?HtaccessWriter $htaccess_writer = null;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		\UxStudio\Core\DB::ensure_module_tables(
			'security-optimization',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_login_failed (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						username varchar(100) NOT NULL,
						ip varchar(45) NOT NULL,
						country varchar(60) NULL,
						status tinyint(1) NOT NULL DEFAULT 0,
						locktime int(11) NOT NULL DEFAULT 30,
						locklimit int(11) NOT NULL DEFAULT 3,
						date datetime NOT NULL DEFAULT current_timestamp(),
						PRIMARY KEY (id), KEY ip (ip), KEY status (status)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_login_attempt (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						attempt int(11) NOT NULL DEFAULT 1,
						ip varchar(45) NOT NULL,
						date datetime NOT NULL DEFAULT current_timestamp(),
						PRIMARY KEY (id), KEY ip (ip)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_ip_bans (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						type varchar(10) NOT NULL DEFAULT 'single',
						value varchar(64) NOT NULL,
						label varchar(120) NULL,
						note text NULL,
						origin varchar(10) NOT NULL DEFAULT 'local',
						central_id bigint(20) NULL,
						is_active tinyint(1) NOT NULL DEFAULT 1,
						expires_at datetime NULL,
						hit_count bigint(20) NOT NULL DEFAULT 0,
						last_hit_at datetime NULL,
						created_by bigint(20) NULL,
						created_at datetime NOT NULL DEFAULT current_timestamp(),
						PRIMARY KEY (id), UNIQUE KEY origin_value (origin, value), KEY is_active (is_active), KEY origin (origin)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_ip_ban_ranges (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						ban_id bigint(20) NOT NULL,
						ip_version tinyint(1) NOT NULL DEFAULT 4,
						start_ip varbinary(16) NOT NULL,
						end_ip varbinary(16) NOT NULL,
						PRIMARY KEY (id), KEY ban_id (ban_id), KEY lookup (ip_version, start_ip)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		/* ── IP firewall - as early as possible (after pluggable functions load) ── */
		add_action( 'init', array( $this, 'enforce_ip_firewall' ), 1 );

		/* ── Security headers - PHP defense-in-depth even when .htaccess is ignored (nginx) ── */
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		add_action( 'admin_init', array( $this, 'send_security_headers' ) );

		/* ── .htaccess sync when settings changed ── */
		$this->maybe_update_htaccess();

		/* ── XML-RPC ── */
		if ( $this->setting( 'disable_xmlrpc', true ) ) {
			add_action( 'init', array( $this, 'maybe_block_xmlrpc_request' ), 1 );
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'pings_open', '__return_false', 9999 );
		}

		/* ── Hide WP version ── */
		if ( $this->setting( 'hide_wp_version', true ) ) {
			add_filter( 'the_generator', '__return_false' );
		}

		/* ── Custom login URL (with kill-switch + collision fail-open guard) ── */
		if ( ! $this->login_lock_disabled() && $this->setting( 'login_url_enabled', false ) && '' !== trim( (string) $this->setting( 'login_url', '' ) ) ) {
			add_action( 'login_init', array( $this, 'block_direct_wp_login' ), 1 );
			add_action( 'init', array( $this, 'handle_custom_login_url' ), 0 );
		}

		/* ── Login rate limiting ── */
		if ( $this->setting( 'limit_login_attempts', false ) ) {
			$this->attempts = new AttemptsHandler( $this );
			add_filter( 'authenticate', array( $this->attempts, 'check_attempted_login' ), 30, 3 );
			add_action( 'wp_login_failed', array( $this->attempts, 'handle_failed_login' ), 10, 1 );
			add_action( 'init', array( $this->attempts, 'cleanup_expired_attempts' ) );
		}

		/* ── CAPTCHA ── */
		if ( $this->setting( 'captcha_enabled', false ) ) {
			new CaptchaHandler( $this );
		}

		/* ── Block reserved usernames ── */
		if ( $this->setting( 'block_usernames', false ) ) {
			add_filter( 'registration_errors', array( $this, 'block_restricted_usernames' ), 10, 3 );
			add_action( 'user_profile_update_errors', array( $this, 'block_restricted_usernames_admin' ), 10, 3 );
			add_filter( 'wpmu_validate_user_signup', array( $this, 'block_restricted_usernames_multisite' ) );
		}

		/* ── Cleanup / performance / hardening toggles ── */
		if ( $this->setting( 'disable_emojis', false ) ) {
			add_action( 'init', array( $this, 'disable_emojis' ) );
		}
		if ( $this->setting( 'disable_embeds', false ) ) {
			add_action( 'init', array( $this, 'disable_embeds' ), 9999 );
		}
		if ( $this->setting( 'disable_rss_feeds', false ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
			add_action( 'template_redirect', array( $this, 'redirect_feed' ) );
		}
		if ( $this->setting( 'restrict_rest_api', false ) ) {
			add_filter( 'rest_authentication_errors', array( $this, 'restrict_rest_api' ), 99 );
		}

		/* ── CSP violation reporting + Upload Guard malware scanner (ported
		 * separately, self-contained: own tables, own REST routes, own cron/
		 * upload hooks). See CspUploadGuardBootstrap for details. ── */
		CspUploadGuardBootstrap::register();
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
	 * Public settings getter - shared with AttemptsHandler/CaptchaHandler which
	 * are plain collaborators, not modules themselves.
	 *
	 * @param mixed $default Fallback value.
	 * @return mixed
	 */
	public function setting( string $key, $default = null ) {
		return $this->settings->get( $key, $default );
	}

	public function captcha_secret_key(): string {
		return Security::get_secret( self::SECRET_CAPTCHA );
	}

	public function ip_ban_store(): IpBanStore {
		if ( null === $this->ip_ban_store ) {
			$this->ip_ban_store = new IpBanStore();
		}
		return $this->ip_ban_store;
	}

	public function ip_ban_manager(): IpBanManager {
		if ( null === $this->ip_ban_manager ) {
			$this->ip_ban_manager = new IpBanManager( $this->ip_ban_store() );
		}
		return $this->ip_ban_manager;
	}

	public function attempts_handler(): AttemptsHandler {
		if ( null === $this->attempts ) {
			$this->attempts = new AttemptsHandler( $this );
		}
		return $this->attempts;
	}

	public function htaccess_writer(): HtaccessWriter {
		if ( null === $this->htaccess_writer ) {
			$this->htaccess_writer = new HtaccessWriter();
		}
		return $this->htaccess_writer;
	}

	/* ═══════════════════════════════════════════════════
	   Settings schema
	   ═══════════════════════════════════════════════════ */

	public function settings_schema(): array {
		$country_options = array();
		foreach ( CountryBlocklist::get_countries() as $code => $name ) {
			$country_options[ $code ] = $name . ' (' . $code . ')';
		}

		return array(
			// IP firewall.
			array(
				'key'     => 'ip_firewall_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable IP firewall', 'ux-studio' ),
				'help'    => __( 'Blocks every request (frontend, login, admin, REST) from banned IPs/ranges/countries. Logged-in administrators and allowlisted IPs are never blocked.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'ip_firewall_proxy',
				'type'    => 'select',
				'label'   => __( 'Client IP source', 'ux-studio' ),
				'help'    => __( 'Forwarded headers are only trusted in the selected mode (otherwise spoofable). Choose Cloudflare if the site is behind Cloudflare.', 'ux-studio' ),
				'options' => array(
					'none'       => __( 'Direct connection (REMOTE_ADDR) - safe default', 'ux-studio' ),
					'cloudflare' => __( 'Behind Cloudflare (CF-Connecting-IP)', 'ux-studio' ),
					'xff'        => __( 'Behind a reverse proxy (X-Forwarded-For)', 'ux-studio' ),
				),
				'default' => 'none',
			),
			array(
				'key'     => 'ip_firewall_allowlist',
				'type'    => 'textarea',
				'label'   => __( 'Allowlist (never block)', 'ux-studio' ),
				'help'    => __( 'IP addresses or CIDR ranges that are never blocked, one per line. Loopback is always allowed.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ip_firewall_message',
				'type'    => 'text',
				'label'   => __( 'Block page message', 'ux-studio' ),
				'help'    => __( 'Optional message shown to a blocked visitor (403).', 'ux-studio' ),
				'default' => '',
			),

			// Country blocklist.
			array(
				'key'     => 'country_blocklist_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable country blocklist', 'ux-studio' ),
				'help'    => __( 'Blocks visitors from the selected countries via the IP firewall (downloads aggregated IP ranges per country).', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'blocked_countries',
				'type'    => 'multiselect',
				'label'   => __( 'Blocked countries', 'ux-studio' ),
				'help'    => __( 'Selecting a country here creates/refreshes a country-type IP ban the next time settings are saved.', 'ux-studio' ),
				'options' => $country_options,
				'default' => array(),
			),

			// Canonical URL / protocols.
			array(
				'key'     => 'canonical_redirect',
				'type'    => 'toggle',
				'label'   => __( 'Force canonical URL redirect', 'ux-studio' ),
				'help'    => __( 'Writes .htaccess rules that redirect every request to a single canonical version of the site, based on Settings -> General -> Site Address.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'force_https',
				'type'    => 'toggle',
				'label'   => __( 'Force HTTPS', 'ux-studio' ),
				'help'    => __( 'Redirects all HTTP requests to HTTPS. Only enable with a working SSL certificate on the whole site. Ignored when canonical URL redirect is on (it already covers this).', 'ux-studio' ),
				'default' => false,
			),

			// Security headers.
			array(
				'key'     => 'security_headers',
				'type'    => 'toggle',
				'label'   => __( 'Security headers', 'ux-studio' ),
				'help'    => __( 'Sends X-Frame-Options, X-Content-Type-Options and Referrer-Policy, both via .htaccess and as a PHP fallback.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'enable_hsts',
				'type'    => 'toggle',
				'label'   => __( 'HSTS (Strict-Transport-Security)', 'ux-studio' ),
				'help'    => __( 'Tells browsers to only ever connect over HTTPS for a year. Only enable with a valid SSL certificate everywhere on the site.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'enable_permissions_policy',
				'type'    => 'toggle',
				'label'   => __( 'Permissions-Policy', 'ux-studio' ),
				'help'    => __( 'Disables browser access to camera, microphone, geolocation, payment and USB APIs by default.', 'ux-studio' ),
				'default' => false,
			),

			// File protection (.htaccess).
			array(
				'key'     => 'protect_wp_config',
				'type'    => 'toggle',
				'label'   => __( 'Protect wp-config.php', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_directory_listing',
				'type'    => 'toggle',
				'label'   => __( 'Disable directory listing', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'block_sensitive_files',
				'type'    => 'toggle',
				'label'   => __( 'Block readme.html / license.txt', 'ux-studio' ),
				'default' => false,
			),

			// Custom login URL.
			array(
				'key'     => 'login_url_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable custom login URL', 'ux-studio' ),
				'help'    => __( 'Rescue switch: define UXSTUDIO_DISABLE_LOGIN_LOCK in wp-config.php to bypass this feature entirely if you get locked out.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'login_url',
				'type'    => 'text',
				'label'   => __( 'Custom login slug', 'ux-studio' ),
				'help'    => __( 'New slug for the login page. wp-login.php will 404 for direct visitors. If the slug collides with an existing page/rewrite rule, the redirect is automatically NOT activated (fail-open) and a warning is logged.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'disabled_behavior',
				'type'    => 'select',
				'label'   => __( 'Direct wp-login.php behavior', 'ux-studio' ),
				'options' => array(
					'404'  => __( '404 page', 'ux-studio' ),
					'home' => __( 'Homepage', 'ux-studio' ),
				),
				'default' => '404',
			),

			// Login rate limiting.
			array(
				'key'     => 'limit_login_attempts',
				'type'    => 'toggle',
				'label'   => __( 'Limit login attempts', 'ux-studio' ),
				'help'    => __( 'Blocks an IP/username after too many failed login attempts (brute-force protection).', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'max_attempts',
				'type'    => 'number',
				'label'   => __( 'Max attempts', 'ux-studio' ),
				'default' => 3,
			),
			array(
				'key'     => 'lockout_time',
				'type'    => 'number',
				'label'   => __( 'Lockout time (minutes)', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'notify_admin',
				'type'    => 'toggle',
				'label'   => __( 'Email admin on lockout', 'ux-studio' ),
				'default' => false,
			),

			// Block usernames.
			array(
				'key'     => 'block_usernames',
				'type'    => 'toggle',
				'label'   => __( 'Block reserved usernames', 'ux-studio' ),
				'help'    => __( 'Prevents registering/renaming to reserved names such as admin, administrator, root, test.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'blocked_usernames_extra',
				'type'    => 'textarea',
				'label'   => __( 'Additional blocked usernames', 'ux-studio' ),
				'help'    => __( 'One per line, added on top of the built-in reserved list (admin, administrator, root, test).', 'ux-studio' ),
				'default' => '',
			),

			// CAPTCHA.
			array(
				'key'     => 'captcha_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Enable CAPTCHA', 'ux-studio' ),
				'help'    => __( 'Adds CAPTCHA verification to login, registration and lost-password forms. Without both keys set, nothing is enforced.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'captcha_provider',
				'type'    => 'select',
				'label'   => __( 'CAPTCHA provider', 'ux-studio' ),
				'options' => array(
					'turnstile'    => __( 'Cloudflare Turnstile', 'ux-studio' ),
					'recaptcha_v2' => __( 'Google reCAPTCHA v2 (checkbox)', 'ux-studio' ),
					'recaptcha_v3' => __( 'Google reCAPTCHA v3 (score)', 'ux-studio' ),
				),
				'default' => 'turnstile',
			),
			array(
				'key'     => 'captcha_mode',
				'type'    => 'select',
				'label'   => __( 'Enforcement mode', 'ux-studio' ),
				'options' => array(
					'always'   => __( 'Always', 'ux-studio' ),
					'adaptive' => __( 'Adaptive (after repeated failures)', 'ux-studio' ),
				),
				'default' => 'always',
			),
			array(
				'key'     => 'captcha_site_key',
				'type'    => 'text',
				'label'   => __( 'Site key', 'ux-studio' ),
				'help'    => __( 'Public key, safe to expose in the page markup.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'captcha_secret_key',
				'type'    => 'text',
				'label'   => __( 'Secret key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted server-side. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),

			// Cleanup / performance / disable-features.
			array(
				'key'     => 'disable_xmlrpc',
				'type'    => 'toggle',
				'label'   => __( 'Disable XML-RPC', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'hide_wp_version',
				'type'    => 'toggle',
				'label'   => __( 'Hide WordPress version', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'disable_emojis',
				'type'    => 'toggle',
				'label'   => __( 'Disable emoji scripts', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_embeds',
				'type'    => 'toggle',
				'label'   => __( 'Disable embeds script', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_rss_feeds',
				'type'    => 'toggle',
				'label'   => __( 'Disable RSS feeds', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'restrict_rest_api',
				'type'    => 'toggle',
				'label'   => __( 'Restrict REST API to logged-in users', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Intercept the CAPTCHA secret key before it reaches the plain settings
	 * option, and sync the country blocklist to IP bans.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'captcha_secret_key', $input ) && '' !== (string) $input['captcha_secret_key'] ) {
			Security::store_secret( self::SECRET_CAPTCHA, (string) $input['captcha_secret_key'] );
		}
		unset( $input['captcha_secret_key'] );

		$result = parent::save_settings( $input );

		delete_option( HtaccessWriter::HASH_OPTION );
		$this->maybe_update_htaccess();
		$this->sync_country_blocklist();

		return $result;
	}

	/**
	 * Never leak the CAPTCHA secret back to the client; expose only whether it's set.
	 */
	public function settings_values(): array {
		$values                        = parent::settings_values();
		$values['captcha_secret_key']  = '';
		$values['has_captcha_secret_key'] = '' !== $this->captcha_secret_key();
		return $values;
	}

	/**
	 * Create/refresh local country-type IP bans to match the "blocked_countries"
	 * setting (removes country bans that were unchecked).
	 */
	private function sync_country_blocklist(): void {
		if ( ! $this->setting( 'country_blocklist_enabled', false ) ) {
			return;
		}

		$wanted  = array_map( 'strtoupper', (array) $this->setting( 'blocked_countries', array() ) );
		$manager = $this->ip_ban_manager();

		$existing = $manager->list_bans( array( 'type' => 'country', 'per_page' => 200 ) );
		$have     = array();
		foreach ( $existing['items'] as $item ) {
			$have[ strtoupper( $item['value'] ) ] = (int) $item['id'];
		}

		foreach ( $wanted as $code ) {
			if ( ! isset( $have[ $code ] ) ) {
				$manager->save_ban( array( 'type' => 'country', 'value' => $code ) );
			}
		}

		foreach ( $have as $code => $id ) {
			if ( ! in_array( $code, $wanted, true ) ) {
				$manager->delete_ban( $id );
			}
		}
	}

	/* ═══════════════════════════════════════════════════
	   IP firewall
	   ═══════════════════════════════════════════════════ */

	public function enforce_ip_firewall(): void {
		if ( ! $this->setting( 'ip_firewall_enabled', false ) ) {
			return;
		}
		if ( wp_doing_cron() ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$store = $this->ip_ban_store();
		if ( ! $store->has_active_bans() ) {
			return;
		}

		$proxy_mode = (string) $this->setting( 'ip_firewall_proxy', 'none' );
		$ip         = $store->get_client_ip( $proxy_mode );
		if ( '' === $ip ) {
			return;
		}

		$allowlist = (string) $this->setting( 'ip_firewall_allowlist', '' );
		if ( $store->is_allowlisted( $ip, $allowlist ) ) {
			return;
		}

		$ban = $store->matched_ban( $ip );
		if ( ! $ban ) {
			return;
		}

		$store->record_hit( (int) $ban['id'], $ip );

		$message = (string) $this->setting( 'ip_firewall_message', '' );
		status_header( 403 );
		nocache_headers();
		wp_die(
			$message ? esc_html( $message ) : esc_html__( 'Your IP address has been blocked.', 'ux-studio' ),
			esc_html__( '403 Forbidden', 'ux-studio' ),
			array( 'response' => 403 )
		);
	}

	/* ═══════════════════════════════════════════════════
	   Security headers (PHP defense-in-depth)
	   ═══════════════════════════════════════════════════ */

	public function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}

		if ( $this->setting( 'security_headers', false ) ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		}

		if ( $this->setting( 'enable_hsts', false ) && is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}

		if ( $this->setting( 'enable_permissions_policy', false ) ) {
			header( 'Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=()' );
		}
	}

	/* ═══════════════════════════════════════════════════
	   .htaccess sync
	   ═══════════════════════════════════════════════════ */

	private function maybe_update_htaccess(): void {
		$writer   = $this->htaccess_writer();
		$settings = $this->settings_values();

		if ( ! $writer->is_applied( $settings ) ) {
			$result = $writer->apply( $settings );
			if ( true === $result ) {
				ActivityLog::log( 'security-optimization', 'htaccess_applied' );
			}
		}
	}

	/* ═══════════════════════════════════════════════════
	   XML-RPC
	   ═══════════════════════════════════════════════════ */

	public function maybe_block_xmlrpc_request(): void {
		$is_xmlrpc = ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( isset( $_SERVER['SCRIPT_FILENAME'] ) && 'xmlrpc.php' === basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) );

		if ( ! $is_xmlrpc ) {
			return;
		}

		http_response_code( 403 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html__( 'XML-RPC is disabled.', 'ux-studio' );
		exit;
	}

	/* ═══════════════════════════════════════════════════
	   Custom login URL
	   ═══════════════════════════════════════════════════ */

	private function login_lock_disabled(): bool {
		return defined( self::LOGIN_LOCK_CONST ) && constant( self::LOGIN_LOCK_CONST );
	}

	private function login_slug(): string {
		return trim( (string) $this->setting( 'login_url', '' ), '/' );
	}

	private function current_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
	}

	/**
	 * Fail-open safety: never activate the redirect if the slug collides with
	 * an existing page or rewrite rule - better to leave wp-login.php working
	 * than break the site.
	 */
	private function login_slug_collides( string $slug ): bool {
		if ( in_array( $slug, array( 'wp-admin', 'wp-login', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-json' ), true ) ) {
			return true;
		}
		if ( null !== get_page_by_path( $slug ) ) {
			return true;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( array_keys( $rules ) as $pattern ) {
				if ( '' !== $pattern && @preg_match( '#^' . $pattern . '$#', $slug ) === 1 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Runs on 'init': when the current request path matches the custom slug,
	 * serve wp-login.php directly (with a bypass marker so block_direct_wp_login
	 * lets it through).
	 */
	public function handle_custom_login_url(): void {
		$slug = $this->login_slug();
		if ( '' === $slug || $this->login_slug_collides( $slug ) ) {
			if ( '' !== $slug ) {
				ActivityLog::log( 'security-optimization', 'login_url_collision', 'setting', 0, array( 'slug' => $slug ) );
			}
			return;
		}

		if ( $this->current_path() !== $slug ) {
			return;
		}

		if ( is_user_logged_in() && empty( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( admin_url() );
			exit;
		}

		if ( ! defined( 'UXSTUDIO_LOGIN_URL_BYPASS' ) ) {
			define( 'UXSTUDIO_LOGIN_URL_BYPASS', true );
		}
		require ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * Runs on 'login_init' (fired only for direct wp-login.php hits): 404 unless
	 * we ourselves are including it via the custom slug (bypass marker set).
	 */
	public function block_direct_wp_login(): void {
		if ( defined( 'UXSTUDIO_LOGIN_URL_BYPASS' ) && UXSTUDIO_LOGIN_URL_BYPASS ) {
			return;
		}

		$slug = $this->login_slug();
		if ( '' === $slug || $this->login_slug_collides( $slug ) ) {
			return;
		}

		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Not found.', 'ux-studio' ), '', array( 'response' => 404 ) );
	}

	/* ═══════════════════════════════════════════════════
	   Block reserved usernames
	   ═══════════════════════════════════════════════════ */

	public function blocked_usernames(): array {
		$defaults = array( 'admin', 'administrator', 'root', 'test' );
		$extra    = (string) $this->setting( 'blocked_usernames_extra', '' );
		foreach ( preg_split( '/[\r\n,]+/', $extra ) as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' !== $line ) {
				$defaults[] = $line;
			}
		}
		return array_values( array_unique( $defaults ) );
	}

	public function is_blocked_username( string $username ): bool {
		$username = strtolower( trim( $username ) );
		if ( '' === $username ) {
			return false;
		}
		foreach ( $this->blocked_usernames() as $blocked ) {
			if ( preg_match( '/\b' . preg_quote( $blocked, '/' ) . '\b/i', $username ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param WP_Error $errors                Registration errors.
	 * @param string   $sanitized_user_login  Sanitized username.
	 * @param string   $user_email            Email (unused).
	 */
	public function block_restricted_usernames( $errors, $sanitized_user_login, $user_email ) {
		if ( $this->is_blocked_username( $sanitized_user_login ) ) {
			$errors->add( 'uxstudio_blocked_username', __( 'This username is not allowed for security reasons. Please choose another.', 'ux-studio' ) );
		}
		return $errors;
	}

	/**
	 * @param WP_Error $errors User profile update errors.
	 * @param bool     $update Whether this is an update (vs. new user).
	 * @param \stdClass $user  User object being saved.
	 */
	public function block_restricted_usernames_admin( $errors, $update, $user ) {
		if ( $update ) {
			return;
		}
		if ( isset( $user->user_login ) && $this->is_blocked_username( $user->user_login ) ) {
			$errors->add( 'uxstudio_blocked_username', __( 'This username is not allowed for security reasons.', 'ux-studio' ) );
		}
	}

	/**
	 * @param array $result Multisite signup validation result.
	 */
	public function block_restricted_usernames_multisite( $result ) {
		$username = $result['user_name'] ?? '';
		if ( $this->is_blocked_username( $username ) ) {
			if ( ! is_wp_error( $result['errors'] ) ) {
				$result['errors'] = new WP_Error();
			}
			$result['errors']->add( 'uxstudio_blocked_username', __( 'This username is not allowed for security reasons.', 'ux-studio' ) );
		}
		return $result;
	}

	/* ═══════════════════════════════════════════════════
	   Cleanup / performance
	   ═══════════════════════════════════════════════════ */

	public function disable_emojis(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( $this, 'disable_emojis_tinymce' ) );
		add_filter( 'emoji_svg_url', '__return_false' );
	}

	/**
	 * @param array $plugins TinyMCE plugins.
	 */
	public function disable_emojis_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return array();
	}

	public function disable_embeds(): void {
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		add_filter(
			'tiny_mce_plugins',
			static function ( $plugins ) {
				return is_array( $plugins ) ? array_diff( $plugins, array( 'wpembed' ) ) : array();
			}
		);
	}

	public function redirect_feed(): void {
		if ( ! is_feed() ) {
			return;
		}
		status_header( 403 );
		wp_die(
			esc_html__( 'RSS feeds are disabled on this site.', 'ux-studio' ),
			esc_html__( '403 Forbidden', 'ux-studio' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * @param \WP_Error|null|mixed $result Incoming filter value.
	 * @return \WP_Error|null|mixed
	 */
	public function restrict_rest_api( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}
		return new WP_Error( 'uxstudio_rest_forbidden', __( 'REST API access is restricted to logged-in users.', 'ux-studio' ), array( 'status' => 401 ) );
	}
}
