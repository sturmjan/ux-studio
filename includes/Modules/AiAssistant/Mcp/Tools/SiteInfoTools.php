<?php
/**
 * Custom MCP tool with a bespoke callback (not a REST route wrapper) that
 * returns aggregated site info for MCP tool-callers.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

defined( 'ABSPATH' ) || exit;

class SiteInfoTools extends RestEndpointTool {

	protected function get_operations(): array {
		return array();
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			// Distinct from Resources\SiteInfoResource's "ai-assistant/site-info" -
			// that one exposes site+plugins+themes+users as read-only MCP context,
			// this is an active tool returning live post-type counts.
			'ai-assistant/site-stats',
			array(
				'label'               => __( 'Get Site Stats', 'ux-studio' ),
				'description'         => __( 'Get comprehensive information about this WordPress site', 'ux-studio' ),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'get_site_info' ),
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
						'title'    => __( 'Get Site Stats', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);
	}

	public function get_site_info( array $input = array() ): array {
		$theme       = wp_get_theme();
		$post_types  = get_post_types( array( 'public' => true ), 'names' );
		$post_counts = array();
		foreach ( $post_types as $type ) {
			$counts = wp_count_posts( $type );
			$total  = 0;
			foreach ( $counts as $status => $count ) {
				if ( 'auto-draft' !== $status ) {
					$total += $count;
				}
			}
			$post_counts[ $type ] = $total;
		}

		return array(
			array(
				'text' => wp_json_encode(
					array(
						'site_name'         => get_bloginfo( 'name' ),
						'site_url'          => get_bloginfo( 'url' ),
						'description'       => get_bloginfo( 'description' ),
						'admin_email'       => get_bloginfo( 'admin_email' ),
						'wordpress_version' => get_bloginfo( 'version' ),
						'language'          => get_bloginfo( 'language' ),
						'timezone'          => wp_timezone_string(),
						'active_theme'      => array(
							'name'    => $theme->get( 'Name' ),
							'version' => $theme->get( 'Version' ),
						),
						'post_counts'       => $post_counts,
						'users_count'       => count_users()['total_users'],
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
				),
			),
		);
	}
}
