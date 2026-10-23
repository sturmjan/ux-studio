<?php
/**
 * Registry of MCP abilities (tools) with their scope, schema and callback.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Independent of the WordPress Abilities API (core only since 6.9, the plugin supports 6.5):
 * the server reads this registry. Where the Abilities API exists, the same definitions are
 * mirrored into it (see Bootstrap) so other MCP transports see them too.
 *
 * Ability definition keys: name (`ns/slug`), label, description, scope, category,
 * input_schema (JSON schema object), annotations (readonly|destructive|idempotent),
 * callback (fn( array $input ): mixed), module (module id the ability needs, or ''),
 * confirm (false | true | callable describing what a confirmation does).
 */
final class Registry {

	/** @var array<string, array<string, mixed>>|null */
	private static ?array $cache = null;

	/** @var callable|null Test seam: fn( string $module_id ): bool. */
	private static $module_checker = null;

	/**
	 * Replace the "is this module on" lookup (tests).
	 *
	 * @param callable|null $checker Checker.
	 */
	public static function set_module_checker( ?callable $checker ): void {
		self::$module_checker = $checker;
	}

	/**
	 * Forget the built list.
	 */
	public static function reset(): void {
		self::$cache = null;
	}

	/**
	 * Is a UX Studio module on?
	 *
	 * @param string $module_id Module id.
	 */
	public static function module_enabled( string $module_id ): bool {
		if ( self::$module_checker ) {
			return (bool) ( self::$module_checker )( $module_id );
		}
		$plugin = \UxStudio\Plugin::instance();
		return isset( $plugin->modules ) && in_array( $module_id, $plugin->modules->enabled_ids(), true );
	}

	/**
	 * Tool name of an ability (`uxstudio/site-overview` -> `uxstudio-site-overview`).
	 *
	 * @param string $name Ability name.
	 */
	public static function tool_name( string $name ): string {
		return str_replace( '/', '-', $name );
	}

	/**
	 * All abilities whose module is on, keyed by ability name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$defs = array_merge(
				CoreAbilities::definitions(),
				MigrationAbilities::definitions(),
				Bridge::definitions()
			);
			/**
			 * Abilities exposed by the MCP server. Add-ons append definitions made with Registry::define().
			 *
			 * @param array<int, array<string, mixed>> $defs Definitions.
			 */
			$defs  = (array) apply_filters( 'uxstudio_mcp_abilities', $defs );
			$cache = array();
			foreach ( $defs as $def ) {
				if ( empty( $def['name'] ) || empty( $def['callback'] ) ) {
					continue;
				}
				$def = self::prepare( $def );
				if ( '' !== $def['module'] && ! self::module_enabled( $def['module'] ) ) {
					continue;
				}
				$cache[ $def['name'] ] = $def;
			}
			self::$cache = $cache;
		}
		return self::$cache;
	}

	/**
	 * Ability by its tool name.
	 *
	 * @param string $tool Tool name.
	 * @return array<string, mixed>|null
	 */
	public static function find_tool( string $tool ): ?array {
		foreach ( self::all() as $def ) {
			if ( $def['tool'] === $tool ) {
				return $def;
			}
		}
		return null;
	}

	/**
	 * Abilities a token with these scopes may call.
	 *
	 * @param string[] $granted Scopes of the token.
	 * @return array<string, array<string, mixed>>
	 */
	public static function visible( array $granted ): array {
		return array_filter( self::all(), static fn( array $def ): bool => Scopes::allows( $granted, (string) $def['scope'] ) );
	}

	/**
	 * Build a definition.
	 *
	 * @param string               $name        `ns/slug`.
	 * @param string               $label       Label.
	 * @param string               $description Description for the model (English on purpose).
	 * @param string               $scope       Required scope.
	 * @param array<string, mixed> $properties  Input properties.
	 * @param string[]             $required    Required properties.
	 * @param callable             $callback    fn( array $input ): mixed.
	 * @param array<string, mixed> $options     annotations, module, confirm, category.
	 * @return array<string, mixed>
	 */
	public static function define( string $name, string $label, string $description, string $scope, array $properties, array $required, callable $callback, array $options = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => (object) $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return array(
			'name'         => $name,
			'label'        => $label,
			'description'  => $description,
			'scope'        => $scope,
			'input_schema' => $schema,
			'callback'     => $callback,
			'annotations'  => (array) ( $options['annotations'] ?? array() ),
			'module'       => (string) ( $options['module'] ?? '' ),
			'confirm'      => $options['confirm'] ?? false,
			'category'     => (string) ( $options['category'] ?? strtok( $name, '/' ) ),
		);
	}

	/**
	 * Fill defaults and add the `confirm` parameter to abilities that need one.
	 *
	 * @param array<string, mixed> $def Definition.
	 * @return array<string, mixed>
	 */
	public static function prepare( array $def ): array {
		$def += array(
			'label'       => $def['name'],
			'description' => '',
			'scope'       => Scopes::ADMIN,
			'annotations' => array(),
			'module'      => '',
			'confirm'     => false,
			'category'    => strtok( (string) $def['name'], '/' ),
		);
		$schema = (array) ( $def['input_schema'] ?? array( 'type' => 'object' ) );
		if ( ! empty( $def['annotations']['destructive'] ) && ! $def['confirm'] ) {
			$def['confirm'] = true;
		}
		$props = isset( $schema['properties'] ) ? (array) $schema['properties'] : array();
		if ( $def['confirm'] && ! isset( $props['confirm'] ) ) {
			$props['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Must be true to carry out this action. Without it nothing happens and the reply explains what would change. Ask the user before setting it.',
			);
		}
		$schema['type']       = 'object';
		$schema['properties'] = (object) $props;
		$def['input_schema']  = $schema;
		$def['tool']          = self::tool_name( (string) $def['name'] );
		return $def;
	}

	/**
	 * Run an ability: defaults, validation, confirmation and module checks, then the callback.
	 *
	 * @param array<string, mixed> $def   Definition.
	 * @param array<string, mixed> $input Arguments from the client.
	 * @return mixed Ability result (not yet scrubbed).
	 * @throws McpError On invalid input, a missing confirmation or an ability error.
	 */
	public static function invoke( array $def, array $input ) {
		$schema = (array) $def['input_schema'];
		$schema['properties'] = (array) $schema['properties'];
		$input  = Schema::with_defaults( $schema, $input );
		$errors = Schema::validate( $schema, $input );
		if ( $errors ) {
			throw new McpError( 'invalid_input', 'Invalid arguments: ' . implode( ' ', $errors ), array( 'problems' => $errors ) );
		}
		if ( $def['confirm'] && empty( $input['confirm'] ) ) {
			$info = is_callable( $def['confirm'] ) ? (array) ( $def['confirm'] )( $input ) : array();
			throw new McpError(
				'confirmation_required',
				(string) ( $info['message'] ?? 'This action needs an explicit confirmation. Explain it to the user and call again with confirm=true once they agree.' ),
				(array) ( $info['data'] ?? array() )
			);
		}
		return ( $def['callback'] )( $input );
	}
}
