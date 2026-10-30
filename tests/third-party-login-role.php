<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the Third-Party Login role gate: the role is checked BEFORE an account is created.
 *
 *   php tests/third-party-login-role.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
eval( 'namespace UxStudio\Modules { abstract class BaseModule {} } namespace { class WP_User {} }' );

require_once __DIR__ . '/../includes/Modules/ThirdPartyLogin/Module.php';

use UxStudio\Modules\ThirdPartyLogin\Module;

$fail = 0;
function check( $label, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n"; if ( ! $ok ) { ++$fail; } }

check( 'role on the allow-list passes', Module::any_role_allowed( array( 'subscriber' ), array( 'subscriber', 'editor' ) ) );
check( 'role off the allow-list is refused', ! Module::any_role_allowed( array( 'subscriber' ), array( 'editor' ) ) );
check( 'empty allow-list denies everyone (fail closed)', ! Module::any_role_allowed( array( 'subscriber' ), array() ) );
check( 'one of several roles is enough', Module::any_role_allowed( array( 'customer', 'editor' ), array( 'editor' ) ) );
check( 'no roles are refused', ! Module::any_role_allowed( array(), array( 'editor' ) ) );

// Order of the checks in handle_login(): the gate must come before wp_insert_user().
$src  = (string) file_get_contents( __DIR__ . '/../includes/Modules/ThirdPartyLogin/RestController.php' );
$gate = strpos( $src, 'is_role_allowed(' );
$ins  = strpos( $src, 'wp_insert_user(' );
check( 'handle_login() checks the role before it creates the account', false !== $gate && false !== $ins && $gate < $ins );

exit( $fail > 0 ? 1 : 0 );
