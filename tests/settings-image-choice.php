<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the `image_choice` field type: the server validates it like a select.
 *
 *   php tests/settings-image-choice.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/Core/Settings.php';

use UxStudio\Core\Settings;

$fails = 0;
function check( $name, $actual, $expected ) {
	global $fails;
	$ok = $actual === $expected;
	$fails += $ok ? 0 : 1;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : ' => got ' . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) ) . "\n";
}

$schema = array(
	array( 'key' => 'template', 'type' => 'image_choice', 'options' => array( 'classic' => 'Classic', 'slim' => 'Slim' ), 'images' => array( 'classic' => 'a.svg' ), 'default' => 'classic' ),
	array( 'key' => 'page', 'type' => 'image_choice', 'options' => array( 12 => 'Twelve' ), 'default' => '' ),
);
check( 'a listed key is kept', Settings::clean( array( 'template' => 'slim' ), $schema )['template'], 'slim' );
check( 'an unknown key falls back to the default', Settings::clean( array( 'template' => 'evil' ), $schema )['template'], 'classic' );
check( 'an empty value falls back to the default', Settings::clean( array( 'template' => '' ), $schema )['template'], 'classic' );
check( 'an array falls back to the default', Settings::clean( array( 'template' => array( 'slim' ) ), $schema )['template'] ?? null, 'classic' );
check( 'integer option keys match a string value', Settings::clean( array( 'page' => '12' ), $schema )['page'], '12' );
check( 'a missing key is not written', array_key_exists( 'template', Settings::clean( array(), $schema ) ), false );

echo $fails ? "$fails failed\n" : "OK\n";
exit( $fails ? 1 : 0 );
