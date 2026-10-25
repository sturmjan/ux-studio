<?php
/**
 * Abilities that live in modules: Redirects and the AI Assistant content tools.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Modules\Redirects\Abilities as RedirectsAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * They keep their code where it is; the MCP core only maps them to its scopes:
 * read-only abilities need `read`, the others `write` (destructive ones also a `confirm`).
 */
final class Bridge {

	public const AI_CATEGORY = 'ai-assistant';

	/**
	 * Definitions of the module abilities.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$out = array();

		foreach ( RedirectsAbilities::definitions() as $def ) {
			$annotations = (array) $def['annotations'];
			$out[]       = array(
				'name'         => 'redirects/' . $def['slug'],
				'label'        => $def['label'],
				'description'  => $def['description'],
				'scope'        => ! empty( $annotations['readonly'] ) ? Scopes::READ : Scopes::WRITE,
				'input_schema' => $def['schema'],
				'annotations'  => $annotations,
				'callback'     => $def['callback'],
				'module'       => 'redirects',
				'category'     => 'redirects',
			);
		}

		foreach ( self::ai_abilities( 'tool' ) as $ability ) {
			$meta        = (array) $ability->get_meta();
			$annotations = (array) ( $meta['annotations'] ?? array() );
			// The AI Assistant tools use the MCP spelling (destructiveHint); normalise to ours.
			$annotations['readonly']    = ! empty( $annotations['readonly'] ) || ! empty( $annotations['readOnlyHint'] );
			$annotations['destructive'] = ! empty( $annotations['destructive'] ) || ! empty( $annotations['destructiveHint'] );
			$schema      = (array) $ability->get_input_schema();
			$out[]       = array(
				'name'         => $ability->get_name(),
				'label'        => $ability->get_label(),
				'description'  => $ability->get_description(),
				'scope'        => ! empty( $annotations['readonly'] ) ? Scopes::READ : Scopes::WRITE,
				'input_schema' => $schema ? $schema : array( 'type' => 'object', 'properties' => array() ),
				'annotations'  => $annotations,
				'callback'     => static fn( array $input ) => self::run_wp_ability( $ability, $input ),
				'module'       => 'ai-assistant',
				'category'     => self::AI_CATEGORY,
			);
		}
		return $out;
	}

	/**
	 * Read-only context the AI Assistant exposes as MCP resources (uri => ability).
	 *
	 * @return array<int, array{uri:string,name:string,description:string,ability:\WP_Ability}>
	 */
	public static function ai_resources(): array {
		if ( ! Registry::module_enabled( 'ai-assistant' ) ) {
			return array();
		}
		$out = array();
		foreach ( self::ai_abilities( 'resource' ) as $ability ) {
			$uri = (string) $ability->get_meta_item( 'uri', '' );
			if ( '' === $uri ) {
				continue;
			}
			$out[] = array(
				'uri'         => $uri,
				'name'        => $ability->get_label(),
				'description' => $ability->get_description(),
				'ability'     => $ability,
			);
		}
		return $out;
	}

	/**
	 * Abilities of the AI Assistant category (empty where the Abilities API does not exist).
	 *
	 * @param string $type `tool` or `resource`.
	 * @return \WP_Ability[]
	 */
	private static function ai_abilities( string $type ): array {
		if ( ! function_exists( 'wp_get_abilities' ) || ! Registry::module_enabled( 'ai-assistant' ) ) {
			return array();
		}
		$out = array();
		foreach ( wp_get_abilities() as $ability ) {
			if ( self::AI_CATEGORY !== $ability->get_category() ) {
				continue;
			}
			$meta = (array) $ability->get_meta();
			if ( $type !== (string) ( $meta['mcp']['type'] ?? 'tool' ) ) {
				continue;
			}
			$out[] = $ability;
		}
		return $out;
	}

	/**
	 * Execute an Abilities API ability, surfacing errors the MCP way.
	 *
	 * @param \WP_Ability          $ability Ability.
	 * @param array<string, mixed> $input   Arguments.
	 * @return mixed
	 * @throws McpError When the ability fails or refuses.
	 */
	public static function run_wp_ability( $ability, array $input = array() ) {
		unset( $input['confirm'] ); // Added by the MCP layer; the ability's own schema does not know it.
		$result = $ability->execute( $input ? $input : null );
		if ( is_wp_error( $result ) ) {
			throw new McpError( (string) $result->get_error_code(), $result->get_error_message() );
		}
		return $result;
	}
}
