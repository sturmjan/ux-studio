<?php
/**
 * Error raised by an MCP ability; surfaced to the client as a structured tool error.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine readable code, a human message and optional data (e.g. the
 * refusal of the overlap guard, or what a `confirm` would do).
 */
final class McpError extends \RuntimeException {

	private string $error_code;

	/** @var array<string, mixed> */
	private array $data;

	/**
	 * @param string               $error_code Machine readable code.
	 * @param string               $message    Message for the user / agent.
	 * @param array<string, mixed> $data       Extra data.
	 */
	public function __construct( string $error_code, string $message, array $data = array() ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
		$this->data       = $data;
	}

	/** Machine readable code. */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Extra data.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Shape returned to the MCP client.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$out = array(
			'code'    => $this->error_code,
			'message' => $this->getMessage(),
		);
		if ( $this->data ) {
			$out['data'] = $this->data;
		}
		return array( 'error' => $out );
	}
}
