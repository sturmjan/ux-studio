<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Language per user (post-audit plan, package 0): REST requests of uxstudio/v1 are answered in the locale of the
 * logged-in user, not of the site. Run against a site whose language is NOT English with the plugin loaded (the
 * local pobyty is cs_CZ):
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\rest-locale-wp.php </dev/null
 *
 * Checks module names and descriptions (meta.json), setting labels and help, the plugin text domain, that the site
 * locale is back after every request, that other namespaces and anonymous requests are left alone and that nested
 * requests stay balanced. Temp users are removed again.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

require_once ABSPATH . 'wp-admin/includes/user.php';

$site_locale = get_locale();
if ( 0 === strpos( $site_locale, 'en_' ) ) {
	WP_CLI::warning( 'The site language is English: the test needs a non-English site to prove anything.' );
}
if ( ! is_file( UXSTUDIO_PATH . 'languages/ux-studio-cs_CZ.mo' ) ) {
	WP_CLI::error( 'languages/ux-studio-cs_CZ.mo is missing.' );
}

$make = static function ( string $tag, string $locale ): int {
	$uid = wp_insert_user(
		array(
			'user_login' => 'uxs_loc_' . $tag . '_' . wp_rand( 1000, 9999 ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => "uxs-loc-$tag-" . wp_rand( 1000, 9999 ) . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	update_user_meta( $uid, 'locale', $locale );
	return (int) $uid;
};
$en = $make( 'en', 'en_US' );
$cs = $make( 'cs', 'cs_CZ' );

$get = static function ( string $route ) {
	$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	return $response->get_data();
};

try {
	// English administrator on the (Czech) site.
	wp_set_current_user( $en );
	$modules = $get( '/uxstudio/v1/modules' );
	$names   = array_column( $modules['data'] ?? array(), 'name', 'id' );
	$assert( 'English user: module name in English', 'Activity Log' === ( $names['activity-log'] ?? '' ), (string) ( $names['activity-log'] ?? '(none)' ) );
	$assert( 'the site locale is back after the request', $site_locale === get_locale(), get_locale() );
	$assert( 'the plugin text domain is the site one again', 'Log aktivit' === __( 'Activity Log', 'ux-studio' ) || 0 === strpos( $site_locale, 'en_' ), __( 'Activity Log', 'ux-studio' ) );

	$settings = $get( '/uxstudio/v1/settings/security-optimization' );
	$labels   = array_column( $settings['data']['schema'] ?? array(), 'label', 'key' );
	$assert( 'English user: setting labels in English', array() !== $labels && ! preg_match( '/[áčďéěíňóřšťúůýž]/iu', implode( ' ', array_slice( $labels, 0, 8 ) ) ), implode( ' | ', array_slice( $labels, 0, 3 ) ) );
	$helps = array_filter( array_column( $settings['data']['schema'] ?? array(), 'explain' ) );
	$does  = (string) ( reset( $helps )['does'] ?? '' );
	$assert( 'English user: explanation (help.php) in English', '' !== $does && ! preg_match( '/[áčďéěíňóřšťúůýž]/iu', $does ), $does );

	// Czech administrator.
	wp_set_current_user( $cs );
	$modules = $get( '/uxstudio/v1/modules' );
	$names   = array_column( $modules['data'] ?? array(), 'name', 'id' );
	$assert( 'Czech user: module name in Czech', 'Log aktivit' === ( $names['activity-log'] ?? '' ), (string) ( $names['activity-log'] ?? '(none)' ) );
	$settings = $get( '/uxstudio/v1/settings/security-optimization' );
	$helps    = array_filter( array_column( $settings['data']['schema'] ?? array(), 'explain' ) );
	$does     = (string) ( reset( $helps )['does'] ?? '' );
	$assert( 'Czech user: explanation (help.php) in Czech', '' !== $does && (bool) preg_match( '/[áčďéěíňóřšťúůýž]/iu', $does ), $does );
	$assert( 'the site locale is back (Czech user)', $site_locale === get_locale(), get_locale() );

	// The two users in a row: the Security help cache is per locale, not stuck on the first one.
	wp_set_current_user( $en );
	$settings = $get( '/uxstudio/v1/settings/security-optimization' );
	$helps    = array_filter( array_column( $settings['data']['schema'] ?? array(), 'explain' ) );
	$assert( 'English again after Czech: explanation not stuck in Czech', ! preg_match( '/[áčďéěíňóřšťúůýž]/iu', (string) ( reset( $helps )['does'] ?? 'á' ) ) );

	// Glossary follows the locale of the page (boot data), checked on the PHP side.
	$assert( 'glossary has the terms of the plan', count( \UxStudio\Core\Glossary::terms() ) >= 40 && null !== \UxStudio\Core\Glossary::get( '[[REST API]]' ) && null !== \UxStudio\Core\Glossary::get( 'cron' ) && null === \UxStudio\Core\Glossary::get( 'no such term' ) );

	// Anonymous: no switch (the stack stays balanced).
	wp_set_current_user( 0 );
	$response = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/modules' ) );
	$assert( 'anonymous request is refused, locale untouched', $response->get_status() >= 400 && $site_locale === get_locale() );

	// Another namespace: not switched.
	wp_set_current_user( $en );
	$seen = array();
	add_filter(
		'rest_pre_dispatch',
		static function ( $result ) use ( &$seen ) {
			$seen[] = get_locale();
			return $result;
		},
		50
	);
	rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types' ) );
	$assert( 'a request of another namespace runs in the site locale', array( $site_locale ) === $seen, implode( ',', $seen ) );
	$assert( 'locale balanced at the end', $site_locale === get_locale() );
} finally {
	wp_set_current_user( 0 );
	foreach ( array( $en, $cs ) as $uid ) {
		wp_delete_user( $uid );
	}
}

WP_CLI::log( $failures ? "$failures FAILED" : 'ALL PASSED' );
if ( $failures ) {
	exit( 1 );
}
