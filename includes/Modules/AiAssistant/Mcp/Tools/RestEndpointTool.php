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
		$matched       = false;

		foreach ( $routes as $pattern => $handlers ) {
			if ( ! $this->route_matches( $route_pattern, $pattern ) ) {
				continue;
			}
			$matched = true;

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

		// URL parameters: required, and a string one must match the route regex (the real check is
		// in build_route(), this tells the client up front).
		$required = array();
		foreach ( self::url_params( $config->route ) as $param_name => $regex ) {
			$required[] = $param_name;
			if ( ! isset( $schema['properties'][ $param_name ] ) ) {
				$schema['properties'][ $param_name ] = array( 'type' => 'string' );
			}
			if ( 'string' === ( $schema['properties'][ $param_name ]['type'] ?? '' ) ) {
				$schema['properties'][ $param_name ]['pattern'] = '^(?:' . $regex . ')$';
			}
		}
		if ( $required ) {
			$schema['required'] = $required;
		}
		// The arguments of the wrapped route are known: nothing else may be sent.
		if ( $matched ) {
			$schema['additionalProperties'] = false;
		}

		return $schema;
	}

	/**
	 * Named URL parameters of a route and their regex, e.g. `/posts/(?P<id>[\d]+)` => id => `[\d]+`.
	 *
	 * @param string $route Route pattern.
	 * @return array<string, string>
	 */
	public static function url_params( string $route ): array {
		preg_match_all( '/\(\?P<(\w+)>([^)]+)\)/', $route, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$out[ $match[1] ] = $match[2];
		}
		return $out;
	}

	/**
	 * Put the URL parameters of `$input` into the route. Every value must be a plain string or
	 * integer that matches the regex of its named group AND `^[A-Za-z0-9_-]+$`, so `batch`,
	 * `1/application-passwords`, `..`, `?` or `#` can never reach another route than the one
	 * the tool wraps.
	 *
	 * @param string               $route Route pattern (`/products/(?P<id>[\d]+)`).
	 * @param array<string, mixed> $input Tool input.
	 * @return array{route:string,input:array<string, mixed>}|\WP_Error Route and the remaining input.
	 */
	public static function build_route( string $route, array $input ) {
		$params = self::url_params( $route );
		foreach ( $params as $name => $regex ) {
			$value = $input[ $name ] ?? null;
			if ( is_int( $value ) ) {
				$value = (string) $value;
			}
			if ( ! is_string( $value ) || '' === $value ) {
				/* translators: %s: URL parameter name. */
				return new \WP_Error( 'invalid_input', sprintf( __( 'The URL parameter %s is required.', 'ux-studio' ), $name ), array( 'status' => 400 ) );
			}
			$own = '~^(?:' . str_replace( '~', '\~', $regex ) . ')$~D';
			if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) || ! preg_match( $own, $value ) ) {
				/* translators: %s: URL parameter name. */
				return new \WP_Error( 'invalid_input', sprintf( __( 'The URL parameter %s has an invalid value.', 'ux-studio' ), $name ), array( 'status' => 400 ) );
			}
			$params[ $name ] = $value;
			unset( $input[ $name ] );
		}
		$built = preg_replace_callback(
			'/\(\?P<(\w+)>[^)]+\)/',
			static fn( array $m ): string => $params[ $m[1] ],
			$route
		);
		return array(
			'route' => (string) $built,
			'input' => $input,
		);
	}

	/**
	 * Without `unfiltered_html` (an MCP token without the admin scope), WooCommerce `meta_data` may
	 * not touch protected (`_`-prefixed) keys such as `_elementor_data`, and its string values are
	 * run through wp_kses_post(): WooCommerce stores them as they come and themes print them.
	 *
	 * @param array<string, mixed> $input Body parameters.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function guard_meta( array $input ) {
		if ( ! isset( $input['meta_data'] ) || ! is_array( $input['meta_data'] ) || current_user_can( 'unfiltered_html' ) ) {
			return $input;
		}
		foreach ( $input['meta_data'] as $i => $meta ) {
			$key = is_array( $meta ) ? (string) ( $meta['key'] ?? '' ) : '';
			if ( '' === $key || is_protected_meta( $key, 'post' ) ) {
				return new \WP_Error( 'invalid_input', __( 'Protected meta keys cannot be written with this token.', 'ux-studio' ), array( 'status' => 403 ) );
			}
			if ( array_key_exists( 'value', $meta ) ) {
				$input['meta_data'][ $i ]['value'] = self::kses_deep( $meta['value'] );
			}
		}
		return $input;
	}

	/**
	 * wp_kses_post() on every string of a value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function kses_deep( $value ) {
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'kses_deep' ), $value );
		}
		return $value;
	}

	/**
	 * Forward the call to the wrapped route.
	 *
	 * @param RestOperationConfig  $config Operation.
	 * @param array<string, mixed> $input  Tool input.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	protected function execute_rest_request( RestOperationConfig $config, array $input ) {
		$built = self::build_route( $config->route, $input );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$input = self::guard_meta( $built['input'] );
		if ( is_wp_error( $input ) ) {
			return $input;
		}

		$url = '/' . $config->namespace . $built['route'];

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
