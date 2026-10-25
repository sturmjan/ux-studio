<?php
/**
 * Global switch of the MCP server (default OFF) and its endpoint.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in one option `uxstudio_mcp`. The old AI Assistant setting `mcp_enabled` is copied
 * into it exactly once (when the option does not exist yet).
 */
final class Config {

	public const OPTION   = 'uxstudio_mcp';
	public const ROUTE    = '/mcp';
	public const SETTINGS = 'uxstudio_ai_assistant';

	/**
	 * Is the server on?
	 */
	public static function enabled(): bool {
		return (bool) self::read()['enabled'];
	}

	/**
	 * Turn the server on / off.
	 *
	 * @param bool $enabled New state.
	 */
	public static function set_enabled( bool $enabled ): void {
		$state            = self::read();
		$state['enabled'] = $enabled;
		update_option( self::OPTION, $state, true );
	}

	/**
	 * Option content; creates it (with the migrated legacy value) on first use.
	 *
	 * @return array{enabled:bool,migrated_legacy:bool}
	 */
	private static function read(): array {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) ) {
			$legacy = get_option( self::SETTINGS, array() );
			$state  = array(
				'enabled'         => is_array( $legacy ) && ! empty( $legacy['mcp_enabled'] ),
				'migrated_legacy' => true,
			);
			update_option( self::OPTION, $state, true );
		}
		return array(
			'enabled'         => ! empty( $state['enabled'] ),
			'migrated_legacy' => ! empty( $state['migrated_legacy'] ),
		);
	}

	/**
	 * Full URL of the Streamable HTTP endpoint.
	 */
	public static function endpoint_url(): string {
		return rest_url( 'uxstudio/v1' . self::ROUTE );
	}
}
