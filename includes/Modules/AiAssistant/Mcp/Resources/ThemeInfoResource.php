<?php
/**
 * MCP resource exposing active theme (and parent theme, if a child theme)
 * information.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Resources;

defined( 'ABSPATH' ) || exit;

class ThemeInfoResource {

	private const RESOURCE_URI = 'wordpress://theme-info';

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'ai-assistant/theme-info',
			array(
				'label'               => __( 'Theme Information', 'ux-studio' ),
				'description'         => __( 'Provides detailed information about the active WordPress theme and its parent theme if applicable', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'get_theme_info' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'uri'          => self::RESOURCE_URI,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'resource',
					),
					'annotations'  => array(
						'title'    => __( 'Theme Information', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);
	}

	public function get_theme_info( array $input = array() ): array {
		$theme    = wp_get_theme();
		$is_child = false !== $theme->parent();

		$theme_data = array(
			'uri'         => self::RESOURCE_URI,
			'text'        => '',
			'blob'        => '',
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'description' => $theme->get( 'Description' ),
			'author'      => $theme->get( 'Author' ),
			'theme_uri'   => $theme->get( 'ThemeURI' ),
			'template'    => $theme->get_template(),
			'stylesheet'  => $theme->get_stylesheet(),
			'is_child'    => $is_child,
		);

		if ( $is_child ) {
			$parent_theme          = $theme->parent();
			$theme_data['parent'] = array(
				'name'        => $parent_theme->get( 'Name' ),
				'version'     => $parent_theme->get( 'Version' ),
				'description' => $parent_theme->get( 'Description' ),
				'author'      => $parent_theme->get( 'Author' ),
				'theme_uri'   => $parent_theme->get( 'ThemeURI' ),
				'template'    => $parent_theme->get_template(),
				'stylesheet'  => $parent_theme->get_stylesheet(),
			);
		} else {
			$theme_data['parent'] = null;
		}

		return array( $theme_data );
	}
}
