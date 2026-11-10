<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the unified dashboard widget table (PLAN.md 20.12): the catalog of the widgets of UX Studio, the
 * starting state of a new installation, the default-hidden widgets that appear later, the classification of every row
 * (type, owner, switch, state) and the switches the REST layer accepts.
 *
 *   php tests/dashboard-widgets-unified.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) { return $text; }
}

require_once dirname( __DIR__ ) . '/includes/Modules/DashboardWidgets/WidgetRules.php';
require_once dirname( __DIR__ ) . '/includes/Modules/DashboardWidgets/WidgetCatalog.php';

use UxStudio\Modules\DashboardWidgets\WidgetCatalog as C;
use UxStudio\Modules\DashboardWidgets\WidgetRules as W;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$j = static fn( $v ): string => (string) json_encode( $v );

// --- The starting state of a new installation (the owner's wish) ------------------------------------------------------
$start = C::new_install();
$on    = array( 'widget_recent_media', 'widget_tasks', 'widget_notes', 'widget_user_activity', 'widget_service_requests', 'widget_blocked_users', 'widget_forms', 'widget_bot_throttle', 'widget_dns_manager', 'widget_ai_panel' );
$off   = array( 'widget_disk_usage', 'widget_recent_posts', 'widget_pagespeed', 'widget_recent_forms', 'widget_analytics', 'widget_recent_orders', 'widget_top_products' );
foreach ( $on as $key ) {
	$assert( "new install: $key on", true === ( $start['toggles'][ $key ] ?? null ) );
}
foreach ( $off as $key ) {
	$assert( "new install: $key off", false === ( $start['toggles'][ $key ] ?? null ) );
}
$assert( 'new install: every switch is decided', count( $start['toggles'] ) === count( $on ) + count( $off ), (string) count( $start['toggles'] ) );
$hidden = array_keys( $start['rules'] );
sort( $hidden );
$want = array( 'dashboard_primary', 'dashboard_site_health', 'dashboard_welcome', 'uxstudio_notes' );
$assert( 'new install: hidden at once', $hidden === $want, $j( $hidden ) );
$assert( 'new install: hidden for everybody', ! array_filter( $start['rules'], static fn( $r ) => ! $r['hidden'] || $r['roles'] ) );
$assert( 'new install: Yoast is pending, not in the rules', $start['pending'] === array( 'wpseo-wincher-dashboard-overview', 'wpseo-dashboard-overview' ) && ! isset( $start['rules']['wpseo-dashboard-overview'] ) );
$assert( 'new install: the rules survive sanitising', W::sanitize( $start['rules'], null ) === $start['rules'], $j( W::sanitize( $start['rules'], null ) ) );
$assert( 'notes are on and hidden at once', true === $start['toggles']['widget_notes'] && ( $start['rules']['uxstudio_notes']['hidden'] ?? false ) );

// --- Widgets that appear later ----------------------------------------------------------------------------------------
$hit = C::pending_hits( $start['pending'], array( 'dashboard_right_now', 'wpseo-dashboard-overview' ), $start['rules'] );
$assert( 'later: the seen widget gets hidden', true === ( $hit['rules']['wpseo-dashboard-overview']['hidden'] ?? false ) && $hit['changed'] );
$assert( 'later: the other stays pending', $hit['pending'] === array( 'wpseo-wincher-dashboard-overview' ), $j( $hit['pending'] ) );
$none = C::pending_hits( $hit['pending'], array( 'dashboard_right_now' ), $hit['rules'] );
$assert( 'later: nothing seen, nothing changes', ! $none['changed'] && $none['rules'] === $hit['rules'] && $none['pending'] === $hit['pending'] );
$kept = C::pending_hits( array( 'wpseo-dashboard-overview' ), array( 'wpseo-dashboard-overview' ), array( 'wpseo-dashboard-overview' => array( 'hidden' => false, 'roles' => array( 'editor' ) ) ) );
$assert( 'later: the decision of the owner wins', false === $kept['rules']['wpseo-dashboard-overview']['hidden'] && array() === $kept['pending'] );

// --- Catalog ----------------------------------------------------------------------------------------------------------
$entries = C::entries();
$keys    = C::toggle_keys();
$assert( 'catalog: switch keys are unique', count( $keys ) === count( array_unique( $keys ) ) );
$assert( 'catalog: ids are valid widget ids', ! array_filter( array_keys( $entries ), static fn( $id ) => '' === W::clean_id( $id ) ) );
$assert( 'clean_toggles drops unknown keys and casts', $j( C::clean_toggles( array( 'widget_tasks' => 0, 'widget_notes' => '1', 'evil' => true, 'widget_forms' => 'true' ) ) ) === '{"widget_tasks":false,"widget_notes":true,"widget_forms":true}', $j( C::clean_toggles( array( 'widget_tasks' => 0, 'widget_notes' => '1', 'evil' => true ) ) ) );
$assert( 'clean_toggles of junk', array() === C::clean_toggles( 'x' ) && array() === C::clean_toggles( null ) );

// --- Classification of the rows ----------------------------------------------------------------------------------------
$base   = static fn( string $id, string $source = 'uxstudio', string $name = '' ): array => array( 'id' => $id, 'title' => $id, 'source' => $source, 'source_name' => $name, 'stale' => false );
$values = $start['toggles'];
$names  = array( 'forms' => 'Form Builder', 'bot-throttle' => 'Bot Throttle' );
$env    = array( 'woocommerce' => false, 'elementor_pro' => false, 'ga' => false, 'pagespeed' => false );

$r = C::decorate( $base( 'dashboard_right_now', 'core' ), $values, $names, $env );
$assert( 'core widget is WordPress', C::TYPE_WORDPRESS === $r['type'] && null === $r['toggle'] && 'on' === $r['state'] );
$r = C::decorate( $base( 'wpseo-dashboard-overview', 'plugin:wordpress-seo', 'Yoast SEO' ), $values, $names, $env );
$assert( 'plugin widget names its plugin', C::TYPE_PLUGIN === $r['type'] && 'Yoast SEO' === $r['type_name'] && null === $r['toggle'] );
$r = C::decorate( $base( 'some_theme_box', 'theme:twentytwentyfive', 'Twenty Twenty-Five' ), $values, $names, $env );
$assert( 'theme widget counts as third party', C::TYPE_PLUGIN === $r['type'] && 'Twenty Twenty-Five' === $r['type_name'] );
$r = C::decorate( $base( 'mystery', 'unknown' ), $values, $names, $env );
$assert( 'unknown source is third party', C::TYPE_PLUGIN === $r['type'] && '' === $r['type_name'] );

$r = C::decorate( $base( 'uxstudio_tasks' ), $values, $names, $env );
$assert( 'own widget: type, switch on', C::TYPE_OWN === $r['type'] && 'on' === $r['state'] && true === $r['toggle']['enabled'] && 'widget_tasks' === $r['toggle']['key'] );
$r = C::decorate( $base( 'uxstudio_disk_usage' ), $values, $names, $env );
$assert( 'own widget: switched off', 'off' === $r['state'] && false === $r['toggle']['enabled'] );
$r = C::decorate( $base( 'uxstudio_pagespeed' ), array( 'widget_pagespeed' => true ), $names, $env );
$assert( 'own widget: needs a key, stays on', 'on' === $r['state'] && 'keys' === $r['note_tab'] && '' !== $r['note'] );
$r = C::decorate( $base( 'uxstudio_pagespeed' ), array( 'widget_pagespeed' => true ), $names, array( 'pagespeed' => true ) + $env );
$assert( 'own widget: key present, no note', '' === $r['note'] );
$r = C::decorate( $base( 'uxstudio_recent_forms' ), array( 'widget_recent_forms' => true ), $names, $env );
$assert( 'Elementor widget without Elementor Pro is unavailable', 'unavailable' === $r['state'] );

$r = C::decorate( $base( 'uxstudio_forms_widget' ), $values, $names, $env );
$assert( 'module widget: module and its name', C::TYPE_MODULE === $r['type'] && 'Form Builder' === $r['type_name'] && true === $r['module']['active'] && 'on' === $r['state'] );
$r = C::decorate( $base( 'uxstudio_dns_manager_widget' ), $values, $names, $env );
$assert( 'module widget: module off wins', 'module_off' === $r['state'] && false === $r['module']['active'] && 'DNS Management' === $r['type_name'], $j( $r ) );
$r = C::decorate( $base( 'uxstudio_bot_throttle_widget' ), array( 'widget_bot_throttle' => false ), $names, $env );
$assert( 'module widget: switched off with the module on', 'off' === $r['state'] );
$r = C::decorate( $base( 'uxstudio_woocommerce_recent_orders' ), array( 'widget_recent_orders' => true ), $names, $env );
$assert( 'WooCommerce widget without WooCommerce is unavailable', 'unavailable' === $r['state'] && '' === $r['module']['id'] );
$r = C::decorate( $base( 'uxstudio_woocommerce_recent_orders' ), array( 'widget_recent_orders' => true ), $names, array( 'woocommerce' => true ) + $env );
$assert( 'WooCommerce widget with WooCommerce is on', 'on' === $r['state'] );
$r = C::decorate( $base( 'uxstudio_csp_widget' ), $values, array( 'security-optimization' => 'Security' ) + $names, $env );
$assert( 'module widget without a switch follows its module', null === $r['toggle'] && 'on' === $r['state'] && C::TYPE_MODULE === $r['type'] );
$r = C::decorate( $base( 'uxstudio_something_new', 'uxstudio' ), $values, $names, $env );
$assert( 'unknown widget of UX Studio is a module widget', C::TYPE_MODULE === $r['type'] && 'UX Studio' === $r['type_name'] );
$r = C::decorate( array( 'id' => 'uxstudio_notes', 'title' => 'uxstudio_notes', 'source' => 'unknown', 'source_name' => '', 'stale' => true ), $values, $names, $env );
$assert( 'catalog title replaces a bare id, source becomes UX Studio', 'Notes' === $r['title'] && 'uxstudio' === $r['source'] );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
