<?php
/**
 * Native "UX Form" Elementor widget (PLAN.md 20.7/F3).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only via the `elementor/widgets/register` hook - that action is
 * fired exclusively by Elementor itself, so this class (and its `extends
 * \Elementor\Widget_Base`) is only ever autoloaded once Elementor is already
 * active; when Elementor is absent the class is simply never referenced and
 * `forms` keeps working through the shortcode/Gutenberg block as usual.
 *
 * The widget carries no rendering logic of its own - `render()` calls the
 * exact same `Module::render_shortcode()` the shortcode and the Gutenberg
 * block use (PublicRenderer::render()), so there is never a second markup
 * implementation to keep in sync (PLAN.md 20.2/20.7). What IS specific to
 * this widget is the style layer: Elementor style controls (typography,
 * colors, spacing, hover/focus/error) target the exact class names
 * PublicRenderer already prints (`.uxs-fp-label`, `.uxs-form-public input`,
 * `.uxs-fp-btn--primary`, `.uxs-fp-error`, ...) with `{{WRAPPER}}`-scoped
 * selectors, which win over PublicRenderer's own base CSS on specificity.
 */
final class ElementorWidget extends \Elementor\Widget_Base {

	private static ?Module $module = null;

	/**
	 * @param Module $module Forms module instance (for render + the form picker).
	 */
	public static function register( Module $module ): void {
		self::$module = $module;
		add_action(
			'elementor/widgets/register',
			static function ( $widgets_manager ): void {
				$widgets_manager->register( new self() );
			}
		);
	}

	public function get_name(): string {
		return 'uxstudio_form';
	}

	public function get_title(): string {
		return __( 'UX Form', 'ux-studio' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	public function get_categories(): array {
		return array( 'general' );
	}

	public function get_keywords(): array {
		return array( 'form', 'forms', 'contact', 'ux studio' );
	}

	protected function is_dynamic_content(): bool {
		return true; // The rendered markup depends on the selected form's live definition.
	}

	protected function register_controls(): void {
		$this->register_content_controls();
		$this->register_label_style_controls();
		$this->register_input_style_controls();
		$this->register_button_style_controls();
		$this->register_error_style_controls();
	}

	private function register_content_controls(): void {
		$this->start_controls_section(
			'section_content',
			array( 'label' => __( 'Form', 'ux-studio' ) )
		);

		$this->add_control(
			'form_id',
			array(
				'label'   => __( 'Select form', 'ux-studio' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '0',
				'options' => $this->form_options(),
			)
		);

		$this->add_control(
			'no_form_notice',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => __( 'Build and publish the form in the UX Studio → Form Builder screen first, then pick it here.', 'ux-studio' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * @return array<string, string> { id => title }, "0" always first.
	 */
	private function form_options(): array {
		$options = array( '0' => __( '— Select a form —', 'ux-studio' ) );
		if ( null === self::$module ) {
			return $options;
		}
		foreach ( self::$module->list_form_options() as $form ) {
			$label = (string) $form['title'];
			if ( 'active' !== $form['status'] ) {
				/* translators: %s: form title. */
				$label = sprintf( __( '%s (not published)', 'ux-studio' ), $label );
			}
			$options[ (string) $form['id'] ] = $label;
		}
		return $options;
	}

	private function register_label_style_controls(): void {
		$this->start_controls_section(
			'section_style_label',
			array(
				'label' => __( 'Label', 'ux-studio' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'label_color',
			array(
				'label'     => __( 'Text Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .uxs-fp-label' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .uxs-fp-label',
			)
		);

		$this->add_responsive_control(
			'label_spacing',
			array(
				'label'      => __( 'Spacing', 'ux-studio' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'max' => 40 ) ),
				'selectors'  => array( '{{WRAPPER}} .uxs-fp-field' => 'gap: {{SIZE}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_input_style_controls(): void {
		$input_selector = '{{WRAPPER}} .uxs-form-public input:not([type=checkbox]):not([type=radio]):not([type=hidden]),'
			. ' {{WRAPPER}} .uxs-form-public select, {{WRAPPER}} .uxs-form-public textarea';
		$focus_selector = '{{WRAPPER}} .uxs-form-public input:focus, {{WRAPPER}} .uxs-form-public select:focus, {{WRAPPER}} .uxs-form-public textarea:focus';

		$this->start_controls_section(
			'section_style_input',
			array(
				'label' => __( 'Input', 'ux-studio' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'selector' => $input_selector,
			)
		);

		$this->start_controls_tabs( 'tabs_input_style' );

		$this->start_controls_tab( 'tab_input_normal', array( 'label' => __( 'Normal', 'ux-studio' ) ) );

		$this->add_control(
			'input_text_color',
			array(
				'label'     => __( 'Text Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $input_selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_background',
			array(
				'label'     => __( 'Background Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $input_selector => 'background-color: {{VALUE}};' ),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			array(
				'name'     => 'input_border',
				'selector' => $input_selector,
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_input_focus', array( 'label' => __( 'Focus', 'ux-studio' ) ) );

		$this->add_control(
			'input_focus_border_color',
			array(
				'label'     => __( 'Border Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $focus_selector => 'border-color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'input_focus_shadow_color',
			array(
				'label'     => __( 'Focus Ring Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $focus_selector => 'box-shadow: 0 0 0 3px {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'input_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ux-studio' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $input_selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'input_padding',
			array(
				'label'      => __( 'Padding', 'ux-studio' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( $input_selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_button_style_controls(): void {
		$button_selector = '{{WRAPPER}} .uxs-fp-btn--primary';
		$hover_selector  = '{{WRAPPER}} .uxs-fp-btn--primary:hover';

		$this->start_controls_section(
			'section_style_button',
			array(
				'label' => __( 'Button', 'ux-studio' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'selector' => $button_selector,
			)
		);

		$this->start_controls_tabs( 'tabs_button_style' );

		$this->start_controls_tab( 'tab_button_normal', array( 'label' => __( 'Normal', 'ux-studio' ) ) );

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $button_selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_background',
			array(
				'label'     => __( 'Background Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $button_selector => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->start_controls_tab( 'tab_button_hover', array( 'label' => __( 'Hover', 'ux-studio' ) ) );

		$this->add_control(
			'button_hover_text_color',
			array(
				'label'     => __( 'Text Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $hover_selector => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'button_hover_background',
			array(
				'label'     => __( 'Background Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( $hover_selector => 'background-color: {{VALUE}};' ),
			)
		);

		$this->end_controls_tab();

		$this->end_controls_tabs();

		$this->add_responsive_control(
			'button_border_radius',
			array(
				'label'      => __( 'Border Radius', 'ux-studio' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array( $button_selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'ux-studio' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array( $button_selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);

		$this->end_controls_section();
	}

	private function register_error_style_controls(): void {
		$this->start_controls_section(
			'section_style_error',
			array(
				'label' => __( 'Error state', 'ux-studio' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'error_text_color',
			array(
				'label'     => __( 'Error Text Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} .uxs-fp-error' => 'color: {{VALUE}};' ),
			)
		);

		$this->add_control(
			'error_border_color',
			array(
				'label'     => __( 'Invalid Field Border Color', 'ux-studio' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .uxs-fp-field.has-error input, {{WRAPPER}} .uxs-fp-field.has-error select, {{WRAPPER}} .uxs-fp-field.has-error textarea' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Renders the widget - both on the live front-end and for the editor's
	 * own live-preview AJAX render, which calls this exact same method
	 * (Elementor never round-trips widget content through our REST API; the
	 * "no second render implementation" requirement is met by delegating to
	 * Module::render_shortcode(), the single source of truth also used by
	 * the shortcode and the Gutenberg block - see class docblock).
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$form_id  = (int) ( $settings['form_id'] ?? 0 );

		if ( $form_id <= 0 || null === self::$module ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="elementor-alert elementor-alert-info">' . esc_html__( 'Select a form in the panel on the left.', 'ux-studio' ) . '</div>';
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PublicRenderer owns all escaping of its own markup.
		echo self::$module->render_shortcode( array( 'id' => $form_id ) );
	}
}
