<?php
/**
 * Plain-language help of the Security settings, read by Core\SettingHelp (settings screen) and by
 * Security\SettingHelp (onboarding wizard). Format: docs/help-texts.md.
 *
 * Keys and texts are identical to the ones the module has always shown (so translations stay valid).
 * These entries predate the `effect` part, so they carry only does / risk / off / skip.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	'captcha_enabled'          => array(
		'does' => __( 'Login, registration, lost password and comments ask for a quick check before they are accepted. With the key-less Shield the browser of the visitor solves a small puzzle by itself: a fraction of a second, no account, no keys, no cookies, nothing sent to a third party. A visitor without JavaScript answers a simple sum instead, so nobody is locked out.', 'ux-studio' ),
		'risk' => __( 'The Shield stops mass scripted attempts. It does not tell a person from a clever script: an attacker who spends the computing time passes, only slower. A visitor whose browser blocks the script sees the sum after a few seconds. Five wrong answers count as failed logins and lock the address like wrong passwords do.', 'ux-studio' ),
		'off'  => __( 'When you use Cloudflare Turnstile or reCAPTCHA keys or a CAPTCHA plugin instead, or when you want to protect only the forms of the Forms module.', 'ux-studio' ),
		'skip' => __( 'A CAPTCHA is already set up (turned on, keys stored or a CAPTCHA plugin active), or the site is behind a reverse proxy that is not Cloudflare: the Shield limits per IP address, and behind such a proxy every visitor looks the same.', 'ux-studio' ),
	),
	'captcha_provider'          => array(
		'does' => __( 'Automatic uses keys from the central app while the site is connected, then your own Turnstile or reCAPTCHA keys, and the key-less Shield when there are none. Anything you choose by hand is used as it is.', 'ux-studio' ),
		'risk' => __( 'Choosing the Shield on a site that has Turnstile or reCAPTCHA keys drops that check: the Shield is lighter and has no network of other sites behind it. Choosing a provider without its keys switches the CAPTCHA off silently.', 'ux-studio' ),
		'off'  => __( 'Leave it on Automatic unless you need one specific provider.', 'ux-studio' ),
		'skip' => __( 'Same as the CAPTCHA switch: a CAPTCHA is already set up, a CAPTCHA plugin is active or the site is behind a reverse proxy that is not Cloudflare.', 'ux-studio' ),
	),
	'disable_comments'          => array(
		'does' => __( 'Switches comments off on the whole site: the comment forms, the Comments menu and the comment feeds disappear. Existing comments stay in the database but are not shown.', 'ux-studio' ),
		'risk' => __( 'A site that lives from discussion or from product reviews loses them. With WooCommerce the pack switches comments off on posts and pages only.', 'ux-studio' ),
		'off'  => __( 'When visitors should be able to comment.', 'ux-studio' ),
		'skip' => __( 'A plugin that disables comments is already active.', 'ux-studio' ),
	),
	'limit_login_attempts'      => array(
		'does' => __( 'After the set number of wrong passwords (3 by default) the visitor\'s IP address is locked out for the lockout time (30 minutes by default, longer after repeated lockouts).', 'ux-studio' ),
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
		'does' => __( 'A failed login with a reserved name such as admin locks the IP address at once for the lockout time, without waiting for the maximum number of attempts. Bots try "admin" first.', 'ux-studio' ),
		'risk' => __( 'It also applies to a real account with such a name: one typo in its password locks that IP address, maybe your own. Behind a shared proxy address it could lock everybody out.', 'ux-studio' ),
		'off'  => __( 'When an account with a reserved name has to stay, or when you often mistype.', 'ux-studio' ),
		'skip' => __( 'An existing account uses a reserved name (rename it first), or the login attempt limit is not set.', 'ux-studio' ),
	),
	'protect_login'             => array(
		'does' => __( 'The login page no longer tells whether the username or the password was wrong. Application Passwords are turned off, so new ones cannot be created. Messages about a lockout, CAPTCHA and two-step verification stay readable.', 'ux-studio' ),
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
		'does' => __( 'The code editors for themes and plugins in wp-admin are turned off (DISALLOW_FILE_EDIT). A stolen administrator login then cannot easily turn into running code.', 'ux-studio' ),
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
	'ip_firewall_enabled'        => array(
		'does'   => __( 'Every request from a banned address, range or country is refused with a 403 page, whatever it asks for: pages, login, admin or REST API.', 'ux-studio' ),
		'effect' => __( 'Blocked visitors see only the block page. Administrators who are logged in and addresses on the allowlist are never blocked, so you cannot lock yourself out while you are signed in.', 'ux-studio' ),
		'risk'   => __( 'A wrong ban or a wrong "Client IP source" can block real visitors, or your own address once you log out. Add your own address to the allowlist first.', 'ux-studio' ),
		'off'    => __( 'Leave it off when the server or Cloudflare already filters addresses for you.', 'ux-studio' ),
	),
	'ip_firewall_proxy'          => array(
		'does'   => __( 'Tells the firewall where to read the visitor\'s address from. "Direct connection" uses the address the server sees on the connection (REMOTE_ADDR). Behind Cloudflare or another reverse proxy that address is the proxy, so the real visitor is read from a header the proxy adds.', 'ux-studio' ),
		'effect' => __( 'The right choice makes bans and lockouts hit the real visitor. Headers are trusted only when the request really comes from Cloudflare (or from a proxy range of your own), because anybody can fake a header.', 'ux-studio' ),
		'risk'   => __( 'Direct connection behind a proxy makes all visitors look like one address: one ban or lockout then blocks everybody. Choosing Cloudflare or a reverse proxy on a site that is not behind one lets a visitor pretend to be another address.', 'ux-studio' ),
		'off'    => __( 'Leave "Direct connection" unless you are sure the site sits behind Cloudflare or a reverse proxy (the host can tell you).', 'ux-studio' ),
	),
	'ip_firewall_allowlist'      => array(
		'does'   => __( 'Addresses that are never blocked, one per line. You can write a single address (203.0.113.7) or a whole range in [[cidr|CIDR]] notation (203.0.113.0/24). The loopback address 127.0.0.1, the computer talking to itself, is always allowed.', 'ux-studio' ),
		'effect' => __( 'These visitors pass the firewall and the country blocklist even when a ban would match them. Put your office, your monitoring and your payment gateway here.', 'ux-studio' ),
		'risk'   => __( 'Everybody behind an address on this list is trusted, so list only addresses you control. A shared or changing address (home internet) is a poor choice.', 'ux-studio' ),
	),
	'ip_firewall_message'        => array(
		'does'   => __( 'The sentence a blocked visitor sees on the 403 page. Empty shows a plain default.', 'ux-studio' ),
		'effect' => __( 'Say how to reach you, so a visitor who is blocked by mistake can ask for help.', 'ux-studio' ),
	),
	'country_blocklist_enabled'  => array(
		'does'   => __( 'Visitors whose address belongs to one of the countries below are refused by the IP firewall. The address ranges of each country are downloaded and stored on the site.', 'ux-studio' ),
		'effect' => __( 'Traffic from those countries, including real customers, search engine robots and monitoring tools, sees only the block page.', 'ux-studio' ),
		'risk'   => __( 'Country data is never exact: a visitor on a VPN or a traveler can be blocked or let through by mistake. Blocking a country also blocks every bot or service whose servers stand there, for example search engines.', 'ux-studio' ),
		'off'    => __( 'Use it only against a clear, repeated attack from specific countries. It is not a general protection.', 'ux-studio' ),
	),
	'blocked_countries'          => array(
		'does'   => __( 'The countries to block. The list is applied when you save the settings: the address ranges of each chosen country are downloaded and a ban is created or refreshed.', 'ux-studio' ),
		'effect' => __( 'Every visitor from these countries is blocked as soon as the country blocklist is on. Removing a country lifts the block for it.', 'ux-studio' ),
		'risk'   => __( 'Do not block the country where your customers, your host or your payment gateway are. Blocking your own country can lock you out once you log out; add your address to the allowlist first.', 'ux-studio' ),
	),
	'login_url_enabled'          => array(
		'does'   => __( 'The login page moves from wp-login.php to the address you choose below. Anybody who opens the old address gets the response chosen under "Direct wp-login.php behavior".', 'ux-studio' ),
		'effect' => __( 'Bots that hammer wp-login.php find nothing. People who log in must use the new address, so give it to everybody who logs in and bookmark it.', 'ux-studio' ),
		'risk'   => __( 'If you forget the new address you cannot log in. Rescue: add define( \'UXSTUDIO_DISABLE_LOGIN_LOCK\', true ); to wp-config.php. Plugins and saved links that point to wp-login.php stop working.', 'ux-studio' ),
		'off'    => __( 'Leave it off if several people log in and you cannot make sure they know the new address.', 'ux-studio' ),
	),
	'login_url'                  => array(
		'does'   => __( 'The new address of the login page, for example "my-login" gives yourdomain.com/my-login.', 'ux-studio' ),
		'effect' => __( 'Pick something that is not easy to guess. If it clashes with an existing page, the change is not applied and a warning is logged, so the old login keeps working.', 'ux-studio' ),
		'risk'   => __( 'Write the address down before you save. Without it you cannot reach the login page.', 'ux-studio' ),
	),
	'disabled_behavior'          => array(
		'does'   => __( 'What a visitor gets who opens wp-login.php or wp-admin without being logged in, once the custom login address is on.', 'ux-studio' ),
		'effect' => __( 'A 404 page looks as if there is no login at all. A redirect or a message tells honest visitors where they are, but also shows the page exists.', 'ux-studio' ),
		'risk'   => __( 'Choosing the homepage or another page for a visitor who really wants to log in can confuse them. Make sure your team knows the new login address.', 'ux-studio' ),
	),
	'existing_page'              => array(
		'does'   => __( 'The page of your site that a visitor of the old login address is sent to.', 'ux-studio' ),
		'effect' => __( 'Pick a harmless public page, for example the homepage or a contact page. Used only with "Existing page" above.', 'ux-studio' ),
		'risk'   => __( 'A page that is deleted or turned into a draft later leaves visitors on an error page.', 'ux-studio' ),
	),
	'max_attempts'               => array(
		'does'   => __( 'How many wrong passwords from one address are tolerated before that address is locked out. The default is 3.', 'ux-studio' ),
		'effect' => __( 'A lower number stops password guessing sooner but also catches people who simply mistype. Three to five is a sensible range.', 'ux-studio' ),
		'risk'   => __( 'A very low number (1 or 2) can lock out you or your client after a typo. People who share one address, such as an office, share the count.', 'ux-studio' ),
	),
	'lockout_time'               => array(
		'does'   => __( 'For how many minutes a locked-out address cannot try to log in. The default is 30. With "Lengthen repeated lockouts" the time grows with every repeat.', 'ux-studio' ),
		'effect' => __( 'A longer lockout slows down attackers more, but a person who locked themselves out has to wait that long.', 'ux-studio' ),
		'risk'   => __( 'A very long lockout on a shared address keeps everybody behind it out for hours.', 'ux-studio' ),
	),
	'notify_admin'               => array(
		'does'   => __( 'Sends an e-mail each time an address gets locked out. It names the address, the username that was tried and the length of the lockout. The e-mail goes to the address set below, or to the site administrator.', 'ux-studio' ),
		'effect' => __( 'You learn about attacks at once. On a site that is attacked all day it can mean dozens of e-mails, so it fits a quiet site best.', 'ux-studio' ),
		'off'    => __( 'Leave it off if the site is a regular target of bots and you do not want your inbox full.', 'ux-studio' ),
	),
	'captcha_shield_adaptive'    => array(
		'does'   => __( 'When an address has had several failed logins recently (the threshold set under "Adaptive mode threshold"), it gets the next harder Shield difficulty for 15 minutes. The Shield is the key-less [[CAPTCHA]] of UX Studio: the browser solves a small puzzle by itself.', 'ux-studio' ),
		'effect' => __( 'A normal visitor still gets the easy check. Only addresses that look like an attack must compute longer, so the cost lands on the attacker.', 'ux-studio' ),
	),
	'captcha_mode'               => array(
		'does'   => __( 'Decides when the check is required in the inline form. "Always" asks every visitor. "Adaptive" asks only after repeated failed attempts from that address.', 'ux-studio' ),
		'effect' => __( 'Adaptive is friendlier: most visitors see no check at all. Always protects better against the very first attempt.', 'ux-studio' ),
		'risk'   => __( 'With Adaptive an attacker gets a few free attempts before the check appears. Always puts a small extra step in front of every honest visitor.', 'ux-studio' ),
	),
	'captcha_adaptive_threshold' => array(
		'does'   => __( 'In adaptive mode, how many failed attempts from one address make the [[CAPTCHA]] appear. Allowed values are 1 to 20.', 'ux-studio' ),
		'effect' => __( 'A low number starts the check early; a high number lets an attacker try more before it shows.', 'ux-studio' ),
	),
	'disable_embeds'             => array(
		'does'   => __( 'Turns off the extra embed machinery of WordPress: other sites can no longer discover your embed address, pasted links to sites that are not on the WordPress list of known services are no longer turned into embeds, and the embed preview in the visual editor goes away.', 'ux-studio' ),
		'effect' => __( 'The page head is a little lighter. Links to well-known services such as YouTube still become players as before.', 'ux-studio' ),
		'risk'   => __( 'Other WordPress sites can no longer show your posts as embedded cards. Pasted links to less common sites stay plain links instead of becoming a box. Check posts that rely on such embeds.', 'ux-studio' ),
		'off'    => __( 'Leave it off if you want others to embed your posts, or you paste links to uncommon sites and expect a box.', 'ux-studio' ),
	),
	'restrict_rest_api'          => array(
		'does'   => __( 'Anonymous visitors are refused by the [[REST API]], the technical interface that apps, forms and many plugins use. Logged-in users keep full access. A list of well-known public endpoints (forms, shop, oEmbed) stays open.', 'ux-studio' ),
		'effect' => __( 'Bots can no longer read your content and user names through the interface. Pages themselves look the same.', 'ux-studio' ),
		'risk'   => __( 'A plugin, mobile app, newsletter tool, headless front end or contact form that calls the REST API without logging in can stop working. Test the forms and the shop after you turn it on.', 'ux-studio' ),
		'off'    => __( 'Leave it off when other services read your site through the REST API.', 'ux-studio' ),
	),
	'restrict_mime_types'        => array(
		'does'   => __( 'Only common file types can be uploaded: images, PDF and Office documents, ZIP, MP3, MP4, WebM and web fonts. Everything else is refused in the media library.', 'ux-studio' ),
		'effect' => __( 'Nobody can upload a risky or unusual file type by mistake or by a stolen account.', 'ux-studio' ),
		'risk'   => __( 'Other file types, for example 3D models, CAD drawings, special audio or code files, can no longer be uploaded. Users will get an error and not know why.', 'ux-studio' ),
		'off'    => __( 'Leave it off when your team regularly uploads types that are not in the list above.', 'ux-studio' ),
	),
	'disable_blog'               => array(
		'does'   => __( 'Hides Posts from the admin menu and the admin bar, sends the Posts screens in the admin back to the dashboard and removes the Quick Draft box. On the site every blog post, category, tag, date and author page is sent to the homepage with a permanent (301) redirect.', 'ux-studio' ),
		'effect' => __( 'A presentation site without a blog looks tidier in the admin. A visitor who opens an old post address lands on the homepage.', 'ux-studio' ),
		'risk'   => __( 'Existing posts disappear for visitors and search engines, which can cost you search traffic, and browsers remember a permanent redirect for a long time. You also cannot edit posts in the admin any more. Plugins that store their content as posts (news, events) are affected too. The posts stay in the database.', 'ux-studio' ),
		'off'    => __( 'Leave it off if the site has a blog or a plugin stores its content as posts.', 'ux-studio' ),
	),
);
