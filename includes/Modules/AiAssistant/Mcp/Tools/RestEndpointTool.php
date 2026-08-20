<?php
/**
 * Generic wrapper that registers an existing WP REST route (wp/v2, wc/v3 -
 * never uxstudio/v1) as an MCP "ability" via wp_register_ability(). Purely
 * declarative: derives its JSON schema from the wrapped route's registered
 * args and forwards execution through rest_do_request() - no bespoke CRUD
 * logic lives here or in any subclass.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Tools;

use UxStudio\Modules\AiAssistant\Mcp\Dto\RestOperationConfig;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

abstract class RestEndpointTool {

	protected const CATEGORY = 'ai-assistant';

	/**
	 * @return RestOperationConfig[]
	 */
	abstract protected function get_operations(): array;

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->get_operations() as $config ) {
			$this->register_operation( $config );
		}
	}

	protected function register_operation( RestOperationConfig $config ): void {
		$input_schema = $this->build_input_schema( $config );

		$is_destructive = in_array( $config->method, array( 'DELETE' ), true );
		$is_readonly    = in_array( $config->method, array( 'GET' ), true );

		wp_register_ability(
			'ai-assistant/' . $config->operation_id,
			array(
				'label'               => $config->label,
				'description'         => $config->description,
				'category'            => self::CATEGORY,
				'execute_callback'    => function ( array $input ) use ( $config ) {
					return $this->execute_rest_request( $config, $input );
				},
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => $input_schema,
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => $config->type,
					),
					'annotations'  => array(
						'title'           => $config->label,
						'readonly'        => $is_readonly,
						'destructiveHint' => $is_destructive,
					),
				),
			)
		);
	}

	protected function build_input_schema( RestOperationConfig $config ): array {
		$server = rest_get_server();
		$routes = $server->get_routes( $config->namespace );

		$schema = array(
			'type'       => 'object',
			'properties' => array(),
		);

		$route_pattern = '/' . $config->namespace . $config->route;

		foreach ( $routes as $pattern => $handlers ) {
			if ( ! $this->route_matches( $route_pattern, $pattern ) ) {
				continue;
			}

			preg_match_all( '/\(\?P<(\w+)>[^)]+\)/', $pattern, $url_params );
			foreach ( $url_params[1] as $param_name ) {
				$schema['properties'][ $param_name ] = array(
					'type'        => 'string',
					/* translators: %s: URL parameter name. */
					'description' => sprintf( __( 'URL parameter: %s', 'ux-studio' ), $param_name ),
				);
			}

			foreach ( $handlers as $handler ) {
				if ( ! isset( $handler['methods'] ) || ! $this->method_matches( $config->method, $handler['methods'] ) ) {
					continue;
				}

				if ( ! empty( $handler['args'] ) ) {
					foreach ( $handler['args'] as $arg_name => $arg_def ) {
						if ( isset( $arg_def['readonly'] ) && $arg_def['readonly'] ) {
							continue;
						}
						$prop = $this->arg_to_schema_property( $arg_name, $arg_def );
						if ( $prop ) {
							$schema['properties'][ $arg_name ] = $prop;
						}
					}
				}

				break;
			}
			break;
		}

		foreach ( $config->input_schema_modifications as $key => $modification ) {
			if ( null === $modification ) {
				unset( $schema['properties'][ $key ] );
			} else {
				$schema['properties'][ $key ] = $modification;
			}
		}

		return $schema;
	}

	protected function execute_rest_request( RestOperationConfig $config, array $input ): array {
		$route = $config->route;

		foreach ( $input as $key => $value ) {
			$placeholder = '(?P<' . $key . '>';
			if ( false !== strpos( $route, $placeholder ) || false !== strpos( $route, '<' . $key . '>' ) ) {
				$route = preg_replace( '/\(\?P<' . $key . '>[^)]+\)/', (string) $value, $route );
				unset( $input[ $key ] );
			}
		}

		$url = '/' . $config->namespace . $route;

		$request = new WP_REST_Request( $config->method, $url );

		if ( in_array( $config->method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$request->set_body_params( $input );
		} else {
			$request->set_query_params( $input );
		}

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		return array(
			array(
				'text' => is_array( $data ) ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : (string) $data,
			),
		);
	}

	private function route_matches( string $target, string $pattern ): bool {
		$target_clean  = preg_replace( '/\(\?P<\w+>[^)]+\)/', '___PARAM___', $target );
		$pattern_clean = preg_replace( '/\(\?P<\w+>[^)]+\)/', '___PARAM___', $pattern );
		return $target_clean === $pattern_clean;
	}

	private function method_matches( string $method, $handler_methods ): bool {
		if ( is_string( $handler_methods ) ) {
			return strtoupper( $method ) === strtoupper( $handler_methods );
		}
		if ( is_array( $handler_methods ) ) {
			return isset( $handler_methods[ strtoupper( $method ) ] );
		}
		return false;
	}

	private function arg_to_schema_property( string $name, array $def ): ?array {
		$type = $def['type'] ?? 'string';

		if ( in_array( $type, array( 'object', 'array' ), true ) ) {
			if ( ! isset( $def['items'] ) && ! isset( $def['properties'] ) ) {
				return null;
			}
		}

		$prop = array( 'type' => $type );

		if ( isset( $def['description'] ) ) {
			$prop['description'] = $def['description'];
		}
		if ( isset( $def['enum'] ) ) {
			$prop['enum'] = $def['enum'];
		}
		if ( isset( $def['default'] ) ) {
			$prop['default'] = $def['default'];
		}
		if ( isset( $def['required'] ) && $def['required'] ) {
			$prop['required'] = true;
		}

		return $prop;
	}
}
