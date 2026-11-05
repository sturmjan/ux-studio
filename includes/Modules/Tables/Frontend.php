<?php
/**
 * Tables front end: shortcode, legacy alias and assets.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * [uxstudio_table id="12"] prints a semantic, accessible <table>. The CSS
 * (assets/css/tables.css) is enqueued only on pages that contain a table;
 * assets/js/tables.js (sorting, search, pagination) only when the table has
 * one of those switched on.
 *
 * [table id="N" /] is answered too while TablePress is inactive, for tables
 * migrated from it (bin/migrate-plugins.php), so pages keep working without
 * editing their content or Elementor data.
 */
final class Frontend {

	private Module $module;

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register shortcodes and assets.
	 */
	public function register(): void {
		add_shortcode( 'uxstudio_table', array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_legacy_shortcode' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * TablePress alias, only while TablePress itself is not active.
	 */
	public function register_legacy_shortcode(): void {
		if ( ! shortcode_exists( 'table' ) ) {
			add_shortcode( 'table', array( $this, 'render_legacy_shortcode' ) );
		}
	}

	/**
	 * Register (not enqueue) the assets; the shortcode enqueues what it needs.
	 */
	public function register_assets(): void {
		$base = UXSTUDIO_URL . 'assets/';
		wp_register_style( 'uxstudio-tables', $base . 'css/tables.css', array(), $this->asset_version( 'css/tables.css' ) );
		wp_register_script( 'uxstudio-tables', $base . 'js/tables.js', array(), $this->asset_version( 'js/tables.js' ), true );
	}

	/**
	 * File modification time as the version, so an update busts the cache.
	 *
	 * @param string $relative Path inside assets/.
	 */
	private function asset_version( string $relative ): string {
		$time = @filemtime( UXSTUDIO_PATH . 'assets/' . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $time ? (string) $time : UXSTUDIO_VERSION;
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public function render_legacy_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'table' );
		$id   = $this->module->find_by_legacy_id( (int) $atts['id'] );
		return $id ? $this->render_table( $id ) : '';
	}

	/**
	 * @param array|string $atts Shortcode attributes: id.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), (array) $atts, 'uxstudio_table' );
		return $this->render_table( (int) $atts['id'] );
	}

	/**
	 * Plain text of a header cell, used as the label of the values below it
	 * in the stacked (card) layout.
	 *
	 * @param string $cell Header cell HTML.
	 */
	private function label_of( string $cell ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $cell ) ) ?? '' );
	}

	/**
	 * @param string $cell Cell HTML (sanitized again on output).
	 */
	private function cell_html( string $cell ): string {
		return wp_kses( $cell, Module::allowed_html(), array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/**
	 * @param int $id Table ID.
	 */
	private function render_table( int $id ): string {
		$table = $this->module->get_table( $id );
		if ( null === $table ) {
			return current_user_can( 'edit_posts' ) ? '<!-- UX Studio: table ' . (int) $id . ' not found -->' : '';
		}

		$o     = $table['options'];
		$rows  = $table['cells'];
		$head  = ! empty( $o['head'] ) && count( $rows ) > 1 ? array_shift( $rows ) : array();
		$foot  = ! empty( $o['foot'] ) && count( $rows ) > 1 ? array_pop( $rows ) : array();
		$stack = 'stack' === $o['responsive'];
		$sort  = ! empty( $o['sort'] ) && $head; // Sorting needs a header row; search and pagination do not.
		$js    = $sort || ! empty( $o['search'] ) || ! empty( $o['paginate'] );

		$labels = array_map( array( $this, 'label_of' ), $head );

		$classes = array( 'uxs-tbl' );
		foreach ( array( 'striped', 'hover' ) as $flag ) {
			if ( ! empty( $o[ $flag ] ) ) {
				$classes[] = 'uxs-tbl--' . $flag;
			}
		}
		if ( '' !== $o['extra_class'] ) {
			$classes[] = $o['extra_class'];
		}

		$title   = '' !== $o['caption'] ? $o['caption'] : $table['title'];
		$scroll  = 'scroll' === $o['responsive'];
		$wrapper = $scroll
			? sprintf( '<div class="uxs-tbl-scroll" role="region" tabindex="0" aria-label="%s">', esc_attr( $title ) )
			: '<div class="uxs-tbl-scroll">';

		$html  = sprintf( '<div class="uxs-tbl-root uxs-tbl-root--%s"', esc_attr( $o['responsive'] ) );
		if ( $js ) {
			$client = array(
				'sort'     => $sort,
				'search'   => ! empty( $o['search'] ),
				'paginate' => ! empty( $o['paginate'] ),
				'pageSize' => (int) $o['page_size'],
				'i18n'     => array(
					'search'  => __( 'Search table', 'ux-studio' ),
					'first'   => __( 'First', 'ux-studio' ),
					'prev'    => __( 'Previous', 'ux-studio' ),
					'next'    => __( 'Next', 'ux-studio' ),
					'last'    => __( 'Last', 'ux-studio' ),
					/* translators: 1: current page, 2: number of pages */
					'page'    => __( 'Page %1$s of %2$s', 'ux-studio' ),
					'empty'   => __( 'No matching rows.', 'ux-studio' ),
					'sortby'  => __( 'Sort by this column', 'ux-studio' ),
				),
			);
			$html .= ' data-uxs-table="' . esc_attr( (string) wp_json_encode( $client ) ) . '"';
		}
		$html .= '>' . $wrapper;

		$html .= '<table class="' . esc_attr( implode( ' ', $classes ) ) . '">';
		if ( '' !== $o['caption'] ) {
			$html .= '<caption class="uxs-tbl__caption' . ( 'below' === $o['caption_position'] ? ' uxs-tbl__caption--below' : '' ) . '">' . esc_html( $o['caption'] ) . '</caption>';
		}

		if ( $head ) {
			$html .= '<thead><tr>';
			foreach ( $head as $cell ) {
				$html .= '<th scope="col">' . $this->cell_html( $cell ) . '</th>';
			}
			$html .= '</tr></thead>';
		}

		$html .= '<tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>' . $this->row_cells( $row, $labels, $stack ) . '</tr>';
		}
		$html .= '</tbody>';

		if ( $foot ) {
			$html .= '<tfoot><tr>' . $this->row_cells( $foot, $labels, $stack ) . '</tr></tfoot>';
		}

		$html .= '</table></div></div>';

		wp_enqueue_style( 'uxstudio-tables' );
		if ( $js ) {
			wp_enqueue_script( 'uxstudio-tables' );
		}
		return $html;
	}

	/**
	 * Body/footer cells of one row; with the stacked layout each carries its column label.
	 *
	 * @param string[] $row    Cells.
	 * @param string[] $labels Header texts.
	 * @param bool     $stack  Stacked (card) layout.
	 */
	private function row_cells( array $row, array $labels, bool $stack ): string {
		$html = '';
		foreach ( $row as $i => $cell ) {
			$label = $stack && ! empty( $labels[ $i ] ) ? ' data-label="' . esc_attr( $labels[ $i ] ) . '"' : '';
			$html .= '<td' . $label . '>' . $this->cell_html( $cell ) . '</td>';
		}
		return $html;
	}
}
