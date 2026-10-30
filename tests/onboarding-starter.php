<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the onboarding starter pack (PLAN.md 29, phase F1): which items exist, what they contain
 * for which environment, that every setting key really exists in its module, the Bot Throttle lists, the pending
 * state of the custom login address, the update e-mail filters and the AutoStart decision for a new site.
 * Needs no WordPress and no database:
 *
 *   php tests/onboarding-starter.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );

function __( $text, $domain = '' ) { return $text; }
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
$GLOBALS['uxs_options'] = array();

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Migration\AutoStart;
use UxStudio\Core\Onboarding\Applier;
use UxStudio\Core\Onboarding\HtaccessGuard;
use UxStudio\Modules\SecurityOptimization\HtaccessWriter;
use UxStudio\Core\Onboarding\StarterPack;
use UxStudio\Modules\SecurityOptimization\HeadLinks;
use UxStudio\Modules\SecurityOptimization\LoginUrlPending;
use UxStudio\Modules\SecurityOptimization\UpdateEmails;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

$by_id = static function ( array $env ): array {
	$out = array();
	foreach ( StarterPack::items( $env ) as $item ) {
		$out[ $item['id'] ] = $item;
	}
	return $out;
};
$sec = static fn( array $items ): array => $items['security-hardening']['settings']['security-optimization'];

/* ── Structure ── */

$items = StarterPack::items( array() );
$ids   = array_column( $items, 'id' );
$assert( 'every item id of the whitelist is built, in the same order', $ids === StarterPack::ids(), json_encode( $ids ) );
$assert( 'the custom login address is the LAST item', StarterPack::LOGIN_ITEM === end( $ids ) );
$assert( 'ids are unique', count( $ids ) === count( array_unique( $ids ) ) );
$assert( 'sanitize_ids keeps only whitelisted ids, in application order, without duplicates', array( 'security-hardening', 'login-url' ) === StarterPack::sanitize_ids( array( 'login-url', 'evil', 'security-hardening', 'login-url', 5 ) ) );
$assert( 'sanitize_ids of junk is empty', array() === StarterPack::sanitize_ids( 'x' ) && array() === StarterPack::sanitize_ids( null ) );

$checked  = array_column( array_filter( $items, static fn( $i ) => $i['checked'] ), 'id' );
$optional = array_column( array_filter( $items, static fn( $i ) => $i['optional'] ), 'id' );
$assert( 'optional items: smtp, redirects, media replace, hide admin bar', array( 'smtp-email', 'redirects', 'media-replace', 'hide-admin-bar' ) === $optional, json_encode( $optional ) );
$assert( 'pre-checked since 0.7.1: e-mail log, last login, CSP inventory', 3 === count( array_intersect( array( 'email-log', 'user-last-login', 'csp-audit' ), $checked ) ), json_encode( $checked ) );
$assert( 'optional items are not pre-checked, the others are', ! array_intersect( $optional, $checked ) && count( $checked ) === count( $ids ) - count( $optional ) );

// The Disable auto-updates module would turn ALL updates off by default: never part of the pack.
$all_modules = array();
foreach ( $items as $item ) {
	$all_modules = array_merge( $all_modules, $item['modules'], array_keys( $item['settings'] ) );
}
$assert( 'the Disable auto-updates module is never in the pack', ! in_array( 'disable-auto-updates', $all_modules, true ) );

/* ── Every module and every setting key exists in the code ── */

$dirs = array();
foreach ( glob( $root . '/includes/Modules/*/meta.json' ) as $file ) {
	$meta = json_decode( (string) file_get_contents( $file ), true );
	if ( is_array( $meta ) && ! empty( $meta['id'] ) ) {
		$dirs[ $meta['id'] ] = dirname( $file );
	}
}
$unknown_module = array();
$unknown_key    = array();
$code_of        = static function ( string $dir ): string {
	$code = '';
	foreach ( glob( $dir . '/*.php' ) as $f ) {
		$code .= (string) file_get_contents( $f );
	}
	return $code;
};
foreach ( StarterPack::items( array( 'woocommerce' => true, 'cloudflare' => true ) ) as $item ) {
	foreach ( $item['modules'] as $module ) {
		if ( ! isset( $dirs[ $module ] ) ) {
			$unknown_module[] = $module;
		}
	}
	foreach ( $item['settings'] as $module => $values ) {
		if ( ! isset( $dirs[ $module ] ) ) {
			$unknown_module[] = $module;
			continue;
		}
		$code = $code_of( $dirs[ $module ] );
		foreach ( array_keys( $values ) as $key ) {
			if ( ! preg_match( "/'key'\s*=>\s*'" . preg_quote( $key, '/' ) . "'/", $code ) ) {
				$unknown_key[] = $module . '.' . $key;
			}
		}
	}
}
$assert( 'every module of the pack exists', array() === $unknown_module, implode( ',', $unknown_module ) );
$assert( 'every setting key of the pack is in its module schema', array() === $unknown_key, implode( ',', $unknown_key ) );

/* ── Never in the pack ── */

$never = array( 'ip_firewall_enabled', 'country_blocklist_enabled', 'blocked_countries', 'canonical_redirect', 'enable_hsts', 'restrict_rest_api', 'two_factor_enabled', 'two_factor_required_roles' );
$found = array();
foreach ( StarterPack::items( array( 'woocommerce' => true, 'cloudflare' => true ) ) as $item ) {
	foreach ( (array) ( $item['settings']['security-optimization'] ?? array() ) as $key => $value ) {
		if ( in_array( $key, $never, true ) ) {
			$found[] = $key;
		}
	}
}
$assert( 'forced 2FA, IP firewall, country block, HTTPS forcing, HSTS and REST restriction are never set', array() === $found, implode( ',', $found ) );
$assert( 'CSP is never enforced; the optional item only inventories (audit, 20 percent)', array( 'csp_mode' => 'audit', 'csp_sample_rate' => 20 ) === $by_id( array() )['csp-audit']['settings']['security-optimization'] && $by_id( array() )['csp-audit']['checked'] );
foreach ( array( 'audit', 'report-only', 'enforce' ) as $mode ) {
	$csp = $by_id( array( 'csp_mode' => $mode ) )['csp-audit'];
	$assert( "CSP item is left out when the mode is already $mode (never downgrades)", ! $csp['available'] && ! $csp['checked'] && 'csp_set' === $csp['unavailable'] );
}

/* ── Security preset ── */

$s = $sec( $by_id( array() ) );
$assert( 'security: comments, file editor, XML-RPC, version, headers, emojis on', true === $s['disable_comments'] && true === $s['disable_file_editing'] && true === $s['disable_xmlrpc'] && true === $s['hide_wp_version'] && true === $s['security_headers'] && true === $s['disable_emojis'] );
$assert( 'security: revisions 10, heartbeat slow', true === $s['limit_revisions'] && 10 === $s['revisions_count'] && 'slow' === $s['heartbeat_control'] );
$assert( 'security: login limit 5 attempts, 15 minutes, progressive', true === $s['limit_login_attempts'] && 5 === $s['max_attempts'] && 15 === $s['lockout_time'] && true === $s['lockout_progressive'] );
$assert( 'security: without WooCommerce comments are off everywhere (no scope keys)', ! isset( $s['disable_comments_scope'] ) && ! isset( $s['disable_comments_post_types'] ) );
$assert( 'security: no proxy mode without Cloudflare', ! isset( $s['ip_firewall_proxy'] ) );

$woo = $by_id( array( 'woocommerce' => true ) );
$sw  = $sec( $woo );
$assert( 'WooCommerce: comments off for posts and pages only, so product reviews stay', 'selected' === $sw['disable_comments_scope'] && array( 'post', 'page' ) === $sw['disable_comments_post_types'] && true === $sw['disable_comments'] );
$assert( 'WooCommerce: the item tells why', in_array( 'woo_comments', array_column( $woo['security-hardening']['warnings'], 'code' ), true ) );

$cf = $by_id( array( 'cloudflare' => true ) );
$assert( 'Cloudflare: client IP source is cloudflare and login limiting stays on', 'cloudflare' === $sec( $cf )['ip_firewall_proxy'] && true === $sec( $cf )['limit_login_attempts'] );
$xf = $by_id( array( 'xff' => true ) );
$assert( 'proxy without Cloudflare: login limiting is NOT enabled (and its numbers are not sent), with a warning', ! isset( $sec( $xf )['limit_login_attempts'] ) && ! isset( $sec( $xf )['max_attempts'] ) && ! isset( $sec( $xf )['lockout_time'] ) && in_array( 'proxy_xff', array_column( $xf['security-hardening']['warnings'], 'code' ), true ) );
$assert( 'proxy without Cloudflare: no proxy mode is guessed', ! isset( $sec( $xf )['ip_firewall_proxy'] ) );
$both = $by_id( array( 'xff' => true, 'cloudflare' => true ) );
$assert( 'Cloudflare also sends X-Forwarded-For: still Cloudflare mode with login limiting', 'cloudflare' === $sec( $both )['ip_firewall_proxy'] && true === $sec( $both )['limit_login_attempts'] );

$ng = $by_id( array( 'server' => 'nginx', 'htaccess_writable' => false ) );
$assert( 'nginx: security headers still on (PHP fallback), no .htaccess warning', true === $sec( $ng )['security_headers'] && ! in_array( 'htaccess_not_writable', array_column( $ng['security-hardening']['warnings'], 'code' ), true ) );
$ro = $by_id( array( 'server' => 'apache', 'htaccess_writable' => false ) );
$assert( 'Apache with a read only .htaccess: headers still on (PHP fallback) and a warning says so', true === $sec( $ro )['security_headers'] && in_array( 'htaccess_not_writable', array_column( $ro['security-hardening']['warnings'], 'code' ), true ) );
$ls = $by_id( array( 'server' => 'litespeed', 'htaccess_writable' => false ) );
$assert( 'LiteSpeed counts as Apache for the .htaccess warning', in_array( 'htaccess_not_writable', array_column( $ls['security-hardening']['warnings'], 'code' ), true ) );

$blocked = $by_id( array( 'blocked_settings' => array( 'disable_comments' => array( 'Disable Comments' ), 'limit_login_attempts' => array( 'Loginizer' ), 'login_url_enabled' => array( 'WPS Hide Login' ) ) ) );
$sb      = $sec( $blocked );
$assert( 'a foreign plugin that does the job: the setting is left out, never doubled', ! isset( $sb['disable_comments'] ) && ! isset( $sb['limit_login_attempts'] ) && ! isset( $sb['max_attempts'] ) && ! isset( $sb['lockout_progressive'] ) && true === $sb['disable_xmlrpc'] );
$assert( 'a foreign plugin that does the job: the item says which plugin', 2 === count( array_filter( $blocked['security-hardening']['warnings'], static fn( $w ) => 'blocked_feature' === $w['code'] ) ) );
$assert( 'a blocked comments setting also drops the WooCommerce scope keys', ! isset( $sec( $by_id( array( 'woocommerce' => true, 'blocked_settings' => array( 'disable_comments' => array( 'x' ) ) ) ) )['disable_comments_scope'] ) );

/* ── Custom login address ── */

$login = $by_id( array() )[ StarterPack::LOGIN_ITEM ];
$assert( 'login address: /administrace, enabled, available and pre-checked', $login['available'] && $login['checked'] && array( 'login_url_enabled' => true, 'login_url' => 'administrace' ) === $login['settings']['security-optimization'] );
$un = static fn( array $env ): string => $by_id( $env )[ StarterPack::LOGIN_ITEM ]['unavailable'];
$assert( 'login address: not with plain permalinks', 'no_pretty_permalinks' === $un( array( 'pretty_permalinks' => false ) ) );
$assert( 'login address: not when already set', 'already_set' === $un( array( 'login_url_in_use' => true ) ) );
$assert( 'login address: not when the slug collides with a page, post or term', 'collision' === $un( array( 'login_slug_collides' => true ) ) );
$assert( 'login address: not when a plugin like WPS Hide Login does it', 'plugin_overlap' === $un( array( 'blocked_settings' => array( 'login_url_enabled' => array( 'WPS Hide Login' ) ) ) ) );
$assert( 'an unavailable item is unchecked', ! $by_id( array( 'pretty_permalinks' => false ) )[ StarterPack::LOGIN_ITEM ]['checked'] );

// Pending state: set, but wp-login.php stays open until confirmed.
$now = 1000000;
$assert( 'pending: no record = none', 'none' === LoginUrlPending::decide( null, 'administrace', $now ) );
$assert( 'pending: record for the slug in force = pending', 'pending' === LoginUrlPending::decide( array( 'slug' => 'administrace', 'since' => $now - 60 ), 'administrace', $now ) );
$assert( 'pending: after 24 hours it is only reported as expired, it never turns into blocking', 'expired' === LoginUrlPending::decide( array( 'slug' => 'administrace', 'since' => $now - DAY_IN_SECONDS ), 'administrace', $now ) && 'expired' === LoginUrlPending::decide( array( 'slug' => 'administrace', 'since' => $now - 30 * DAY_IN_SECONDS ), 'administrace', $now ) );
$assert( 'pending: a slug changed by hand ends the pending state', 'none' === LoginUrlPending::decide( array( 'slug' => 'administrace', 'since' => $now ), 'my-login', $now ) );
$assert( 'pending: an empty slug is never pending', 'none' === LoginUrlPending::decide( array( 'slug' => '', 'since' => $now ), '', $now ) );

// Wiring in the Security module (source level, like tests/login-url-notice.php).
$src = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
$assert( 'module: the status ends with the pending check after the collision check', 1 === preg_match( "/'collision'.*LoginUrlPending::is_pending\( \\\$slug \).*'pending'/s", substr( $src, (int) strpos( $src, 'function login_lock_status' ), 2500 ) ) );
$assert( 'module: a pending address is served (the login form works there)', 1 === preg_match( "/function handle_custom_login_url.*'pending' !== \\\$status\['reason'\]/s", $src ) );
$assert( 'module: wp-login.php is denied and the form URLs are swapped only while the lock is ACTIVE, so pending fails open', false !== strpos( $src, '! $this->lock_status_cached()' . "['active']" ) && 1 === preg_match( '/function filter_login_site_url.*\$status\[\'active\'\]/s', $src ) );
$assert( 'module: the new Security settings exist with their hooks', false !== strpos( $src, "'key'     => 'hide_wp_head_links'" ) && false !== strpos( $src, 'HeadLinks::register()' ) && false !== strpos( $src, "'key'     => 'suppress_update_emails'" ) && false !== strpos( $src, 'UpdateEmails::register()' ) );

/* ── Hide WordPress ── */

$hw = $by_id( array( 'site_name' => 'Hotel Alfa', 'login_logo_id' => 77 ) )['hide-wordpress'];
$ac = $hw['settings']['admin-customiser'];
$assert( 'hide WordPress: admin bar logo hidden, footer = site name, help tab hidden, login branding on with the site logo', array( 'wp_logo' ) === $ac['hide_admin_bar_elements'] && 'Hotel Alfa' === $ac['admin_footer_text'] && true === $ac['hide_help_tab'] && true === $ac['login_customization_enabled'] && 77 === $ac['login_logo'] );
$assert( 'hide WordPress: the WordPress version in the admin footer is hidden too', true === $ac['hide_footer_version'] );
$assert( 'hide WordPress: it no longer decides about the quick search (own item)', ! array_key_exists( 'quick_search_enabled', $ac ) );
$qs = array_values( array_filter( $items, static fn( $i ) => 'quick-search' === $i['id'] ) )[0] ?? array();
$assert( 'quick search: its own item, checked by default, switches the quick search on', ! empty( $qs ) && ! empty( $qs['checked'] ) && true === ( $qs['settings']['admin-customiser']['quick_search_enabled'] ?? null ) && 1 === count( $qs['settings']['admin-customiser'] ) );
$an = array_values( array_filter( $items, static fn( $i ) => 'admin-notices' === $i['id'] ) )[0] ?? array();
$assert( 'admin notices: its own item, checked by default, moves the notices into the bell panel (relocate, not hide)', ! empty( $an ) && ! empty( $an['checked'] ) && true === ( $an['settings']['admin-customiser']['hide_notices_enabled'] ?? null ) && true === ( $an['settings']['admin-customiser']['hide_notices_relocate'] ?? null ) && 2 === count( $an['settings']['admin-customiser'] ) );
$assert( 'hide WordPress: generator and head links in Security', true === $hw['settings']['security-optimization']['hide_wp_head_links'] && true === $hw['settings']['security-optimization']['hide_wp_version'] );
$nl = $by_id( array() )['hide-wordpress'];
$assert( 'hide WordPress: no logo = no login_logo key and a warning', ! isset( $nl['settings']['admin-customiser']['login_logo'] ) && in_array( 'no_logo', array_column( $nl['warnings'], 'code' ), true ) );
$assert( 'hide WordPress: no logo = the login logo is hidden (form_options) with the login customization on', array( 'hide_login_logo' ) === $nl['settings']['admin-customiser']['form_options'] && true === $nl['settings']['admin-customiser']['login_customization_enabled'] );
$assert( 'hide WordPress: with a logo the login logo is not hidden', ! isset( $ac['form_options'] ) );
$assert( 'hide WordPress: the admin bar customization (Howdy) is not enabled', ! isset( $ac['admin_bar_enabled'] ) && ! isset( $ac['hide_howdy_name'] ) );
$plain = $by_id( array( 'site_name' => 'Hotel Alfa', 'login_logo_id' => 77, 'login_settings' => array( 'login_style' => 'classic', 'login_logo' => '', 'login_split_show_logo' => true ) ) )['hide-wordpress'];
$assert( 'hide WordPress: a plain login page gets the site logo AND an explicit visible note', 77 === $plain['settings']['admin-customiser']['login_logo'] && 'applied' === $plain['login_logo_state'] && in_array( 'login_logo_used', array_column( $plain['warnings'], 'code' ), true ) );
$cu = $by_id( array( 'site_name' => 'Hotel Alfa', 'login_logo_id' => 77, 'login_settings' => array( 'login_style' => 'split', 'login_customization_enabled' => true, 'login_logo' => 33 ) ) )['hide-wordpress'];
$cua = $cu['settings']['admin-customiser'];
$assert( 'hide WordPress: a customised login page keeps ALL login keys untouched', ! isset( $cua['login_logo'] ) && ! isset( $cua['login_customization_enabled'] ) && ! isset( $cua['form_options'] ) && 'custom' === $cu['login_logo_state'] );
$assert( 'hide WordPress: a customised login page still gets the other parts and an info note', array( 'wp_logo' ) === $cua['hide_admin_bar_elements'] && true === $cua['hide_help_tab'] && true === $cua['hide_footer_version'] && 'Hotel Alfa' === $cua['admin_footer_text'] && true === $cu['settings']['security-optimization']['hide_wp_head_links'] && in_array( 'login_customised', array_column( $cu['warnings'], 'code' ), true ) );
$cn = $by_id( array( 'login_settings' => array( 'login_style' => 'split' ) ) )['hide-wordpress'];
$assert( 'hide WordPress: customised page without a site logo does not get hide_login_logo either', ! isset( $cn['settings']['admin-customiser']['form_options'] ) );
$assert( 'head links: the six core callbacks are removed, none of them is below wp-content', 6 === count( HeadLinks::actions() ) && in_array( array( 'wp_head', 'rsd_link', 10 ), HeadLinks::actions(), true ) && in_array( array( 'template_redirect', 'rest_output_link_header', 11 ), HeadLinks::actions(), true ) );

/* ── .htaccess block removal (pure) ── */

$hw_cls = '\UxStudio\Modules\SecurityOptimization\HtaccessWriter';
$block  = "# BEGIN UX Studio Security\n# The directives (lines) between \"BEGIN UX Studio Security\" and \"END UX Studio Security\" are\n# dynamically generated.\n# END UX Studio Security";
$wp     = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress";
$assert( 'htaccess: an appended empty block is removed completely', $wp . "\n" === $hw_cls::strip_block( $wp . "\n\n" . $block . "\n" ) );
$assert( 'htaccess: a block in the middle goes with one separating blank line', $wp . "\n\nOptions -Indexes\n" === $hw_cls::strip_block( $wp . "\n\n" . $block . "\n\nOptions -Indexes\n" ) );
$assert( 'htaccess: a file without the block is returned unchanged', $wp === $hw_cls::strip_block( $wp ) );
$assert( 'htaccess: a file that held only the block becomes empty', '' === $hw_cls::strip_block( $block . "\n" ) );
$assert( 'htaccess: an unterminated block is left alone', $wp . "\n# BEGIN UX Studio Security\nx" === $hw_cls::strip_block( $wp . "\n# BEGIN UX Studio Security\nx" ) );

/* ── Bot Throttle ── */

$default_black = StarterPack::default_env()['bot_blacklist'];
$black         = StarterPack::bot_blacklist( $default_black );
$lines         = explode( "\n", $black );
$assert( 'bots: Ahrefs and Semrush are not on the blacklist', ! preg_grep( '/ahrefs|semrush/i', $lines ), $black );
$assert( 'bots: the other crawlers stay on it', array( 'MJ12bot', 'DotBot', 'PetalBot', 'BLEXBot', 'MegaIndex', 'SerpstatBot', 'rogerbot', 'DataForSeoBot' ) === $lines, $black );
$assert( 'bots: removed names are reported', array( 'AhrefsBot', 'SemrushBot' ) === StarterPack::bots_removed( $default_black ) );
$assert( 'bots: other Ahrefs and Semrush user agents are removed too', '' === StarterPack::bot_blacklist( "AhrefsSiteAudit\nSemrushBot-BA, semrushbot-sa" ) );
$assert( 'bots: Google and Bing are never touched by the list', ! preg_grep( '/google|bing/i', $lines ) );
$white = StarterPack::bot_whitelist( '' );
$assert( 'bots: the whitelist gets Ahrefs and Semrush, an existing entry is kept and nothing is doubled', "AhrefsBot\nSemrushBot" === $white && "MyMonitor\nAhrefsBot\nSemrushBot" === StarterPack::bot_whitelist( 'MyMonitor' ) && $white === StarterPack::bot_whitelist( $white ) );
$bt = $by_id( array() )['bot-throttle']['settings']['bot-throttle'];
$assert( 'bots: preset is enabled, 120 requests a minute, lists as above', true === $bt['enabled'] && 120 === $bt['requests_per_minute'] && $black === $bt['blacklist_ua'] && $white === $bt['whitelist_ua'] );

/* ── The other items ── */

$all = $by_id( array() );
$assert( 'classic: editor classic with user choice, plus widgets', array( 'default_editor' => 'classic', 'allow_users' => true ) === $all['classic']['settings']['classic-editor'] && array( 'classic-editor', 'classic-widgets' ) === $all['classic']['modules'] );
$assert( 'profiles: color scheme, shortcuts, bio and website hidden; e-mail is never hidden', array( 'user-admin-color-wrap', 'user-comment-shortcuts-wrap', 'user-description-wrap', 'user-url-wrap' ) === $all['clean-profiles']['settings']['clean-profiles']['fields'] && ! isset( $all['clean-profiles']['settings']['clean-profiles']['sections'] ) );
$assert( 'dashboard: notes and recent activity on, PageSpeed (needs a key) off with a note', true === $all['dashboard-widgets']['settings']['dashboard-widgets']['widget_notes'] && true === $all['dashboard-widgets']['settings']['dashboard-widgets']['widget_user_activity'] && ! isset( $all['dashboard-widgets']['settings']['dashboard-widgets']['widget_pagespeed'] ) && in_array( 'pagespeed_key', array_column( $all['dashboard-widgets']['warnings'], 'code' ), true ) );
$assert( 'duplicate: posts as drafts, plus menus', 'draft' === $all['duplicate']['settings']['duplicate-post']['post_status'] && array( 'duplicate-post', 'duplicate-menu' ) === $all['duplicate']['modules'] );
$assert( 'image optimizer: WebP, quality 82, no .htaccess delivery, uploads untouched', array( 'quality' => 82, 'convert_to_webp' => true, 'htaccess_delivery' => false ) === $all['image-optimizer']['settings']['image-optimizer'] );
$assert( 'activity log keeps 90 days', 90 === $all['activity-log']['settings']['activity-log']['retention_days'] );
$assert( 'update e-mails item switches the Security setting on', array( 'suppress_update_emails' => true ) === $all['update-emails']['settings']['security-optimization'] );
$assert( 'e-mail log keeps 14 days (pre-checked)', 14 === $all['email-log']['settings']['email-log']['retention_days'] && ! $all['email-log']['optional'] && $all['email-log']['checked'] );
$assert( 'last login is pre-checked', ! $all['user-last-login']['optional'] && $all['user-last-login']['checked'] );
$assert( 'SMTP has no preset and warns about credentials', array() === $all['smtp-email']['settings'] && in_array( 'smtp_credentials', array_column( $all['smtp-email']['warnings'], 'code' ), true ) );
$assert( 'hide admin bar: only roles that exist (subscriber, customer)', array( 'subscriber' ) === $all['hide-admin-bar']['settings']['hide-admin-bar']['roles'] && array( 'subscriber', 'customer' ) === $by_id( array( 'roles' => array( 'administrator', 'customer', 'subscriber' ) ) )['hide-admin-bar']['settings']['hide-admin-bar']['roles'] );
$assert( 'hide admin bar: no such role = unavailable', ! $by_id( array( 'roles' => array( 'administrator' ) ) )['hide-admin-bar']['available'] );

/* ── Overrides ── */

$item = $all['activity-log'];
$over = StarterPack::apply_overrides( $item, array( 'activity-log' => array( 'retention_days' => 30, 'alert_email' => 'x@y.cz', 'nonsense' => 1 ) ) );
$assert( 'overrides change a preset key only', 30 === $over['settings']['activity-log']['retention_days'] && 1 === count( $over['settings']['activity-log'] ) );
$assert( 'overrides ignore other modules, arrays of objects and junk', $item === StarterPack::apply_overrides( $item, array( 'evil' => array( 'x' => 1 ) ) ) && $item === StarterPack::apply_overrides( $item, 'x' ) && $item === StarterPack::apply_overrides( $item, array( 'activity-log' => array( 'retention_days' => new stdClass() ) ) ) );

/* ── Server families and the preset in words ── */

$assert( 'server family', 'apache' === StarterPack::server_family( 'Apache/2.4.58 (Win64)' ) && 'litespeed' === StarterPack::server_family( 'LiteSpeed' ) && 'nginx' === StarterPack::server_family( 'nginx/1.25' ) && 'other' === StarterPack::server_family( '' ) );
$assert( 'value labels: toggle, select, multiselect, text', 'On' === Applier::value_label( true, array() ) && 'Off' === Applier::value_label( false, array( 'type' => 'toggle' ) ) && 'Slowed down (60 s)' === Applier::value_label( 'slow', array( 'options' => array( 'slow' => 'Slowed down (60 s)' ) ) ) && 'Posts, Pages' === Applier::value_label( array( 'post', 'page' ), array( 'options' => array( 'post' => 'Posts', 'page' => 'Pages' ) ) ) && '10' === Applier::value_label( 10, array() ) );
$assert( 'login probe: only a 200 with the login form counts', Applier::probe_verdict( 200, '<form name="loginform" id="loginform">' ) && ! Applier::probe_verdict( 404, '<form id="loginform">' ) && ! Applier::probe_verdict( 200, '<html>home</html>' ) && ! Applier::probe_verdict( 301, '' ) );

/* ── Update e-mail filters ── */

$assert( 'update e-mails: plugin and theme mails are never sent', false === UpdateEmails::decide_plugin_theme( true ) && false === UpdateEmails::filter_plugin_theme( true ) );
$assert( 'update e-mails: core success mail is dropped', false === UpdateEmails::decide_core( true, 'success' ) && false === UpdateEmails::filter_core( true, 'success' ) );
$assert( 'update e-mails: core fail, critical and manual mails still arrive', true === UpdateEmails::decide_core( true, 'fail' ) && true === UpdateEmails::decide_core( true, 'critical' ) && true === UpdateEmails::decide_core( true, 'manual' ) );
$assert( 'update e-mails: a mail WordPress did not want stays unwanted', false === UpdateEmails::decide_core( false, 'fail' ) && false === UpdateEmails::decide_core( false, 'success' ) );


/* ── Login hardening, feeds, .htaccess rules, enumeration, author slugs ── */

$warn_codes = static fn( array $items ): array => array_column( $items['security-hardening']['warnings'], 'code' );
$clean      = $by_id( array() );
$sc         = $sec( $clean );
$assert( 'clean site: login hardening keys are all on', true === $sc['limit_login_attempts'] && true === $sc['block_usernames'] && true === $sc['block_username_attempts'] && true === $sc['protect_login'] );
$assert( 'clean site: feeds, file rules, listing, enumeration and author slugs are on', true === $sc['disable_rss_feeds'] && true === $sc['protect_wp_config'] && true === $sc['disable_directory_listing'] && true === $sc['block_sensitive_files'] && true === $sc['block_user_enumeration'] && true === $sc['hide_author_slugs'] );
$assert( 'clean site: no warning at all', array() === $clean['security-hardening']['warnings'] );

$px = $sec( $xf );
$assert( 'proxy without Cloudflare: reserved username keys follow the limiter out, protect_login stays', ! isset( $px['block_usernames'] ) && ! isset( $px['block_username_attempts'] ) && true === $px['protect_login'] && in_array( 'username_block_needs_limit', $warn_codes( $xf ), true ) );
$assert( 'Cloudflare: reserved username keys are on', true === $sec( $cf )['block_username_attempts'] );
$lb = $sec( $by_id( array( 'blocked_settings' => array( 'limit_login_attempts' => array( 'Loginizer' ) ) ) ) );
$assert( 'a plugin does the login limit: both username keys are left out too', ! isset( $lb['block_usernames'] ) && ! isset( $lb['block_username_attempts'] ) && true === $lb['protect_login'] );

$rv = $by_id( array( 'reserved_users' => array( 'admin', 'tester' ) ) );
$assert( 'an existing reserved username: block_username_attempts is left out, block_usernames stays, warning names the accounts', ! isset( $sec( $rv )['block_username_attempts'] ) && true === $sec( $rv )['block_usernames'] && in_array( 'reserved_user_exists', $warn_codes( $rv ), true ) && false !== strpos( $rv['security-hardening']['warnings'][0]['message'], 'admin, tester' ) );

$ap = $by_id( array( 'app_passwords' => array( 'users' => 2, 'passwords' => 3 ) ) );
$assert( 'application passwords exist: protect_login is left out, with a warning', ! isset( $sec( $ap )['protect_login'] ) && in_array( 'app_passwords_in_use', $warn_codes( $ap ), true ) );
$assert( 'application passwords: the warning carries the counts', false !== strpos( $ap['security-hardening']['warnings'][0]['message'], 'passwords: 3, users: 2' ) );
$combo = $by_id( array( 'xff' => true, 'reserved_users' => array( 'root' ), 'app_passwords' => array( 'users' => 1, 'passwords' => 1 ) ) );
$assert( 'combo: proxy + reserved user + application passwords leaves out the limiter, both username keys and protect_login', ! isset( $sec( $combo )['limit_login_attempts'] ) && ! isset( $sec( $combo )['block_usernames'] ) && ! isset( $sec( $combo )['block_username_attempts'] ) && ! isset( $sec( $combo )['protect_login'] ) && true === $sec( $combo )['disable_xmlrpc'] );
$pl = $by_id( array( 'blocked_settings' => array( 'protect_login' => array( 'X' ) ) ) );
$assert( 'a plugin does the same (blocked_settings): protect_login is reported with its plugin', in_array( 'blocked_feature', $warn_codes( $pl ), true ) && ! isset( $sec( $pl )['protect_login'] ) );

// RSS feeds.
$f6 = $by_id( array( 'published_posts' => 6 ) );
$f5 = $by_id( array( 'published_posts' => 5 ) );
$assert( 'feeds: more than 5 published posts leaves them on, with a warning', ! isset( $sec( $f6 )['disable_rss_feeds'] ) && in_array( 'feeds_blog', $warn_codes( $f6 ), true ) );
$assert( 'feeds: exactly 5 posts still switches them off', true === $sec( $f5 )['disable_rss_feeds'] );
$fp = $by_id( array( 'feed_plugins' => array( 'Mailchimp for WP' ) ) );
$assert( 'feeds: an active feed plugin keeps them, the warning names it', ! isset( $sec( $fp )['disable_rss_feeds'] ) && in_array( 'feeds_plugin', $warn_codes( $fp ), true ) && false !== strpos( $fp['security-hardening']['warnings'][0]['message'], 'Mailchimp for WP' ) );
$assert( 'feeds: a plugin that disables feeds already (blocked_settings) is respected', ! isset( $sec( $by_id( array( 'blocked_settings' => array( 'disable_rss_feeds' => array( 'Disable Feeds' ) ) ) ) )['disable_rss_feeds'] ) );
$assert( 'feed plugin list is small and holds the known slugs', isset( StarterPack::FEED_PLUGINS['mailchimp-for-wp'], StarterPack::FEED_PLUGINS['powerpress'], StarterPack::FEED_PLUGINS['seriously-simple-podcasting'] ) && ! isset( StarterPack::FEED_PLUGINS['jetpack'] ) );

// .htaccess rules.
$hk      = array( 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files' );
$present = static fn( array $set ): array => array_values( array_intersect( $hk, array_keys( $set ) ) );
$assert( 'Apache with a writable .htaccess: all three rules are in', $hk === $present( $sec( $by_id( array( 'server' => 'apache' ) ) ) ) );
$assert( 'LiteSpeed: the rules are in', $hk === $present( $sec( $by_id( array( 'server' => 'litespeed' ) ) ) ) );
foreach ( array( 'nginx', 'iis', 'other' ) as $srv ) {
	$o = $by_id( array( 'server' => $srv ) );
	$assert( "$srv: no .htaccess rule, warning says to block in the server configuration", array() === $present( $sec( $o ) ) && in_array( 'htaccess_rules_unsupported', $warn_codes( $o ), true ) );
}
$ur = $by_id( array( 'server' => 'apache', 'htaccess_writable' => false ) );
$assert( 'unwritable .htaccess: no rule, warning explains, headers stay (PHP fallback)', array() === $present( $sec( $ur ) ) && in_array( 'htaccess_rules_unwritable', $warn_codes( $ur ), true ) && true === $sec( $ur )['security_headers'] );

// User enumeration and author slugs.
$hl = $by_id( array( 'headless_plugins' => array( 'WPGraphQL' ) ) );
$assert( 'headless front end: users stay readable over REST, warning names the plugin; author slugs unaffected', ! isset( $sec( $hl )['block_user_enumeration'] ) && in_array( 'enumeration_headless', $warn_codes( $hl ), true ) && true === $sec( $hl )['hide_author_slugs'] );
$assert( 'author slugs: more than 5 posts leaves them off with a warning, enumeration stays on', ! isset( $sec( $f6 )['hide_author_slugs'] ) && in_array( 'author_slugs_blog', $warn_codes( $f6 ), true ) && true === $sec( $f6 )['block_user_enumeration'] );
$assert( 'author slugs: the same threshold as the feeds', true === $sec( $f5 )['hide_author_slugs'] && 5 === StarterPack::BLOG_POSTS_LIMIT );
$assert( 'headless plugin list is small', 3 === count( StarterPack::HEADLESS_PLUGINS ) && isset( StarterPack::HEADLESS_PLUGINS['wp-graphql'] ) );
$assert( 'stop-user-enumeration style plugin (blocked_settings) is respected', ! isset( $sec( $by_id( array( 'blocked_settings' => array( 'block_user_enumeration' => array( 'Stop User Enumeration' ) ) ) ) )['block_user_enumeration'] ) );

// Item text.
$desc = $clean['security-hardening']['description'];
foreach ( array( 'RSS', 'wp-config.php', 'readme.html', 'directory listing', '?author=N', 'reserved username', 'Application Passwords', 'Security > Login', 'users sitemap', 'author page', 'Permissions-Policy', 'https://', 'HSTS', 'canonical URL redirect' ) as $needle ) {
	$assert( "item text mentions: $needle", false !== stripos( $desc, $needle ) );
}

// Every new key is in the schema, with a label.
$module_code = (string) file_get_contents( $root . '/includes/Modules/SecurityOptimization/Module.php' );
foreach ( array( 'block_usernames', 'block_username_attempts', 'protect_login', 'disable_rss_feeds', 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files', 'block_user_enumeration', 'hide_author_slugs' ) as $key ) {
	$assert( "schema has $key with a label", 1 === preg_match( "/'key'\s*=>\s*'" . $key . "'[^;]*?'label'\s*=>\s*__\(/s", $module_code ) );
}

// .htaccess rule text (Apache 2.4 without mod_access_compat must not get a 500).
$rules = implode( "\n", ( new HtaccessWriter() )->build_rules( array( 'protect_wp_config' => true, 'block_sensitive_files' => true, 'disable_directory_listing' => true ) ) );
$assert( 'rules: Apache 2.4 syntax sits in mod_authz_core', false !== strpos( $rules, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>" ) );
$assert( 'rules: Apache 2.2 fallback sits in the negated check', false !== strpos( $rules, "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>" ) );
$assert( 'rules: no bare Order/Deny outside the module checks', 0 === preg_match( '/^(Order|Deny|order|deny)/m', preg_replace( '#<IfModule !mod_authz_core\.c>.*?</IfModule>#s', '', $rules ) ) );
$assert( 'rules: wp-config, its backups, readme, license and sample are covered, three blocks of both syntaxes', 3 === substr_count( $rules, 'Require all denied' ) && 3 === substr_count( $rules, 'Deny from all' ) && false !== strpos( $rules, '<Files wp-config.php>' ) && false !== strpos( $rules, 'readme\.html|license\.txt|wp-config-sample\.php' ) && false !== strpos( $rules, 'Options -Indexes' ) );

// Loopback safety net: decisions.
$assert( 'guard: a 500 on the home page or on the login rolls back', HtaccessGuard::should_roll_back( array( 'home' => 500, 'login' => 200 ) ) && HtaccessGuard::should_roll_back( array( 'home' => 200, 'login' => 503 ) ) );
$assert( 'guard: 200, 301, 403, 404 do not roll back', ! HtaccessGuard::should_roll_back( array( 'home' => 200, 'login' => 301 ) ) && ! HtaccessGuard::should_roll_back( array( 'home' => 403, 'login' => 404 ) ) );
$assert( 'guard: an impossible loopback is unknown, not a rollback', ! HtaccessGuard::should_roll_back( array( 'home' => 0, 'login' => 0 ) ) && ! HtaccessGuard::was_checked( array( 'home' => 0, 'login' => 0 ) ) && HtaccessGuard::was_checked( array( 'home' => 0, 'login' => 200 ) ) );
$assert( 'guard retry: the directory listing is dropped first, the rest stays', array( 'protect_wp_config', 'block_sensitive_files' ) === HtaccessGuard::retry_keys( array( 'protect_wp_config', 'disable_directory_listing', 'block_sensitive_files' ) ) );
$assert( 'guard retry: nothing to drop means giving up on the group (null)', null === HtaccessGuard::retry_keys( array( 'protect_wp_config', 'block_sensitive_files' ) ) && array() === HtaccessGuard::retry_keys( array( 'disable_directory_listing' ) ) );
$assert( 'guard verify: refused is 403 or 404, a listing is recognised', HtaccessGuard::is_refused( 403 ) && HtaccessGuard::is_refused( 404 ) && ! HtaccessGuard::is_refused( 200 ) && HtaccessGuard::is_listing( 200, '<html><head><title>Index of /wp-content/uploads</title>' ) && ! HtaccessGuard::is_listing( 200, '<html>hello' ) && ! HtaccessGuard::is_listing( 403, 'Index of' ) );
$assert( 'guard verify: enumeration is blocked with 401/403, an empty list, no author redirect', HtaccessGuard::enumeration_blocked( '', 401, '' ) && HtaccessGuard::enumeration_blocked( 'https://x.test/', 200, '[]' ) && ! HtaccessGuard::enumeration_blocked( 'https://x.test/author/jan/', 401, '' ) && ! HtaccessGuard::enumeration_blocked( '', 200, '[{"id":1,"slug":"jan"}]' ) );

// Rollback against a copy of an .htaccess file (never the real one).
$scratch = 'D:/xampp/htdocs/_uxs-scratch';
@mkdir( $scratch, 0777, true );
$tmp  = $scratch . '/htaccess-test-' . getmypid();
$orig = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
file_put_contents( $tmp, $orig );
$copy = HtaccessGuard::read_file( $tmp );
file_put_contents( $tmp, $orig . "\n# BEGIN UX Studio Security\nOptions -Indexes\n# END UX Studio Security\n" );
$assert( 'rollback: the copy is the original file content', $orig === $copy );
$assert( 'rollback: restore puts the original bytes back', HtaccessGuard::restore_file( $tmp, $copy ) && $orig === file_get_contents( $tmp ) );
$assert( 'rollback: a file that did not exist is removed again', HtaccessGuard::restore_file( $tmp, null ) && ! is_file( $tmp ) && null === HtaccessGuard::read_file( $tmp ) );


/* ── Permissions-Policy, forced https, explanations ── */

$assert( 'clean site: Permissions-Policy and forced https are on', true === $sc['enable_permissions_policy'] && true === $sc['force_https'] );
$assert( 'canonical redirect and HSTS stay off (not in the pack)', ! isset( $sc['canonical_redirect'] ) && ! isset( $sc['enable_hsts'] ) );
$ppw = $by_id( array( 'woocommerce' => true ) );
$assert( 'WooCommerce: no Permissions-Policy (payments), warning says so', ! isset( $sec( $ppw )['enable_permissions_policy'] ) && in_array( 'permissions_policy_woo', $warn_codes( $ppw ), true ) );
$ppp = $by_id( array( 'header_plugins' => array( 'Really Simple SSL' ) ) );
$assert( 'a header plugin: no Permissions-Policy, warning names it', ! isset( $sec( $ppp )['enable_permissions_policy'] ) && in_array( 'permissions_policy_plugin', $warn_codes( $ppp ), true ) && false !== strpos( $ppp['security-hardening']['warnings'][0]['message'], 'Really Simple SSL' ) );
$assert( 'header plugin list is small', 2 === count( StarterPack::HEADER_PLUGINS ) );

$nh = $by_id( array( 'https_ready' => false ) );
$assert( 'http site or http request: forced https left out with a warning', ! isset( $sec( $nh )['force_https'] ) && in_array( 'https_not_ready', $warn_codes( $nh ), true ) && true === $sec( $nh )['protect_wp_config'] );
$hp = $by_id( array( 'xff' => true ) );
$assert( 'unknown proxy: forced https left out', ! isset( $sec( $hp )['force_https'] ) && in_array( 'https_proxy', $warn_codes( $hp ), true ) );
$assert( 'Cloudflare (also with X-Forwarded-For): forced https stays, the loop check protects it', true === $sec( $by_id( array( 'cloudflare' => true, 'xff' => true ) ) )['force_https'] );
$assert( 'nginx: forced https goes with the .htaccess rules', ! isset( $sec( $by_id( array( 'server' => 'nginx' ) ) )['force_https'] ) );
$assert( 'forced https is a guarded .htaccess key', in_array( 'force_https', StarterPack::HTACCESS_KEYS, true ) );

// Redirect loop decision.
$assert( 'loop: the same address twice', HtaccessGuard::redirect_loop( array( 'https://x.test/', 'https://x.test/a', 'https://x.test/' ) ) );
$assert( 'loop: trailing slash and case do not hide it', HtaccessGuard::redirect_loop( array( 'https://X.test/a', 'https://x.test/a/' ) ) );
$assert( 'no loop: http -> https', ! HtaccessGuard::redirect_loop( array( 'http://x.test/', 'https://x.test/' ) ) );
$assert( 'loop: more than 5 hops', HtaccessGuard::redirect_loop( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g' ) ) && ! HtaccessGuard::redirect_loop( array( 'a', 'b', 'c', 'd', 'e', 'f' ) ) );
$assert( 'https failed: a loop or a 5xx; a plain 200 is fine', HtaccessGuard::https_failed( array( 'a', 'b', 'a' ), 301 ) && HtaccessGuard::https_failed( array( 'a' ), 500 ) && ! HtaccessGuard::https_failed( array( 'a', 'b' ), 200 ) && ! HtaccessGuard::https_failed( array( 'a' ), 0 ) );
$assert( 'force_https rule text is the HTTPS-off rewrite', false !== strpos( implode( "\n", ( new HtaccessWriter() )->build_rules( array( 'force_https' => true ) ) ), 'RewriteCond %{HTTPS} off' ) );

// Explanations: every setting of the pack has the three parts (and the skip rule), no empty text.
use UxStudio\Modules\SecurityOptimization\SettingHelp;
$every = array();
foreach ( array( array( 'woocommerce' => true, 'cloudflare' => true ), array() ) as $e ) {
	foreach ( $by_id( $e )['security-hardening']['settings']['security-optimization'] as $key => $v ) {
		$every[ $key ] = true;
	}
}
$missing = array();
foreach ( array_keys( $every ) as $key ) {
	if ( in_array( $key, SettingHelp::PARAMETERS, true ) ) {
		continue;
	}
	$ex = SettingHelp::for_key( $key, true );
	if ( null === $ex || '' === trim( $ex['does'] ) || '' === trim( $ex['risk'] ) || '' === trim( $ex['off'] ) || '' === trim( (string) ( $ex['skip'] ?? '' ) ) ) {
		$missing[] = $key;
	}
}
$assert( 'every setting of the security item has what it does, the risk, when to switch it off and when it is left out', array() === $missing, implode( ',', $missing ) );
$assert( 'the schema explanation has no skip rule, the wizard one has', ! array_key_exists( 'skip', (array) SettingHelp::for_key( 'protect_login' ) ) && array_key_exists( 'skip', (array) SettingHelp::for_key( 'protect_login', true ) ) );
$assert( 'the risk of application passwords is stated', false !== stripos( SettingHelp::for_key( 'protect_login' )['risk'], 'Application Password' ) );
$assert( 'the risks that were promised are stated honestly', false !== stripos( SettingHelp::for_key( 'disable_directory_listing' )['risk'], '500' ) && false !== stripos( SettingHelp::for_key( 'force_https' )['risk'], 'Flexible' ) );
foreach ( array( 'security_headers', 'enable_permissions_policy', 'force_https', 'protect_login' ) as $key ) {
	$assert( "$key is in the schema code (so its help can carry the explanation)", false !== strpos( $module_code, "'key'     => '$key'" ) );
}
$assert( 'the REST layer attaches the shared SettingHelp registry to every option', false !== strpos( (string) file_get_contents( $root . '/includes/Core/Rest.php' ), 'SettingHelp::attach(' ) && is_file( $root . '/includes/Modules/SecurityOptimization/help.php' ) );

/* ── AutoStart: a new site gets the starter pack ── */

$req = array( 'flag' => 'pending', 'can_manage' => true, 'same_user' => true, 'count' => 0 );
$r   = static fn( array $over ): string => AutoStart::decide_redirect( $over + $req );
$assert( 'autostart: nothing to migrate -> new', 'new' === $r( array() ) );
$assert( 'autostart: something to migrate still goes to the migration wizard', 'go' === $r( array( 'count' => 2 ) ) );
$assert( 'autostart: no handoff, no history, no bulk activation, no earlier starter pack for the new-site offer', 'drop' === $r( array( 'handoff' => true ) ) && 'drop' === $r( array( 'ran' => true ) ) && 'drop' === $r( array( 'activate_multi' => true ) ) && 'drop' === $r( array( 'starter_applied' => true ) ) );
$assert( 'autostart: AJAX, other administrator and spent flag behave as before', 'wait' === $r( array( 'ajax' => true ) ) && 'wait' === $r( array( 'same_user' => false ) ) && 'none' === $r( array( 'flag' => 'done' ) ) );
$assert( 'autostart: the new-site path is the wizard in mode=new', 'admin.php?page=ux-studio#/wizard?mode=new' === AutoStart::NEW_PATH );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
