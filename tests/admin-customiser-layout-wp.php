<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * WordPress side of the Admin Customiser layout: a field that is hidden by `show_if` keeps its stored value through
 * the REST schema, a save of the whole form, and an export / import round trip. Run against the local pobyty:
 *
 *   cd D:\xampp\htdocs\pobyty
 *   php wp-cli.phar --skip-plugins=wp-mail-smtp eval-file D:\xampp\htdocs\_uxs-wt-csp\tests\admin-customiser-layout-wp.php
 *
 * The option of the module and the import snapshot are saved first and put back exactly (also on failure); cron
 * hooks of the plugin are compared before and after.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

use UxStudio\Core\Migration\Snapshot;
use UxStudio\Core\Transfer\Envelope;
use UxStudio\Core\Transfer\Exporter;
use UxStudio\Core\Transfer\ImportSnapshot;
use UxStudio\Core\Transfer\Importer;
use UxStudio\Plugin;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	WP_CLI::log( ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) );
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$modules  = Plugin::instance()->modules;
$exporter = new Exporter( $modules );
$importer = new Importer( $modules );
$module   = $modules->instance( 'admin-customiser' );

$cron_hooks = static function (): array {
	$out = array();
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'uxstudio_' ) ) {
				$out[] = (string) $hook;
			}
		}
	}
	sort( $out );
	return $out;
};
$cron_before = $cron_hooks();
$names       = array( 'uxstudio_admin_customiser', ImportSnapshot::OPTION, 'uxstudio_import_applied' );
$saved       = Snapshot::read_options( $names );

try {
	if ( ! $module ) {
		$assert( 'the admin-customiser module exists', false );
		throw new RuntimeException( 'no module' );
	}

	// The REST payload carries the new properties.
	$schema = $module->settings_schema();
	$json   = json_decode( wp_json_encode( $schema ), true );
	$by_key = array_column( $json, null, 'key' );
	$assert( 'the schema JSON carries group, group_help and show_if', ! empty( $by_key['login_split_media_video_url']['group'] ) && ! empty( $by_key['login_split_media_video_url']['group_help'] ) && in_array( array( 'key' => 'login_split_media_type', 'equals' => 'video' ), $by_key['login_split_media_video_url']['show_if'] ?? array(), true ), wp_json_encode( $by_key['login_split_media_video_url'] ?? null ) );

	// A state where several fields are hidden but hold values.
	$module->save_settings(
		array(
			'login_customization_enabled'   => true,
			'login_style'                   => 'classic',
			'login_split_media_type'        => 'video',
			'login_split_media_video_url'   => 'https://example.com/clip.mp4',
			'login_split_media_youtube_url' => 'dQw4w9WgXcQ',
			'admin_bar_enabled'             => false,
			'keep_on_bar_ids'               => 'wp-admin-bar-keepme',
			'quick_search_enabled'          => false,
			'server_clock_enabled'          => false,
			'server_clock_position'         => 'plus-menu',
		)
	);
	$hidden = array( 'login_split_media_video_url', 'login_split_media_youtube_url', 'keep_on_bar_ids', 'server_clock_position' );
	$values = $module->settings_values();
	$assert( 'hidden fields hold their values', 'dQw4w9WgXcQ' === $values['login_split_media_youtube_url'] && 'wp-admin-bar-keepme' === $values['keep_on_bar_ids'] && 'plus-menu' === $values['server_clock_position'] );

	// The form posts the whole draft: hidden values are sent back unchanged.
	$before = array_intersect_key( $module->settings_values(), array_flip( $hidden ) );
	$module->save_settings( $module->settings_values() );
	$after = array_intersect_key( $module->settings_values(), array_flip( $hidden ) );
	$assert( 'saving the whole form keeps the hidden values', $before === $after, wp_json_encode( array( $before, $after ) ) );

	// Export, scramble, import: the hidden values come back.
	$built = $exporter->build( array( 'admin-customiser' ), array( 'include_enabled' => false, 'include_data' => false ) );
	$assert( 'export builds', ! is_wp_error( $built ) );
	$env      = $built['envelope'];
	$exported = $env['modules']['admin-customiser']['settings'] ?? array();
	$assert( 'the export holds the hidden values', 'dQw4w9WgXcQ' === ( $exported['login_split_media_youtube_url'] ?? null ) && 'wp-admin-bar-keepme' === ( $exported['keep_on_bar_ids'] ?? null ) && 'plus-menu' === ( $exported['server_clock_position'] ?? null ) && 'https://example.com/clip.mp4' === ( $exported['login_split_media_video_url'] ?? null ), wp_json_encode( array_intersect_key( $exported, array_flip( $hidden ) ) ) );
	$file = $built['json'];

	$module->save_settings( array( 'login_split_media_youtube_url' => '', 'keep_on_bar_ids' => '', 'server_clock_position' => 'admin-bar-pill', 'login_split_media_video_url' => '' ) );
	$assert( 'the values were scrambled', '' === $module->settings_values()['login_split_media_youtube_url'] );
	$res = $importer->apply( $file, '', Importer::options( array( 'modules' => array( 'admin-customiser' ) ) ) );
	$assert( 'import applies', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
	$back = array_intersect_key( $module->settings_values(), array_flip( $hidden ) );
	$assert( 'import brings the hidden values back', $back === $before, wp_json_encode( array( $back, $before ) ) );
} finally {
	Snapshot::restore_options( $saved );
	wp_cache_flush();
}

$assert( 'the option is exactly as before', get_option( 'uxstudio_admin_customiser', null ) === $saved['uxstudio_admin_customiser']['value'] );
$assert( 'plugin cron hooks are unchanged', $cron_hooks() === $cron_before );

if ( $failures ) {
	WP_CLI::error( "FAILED: $failures" );
}
WP_CLI::success( 'ALL PASSED' );
