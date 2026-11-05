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
 * TablePress -> tables module (uxstudio_table), saved through
 * Tables\Module::save_table() so cells and options are validated exactly like
 * in the editor. Cells keep TablePress' JSON 2D array shape, options are
 * translated to _uxstudio_table_options.
 */
$uxs_tablepress = static function ( bool $apply ) use ( $uxs_usage ): void {
	WP_CLI::log( '--- tablepress ---' );

	$map    = json_decode( (string) get_option( 'tablepress_tables', '' ), true );
	$tables = is_array( $map ) ? (array) ( $map['table_post'] ?? array() ) : array();
	$plugin = class_exists( '\UxStudio\Plugin' ) ? \UxStudio\Plugin::instance() : null;
	$module = $plugin && isset( $plugin->modules ) ? $plugin->modules->instance( 'tables' ) : null;
	if ( ! $module instanceof \UxStudio\Modules\Tables\Module && class_exists( '\UxStudio\Modules\Tables\Module' ) && post_type_exists( \UxStudio\Modules\Tables\Module::CPT ) ) {
		// The plugin is not booted in this process (wp eval-file with --skip-plugins), but the post type is registered.
		$module = new \UxStudio\Modules\Tables\Module( 'tables', (array) json_decode( (string) file_get_contents( UXSTUDIO_PATH . 'includes/Modules/Tables/meta.json' ), true ) );
	}
	$ready = $module instanceof \UxStudio\Modules\Tables\Module && post_type_exists( \UxStudio\Modules\Tables\Module::CPT );
	if ( ! $ready ) {
		WP_CLI::warning( 'Module "tables" is not enabled in UX Studio - showing what would be migrated only.' );
	}

	foreach ( $tables as $legacy_id => $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			WP_CLI::warning( "Table {$legacy_id}: post {$post_id} is missing, skipped." );
			continue;
		}
		$cells = json_decode( $post->post_content, true );
		if ( ! is_array( $cells ) ) {
			WP_CLI::warning( "Table {$legacy_id}: cell data is not valid JSON, skipped." );
			continue;
		}

		// Hidden rows/columns are not carried over as such - they are dropped.
		$visibility = json_decode( (string) get_post_meta( $post->ID, '_tablepress_table_visibility', true ), true );
		if ( is_array( $visibility ) ) {
			$rows_visible = (array) ( $visibility['rows'] ?? array() );
			$cols_visible = (array) ( $visibility['columns'] ?? array() );
			$cells        = array_values( array_filter( $cells, static fn ( $i ) => ! isset( $rows_visible[ $i ] ) || $rows_visible[ $i ], ARRAY_FILTER_USE_KEY ) );
			$cells        = array_map( static fn ( $row ) => array_values( array_filter( (array) $row, static fn ( $i ) => ! isset( $cols_visible[ $i ] ) || $cols_visible[ $i ], ARRAY_FILTER_USE_KEY ) ), $cells );
		}

		$tp      = json_decode( (string) get_post_meta( $post->ID, '_tablepress_table_options', true ), true );
		$tp      = is_array( $tp ) ? $tp : array();
		$use_dt  = ! empty( $tp['use_datatables'] );
		$options = array(
			'head'             => ! empty( $tp['table_head'] ),
			'foot'             => ! empty( $tp['table_foot'] ),
			'striped'          => ! empty( $tp['alternating_row_colors'] ),
			'hover'            => ! empty( $tp['row_hover'] ),
			'responsive'       => $use_dt && ! empty( $tp['datatables_scrollx'] ) ? 'scroll' : 'none',
			'sort'             => $use_dt && ! empty( $tp['datatables_sort'] ),
			'search'           => $use_dt && ! empty( $tp['datatables_filter'] ),
			'paginate'         => $use_dt && ! empty( $tp['datatables_paginate'] ),
			'page_size'        => (int) ( $tp['datatables_paginate_entries'] ?? 10 ),
			'caption'          => ! empty( $tp['print_name'] ) ? $post->post_title : '',
			'caption_position' => ( $tp['print_name_position'] ?? 'above' ) === 'below' ? 'below' : 'above',
			'extra_class'      => sanitize_text_field( (string) ( $tp['extra_css_classes'] ?? '' ) ),
		);

		$empty_rows = count( array_filter( $cells, static fn ( $row ) => '' === implode( '', array_map( 'trim', (array) $row ) ) ) );
		$existing   = $ready ? $module->find_by_legacy_id( (int) $legacy_id ) : 0;
		WP_CLI::log(
			sprintf(
				'Table %d "%s": %d rows x %d cols, %s%s',
				$legacy_id,
				$post->post_title,
				count( $cells ),
				count( (array) ( $cells[0] ?? array() ) ),
				$existing ? "already migrated as #{$existing}" : 'new',
				$empty_rows ? ", {$empty_rows} empty row(s)" : ''
			)
		);
		if ( ! empty( $tp['datatables_custom_commands'] ) ) {
			WP_CLI::warning( "Table {$legacy_id}: custom DataTables commands are not migrated: " . $tp['datatables_custom_commands'] );
		}
		foreach ( $uxs_usage( "[table id={$legacy_id} " ) as $page ) {
			WP_CLI::log( "  used on page {$page} (the [table id={$legacy_id} /] alias keeps it working)" );
		}

		if ( $apply && $ready && ! $existing ) {
			$saved = $module->save_table(
				0,
				array(
					'title'       => $post->post_title,
					'description' => $post->post_excerpt,
					'cells'       => $cells,
					'options'     => $options,
					'legacy_id'   => (int) $legacy_id,
				)
			);
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

	$settings_post = get_posts( array( 'post_type' => 'mapster-wp-settings', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	$default_token = $settings_post ? (string) get_post_meta( (int) $settings_post[0], 'default_access_token', true ) : '';

	$maps = get_posts( array( 'post_type' => 'mapster-wp-map', 'post_status' => 'publish', 'posts_per_page' => -1 ) );
	if ( ! $maps ) {
		WP_CLI::log( 'No Mapster maps found.' );
	}
	foreach ( $maps as $map ) {
		$m = static fn ( string $key ) => get_post_meta( $map->ID, $key, true );

		$provider = (string) $m( 'map_type_map_provider' );
		$token    = (string) $m( 'map_type_access_token' ) ?: $default_token;
		$style    = (string) preg_replace( '#^mapbox://styles/#', '', (string) $m( 'map_type_custom_mapbox_style' ) );
		if ( '' === $style ) {
			$style = 'mapbox/' . ( (string) $m( 'map_type_map_tile_style_access_token' ) ?: 'streets' ) . '-v12';
		}
		// Mapster also offers Google; anything else falls back to OpenStreetMap.
		$target = array( 'mapbox' => 'mapbox', 'google-maps' => 'google', 'google' => 'google' )[ $provider ] ?? 'osm';

		$markers = array();
		$skipped = array();
		foreach ( array_filter( array_map( 'intval', (array) maybe_unserialize( $m( 'locations' ) ) ) ) as $loc_id ) {
			$loc = get_post( $loc_id );
			if ( ! $loc ) {
				continue;
			}
			$geo   = json_decode( (string) get_post_meta( $loc_id, 'location', true ), true );
			$point = $geo['features'][0]['geometry'] ?? null;
			if ( ! is_array( $point ) || 'Point' !== ( $point['type'] ?? '' ) ) {
				$skipped[] = $loc->post_title;
				continue;
			}
			$popup     = '1' === (string) get_post_meta( $loc_id, 'enable_popup', true );
			$header    = (string) get_post_meta( $loc_id, 'popup_header_text', true );
			$markers[] = array(
				'lat'   => (float) $point['coordinates'][1],
				'lng'   => (float) $point['coordinates'][0],
				'title' => $popup && '' !== $header ? $header : $loc->post_title,
				'text'  => $popup ? trim( (string) get_post_meta( $loc_id, 'popup_body_text', true ) ) : '',
				'color' => (string) get_post_meta( $loc_id, 'marker_color', true ),
				'url'   => '',
			);
		}
		foreach ( array( 'lines', 'polygons' ) as $kind ) {
			if ( array_filter( (array) maybe_unserialize( $m( $kind ) ) ) ) {
				$skipped[] = "all {$kind}";
			}
		}

		$config = array(
			'provider'      => $target,
			'style'         => 'mapbox' === $target ? $style : '',
			'height'        => 'px' === ( (string) $m( 'layout_height_units' ) ?: 'px' ) ? (int) $m( 'layout_height' ) : 450,
			'zoom'          => (float) $m( 'view_manual_zoom' ) ?: 13,
			'center'        => array( 'lat' => (float) $m( 'view_manual_latitude' ), 'lng' => (float) $m( 'view_manual_longitude' ) ),
			'fit_markers'   => 'manual' !== (string) $m( 'view_initial_load' ),
			'scroll_zoom'   => '1' === (string) $m( 'zoom_on_scroll' ),
			'route_link'    => false,
			'click_to_load' => false,
			'markers'       => $markers,
		);

		$existing = $ready ? $module->find_by_legacy_id( $map->ID ) : 0;
		WP_CLI::log( sprintf( 'Map %d "%s": %s -> %s%s, %d pin(s), height %dpx, %s', $map->ID, $map->post_title, $provider ?: '?', $target, $config['style'] ? " ({$config['style']})" : '', count( $markers ), $config['height'], $existing ? "already migrated as #{$existing}" : 'new' ) );
		foreach ( $markers as $marker ) {
			WP_CLI::log( sprintf( '  pin "%s" %.6f, %.6f %s', $marker['title'], $marker['lat'], $marker['lng'], $marker['color'] ) );
		}
		if ( 'mapbox' === $target ) {
			WP_CLI::log( '  Mapbox token: ' . ( '' !== $token ? 'found (stored encrypted on apply unless the module already has one)' : 'MISSING - the map shows OpenStreetMap until a token is set' ) );
		}
		if ( $skipped ) {
			WP_CLI::warning( "Map {$map->ID}: not migrated (only points are supported): " . implode( ', ', $skipped ) );
		}
		foreach ( $uxs_usage( 'mapster_wp_map id=' ) as $page ) {
			WP_CLI::log( "  page with a Mapster shortcode: {$page} (keeps working through the alias once Mapster is off)" );
		}

		if ( $apply && $ready && ! $existing ) {
			if ( 'mapbox' === $target && '' !== $token ) {
				$module->store_provider_key( 'mapbox', $token );
			}
			$saved = $module->save_map( 0, array( 'title' => $map->post_title, 'config' => $config, 'legacy_id' => $map->ID ) );
			if ( is_wp_error( $saved ) ) {
				WP_CLI::warning( "Map {$map->ID}: " . $saved->get_error_message() );
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
