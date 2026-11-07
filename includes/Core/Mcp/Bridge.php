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
 * AI Assistant tools are stricter: only the ones in AI_WRITE_TOOLS get `write`, every other
 * tool that is not read-only needs `admin`.
 */
final class Bridge {

	public const AI_CATEGORY = 'ai-assistant';

	/**
	 * AI Assistant tools that change content and are fine for a `write` token. Allowlist on purpose:
	 * the tools run as the token owner (an administrator), so a new tool must not become available
	 * to `write` tokens just because nobody listed it. What these write is filtered by kses (a token
	 * without `admin` has no unfiltered_html, see Auth::limit_caps) and their URL parameters are
	 * checked (RestEndpointTool::build_route).
	 *
	 * Deliberately NOT here (= admin): users-create/update/delete (create an administrator, take over
	 * an account), settings-update (site e-mail, URLs, default role), woo-orders-create (sets paid
	 * orders, customers and sends shop e-mails), elementor-update-widget (raw builder data, see
	 * ElementorTools::update_widget_content).
	 *
	 * @var string[]
	 */
	public const AI_WRITE_TOOLS = array(
		'ai-assistant/posts-create',
		'ai-assistant/posts-update',
		'ai-assistant/posts-delete',
		'ai-assistant/pages-create',
		'ai-assistant/pages-update',
		'ai-assistant/pages-delete',
		'ai-assistant/categories-create',
		'ai-assistant/categories-update',
		'ai-assistant/categories-delete',
		'ai-assistant/tags-create',
		'ai-assistant/tags-update',
		'ai-assistant/tags-delete',
		'ai-assistant/media-delete',
		'ai-assistant/woo-products-create',
		'ai-assistant/woo-products-update',
		'ai-assistant/woo-products-delete',
	);

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
				'scope'        => self::ai_scope( (string) $ability->get_name(), $annotations ),
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
	 * Scope an AI Assistant tool needs: read-only = `read`, allowlisted = `write`, anything else `admin`.
	 *
	 * @param string               $name        Ability name (`ai-assistant/...`).
	 * @param array<string, mixed> $annotations Normalised annotations.
	 */
	public static function ai_scope( string $name, array $annotations ): string {
		if ( ! empty( $annotations['readonly'] ) ) {
			return Scopes::READ;
		}
		return in_array( $name, self::AI_WRITE_TOOLS, true ) ? Scopes::WRITE : Scopes::ADMIN;
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
