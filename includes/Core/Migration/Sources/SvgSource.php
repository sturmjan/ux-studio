<?php
/**
 * Source: Safe SVG, SVG Support.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Both plugins decide which roles may upload SVG; the module has the same list
 * (`allowed_roles`) and always sanitizes uploads itself.
 */
final class SvgSource extends AbstractSource {

	protected function option_names(): array {
		return 'safe-svg' === $this->slug ? array( 'safe_svg_upload_roles', 'safe_svg_large_svg' ) : array( 'bodhi_svgs_settings' );
	}

	protected function map( bool $active ): Mapping {
		return 'safe-svg' === $this->slug ? $this->map_safe_svg( $active ) : $this->map_svg_support( $active );
	}

	/**
	 * Safe SVG: `safe_svg_upload_roles` (empty = everybody who can upload files), `safe_svg_large_svg`.
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_safe_svg( bool $active ): Mapping {
		$m     = new Mapping();
		$roles = $this->opt( 'safe_svg_upload_roles' );
		if ( null === $roles && ! $active && null === $this->opt( 'safe_svg_large_svg' ) ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$roles = array_values( array_filter( array_map( 'strval', (array) $roles ) ) );
		if ( array() === $roles ) {
			// Plugin fallback: everybody with the upload_files capability.
			$roles = $this->roles_with_cap( 'upload_files' );
		}
		$m->set( 'svg-upload', 'allowed_roles', $roles, __( 'Allowed roles', 'ux-studio' ), 'safe_svg_upload_roles' );
		if ( ! empty( $this->opt( 'safe_svg_large_svg' ) ) ) {
			$m->lossy( __( 'The plugin allows large SVG files, the module keeps its own size limit.', 'ux-studio' ) );
		}
		return $m;
	}

	/**
	 * SVG Support: `bodhi_svgs_settings` { restrict: roles that may upload, css_target, ... }.
	 *
	 * @param bool $active Plugin active.
	 */
	private function map_svg_support( bool $active ): Mapping {
		$m = new Mapping();
		$o = $this->opt( 'bodhi_svgs_settings' );
		if ( ! is_array( $o ) ) {
			if ( ! $active ) {
				$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
				return $m;
			}
			$o = array();
		}
		$restrict = $o['restrict'] ?? 'on';
		if ( 'on' === $restrict ) {
			$restrict = array( 'administrator' ); // The plugin turns the legacy value into this.
		}
		$roles = array_values( array_diff( array_filter( array_map( 'strval', (array) $restrict ) ), array( 'none' ) ) );
		if ( $roles ) {
			$m->set( 'svg-upload', 'allowed_roles', $roles, __( 'Allowed roles', 'ux-studio' ), 'bodhi_svgs_settings:restrict' );
		} else {
			$m->lossy( __( 'The plugin has no role allowed to upload SVG ("none"). The module keeps its own list of allowed roles (administrators by default).', 'ux-studio' ) );
		}

		$lost = array();
		foreach ( array(
			'css_target'             => __( 'CSS class for inline SVG', 'ux-studio' ),
			'force_inline_svg'       => __( 'inline rendering', 'ux-studio' ),
			'minify_svg'             => __( 'minification', 'ux-studio' ),
			'sanitize_svg_front_end' => __( 'sanitizing on the front end', 'ux-studio' ),
			'advanced_mode'          => __( 'advanced mode', 'ux-studio' ),
		) as $key => $label ) {
			if ( ! empty( $o[ $key ] ) ) {
				$lost[] = $label;
			}
		}
		if ( $lost ) {
			$m->lossy(
				sprintf(
					/* translators: %s: comma-separated list of plugin features. */
					__( 'Not available in the module: %s.', 'ux-studio' ),
					implode( ', ', $lost )
				)
			);
		}
		return $m;
	}
}
