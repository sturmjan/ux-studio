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
 *  - `foreign_when`  : optional list of conditions on the FOREIGN plugin's own options or files (see
 *                      Overlaps::foreign_condition_met()). The entry keeps its type only while at least one
 *                      holds, that is while the foreign feature is really ON. Otherwise it is downgraded to
 *                      partial (information only) or, with `foreign_else` => `none`, not reported at all.
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
			$full(
				'security-optimization',
				array( 'disable-feeds' => 'Disable Feeds' ),
				array(
					'when'    => array( 'disable_rss_feeds' ),
					'feature' => __( 'Disable RSS feeds', 'ux-studio' ),
				)
			),
			$full(
				'security-optimization',
				array( 'stop-user-enumeration' => 'Stop User Enumeration' ),
				array(
					'when'    => array( 'block_user_enumeration' ),
					'feature' => __( 'Block user enumeration', 'ux-studio' ),
				)
			),
			// Switching off Application Passwords is only half of "Harden the login page": information only.
			$part(
				'security-optimization',
				array( 'disable-application-passwords' => 'Disable Application Passwords' ),
				array(
					'when'    => array( 'protect_login' ),
					'feature' => __( 'Disable Application Passwords', 'ux-studio' ),
				)
			),
			$full(
				'image-optimizer',
				array(
					'ewww-image-optimizer'       => 'EWWW Image Optimizer',
					'ewww-image-optimizer-cloud' => 'EWWW Image Optimizer Cloud',
					'wp-smushit'                 => 'Smush',
					'wp-smush-pro'               => 'Smush Pro',
					'shortpixel-image-optimiser' => 'ShortPixel Image Optimizer',
					'imagify'                    => 'Imagify',
					'webp-converter-for-media'   => 'Converter for Media',
					'optimole-wp'                => 'Optimole',
				)
			),
			// Only while the foreign plugin really optimises images / serves WebP; otherwise not reported.
			$full(
				'image-optimizer',
				array( 'litespeed-cache' => 'LiteSpeed Cache' ),
				array(
					'foreign_when' => array(
						array( 'option' => 'litespeed.conf.img_optm-auto' ),
						array( 'option' => 'litespeed.conf.img_optm-webp' ),
					),
					'foreign_else' => 'none',
					'feature'      => __( 'Image optimization', 'ux-studio' ),
				)
			),
			$full(
				'image-optimizer',
				array( 'jetpack' => 'Jetpack' ),
				array(
					'foreign_when' => array(
						array(
							'option'   => 'jetpack_active_modules',
							'contains' => array( 'photon', 'image-cdn' ),
						),
					),
					'foreign_else' => 'none',
					'feature'      => __( 'Image CDN', 'ux-studio' ),
				)
			),
			// llms.txt: blocking only while the SEO plugin really serves its own llms.txt (its option is on, or the
			// file it writes into the site root exists); otherwise just a note that both exist.
			$full(
				'ai-markdown',
				array( 'wordpress-seo' => 'Yoast SEO', 'wordpress-seo-premium' => 'Yoast SEO Premium' ),
				array(
					'foreign_when' => array(
						array( 'option' => 'wpseo', 'key' => 'enable_llms_txt' ),
						array( 'file' => 'llms.txt' ),
					),
					'foreign_else' => 'partial',
					'feature'      => __( 'llms.txt', 'ux-studio' ),
				)
			),
			$full(
				'ai-markdown',
				array( 'seo-by-rank-math' => 'Rank Math SEO', 'seo-by-rank-math-pro' => 'Rank Math SEO Pro' ),
				array(
					'foreign_when' => array(
						array( 'option' => 'rank_math_modules', 'contains' => array( 'llms-txt', 'llms_txt', 'llms' ) ),
						array( 'file' => 'llms.txt' ),
					),
					'foreign_else' => 'partial',
					'feature'      => __( 'llms.txt', 'ux-studio' ),
				)
			),
			$full(
				'ai-markdown',
				array( 'all-in-one-seo-pack' => 'All in One SEO', 'all-in-one-seo-pack-pro' => 'All in One SEO Pro' ),
				array(
					'foreign_when' => array( array( 'file' => 'llms.txt' ) ),
					'foreign_else' => 'partial',
					'feature'      => __( 'llms.txt', 'ux-studio' ),
				)
			),
			// Security: the plugin silences the same update e-mails (information only, never blocks).
			$part(
				'security-optimization',
				array(
					'disable-auto-update-email-notifications' => 'Disable Auto-Update Email Notifications',
					'manage-notification-emails'              => 'Manage Notification E-mails',
				),
				array(
					'when'    => array( 'suppress_update_emails' ),
					'feature' => __( 'Silence update e-mails', 'ux-studio' ),
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
					'admin-menu-editor-pro' => 'Admin Menu Editor Pro',
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
			$part( 'image-optimizer', array( 'webp-express' => 'WebP Express' ) ),
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
	 * Catalog entry that lists a plugin slug (first match). The migration sources
	 * use it to take type (full / partial), target module and display name from
	 * one place.
	 *
	 * @param string $slug Folder slug.
	 * @return array<string, mixed>|null
	 */
	public static function entry_for_slug( string $slug ): ?array {
		foreach ( self::entries() as $entry ) {
			if ( isset( $entry['plugins'][ $slug ] ) ) {
				return $entry;
			}
		}
		return null;
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
