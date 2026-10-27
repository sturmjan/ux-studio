<?php
/**
 * Definition of the onboarding "starter pack": modules and settings suitable for every WordPress site.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Onboarding;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data and pure decisions (PLAN.md 29), tested without WordPress. Everything that has to look at the live
 * site goes through the `$env` array (see Environment::detect()), so the same function answers in the tests, on
 * the GET route and when the pack is applied:
 *
 *   woocommerce          bool    WooCommerce is active (product reviews must keep working),
 *   cloudflare           bool    the request came through Cloudflare (CF-Connecting-IP present),
 *   xff                  bool    X-Forwarded-For present,
 *   server               string  apache | litespeed | nginx | iis | other,
 *   htaccess_writable    bool    the .htaccess file (or its folder) can be written,
 *   pretty_permalinks    bool    the permalink structure gives the login slug a route,
 *   login_slug_collides  bool    the wanted slug is a page, post, term or other rewrite rule,
 *   login_url_in_use     bool    the custom login URL is already switched on or has a slug,
 *   site_name            string  site name for the admin footer,
 *   login_logo_id        int     attachment id of the site logo or site icon, 0 when there is none,
 *   login_settings       array   current login page settings of Admin Customiser (key => value), for login_already_customised(),
 *   roles                string[] role slugs that exist,
 *   bot_blacklist        string  current Bot Throttle blacklist (one User-Agent per line),
 *   bot_whitelist        string  current Bot Throttle whitelist,
 *   blocked_settings     array   Security setting key => names of the foreign plugins that already do that job,
 *   csp_mode             string  current Content Security Policy mode of the Security module (off | audit | report-only | enforce),
 *   reserved_users       string[] logins of existing users that match the Security module's reserved username list,
 *   app_passwords        array   {users, passwords}: how many users hold Application Passwords and how many passwords,
 *   published_posts      int     published posts of type post (a site with a blog may have RSS subscribers),
 *   feed_plugins         string[] names of active plugins that consume or serve RSS feeds (see FEED_PLUGINS),
 *   headless_plugins     string[] names of active decoupled front end plugins (see HEADLESS_PLUGINS),
 *   header_plugins       string[] names of active plugins that send security headers (see HEADER_PLUGINS),
 *   https_ready          bool    the site and home address are https and this very request came over https,
 *   captcha_set          bool    a CAPTCHA is already set up: switched on, or keys stored (Turnstile, reCAPTCHA).
 *
 * An item is one tick box in the wizard:
 *
 *   id, title, description, optional (not in the safe core), checked (default), available + unavailable (reason code),
 *   modules (ids, in the order they are switched on), settings (module id => key => value, exactly what is saved),
 *   warnings (list of {code, message}).
 *
 * The custom login URL is always the LAST item, and only ever applied in the pending state (see LoginUrlPending).
 */
final class StarterPack {

	/** Option: unix time the pack was applied (the Modules screen hint stops offering "Set up new site"). */
	public const APPLIED_OPTION = 'uxstudio_starter_applied';

	/** Option: how everything was before the pack was applied (for the revert). */
	public const SNAPSHOT_OPTION = 'uxstudio_starter_snapshot';

	/** Slug of the custom login address. */
	public const LOGIN_SLUG = 'administrace';

	/** Id of the item that is applied last, in two steps. */
	public const LOGIN_ITEM = 'login-url';

	/** Plugins that need the RSS feeds (folder slug => name). Active one of them keeps the feeds switched on. */
	public const FEED_PLUGINS = array(
		'mailchimp-for-wp'            => 'Mailchimp for WP',
		'seriously-simple-podcasting' => 'Seriously Simple Podcasting',
		'powerpress'                  => 'PowerPress',
		'feedzy-rss-feeds'            => 'Feedzy RSS Feeds',
		'feedwordpress'               => 'FeedWordPress',
		'wp-rss-aggregator'           => 'WP RSS Aggregator',
	);

	/** Decoupled front ends read the public REST users route (folder slug => name). */
	public const HEADLESS_PLUGINS = array(
		'wp-graphql' => 'WPGraphQL',
		'faustwp'    => 'Faust.js',
		'wp-gatsby'  => 'WPGatsby',
	);

	/** Setting keys that are written into .htaccess by the Security module. */
	public const HTACCESS_KEYS = array( 'security_headers', 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files', 'force_https' );

	/** Plugins that send their own security headers (folder slug => name). They keep the Permissions-Policy. */
	public const HEADER_PLUGINS = array(
		'really-simple-ssl'                 => 'Really Simple SSL',
		'headers-security-advanced-hsts-wp' => 'HTTP Headers',
	);

	/** A site with more published posts than this has a blog: readers subscribe to its feed and author pages are indexed. */
	public const BLOG_POSTS_LIMIT = 5;

	/** SEO tool crawlers that must NOT be on the Bot Throttle blacklist (agencies audit with them). */
	private const SEO_ALLOWED_BOTS = array( 'AhrefsBot', 'SemrushBot' );

	/**
	 * All item ids, in the order they are applied (the login URL last).
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array(
			'security-hardening',
			'update-emails',
			'captcha-shield',
			'hide-wordpress',
			'classic',
			'clean-profiles',
			'dashboard-widgets',
			'duplicate',
			'external-permalinks',
			'ai-markdown',
			'bot-throttle',
			'image-optimizer',
			'media-trash',
			'stock-photos',
			'vulnerability-scanner',
			'activity-log',
			'indexing-notice',
			'smtp-email',
			'email-log',
			'redirects',
			'media-replace',
			'user-last-login',
			'hide-admin-bar',
			'csp-audit',
			self::LOGIN_ITEM,
		);
	}

	/**
	 * Neutral environment (a plain Apache site, nothing special).
	 *
	 * @return array<string, mixed>
	 */
	public static function default_env(): array {
		return array(
			'woocommerce'         => false,
			'cloudflare'          => false,
			'xff'                 => false,
			'server'              => 'apache',
			'htaccess_writable'   => true,
			'pretty_permalinks'   => true,
			'login_slug_collides' => false,
			'login_url_in_use'    => false,
			'site_name'           => '',
			'login_logo_id'       => 0,
			'login_settings'      => array(),
			'roles'               => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'bot_blacklist'       => "AhrefsBot\nSemrushBot\nMJ12bot\nDotBot\nPetalBot\nBLEXBot\nMegaIndex\nSerpstatBot\nrogerbot\nDataForSeoBot",
			'bot_whitelist'       => '',
			'blocked_settings'    => array(),
			'csp_mode'            => 'off',
			'reserved_users'      => array(),
			'app_passwords'       => array(
				'users'     => 0,
				'passwords' => 0,
			),
			'published_posts'     => 0,
			'feed_plugins'        => array(),
			'headless_plugins'    => array(),
			'header_plugins'      => array(),
			'https_ready'         => true,
			'captcha_set'         => false,
		);
	}

	/**
	 * Every item for this environment, in application order.
	 *
	 * @param array<string, mixed> $env Environment (missing keys fall back to default_env()).
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( array $env ): array {
		$env   = array_merge( self::default_env(), $env );
		$items = array(
			self::security_hardening( $env ),
			self::update_emails( $env ),
			self::captcha_shield( $env ),
			self::hide_wordpress( $env ),
			self::simple(
				'classic',
				__( 'Classic editor and widgets', 'ux-studio' ),
				__( 'The familiar post editor and widget screen instead of blocks. Editors can still switch to the block editor per post.', 'ux-studio' ),
				array( 'classic-editor', 'classic-widgets' ),
				array(
					'classic-editor' => array(
						'default_editor' => 'classic',
						'allow_users'    => true,
					),
				)
			),
			self::simple(
				'clean-profiles',
				__( 'Tidy user profiles', 'ux-studio' ),
				__( 'Hides the admin color scheme, keyboard shortcuts, biographical info and website fields on the profile screen.', 'ux-studio' ),
				array( 'clean-profiles' ),
				array(
					'clean-profiles' => array(
						'fields' => array( 'user-admin-color-wrap', 'user-comment-shortcuts-wrap', 'user-description-wrap', 'user-url-wrap' ),
					),
				)
			),
			self::dashboard_widgets(),
			self::simple(
				'duplicate',
				__( 'Duplicate posts, pages and menus', 'ux-studio' ),
				__( 'A "Duplicate" link next to every post and page (the copy is a draft) and a Duplicate button for menus.', 'ux-studio' ),
				array( 'duplicate-post', 'duplicate-menu' ),
				array(
					'duplicate-post' => array( 'post_status' => 'draft' ),
				)
			),
			self::simple(
				'external-permalinks',
				__( 'Links to external addresses', 'ux-studio' ),
				__( 'Lets a page or post point to another address, for example from a menu or a list of posts.', 'ux-studio' ),
				array( 'external-permalinks' )
			),
			self::simple(
				'ai-markdown',
				__( 'AI readable site (llms.txt and Markdown)', 'ux-studio' ),
				__( 'Publishes llms.txt and a Markdown version of the content so AI assistants understand the site.', 'ux-studio' ),
				array( 'ai-markdown' )
			),
			self::bot_throttle( $env ),
			self::simple(
				'image-optimizer',
				__( 'Image optimization', 'ux-studio' ),
				__( 'Compresses images and creates WebP copies. Nothing is changed on existing files until you run it.', 'ux-studio' ),
				array( 'image-optimizer' ),
				array(
					'image-optimizer' => array(
						'quality'           => 82,
						'convert_to_webp'   => true,
						'htaccess_delivery' => false,
					),
				)
			),
			self::simple(
				'media-trash',
				__( 'Media trash', 'ux-studio' ),
				__( 'Deleted files go to a trash first, so a mistake can be undone.', 'ux-studio' ),
				array( 'media-trash' )
			),
			self::simple(
				'stock-photos',
				__( 'Free stock photos', 'ux-studio' ),
				__( 'Search free photos from the media library. Openverse works without a key; other providers need their own API key.', 'ux-studio' ),
				array( 'stock-photos' )
			),
			self::simple(
				'vulnerability-scanner',
				__( 'Vulnerability scanner', 'ux-studio' ),
				__( 'Checks installed plugins, themes and WordPress against known vulnerabilities once a day.', 'ux-studio' ),
				array( 'vulnerability-scanner' )
			),
			self::simple(
				'activity-log',
				__( 'Activity log (90 days)', 'ux-studio' ),
				__( 'Records who changed what in the admin and keeps the records for 90 days.', 'ux-studio' ),
				array( 'activity-log' ),
				array(
					'activity-log' => array( 'retention_days' => 90 ),
				)
			),
			self::simple(
				'indexing-notice',
				__( 'Search engine visibility notice', 'ux-studio' ),
				__( 'Warns in the admin when the site is hidden from search engines, so a launch is not forgotten.', 'ux-studio' ),
				array( 'indexing-notice' )
			),
			self::optional(
				'smtp-email',
				__( 'E-mail sending through SMTP', 'ux-studio' ),
				__( 'Sends the site e-mail through your mail server. Needs the server and login details, so it is only switched on here and you fill them in afterwards.', 'ux-studio' ),
				array( 'smtp-email' ),
				array(),
				array( array( 'smtp_credentials', __( 'Fill in the SMTP server and login in the module settings, otherwise e-mail keeps going through the default WordPress way.', 'ux-studio' ) ) )
			),
			self::simple(
				'email-log',
				__( 'E-mail log (14 days)', 'ux-studio' ),
				__( 'Keeps a record of the e-mails the site sends for 14 days.', 'ux-studio' ),
				array( 'email-log' ),
				array( 'email-log' => array( 'retention_days' => 14 ) )
			),
			self::optional(
				'redirects',
				__( 'Redirects and 404 log', 'ux-studio' ),
				__( 'Manage redirects and see which missing addresses visitors ask for.', 'ux-studio' ),
				array( 'redirects' ),
				array( 'redirects' => array( 'log_404' => true ) )
			),
			self::optional(
				'media-replace',
				__( 'Replace media files', 'ux-studio' ),
				__( 'Replace a file in the media library without changing its address.', 'ux-studio' ),
				array( 'media-replace' )
			),
			self::simple(
				'user-last-login',
				__( 'Last login of users', 'ux-studio' ),
				__( 'Shows when each user last logged in.', 'ux-studio' ),
				array( 'user-last-login' )
			),
			self::hide_admin_bar( $env ),
			self::csp_audit( $env ),
			self::login_url( $env ),
		);
		return $items;
	}

	/**
	 * Item by id.
	 *
	 * @param string               $id  Item id.
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>|null
	 */
	public static function item( string $id, array $env ): ?array {
		foreach ( self::items( $env ) as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Keep only ids from the whitelist, in application order (the login URL last), without duplicates.
	 *
	 * @param mixed $ids Requested ids.
	 * @return string[]
	 */
	public static function sanitize_ids( $ids ): array {
		$ids = is_array( $ids ) ? array_map( 'strval', $ids ) : array();
		return array_values( array_intersect( self::ids(), $ids ) );
	}

	/**
	 * Apply the administrator's overrides to an item: only keys the item's preset already contains, and only
	 * scalar or flat list values. The result is still cleaned against the module's schema when it is saved.
	 *
	 * @param array<string, mixed> $item      Item from items().
	 * @param mixed                $overrides module id => key => value.
	 * @return array<string, mixed>
	 */
	public static function apply_overrides( array $item, $overrides ): array {
		if ( ! is_array( $overrides ) ) {
			return $item;
		}
		foreach ( (array) $item['settings'] as $module => $values ) {
			$given = $overrides[ $module ] ?? null;
			if ( ! is_array( $given ) ) {
				continue;
			}
			foreach ( array_keys( $values ) as $key ) {
				if ( ! array_key_exists( $key, $given ) ) {
					continue;
				}
				$value = $given[ $key ];
				if ( is_array( $value ) ) {
					$value = array_values( array_filter( $value, 'is_scalar' ) );
				} elseif ( ! is_scalar( $value ) ) {
					continue;
				}
				$item['settings'][ $module ][ $key ] = $value;
			}
		}
		return $item;
	}

	/* ── Pure decisions ── */

	/**
	 * Bot Throttle blacklist without the SEO tool crawlers of Ahrefs and Semrush. Other crawlers on the list
	 * (Majestic, Moz, Serpstat, Petal and so on) stay, and search engines are never on it.
	 *
	 * @param string $current Current blacklist (one User-Agent fragment per line or comma separated).
	 */
	public static function bot_blacklist( string $current ): string {
		$lines = preg_split( '/\r\n|\r|\n|,/', $current ) ?: array();
		$keep  = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || self::is_allowed_seo_bot( $line ) ) {
				continue;
			}
			$keep[] = $line;
		}
		return implode( "\n", $keep );
	}

	/**
	 * Bot Throttle whitelist with Ahrefs and Semrush added, so their site audits are never slowed down.
	 *
	 * @param string $current Current whitelist.
	 */
	public static function bot_whitelist( string $current ): string {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|,/', $current ) ?: array() ), static fn( $l ) => '' !== $l ) );
		foreach ( self::SEO_ALLOWED_BOTS as $bot ) {
			if ( ! in_array( strtolower( $bot ), array_map( 'strtolower', $lines ), true ) ) {
				$lines[] = $bot;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Names removed from a blacklist by bot_blacklist() (for the report).
	 *
	 * @param string $current Current blacklist.
	 * @return string[]
	 */
	public static function bots_removed( string $current ): array {
		$removed = array();
		foreach ( preg_split( '/\r\n|\r|\n|,/', $current ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line && self::is_allowed_seo_bot( $line ) ) {
				$removed[] = $line;
			}
		}
		return $removed;
	}

	/**
	 * Is this blacklist line an Ahrefs or Semrush crawler?
	 *
	 * @param string $line Blacklist line.
	 */
	private static function is_allowed_seo_bot( string $line ): bool {
		$line = strtolower( $line );
		return false !== strpos( $line, 'ahrefs' ) || false !== strpos( $line, 'semrush' );
	}

	/**
	 * Server family from `$_SERVER['SERVER_SOFTWARE']`.
	 *
	 * @param string $software Raw value.
	 * @return string apache | litespeed | nginx | iis | other
	 */
	public static function server_family( string $software ): string {
		$software = strtolower( $software );
		if ( false !== strpos( $software, 'litespeed' ) ) {
			return 'litespeed';
		}
		if ( false !== strpos( $software, 'apache' ) ) {
			return 'apache';
		}
		if ( false !== strpos( $software, 'nginx' ) ) {
			return 'nginx';
		}
		if ( false !== strpos( $software, 'iis' ) ) {
			return 'iis';
		}
		return 'other';
	}

	/* ── Items ── */

	/**
	 * Build a plain item.
	 *
	 * @param string                              $id          Id.
	 * @param string                              $title       Title.
	 * @param string                              $description Description.
	 * @param string[]                            $modules     Module ids.
	 * @param array<string, array<string, mixed>> $settings    Preset.
	 * @param array<int, array{0:string,1:string,2?:array<string, mixed>}> $warnings Code, message and optional machine data.
	 * @param bool                                $optional    Not in the pre-checked core.
	 * @return array<string, mixed>
	 */
	private static function build( string $id, string $title, string $description, array $modules, array $settings, array $warnings, bool $optional ): array {
		$rows = array();
		foreach ( $warnings as $w ) {
			$row = array(
				'code'    => $w[0],
				'message' => $w[1],
			);
			if ( isset( $w[2] ) ) {
				// Machine readable details (the wizard words them from the module schema).
				$row['data'] = $w[2];
			}
			$rows[] = $row;
		}
		return array(
			'id'          => $id,
			'title'       => $title,
			'description' => $description,
			'optional'    => $optional,
			'checked'     => ! $optional,
			'available'   => true,
			'unavailable' => '',
			'modules'     => $modules,
			'settings'    => $settings,
			'warnings'    => $rows,
		);
	}

	/**
	 * Pre-checked item.
	 *
	 * @param string                              $id          Id.
	 * @param string                              $title       Title.
	 * @param string                              $description Description.
	 * @param string[]                            $modules     Module ids.
	 * @param array<string, array<string, mixed>> $settings    Preset.
	 * @param array<int, array{0:string,1:string}> $warnings    Warnings.
	 * @return array<string, mixed>
	 */
	private static function simple( string $id, string $title, string $description, array $modules, array $settings = array(), array $warnings = array() ): array {
		return self::build( $id, $title, $description, $modules, $settings, $warnings, false );
	}

	/**
	 * Unchecked item.
	 *
	 * @param string                              $id          Id.
	 * @param string                              $title       Title.
	 * @param string                              $description Description.
	 * @param string[]                            $modules     Module ids.
	 * @param array<string, array<string, mixed>> $settings    Preset.
	 * @param array<int, array{0:string,1:string}> $warnings    Warnings.
	 * @return array<string, mixed>
	 */
	private static function optional( string $id, string $title, string $description, array $modules, array $settings = array(), array $warnings = array() ): array {
		return self::build( $id, $title, $description, $modules, $settings, $warnings, true );
	}

	/**
	 * Mark an item as impossible on this site (it stays in the list, unchecked, with the reason).
	 *
	 * @param array<string, mixed> $item   Item.
	 * @param string               $reason Reason code.
	 * @return array<string, mixed>
	 */
	private static function unavailable( array $item, string $reason ): array {
		$item['available']   = false;
		$item['unavailable'] = $reason;
		$item['checked']     = false;
		return $item;
	}

	/**
	 * Security: hardening, cleanup, login rate limit, comments.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function security_hardening( array $env ): array {
		$set      = array(
			'disable_comments'             => true,
			'disable_file_editing'         => true,
			'disable_xmlrpc'               => true,
			'hide_wp_version'              => true,
			// The PHP fallback sends the headers even when .htaccess cannot be written and on nginx.
			'security_headers'             => true,
			'limit_revisions'              => true,
			'revisions_count'              => 10,
			'heartbeat_control'            => 'slow',
			'disable_emojis'               => true,
			'remove_unused_default_themes' => true,
			// Login hardening: the conditions that make these unsafe on a particular site are checked below.
			'block_usernames'              => true,
			'block_username_attempts'      => true,
			'protect_login'                => true,
			'disable_rss_feeds'            => true,
			'protect_wp_config'            => true,
			'disable_directory_listing'    => true,
			'block_sensitive_files'        => true,
			'block_user_enumeration'       => true,
			'hide_author_slugs'            => true,
			'enable_permissions_policy'    => true,
			'force_https'                  => true,
		);
		$warnings = array();

		if ( ! empty( $env['woocommerce'] ) ) {
			// Product reviews are comments: switch comments off for posts and pages only.
			$set['disable_comments_scope']      = 'selected';
			$set['disable_comments_post_types'] = array( 'post', 'page' );
			$warnings[]                         = array( 'woo_comments', __( 'WooCommerce is active: comments are disabled on posts and pages only, so product reviews keep working.', 'ux-studio' ) );
		}

		if ( ! empty( $env['cloudflare'] ) ) {
			$set['ip_firewall_proxy'] = 'cloudflare';
		}
		if ( ! empty( $env['xff'] ) && empty( $env['cloudflare'] ) ) {
			// Behind an unknown proxy every visitor looks like the proxy, so a lockout would lock everybody out.
			$warnings[] = array( 'proxy_xff', __( 'The site is behind a reverse proxy. Login attempt limiting is left off: first set the client IP source (Security > Firewall), then switch it on.', 'ux-studio' ), array( 'keys' => array( 'limit_login_attempts' ) ) );
		} else {
			$set['limit_login_attempts'] = true;
			$set['max_attempts']         = 5;
			$set['lockout_time']         = 15;
			$set['lockout_progressive']  = true;
		}

		if ( 'apache' === $env['server'] || 'litespeed' === $env['server'] ) {
			if ( empty( $env['htaccess_writable'] ) ) {
				$warnings[] = array( 'htaccess_not_writable', __( 'The .htaccess file cannot be written, so the security headers are sent by PHP only.', 'ux-studio' ) );
			}
		}

		// Application Passwords are used by integrations (mobile apps, REST clients), so never cut off ones that exist.
		$app_users = (int) ( $env['app_passwords']['users'] ?? 0 );
		$app_total = (int) ( $env['app_passwords']['passwords'] ?? 0 );
		if ( $app_total > 0 ) {
			unset( $set['protect_login'] );
			$warnings[] = array(
				'app_passwords_in_use',
				sprintf(
					/* translators: 1: number of users, 2: number of passwords. */
					__( 'Left out: Harden the login page. It would switch off Application Passwords, which are in use (passwords: %2$d, users: %1$d): integrations such as the WordPress mobile app or REST clients rely on them. Switch it on in Security once they are not needed.', 'ux-studio' ),
					$app_users,
					$app_total
				), array( 'keys' => array( 'protect_login' ) ) );
		}

		// An RSS feed can have subscribers: leave it alone on a site that looks like a blog or uses a feed plugin.
		$feed_plugins = array_values( array_map( 'strval', (array) $env['feed_plugins'] ) );
		if ( $feed_plugins ) {
			unset( $set['disable_rss_feeds'] );
			$warnings[] = array(
				'feeds_plugin',
				sprintf(
					/* translators: %s: plugin names. */
					__( 'Left out: Disable RSS feeds, because %s needs the feeds.', 'ux-studio' ),
					implode( ', ', $feed_plugins )
				),
				array( 'keys' => array( 'disable_rss_feeds' ) ),
			);
		} elseif ( (int) $env['published_posts'] > self::BLOG_POSTS_LIMIT ) {
			unset( $set['disable_rss_feeds'] );
			$warnings[] = array(
				'feeds_blog',
				sprintf(
					/* translators: %d: number of published posts. */
					__( 'Left out: Disable RSS feeds. The site has %d published posts, so it may have readers who subscribe. Switch it on in Security if nobody does.', 'ux-studio' ),
					(int) $env['published_posts']
				), array( 'keys' => array( 'disable_rss_feeds' ) ) );
		}

		// Rules that live in .htaccess: only where Apache reads it and it can be written.
		$htaccess_rules = array( 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files', 'force_https' );
		if ( 'apache' !== $env['server'] && 'litespeed' !== $env['server'] ) {
			$set        = array_diff_key( $set, array_flip( $htaccess_rules ) );
			$warnings[] = array( 'htaccess_rules_unsupported', __( 'Left out: Protect wp-config.php, Disable directory listing, Block readme.html / license.txt and Force HTTPS. Rules in .htaccess do not work on this server: block wp-config.php, readme.html and license.txt and the directory listing in the server configuration instead.', 'ux-studio' ), array( 'keys' => array( 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files', 'force_https' ) ) );
		} elseif ( empty( $env['htaccess_writable'] ) ) {
			$set        = array_diff_key( $set, array_flip( $htaccess_rules ) );
			$warnings[] = array( 'htaccess_rules_unwritable', __( 'Left out: Protect wp-config.php, Disable directory listing, Block readme.html / license.txt and Force HTTPS, because the .htaccess file cannot be written. Make it writable and switch them on in Security.', 'ux-studio' ), array( 'keys' => array( 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files', 'force_https' ) ) );
		}

		// Permissions-Policy turns payment off, which would break Apple Pay, Google Pay and the Payment Request API.
		$header_plugins = array_values( array_map( 'strval', (array) $env['header_plugins'] ) );
		if ( ! empty( $env['woocommerce'] ) ) {
			unset( $set['enable_permissions_policy'] );
			$warnings[] = array( 'permissions_policy_woo', __( 'Left out: Permissions-Policy. WooCommerce is active and the header would switch off the payment API that Apple Pay and Google Pay use.', 'ux-studio' ), array( 'keys' => array( 'enable_permissions_policy' ) ) );
		} elseif ( $header_plugins ) {
			unset( $set['enable_permissions_policy'] );
			$warnings[] = array(
				'permissions_policy_plugin',
				sprintf(
					/* translators: %s: plugin names. */
					__( 'Left out: Permissions-Policy, because %s already sends security headers.', 'ux-studio' ),
					implode( ', ', $header_plugins )
				),
				array( 'keys' => array( 'enable_permissions_policy' ) ),
			);
		}

		// Forced HTTPS is only safe when the site already works on https and no unknown proxy sits in front of it.
		if ( empty( $env['https_ready'] ) ) {
			unset( $set['force_https'] );
			$warnings[] = array( 'https_not_ready', __( 'Left out: Force HTTPS. The site address does not start with https:// or this page was not opened over https, so a redirect to https could lock you out.', 'ux-studio' ), array( 'keys' => array( 'force_https' ) ) );
		} elseif ( ! empty( $env['xff'] ) && empty( $env['cloudflare'] ) ) {
			unset( $set['force_https'] );
			$warnings[] = array( 'https_proxy', __( 'Left out: Force HTTPS. The site is behind a reverse proxy, which often talks to the server over plain http and would make an endless redirect. Switch it on in Security once you know how the proxy connects.', 'ux-studio' ), array( 'keys' => array( 'force_https' ) ) );
		}

		// Public REST users are read by a decoupled front end: do not take them away.
		$headless = array_values( array_map( 'strval', (array) $env['headless_plugins'] ) );
		if ( $headless ) {
			unset( $set['block_user_enumeration'] );
			$warnings[] = array(
				'enumeration_headless',
				sprintf(
					/* translators: %s: plugin names. */
					__( 'Left out: Block user enumeration, because %s reads the public list of users over the REST API.', 'ux-studio' ),
					implode( ', ', $headless )
				),
				array( 'keys' => array( 'block_user_enumeration' ) ),
			);
		}

		// New author URLs would break the ones that are already indexed or linked: only on a site without content.
		if ( (int) $env['published_posts'] > self::BLOG_POSTS_LIMIT ) {
			unset( $set['hide_author_slugs'] );
			$warnings[] = array(
				'author_slugs_blog',
				sprintf(
					/* translators: %d: number of published posts. */
					__( 'Left out: Hide author slugs. The site has %d published posts, so its author pages may already be indexed or linked, and the old addresses would stop working. Switch it on in Security if you accept that.', 'ux-studio' ),
					(int) $env['published_posts']
				), array( 'keys' => array( 'hide_author_slugs' ) ) );
		}

		// A foreign plugin already does one of these jobs: leave that setting out, never create the overlap.
		foreach ( (array) $env['blocked_settings'] as $key => $plugins ) {
			if ( ! array_key_exists( $key, $set ) ) {
				continue;
			}
			unset( $set[ $key ] );
			if ( 'limit_login_attempts' === $key ) {
				unset( $set['max_attempts'], $set['lockout_time'], $set['lockout_progressive'] );
			}
			if ( 'disable_comments' === $key ) {
				unset( $set['disable_comments_scope'], $set['disable_comments_post_types'] );
			}
			$warnings[] = array(
				'blocked_feature',
				sprintf(
					/* translators: 1: setting key, 2: plugin names. */
					__( 'Left out: %1$s, because %2$s already does it.', 'ux-studio' ),
					$key,
					implode( ', ', array_map( 'strval', (array) $plugins ) )
				),
				array(
					'key'     => (string) $key,
					'plugins' => array_values( array_map( 'strval', (array) $plugins ) ),
				),
			);
		}

		// Blocking a reserved username at once only makes sense with the login limiter, which does the locking.
		if ( empty( $set['limit_login_attempts'] ) && ( isset( $set['block_usernames'] ) || isset( $set['block_username_attempts'] ) ) ) {
			unset( $set['block_usernames'], $set['block_username_attempts'] );
			$warnings[] = array( 'username_block_needs_limit', __( 'Left out: Block reserved usernames and Lock out IPs that try a blocked username, because they need the login attempt limit, which is not switched on here.', 'ux-studio' ), array( 'keys' => array( 'block_usernames', 'block_username_attempts' ) ) );
		}

		// A failed login with a reserved name locks the IP at once, also for an existing account with such a name.
		$reserved = array_values( array_map( 'strval', (array) $env['reserved_users'] ) );
		if ( $reserved && isset( $set['block_username_attempts'] ) ) {
			unset( $set['block_username_attempts'] );
			$warnings[] = array(
				'reserved_user_exists',
				sprintf(
					/* translators: %s: user logins. */
					__( 'The account %s uses a reserved username: rename it, then switch on "Lock out IPs that try a blocked username" in Security. Until then one typo in its password would lock your own IP.', 'ux-studio' ),
					implode( ', ', array_slice( $reserved, 0, 3 ) )
				),
				array( 'keys' => array( 'block_username_attempts' ) ),
			);
		}

		return self::build(
			'security-hardening',
			__( 'Security hardening and cleanup', 'ux-studio' ),
			__( 'Switches off comments, the file editor, XML-RPC and RSS feeds (feed addresses answer 403, the feed links leave the page head), hides the WordPress version, sends security headers and the Permissions-Policy header (camera, microphone, location, payment and USB off), redirects http:// to https:// when the site already runs on https (checked after saving; an endless redirect is taken back), blocks wp-config.php (and its backup copies), readme.html, license.txt and wp-config-sample.php in .htaccess, switches off directory listing, stops ?author=N and the public users list in the REST API (logged-in users keep it; the core users sitemap is not touched), replaces user names in author page addresses with a code (old /author/name/ addresses then give 404), limits login attempts, locks out an IP that tries a reserved username such as admin, hides which part of a failed login was wrong and switches off Application Passwords (new ones cannot be created; an MCP or REST client that needs them: switch off "Harden the login page" in Security > Login), slows down Heartbeat, keeps 10 revisions, removes the emoji script and deletes unused default themes that WordPress itself brought (Twenty*, never a manually installed one, the newest one stays as the fallback). Left off on purpose, switch them on yourself when you are sure: the canonical URL redirect and HSTS (a browser remembers HSTS for a year). Open "What will be set" for what each option does, what can go wrong and when to switch it off.', 'ux-studio' ),
			array( 'security-optimization' ),
			array( 'security-optimization' => $set ),
			$warnings,
			false
		);
	}

	/**
	 * Forms that the pack protects with the key-less Shield.
	 *
	 * Comments only when the pack leaves them on: with WooCommerce they stay on products (reviews), and a plugin that
	 * already disables comments keeps the site's own decision; on any other site the security item switches comments off
	 * for good, so a widget on a comment form that is never shown would only be noise.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return string[]
	 */
	public static function captcha_forms( array $env ): array {
		$env      = array_merge( self::default_env(), $env );
		$forms    = array( 'login', 'register', 'lostpassword' );
		$disabled = empty( $env['woocommerce'] ) && ! isset( $env['blocked_settings']['disable_comments'] );
		if ( ! $disabled ) {
			$forms[] = 'comment';
		}
		return $forms;
	}

	/**
	 * Why the key-less CAPTCHA is left out on this site, or '' when it can go on.
	 *
	 *  - captcha_plugin  a CAPTCHA plugin is active (the overlap catalog knows them),
	 *  - captcha_set     a CAPTCHA is already switched on or has keys: it stays as it is,
	 *  - captcha_proxy   behind a reverse proxy that is not Cloudflare: the Shield limits per IP address, and behind such
	 *                    a proxy every visitor looks like the proxy (the same rule as the login attempt limit).
	 *
	 * @param array<string, mixed> $env Environment.
	 */
	public static function captcha_skip_reason( array $env ): string {
		$env = array_merge( self::default_env(), $env );
		if ( ! empty( $env['blocked_settings']['captcha_enabled'] ) ) {
			return 'captcha_plugin';
		}
		if ( ! empty( $env['captcha_set'] ) ) {
			return 'captcha_set';
		}
		if ( ! empty( $env['xff'] ) && empty( $env['cloudflare'] ) ) {
			return 'captcha_proxy';
		}
		return '';
	}

	/**
	 * CAPTCHA without keys: the Shield on login, registration, lost password and comments.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function captcha_shield( array $env ): array {
		$item = self::simple(
			'captcha-shield',
			__( 'CAPTCHA without keys', 'ux-studio' ),
			__( 'Login, registration, lost password and comments ask the browser of the visitor to solve a small puzzle by itself (a fraction of a second, no account, no keys, no cookies, nothing sent to a third party). A visitor without JavaScript answers a simple sum instead, so nobody is locked out. It stops mass scripted attempts; it does not tell a person from a determined script. Keys from the central application or your own Turnstile keys are used instead as soon as they exist.', 'ux-studio' ),
			array( 'security-optimization' ),
			array(
				'security-optimization' => array(
					'captcha_enabled'   => true,
					'captcha_provider'  => 'auto',
					// In the form itself, not a page before it: the typed-question fallback sits right there.
					'captcha_placement' => 'inline',
					'captcha_forms'     => self::captcha_forms( $env ),
				),
			),
			array( array( 'captcha_limits', __( 'The Shield stops mass scripted attempts, not a determined attacker with a computer (that one only needs a little more time). It does not replace the login attempt limit.', 'ux-studio' ) ) )
		);
		$reason = self::captcha_skip_reason( $env );
		return '' === $reason ? $item : self::unavailable( $item, $reason );
	}

	/**
	 * Security: no e-mails about successful automatic updates.
	 *
	 * @param array<string, mixed> $env Environment (unused).
	 * @return array<string, mixed>
	 */
	private static function update_emails( array $env ): array {
		unset( $env );
		return self::simple(
			'update-emails',
			__( 'No e-mails about automatic updates', 'ux-studio' ),
			__( 'Stops the e-mails after successful automatic updates. Updates keep running and e-mails about failed or critical updates still arrive.', 'ux-studio' ),
			array( 'security-optimization' ),
			array( 'security-optimization' => array( 'suppress_update_emails' => true ) )
		);
	}

	/**
	 * Hide the WordPress branding in the admin, on the login page and in the page head.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function hide_wordpress( array $env ): array {
		$admin = array(
			'hide_admin_bar_elements' => array( 'wp_logo' ),
			'hide_help_tab'           => true,
			'hide_footer_version'     => true,
			// Enabling the module also turns the admin quick search on by default; nobody asked for that here.
			'quick_search_enabled'    => false,
		);
		if ( '' !== trim( (string) $env['site_name'] ) ) {
			$admin['admin_footer_text'] = trim( (string) $env['site_name'] );
		}
		$warnings = array();
		$state    = self::login_logo_state( $env );
		if ( 'custom' === $state ) {
			// The login page already has its own design: none of its keys is touched, the logo of the site is not added.
			$warnings[] = array( 'login_customised', self::login_customised_note() );
		} else {
			$admin['login_customization_enabled'] = true;
			if ( 'applied' === $state ) {
				$admin['login_logo'] = (int) $env['login_logo_id'];
				$warnings[]          = array( 'login_logo_used', self::login_logo_used_note() );
			} else {
				// No logo of our own: show none instead of the big WordPress one (LoginExtras::logo_hidden()).
				$admin['form_options'] = array( 'hide_login_logo' );
				$warnings[]            = array( 'no_logo', __( 'The site has no logo or site icon yet, so the login page shows no logo at all. Set a logo in Admin Customiser later and untick "Hide login logo".', 'ux-studio' ) );
			}
		}
		$item                   = self::simple(
			'hide-wordpress',
			__( 'Hide WordPress', 'ux-studio' ),
			__( 'Removes the WordPress logo from the admin bar, replaces the admin footer text with the site name and hides the WordPress version there, hides the Help tab, shows the site logo on the login page (or no logo when the site has none; a login page that already has its own design is left alone) and removes the generator and other WordPress links from the page head.', 'ux-studio' ),
			array( 'admin-customiser', 'security-optimization' ),
			array(
				'admin-customiser'      => $admin,
				'security-optimization' => array(
					'hide_wp_version'    => true,
					'hide_wp_head_links' => true,
				),
			),
			$warnings
		);
		$item['login_logo_state'] = $state;
		return $item;
	}

	/** Shown when the pack leaves a custom login page alone (wizard list and apply result). */
	public static function login_customised_note(): string {
		return __( 'The login page already has its own design, the site logo was not added to it.', 'ux-studio' );
	}

	/** Shown when the pack puts the site logo on a plain login page. */
	public static function login_logo_used_note(): string {
		return __( 'Site logo on the login page: used (Admin Customiser > Login page > Login screen logo). To remove it, clear that logo there or switch off "Enable login screen customization".', 'ux-studio' );
	}

	/**
	 * What the pack does with the login page logo: 'custom' (the page has its own design, nothing is touched),
	 * 'applied' (plain page, the site logo is set) or 'hidden' (plain page, the site has no logo).
	 *
	 * @param array<string, mixed> $env Environment.
	 */
	public static function login_logo_state( array $env ): string {
		if ( self::login_already_customised( (array) ( $env['login_settings'] ?? array() ) ) ) {
			return 'custom';
		}
		return (int) ( $env['login_logo_id'] ?? 0 ) > 0 ? 'applied' : 'hidden';
	}

	/**
	 * Does the login page already have a design of its own (so the pack must not touch it)? True when any of:
	 * customization switched on, a layout other than classic, a logo, a background image, a split media image or
	 * address, custom CSS, a message, or any login color.
	 *
	 * @param array<string, mixed> $s Login settings of Admin Customiser (key => value; missing keys count as empty).
	 */
	public static function login_already_customised( array $s ): bool {
		$id = static function ( $v ): int {
			if ( is_array( $v ) ) {
				$v = reset( $v );
			}
			return is_numeric( $v ) ? max( 0, (int) $v ) : 0;
		};
		$on = static fn( $v ): bool => true === $v || 1 === $v || '1' === $v || 'true' === $v || 'yes' === $v || 'on' === $v;
		if ( $on( $s['login_customization_enabled'] ?? false ) ) {
			return true;
		}
		$style = trim( (string) ( $s['login_style'] ?? '' ) );
		if ( '' !== $style && 'classic' !== $style ) {
			return true;
		}
		foreach ( array( 'login_logo', 'login_background_image', 'login_split_media_image' ) as $key ) {
			if ( $id( $s[ $key ] ?? 0 ) > 0 ) {
				return true;
			}
		}
		foreach ( array( 'login_split_media_video_url', 'login_split_media_youtube_url', 'login_custom_css', 'login_message' ) as $key ) {
			if ( '' !== trim( (string) ( $s[ $key ] ?? '' ) ) ) {
				return true;
			}
		}
		foreach ( $s as $key => $value ) {
			if ( 1 === preg_match( '/^login_.*_color$/', (string) $key ) && is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Dashboard widgets that need no setup.
	 *
	 * @return array<string, mixed>
	 */
	private static function dashboard_widgets(): array {
		return self::simple(
			'dashboard-widgets',
			__( 'Useful dashboard widgets', 'ux-studio' ),
			__( 'Adds a notes widget and a recent activity widget to the dashboard.', 'ux-studio' ),
			array( 'dashboard-widgets' ),
			array(
				'dashboard-widgets' => array(
					'widget_notes'         => true,
					'widget_user_activity' => true,
				),
			),
			array( array( 'pagespeed_key', __( 'The PageSpeed widget needs a Google API key, so it stays off. Add the key in the module settings and switch it on.', 'ux-studio' ) ) )
		);
	}

	/**
	 * Bot Throttle without blocking SEO tools.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function bot_throttle( array $env ): array {
		return self::simple(
			'bot-throttle',
			__( 'Bot throttling', 'ux-studio' ),
			__( 'Slows down aggressive crawlers when the server is busy and caps requests per visitor at 120 a minute. Search engines are protected; Ahrefs and Semrush site audits are never slowed down.', 'ux-studio' ),
			array( 'bot-throttle' ),
			array(
				'bot-throttle' => array(
					'enabled'             => true,
					'requests_per_minute' => 120,
					'blacklist_ua'        => self::bot_blacklist( (string) $env['bot_blacklist'] ),
					'whitelist_ua'        => self::bot_whitelist( (string) $env['bot_whitelist'] ),
				),
			)
		);
	}

	/**
	 * Content Security Policy inventory: audit only (nothing is blocked, never enforce). A site that already runs
	 * any CSP mode keeps it: the item is left out instead of downgrading report-only or enforce to audit.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function csp_audit( array $env ): array {
		$item = self::simple(
			'csp-audit',
			__( 'Content Security Policy inventory', 'ux-studio' ),
			__( 'Starts only listing what the website loads (nothing is blocked), the first step to a Content Security Policy.', 'ux-studio' ),
			array( 'security-optimization' ),
			array(
				'security-optimization' => array(
					'csp_mode'        => 'audit',
					'csp_sample_rate' => 20,
				),
			)
		);
		return 'off' !== (string) $env['csp_mode'] ? self::unavailable( $item, 'csp_set' ) : $item;
	}

	/**
	 * Hide the admin bar for visitors who log in as customers.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function hide_admin_bar( array $env ): array {
		$roles = array_values( array_intersect( array( 'subscriber', 'customer' ), array_map( 'strval', (array) $env['roles'] ) ) );
		$item  = self::optional(
			'hide-admin-bar',
			__( 'Hide the admin bar for customers', 'ux-studio' ),
			__( 'Subscribers and shop customers do not see the black admin bar on the site.', 'ux-studio' ),
			array( 'hide-admin-bar' ),
			array( 'hide-admin-bar' => array( 'roles' => $roles ) )
		);
		return array() === $roles ? self::unavailable( $item, 'no_roles' ) : $item;
	}

	/**
	 * The custom login address, last and only in the pending state.
	 *
	 * @param array<string, mixed> $env Environment.
	 * @return array<string, mixed>
	 */
	private static function login_url( array $env ): array {
		$item = self::simple(
			self::LOGIN_ITEM,
			/* translators: %s: login slug. */
			sprintf( __( 'Custom login address /%s', 'ux-studio' ), self::LOGIN_SLUG ),
			__( 'Moves the login page to a new address. First the new address is set and the old wp-login.php stays open; after you open the new address and confirm it, wp-login.php is closed and you get an e-mail with the address.', 'ux-studio' ),
			array( 'security-optimization' ),
			array(
				'security-optimization' => array(
					'login_url_enabled' => true,
					'login_url'         => self::LOGIN_SLUG,
				),
			),
			array( array( 'login_url_two_steps', __( 'You will have to confirm the new address before the old one is closed. Until then nobody is locked out.', 'ux-studio' ) ) )
		);
		if ( empty( $env['pretty_permalinks'] ) ) {
			return self::unavailable( $item, 'no_pretty_permalinks' );
		}
		if ( ! empty( $env['login_url_in_use'] ) ) {
			return self::unavailable( $item, 'already_set' );
		}
		if ( ! empty( $env['login_slug_collides'] ) ) {
			return self::unavailable( $item, 'collision' );
		}
		if ( isset( $env['blocked_settings']['login_url_enabled'] ) ) {
			return self::unavailable( $item, 'plugin_overlap' );
		}
		return $item;
	}
}
