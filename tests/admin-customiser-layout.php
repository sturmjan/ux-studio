<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the Admin Customiser layout (cards + `show_if`): every condition points at a field of the same
 * schema, uses one valid operator with values the controlling field can really have, there is no loop, every planned
 * key exists once, and the layout changes nothing about a field but its place, card and condition.
 *
 *   php tests/admin-customiser-layout.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = '' ) { return $text; }
function get_post_types( $args = array(), $output = 'names' ) {
	$o = new stdClass();
	$o->name   = 'page';
	$o->labels = (object) array( 'name' => 'Pages' );
	return array( 'page' => $o );
}

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\AdminCustomiser\Module;
use UxStudio\Modules\AdminCustomiser\SettingsLayout;

$failures = 0;
function check( string $name, bool $ok, string $detail = '' ): void {
	global $failures;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
}

$schema = ( new Module( 'admin-customiser', array() ) )->settings_schema();
$by_key = array();
foreach ( $schema as $f ) {
	$by_key[ $f['key'] ] = $f;
}
check( 'the schema has the known keys', isset( $by_key['login_split_media_type'], $by_key['admin_bar_enabled'], $by_key['server_clock_position'] ) );
check( 'keys are unique', count( $by_key ) === count( $schema ) );

// 1. Every condition is valid.
$bad   = array();
$count = 0;
foreach ( $schema as $f ) {
	foreach ( (array) ( $f['show_if'] ?? array() ) as $c ) {
		++$count;
		$where = $f['key'] . ' -> ' . ( $c['key'] ?? '?' );
		if ( ! is_array( $c ) || ! isset( $c['key'] ) || ! isset( $by_key[ $c['key'] ] ) ) {
			$bad[] = $where . ' (unknown key)';
			continue;
		}
		if ( $c['key'] === $f['key'] ) {
			$bad[] = $where . ' (itself)';
		}
		$ops = array_values( array_intersect( array( 'equals', 'in', 'truthy' ), array_keys( $c ) ) );
		if ( 1 !== count( $ops ) || count( $c ) !== 2 ) {
			$bad[] = $where . ' (needs exactly one of equals / in / truthy)';
			continue;
		}
		$ctl = $by_key[ $c['key'] ];
		if ( 'truthy' === $ops[0] && ! is_bool( $c['truthy'] ) ) {
			$bad[] = $where . ' (truthy is a bool)';
		}
		$options = array_map( 'strval', array_keys( (array) ( $ctl['options'] ?? array() ) ) );
		if ( 'in' === $ops[0] ) {
			if ( ! is_array( $c['in'] ) || array() === $c['in'] || ( $options && array_diff( array_map( 'strval', $c['in'] ), $options ) ) ) {
				$bad[] = $where . ' (in must list real options)';
			}
		}
		if ( 'equals' === $ops[0] && ( ! $options || ! in_array( (string) $c['equals'], $options, true ) ) ) {
			$bad[] = $where . ' (equals must be a real option)';
		}
		if ( 'truthy' === $ops[0] && ! in_array( $ctl['type'], array( 'toggle', 'multiselect' ), true ) ) {
			$bad[] = $where . ' (truthy on a ' . $ctl['type'] . ')';
		}
	}
}
check( 'there are conditions to check (' . $count . ')', $count > 15 );
check( 'every show_if is valid and points at a real field of the schema', array() === $bad, implode( '; ', $bad ) );

// 2. The real option values the login media type fields rely on.
$types = array_keys( $by_key['login_split_media_type']['options'] );
sort( $types );
check( 'media types are image / video / youtube', array( 'image', 'video', 'youtube' ) === $types, implode( ',', $types ) );
$cond = static function ( string $key ) use ( $by_key ): array {
	return (array) ( $by_key[ $key ]['show_if'] ?? array() );
};
$has  = static function ( string $key, string $ctl, string $op, $val ) use ( $cond ): bool {
	foreach ( $cond( $key ) as $c ) {
		if ( $c['key'] === $ctl && isset( $c[ $op ] ) && $c[ $op ] === $val ) {
			return true;
		}
	}
	return false;
};
check( 'mp4 field only for video', $has( 'login_split_media_video_url', 'login_split_media_type', 'equals', 'video' ) );
check( 'YouTube field only for youtube', $has( 'login_split_media_youtube_url', 'login_split_media_type', 'equals', 'youtube' ) );
check( 'image / poster field for image and video', $has( 'login_split_media_image', 'login_split_media_type', 'in', array( 'image', 'video' ) ) );
check( 'media fields only in the split layout', $has( 'login_split_media_type', 'login_style', 'equals', 'split' ) && $has( 'login_split_show_logo', 'login_style', 'equals', 'split' ) );
check( 'background image only in the classic layout', $has( 'login_background_image', 'login_style', 'equals', 'classic' ) );
check( 'login settings need the login switch', $has( 'login_style', 'login_customization_enabled', 'truthy', true ) && $has( 'login_button_bg_color', 'login_customization_enabled', 'truthy', true ) );
check( 'the login switch itself is never hidden', array() === $cond( 'login_customization_enabled' ) );
check( 'quick search types need quick search', $has( 'quick_search_post_types', 'quick_search_enabled', 'truthy', true ) );
check( 'clock position needs the clock', $has( 'server_clock_position', 'server_clock_enabled', 'truthy', true ) );
check( 'notice options need hide notices', $has( 'hide_notices_relocate', 'hide_notices_enabled', 'truthy', true ) && $has( 'global__update_notifications', 'hide_notices_enabled', 'truthy', true ) );
check( 'menu lock and horizontal merge need the organizer', $has( 'menu_lock_enabled', 'menu_organizer_enabled', 'truthy', true ) && $has( 'horizontal_menu_inline', 'menu_organizer_enabled', 'truthy', true ) );
check( 'admin bar tidy-up options need their switch', $has( 'hide_howdy_name', 'admin_bar_enabled', 'truthy', true ) && $has( 'keep_on_bar_ids', 'consolidate_plugin_actions', 'truthy', true ) );
check( 'bar colors do not depend on the tidy-up switch (they work without it)', array() === $cond( 'admin_bar_bg_color' ) );

// 3. No loop of conditions.
$loop = array();
foreach ( array_keys( $by_key ) as $start ) {
	$seen  = array( $start );
	$queue = array( $start );
	while ( $queue ) {
		$k = array_shift( $queue );
		foreach ( $cond( $k ) as $c ) {
			if ( $c['key'] === $start ) {
				$loop[] = $start;
			} elseif ( ! in_array( $c['key'], $seen, true ) ) {
				$seen[]  = $c['key'];
				$queue[] = $c['key'];
			}
		}
	}
}
check( 'conditions never form a loop', array() === $loop, implode( ',', $loop ) );

// 4. The plan.
$planned = array();
$dupes   = array();
foreach ( SettingsLayout::plan() as $g ) {
	foreach ( $g['fields'] as $k => $v ) {
		$key = is_int( $k ) ? $v : $k;
		if ( isset( $planned[ $key ] ) ) {
			$dupes[] = $key;
		}
		$planned[ $key ] = $g['title'];
	}
	check( 'card "' . $g['title'] . '" has a one-line description', '' !== $g['help'] && false === strpos( $g['help'], "\n" ) );
}
check( 'no field is planned twice', array() === $dupes, implode( ',', $dupes ) );
check( 'every planned key exists in the schema', array() === array_diff( array_keys( $planned ), array_keys( $by_key ) ), implode( ',', array_diff( array_keys( $planned ), array_keys( $by_key ) ) ) );
$ungrouped = array_keys( array_filter( $by_key, static fn( $f ) => empty( $f['group'] ) ) );
check( 'every field is in a card', array() === $ungrouped, implode( ',', $ungrouped ) );
$tab_of = array();
foreach ( $schema as $f ) {
	$tab_of[ $f['group'] ?? '' ][ $f['section'] ] = true;
}
$mixed = array();
foreach ( $tab_of as $group => $tabs ) {
	if ( count( $tabs ) > 1 ) {
		$mixed[] = $group . ' (' . implode( ', ', array_keys( $tabs ) ) . ')';
	}
}
check( 'a card never mixes fields of two tabs', array() === $mixed, implode( '; ', $mixed ) );

// 5. apply() changes the place, card and condition only (synthetic schema, order scrambled).
$raw = array();
foreach ( array_reverse( $schema ) as $f ) {
	unset( $f['group'], $f['group_help'], $f['show_if'] );
	$raw[] = $f;
}
$again = SettingsLayout::apply( $raw );
check( 'apply keeps the number of fields', count( $again ) === count( $raw ) );
$changed = array();
foreach ( $again as $f ) {
	$cmp = $f;
	$org = $by_key[ $f['key'] ];
	unset( $cmp['group'], $cmp['group_help'], $cmp['show_if'], $org['group'], $org['group_help'], $org['show_if'] );
	if ( $cmp !== $org ) {
		$changed[] = $f['key'];
	}
}
check( 'apply leaves type, label, help, options and default untouched', array() === $changed, implode( ',', $changed ) );
check( 'apply gives the planned order whatever order it is given (planned fields first)', array_slice( array_column( $again, 'key' ), 0, 10 ) === array_slice( array_column( $schema, 'key' ), 0, 10 ) );
$unknown = SettingsLayout::apply( array( array( 'key' => 'zzz_future', 'type' => 'text', 'label' => 'x', 'default' => '' ) ) );
check( 'a field that is not planned stays, without a card', 1 === count( $unknown ) && ! isset( $unknown[0]['group'] ) && ! isset( $unknown[0]['show_if'] ) );

if ( $failures ) {
	echo "\nFAILED: $failures\n";
	exit( 1 );
}
echo "\nALL PASSED\n";
