<?php
/**
 * Hooks of the MCP server in the core.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Registered once from Plugin::boot(). Works without the AI Assistant module.
 */
final class Bootstrap {

	public const CATEGORY = 'uxstudio';

	/**
	 * Wire everything up.
	 */
	public static function register(): void {
		Auth::register();
		Server::register();
		add_action(
			'rest_api_init',
			static function (): void {
				( new AdminRest() )->register_routes();
			}
		);

		// Mirror the abilities into the WordPress Abilities API (core since 6.9), so other MCP
		// transports (the WordPress MCP Adapter plugin) offer them too. Their permission callback
		// applies the token scope, and admin tokens are the only ones such transports accept.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Ability category `uxstudio`.
	 */
	public static function register_category(): void {
		if ( ! Config::enabled() || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'UX Studio', 'ux-studio' ),
				'description' => __( 'Modules, settings and migration from other plugins.', 'ux-studio' ),
			)
		);
	}

	/**
	 * Register `uxstudio/*` in the Abilities API.
	 */
	public static function register_abilities(): void {
		if ( ! Config::enabled() || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( array_merge( CoreAbilities::definitions(), MigrationAbilities::definitions() ) as $raw ) {
			$def = Registry::prepare( $raw );
			if ( '' !== $def['module'] ) {
				continue;
			}
			$schema               = (array) $def['input_schema'];
			$schema['properties'] = (array) $schema['properties'];
			wp_register_ability(
				$def['name'],
				array(
					'label'               => $def['label'],
					'description'         => $def['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => $schema,
					'execute_callback'    => static function ( $input = null ) use ( $def ) {
						try {
							return Secrets::scrub( Registry::invoke( $def, is_array( $input ) ? $input : array() ) );
						} catch ( McpError $e ) {
							return new \WP_Error( 'uxstudio_mcp_' . $e->error_code(), $e->getMessage(), $e->data() );
						}
					},
					'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ) && Scopes::allows( CoreAbilities::caller_scopes(), (string) $def['scope'] ),
					'meta'                => array(
						'show_in_rest' => true,
						'mcp'          => array(
							'public' => true,
							'type'   => 'tool',
						),
						'annotations'  => (array) $def['annotations'] + array( 'title' => $def['label'] ),
					),
				)
			);
		}
	}
}
