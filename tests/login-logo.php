<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the login logo rules: when the starter pack must leave a custom login page alone
 * (StarterPack::login_already_customised) and when the split layout badge may repeat the logo
 * (Login::brand_icon_logo_url). Needs no WordPress:
 *
 *   php tests/login-logo.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
function __( $text, $domain = '' ) { return $text; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Onboarding\StarterPack;
use UxStudio\Modules\AdminCustomiser\Login;

$failures = 0;
$assert   = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
};

$c = static fn( array $s ): bool => StarterPack::login_already_customised( $s );

$assert( 'empty settings are not customised', ! $c( array() ) );
$defaults = array(
	'login_customization_enabled' => false, 'login_style' => 'classic', 'login_logo' => '', 'login_background_image' => 0,
	'login_split_media_type' => 'video', 'login_split_media_image' => '', 'login_split_media_video_url' => '',
	'login_split_media_youtube_url' => '', 'login_split_show_logo' => true, 'login_custom_css' => '', 'login_message' => '',
	'login_background_color' => '', 'login_button_bg_color' => '',
);
$assert( 'the schema defaults are not customised', ! $c( $defaults ) );
$assert( 'switch (login_customization_enabled) on', $c( array( 'login_customization_enabled' => true ) ) && $c( array( 'login_customization_enabled' => '1' ) ) );
$assert( 'layout split', $c( array( 'login_style' => 'split' ) ) );
$assert( 'layout classic alone is not a trigger', ! $c( array( 'login_style' => 'classic' ) ) );
$assert( 'login_logo as id and as legacy array', $c( array( 'login_logo' => 33 ) ) && $c( array( 'login_logo' => array( 33 ) ) ) && $c( array( 'login_logo' => '33' ) ) );
$assert( 'login_logo 0 / empty array is not a trigger', ! $c( array( 'login_logo' => 0 ) ) && ! $c( array( 'login_logo' => array() ) ) );
$assert( 'background image', $c( array( 'login_background_image' => 5 ) ) );
$assert( 'split media image', $c( array( 'login_split_media_image' => 5 ) ) );
$assert( 'split video / YouTube address', $c( array( 'login_split_media_video_url' => 'https://x.test/a.mp4' ) ) && $c( array( 'login_split_media_youtube_url' => 'abc' ) ) );
$assert( 'custom CSS (whitespace only is not)', $c( array( 'login_custom_css' => 'body{}' ) ) && ! $c( array( 'login_custom_css' => "  \n" ) ) );
$assert( 'message above the form', $c( array( 'login_message' => 'Hi' ) ) );
$assert( 'any login color', $c( array( 'login_background_color' => '#fff' ) ) && $c( array( 'login_input_border_color' => '#000' ) ) );
$assert( 'a color of another area is not a login color', ! $c( array( 'primary_color' => '#fff' ) ) );

$env = array( 'login_logo_id' => 77 );
$assert( 'state: plain page + site logo = applied', 'applied' === StarterPack::login_logo_state( $env + array( 'login_settings' => $defaults ) ) );
$assert( 'state: plain page + no logo = hidden', 'hidden' === StarterPack::login_logo_state( array( 'login_logo_id' => 0, 'login_settings' => array() ) ) );
$assert( 'state: customised page = custom', 'custom' === StarterPack::login_logo_state( $env + array( 'login_settings' => array( 'login_logo' => 33 ) ) ) );

$b = static fn( bool $over, string $url ): string => Login::brand_icon_logo_url( $over, $url );
$assert( 'badge: logo over the media -> badge has no logo image', '' === $b( true, 'https://x.test/l.png' ) );
$assert( 'badge: logo not over the media -> badge shows it', 'https://x.test/l.png' === $b( false, 'https://x.test/l.png' ) );
$assert( 'badge: no logo at all -> empty either way', '' === $b( true, '' ) && '' === $b( false, '' ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
