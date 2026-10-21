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
use UxStudio\Core\Settings;
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

	private const SECRET_CAPTCHA        = 'uxstudio_secret_security_optimization_captcha_secret_key';
	private const SECRET_CF_API_TOKEN   = 'uxstudio_secret_security_optimization_cf_api_token';

	/** Kill-switch for the custom login URL feature, settable in wp-config.php:
	 *  define( 'UXSTUDIO_DISABLE_LOGIN_LOCK', true );
	 */
	private const LOGIN_LOCK_CONST = 'UXSTUDIO_DISABLE_LOGIN_LOCK';

	/** Hourly purge of stale login-attempt counters. */
	public const ATTEMPTS_CLEANUP_HOOK = 'uxstudio_security_login_attempts_cleanup';

	/** Set after a failed .htaccess write so an unwritable file isn't retried on every admin page. */
	private const HTACCESS_BACKOFF = 'uxstudio_security_htaccess_backoff';

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
						PRIMARY KEY  (id),
						KEY ip (ip),
						KEY status (status)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_login_attempt (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						attempt int(11) NOT NULL DEFAULT 1,
						ip varchar(45) NOT NULL,
						date datetime NOT NULL DEFAULT current_timestamp(),
						PRIMARY KEY  (id),
						KEY ip (ip)
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
						PRIMARY KEY  (id),
						UNIQUE KEY origin_value (origin,value),
						KEY is_active (is_active),
						KEY origin (origin)
					) {$charset};"
				);

				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_ip_ban_ranges (
						id bigint(20) NOT NULL AUTO_INCREMENT,
						ban_id bigint(20) NOT NULL,
						ip_version tinyint(1) NOT NULL DEFAULT 4,
						start_ip varbinary(16) NOT NULL,
						end_ip varbinary(16) NOT NULL,
						PRIMARY KEY  (id),
						KEY ban_id (ban_id),
						KEY lookup (ip_version,start_ip)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		DashboardWidget::register();

		/* ── IP firewall - as early as possible (after pluggable functions load) ── */
		add_action( 'init', array( $this, 'enforce_ip_firewall' ), 1 );

		/* ── Security headers - PHP defense-in-depth even when .htaccess is ignored (nginx) ── */
		add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		add_action( 'admin_init', array( $this, 'send_security_headers' ) );

		/* ── Content-Security-Policy - FRONT END ONLY (send_headers never fires in
		 * wp-admin) so a bad policy can never lock the dashboard. See CspPolicy
		 * for the modes; the dashboard widget is mandatory while CSP is on. ── */
		add_action( 'send_headers', array( $this, 'maybe_send_csp_header' ) );
		$csp_mode = $this->csp_policy()->mode();
		CspMaintenance::register( $csp_mode );
		if ( 'off' !== $csp_mode ) {
			CspDashboardWidget::register();
		}

		/* ── .htaccess sync - written on settings save; admin_init only re-checks
		 * the stored hash (cheap, no secrets decrypted) to heal drift, e.g. after
		 * a site-URL change. Never on front-end requests. ── */
		add_action( 'admin_init', array( $this, 'maybe_update_htaccess' ) );

		/* ── XML-RPC ── */
		if ( $this->setting( 'disable_xmlrpc', true ) ) {
			add_action( 'init', array( $this, 'maybe_block_xmlrpc_request' ), 1 );
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array' );
			add_filter( 'pings_open', '__return_false', 9999 );
			add_filter( 'wp_headers', array( $this, 'remove_x_pingback' ) );
		}

		/* ── Hide WP version (asset ?ver= masked only where it equals the core version) ── */
		if ( $this->setting( 'hide_wp_version', true ) ) {
			add_filter( 'the_generator', '__return_false' );
			add_filter( 'style_loader_src', array( $this, 'remove_version_query_arg' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'remove_version_query_arg' ), 9999 );
		}

		/* ── Disable theme/plugin file editor (define early, before wp-admin caps) ── */
		if ( $this->setting( 'disable_file_editing', false ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		/* ── Block user enumeration ── */
		if ( $this->setting( 'block_user_enumeration', false ) ) {
			add_action( 'init', array( $this, 'block_author_enumeration' ), 1 );
			add_filter( 'rest_endpoints', array( $this, 'disable_user_rest_route' ) );
		}

		/* ── Hide author slugs (encrypted user ID instead of the username) ── */
		if ( $this->setting( 'hide_author_slugs', false ) ) {
			add_action( 'pre_get_posts', array( $this, 'handle_author_query' ) );
			add_filter( 'author_link', array( $this, 'encrypt_author_link' ), 10, 3 );
			add_filter( 'rest_prepare_user', array( $this, 'encrypt_rest_api_user' ), 10, 3 );
		}

		/* ── Harden login page ── */
		if ( $this->setting( 'protect_login', false ) ) {
			add_filter( 'login_errors', array( $this, 'sanitize_login_errors' ) );
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		}

		/* ── Custom login URL (with kill-switch + collision fail-open guard) ── */
		if ( ! $this->login_lock_disabled() && $this->setting( 'login_url_enabled', false ) && '' !== trim( (string) $this->setting( 'login_url', '' ) ) ) {
			add_action( 'login_init', array( $this, 'block_direct_wp_login' ), 1 );
			add_action( 'init', array( $this, 'handle_custom_login_url' ), 0 );
		}

		/* ── Login rate limiting - lockout checked at priority 5, BEFORE core
		 * verifies the password (20), and re-asserted last (PHP_INT_MAX) since
		 * core's password check overwrites an incoming WP_Error. Counters reset
		 * only on a completed login (wp_login). ── */
		if ( $this->setting( 'limit_login_attempts', false ) ) {
			$this->attempts = new AttemptsHandler( $this );
			add_filter( 'authenticate', array( $this->attempts, 'check_attempted_login' ), 5, 3 );
			add_filter( 'authenticate', array( $this->attempts, 'enforce_lockout' ), PHP_INT_MAX, 1 );
			add_action( 'wp_login_failed', array( $this->attempts, 'handle_failed_login' ), 10, 1 );
			add_action( 'wp_login', array( $this->attempts, 'handle_successful_login' ), 10, 1 );
			// Before handle_failed_login (10), so that one sees the lock and adds no second row.
			if ( $this->setting( 'block_usernames', false ) && $this->setting( 'block_username_attempts', false ) ) {
				add_action( 'wp_login_failed', array( $this->attempts, 'handle_blocked_username_login' ), 5, 2 );
			}
			add_action( self::ATTEMPTS_CLEANUP_HOOK, array( $this->attempts, 'cleanup_expired_attempts' ) );
			if ( ! wp_next_scheduled( self::ATTEMPTS_CLEANUP_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::ATTEMPTS_CLEANUP_HOOK );
			}
		} elseif ( wp_next_scheduled( self::ATTEMPTS_CLEANUP_HOOK ) ) {
			wp_clear_scheduled_hook( self::ATTEMPTS_CLEANUP_HOOK );
		}

		/* ── CAPTCHA - inline widget in the form, or a standalone gate page
		 * shown before it (also covers wp-admin, since WordPress itself
		 * redirects every logged-out admin request to wp-login.php). ── */
		if ( $this->setting( 'captcha_enabled', false ) ) {
			if ( 'gate' === (string) $this->setting( 'captcha_placement', 'gate' ) ) {
				new CaptchaGate( $this );
			} else {
				new CaptchaHandler( $this );
			}
		}

		/* ── Block reserved usernames ── */
		if ( $this->setting( 'block_usernames', false ) ) {
			add_filter( 'registration_errors', array( $this, 'block_restricted_usernames' ), 10, 3 );
			add_action( 'user_profile_update_errors', array( $this, 'block_restricted_usernames_admin' ), 10, 3 );
			add_filter( 'wpmu_validate_user_signup', array( $this, 'block_restricted_usernames_multisite' ) );
			( new BlockedUsernamesNotice( $this ) )->boot();
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

		/* ── Heartbeat (left alone when a caching/perf plugin already manages it) ── */
		if ( false === self::heartbeat_managed_externally() ) {
			$heartbeat = (string) $this->setting( 'heartbeat_control', 'normal' );
			if ( 'slow' === $heartbeat ) {
				add_filter( 'heartbeat_settings', array( $this, 'slow_heartbeat' ) );
			} elseif ( 'disable' === $heartbeat ) {
				add_action( 'init', array( $this, 'disable_heartbeat' ), 1 );
			}
		}

		if ( $this->setting( 'limit_revisions', false ) ) {
			add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions' ), 10, 2 );
		}
		if ( $this->setting( 'restrict_mime_types', false ) ) {
			add_filter( 'upload_mimes', array( $this, 'restrict_mime_types' ), 9999 );
		}

		/* ── Content: disable comments / blog ── */
		if ( $this->setting( 'disable_comments', false ) ) {
			$this->init_disable_comments();
		}
		if ( $this->setting( 'disable_blog', false ) ) {
			$this->init_disable_blog();
		}

		/* ── CSP violation reporting + Upload Guard malware scanner (ported
		 * separately, self-contained: own tables, own REST routes, own cron/
		 * upload hooks). See CspUploadGuardBootstrap for details. The Upload Guard
		 * scanner honours its enable toggle; CSP violation collection is always on
		 * so the report-uri sink keeps working regardless. ── */
		CspUploadGuardBootstrap::register( (bool) $this->setting( 'upload_guard_enabled', true ) );
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

	/**
	 * Validated `ip_firewall_proxy` setting for Core\ClientIp::get().
	 */
	public function proxy_mode(): string {
		$mode = (string) $this->setting( 'ip_firewall_proxy', 'none' );
		return in_array( $mode, array( 'none', 'cloudflare', 'xff' ), true ) ? $mode : 'none';
	}

	public function captcha_secret_key(): string {
		return Security::get_secret( self::SECRET_CAPTCHA );
	}

	public function captcha_cf_api_token(): string {
		return Security::get_secret( self::SECRET_CF_API_TOKEN );
	}

	public function ip_ban_store(): IpBanStore {
		if ( null === $this->ip_ban_store ) {
			$this->ip_ban_store = new IpBanStore( $this->proxy_mode() );
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
				'section' => __( 'Firewall', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable IP firewall', 'ux-studio' ),
				'help'    => __( 'Blocks every request (frontend, login, admin, REST) from banned IPs/ranges/countries. Logged-in administrators and allowlisted IPs are never blocked.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'ip_firewall_proxy',
				'section' => __( 'Firewall', 'ux-studio' ),
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
				'section' => __( 'Firewall', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Allowlist (never block)', 'ux-studio' ),
				'help'    => __( 'IP addresses or CIDR ranges that are never blocked, one per line. Loopback is always allowed.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ip_firewall_message',
				'section' => __( 'Firewall', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Block page message', 'ux-studio' ),
				'help'    => __( 'Optional message shown to a blocked visitor (403).', 'ux-studio' ),
				'default' => '',
			),

			// Country blocklist.
			array(
				'key'     => 'country_blocklist_enabled',
				'section' => __( 'Firewall', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable country blocklist', 'ux-studio' ),
				'help'    => __( 'Blocks visitors from the selected countries via the IP firewall (downloads aggregated IP ranges per country).', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'blocked_countries',
				'section' => __( 'Firewall', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Blocked countries', 'ux-studio' ),
				'help'    => __( 'Selecting a country here creates/refreshes a country-type IP ban the next time settings are saved.', 'ux-studio' ),
				'options' => $country_options,
				'default' => array(),
			),

			// Canonical URL / protocols.
			array(
				'key'     => 'canonical_redirect',
				'section' => __( 'HTTPS & headers', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Force canonical URL redirect', 'ux-studio' ),
				'help'    => __( 'Writes .htaccess rules that redirect every request to a single canonical version of the site, based on Settings -> General -> Site Address.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'force_https',
				'section' => __( 'HTTPS & headers', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Force HTTPS', 'ux-studio' ),
				'help'    => __( 'Redirects all HTTP requests to HTTPS. Only enable with a working SSL certificate on the whole site. Ignored when canonical URL redirect is on (it already covers this).', 'ux-studio' ),
				'default' => false,
			),

			// Security headers.
			array(
				'key'     => 'security_headers',
				'section' => __( 'HTTPS & headers', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Security headers', 'ux-studio' ),
				'help'    => __( 'Sends X-Frame-Options, X-Content-Type-Options and Referrer-Policy, both via .htaccess and as a PHP fallback.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'enable_hsts',
				'section' => __( 'HTTPS & headers', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'HSTS (Strict-Transport-Security)', 'ux-studio' ),
				'help'    => __( 'Tells browsers to only ever connect over HTTPS for a year. Only enable with a valid SSL certificate everywhere on the site.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'enable_permissions_policy',
				'section' => __( 'HTTPS & headers', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Permissions-Policy', 'ux-studio' ),
				'help'    => __( 'Disables browser access to camera, microphone, geolocation, payment and USB APIs by default.', 'ux-studio' ),
				'default' => false,
			),

			// Content-Security-Policy (rendered on the CSP tab, not in the generic grid).
			array(
				'key'     => 'csp_mode',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Content-Security-Policy (CSP)', 'ux-studio' ),
				'help'    => __( 'CSP tells the browser where scripts, styles, images and other content may load from. It can break parts of the website (payments, chat, maps, analytics, booking widgets) when a needed source is missing, so go step by step: Inventory finds out what the website loads, Policy test shows what Enforce would block, and only then Enforce blocks for real. Inventory and Policy test never block anything. CSP is never sent in wp-admin.', 'ux-studio' ),
				'options' => array(
					'off'         => __( 'Off', 'ux-studio' ),
					'audit'       => __( 'Inventory - find out what the website loads (Report only, recommended first)', 'ux-studio' ),
					'report-only' => __( 'Policy test - report what Enforce would block (Report only)', 'ux-studio' ),
					'enforce'     => __( 'Enforce - actively block disallowed sources', 'ux-studio' ),
				),
				'default' => 'off',
			),
			array(
				'key'     => 'csp_level',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'CSP strictness', 'ux-studio' ),
				'help'    => __( 'Basic allows inline scripts and eval() so it stays compatible with most themes, plugins and visual editors. Strict removes eval() for tighter protection but may break page builders (Elementor, WPBakery, Divi).', 'ux-studio' ),
				'options' => array(
					'basic'  => __( 'Basic (recommended - allows eval)', 'ux-studio' ),
					'strict' => __( 'Strict (no eval)', 'ux-studio' ),
				),
				'default' => 'basic',
			),
			array(
				'key'     => 'csp_presets',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'CSP - allowed services', 'ux-studio' ),
				'help'    => __( 'Select the external services the site uses. Matching domains are added to the policy so their scripts, styles, fonts and images keep working.', 'ux-studio' ),
				'options' => self::csp_preset_labels(),
				'default' => array(),
			),
			array(
				'key'     => 'csp_custom_allowlist',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'CSP - custom domain allowlist', 'ux-studio' ),
				'help'    => __( 'One domain per line, added to script/style/img/font/connect/frame sources. Examples: https://cdn.example.com, https://*.example.com, wss://socket.example.com. Sources approved in the inventory below are added only for the type they were seen with and do not need to be listed here.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'csp_sample_rate',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Report-only sampling (% of page views)', 'ux-studio' ),
				'help'    => __( 'Share of page views that carry the Report-only header in Inventory and Policy test. Every report is a small request to the website; lower this on very busy sites. Over 600 reports an hour collecting pauses until the next full hour automatically. Enforce is always sent on every page view.', 'ux-studio' ),
				'default' => 100,
			),
			array(
				'key'     => 'csp_auto_rollback',
				'section' => __( 'CSP', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Switch back to Policy test automatically', 'ux-studio' ),
				'help'    => __( 'When Enforce blocks several new scripts, connections or frames that visitors keep loading within 72 hours of switching it on, CSP goes back to Policy test (nothing blocked) and the administrator gets an e-mail.', 'ux-studio' ),
				'default' => true,
			),

			// File protection (.htaccess).
			array(
				'key'     => 'protect_wp_config',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Protect wp-config.php', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_directory_listing',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable directory listing', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'block_sensitive_files',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Block readme.html / license.txt', 'ux-studio' ),
				'default' => false,
			),

			// Custom login URL.
			array(
				'key'     => 'login_url_enabled',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable custom login URL', 'ux-studio' ),
				'help'    => __( 'Rescue switch: define UXSTUDIO_DISABLE_LOGIN_LOCK in wp-config.php to bypass this feature entirely if you get locked out.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'login_url',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Custom login slug', 'ux-studio' ),
				'help'    => __( 'New slug for the login page. Direct visitors to wp-login.php get the response chosen below. If the slug collides with an existing page/rewrite rule, the redirect is automatically NOT activated (fail-open) and a warning is logged.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'disabled_behavior',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Direct wp-login.php behavior', 'ux-studio' ),
				'help'    => __( 'What a visitor who opens wp-login.php (or wp-admin while logged out) directly gets once the custom login URL is active.', 'ux-studio' ),
				'options' => array(
					'404'           => __( '404 page', 'ux-studio' ),
					'home'          => __( 'Homepage', 'ux-studio' ),
					'url_redirect'  => __( 'Custom path on this site', 'ux-studio' ),
					'existing_page' => __( 'Existing page', 'ux-studio' ),
					'message'       => __( 'Custom message (403)', 'ux-studio' ),
				),
				'default' => '404',
			),
			array(
				'key'     => 'url_redirect',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Redirect path', 'ux-studio' ),
				'help'    => __( 'Used with "Custom path on this site". A slug or path on the current domain, e.g. about-us or contact/address. The domain is added automatically, so the redirect keeps working after a domain move.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'existing_page',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Redirect page', 'ux-studio' ),
				'help'    => __( 'Used with "Existing page".', 'ux-studio' ),
				'options' => self::page_options(),
				'default' => '',
			),
			array(
				'key'     => 'disabled_message',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Access denied message', 'ux-studio' ),
				'help'    => __( 'Used with "Custom message (403)". Plain text shown to the visitor.', 'ux-studio' ),
				'default' => __( 'This has been disabled.', 'ux-studio' ),
			),

			// Login rate limiting.
			array(
				'key'     => 'limit_login_attempts',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Limit login attempts', 'ux-studio' ),
				'help'    => __( 'Blocks an IP/username after too many failed login attempts (brute-force protection).', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'max_attempts',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Max attempts', 'ux-studio' ),
				'default' => 3,
			),
			array(
				'key'     => 'lockout_time',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Lockout time (minutes)', 'ux-studio' ),
				'default' => 30,
			),
			array(
				'key'     => 'notify_admin',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Email admin on lockout', 'ux-studio' ),
				'default' => false,
			),

			// Block usernames.
			array(
				'key'     => 'block_usernames',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Block reserved usernames', 'ux-studio' ),
				'help'    => __( 'Prevents registering/renaming to reserved names such as admin, administrator, root, test.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'blocked_usernames_extra',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Additional blocked usernames', 'ux-studio' ),
				'help'    => __( 'One per line, added on top of the built-in reserved list (admin, administrator, root, test).', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'block_username_attempts',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Lock out IPs that try a blocked username', 'ux-studio' ),
				'help'    => __( 'A failed login with a reserved username locks the IP out immediately (for the lockout time above) instead of after the maximum number of attempts. Requires "Limit login attempts" and "Block reserved usernames".', 'ux-studio' ),
				'default' => false,
			),

			// CAPTCHA.
			array(
				'key'     => 'captcha_enabled',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable CAPTCHA', 'ux-studio' ),
				'help'    => __( 'Adds CAPTCHA verification to login, registration and lost-password forms. Without both keys set, nothing is enforced.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'captcha_provider',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
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
				'key'     => 'captcha_recaptcha_v3_threshold',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'reCAPTCHA v3 minimum score', 'ux-studio' ),
				'help'    => __( 'Only for Google reCAPTCHA v3. Score from 0.0 (bot) to 1.0 (human); requests below it are rejected. Recommended 0.5.', 'ux-studio' ),
				'default' => 0.5,
			),
			array(
				'key'     => 'captcha_placement',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Placement', 'ux-studio' ),
				'help'    => __( 'Inline shows the widget inside the login/register/lost-password form itself. Standalone gate shows a dedicated "verify you\'re not a robot" page before the form is reachable at all - since WordPress redirects every logged-out admin request to wp-login.php, this also protects the whole admin, not just the login form. The gate ignores enforcement mode below and always applies (adaptive mode only affects the inline widget).', 'ux-studio' ),
				'options' => array(
					'inline' => __( 'Inline widget in the form', 'ux-studio' ),
					'gate'   => __( 'Standalone page before login (also gates wp-admin)', 'ux-studio' ),
				),
				'default' => 'gate',
			),
			array(
				'key'     => 'captcha_mode',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Enforcement mode (inline placement only)', 'ux-studio' ),
				'options' => array(
					'always'   => __( 'Always', 'ux-studio' ),
					'adaptive' => __( 'Adaptive (after repeated failures)', 'ux-studio' ),
				),
				'default' => 'always',
			),
			array(
				'key'     => 'captcha_adaptive_threshold',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Adaptive mode threshold', 'ux-studio' ),
				'help'    => __( 'Only for adaptive mode: after how many failed attempts from one IP the CAPTCHA starts being required (1-20).', 'ux-studio' ),
				'default' => 2,
			),
			array(
				'key'     => 'captcha_forms',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Protected forms (inline placement only)', 'ux-studio' ),
				'help'    => __( 'Which forms show and verify the CAPTCHA widget. The standalone gate always covers login and lost password.', 'ux-studio' ),
				'options' => array(
					'login'        => __( 'Login', 'ux-studio' ),
					'register'     => __( 'Registration', 'ux-studio' ),
					'lostpassword' => __( 'Lost password', 'ux-studio' ),
				),
				'default' => array( 'login', 'register', 'lostpassword' ),
			),
			array(
				'key'     => 'captcha_site_key',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Site key', 'ux-studio' ),
				'help'    => __( 'Public key, safe to expose in the page markup.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'captcha_secret_key',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Secret key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted server-side. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'captcha_key_source',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Key source', 'ux-studio' ),
				'help'    => __( 'Manual: paste site/secret key yourself. Central app: this site (already paired via Content Sync) gets a key automatically from a shared Turnstile widget managed by the central app. Own Cloudflare API token: this site manages its own Turnstile widget directly, using an API token you paste below.', 'ux-studio' ),
				'options' => array(
					'manual'   => __( 'Manual (paste keys below)', 'ux-studio' ),
					'ca'       => __( 'Automatic via central app', 'ux-studio' ),
					'self_api' => __( 'Automatic via own Cloudflare API token', 'ux-studio' ),
				),
				'default' => 'manual',
			),
			array(
				'key'     => 'captcha_cf_account_id',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Cloudflare account ID', 'ux-studio' ),
				'help'    => __( 'Used only for "own Cloudflare API token" key source.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'captcha_cf_api_token',
				'section' => __( 'CAPTCHA', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Cloudflare API token', 'ux-studio' ),
				'help'    => __( 'Scope it to Account > Turnstile > Edit only, nothing else. Stored encrypted server-side. Leave blank to keep the current token.', 'ux-studio' ),
				'default' => '',
			),

			// Cleanup / performance / disable-features.
			array(
				'key'     => 'disable_xmlrpc',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable XML-RPC', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'hide_wp_version',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide WordPress version', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'disable_emojis',
				'section' => __( 'Performance', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable emoji scripts', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_embeds',
				'section' => __( 'Performance', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable embeds script', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_rss_feeds',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable RSS feeds', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'restrict_rest_api',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Restrict REST API to logged-in users', 'ux-studio' ),
				'help'    => __( 'Blocks the WordPress REST API for anonymous visitors. Common public endpoints (oEmbed, WooCommerce, Contact Form 7, WPForms, Elementor, site health) stay reachable so front-end features keep working.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'heartbeat_control',
				'section' => __( 'Performance', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Heartbeat API', 'ux-studio' ),
				'help'    => __( 'Heartbeat periodically talks to the server in the background (autosave, post locking, session checks). Slowing it down reduces server load on busy admins. Ignored when WP Rocket, LiteSpeed Cache, Heartbeat Control or Perfmatters already manage Heartbeat.', 'ux-studio' ),
				'options' => array(
					'normal'  => __( 'Default (15 s)', 'ux-studio' ),
					'slow'    => __( 'Slowed down (60 s)', 'ux-studio' ),
					'disable' => __( 'Disabled', 'ux-studio' ),
				),
				'default' => 'normal',
			),
			array(
				'key'     => 'limit_revisions',
				'section' => __( 'Performance', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Limit post revisions', 'ux-studio' ),
				'help'    => __( 'Limits how many revisions are kept for each post.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'revisions_count',
				'section' => __( 'Performance', 'ux-studio' ),
				'type'    => 'number',
				'label'   => __( 'Maximum number of revisions', 'ux-studio' ),
				'help'    => __( 'Recommended value is 3-10. Setting 0 disables revisions entirely.', 'ux-studio' ),
				'default' => 5,
			),
			array(
				'key'     => 'restrict_mime_types',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Restrict uploadable file types', 'ux-studio' ),
				'help'    => __( 'Allows only common images, documents (PDF, Office), ZIP, MP3/MP4/WebM and web fonts. SVG stays allowed only if the SVG Upload module permits it.', 'ux-studio' ),
				'default' => false,
			),

			// Content - features to disable.
			array(
				'key'     => 'disable_comments',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable comments', 'ux-studio' ),
				'help'    => __( 'Completely disables comments site-wide - closes comments on all content types, removes the Comments section from wp-admin and hides the comment counter from the admin bar. Existing comments stay in the database but are not displayed.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_comments_scope',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'select',
				'label'   => __( 'Where to disable comments', 'ux-studio' ),
				'help'    => __( 'Everywhere closes comments on all content and removes the comment tools from the admin. For selected post types only those post types are affected.', 'ux-studio' ),
				'options' => array(
					'all'      => __( 'Everywhere', 'ux-studio' ),
					'selected' => __( 'Only for selected post types', 'ux-studio' ),
				),
				'default' => 'all',
			),
			array(
				'key'     => 'disable_comments_post_types',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'multiselect',
				'label'   => __( 'Post types without comments', 'ux-studio' ),
				'help'    => __( 'Used when "Only for selected post types" is chosen.', 'ux-studio' ),
				'options' => self::post_type_options(),
				'default' => array(),
			),
			array(
				'key'     => 'disable_comments_hide_existing',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide existing comments', 'ux-studio' ),
				'help'    => __( 'Existing comments are hidden on the site but not deleted - switching this off shows them again.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'disable_comments_admin_ui',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Remove comment tools from the admin', 'ux-studio' ),
				'help'    => __( 'Hides the Comments menu, the Discussion settings page, the dashboard widget, the admin bar bubble and the Recent Comments widget. Applies only when disabling everywhere.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'disable_comments_block_endpoints',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Block comment endpoints', 'ux-studio' ),
				'help'    => __( 'Removes the REST API comment routes, XML-RPC comment/pingback methods, the X-Pingback header and comment feeds. Applies only when disabling everywhere.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'disable_blog',
				'section' => __( 'Content', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable blog', 'ux-studio' ),
				'help'    => __( 'Removes blog features - hides "Posts" from the admin menu, redirects blog posts and archives to the homepage and hides the Quick Draft dashboard widget. Suitable for presentation sites without a blog.', 'ux-studio' ),
				'default' => false,
			),

			// Extra hardening (ported from legacy).
			array(
				'key'     => 'block_user_enumeration',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Block user enumeration', 'ux-studio' ),
				'help'    => __( 'Stops attackers harvesting usernames via ?author=N redirects and the public /wp/v2/users REST route. Logged-in users keep full access.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'hide_author_slugs',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Hide author slugs', 'ux-studio' ),
				'help'    => __( 'Replaces usernames in author archive URLs and REST API responses with an encrypted ID, so the login name cannot be read from the URL. Use instead of or together with blocking user enumeration.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'disable_file_editing',
				'section' => __( 'Hardening', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Disable theme/plugin file editor', 'ux-studio' ),
				'help'    => __( 'Defines DISALLOW_FILE_EDIT so nobody can edit theme or plugin PHP from wp-admin - a common way attackers turn a stolen admin login into code execution.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'protect_login',
				'section' => __( 'Login', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Harden the login page', 'ux-studio' ),
				'help'    => __( 'Hides detailed login error messages (so an attacker cannot tell which of username/password was wrong) and disables Application Passwords.', 'ux-studio' ),
				'default' => false,
			),

			// Upload Guard (malware scanner) configuration.
			array(
				'key'     => 'upload_guard_enabled',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Enable Upload Guard scanner', 'ux-studio' ),
				'help'    => __( 'Checks new uploads and runs a scheduled night scan of uploads, plugins and themes. It only reports - files are never moved or deleted automatically. Plugin files identical to the official WordPress.org version count as clean. Turning this off stops all scanning (existing findings are kept). CSP violation reporting is unaffected.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'upload_guard_schedule',
				'type'    => 'select',
				'label'   => __( 'Full scan frequency', 'ux-studio' ),
				'help'    => __( 'Automatic full scans run only at night, in a 4-hour window starting between 01:00 and 03:00 site time (different for each site), in small batches with pauses, and wait when the server is busy. Unchanged files are skipped without being read; every 4th run reads everything. New uploads are always checked right away.', 'ux-studio' ),
				'options' => array(
					'weekly' => __( 'Weekly (recommended)', 'ux-studio' ),
					'daily'  => __( 'Daily', 'ux-studio' ),
					'manual' => __( 'Only when I click "Scan now"', 'ux-studio' ),
				),
				'default' => 'weekly',
			),
			array(
				'key'     => 'upload_guard_notify',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'toggle',
				'label'   => __( 'Email admin on detections', 'ux-studio' ),
				'help'    => __( 'Sends a throttled summary email when new high/critical findings appear (at most one per 15 minutes).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'upload_guard_notify_email',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'text',
				'label'   => __( 'Notification email', 'ux-studio' ),
				'help'    => __( 'Where to send Upload Guard alerts. Leave blank to use the site admin email.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'upload_guard_custom_patterns',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Custom patterns (advanced)', 'ux-studio' ),
				'help'    => __( 'Your own regular expressions for suspicious code, one per line, e.g. /malicious_function\s*\(/i. Lines starting with # are comments. Each matching pattern adds 25 points to the file score. Invalid expressions are commented out on save and never used.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'upload_guard_whitelist_paths',
				'section' => __( 'Uploads', 'ux-studio' ),
				'type'    => 'textarea',
				'label'   => __( 'Skipped paths', 'ux-studio' ),
				'help'    => __( 'Files under these paths are never scanned, one per line. Either an absolute path or a path relative to the site, e.g. wp-content/plugins/cache-plugin/. Useful for a trusted plugin that legitimately uses eval() or base64_decode().', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Published pages for the "Existing page" redirect target.
	 *
	 * @return array<int|string,string>
	 */
	private static function page_options(): array {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$options = array();
		foreach ( $pages as $page ) {
			$options[ $page->ID ] = '' !== $page->post_title ? $page->post_title : '#' . $page->ID;
		}
		return $options;
	}

	/**
	 * CSP preset checkbox labels (keys match CspServices::preset_sources()).
	 *
	 * @return array<string,string>
	 */
	private static function csp_preset_labels(): array {
		return array(
			'google_fonts'   => __( 'Google Fonts', 'ux-studio' ),
			'gtm_ga'         => __( 'Google Tag Manager + Analytics', 'ux-studio' ),
			'youtube'        => __( 'YouTube (embedded videos)', 'ux-studio' ),
			'vimeo'          => __( 'Vimeo (embedded videos)', 'ux-studio' ),
			'smartsupp'      => __( 'Smartsupp (chat)', 'ux-studio' ),
			'recaptcha'      => __( 'Google reCAPTCHA', 'ux-studio' ),
			'cloudflare'     => __( 'Cloudflare / CloudFront CDN', 'ux-studio' ),
			'mapy_cz'        => __( 'Mapy.cz', 'ux-studio' ),
			'google_maps'    => __( 'Google Maps', 'ux-studio' ),
			'facebook_pixel' => __( 'Facebook Pixel', 'ux-studio' ),
			'gravatar'       => __( 'Gravatar (comment avatars)', 'ux-studio' ),
		);
	}

	/**
	 * Intercept the CAPTCHA secret key before it reaches the plain settings
	 * option, and sync the country blocklist to IP bans.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		$previous_csp_mode = $this->csp_policy()->mode();
		[ $input, $csp_blocked, $csp_override ] = $this->apply_csp_gate( $input, $previous_csp_mode );

		if ( array_key_exists( 'captcha_secret_key', $input ) && '' !== (string) $input['captcha_secret_key'] ) {
			Security::store_secret( self::SECRET_CAPTCHA, (string) $input['captcha_secret_key'] );
		}
		unset( $input['captcha_secret_key'] );

		if ( array_key_exists( 'captcha_cf_api_token', $input ) && '' !== (string) $input['captcha_cf_api_token'] ) {
			Security::store_secret( self::SECRET_CF_API_TOKEN, (string) $input['captcha_cf_api_token'] );
		}
		unset( $input['captcha_cf_api_token'] );

		$input = $this->maybe_provision_captcha_keys( $input );

		if ( array_key_exists( 'captcha_adaptive_threshold', $input ) ) {
			$input['captcha_adaptive_threshold'] = max( 1, min( 20, (int) $input['captcha_adaptive_threshold'] ) );
		}
		if ( array_key_exists( 'captcha_recaptcha_v3_threshold', $input ) ) {
			$input['captcha_recaptcha_v3_threshold'] = round( max( 0.0, min( 1.0, (float) $input['captcha_recaptcha_v3_threshold'] ) ), 2 );
		}
		if ( array_key_exists( 'url_redirect', $input ) ) {
			$input['url_redirect'] = self::normalize_redirect_path( (string) $input['url_redirect'] );
		}

		// Regexes must not pass through the generic textarea sanitizer, which
		// strips anything tag-like ("<script", "<\?php") and %XX sequences -
		// exactly what malware patterns contain. Validated and stored separately.
		$custom_patterns = null;
		if ( array_key_exists( 'upload_guard_custom_patterns', $input ) ) {
			$custom_patterns = UploadGuardDetectionEngine::clean_custom_patterns( (string) $input['upload_guard_custom_patterns'] );
			unset( $input['upload_guard_custom_patterns'] );
		}

		$result = parent::save_settings( $input );

		if ( null !== $custom_patterns ) {
			$this->store_raw_setting( 'upload_guard_custom_patterns', $custom_patterns );
			$result = $this->settings_values();
		}

		delete_option( HtaccessWriter::HASH_OPTION );
		delete_transient( self::HTACCESS_BACKOFF );
		$this->maybe_update_htaccess();
		$this->sync_country_blocklist();

		$this->after_csp_save( $previous_csp_mode, $csp_override );
		$result['csp_gate_blocked'] = $csp_blocked;

		return $result;
	}

	/**
	 * Enforce only when the gate is passed (CspGate) or the admin explicitly
	 * overrides it in the confirmation dialog. The override flag is consumed
	 * here and never stored. Returns [input, blocked, override].
	 *
	 * @param array  $input   Raw input.
	 * @param string $current Mode before this save.
	 * @return array{0:array,1:bool,2:bool}
	 */
	private function apply_csp_gate( array $input, string $current ): array {
		$override = ! empty( $input['csp_enforce_override'] );
		unset( $input['csp_enforce_override'] );

		if ( array_key_exists( 'csp_sample_rate', $input ) ) {
			$input['csp_sample_rate'] = max( 1, min( 100, (int) $input['csp_sample_rate'] ) );
		}

		$blocked = false;
		if ( 'enforce' === (string) ( $input['csp_mode'] ?? '' ) && 'enforce' !== $current && ! $override && ! CspGate::ready() ) {
			$input['csp_mode'] = $current;
			$blocked           = true;
		}
		return array( $input, $blocked, $override );
	}

	/**
	 * Bookkeeping after a save that changed the CSP mode.
	 */
	private function after_csp_save( string $previous, bool $override ): void {
		$mode = $this->csp_policy()->mode();
		if ( $mode === $previous ) {
			return;
		}
		CspPolicy::record_mode( $mode );
		if ( 'off' === $mode ) {
			CspMaintenance::unschedule();
		}
		ActivityLog::log(
			'security-optimization',
			'csp_mode_changed',
			'',
			0,
			array(
				'from'          => $previous,
				'to'            => $mode,
				'gate_override' => $override && 'enforce' === $mode && ! CspGate::ready(),
			)
		);
	}

	/**
	 * Never leak the CAPTCHA secret back to the client; expose only whether it's set.
	 */
	public function settings_values(): array {
		$values                           = parent::settings_values();
		$values['captcha_secret_key']     = '';
		$values['has_captcha_secret_key'] = '' !== $this->captcha_secret_key();
		$values['captcha_cf_api_token']   = '';
		$values['has_captcha_cf_api_token'] = '' !== $this->captcha_cf_api_token();

		// Migrated legacy values live under the old lwug_* keys until first save.
		$values['upload_guard_custom_patterns'] = UploadGuardDetectionEngine::custom_patterns_setting();
		$values['upload_guard_whitelist_paths'] = UploadGuardScope::whitelist_setting();
		return $values;
	}

	/**
	 * Write one value straight into the module option, bypassing the schema
	 * sanitizer (caller has validated it), and reload the cached settings.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Already validated value.
	 */
	private function store_raw_setting( string $key, $value ): void {
		$option         = 'uxstudio_security_optimization';
		$values         = (array) get_option( $option, array() );
		$values[ $key ] = $value;
		update_option( $option, $values, Settings::should_autoload( $values ) );
		$this->settings = new Settings( $option );
	}

	/**
	 * Reduce the "custom path" redirect target to a site-relative path
	 * (legacy accepted a full URL too - only its path was ever used).
	 */
	private static function normalize_redirect_path( string $value ): string {
		$value = trim( sanitize_text_field( $value ) );
		if ( '' === $value ) {
			return '';
		}
		$path = wp_parse_url( $value, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = $value;
		}
		$path = str_replace( array( '\\', '..' ), array( '/', '' ), $path );
		return trim( (string) preg_replace( '#/+#', '/', $path ), '/' );
	}

	/**
	 * When `captcha_key_source` is `ca` or `self_api`, auto-fill
	 * captcha_site_key (and store the secret key) via the matching
	 * provisioner - either once (no site key yet) or again after the site's
	 * public domain changed (covers a staging -> prod move without a
	 * dedicated "re-sync" button, just re-saving the settings screen).
	 *
	 * Provisioning failures are logged, never block the rest of the save -
	 * CaptchaVerifier::is_configured() already requires both keys present,
	 * so until provisioning succeeds the gate simply stays inactive instead
	 * of locking anyone out.
	 *
	 * @param array $input Raw input, already stripped of the secret fields.
	 * @return array Input, with captcha_site_key filled in on success.
	 */
	private function maybe_provision_captcha_keys( array $input ): array {
		$key_source = (string) ( $input['captcha_key_source'] ?? $this->setting( 'captcha_key_source', 'manual' ) );
		if ( ! in_array( $key_source, array( 'ca', 'self_api' ), true ) ) {
			return $input;
		}

		$domain_option    = 'uxstudio_security_optimization_cf_registered_domain';
		$current_domain   = home_url( '/' );
		$site_key_present = '' !== (string) ( $input['captcha_site_key'] ?? $this->setting( 'captcha_site_key', '' ) );
		$domain_changed   = get_option( $domain_option, '' ) !== $current_domain;

		if ( $site_key_present && ! $domain_changed ) {
			return $input;
		}

		$result = 'ca' === $key_source
			? TurnstileCaProvisioner::provision( $this )
			: TurnstileSelfProvisioner::provision( $this );

		if ( is_wp_error( $result ) ) {
			ActivityLog::log(
				'security-optimization',
				'captcha_provision_failed',
				'turnstile',
				0,
				array( 'source' => $key_source, 'error' => $result->get_error_message() )
			);
			return $input;
		}

		$input['captcha_site_key'] = (string) $result['site_key'];
		Security::store_secret( self::SECRET_CAPTCHA, (string) $result['secret_key'] );
		update_option( $domain_option, $current_domain );

		ActivityLog::log( 'security-optimization', 'captcha_provisioned', 'turnstile', 0, array( 'source' => $key_source ) );

		return $input;
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

		$ip = $store->get_client_ip( $this->proxy_mode() );
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
			header( 'X-XSS-Protection: 1; mode=block' );
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
	   Content-Security-Policy (front end only)
	   ═══════════════════════════════════════════════════ */

	/**
	 * Emit the CSP header on front-end requests (see CspPolicy).
	 */
	public function maybe_send_csp_header(): void {
		$this->csp_policy()->send();
	}

	public function csp_policy(): CspPolicy {
		return new CspPolicy( array( $this, 'setting' ) );
	}

	/* ═══════════════════════════════════════════════════
	   Extra hardening
	   ═══════════════════════════════════════════════════ */

	/**
	 * Redirect ?author=N enumeration probes to the homepage (front end only).
	 */
	public function block_author_enumeration(): void {
		if ( is_admin() ) {
			return;
		}
		$qs = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' !== $qs && preg_match( '/author=\d+/i', $qs ) ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * Remove the public users REST routes for anonymous visitors.
	 *
	 * @param array $endpoints REST endpoint map.
	 */
	public function disable_user_rest_route( $endpoints ) {
		if ( ! is_user_logged_in() && is_array( $endpoints ) ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	/**
	 * Resolve an encrypted author slug back to the real user; anything that
	 * isn't a valid encrypted slug (including the plain username) 404s.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function handle_author_query( $query ): void {
		if ( ! $query->is_author() || empty( $query->query_vars['author_name'] ) ) {
			return;
		}

		$slug    = (string) $query->query_vars['author_name'];
		$user_id = ctype_xdigit( $slug ) ? $this->decrypt_author_slug( $slug ) : 0;
		$user    = $user_id > 0 ? get_user_by( 'id', $user_id ) : false;

		if ( ! $user ) {
			$query->is_404     = true;
			$query->is_author  = false;
			$query->is_archive = false;
			return;
		}

		$query->set( 'author_name', $user->user_nicename );
	}

	/**
	 * @param string $link        Author archive URL.
	 * @param int    $user_id     User ID.
	 * @param string $author_slug Original nicename.
	 */
	public function encrypt_author_link( $link, $user_id, $author_slug ): string {
		$encrypted = $this->encrypt_author_slug( (int) $user_id );
		return '' === $encrypted ? (string) $link : str_replace( '/' . $author_slug, '/' . $encrypted, (string) $link );
	}

	/**
	 * @param \WP_REST_Response $response REST response.
	 * @param \WP_User          $user     User (unused).
	 * @param \WP_REST_Request  $request  Request (unused).
	 */
	public function encrypt_rest_api_user( $response, $user, $request ) {
		$data = $response->get_data();
		if ( isset( $data['id'] ) ) {
			$encrypted = $this->encrypt_author_slug( (int) $data['id'] );
			if ( '' !== $encrypted ) {
				$data['slug'] = $encrypted;
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/**
	 * Deterministic (same ID -> same slug, so URLs stay stable) reversible
	 * encoding of the user ID. Legacy used DES-EDE3 keyed by SERVER_ADDR +
	 * plugin URL, which OpenSSL 3 no longer ships by default and which changed
	 * whenever the server IP moved; keyed by the site salt instead.
	 */
	private function encrypt_author_slug( int $user_id ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$encrypted = openssl_encrypt( base_convert( (string) $user_id, 10, 36 ), 'aes-128-ecb', $this->author_slug_key(), OPENSSL_RAW_DATA );
		return false === $encrypted ? '' : bin2hex( $encrypted );
	}

	private function decrypt_author_slug( string $slug ): int {
		if ( ! function_exists( 'openssl_decrypt' ) || 0 !== strlen( $slug ) % 2 ) {
			return 0;
		}
		$decrypted = openssl_decrypt( (string) hex2bin( $slug ), 'aes-128-ecb', $this->author_slug_key(), OPENSSL_RAW_DATA );
		return false === $decrypted ? 0 : (int) base_convert( $decrypted, 36, 10 );
	}

	private function author_slug_key(): string {
		return substr( hash( 'sha256', wp_salt( 'auth' ) . '|uxstudio-author-slug', true ), 0, 16 );
	}

	/**
	 * Replace detailed login error text with a generic message.
	 *
	 * @param string $error Original error markup (unused).
	 */
	public function sanitize_login_errors( $error ): string {
		return __( 'Invalid login credentials. Please check both the username and the password.', 'ux-studio' );
	}

	/**
	 * Strip the X-Pingback response header (XML-RPC endpoint advertisement).
	 *
	 * @param array $headers Response headers.
	 */
	public function remove_x_pingback( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'], $headers['x-pingback'] );
		}
		return $headers;
	}

	/**
	 * Hide the WordPress version in enqueued asset URLs. Only a `ver` equal to
	 * the core version is touched - and replaced by a site-salted hash of it
	 * rather than dropped, so it still changes on a core update (assets can be
	 * served with long immutable cache headers; stripping `ver` would pin stale
	 * CSS/JS). Plugin/theme versions are left intact.
	 *
	 * @param string $src Asset URL.
	 */
	public function remove_version_query_arg( $src ): string {
		$src = (string) $src;
		if ( false === strpos( $src, 'ver=' ) ) {
			return $src;
		}

		static $wp_version = null, $masked = null;
		if ( null === $wp_version ) {
			$wp_version = (string) get_bloginfo( 'version' );
			$masked     = substr( wp_hash( 'uxstudio-ver|' . $wp_version ), 0, 10 );
		}

		$query = (string) wp_parse_url( $src, PHP_URL_QUERY );
		parse_str( $query, $args );
		if ( '' === $wp_version || ! isset( $args['ver'] ) || (string) $args['ver'] !== $wp_version ) {
			return $src;
		}

		return add_query_arg( 'ver', $masked, $src );
	}

	/* ═══════════════════════════════════════════════════
	   .htaccess sync
	   ═══════════════════════════════════════════════════ */

	/**
	 * Only the keys HtaccessWriter::build_rules() reads - deliberately NOT
	 * settings_values(), which builds the whole schema and decrypts secrets.
	 *
	 * @return array<string,mixed>
	 */
	public function htaccess_settings(): array {
		$out = array();
		foreach ( array( 'canonical_redirect', 'force_https', 'disable_directory_listing', 'protect_wp_config', 'block_sensitive_files', 'security_headers', 'enable_hsts' ) as $key ) {
			$out[ $key ] = $this->setting( $key, false );
		}
		return $out;
	}

	/**
	 * Write the rules if the stored hash no longer matches the settings. Runs on
	 * admin_init and after a settings save (which clears the backoff). A failed
	 * write (unwritable .htaccess) backs off for an hour instead of retrying on
	 * every admin request.
	 */
	public function maybe_update_htaccess(): void {
		if ( get_transient( self::HTACCESS_BACKOFF ) ) {
			return;
		}

		$writer   = $this->htaccess_writer();
		$settings = $this->htaccess_settings();

		if ( $writer->is_applied( $settings ) ) {
			return;
		}

		$result = $writer->apply( $settings );
		if ( true === $result ) {
			ActivityLog::log( 'security-optimization', 'htaccess_applied' );
			return;
		}

		set_transient( self::HTACCESS_BACKOFF, 1, HOUR_IN_SECONDS );
	}

	/* ═══════════════════════════════════════════════════
	   Module switched off / plugin deactivated
	   ═══════════════════════════════════════════════════ */

	/**
	 * The hash of the last written `.htaccess` block changes with a switch.
	 *
	 * @return string[]
	 */
	public function switch_options(): array {
		return array( HtaccessWriter::HASH_OPTION );
	}

	/**
	 * What save_settings() does after storing, for the migration switch: rewrite the `.htaccess`
	 * block (the hash is cleared first, so it is written even when the hash looks current) and sync
	 * the country bans.
	 */
	public function after_migration_switch(): void {
		parent::after_migration_switch();
		delete_option( HtaccessWriter::HASH_OPTION );
		delete_transient( self::HTACCESS_BACKOFF );
		$writer   = $this->htaccess_writer();
		$settings = $this->htaccess_settings();
		if ( $writer->matches_file( $settings ) ) {
			// Nothing to change in the file: leave it byte for byte as it is.
			$writer->remember( $settings );
		} else {
			$this->maybe_update_htaccess();
		}
		$this->sync_country_blocklist();
	}

	/**
	 * Remove this module's .htaccess block and unschedule its cron events.
	 * May run on an instance that was never booted - reads settings only.
	 * Data (tables, options, findings) is kept.
	 */
	public function on_disable(): void {
		$this->htaccess_writer()->remove();
		delete_transient( self::HTACCESS_BACKOFF );

		wp_clear_scheduled_hook( self::ATTEMPTS_CLEANUP_HOOK );

		// Upload Guard: nightly + chained batch events; a running full scan is
		// ended so re-enabling later does not resume a stale worklist.
		CspUploadGuardBootstrap::on_deactivate();
		CspMaintenance::unschedule();
		( new UploadGuardScanner() )->abort_full_scan();
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
		$path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		// A site in a subdirectory (example.com/blog): the slug is relative to the site address.
		$base = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		if ( '' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$path = trim( substr( $path, strlen( $base ) ), '/' );
		}
		return $path;
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
		if ( null !== get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) ) ) {
			return true;
		}
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( $rules as $pattern => $target ) {
				// The catch-all rules for pages, posts and attachments match every slug; real pages and posts are
				// checked above, so only the specific rules (category, feed, a custom post type ...) count.
				$target = (string) $target;
				if ( 0 === strpos( $target, 'index.php?pagename=' ) || 0 === strpos( $target, 'index.php?name=' ) || false !== strpos( $target, 'attachment=' ) ) {
					continue;
				}
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
	 * Runs on 'login_init' (fired only for direct wp-login.php hits): deny
	 * unless we ourselves are including it via the custom slug (bypass marker
	 * set). How it is denied follows `disabled_behavior`.
	 */
	public function block_direct_wp_login(): void {
		if ( defined( 'UXSTUDIO_LOGIN_URL_BYPASS' ) && UXSTUDIO_LOGIN_URL_BYPASS ) {
			return;
		}

		$slug = $this->login_slug();
		if ( '' === $slug || $this->login_slug_collides( $slug ) ) {
			return;
		}

		$this->deny_direct_login();
	}

	/**
	 * Ported from legacy disableLoginUrl(): 404 (default), homepage, a path on
	 * this site, an existing page, or a 403 with a custom message. A redirect
	 * whose target is missing or points back at the login (loop) falls back to
	 * the 403 message, like legacy did for an empty target.
	 */
	private function deny_direct_login(): void {
		nocache_headers();

		switch ( (string) $this->setting( 'disabled_behavior', '404' ) ) {
			case 'home':
				wp_safe_redirect( home_url( '/' ) );
				exit;

			case 'url_redirect':
				$path = self::normalize_redirect_path( (string) $this->setting( 'url_redirect', '' ) );
				if ( '' !== $path && ! $this->is_login_path( $path ) ) {
					wp_safe_redirect( home_url( '/' . $path ) );
					exit;
				}
				break;

			case 'existing_page':
				$page_id = absint( $this->setting( 'existing_page', 0 ) );
				$url     = ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) ? get_permalink( $page_id ) : false;
				if ( $url ) {
					wp_safe_redirect( $url );
					exit;
				}
				break;

			case 'message':
				break;

			default:
				status_header( 404 );
				wp_die( esc_html__( 'Not found.', 'ux-studio' ), '', array( 'response' => 404 ) );
		}

		$message = trim( (string) $this->setting( 'disabled_message', '' ) );
		if ( '' === $message ) {
			$message = __( 'This has been disabled.', 'ux-studio' );
		}
		status_header( 403 );
		wp_die( nl2br( esc_html( $message ) ), esc_html__( '403 Forbidden', 'ux-studio' ), array( 'response' => 403 ) );
	}

	/**
	 * Would redirecting to this site-relative path land on the login again?
	 */
	private function is_login_path( string $path ): bool {
		$first = strtolower( (string) strtok( $path, '/?#' ) );
		return in_array( $first, array( 'wp-login.php', 'wp-admin', strtolower( $this->login_slug() ) ), true );
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

	/* ═══════════════════════════════════════════════════
	   Performance: Heartbeat, revisions, upload types
	   ═══════════════════════════════════════════════════ */

	/**
	 * Name of a plugin that already controls Heartbeat, or false.
	 *
	 * @return string|false
	 */
	public static function heartbeat_managed_externally() {
		if ( defined( 'WP_ROCKET_VERSION' ) ) {
			return 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			return 'LiteSpeed Cache';
		}
		if ( defined( 'JERL_STARTER_SLUG' ) || defined( 'JERL_STARTER_PATH' ) ) {
			return 'Heartbeat Control';
		}
		if ( defined( 'PERFMATTERS_VERSION' ) ) {
			return 'Perfmatters';
		}
		return false;
	}

	/**
	 * @param array $settings Heartbeat settings.
	 */
	public function slow_heartbeat( $settings ) {
		$settings             = (array) $settings;
		$settings['interval'] = 60;
		return $settings;
	}

	public function disable_heartbeat(): void {
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * @param int      $num  Revisions to keep.
	 * @param \WP_Post $post Post (unused).
	 */
	public function limit_revisions( $num, $post ): int {
		return max( 0, (int) $this->setting( 'revisions_count', 5 ) );
	}

	/**
	 * Narrow the upload allowlist to common safe types. Legacy replaced the
	 * whole map (and so force-enabled SVG even with the SVG Upload module off);
	 * here the incoming map is filtered instead, so SVG - and anything another
	 * module removed, e.g. video via Disable Video Uploads - stays as decided
	 * upstream. Web fonts aren't in core's map, so they are added explicitly.
	 *
	 * @param array $mimes Extension regex => MIME type.
	 */
	public function restrict_mime_types( $mimes ): array {
		$allowed = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'mp3', 'mp4', 'webm' );

		$result = array();
		foreach ( (array) $mimes as $exts => $type ) {
			$kept = array_intersect( explode( '|', (string) $exts ), $allowed );
			if ( ! empty( $kept ) ) {
				$result[ implode( '|', $kept ) ] = $type;
			}
		}

		$result['woff']  = 'font/woff';
		$result['woff2'] = 'font/woff2';
		return $result;
	}

	/* ═══════════════════════════════════════════════════
	   Content: disable comments
	   ═══════════════════════════════════════════════════ */

	/**
	 * Closes comments and pingbacks everywhere or for selected post types,
	 * hides existing comments and (when everywhere) removes the comment admin
	 * screens, REST/XML-RPC endpoints and comment feeds. No data is deleted -
	 * switching it off restores the previous state.
	 */
	private function init_disable_comments(): void {
		add_action( 'init', array( $this, 'remove_comment_support' ), 100 );
		add_filter( 'comments_open', array( $this, 'filter_comments_open' ), 20, 2 );
		add_filter( 'pings_open', array( $this, 'filter_comments_open' ), 20, 2 );
		add_action( 'pre_comment_on_post', array( $this, 'block_comment_submission' ) );

		if ( $this->setting( 'disable_comments_hide_existing', true ) ) {
			add_filter( 'comments_array', array( $this, 'filter_comments_array' ), 20, 2 );
			add_filter( 'get_comments_number', array( $this, 'filter_comments_number' ), 20, 2 );
		}

		// The remaining switches only make sense when comments are off everywhere.
		if ( ! $this->comments_disabled_globally() ) {
			return;
		}

		if ( $this->setting( 'disable_comments_admin_ui', true ) ) {
			add_action( 'admin_menu', array( $this, 'remove_comments_admin_menus' ), 999 );
			add_action( 'admin_init', array( $this, 'block_comments_admin_screens' ) );
			add_action( 'wp_dashboard_setup', array( $this, 'remove_comments_dashboard_widget' ) );
			add_action( 'wp_before_admin_bar_render', array( $this, 'remove_comments_admin_bar_node' ) );
			add_action( 'widgets_init', array( $this, 'remove_comments_widget' ), 20 );
			add_filter( 'wp_count_comments', array( $this, 'zero_comment_count' ), 10, 2 );
		}

		if ( $this->setting( 'disable_comments_block_endpoints', true ) ) {
			add_filter( 'rest_endpoints', array( $this, 'filter_comment_rest_endpoints' ) );
			add_filter( 'xmlrpc_methods', array( $this, 'filter_comment_xmlrpc_methods' ) );
			add_filter( 'wp_headers', array( $this, 'remove_x_pingback' ) );
			add_filter( 'feed_links_show_comments_feed', '__return_false' );
			add_action( 'template_redirect', array( $this, 'redirect_comment_feeds' ), 1 );
		}
	}

	private function comments_disabled_globally(): bool {
		return 'selected' !== (string) $this->setting( 'disable_comments_scope', 'all' );
	}

	private function comments_disabled_for( string $post_type ): bool {
		if ( $this->comments_disabled_globally() ) {
			return true;
		}
		return in_array( $post_type, (array) $this->setting( 'disable_comments_post_types', array() ), true );
	}

	/**
	 * Selectable post types for the per-type scope.
	 *
	 * @return array<string,string>
	 */
	private static function post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			$options[ $type->name ] = $type->labels->name;
		}
		return $options;
	}

	public function remove_comment_support(): void {
		foreach ( get_post_types( array(), 'names' ) as $post_type ) {
			if ( ! $this->comments_disabled_for( $post_type ) ) {
				continue;
			}
			remove_post_type_support( $post_type, 'comments' );
			remove_post_type_support( $post_type, 'trackbacks' );
		}
	}

	/**
	 * @param bool         $open    Current state.
	 * @param int|\WP_Post $post_id Post.
	 */
	public function filter_comments_open( $open, $post_id ) {
		$post_type = get_post_type( $post_id );
		return ( $post_type && $this->comments_disabled_for( $post_type ) ) ? false : $open;
	}

	/**
	 * Refuse direct submissions to wp-comments-post.php.
	 *
	 * @param int|string $post_id Post ID.
	 */
	public function block_comment_submission( $post_id ): void {
		$post_type = get_post_type( (int) $post_id );
		if ( $post_type && $this->comments_disabled_for( $post_type ) ) {
			wp_die( esc_html__( 'Comments are disabled.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * @param array $comments Comments.
	 * @param int   $post_id  Post ID.
	 */
	public function filter_comments_array( $comments, $post_id ) {
		$post_type = get_post_type( $post_id );
		return ( $post_type && $this->comments_disabled_for( $post_type ) ) ? array() : $comments;
	}

	/**
	 * @param int|string $count   Comment count.
	 * @param int        $post_id Post ID.
	 */
	public function filter_comments_number( $count, $post_id ) {
		$post_type = get_post_type( $post_id );
		return ( $post_type && $this->comments_disabled_for( $post_type ) ) ? 0 : $count;
	}

	public function remove_comments_admin_menus(): void {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	public function block_comments_admin_screens(): void {
		global $pagenow;
		if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
		// Again here for post types registered after init:100.
		$this->remove_comment_support();
	}

	public function remove_comments_dashboard_widget(): void {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	public function remove_comments_admin_bar_node(): void {
		global $wp_admin_bar;
		if ( $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	public function remove_comments_widget(): void {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	/**
	 * @param array $endpoints Registered REST routes.
	 */
	public function filter_comment_rest_endpoints( $endpoints ) {
		foreach ( array_keys( (array) $endpoints ) as $route ) {
			if ( 0 === strpos( (string) $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * @param array $methods XML-RPC methods.
	 */
	public function filter_comment_xmlrpc_methods( $methods ) {
		$remove = array(
			'wp.newComment',
			'wp.getComment',
			'wp.getComments',
			'wp.getCommentCount',
			'wp.editComment',
			'wp.deleteComment',
			'wp.getCommentStatusList',
			'pingback.ping',
			'pingback.extensions.getPingbacks',
		);
		foreach ( $remove as $method ) {
			unset( $methods[ $method ] );
		}
		return $methods;
	}

	public function redirect_comment_feeds(): void {
		if ( is_comment_feed() ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Admin counters (menu bubble, At a Glance) report zero when comments are off everywhere.
	 *
	 * @param array|object $counts  Incoming counts (unused).
	 * @param int          $post_id Post ID (unused).
	 */
	public function zero_comment_count( $counts, $post_id ): object {
		return (object) array(
			'approved'            => 0,
			'awaiting_moderation' => 0,
			'moderated'           => 0,
			'spam'                => 0,
			'trash'               => 0,
			'post-trashed'        => 0,
			'total_comments'      => 0,
			'all'                 => 0,
		);
	}

	/* ═══════════════════════════════════════════════════
	   Content: disable blog
	   ═══════════════════════════════════════════════════ */

	private function init_disable_blog(): void {
		add_action( 'admin_menu', array( $this, 'remove_blog_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'disable_blog_admin_redirect' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'remove_blog_dashboard_widgets' ) );
		add_action( 'template_redirect', array( $this, 'redirect_blog_pages' ) );
		add_action( 'admin_bar_menu', array( $this, 'remove_blog_admin_bar_nodes' ), 999 );
	}

	public function remove_blog_admin_menu(): void {
		remove_menu_page( 'edit.php' );
	}

	public function disable_blog_admin_redirect(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post';
		if ( in_array( $pagenow, array( 'edit.php', 'post-new.php' ), true ) && 'post' === $post_type ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	public function remove_blog_dashboard_widgets(): void {
		remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_recent_drafts', 'dashboard', 'side' );
	}

	public function redirect_blog_pages(): void {
		if ( ! ( is_singular( 'post' ) || is_category() || is_tag() || is_date() || is_author() || is_home() ) ) {
			return;
		}
		// Homepage set to "latest posts" is the front page itself - never redirect it.
		if ( is_home() && is_front_page() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}

	/**
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public function remove_blog_admin_bar_nodes( $wp_admin_bar ): void {
		$wp_admin_bar->remove_node( 'new-post' );
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
		if ( $this->is_public_rest_route() ) {
			return $result;
		}
		return new WP_Error( 'uxstudio_rest_forbidden', __( 'REST API access is restricted to logged-in users.', 'ux-studio' ), array( 'status' => 401 ) );
	}

	/**
	 * Is the requested route explicitly exempt from the login requirement?
	 *
	 * Some endpoints have to stay reachable by anonymous clients even with the
	 * REST API locked down, because they ARE the way a visitor becomes logged
	 * in - the third-party-login callback the central app redirects back to is
	 * the canonical case. Such routes carry their own proof (HMAC signature,
	 * single-use nonce), so the login requirement adds nothing there but breaks
	 * the flow.
	 */
	private function is_public_rest_route(): bool {
		$route = $this->current_rest_route();
		if ( '' === $route ) {
			return false;
		}

		// A prefix must never be a springboard to another route via dot segments
		// (/complianz/v1/../wp/v2/users), plain or URL-encoded.
		if ( preg_match( '#(^|/)\.\.?(/|$)#', $route ) || preg_match( '#(^|/)\.\.?(/|$)#', rawurldecode( $route ) ) ) {
			return false;
		}

		/**
		 * Route prefixes reachable without login while the REST API is restricted.
		 *
		 * Starts from the built-in defaults (see default_public_rest_prefixes()),
		 * so add-ons can extend the list and the filter can also remove a default.
		 *
		 * @param array<int, string> $routes Leading-slash route prefixes.
		 */
		$public = (array) apply_filters( 'uxstudio_rest_public_routes', self::default_public_rest_prefixes() );

		foreach ( $public as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && 0 === strpos( $route, $prefix ) ) {
				return true;
			}
		}

		return $this->is_own_public_endpoint( $route );
	}

	/**
	 * Route prefixes that are public by default, whatever the filter adds.
	 *
	 * Only third-party namespaces whose front-end code has to reach the REST API
	 * as an anonymous visitor belong here. Never add core routes (wp/v2/users…).
	 *
	 * - /complianz/v1/ - Complianz cookie banner: /banner, /manage_consent_html,
	 *   /documents, /track, /store_cookies. Locking it breaks the banner and
	 *   consent is never stored.
	 *
	 * @return array<int, string> Leading-slash route prefixes.
	 */
	private static function default_public_rest_prefixes(): array {
		return array(
			'/complianz/v1/',
		);
	}

	/**
	 * UX Studio's own endpoints that are deliberately public (AI chat, push
	 * subscribe, popup tracking…) declare `permission_callback => __return_true`.
	 * Honour that for our namespace only, so the lock keeps covering core and
	 * other plugins without every module having to whitelist itself.
	 *
	 * @param string $route Requested route with a leading slash.
	 */
	private function is_own_public_endpoint( string $route ): bool {
		if ( 0 !== strpos( $route, '/uxstudio/v1/' ) || ! function_exists( 'rest_get_server' ) ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		foreach ( rest_get_server()->get_routes( 'uxstudio/v1' ) as $pattern => $handlers ) {
			if ( ! preg_match( '@^' . $pattern . '$@i', $route ) ) {
				continue;
			}
			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['methods'][ $method ] ) ) {
					continue;
				}
				return isset( $handler['permission_callback'] ) && '__return_true' === $handler['permission_callback'];
			}
		}

		return false;
	}

	/**
	 * The REST route being requested, with a leading slash.
	 *
	 * `rest_authentication_errors` fires before the REST server has resolved
	 * the route, so read it off the request the same way the server will:
	 * either the `rest_route` query var (plain permalinks) or the path after
	 * the REST prefix.
	 */
	private function current_rest_route(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing lookup.
		if ( isset( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) );
			return '/' . ltrim( $route, '/' );
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '';
		}

		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$needle = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$pos    = strpos( $path, $needle );
		if ( false === $pos ) {
			return '';
		}

		return '/' . ltrim( substr( $path, $pos + strlen( $needle ) ), '/' );
	}
}
