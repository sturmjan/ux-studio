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
 *   roles                string[] role slugs that exist,
 *   bot_blacklist        string  current Bot Throttle blacklist (one User-Agent per line),
 *   bot_whitelist        string  current Bot Throttle whitelist,
 *   blocked_settings     array   Security setting key => names of the foreign plugins that already do that job.
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
			'roles'               => array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ),
			'bot_blacklist'       => "AhrefsBot\nSemrushBot\nMJ12bot\nDotBot\nPetalBot\nBLEXBot\nMegaIndex\nSerpstatBot\nrogerbot\nDataForSeoBot",
			'bot_whitelist'       => '',
			'blocked_settings'    => array(),
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
			self::optional(
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
			self::optional(
				'user-last-login',
				__( 'Last login of users', 'ux-studio' ),
				__( 'Shows when each user last logged in.', 'ux-studio' ),
				array( 'user-last-login' )
			),
			self::hide_admin_bar( $env ),
			self::optional(
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
			),
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
			'disable_comments'     => true,
			'disable_file_editing' => true,
			'disable_xmlrpc'       => true,
			'hide_wp_version'      => true,
			// The PHP fallback sends the headers even when .htaccess cannot be written and on nginx.
			'security_headers'     => true,
			'limit_revisions'      => true,
			'revisions_count'      => 10,
			'heartbeat_control'    => 'slow',
			'disable_emojis'       => true,
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
			$warnings[] = array( 'proxy_xff', __( 'The site is behind a reverse proxy. Login attempt limiting is left off: first set the client IP source (Security > Firewall), then switch it on.', 'ux-studio' ) );
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

		return self::build(
			'security-hardening',
			__( 'Security hardening and cleanup', 'ux-studio' ),
			__( 'Switches off comments, the file editor and XML-RPC, hides the WordPress version, sends security headers, limits login attempts, slows down Heartbeat, keeps 10 revisions and removes the emoji script.', 'ux-studio' ),
			array( 'security-optimization' ),
			array( 'security-optimization' => $set ),
			$warnings,
			false
		);
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
			'hide_admin_bar_elements'     => array( 'wp_logo' ),
			'hide_help_tab'               => true,
			// Enabling the module also turns the admin quick search on by default; nobody asked for that here.
			'quick_search_enabled'        => false,
			'login_customization_enabled' => true,
		);
		if ( '' !== trim( (string) $env['site_name'] ) ) {
			$admin['admin_footer_text'] = trim( (string) $env['site_name'] );
		}
		if ( (int) $env['login_logo_id'] > 0 ) {
			$admin['login_logo'] = (int) $env['login_logo_id'];
		}
		$warnings = array();
		if ( (int) $env['login_logo_id'] <= 0 ) {
			// No logo of our own: show none instead of the big WordPress one (LoginExtras::logo_hidden()).
			$admin['form_options'] = array( 'hide_login_logo' );
			$warnings[]            = array( 'no_logo', __( 'The site has no logo or site icon yet, so the login page shows no logo at all. Set a logo in Admin Customiser later and untick "Hide login logo".', 'ux-studio' ) );
		}
		return self::simple(
			'hide-wordpress',
			__( 'Hide WordPress', 'ux-studio' ),
			__( 'Removes the WordPress logo from the admin bar, replaces the admin footer text with the site name, hides the Help tab, shows the site logo on the login page (or no logo when the site has none) and removes the generator and other WordPress links from the page head.', 'ux-studio' ),
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
