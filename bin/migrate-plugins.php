<?php
/**
 * Migration from Squeeze, WebP Express, TablePress and Mapster WP Maps into
 * UX Studio (PLAN.md §41). Dry run by default - nothing is written unless the
 * "apply" argument is given.
 *
 * bin/ is not part of the release zip, so the script is piped from this repo
 * into WP-CLI on the target site (WordPress root, UX Studio active):
 *   cat bin/migrate-plugins.php | ssh <host> "cd <wp-root> && wp eval-file - mapster squeeze webp-express tablepress"
 *   cat bin/migrate-plugins.php | ssh <host> "cd <wp-root> && wp eval-file - mapster apply"
 * Locally: wp eval-file D:/path/to/ux-studio/bin/migrate-plugins.php mapster
 *
 * Idempotent: tables and maps are matched by their legacy ID, Squeeze meta is
 * only written where image-optimizer has none yet.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run through WP-CLI: wp eval-file bin/migrate-plugins.php <steps> [apply]\n" );
}

$uxs_args  = isset( $args ) && is_array( $args ) ? array_map( 'strtolower', $args ) : array();
$uxs_apply = in_array( 'apply', $uxs_args, true );
$uxs_steps = array_values( array_intersect( array( 'squeeze', 'webp-express', 'tablepress', 'mapster' ), $uxs_args ) );

if ( empty( $uxs_steps ) ) {
	WP_CLI::error( 'Name at least one step: squeeze, webp-express, tablepress, mapster (add "apply" to write).' );
}

WP_CLI::log( $uxs_apply ? '=== APPLY - changes will be written ===' : '=== DRY RUN - nothing is written (add "apply") ===' );

/**
 * Squeeze -> image-optimizer: mark compressed attachments as optimized so the
 * bulk run does not compress them a second time. The ".bak.<ext>" originals
 * stay where they are; Optimizer::legacy_backup() reads them for Restore.
 */
$uxs_squeeze = static function ( bool $apply ): void {
	WP_CLI::log( '--- squeeze ---' );
	WP_CLI::log( 'Same code as the migration wizard (Core\Migration\Sources\SqueezeSource): the compressed images are marked, never compressed again, .bak originals are kept.' );

	$source = new \UxStudio\Core\Migration\Sources\SqueezeSource( 'squeeze' );
	$state  = array(
		'run_id'  => '',
		'options' => array(
			'keep_backups'  => true,
			'generate_webp' => false,
		),
	);
	$totals = array( 'written' => 0, 'unchanged' => 0 );
	$guard  = 0;
	do {
		$result = $source->migrate( $state, ! $apply );
		$totals['written']   += (int) ( $result['written'] ?? 0 );
		$totals['unchanged'] += (int) ( $result['unchanged'] ?? 0 );
		$state['cursor']      = $result['cursor'] ?? null;
		foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
			WP_CLI::warning( (string) $error );
		}
	} while ( empty( $result['done'] ) && ++$guard < 10000 );

	WP_CLI::log( sprintf( '%s: %d images marked, %d already there.', $apply ? 'Applied' : 'Dry run', $totals['written'], $totals['unchanged'] ) );
	foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
		if ( ! empty( $row['label'] ) ) {
			WP_CLI::log( '  ' . $row['label'] . ': ' . ( $row['to'] ?? '' ) );
		}
	}
	$options = get_option( 'squeeze_options' );
	if ( is_array( $options ) ) {
		WP_CLI::log( sprintf( 'Squeeze settings for comparison: JPEG q%s, WebP q%s, thumbnails: %s', $options['jpeg_quality'] ?? '?', $options['webp_quality'] ?? '?', implode( ', ', array_keys( (array) ( $options['compress_thumbs'] ?? array() ) ) ) ) );
	}
};
/**
 * WebP Express leftovers: the plugin is gone, only its options remain.
 */
$uxs_webp_express = static function ( bool $apply ): void {
	global $wpdb;

	WP_CLI::log( '--- webp-express ---' );
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$active = is_plugin_active( 'webp-express/webp-express.php' );
	$names  = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'webp-express-%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	WP_CLI::log( sprintf( 'Plugin active: %s | leftover options: %d', $active ? 'YES' : 'no', count( $names ) ) );

	$folder = WP_CONTENT_DIR . '/webp-express';
	if ( is_dir( $folder ) ) {
		WP_CLI::warning( "Folder {$folder} still exists (converted WebP files) - delete manually after image-optimizer generates its own." );
	}

	if ( $active ) {
		WP_CLI::warning( 'WebP Express is active - deactivate it first, options kept.' );
		return;
	}
	if ( $apply ) {
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
	WP_CLI::log( ( $apply ? 'Deleted: ' : 'Would delete: ' ) . implode( ', ', $names ) );
};

/**
 * Find a post created by an earlier run (matched by legacy ID).
 */
$uxs_find_migrated = static function ( string $post_type, string $meta_key, int $legacy_id ): int {
	$found = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => (string) $legacy_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	return $found ? (int) $found[0] : 0;
};

/**
 * Pages that embed a legacy shortcode (post_content or Elementor data).
 *
 * @return string[] "ID title" list.
 */
$uxs_usage = static function ( string $like ): array {
	global $wpdb;
	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
			WHERE p.post_status = 'publish' AND p.post_type NOT IN ('revision') AND (p.post_content LIKE %s OR m.meta_value LIKE %s)",
			'%' . $wpdb->esc_like( $like ) . '%',
			'%' . $wpdb->esc_like( $like ) . '%'
		)
	);
	return array_map( static fn ( $r ): string => $r->ID . ' ' . $r->post_title, (array) $rows );
};

/**
 * TablePress -> tables module (uxstudio_table). The conversion lives in
 * Tables\Module::convert_tablepress() / import_tablepress() and is shared with the
 * migration wizard (Core\Migration\Sources\TablePressSource). Saving goes through
 * save_table(), so cells and options are validated exactly like in the editor.
 */
$uxs_tablepress = static function ( bool $apply ) use ( $uxs_usage ): void {
	WP_CLI::log( '--- tablepress ---' );

	$tables = class_exists( '\UxStudio\Modules\Tables\Module' ) ? \UxStudio\Modules\Tables\Module::tablepress_tables() : array();
	// The module need not be switched on: saving needs neither its hooks nor the registered post type
	// (the post type only has to exist for the alias to render, that is the module being on after the switch).
	$module = class_exists( '\UxStudio\Modules\Tables\Module' ) ? \UxStudio\Modules\Tables\Module::standalone() : null;
	$ready  = null !== $module;
	if ( ! $ready ) {
		WP_CLI::warning( 'The tables module code is not available - showing what would be migrated only.' );
	} elseif ( ! post_type_exists( \UxStudio\Modules\Tables\Module::CPT ) ) {
		WP_CLI::warning( 'Module "tables" is not enabled in UX Studio yet - tables are written anyway, but [table id=N /] only renders once the module is on.' );
	}

	foreach ( $tables as $legacy_id => $post_id ) {
		$data = $ready ? $module->convert_tablepress( (int) $post_id ) : null;
		if ( null === $data ) {
			WP_CLI::warning( "Table {$legacy_id}: post {$post_id} is missing or its cell data is not valid JSON, skipped." );
			continue;
		}

		$existing = $ready ? $module->find_by_legacy_id( (int) $legacy_id ) : 0;
		WP_CLI::log(
			sprintf(
				'Table %d "%s": %d rows x %d cols, %s%s',
				$legacy_id,
				$data['title'],
				count( $data['cells'] ),
				count( (array) ( $data['cells'][0] ?? array() ) ),
				$existing ? "already migrated as #{$existing}" : 'new',
				$data['empty_rows'] ? ", {$data['empty_rows']} empty row(s)" : ''
			)
		);
		if ( '' !== $data['custom_commands'] ) {
			WP_CLI::warning( "Table {$legacy_id}: custom DataTables commands are not migrated: " . $data['custom_commands'] );
		}
		if ( $data['formulas'] ) {
			WP_CLI::warning( "Table {$legacy_id}: {$data['formulas']} cell(s) look like formulas; UX Studio does not calculate them." );
		}
		if ( $data['dropped_html'] ) {
			WP_CLI::warning( "Table {$legacy_id}: {$data['dropped_html']} cell(s) contain HTML that is not allowed in a table cell (only links, line breaks, bold, italic, span, images) and will be stripped." );
		}
		foreach ( $uxs_usage( "[table id={$legacy_id} " ) as $page ) {
			WP_CLI::log( "  used on page {$page} (the [table id={$legacy_id} /] alias keeps it working)" );
		}

		if ( $apply && $ready && ! $existing ) {
			$saved = $module->import_tablepress( (int) $legacy_id, (int) $post_id );
			if ( is_wp_error( $saved ) ) {
				WP_CLI::warning( "Table {$legacy_id}: " . $saved->get_error_message() );
				continue;
			}
			WP_CLI::log( "  -> created table #{$saved['id']}, shortcode [uxstudio_table id=\"{$saved['id']}\"]" );
		}
	}
};

/**
 * Mapster WP Maps -> maps module (uxstudio_map), saved through
 * Maps\Module::save_map() so the config is validated exactly like the editor.
 * Only point locations are migrated; lines/polygons are reported.
 */
$uxs_mapster = static function ( bool $apply ) use ( $uxs_usage ): void {
	WP_CLI::log( '--- mapster ---' );

	$plugin = class_exists( '\UxStudio\Plugin' ) ? \UxStudio\Plugin::instance() : null;
	$module = $plugin && isset( $plugin->modules ) ? $plugin->modules->instance( 'maps' ) : null;
	$ready  = $module instanceof \UxStudio\Modules\Maps\Module && post_type_exists( \UxStudio\Modules\Maps\Module::CPT );
	if ( ! $ready ) {
		WP_CLI::warning( 'Module "maps" is not enabled in UX Studio - showing what would be migrated only.' );
	}

	$maps = \UxStudio\Modules\Maps\MapsterImport::map_ids();
	if ( ! $maps ) {
		WP_CLI::log( 'No Mapster maps found.' );
	}
	foreach ( $maps as $map_id ) {
		$data = \UxStudio\Modules\Maps\MapsterImport::read( $map_id );
		if ( null === $data ) {
			continue;
		}
		$config   = $data['config'];
		$existing = $ready ? $module->find_by_legacy_id( $map_id ) : 0;
		WP_CLI::log( sprintf( 'Map %d "%s": %s -> %s%s, %d pin(s), height %dpx, %s', $map_id, $data['title'], $data['from'] ?: '?', $data['provider'], $data['style'] ? " ({$data['style']})" : '', $data['pins'], $config['height'], $existing ? "already migrated as #{$existing}" : 'new' ) );
		foreach ( $config['markers'] as $marker ) {
			WP_CLI::log( sprintf( '  pin "%s" %.6f, %.6f %s', $marker['title'], $marker['lat'], $marker['lng'], $marker['color'] ) );
		}
		if ( 'mapbox' === $data['provider'] ) {
			WP_CLI::log( '  Mapbox token: ' . ( '' !== $data['token'] ? 'found (stored encrypted on apply unless the module already has one)' : 'MISSING - the map shows OpenStreetMap until a token is set' ) );
		}
		if ( $data['skipped'] ) {
			WP_CLI::warning( "Map {$map_id}: not migrated (only points are supported): " . implode( ', ', $data['skipped'] ) );
		}
		foreach ( $uxs_usage( 'mapster_wp_map id=' ) as $page ) {
			WP_CLI::log( "  page with a Mapster shortcode: {$page} (keeps working through the alias once Mapster is off)" );
		}

		if ( $apply && $ready && ! $existing ) {
			$saved = $module->import_mapster( $map_id );
			if ( 'created' !== $saved['status'] ) {
				WP_CLI::warning( "Map {$map_id}: " . ( $saved['message'] ?? $saved['status'] ) );
				continue;
			}
			WP_CLI::log( "  -> created map #{$saved['id']}, shortcode [uxstudio_map id=\"{$saved['id']}\"]" );
		}
	}
};

$uxs_runners = array(
	'squeeze'      => $uxs_squeeze,
	'webp-express' => $uxs_webp_express,
	'tablepress'   => $uxs_tablepress,
	'mapster'      => $uxs_mapster,
);

foreach ( $uxs_steps as $uxs_step ) {
	$uxs_runners[ $uxs_step ]( $uxs_apply );
}

WP_CLI::success( $uxs_apply ? 'Migration finished. Check the pages listed above, then deactivate the old plugins.' : 'Dry run finished, nothing was changed.' );
