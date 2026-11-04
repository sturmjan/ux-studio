<?php
/**
 * "UX Table" Elementor widget: a dropdown of tables that renders the shortcode.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only via the `elementor/widgets/register` hook, which Elementor
 * itself fires, so this class (and its `extends \Elementor\Widget_Base`) is
 * never loaded without Elementor (same approach as Forms\ElementorWidget).
 * The widget has no markup of its own: it runs the [uxstudio_table] shortcode,
 * so the shortcode and the widget can never drift apart.
 */
final class ElementorWidget extends \Elementor\Widget_Base {

	private static ?Module $module = null;

	/**
	 * Called from the Module's `elementor/widgets/register` callback.
	 *
	 * @param Module $module          Tables module.
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public static function register_with( Module $module, $widgets_manager ): void {
		self::$module = $module;
		$widgets_manager->register( new self() );
	}

	public function get_name(): string {
		return 'uxstudio_table';
	}

	public function get_title(): string {
		return __( 'UX Table', 'ux-studio' );
	}

	public function get_icon(): string {
		return 'eicon-table';
	}

	public function get_categories(): array {
		return array( 'general' );
	}

	public function get_keywords(): array {
		return array( 'table', 'tables', 'data', 'ux studio' );
	}

	protected function is_dynamic_content(): bool {
		return true; // The markup depends on the table's current content.
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Table', 'ux-studio' ) )
		);

		$this->add_control(
			'table_id',
			array(
				'label'   => __( 'Select table', 'ux-studio' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '0',
				'options' => $this->table_options(),
			)
		);

		$this->add_control(
			'no_table_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => __( 'Build the table in UX Studio, Tables first, then pick it here.', 'ux-studio' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * @return array<string, string> { id => title }, "0" always first.
	 */
	private function table_options(): array {
		$options = array( '0' => __( 'Select a table', 'ux-studio' ) );
		if ( null === self::$module ) {
			return $options;
		}
		foreach ( self::$module->list_tables() as $table ) {
			$options[ (string) $table['id'] ] = (string) $table['title'];
		}
		return $options;
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$table_id = (int) ( $settings['table_id'] ?? 0 );

		if ( $table_id <= 0 ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-info">' . esc_html__( 'Select a table in the panel on the left.', 'ux-studio' ) . '</div>';
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Frontend escapes all of its own markup.
		echo do_shortcode( sprintf( '[uxstudio_table id="%d"]', $table_id ) );
	}
}
