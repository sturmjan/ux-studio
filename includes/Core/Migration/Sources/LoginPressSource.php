<?php
/**
 * Source: LoginPress.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Option `loginpress_customization` (the Customizer values of the login screen: `setting_logo` (URL),
 * `setting_logo_display`, `setting_background` (URL), `setting_background_color`, `custom_button_color`,
 * `button_text_color`, `form_background_color`, `textfield_*`, `customize_form_radius`, `welcome_message` ...)
 * and `loginpress_setting` (behaviour: login order, password fields, session, auto login ...).
 *
 * Target: the Admin Customiser login keys.
 */
final class LoginPressSource extends AbstractAdminSource {

	protected function option_names(): array {
		return array( 'loginpress_customization', 'loginpress_setting' );
	}

	/**
	 * A stored customizer value as text.
	 *
	 * @param array<string, mixed> $o   Options.
	 * @param string               $key Key.
	 */
	private function str( array $o, string $key ): string {
		$v = $o[ $key ] ?? '';
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'loginpress_customization' );
		$s = $this->opt( 'loginpress_setting' );
		$o = is_array( $o ) ? $o : array();
		$s = is_array( $s ) ? $s : array();
		if ( ! $o && ! $s ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$origin = 'loginpress_customization';
		$lossy  = array();
		$count  = 0;

		$logo = $this->str( $o, 'setting_logo' );
		if ( '' !== $logo ) {
			$id = $this->attachment_id( $logo );
			if ( $id > 0 ) {
				$m->set( self::MODULE, 'login_logo', $id, __( 'Login screen logo', 'ux-studio' ), $origin . ':setting_logo' );
				++$count;
			} else {
				$lossy[] = sprintf(
					/* translators: %s: file address. */
					__( 'The login logo %s is not in the media library of this site, so no attachment could be linked. Upload it and choose it in the Admin Customiser settings.', 'ux-studio' ),
					$logo
				);
			}
		}
		if ( array_key_exists( 'setting_logo_display', $o ) && ! in_array( $o['setting_logo_display'], array( true, 1, '1' ), true ) ) {
			$m->set( self::MODULE, 'form_options', array( 'hide_login_logo' ), __( 'Login form options', 'ux-studio' ), $origin . ':setting_logo_display' );
			++$count;
		}
		$map = array(
			'setting_background_color'  => array( 'login_background_color', __( 'Login screen background color', 'ux-studio' ) ),
			'form_background_color'     => array( 'login_form_bg_color', __( 'Login form background color', 'ux-studio' ) ),
			'textfield_label_color'     => array( 'login_form_text_color', __( 'Login form text color', 'ux-studio' ) ),
			'textfield_background_color' => array( 'login_input_bg_color', __( 'Login field background color', 'ux-studio' ) ),
			'textfield_color'           => array( 'login_input_text_color', __( 'Login field text color', 'ux-studio' ) ),
			'custom_button_color'       => array( 'login_button_bg_color', __( 'Login button background color', 'ux-studio' ) ),
			'button_text_color'         => array( 'login_button_text_color', __( 'Login button text color', 'ux-studio' ) ),
			'login_footer_color'        => array( 'login_text_color', __( 'Login screen text color', 'ux-studio' ) ),
			'button_hover_color'        => array( 'login_button_hover_bg_color', __( 'Login button background color on hover', 'ux-studio' ) ),
			'button_hover_text_color'   => array( 'login_button_hover_text_color', __( 'Login button text color on hover', 'ux-studio' ) ),
		);
		foreach ( $map as $key => $target ) {
			$raw = $this->str( $o, $key );
			if ( '' === $raw ) {
				continue;
			}
			$hex = $this->hex( $raw );
			if ( '' === $hex ) {
				$lossy[] = sprintf(
					/* translators: 1: setting, 2: value. */
					__( 'The color "%1$s" (%2$s) is not a plain hex color and was not transferred.', 'ux-studio' ),
					$key,
					$raw
				);
				continue;
			}
			$m->set( self::MODULE, $target[0], $hex, $target[1], $origin . ':' . $key );
			++$count;
		}
		$radius = $this->str( $o, 'customize_form_radius' );
		if ( '' !== $radius && is_numeric( preg_replace( '/px$/', '', $radius ) ) ) {
			$m->set( self::MODULE, 'login_form_border_radius', (int) $radius, __( 'Login form corner radius (px)', 'ux-studio' ), $origin . ':customize_form_radius' );
			++$count;
		}
		$welcome = $this->str( $o, 'welcome_message' );
		if ( '' !== $welcome ) {
			$m->set( self::MODULE, 'login_message', $welcome, __( 'Custom message above the login form', 'ux-studio' ), $origin . ':welcome_message' );
			++$count;
			if ( '' !== $this->str( $o, 'lostpwd_welcome_message' ) || '' !== $this->str( $o, 'register_welcome_message' ) ) {
				$lossy[] = __( 'The separate messages for the lost password and registration screens (the module shows one message on every login screen).', 'ux-studio' );
			}
		}
		// Background image (the plugin shows it full screen).
		$bg = $this->str( $o, 'setting_background' );
		if ( '' !== $bg ) {
			$bg_id = $this->attachment_id( $bg );
			if ( $bg_id > 0 ) {
				$m->set( self::MODULE, 'login_background_image', $bg_id, __( 'Login screen background image', 'ux-studio' ), $origin . ':setting_background' );
				$m->set( self::MODULE, 'login_background_style', 'cover', __( 'Login screen background image: fit', 'ux-studio' ), $origin . ':setting_background' );
				++$count;
			} else {
				$lossy[] = sprintf(
					/* translators: %s: file address. */
					__( 'The login background image %s is not in the media library of this site, so no attachment could be linked. Upload it and choose it in the Admin Customiser settings.', 'ux-studio' ),
					$bg
				);
			}
		}
		$custom_css = $this->str( $o, 'loginpress_custom_css' );
		if ( '' !== $custom_css ) {
			$m->set( self::MODULE, 'login_custom_css', $custom_css, __( 'Custom CSS of the login page', 'ux-studio' ), $origin . ':loginpress_custom_css' );
			$lossy[] = __( 'The own CSS of the login screen is copied as it is; UX Studio removes HTML tags from it and cuts it at 50 KB, so check the login screen after the switch.', 'ux-studio' );
		}
		if ( $count ) {
			$m->set( self::MODULE, 'login_customization_enabled', true, __( 'Turn on login screen customization', 'ux-studio' ), $origin );
		}

		$lost = array();
		foreach ( array(
			'gallery_background'          => __( 'background gallery', 'ux-studio' ),
			'lp_random_bg_img_check'      => __( 'rotating background images', 'ux-studio' ),
			'mobile_background'           => __( 'mobile background', 'ux-studio' ),
			'loginpress_display_bg_video' => __( 'background video', 'ux-studio' ),
			'setting_form_background'     => __( 'form background image', 'ux-studio' ),
			'customize_logo_width'        => __( 'logo size', 'ux-studio' ),
			'customize_logo_height'       => __( 'logo size', 'ux-studio' ),
			'customize_form_width'        => __( 'form width and padding', 'ux-studio' ),
			'customize_form_padding'      => __( 'form width and padding', 'ux-studio' ),
			'button_border_color'         => __( 'button border', 'ux-studio' ),
			'login_footer_text'           => __( 'custom "Lost your password?" text', 'ux-studio' ),
			'login_footer_copy_right'     => __( 'footer copyright text', 'ux-studio' ),
			'customize_form_shadow'       => __( 'shadows', 'ux-studio' ),
			'login_button_radius'         => __( 'button corner radius', 'ux-studio' ),
		) as $key => $name ) {
			if ( ! empty( $o[ $key ] ) && '0' !== (string) $o[ $key ] ) {
				$lost[ $name ] = true;
			}
		}
		if ( $lost ) {
			$lossy[] = sprintf(
				/* translators: %s: list. */
				__( 'Login screen settings without a target in the module: %s.', 'ux-studio' ),
				implode( ', ', array_keys( $lost ) )
			);
		}
		$behaviour = array();
		foreach ( array(
			'login_order'          => __( 'login with user name or e-mail', 'ux-studio' ),
			'enable_reg_pass_field' => __( 'password field on registration', 'ux-studio' ),
			'session_expiration'   => __( 'session length per role', 'ux-studio' ),
			'auto_login'           => __( 'automatic login after registration', 'ux-studio' ),
			'enable_privacy_policy' => __( 'privacy policy checkbox', 'ux-studio' ),
			'login_cust_error'     => __( 'custom error messages', 'ux-studio' ),
			'enable_repatcha'      => __( 'CAPTCHA', 'ux-studio' ),
		) as $key => $name ) {
			if ( ! empty( $s[ $key ] ) && 'off' !== $s[ $key ] ) {
				$behaviour[] = $name;
			}
		}
		if ( $behaviour ) {
			$lossy[] = sprintf(
				/* translators: %s: list. */
				__( 'Behaviour settings of the plugin are not transferred (UX Studio keeps the login behaviour in Security Optimization): %s.', 'ux-studio' ),
				implode( ', ', $behaviour )
			);
		}
		$lossy[] = __( 'LoginPress presets and templates are not transferred, only the values saved in the customizer.', 'ux-studio' );
		foreach ( array_unique( $lossy ) as $line ) {
			$m->lossy( $line );
		}
		return $m;
	}
}
