<?php
/**
 * Source: TablePress.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\Tables\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `tables`: every TablePress table becomes a `uxstudio_table` post. The conversion is
 * Module::convert_tablepress() (shared with bin/migrate-plugins.php), the write is Module::import_tablepress(),
 * which saves through the same validation as the editor and is idempotent by the legacy table ID.
 *
 * The plugin has no settings that matter: its own options (`tablepress_plugin_options`) only hold the custom CSS,
 * the admin menu position and update notices. The per-table options are part of each table and are converted with it.
 * The custom CSS is reported, not moved: it targets TablePress' own class names, which the module does not print.
 *
 * Writing never changes how the site behaves while TablePress runs: the tables are new posts of a private type and
 * the `[uxstudio_table]` shortcode is new. The `[table id=N /]` shortcode of existing pages keeps being answered by
 * TablePress until the switch turns the module on and TablePress off; from then on the module answers it through the
 * legacy ID, so the pages need no editing. That is why the data is written directly and nothing waits in staging.
 */
final class TablePressSource extends AbstractSource {

	/** @var array<string, array<int, array<string, mixed>>>|null Tables per option variant (keep | drop). */
	private ?array $items = null;

	protected function option_names(): array {
		return array( Module::TABLEPRESS_OPTION );
	}

	protected function has_data(): bool {
		return array() !== Module::tablepress_tables();
	}

	protected function changes_behavior(): bool {
		return false;
	}

	protected function data_kind(): string {
		return 'table';
	}

	protected function data_label(): string {
		return __( 'Tables', 'ux-studio' );
	}

	public function options(): array {
		return array(
			array(
				'key'     => 'drop_empty_rows',
				'label'   => __( 'Leave out empty rows', 'ux-studio' ),
				'help'    => __( 'Rows with nothing in any cell are not copied. Leave it off to get the tables exactly as they are in TablePress.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Every TablePress table in a light, normalized shape (the cells are read again when written).
	 *
	 * @param array<string, bool> $options Chosen options.
	 * @return array<int, array<string, mixed>>
	 */
	private function collect( array $options = array() ): array {
		$drop = ! empty( $options['drop_empty_rows'] );
		$key  = $drop ? 'drop' : 'keep';
		if ( null === $this->items ) {
			$this->items = array();
		}
		if ( ! isset( $this->items[ $key ] ) ) {
			$module = Module::standalone();
			$list   = array();
			foreach ( Module::tablepress_tables() as $legacy_id => $post_id ) {
				$data   = $module->convert_tablepress( $post_id, array( 'drop_empty_rows' => $drop ) );
				$list[] = array(
					'key'     => 'tablepress:' . $legacy_id,
					'label'   => null === $data ? '#' . $legacy_id : $data['title'],
					'id'      => $legacy_id,
					'post_id' => $post_id,
					'valid'   => null !== $data,
					'rows'    => null === $data ? 0 : count( $data['cells'] ),
					'cols'    => null === $data ? 0 : count( (array) ( $data['cells'][0] ?? array() ) ),
					'empty'   => null === $data ? 0 : (int) $data['empty_rows'],
					'html'    => null === $data ? 0 : (int) $data['dropped_html'],
					'formula' => null === $data ? 0 : (int) $data['formulas'],
					'custom'  => null !== $data && '' !== $data['custom_commands'],
				);
			}
			$this->items[ $key ] = $list;
		}
		return $this->items[ $key ];
	}

	public function forget(): void {
		parent::forget();
		$this->items = null;
	}

	/**
	 * Count a field over the tables and report it when it is not zero.
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @param string                           $field Field to sum (or a boolean to count).
	 */
	private function sum( array $items, string $field ): int {
		return (int) array_sum( array_map( static fn( array $item ): int => (int) $item[ $field ], $items ) );
	}

	protected function map( bool $active ): Mapping {
		$m     = new Mapping();
		$items = $this->collect();
		if ( ! $items ) {
			$m->note( __( 'TablePress has no tables, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$m->note( __( 'TablePress has no settings that matter here. Each table keeps its own display options (header, footer, stripes, hover, sorting, search, paging).', 'ux-studio' ) );
		$m->note( __( 'Pages with [table id=N /] keep working without any change: once TablePress is switched off, the Tables module answers the same shortcode.', 'ux-studio' ) );

		$html = $this->sum( $items, 'html' );
		if ( $html > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of table cells. */
					__( '%d table cells contain HTML that is not allowed in a table cell (only links, line breaks, bold, italic, span and images are). The extra tags are removed, the text stays.', 'ux-studio' ),
					$html
				)
			);
		}
		$formulas = $this->sum( $items, 'formula' );
		if ( $formulas > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of table cells. */
					__( '%d table cells look like formulas. TablePress calculates them, UX Studio shows the text as it is.', 'ux-studio' ),
					$formulas
				)
			);
		}
		$custom = count( array_filter( array_column( $items, 'custom' ) ) );
		if ( $custom > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of tables. */
					__( '%d tables use custom DataTables commands. They are not transferred; sorting, search and paging are kept as switches.', 'ux-studio' ),
					$custom
				)
			);
		}
		$invalid = count( array_filter( array_column( $items, 'valid' ), static fn( $valid ): bool => ! $valid ) );
		if ( $invalid > 0 ) {
			$m->lossy(
				sprintf(
					/* translators: %d: number of tables. */
					__( '%d tables are missing or their data is damaged. They are skipped.', 'ux-studio' ),
					$invalid
				)
			);
		}
		$css = $this->opt( 'tablepress_plugin_options' );
		$css = is_array( $css ) ? $css : json_decode( (string) $css, true );
		if ( is_array( $css ) && '' !== trim( (string) ( $css['custom_css'] ?? '' ) ) ) {
			$m->lossy( __( 'TablePress has custom CSS. It is not transferred, because it targets the class names of TablePress. Restyle the tables with the CSS variables of the Tables module or an extra CSS class.', 'ux-studio' ) );
		}
		return $m;
	}

	protected function data_plan( array $options ): array {
		$items = $this->collect( $options );
		return array(
			array(
				'key'   => 'table',
				'label' => __( 'Tables', 'ux-studio' ),
				'count' => count( $items ),
			),
			array(
				'key'   => 'empty_rows',
				'label' => ! empty( $options['drop_empty_rows'] ) ? __( 'empty rows left out', 'ux-studio' ) : __( 'empty rows (copied as they are)', 'ux-studio' ),
				'count' => $this->sum( $items, 'empty' ),
			),
		);
	}

	protected function data_total( array $options ): int {
		return count( $this->collect( $options ) );
	}

	protected function read_batch( $position, int $limit, array $options ): array {
		$all    = $this->collect( $options );
		$offset = (int) $position;
		return array(
			'items' => array_slice( $all, $offset, $limit ),
			'next'  => $offset + $limit,
			'done'  => $offset + $limit >= count( $all ),
		);
	}

	protected function preview_item( array $item, array $ctx ): array {
		if ( empty( $item['valid'] ) ) {
			return array(
				'status'  => 'skipped',
				'message' => __( 'The TablePress table is missing or its data is not valid.', 'ux-studio' ),
			);
		}
		return Module::standalone()->find_by_legacy_id( (int) $item['id'] ) ? array( 'status' => 'unchanged' ) : array( 'status' => 'created' );
	}

	protected function apply_item( array $item, array $ctx ): array {
		if ( empty( $item['valid'] ) ) {
			return $this->preview_item( $item, $ctx );
		}
		$res = Module::standalone()->import_tablepress( (int) $item['id'], (int) $item['post_id'], (array) ( $ctx['options'] ?? array() ) );
		if ( is_wp_error( $res ) ) {
			return array(
				'status'  => 'error',
				'message' => $res->get_error_message(),
			);
		}
		if ( 'created' !== $res['status'] ) {
			return array( 'status' => 'unchanged' );
		}
		return array(
			'status'   => 'created',
			'map_type' => 'table_post',
			'map_id'   => (string) $res['id'],
		);
	}

	protected function verify_item( array $item ): array {
		if ( empty( $item['valid'] ) ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The TablePress table is missing or its data is not valid.', 'ux-studio' ),
			);
		}
		$found = Module::standalone()->find_by_legacy_id( (int) $item['id'] );
		return $found
			? array(
				'ok'     => true,
				'detail' => '',
			)
			: array(
				'ok'     => false,
				'detail' => __( 'The table is not in UX Studio.', 'ux-studio' ),
			);
	}
}
