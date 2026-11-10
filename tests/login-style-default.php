<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * The default login screen layout is "split" for new setups, and a site that already had the login customisation
 * on keeps the classic layout it was showing (Upgrade::pin_login_style). Needs no WordPress:
 *
 *   php tests/login-style-default.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
define( 'UXSTUDIO_PATH', $root . '/' );
function __( $text, $domain = '' ) { return $text; }

$GLOBALS['uxs_options'] = array();
function get_option( $name, $default = false ) { return $GLOBALS['uxs_options'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['uxs_options'][ $name ] = $value; return true; }

require_once $root . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Onboarding\StarterPack;
use UxStudio\Core\Upgrade;
use UxStudio\Modules\AdminCustomiser\Login;

$failures = 0;
$assert   = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL;
};

$assert( 'the default layout is split', 'split' === Login::DEFAULT_STYLE );
$module_src = (string) file_get_contents( $root . '/includes/Modules/AdminCustomiser/Module.php' );
$assert( 'the login_style schema field takes its default from Login::DEFAULT_STYLE', 1 === preg_match( "/'key'\s*=>\s*'login_style'.*?'default'\s*=>\s*Login::DEFAULT_STYLE/s", $module_src ) );

$pin = static function ( $stored ): ?array {
	$GLOBALS['uxs_options'] = null === $stored ? array() : array( 'uxstudio_admin_customiser' => $stored );
	Upgrade::pin_login_style();
	return $GLOBALS['uxs_options']['uxstudio_admin_customiser'] ?? null;
};
$assert( 'upgrade: customisation on, no layout stored -> classic is kept', 'classic' === ( $pin( array( 'login_customization_enabled' => true, 'login_logo' => 5 ) )['login_style'] ?? null ) );
$assert( 'upgrade: other stored keys are untouched', 5 === ( $pin( array( 'login_customization_enabled' => '1', 'login_logo' => 5 ) )['login_logo'] ?? null ) );
$assert( 'upgrade: a stored layout is never changed (split stays split)', 'split' === ( $pin( array( 'login_customization_enabled' => true, 'login_style' => 'split' ) )['login_style'] ?? null ) );
$assert( 'upgrade: a stored classic stays classic', 'classic' === ( $pin( array( 'login_customization_enabled' => true, 'login_style' => 'classic' ) )['login_style'] ?? null ) );
$assert( 'upgrade: customisation off -> nothing stored, the site gets the new default', ! array_key_exists( 'login_style', $pin( array( 'login_customization_enabled' => false, 'login_logo' => 5 ) ) ?? array() ) );
$assert( 'upgrade: no option at all -> nothing written', null === $pin( null ) );

$env = static fn( array $over ): array => array_merge( StarterPack::default_env(), $over );
$admin = static function ( array $env ): array {
	foreach ( StarterPack::items( $env ) as $item ) {
		if ( 'hide-wordpress' === $item['id'] ) {
			return $item['settings']['admin-customiser'];
		}
	}
	return array();
};
$assert( 'starter pack: a plain login page gets the split layout', 'split' === ( $admin( $env( array( 'login_logo_id' => 77 ) ) )['login_style'] ?? null ) );
$assert( 'starter pack: also without a site logo', 'split' === ( $admin( $env( array() ) )['login_style'] ?? null ) );
$assert( 'starter pack: a customised login page keeps its layout (the key is not written)', ! array_key_exists( 'login_style', $admin( $env( array( 'login_settings' => array( 'login_customization_enabled' => true, 'login_style' => 'classic' ) ) ) ) ) );

echo PHP_EOL . ( $failures ? "$failures FAILED" : 'ALL PASSED' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
