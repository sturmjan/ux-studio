<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * "Harden the login page" (protect_login): only the errors that reveal whether the username or the password was wrong
 * become generic; the lockout of the limiter, CAPTCHA, two-step verification and any other message keep their text.
 * Read only (no setting is saved), run against a real site (the local pobyty):
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\security-login-errors-wp.php
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Modules\SecurityOptimization\Module;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$ref    = new ReflectionClass( Module::class );
$make   = static fn(): Module => $ref->newInstanceWithoutConstructor();
$generic = __( 'Invalid login credentials. Please check both the username and the password.', 'ux-studio' );

$m = $make();
$e = new WP_Error( 'incorrect_password', 'The password you entered for the username <strong>jan</strong> is incorrect.' );
$m->generic_credential_errors( $e );
$assert( 'wrong password becomes the generic message, the detail is gone', array( 'uxstudio_invalid_credentials' ) === $e->get_error_codes() && $generic === $e->get_error_message() );

$e = new WP_Error( 'invalid_username', 'Unknown username.' );
$m->generic_credential_errors( $e );
$assert( 'unknown username becomes the generic message', array( 'uxstudio_invalid_credentials' ) === $e->get_error_codes() );

$e = new WP_Error( 'uxstudio_ip_blocked', 'Too many failed login attempts from this IP. Please try again in 15 minutes.' );
$m->generic_credential_errors( $e );
$assert( 'limiter lockout message is kept', array( 'uxstudio_ip_blocked' ) === $e->get_error_codes() && false !== strpos( $e->get_error_message(), '15 minutes' ) );

$e = new WP_Error( 'uxstudio_attempts_exceeded', 'Too many failed login attempts. Please try again in 15 minutes.' );
$m->generic_credential_errors( $e );
$assert( 'limiter attempts message is kept', array( 'uxstudio_attempts_exceeded' ) === $e->get_error_codes() );

$e = new WP_Error( 'incorrect_password', 'detail' );
$e->add( 'uxstudio_ip_blocked', 'Locked for 15 minutes.' );
$m->generic_credential_errors( $e );
$assert( 'instant ban (block_username_attempts) shows the lockout, not the detail', in_array( 'uxstudio_ip_blocked', $e->get_error_codes(), true ) && ! in_array( 'incorrect_password', $e->get_error_codes(), true ) && in_array( 'uxstudio_invalid_credentials', $e->get_error_codes(), true ) );

foreach ( array( 'uxstudio_2fa_non_form', 'uxstudio_2fa_setup_required', 'uxstudio_captcha_failed', 'uxstudio_verify_required' ) as $code ) {
	$e = new WP_Error( $code, 'Own message of ' . $code );
	$m->generic_credential_errors( $e );
	$assert( "$code message is kept", array( $code ) === $e->get_error_codes() && 'Own message of ' . $code === $e->get_error_message() );
}

$assert( 'a non WP_Error value passes through untouched', 'x' === $m->generic_credential_errors( 'x' ) );

$fresh = $make();
$assert( 'string filter without wp_login_errors: generic text (fallback)', $generic === $fresh->sanitize_login_errors( 'anything' ) );
$done = $make();
$done->generic_credential_errors( new WP_Error( 'uxstudio_ip_blocked', 'x' ) );
$assert( 'string filter after wp_login_errors: the message is not replaced', 'Locked.' === $done->sanitize_login_errors( 'Locked.' ) );

WP_CLI::log( $failures ? "$failures FAILED" : 'ALL PASSED' );
if ( $failures ) {
	WP_CLI::halt( 1 );
}
