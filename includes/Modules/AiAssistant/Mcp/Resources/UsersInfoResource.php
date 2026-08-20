<?php
/**
 * MCP resource exposing registered users and their roles.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Resources;

defined( 'ABSPATH' ) || exit;

class UsersInfoResource {

	private const RESOURCE_URI = 'wordpress://users-info';

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'ai-assistant/users-info',
			array(
				'label'               => __( 'Users Information', 'ux-studio' ),
				'description'         => __( 'Provides detailed information about registered WordPress users and their roles', 'ux-studio' ),
				'category'            => 'ai-assistant',
				'execute_callback'    => array( $this, 'get_users_info' ),
				'permission_callback' => function () {
					return current_user_can( 'list_users' );
				},
				'meta'                => array(
					'uri'          => self::RESOURCE_URI,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'resource',
					),
					'annotations'  => array(
						'title'    => __( 'Users Information', 'ux-studio' ),
						'readonly' => true,
					),
				),
			)
		);
	}

	public function get_users_info(): array {
		$users       = get_users();
		$users_count = count_users();
		$users_data  = array();

		foreach ( $users as $user ) {
			$users_data[] = array(
				'id'           => $user->ID,
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
				'registered'   => $user->user_registered,
			);
		}

		return array(
			array(
				'uri'         => self::RESOURCE_URI,
				'text'        => '',
				'blob'        => '',
				'total_users' => $users_count['total_users'],
				'users'       => $users_data,
				'roles'       => $users_count['avail_roles'],
			),
		);
	}
}
