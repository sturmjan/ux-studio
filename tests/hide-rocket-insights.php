<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the "Hide Rocket Insights (WP Rocket)" option of the Admin Customiser: the column filter,
 * the hook registration, and the setting in the module schema (default off, portable through Transfer).
 *
 *   php tests/hide-rocket-insights.php
 *
 * Needs no WordPress and no database. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

$GLOBALS['uxs_filters'] = array();
$GLOBALS['uxs_actions'] = array();
function __( $text, $domain = '' ) { return $text; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_filters'][ $hook ][] = array( $cb, $prio ); return true; }
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['uxs_actions'][ $hook ][] = array( $cb, $prio ); return true; }
function get_post_types( $args = array(), $output = 'names' ) {
	$o = new stdClass();
	$o->name   = 'page';
	$o->labels = (object) array( 'name' => 'Pages' );
	return array( 'page' => $o );
}
class WP_Screen {
	public $base = '';
	public $post_type = '';
}

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Modules\AdminCustomiser\HideRocketInsights;
use UxStudio\Modules\AdminCustomiser\Module;
use UxStudio\Core\Transfer\NonPortable;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── Column filter ── */

$cols = array( 'cb' => '', 'title' => 'Title', 'rocket_insights' => 'Rocket Insights', 'date' => 'Date' );
$out  = HideRocketInsights::strip_column( $cols );
$assert( 'removes only rocket_insights', array( 'cb', 'title', 'date' ) === array_keys( $out ) );
$assert( 'keeps the labels of the other columns', 'Title' === $out['title'] && 'Date' === $out['date'] );
$assert( 'leaves a list without the column untouched', array( 'a' => 1, 'b' => 2 ) === HideRocketInsights::strip_column( array( 'a' => 1, 'b' => 2 ) ) );
$assert( 'does not fail on a non-array', 'x' === HideRocketInsights::strip_column( 'x' ) && null === HideRocketInsights::strip_column( null ) );
$assert( 'works for the sortable list as well', array( 'title' => array( 'title', false ) ) === HideRocketInsights::strip_column( array( 'title' => array( 'title', false ), 'rocket_insights' => array( 'rocket_insights', false ) ) ) );

/* ── Hook registration ── */

HideRocketInsights::register();
$f = $GLOBALS['uxs_filters']['rocket_rocket_insights_enabled'][0] ?? null;
$assert( 'official WP Rocket filter is used with a late priority', $f && '__return_false' === $f[0] && $f[1] > 10 );
$assert( 'current_screen is hooked', ! empty( $GLOBALS['uxs_actions']['current_screen'] ) );

$GLOBALS['uxs_filters'] = array();
$screen = new WP_Screen();
$screen->base = 'edit'; $screen->post_type = 'event';
HideRocketInsights::on_current_screen( $screen );
$assert( 'custom post type list gets the column filter', isset( $GLOBALS['uxs_filters']['manage_event_posts_columns'] ) && PHP_INT_MAX === $GLOBALS['uxs_filters']['manage_event_posts_columns'][0][1] );
$assert( 'custom post type list gets the sortable filter', isset( $GLOBALS['uxs_filters']['manage_edit-event_sortable_columns'] ) );

$GLOBALS['uxs_filters'] = array();
$screen->base = 'dashboard'; $screen->post_type = '';
HideRocketInsights::on_current_screen( $screen );
HideRocketInsights::on_current_screen( null );
$assert( 'other screens and a missing screen register nothing', array() === $GLOBALS['uxs_filters'] );

/* ── Setting in the schema ── */

$schema = ( new Module( 'admin-customiser', array() ) )->settings_schema();
$field  = null;
foreach ( $schema as $row ) {
	if ( is_array( $row ) && 'hide_rocket_insights' === ( $row['key'] ?? '' ) ) {
		$field = $row;
	}
}
$assert( 'setting exists in the schema', null !== $field );
$assert( 'it is a toggle, off by default', $field && 'toggle' === $field['type'] && false === $field['default'] );
$assert( 'it has a label and a help text', $field && '' !== $field['label'] && '' !== $field['help'] );
$keys = array_column( array_filter( $schema, 'is_array' ), 'key' );
$assert( 'its key is unique', 1 === count( array_keys( $keys, 'hide_rocket_insights', true ) ) );
$class = NonPortable::classify( 'admin-customiser', $schema );
$assert( 'Transfer exports it (portable)', in_array( 'hide_rocket_insights', $class['portable'], true ), json_encode( $class ) );
$assert( 'it is not secret or manual', ! in_array( 'hide_rocket_insights', $class['secret'], true ) && ! isset( $class['manual']['hide_rocket_insights'] ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL OK' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
