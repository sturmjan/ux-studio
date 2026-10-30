<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Help coverage of the module settings (post-audit plan, package 0).
 *
 * Walks the settings schema of EVERY module and fails for a field that has no `help` line, no inline `explain` and no
 * entry in the module's help.php (Core\SettingHelp). Fields that still lack a text are listed in
 * tests/fixtures/help-baseline.json ({ "module-id": ["key", ...] }); the baseline only shrinks:
 *   - a field without help that is NOT in the baseline fails (new settings come with their text),
 *   - a baseline entry whose field now has help fails ("remove it from the baseline"),
 *   - a baseline entry for a field the schema does not contain here (an option that depends on the site, for example
 *     WooCommerce) is only reported, so the result does not depend on the plugins of the machine.
 *
 * The only exempt fields are colour pickers and the per-role "hidden meta boxes" lists inside a card whose heading
 * already has a description (the audit rated those "OK": the card explains them).
 *
 * Needs WordPress (schemas use core functions); with a bare WP the plugin does not have to be loaded:
 *
 *   cd D:\xampp\htdocs\pobyty
 *   D:/xampp/wp-cli/wp.bat --skip-plugins --skip-themes eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\setting-help-coverage-wp.php </dev/null
 *
 * To print the current list of fields without help (a fresh baseline) set the environment variable
 * UXS_REGENERATE=1; it only prints the JSON, it never writes a file.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Modules;
use UxStudio\Core\SettingHelp;

$root = dirname( __DIR__ );
if ( ! defined( 'UXSTUDIO_PATH' ) ) {
	define( 'UXSTUDIO_VERSION', 'test' );
	define( 'UXSTUDIO_API_VERSION', 1 );
	define( 'UXSTUDIO_DB_VERSION', 2 );
	define( 'UXSTUDIO_FILE', $root . '/ux-studio.php' );
	define( 'UXSTUDIO_PATH', $root . '/' );
	define( 'UXSTUDIO_URL', 'http://localhost/ux-studio/' );
	require_once $root . '/includes/Autoloader.php';
	\UxStudio\Autoloader::register();
}

$say = static function ( string $line ): void {
	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::log( $line );
	} else {
		echo $line . PHP_EOL;
	}
};

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures, $say ): void {
	if ( ! $ok ) {
		++$failures;
	}
	$say( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$baseline_file = $root . '/tests/fixtures/help-baseline.json';
$baseline      = is_file( $baseline_file ) ? json_decode( (string) file_get_contents( $baseline_file ), true ) : array();
$baseline      = is_array( $baseline ) ? $baseline : array();
$regenerate    = '' !== (string) getenv( 'UXS_REGENERATE' );

// A private registry over every module (nothing is booted, no hook runs).
$modules = new Modules();
$scan    = new ReflectionMethod( Modules::class, 'scan' );
$scan->setAccessible( true );
$link = new ReflectionMethod( Modules::class, 'link_groups' );
$link->setAccessible( true );
$meta_prop = new ReflectionProperty( Modules::class, 'meta' );
$meta_prop->setAccessible( true );
$meta_prop->setValue( $modules, $link->invoke( null, $scan->invoke( null ) ) );

$present = array(); // module => key => has help?
$exempt  = 0;
foreach ( array_keys( $modules->all() ) as $id ) {
	$meta = $modules->all()[ $id ];
	if ( ! empty( $meta['virtual'] ) ) {
		continue;
	}
	try {
		$instance = $modules->instance( (string) $id );
		$schema   = $instance ? $instance->settings_schema() : array();
	} catch ( \Throwable $e ) {
		$assert( "schema of $id loads", false, $e->getMessage() );
		continue;
	}
	// Cards whose heading carries a description.
	$described = array();
	foreach ( $schema as $field ) {
		if ( ! empty( $field['group'] ) && ! empty( $field['group_help'] ) ) {
			$described[ (string) $field['group'] ] = true;
		}
	}
	foreach ( $schema as $field ) {
		$key = (string) ( $field['key'] ?? '' );
		if ( '' === $key ) {
			continue;
		}
		// Colour pickers and the per-role "hidden meta boxes" lists (one field per role of the site, so the keys depend on
		// the site) are explained by the heading of their card.
		$card_explains = 'color' === ( $field['type'] ?? '' ) || 0 === strpos( $key, 'hidden_metaboxes_' );
		if ( $card_explains && ! empty( $field['group'] ) && isset( $described[ (string) $field['group'] ] ) ) {
			++$exempt;
			continue;
		}
		$present[ $id ][ $key ] = SettingHelp::has_help( (string) $id, $field );
	}
}

$missing = array();
foreach ( $present as $id => $keys ) {
	foreach ( $keys as $key => $has ) {
		if ( ! $has ) {
			$missing[ $id ][] = $key;
		}
	}
}
ksort( $missing );
foreach ( $missing as $id => $keys ) {
	sort( $keys );
	$missing[ $id ] = $keys;
}

if ( $regenerate ) {
	$say( wp_json_encode( $missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return;
}

$total    = array_sum( array_map( 'count', $present ) );
$no_help  = array_sum( array_map( 'count', $missing ) );
$in_base  = array_sum( array_map( 'count', $baseline ) );
$say( sprintf( '%d fields in %d modules, %d exempt colours, %d without help, %d in the baseline', $total, count( $present ), $exempt, $no_help, $in_base ) );
$assert( 'the schemas of the modules were read', $total > 300 );

$new = array();
foreach ( $missing as $id => $keys ) {
	foreach ( $keys as $key ) {
		if ( ! in_array( $key, (array) ( $baseline[ $id ] ?? array() ), true ) ) {
			$new[] = "$id/$key";
		}
	}
}
$assert(
	'every field has a help text (or is in the baseline)',
	array() === $new,
	'add a line to help.php (docs/help-texts.md) for: ' . implode( ', ', array_slice( $new, 0, 25 ) ) . ( count( $new ) > 25 ? ' (+' . ( count( $new ) - 25 ) . ' more)' : '' )
);

$stale = array();
$gone  = array();
foreach ( $baseline as $id => $keys ) {
	foreach ( (array) $keys as $key ) {
		if ( isset( $present[ $id ][ $key ] ) ) {
			if ( $present[ $id ][ $key ] ) {
				$stale[] = "$id/$key";
			}
		} else {
			$gone[] = "$id/$key";
		}
	}
}
$assert(
	'the baseline holds only fields that still lack help',
	array() === $stale,
	'remove from tests/fixtures/help-baseline.json: ' . implode( ', ', array_slice( $stale, 0, 25 ) ) . ( count( $stale ) > 25 ? ' (+' . ( count( $stale ) - 25 ) . ' more)' : '' )
);
if ( $gone ) {
	$say( 'NOTE ' . count( $gone ) . ' baseline entries are not in the schema of this site (site-dependent or removed): ' . implode( ', ', array_slice( $gone, 0, 12 ) ) );
}

// The registry itself.
$assert( 'Security help comes from help.php through the shared registry', null !== SettingHelp::for( 'security-optimization', 'captcha_enabled' ) && isset( SettingHelp::for( 'security-optimization', 'captcha_enabled' )['effect'] ) );
$assert( 'the starter pack skip part is hidden unless asked for', ! isset( SettingHelp::for( 'security-optimization', 'captcha_enabled' )['skip'] ) && isset( SettingHelp::for( 'security-optimization', 'captcha_enabled', true )['skip'] ) );
$inline = SettingHelp::attach( 'no-such-module', array( array( 'key' => 'x', 'explain' => array( 'does' => 'D', 'effect' => 'E', 'risk' => 'R', 'bogus' => 'B' ) ) ) );
$assert( 'an inline explain is normalised and kept', array( 'does' => 'D', 'effect' => 'E', 'risk' => 'R' ) === ( $inline[0]['explain'] ?? null ) );
$assert( 'a field without help gets no explain', ! isset( SettingHelp::attach( 'no-such-module', array( array( 'key' => 'y' ) ) )[0]['explain'] ) );
$assert( 'an unsafe module id never reaches the file system', null === SettingHelp::for( '../etc', 'x' ) );
add_filter(
	'uxstudio_setting_help',
	static function ( $parts, string $module, string $key ) {
		return 'filter-mod' === $module && 'k' === $key ? array( 'does' => 'From the filter.' ) : $parts;
	},
	10,
	3
);
$assert( 'the uxstudio_setting_help filter can add help', 'From the filter.' === ( SettingHelp::for( 'filter-mod', 'k' )['does'] ?? '' ) );

$say( $failures ? "$failures FAILED" : 'ALL PASSED' );
if ( $failures ) {
	exit( 1 );
}
