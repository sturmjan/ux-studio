<?php
/**
 * Custom MCP tools (not REST route wrappers) exposing read/write access to
 * Elementor page structure. Registered only when Elementor is active.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

defined( 'ABSPATH' ) || exit;

class ElementorTools {

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! defined( 'ELEMENTOR_VERSION' ) ) {
			return;
		}

		wp_register_ability(
			'ai-assistant/elementor-list-pages',
			array(
				'label'               => __( 'List Elementor Pages', 'ux-studio' ),
				'description'         => __( 'List all pages built with Elementor', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'list_pages' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'title'    => __( 'List Elementor Pages', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);

		wp_register_ability(
			'ai-assistant/elementor-get-structure',
			array(
				'label'               => __( 'Get Page Structure', 'ux-studio' ),
				'description'         => __( 'Get the Elementor widget structure of a page', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'get_page_structure' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Page/Post ID', 'ux-studio' ),
							'required'    => true,
						),
					),
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'title'    => __( 'Get Page Structure', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);

		wp_register_ability(
			'ai-assistant/elementor-find-widgets',
			array(
				'label'               => __( 'Find Widgets', 'ux-studio' ),
				'description'         => __( 'Search for specific widget types in a page', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'find_widgets' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array(
							'type'        => 'integer',
							'description' => __( 'Page/Post ID', 'ux-studio' ),
							'required'    => true,
						),
						'widget_type' => array(
							'type'        => 'string',
							'description' => __( 'Widget type to search for (e.g. heading, text-editor, image)', 'ux-studio' ),
						),
					),
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'title'    => __( 'Find Widgets', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);

		wp_register_ability(
			'ai-assistant/elementor-update-widget',
			array(
				'label'               => __( 'Update Widget Content', 'ux-studio' ),
				'description'         => __( 'Update the content of an Elementor widget', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'update_widget_content' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array(
							'type'        => 'integer',
							'description' => __( 'Page/Post ID', 'ux-studio' ),
							'required'    => true,
						),
						'widget_id' => array(
							'type'        => 'string',
							'description' => __( 'Widget ID', 'ux-studio' ),
							'required'    => true,
						),
						'settings'  => array(
							'type'        => 'string',
							'description' => __( 'JSON string of settings to update', 'ux-studio' ),
							'required'    => true,
						),
					),
				),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'title' => __( 'Update Widget Content', 'ux-studio' ),
					),
				),
			)
		);
	}

	public function list_pages( array $input = array() ): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'posts_per_page' => -1,
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$pages = array();
		foreach ( $posts as $post ) {
			$pages[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'url'      => get_permalink( $post->ID ),
				'edit_url' => admin_url( "post.php?post={$post->ID}&action=edit" ),
			);
		}

		return array( array( 'text' => wp_json_encode( $pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) );
	}

	public function get_page_structure( array $input = array() ): array {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return array( array( 'text' => '{"error": "post_id is required"}' ) );
		}

		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) ) {
			return array( array( 'text' => '{"error": "No Elementor data found"}' ) );
		}

		$elements  = is_string( $data ) ? json_decode( $data, true ) : $data;
		$structure = $this->extract_structure( (array) $elements );

		return array( array( 'text' => wp_json_encode( $structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) );
	}

	public function find_widgets( array $input = array() ): array {
		$post_id     = (int) ( $input['post_id'] ?? 0 );
		$widget_type = $input['widget_type'] ?? '';

		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $data ) ) {
			return array( array( 'text' => '{"error": "No Elementor data found"}' ) );
		}

		$elements = is_string( $data ) ? json_decode( $data, true ) : $data;
		$widgets  = $this->find_widgets_recursive( (array) $elements, $widget_type );

		return array( array( 'text' => wp_json_encode( $widgets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) );
	}

	public function update_widget_content( array $input = array() ): array {
		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$widget_id    = $input['widget_id'] ?? '';
		$new_settings = json_decode( $input['settings'] ?? '{}', true );

		if ( ! $post_id || ! $widget_id || empty( $new_settings ) ) {
			return array( array( 'text' => '{"error": "post_id, widget_id and settings are required"}' ) );
		}

		$data     = get_post_meta( $post_id, '_elementor_data', true );
		$elements = is_string( $data ) ? json_decode( $data, true ) : $data;

		if ( ! is_array( $elements ) ) {
			return array( array( 'text' => '{"error": "Invalid Elementor data"}' ) );
		}

		$updated = $this->update_widget_recursive( $elements, $widget_id, $new_settings );

		update_post_meta( $post_id, '_elementor_data', wp_json_encode( $updated ) );

		if ( class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array( array( 'text' => wp_json_encode( array( 'success' => true, 'widget_id' => $widget_id ) ) ) );
	}

	private function extract_structure( array $elements, int $depth = 0 ): array {
		$result = array();
		foreach ( $elements as $element ) {
			$item = array(
				'id'         => $element['id'] ?? '',
				'elType'     => $element['elType'] ?? '',
				'widgetType' => $element['widgetType'] ?? null,
			);

			if ( ! empty( $element['elements'] ) ) {
				$item['children'] = $this->extract_structure( $element['elements'], $depth + 1 );
			}

			$result[] = $item;
		}
		return $result;
	}

	private function find_widgets_recursive( array $elements, string $type ): array {
		$found = array();
		foreach ( $elements as $element ) {
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				if ( empty( $type ) || ( $element['widgetType'] ?? '' ) === $type ) {
					$found[] = array(
						'id'         => $element['id'] ?? '',
						'widgetType' => $element['widgetType'] ?? '',
						'settings'   => $element['settings'] ?? array(),
					);
				}
			}
			if ( ! empty( $element['elements'] ) ) {
				$found = array_merge( $found, $this->find_widgets_recursive( $element['elements'], $type ) );
			}
		}
		return $found;
	}

	private function update_widget_recursive( array $elements, string $widget_id, array $new_settings ): array {
		foreach ( $elements as &$element ) {
			if ( ( $element['id'] ?? '' ) === $widget_id ) {
				$element['settings'] = array_merge( $element['settings'] ?? array(), $new_settings );
			}
			if ( ! empty( $element['elements'] ) ) {
				$element['elements'] = $this->update_widget_recursive( $element['elements'], $widget_id, $new_settings );
			}
		}
		return $elements;
	}
}
