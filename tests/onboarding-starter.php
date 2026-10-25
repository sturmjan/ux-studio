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
$assert( 'optional items: smtp, email log, redirects, media replace, last login, hide admin bar, CSP audit', array( 'smtp-email', 'email-log', 'redirects', 'media-replace', 'user-last-login', 'hide-admin-bar', 'csp-audit' ) === $optional, json_encode( $optional ) );
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

$never = array( 'ip_firewall_enabled', 'country_blocklist_enabled', 'blocked_countries', 'force_https', 'canonical_redirect', 'enable_hsts', 'restrict_rest_api', 'protect_login', 'block_user_enumeration', 'two_factor_enabled', 'two_factor_required_roles' );
$found = array();
foreach ( StarterPack::items( array( 'woocommerce' => true, 'cloudflare' => true ) ) as $item ) {
	foreach ( (array) ( $item['settings']['security-optimization'] ?? array() ) as $key => $value ) {
		if ( in_array( $key, $never, true ) ) {
			$found[] = $key;
		}
	}
}
$assert( 'forced 2FA, IP firewall, country block, HTTPS forcing, HSTS, REST restriction, login hardening and user enumeration block are never set', array() === $found, implode( ',', $found ) );
$assert( 'CSP is never enforced; the optional item only inventories (audit, 20 percent)', array( 'csp_mode' => 'audit', 'csp_sample_rate' => 20 ) === $by_id( array() )['csp-audit']['settings']['security-optimization'] && ! $by_id( array() )['csp-audit']['checked'] );

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
$assert( 'hide WordPress: the quick search that comes with the module is switched off', false === $ac['quick_search_enabled'] );
$assert( 'hide WordPress: generator and head links in Security', true === $hw['settings']['security-optimization']['hide_wp_head_links'] && true === $hw['settings']['security-optimization']['hide_wp_version'] );
$nl = $by_id( array() )['hide-wordpress'];
$assert( 'hide WordPress: no logo = no login_logo key and a warning', ! isset( $nl['settings']['admin-customiser']['login_logo'] ) && in_array( 'no_logo', array_column( $nl['warnings'], 'code' ), true ) );
$assert( 'hide WordPress: the admin bar customization (Howdy) is not enabled', ! isset( $ac['admin_bar_enabled'] ) && ! isset( $ac['hide_howdy_name'] ) );
$assert( 'head links: the six core callbacks are removed, none of them is below wp-content', 6 === count( HeadLinks::actions() ) && in_array( array( 'wp_head', 'rsd_link', 10 ), HeadLinks::actions(), true ) && in_array( array( 'template_redirect', 'rest_output_link_header', 11 ), HeadLinks::actions(), true ) );

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
$assert( 'e-mail log keeps 14 days (optional)', 14 === $all['email-log']['settings']['email-log']['retention_days'] && $all['email-log']['optional'] );
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
