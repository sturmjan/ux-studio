<?php
/**
 * Native "UX Studio Map" Elementor widget.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only via the `elementor/widgets/register` hook (fired by Elementor
 * itself), so `extends \Elementor\Widget_Base` is only autoloaded when Elementor
 * is active - same rule as Forms\ElementorWidget. Rendering is delegated to
 * Module::render_map(), i.e. the very same markup the shortcode prints.
 */
final class ElementorWidget extends \Elementor\Widget_Base {

	private static ?Module $module = null;

	/**
	 * Called from Module's `elementor/widgets/register` callback.
	 *
	 * @param Module $module          Maps module.
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public static function register_with( Module $module, $widgets_manager ): void {
		self::$module = $module;
		$widgets_manager->register( new self() );
	}

	public function get_name(): string {
		return 'uxstudio_map';
	}

	public function get_title(): string {
		return __( 'UX Studio Map', 'ux-studio' );
	}

	public function get_icon(): string {
		return 'eicon-google-maps';
	}

	public function get_categories(): array {
		return array( 'general' );
	}

	public function get_keywords(): array {
		return array( 'map', 'maps', 'location', 'pin', 'ux studio' );
	}

	protected function is_dynamic_content(): bool {
		return true; // The markup depends on the selected map's live definition.
	}

	protected function register_controls(): void {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Map', 'ux-studio' ) )
		);

		$this->add_control(
			'map_id',
			array(
				'label'   => __( 'Select map', 'ux-studio' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '0',
				'options' => $this->map_options(),
			)
		);

		$this->add_control(
			'height',
			array(
				'label'       => __( 'Height (px)', 'ux-studio' ),
				'description' => __( 'Leave empty to use the height set in the map.', 'ux-studio' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 100,
				'max'         => 2000,
			)
		);

		$this->end_controls_section();
	}

	/**
	 * @return array<string, string> { id => title }, "0" always first.
	 */
	private function map_options(): array {
		$options = array( '0' => __( 'Select a map', 'ux-studio' ) );
		if ( null === self::$module ) {
			return $options;
		}
		foreach ( self::$module->list_maps() as $map ) {
			$options[ (string) $map['id'] ] = '' !== (string) $map['title'] ? (string) $map['title'] : '#' . $map['id'];
		}
		return $options;
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$map_id   = (int) ( $settings['map_id'] ?? 0 );

		if ( $map_id <= 0 || null === self::$module ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-info">' . esc_html__( 'Select a map in the panel on the left.', 'ux-studio' ) . '</div>';
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Frontend::render_map() escapes its own markup.
		echo self::$module->render_map( $map_id, (int) ( $settings['height'] ?? 0 ) );
	}
}
