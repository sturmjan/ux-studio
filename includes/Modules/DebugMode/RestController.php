<?php
/**
 * Debug Mode REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DebugMode;

use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET uxstudio/v1/debug-mode/status - debug constants + debug.log tail.
 */
final class RestController extends Controller {

	private const MAX_LOG_BYTES = 131072; // Read at most the last 128 KB of the log.
	private const MAX_LOG_LINES = 200;

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/debug-mode/status', 'GET', array( $this, 'get_status' ) );
	}

	/**
	 * Report debug constants and the tail of the debug log.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_status( WP_REST_Request $request ) {
		$log_path = $this->log_path();
		$exists   = $log_path && file_exists( $log_path );

		return $this->ok(
			array(
				'constants' => array(
					'WP_DEBUG'         => defined( 'WP_DEBUG' ) ? (bool) WP_DEBUG : null,
					'WP_DEBUG_LOG'     => defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : null,
					'WP_DEBUG_DISPLAY' => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : null,
					'SCRIPT_DEBUG'     => defined( 'SCRIPT_DEBUG' ) ? (bool) SCRIPT_DEBUG : null,
					'SAVEQUERIES'      => defined( 'SAVEQUERIES' ) ? (bool) SAVEQUERIES : null,
				),
				'log'       => array(
					'path'     => $log_path,
					'exists'   => $exists,
					'size'     => $exists ? (int) filesize( $log_path ) : 0,
					'modified' => $exists ? (int) filemtime( $log_path ) : null,
					'tail'     => $exists ? $this->tail( $log_path ) : array(),
				),
			)
		);
	}

	/**
	 * Resolve the debug log path from WP_DEBUG_LOG.
	 */
	private function log_path(): ?string {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return null;
		}

		return is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Last lines of the log file, bounded in bytes and lines.
	 *
	 * @param string $path Log file path.
	 * @return string[]
	 */
	private function tail( string $path ): array {
		$size   = (int) filesize( $path );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array();
		}

		$offset = max( 0, $size - self::MAX_LOG_BYTES );
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$content = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$lines = preg_split( '/\r\n|\r|\n/', trim( $content ) );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		return array_slice( $lines, -self::MAX_LOG_LINES );
	}
}
