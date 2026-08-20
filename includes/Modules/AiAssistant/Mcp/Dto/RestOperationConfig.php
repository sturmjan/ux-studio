<?php
/**
 * DTO describing one MCP tool operation as a thin wrapper around an existing
 * WP core / WooCommerce REST route (wp/v2, wc/v3 - never uxstudio/v1).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\Mcp\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish config value object consumed by Tools\RestEndpointTool.
 */
final class RestOperationConfig {

	public string $operation_id;
	public string $label;
	public string $description;
	public string $method;
	public string $route;
	public string $namespace;
	public string $type;

	/** @var array<string, mixed|null> */
	public array $input_schema_modifications;

	/**
	 * @param string $operation_id                Unique suffix for the ability id (ai-assistant/{operation_id}).
	 * @param string $label                        Human readable label.
	 * @param string $description                  Human readable description.
	 * @param string $method                        HTTP method (GET/POST/PUT/DELETE).
	 * @param string $route                         Route path within $namespace, e.g. '/posts/(?P<id>[\d]+)'.
	 * @param string $namespace                     REST namespace being wrapped (wp/v2, wc/v3 - not uxstudio/v1).
	 * @param string $type                          MCP ability type ('tool' or 'resource').
	 * @param array<string, mixed|null> $input_schema_modifications Per-property overrides/removals applied after auto-derivation.
	 */
	public function __construct(
		string $operation_id,
		string $label,
		string $description,
		string $method,
		string $route,
		string $namespace = 'wp/v2',
		string $type = 'tool',
		array $input_schema_modifications = array()
	) {
		$this->operation_id               = $operation_id;
		$this->label                      = $label;
		$this->description                = $description;
		$this->method                     = $method;
		$this->route                      = $route;
		$this->namespace                  = $namespace;
		$this->type                       = $type;
		$this->input_schema_modifications = $input_schema_modifications;
	}
}
