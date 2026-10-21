<?php
/**
 * Registry of third-party plugins whose job UX Studio modules also do.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data (PLAN.md 26.2 / 26.5). One entry = one module and the foreign
 * plugins that overlap with it:
 *
 *  - `type` full     : the module replaces the plugin completely (group A).
 *                      Enabling the module while the plugin runs is refused.
 *  - `type` partial  : the module covers only a part of the plugin (group B).
 *                      Information only, never blocks. With `confirm` => true
 *                      (Admin Customiser) enabling needs an explicit confirmation
 *                      and the settings can be migrated later (wizard, F2+).
 *  - `when`          : optional list of setting keys of the module itself
 *                      (option `uxstudio_<module_id_with_underscores>`). The
 *                      overlap exists only when at least one of them is on, so a
 *                      broad module (Security Optimization) overlaps with a
 *                      plugin only when the matching feature is switched on.
 *  - `feature`       : short English name of the overlapping feature, shown when
 *                      `when` is used.
 *  - module `*`      : "any UX Studio module" (WP Extended overlaps with all).
 *
 * Plugins are matched by FOLDER slug: `wpforms-lite` matches
 * `wpforms-lite/wpforms.php` and any other file in that folder.
 */
final class Catalog {

	public const TYPE_FULL    = 'full';
	public const TYPE_PARTIAL = 'partial';

	/** Wildcard module id: the entry overlaps with every UX Studio module. */
	public const ANY_MODULE = '*';

	/** @var array<int, array<string, mixed>>|null */
	private static ?array $cache = null;

	/**
	 * All catalog entries.
	 *
	 * @return array<int, array{module:string,type:string,plugins:array<string,string>,when?:string[],feature?:string,confirm?:bool}>
	 */
	public static function entries(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$full = static fn( string $module, array $plugins, array $extra = array() ): array => array_merge(
			array(
				'module'  => $module,
				'type'    => self::TYPE_FULL,
				'plugins' => $plugins,
			),
			$extra
		);
		$part = static fn( string $module, array $plugins, array $extra = array() ): array => array_merge(
			array(
				'module'  => $module,
				'type'    => self::TYPE_PARTIAL,
				'plugins' => $plugins,
			),
			$extra
		);

		$entries = array(
			/* Group A: full matches. */
			$full( 'classic-editor', array( 'classic-editor' => 'Classic Editor' ) ),
			$full( 'classic-widgets', array( 'classic-widgets' => 'Classic Widgets' ) ),
			$full(
				'duplicate-post',
				array(
					'duplicate-post' => 'Yoast Duplicate Post',
					'duplicate-page' => 'Duplicate Page',
					'post-duplicator' => 'Post Duplicator',
				)
			),
			$full( 'duplicate-menu', array( 'duplicate-menu' => 'Duplicate Menu' ) ),
			$full(
				'security-optimization',
				array(
					'disable-comments' => 'Disable Comments',
				),
				array(
					'when'    => array( 'disable_comments' ),
					'feature' => __( 'Disable comments', 'ux-studio' ),
				)
			),
			$full(
				'smtp-email',
				array(
					'wp-mail-smtp'   => 'WP Mail SMTP',
					'easy-wp-smtp'   => 'Easy WP SMTP',
					'post-smtp'      => 'Post SMTP',
					'fluent-smtp'    => 'FluentSMTP',
				)
			),
			$full(
				'email-log',
				array(
					'wp-mail-logging' => 'WP Mail Logging',
					'email-log'       => 'Email Log',
					'wp-mail-log'     => 'WP Mail Log',
				)
			),
			$full( 'email-health', array( 'check-email' => 'Check & Log Email' ) ),
			$full(
				'cron-control',
				array(
					'wp-crontrol'           => 'WP Crontrol',
					'advanced-cron-manager' => 'Advanced Cron Manager',
				)
			),
			$full(
				'redirects',
				array(
					'redirection'            => 'Redirection',
					'safe-redirect-manager'  => 'Safe Redirect Manager',
					'simple-301-redirects'   => 'Simple 301 Redirects',
					'301-redirects'          => '301 Redirects',
				)
			),
			$full(
				'redirect-404-to-homepage',
				array(
					'all-404-redirect-to-homepage' => 'All 404 Redirect to Homepage',
					'redirect-404-to-homepage'     => 'Redirect 404 to Homepage',
				)
			),
			$full(
				'svg-upload',
				array(
					'safe-svg'    => 'Safe SVG',
					'svg-support' => 'SVG Support',
				)
			),
			$full( 'media-replace', array( 'enable-media-replace' => 'Enable Media Replace' ) ),
			$full(
				'folder-manager',
				array(
					'filebird'               => 'FileBird',
					'folders'                => 'Folders',
					'real-media-library-lite' => 'Real Media Library',
					'wicked-folders'         => 'Wicked Folders',
				)
			),
			$full(
				'post-type-order',
				array(
					'simple-page-ordering'        => 'Simple Page Ordering',
					'post-types-order'            => 'Post Types Order',
					'intuitive-custom-post-order' => 'Intuitive Custom Post Order',
				)
			),
			$full( 'post-type-switcher', array( 'post-type-switcher' => 'Post Type Switcher' ) ),
			$full( 'user-switching', array( 'user-switching' => 'User Switching' ) ),
			$full(
				'user-last-login',
				array(
					'when-last-login' => 'When Last Login',
					'wp-last-login'   => 'WP Last Login',
				)
			),
			$full( 'admin-columns', array( 'codepress-admin-columns' => 'Admin Columns' ) ),
			$full( 'hide-admin-bar', array( 'hide-admin-bar' => 'Hide Admin Bar' ) ),
			$full(
				'maintenance-mode',
				array(
					'wp-maintenance-mode'      => 'WP Maintenance Mode',
					'coming-soon'              => 'Coming Soon',
					'maintenance'              => 'Maintenance',
					'under-construction-page'  => 'Under Construction',
				)
			),
			$full(
				'code-snippets',
				array(
					'code-snippets'              => 'Code Snippets',
					'insert-headers-and-footers' => 'WPCode',
					'header-footer-code-manager' => 'Header Footer Code Manager',
				)
			),
			$full( 'external-permalinks', array( 'page-links-to' => 'Page Links To' ) ),
			$full( 'auto-unpublish', array( 'post-expirator' => 'PublishPress Future' ) ),
			$full(
				'disable-auto-updates',
				array(
					'disable-wordpress-updates'              => 'Disable All WordPress Updates',
					'stops-core-theme-and-plugin-updates'    => 'Easy Updates Manager',
				)
			),
			$full( 'rollback-manager', array( 'wp-rollback' => 'WP Rollback' ) ),
			$full( 'stock-photos', array( 'instant-images' => 'Instant Images' ) ),
			$full( 'auto-image-upload', array( 'auto-upload-images' => 'Auto Upload Images' ) ),
			$full( 'link-manager', array( 'wp-external-links' => 'External Links' ) ),
			$full( 'menu-icons', array( 'menu-icons' => 'Menu Icons' ) ),
			$full(
				'menu-visibility',
				array(
					'if-menu'                       => 'If Menu',
					'nav-menu-roles'                => 'Nav Menu Roles',
					'menu-items-visibility-control' => 'Menu Items Visibility Control',
				)
			),
			$full(
				'post-id-display',
				array(
					'reveal-ids-for-wp-admin-25' => 'Reveal IDs',
					'show-ids'                   => 'Show IDs',
				)
			),
			$full(
				'ai-markdown',
				array(
					'website-llms-txt'        => 'Website LLMs.txt',
					'llms-full-txt-generator' => 'LLMs-Full.txt Generator',
				)
			),
			$full(
				'activity-log',
				array(
					'wp-security-audit-log'  => 'WP Activity Log',
					'simple-history'         => 'Simple History',
					'aryo-activity-log'      => 'Activity Log',
					'adminify-activity-logs' => 'Activity Logs (Adminify)',
				)
			),
			$full(
				'analytics',
				array(
					'koko-analytics'         => 'Koko Analytics',
					'independent-analytics'  => 'Independent Analytics',
					'burst-statistics'       => 'Burst Statistics',
					'wp-statistics'          => 'WP Statistics',
				)
			),
			$full(
				'security-optimization',
				array(
					'limit-login-attempts-reloaded' => 'Limit Login Attempts Reloaded',
					'wps-hide-login'                => 'WPS Hide Login',
					'loginizer'                     => 'Loginizer',
				),
				array(
					'when'    => array( 'limit_login_attempts', 'login_url_enabled' ),
					'feature' => __( 'Login protection', 'ux-studio' ),
				)
			),
			$full(
				'security-optimization',
				array(
					'simple-cloudflare-turnstile' => 'Simple CAPTCHA Alternative with Cloudflare Turnstile',
					'advanced-google-recaptcha'   => 'Advanced Google reCAPTCHA',
				),
				array(
					'when'    => array( 'captcha_enabled' ),
					'feature' => __( 'CAPTCHA on login', 'ux-studio' ),
				)
			),
			$full(
				'security-optimization',
				array(
					'disable-xml-rpc'     => 'Disable XML-RPC',
					'disable-xml-rpc-api' => 'Disable XML-RPC-API',
				),
				array(
					'when'    => array( 'disable_xmlrpc' ),
					'feature' => __( 'Disable XML-RPC', 'ux-studio' ),
				)
			),
			// WP Extended is a whole suite of tiny modules. The legacy ux1 plugin
			// is deliberately absent: Ux1Lock already deals with it.
			$full( self::ANY_MODULE, array( 'wpextended' => 'WP Extended' ) ),

			/* Admin Customiser: partial, but migratable later (confirmation needed to enable). */
			$part(
				'admin-customiser',
				array(
					'admin-menu-editor' => 'Admin Menu Editor',
					'adminimize'        => 'Adminimize',
					'white-label-cms'   => 'White Label CMS',
					'adminify'          => 'Adminify',
					'loginpress'        => 'LoginPress',
				),
				array( 'confirm' => true )
			),

			/* Group B: partial, information only. */
			$part(
				'security-optimization',
				array(
					'wordfence'                            => 'Wordfence Security',
					'all-in-one-wp-security-and-firewall'  => 'All-In-One Security',
					'better-wp-security'                   => 'Solid Security',
					'sucuri-scanner'                       => 'Sucuri Security',
				)
			),
			$part(
				'security-optimization',
				array(
					'really-simple-ssl'                  => 'Really Simple SSL',
					'headers-security-advanced-hsts-wp'  => 'HTTP Headers',
				),
				array(
					'when'    => array( 'force_https', 'security_headers', 'enable_hsts' ),
					'feature' => __( 'HTTPS & security headers', 'ux-studio' ),
				)
			),
			$part(
				'image-optimizer',
				array(
					'ewww-image-optimizer'        => 'EWWW Image Optimizer',
					'wp-smushit'                  => 'Smush',
					'shortpixel-image-optimiser'  => 'ShortPixel Image Optimizer',
					'imagify'                     => 'Imagify',
					'webp-express'                => 'WebP Express',
				)
			),
			$part(
				'pixel-tag-manager',
				array(
					'gtm-kit'                          => 'GTM Kit',
					'duracelltomi-google-tag-manager'  => 'GTM4WP',
					'pixelyoursite'                    => 'PixelYourSite',
					'official-facebook-pixel'          => 'Meta Pixel for WordPress',
				)
			),
			$part(
				'forms',
				array(
					'contact-form-7' => 'Contact Form 7',
					'wpforms-lite'   => 'WPForms',
					'fluentform'     => 'Fluent Forms',
					'ninja-forms'    => 'Ninja Forms',
					'formidable'     => 'Formidable Forms',
				)
			),
			$part(
				'popup-manager',
				array(
					'popup-maker'  => 'Popup Maker',
					'optinmonster' => 'OptinMonster',
				)
			),
			$part(
				'exit-popup',
				array(
					'popup-maker'  => 'Popup Maker',
					'optinmonster' => 'OptinMonster',
				)
			),
			$part( 'instagram-feed', array( 'instagram-feed' => 'Smash Balloon Instagram Feed' ) ),
			$part(
				'review-aggregator',
				array(
					'widget-google-reviews'            => 'Widget for Google Reviews',
					'wp-reviews-plugin-for-google'     => 'Reviews Widget for Google',
				)
			),
			$part( 'google-review-request', array( 'customer-reviews-woocommerce' => 'Customer Reviews for WooCommerce' ) ),
			$part(
				'push-notifications',
				array(
					'onesignal-free-web-push-notifications' => 'OneSignal Push Notifications',
					'pushengage'                            => 'PushEngage',
				)
			),
			$part( 'download-files', array( 'download-monitor' => 'Download Monitor' ) ),
			$part( 'performance-optimization', array( 'wp-optimize' => 'WP-Optimize' ) ),
			$part( 'vulnerability-scanner', array( 'wpscan' => 'WPScan' ) ),
			$part( 'file-manager', array( 'wp-file-manager' => 'File Manager' ) ),
			$part( 'third-party-login', array( 'nextend-facebook-connect' => 'Nextend Social Login' ) ),
		);

		self::$cache = $entries;
		return $entries;
	}

	/**
	 * Folder slug of a plugin basename: `wpforms-lite/wpforms.php` => `wpforms-lite`,
	 * single-file `hello.php` => `hello`.
	 *
	 * @param string $basename Plugin basename as stored in active_plugins.
	 */
	public static function slug_of( string $basename ): string {
		$basename = str_replace( '\\', '/', $basename );
		$slash    = strpos( $basename, '/' );
		if ( false !== $slash ) {
			return substr( $basename, 0, $slash );
		}
		return (string) preg_replace( '/\.php$/', '', $basename );
	}
}
