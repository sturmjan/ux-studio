<?php
/**
 * Clean Profiles module - hide sections/fields on the user profile screen.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CleanProfiles;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Injects CSS on the profile / user-edit screens that hides the selected
 * profile sections and fields. Ported from the legacy module (free + pro
 * merged), including the WooCommerce address integration.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_footer', array( $this, 'render_styles' ) );
	}

	/**
	 * Selectable profile fields: css-class => label.
	 *
	 * @return array<string, string>
	 */
	private function get_field_options(): array {
		return array(
			'user-rich-editing-wrap'        => __( 'Visual Editor', 'ux-studio' ),
			'user-syntax-highlighting-wrap' => __( 'Syntax Highlighting', 'ux-studio' ),
			'user-admin-color-wrap'         => __( 'Admin Color Scheme', 'ux-studio' ),
			'user-comment-shortcuts-wrap'   => __( 'Keyboard Shortcuts', 'ux-studio' ),
			'user-admin-bar-front-wrap'     => __( 'Toolbar', 'ux-studio' ),
			'user-description-wrap'         => __( 'Biographical Info', 'ux-studio' ),
			'user-role-wrap'                => __( 'Role', 'ux-studio' ),
			'user-email-wrap'               => __( 'Email', 'ux-studio' ),
			'user-url-wrap'                 => __( 'Website', 'ux-studio' ),
			'user-pass1-wrap'               => __( 'New Password', 'ux-studio' ),
			'user-generate-reset-link-wrap' => __( 'Password Reset', 'ux-studio' ),
			'user-sessions-wrap'            => __( 'Sessions', 'ux-studio' ),
		);
	}

	/**
	 * Selectable profile sections: css-class => label (plus integrations).
	 *
	 * @return array<string, string>
	 */
	private function get_section_options(): array {
		$sections = array(
			'user-syntax-highlighting-wrap' => __( 'Personal Options', 'ux-studio' ),
			'user-user-login-wrap'          => __( 'Name', 'ux-studio' ),
			'user-email-wrap'               => __( 'Contact Info', 'ux-studio' ),
			'user-description-wrap'         => __( 'About the user', 'ux-studio' ),
			'user-pass1-wrap'               => __( 'Account Management', 'ux-studio' ),
			'application-passwords'         => __( 'Application Passwords', 'ux-studio' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$sections['woocommerce-customer-billing']  = __( '[WooCommerce] Billing Address', 'ux-studio' );
			$sections['woocommerce-customer-shipping'] = __( '[WooCommerce] Shipping Address', 'ux-studio' );
		}

		return $sections;
	}

	/**
	 * Output the hiding stylesheet on profile screens.
	 */
	public function render_styles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'user-edit', 'profile' ), true ) ) {
			return;
		}

		$selectors = array_merge(
			$this->section_selectors( (array) $this->settings->get( 'sections', array() ) ),
			$this->field_selectors( (array) $this->settings->get( 'fields', array() ) )
		);

		if ( array() === $selectors ) {
			return;
		}

		printf(
			'<style id="uxstudio-clean-profiles">%s{display:none !important;}</style>',
			implode( ',', array_map( 'esc_html', $selectors ) )
		);
	}

	/**
	 * Build CSS selectors for the selected sections.
	 *
	 * @param string[] $sections Selected section css classes.
	 * @return string[]
	 */
	private function section_selectors( array $sections ): array {
		$selectors = array();

		foreach ( $sections as $section ) {
			$section = (string) $section;

			if ( 'woocommerce-customer-billing' === $section ) {
				$selectors[] = '#fieldset-billing';
				$selectors[] = 'h2:has(+ #fieldset-billing)';
				continue;
			}

			if ( 'woocommerce-customer-shipping' === $section ) {
				$selectors[] = '#fieldset-shipping';
				$selectors[] = 'h2:has(+ #fieldset-shipping)';
				continue;
			}

			$selectors[] = sprintf( 'table.form-table:has(.%s)', $section );
			$selectors[] = sprintf( 'h2:has(+ .form-table .%s)', $section );

			if ( 'application-passwords' === $section ) {
				$selectors[] = sprintf( '.%s', $section );
			}
		}

		return $selectors;
	}

	/**
	 * Build CSS selectors for the selected fields.
	 *
	 * @param string[] $fields Selected field css classes.
	 * @return string[]
	 */
	private function field_selectors( array $fields ): array {
		$selectors = array();

		foreach ( $fields as $field ) {
			$selectors[] = sprintf( '.%s', (string) $field );
		}

		return $selectors;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'sections',
				'type'    => 'multiselect',
				'label'   => __( 'Profile sections', 'ux-studio' ),
				'help'    => __( 'Select the profile sections to hide.', 'ux-studio' ),
				'options' => $this->get_section_options(),
				'default' => array(),
			),
			array(
				'key'     => 'fields',
				'type'    => 'multiselect',
				'label'   => __( 'Profile fields', 'ux-studio' ),
				'help'    => __( 'Select the individual profile fields to hide.', 'ux-studio' ),
				'options' => $this->get_field_options(),
				'default' => array(),
			),
		);
	}
}
