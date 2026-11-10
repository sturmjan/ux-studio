<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test of the live banner preview endpoint inside WordPress (PLAN.md 42.18): POST consent/preview-config runs the
 * frontend config with UNSAVED values, leaves out everything that talks to the outside, writes nothing and needs
 * manage_options.
 *
 *   php wp-cli.phar --path=<site> --skip-plugins=ux-studio,wp-mail-smtp eval-file tests/consent-preview-wp.php </dev/null
 *
 * It temporarily writes the option uxstudio_consent and restores it at the end. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'UXSTUDIO_PATH' ) ) {
	define( 'UXSTUDIO_VERSION', 'test' );
	define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
	define( 'UXSTUDIO_URL', home_url( '/wp-content/plugins/ux-studio/' ) );
	require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
	\UxStudio\Autoloader::register();
}

use UxStudio\Modules\Consent\Module;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

$had = get_option( 'uxstudio_consent', null );
update_option( 'uxstudio_consent', array( 'title' => 'Stored title', 'layout' => 'box', 'display_to' => 'eu', 'legacy_prefix' => 'cmplz_', 'log_enabled' => true ) );

$meta   = json_decode( (string) file_get_contents( UXSTUDIO_PATH . 'includes/Modules/Consent/meta.json' ), true );
$module = new Module( 'consent', $meta );
$module->boot();
$GLOBALS['wp_rest_server'] = null;
rest_get_server();

$call = static function ( array $body ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/preview-config' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( $body ) );
	return rest_do_request( $request );
};

try {
	wp_set_current_user( 0 );
	$r = $call( array( 'values' => array( 'title' => 'x' ) ) );
	$assert( 'anonymous cannot call it', in_array( $r->get_status(), array( 401, 403 ), true ), (string) $r->get_status() );

	$subscriber = get_users( array( 'role' => 'subscriber', 'number' => 1 ) );
	if ( $subscriber ) {
		wp_set_current_user( $subscriber[0]->ID );
		$assert( 'a subscriber cannot call it', 403 === $call( array( 'values' => array() ) )->get_status() );
	}

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );

	$live = $module->frontend_config();
	$assert( 'the live config has the outside connections (so the next checks mean something)', isset( $live['geo'], $live['ck'], $live['leg'] ) && 'Stored title' === $live['t']['title'] );

	$r    = $call(
		array(
			'view'   => 'detail',
			'values' => array(
				'title'        => '</script><b>New</b> title',
				'btn_accept'   => 'Yes please',
				'layout'       => 'bar',
				'position'     => 'right',
				'show_reopen'  => false,
				'color_bg'     => '#112233',
				'color_accent' => 'red',
				'radius'       => 20,
				'nonsense'     => 'dropped',
			),
		)
	);
	$data = $r->get_data()['data'] ?? array();
	$cfg  = $data['config'] ?? array();
	$assert( 'admin gets 200 and the config', 200 === $r->get_status() && ! empty( $cfg ), (string) $r->get_status() );
	$assert( 'unsaved texts are used and sanitized like a save', ( $cfg['t']['title'] ?? '' ) === 'New title' && 'Yes please' === ( $cfg['t']['accept'] ?? '' ), (string) ( $cfg['t']['title'] ?? '' ) );
	$assert( 'unsaved layout, side and floating button are used', 'bar' === $cfg['layout'] && 'right' === $cfg['pos'] && false === $cfg['fab'] );
	$assert( 'unsaved colours and radius reach the CSS variables, an invalid colour is ignored', false !== strpos( $data['css'] ?? '', '--uxc-bg:#112233;' ) && false !== strpos( $data['css'], '--uxc-r:20px;' ) && false === strpos( $data['css'], '--uxc-ac:' ), (string) ( $data['css'] ?? '' ) );
	$assert( 'nothing that talks to the outside is in the preview config', ! isset( $cfg['rec'] ) && ! isset( $cfg['geo'] ) && ! isset( $cfg['leg'] ) && ! isset( $cfg['ck'] ), implode( ',', array_keys( $cfg ) ) );
	$assert( 'the preview flag carries the view', 'detail' === ( $cfg['preview'] ?? '' ) );
	$assert( 'assets point at the banner script and style', false !== strpos( (string) ( $data['assets']['js'] ?? '' ), 'assets/consent/consent' ) && false !== strpos( (string) ( $data['assets']['css'] ?? '' ), 'assets/consent/consent' ) );

	$assert( 'nothing was stored', 'Stored title' === get_option( 'uxstudio_consent' )['title'] && ! isset( get_option( 'uxstudio_consent' )['btn_accept'] ) );
	$after = $module->frontend_config();
	$assert( 'the live config is back to the stored values afterwards', 'Stored title' === $after['t']['title'] && 'box' === $after['layout'] && isset( $after['geo'] ) );

	$r = $call( array( 'view' => 'bogus', 'values' => array() ) );
	$assert( 'an unknown view is refused', 400 === $r->get_status(), (string) $r->get_status() );
	$r = $call( array( 'view' => 'ph', 'values' => array() ) );
	$assert( 'an empty values object gives the stored config', 'Stored title' === ( $r->get_data()['data']['config']['t']['title'] ?? '' ) && 'ph' === ( $r->get_data()['data']['config']['preview'] ?? '' ) );
} finally {
	if ( null === $had ) {
		delete_option( 'uxstudio_consent' );
	} else {
		update_option( 'uxstudio_consent', $had );
	}
}

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "ALL PASSED\n";
