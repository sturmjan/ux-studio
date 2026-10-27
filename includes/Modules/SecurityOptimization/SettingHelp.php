<?php
/**
 * Plain-language explanation of the Security settings (what it does, what can go wrong, when to switch it off).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * ONE place for the wording, used by the Security settings screen (the "Details" under an option) and by the
 * onboarding wizard (the list "What will be set"), so the two cannot drift apart.
 *
 * Every entry has the same parts: `does`, `risk`, `off` and, for the settings of the starter pack, `skip` (the
 * condition under which the pack leaves the setting out).
 */
final class SettingHelp {

	/**
	 * Settings of the starter pack that are only numbers or modes of another setting, so they have no text of their own.
	 */
	public const PARAMETERS = array(
		'revisions_count',
		'max_attempts',
		'lockout_time',
		'lockout_progressive',
		'ip_firewall_proxy',
		'disable_comments_scope',
		'disable_comments_post_types',
	);

	/**
	 * Keys that have an explanation.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * The explanation of one setting, or null.
	 *
	 * @param string $key       Setting key.
	 * @param bool   $with_skip Include the starter pack's skip condition.
	 * @return array{does:string,risk:string,off:string,skip?:string}|null
	 */
	public static function for_key( string $key, bool $with_skip = false ): ?array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return null;
		}
		$entry = $all[ $key ];
		if ( ! $with_skip ) {
			unset( $entry['skip'] );
		}
		return $entry;
	}

	/**
	 * All explanations (translated at the time of the call).
	 *
	 * @return array<string, array{does:string,risk:string,off:string,skip?:string}>
	 */
	public static function all(): array {
		return array(
			'disable_comments'          => array(
				'does' => __( 'Switches comments off on the whole site: the comment forms, the Comments menu and the comment feeds disappear. Existing comments stay in the database but are not shown.', 'ux-studio' ),
				'risk' => __( 'A site that lives from discussion or from product reviews loses them. With WooCommerce the pack switches comments off on posts and pages only.', 'ux-studio' ),
				'off'  => __( 'When visitors should be able to comment.', 'ux-studio' ),
				'skip' => __( 'A plugin that disables comments is already active.', 'ux-studio' ),
			),
			'limit_login_attempts'      => array(
				'does' => __( 'After 5 wrong passwords the visitor\'s IP address is locked out for 15 minutes (longer after repeated lockouts).', 'ux-studio' ),
				'risk' => __( 'Behind a proxy every visitor looks like the same IP address, so a few typos could lock everybody out. People who share one office address share the limit too.', 'ux-studio' ),
				'off'  => __( 'When the site is behind a proxy that hides the real IP address and you did not set the IP source in Security > Firewall.', 'ux-studio' ),
				'skip' => __( 'The site is behind a reverse proxy that is not Cloudflare, or a login protection plugin is active.', 'ux-studio' ),
			),
			'block_usernames'           => array(
				'does' => __( 'Nobody can register or rename a user to a reserved name such as admin, administrator, root or test (any name that contains one of them as a word, for example "my-admin").', 'ux-studio' ),
				'risk' => __( 'Accounts that already exist are not touched; a new account just cannot take such a name.', 'ux-studio' ),
				'off'  => __( 'When you really need an account with a reserved name.', 'ux-studio' ),
				'skip' => __( 'The login attempt limit is not set (this option works together with it).', 'ux-studio' ),
			),
			'block_username_attempts'   => array(
				'does' => __( 'A failed login with a reserved name such as admin locks the IP address at once for the lockout time, without waiting for 5 attempts. Bots try "admin" first.', 'ux-studio' ),
				'risk' => __( 'It also applies to a real account with such a name: one typo in its password locks that IP address, maybe your own. Behind a shared proxy address it could lock everybody out.', 'ux-studio' ),
				'off'  => __( 'When an account with a reserved name has to stay, or when you often mistype.', 'ux-studio' ),
				'skip' => __( 'An existing account uses a reserved name (rename it first), or the login attempt limit is not set.', 'ux-studio' ),
			),
			'protect_login'             => array(
				'does' => __( 'The login page no longer tells whether the username or the password was wrong. Application Passwords are switched off, so new ones cannot be created. Messages about a lockout, CAPTCHA and two-step verification stay readable.', 'ux-studio' ),
				'risk' => __( 'Integrations that sign in with an Application Password stop working: the WordPress mobile app, REST clients, some MCP servers and publishing tools.', 'ux-studio' ),
				'off'  => __( 'When you use such an integration, or want to create Application Passwords again.', 'ux-studio' ),
				'skip' => __( 'Somebody already has an Application Password (the pack never cuts off existing ones).', 'ux-studio' ),
			),
			'disable_rss_feeds'         => array(
				'does' => __( 'Feed addresses (posts, comments, categories, search) answer 403 and the feed links leave the page head.', 'ux-studio' ),
				'risk' => __( 'RSS readers, newsletter tools with RSS campaigns, podcast apps and aggregators stop getting your updates.', 'ux-studio' ),
				'off'  => __( 'When anybody follows the site by RSS or e-mail from the feed.', 'ux-studio' ),
				'skip' => __( 'The site has more than 5 published posts, a feed plugin is active (Mailchimp for WP, podcast or aggregator plugins), or a plugin that disables feeds is active.', 'ux-studio' ),
			),
			'protect_wp_config'         => array(
				'does' => __( 'The server refuses to hand out wp-config.php and its copies (.bak, .old, .zip, ...), which hold the database password.', 'ux-studio' ),
				'risk' => __( 'It is a rule in .htaccess, which only Apache and LiteSpeed read. A wrong rule can break the whole site with an HTTP 500, so the pack checks the site afterwards and takes the rules back if it fails.', 'ux-studio' ),
				'off'  => __( 'When the server configuration already blocks these files.', 'ux-studio' ),
				'skip' => __( 'The server is not Apache or LiteSpeed (nginx, IIS), or the .htaccess file cannot be written.', 'ux-studio' ),
			),
			'disable_directory_listing' => array(
				'does' => __( 'A folder without an index file, for example /wp-content/uploads/, answers 403 instead of showing the list of its files (Options -Indexes).', 'ux-studio' ),
				'risk' => __( 'On some hosts the setting of .htaccess does not allow the Options line and the site answers HTTP 500. The pack detects it, takes only this line back and keeps the other rules.', 'ux-studio' ),
				'off'  => __( 'When you offer a file list on purpose.', 'ux-studio' ),
				'skip' => __( 'The server is not Apache or LiteSpeed (nginx, IIS), or the .htaccess file cannot be written.', 'ux-studio' ),
			),
			'block_sensitive_files'     => array(
				'does' => __( 'readme.html, license.txt and wp-config-sample.php answer 403, so nobody can read the WordPress version from them.', 'ux-studio' ),
				'risk' => __( 'Hardly any. A scanner that reads readme.html to find the version will not see it.', 'ux-studio' ),
				'off'  => __( 'When the server configuration already blocks these files.', 'ux-studio' ),
				'skip' => __( 'The server is not Apache or LiteSpeed (nginx, IIS), or the .htaccess file cannot be written.', 'ux-studio' ),
			),
			'block_user_enumeration'    => array(
				'does' => __( '?author=1 no longer redirects to an author page that shows the login name, and the public list of users in the REST API is closed to visitors. Logged-in users keep it. The WordPress users sitemap (wp-sitemap-users.xml) is not changed.', 'ux-studio' ),
				'risk' => __( 'A decoupled front end (headless) or an app that reads authors over the REST API without logging in stops getting them. The block editor and the admin work as before.', 'ux-studio' ),
				'off'  => __( 'When a separate front end or an app needs the public list of users.', 'ux-studio' ),
				'skip' => __( 'A decoupled front end plugin is active (WPGraphQL, Faust.js, WPGatsby), or a plugin that blocks user enumeration is active.', 'ux-studio' ),
			),
			'hide_author_slugs'         => array(
				'does' => __( 'The user name in the address of an author page (and in the REST "slug") is replaced with a code, for example /author/3f9a.../. The old /author/name/ addresses answer 404, there is no redirect. The sitemap and the theme links use the new addresses.', 'ux-studio' ),
				'risk' => __( 'Author pages that are already indexed or linked stop working, and an author sitemap of an SEO plugin changes its addresses.', 'ux-studio' ),
				'off'  => __( 'When you want to keep the old author addresses.', 'ux-studio' ),
				'skip' => __( 'The site has more than 5 published posts (its author pages may already be indexed).', 'ux-studio' ),
			),
			'disable_xmlrpc'            => array(
				'does' => __( 'The old remote interface xmlrpc.php is closed. Attackers use it to guess passwords in bulk.', 'ux-studio' ),
				'risk' => __( 'Old mobile apps, some publishing tools and the Jetpack connection through XML-RPC stop working.', 'ux-studio' ),
				'off'  => __( 'When a tool you use still talks to xmlrpc.php.', 'ux-studio' ),
				'skip' => __( 'A plugin that disables XML-RPC is already active.', 'ux-studio' ),
			),
			'hide_wp_version'           => array(
				'does' => __( 'The WordPress version leaves the page head and the addresses of scripts and styles.', 'ux-studio' ),
				'risk' => __( 'None noticeable. It only makes automatic scanners work a little harder; it does not replace updates.', 'ux-studio' ),
				'off'  => __( 'Hardly ever needed.', 'ux-studio' ),
				'skip' => __( 'It is never left out.', 'ux-studio' ),
			),
			'disable_file_editing'      => array(
				'does' => __( 'The code editors for themes and plugins in wp-admin are switched off (DISALLOW_FILE_EDIT). A stolen administrator login then cannot easily turn into running code.', 'ux-studio' ),
				'risk' => __( 'You change theme and plugin files over FTP, the file manager or your editor instead.', 'ux-studio' ),
				'off'  => __( 'When you edit code in the WordPress admin.', 'ux-studio' ),
				'skip' => __( 'It is never left out.', 'ux-studio' ),
			),
			'security_headers'          => array(
				'does' => __( 'Sends X-Frame-Options (other sites cannot show yours in a frame), X-Content-Type-Options and Referrer-Policy. The headers are set in .htaccess and also by PHP.', 'ux-studio' ),
				'risk' => __( 'A page that has to be shown inside a frame of another site stops appearing there.', 'ux-studio' ),
				'off'  => __( 'When other sites embed your pages on purpose.', 'ux-studio' ),
				'skip' => __( 'It is never left out; where .htaccess cannot be written the headers are sent by PHP only.', 'ux-studio' ),
			),
			'enable_permissions_policy' => array(
				'does' => __( 'The Permissions-Policy header turns off camera, microphone, location, payment and USB for the page and for frames inside it. It is sent by PHP.', 'ux-studio' ),
				'risk' => __( 'Apple Pay and Google Pay, "use my location" on a map and video calls from the page stop working.', 'ux-studio' ),
				'off'  => __( 'When the site needs any of these functions.', 'ux-studio' ),
				'skip' => __( 'WooCommerce is active (online payments), or a plugin that sets the same header is active.', 'ux-studio' ),
			),
			'force_https'               => array(
				'does' => __( 'Every request to http:// is redirected to https:// (a rule in .htaccess).', 'ux-studio' ),
				'risk' => __( 'Behind Cloudflare in the Flexible mode or another proxy that talks to the server over http this causes an endless redirect and the site does not open. The pack checks it and takes the rule back. Insecure content (http images) on a page is blocked by browsers.', 'ux-studio' ),
				'off'  => __( 'When the site does not work over https everywhere.', 'ux-studio' ),
				'skip' => __( 'The site address is not https, this page was not opened over https, plain http does not answer, or the site is behind a proxy that is not Cloudflare.', 'ux-studio' ),
			),
			'enable_hsts'               => array(
				'does' => __( 'Tells the browser to open the site only over https for a whole year, even when somebody types http://.', 'ux-studio' ),
				'risk' => __( 'The browser remembers it for a year and cannot be talked out of it. If the certificate expires or https breaks, visitors cannot open the site at all until it works again.', 'ux-studio' ),
				'off'  => __( 'Before the site works over https everywhere, including the subdomains.', 'ux-studio' ),
				'skip' => __( 'The starter pack never switches it on. Switch it on yourself when you are sure about https.', 'ux-studio' ),
			),
			'canonical_redirect'        => array(
				'does' => __( 'Every request is redirected to one version of the address (with or without www, http or https) taken from the site address in Settings > General (a rule in .htaccess).', 'ux-studio' ),
				'risk' => __( 'A wrong site address in Settings > General, or a proxy that talks to the server over http, causes an endless redirect and the site does not open.', 'ux-studio' ),
				'off'  => __( 'When the redirect to one address is already done by the server or a CDN.', 'ux-studio' ),
				'skip' => __( 'The starter pack never switches it on. Switch it on yourself when you are sure about the site address.', 'ux-studio' ),
			),
			'limit_revisions'           => array(
				'does' => __( 'WordPress keeps only the last 10 saved versions of each post and page.', 'ux-studio' ),
				'risk' => __( 'You cannot go back to a version older than the tenth one.', 'ux-studio' ),
				'off'  => __( 'When you need a long history of changes of your texts.', 'ux-studio' ),
				'skip' => __( 'It is never left out.', 'ux-studio' ),
			),
			'heartbeat_control'         => array(
				'does' => __( 'The background messages between the editor and the server (autosave, post locking) go once a minute instead of every 15 to 60 seconds.', 'ux-studio' ),
				'risk' => __( 'When two people edit one post, they see each other\'s changes and the post lock a little later.', 'ux-studio' ),
				'off'  => __( 'When many editors work on the same texts at the same time.', 'ux-studio' ),
				'skip' => __( 'A caching plugin that already manages Heartbeat is active (WP Rocket, LiteSpeed Cache, Perfmatters, Heartbeat Control).', 'ux-studio' ),
			),
			'disable_emojis'            => array(
				'does' => __( 'Removes the emoji script and style that WordPress adds to every page. Emoji still show, with the font of the visitor\'s device.', 'ux-studio' ),
				'risk' => __( 'A very old browser without an emoji font shows empty boxes instead of emoji.', 'ux-studio' ),
				'off'  => __( 'Hardly ever needed.', 'ux-studio' ),
				'skip' => __( 'It is never left out.', 'ux-studio' ),
			),
			'remove_unused_default_themes' => array(
				'does' => __( 'Deletes the Twenty* themes that WordPress brought itself and that nothing uses; the newest one stays as the fallback. A theme you installed yourself is never deleted.', 'ux-studio' ),
				'risk' => __( 'A deleted theme has to be installed again when you want it.', 'ux-studio' ),
				'off'  => __( 'When you want to keep the default themes.', 'ux-studio' ),
				'skip' => __( 'It is never left out.', 'ux-studio' ),
			),
		);
	}
}
