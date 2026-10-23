<?php
/**
 * Admin routes of the MCP screen: switch, tokens, overview of abilities.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\ActivityLog;
use UxStudio\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * All need `manage_options` (base controller). Named `mcp-admin` so they never collide with
 * the MCP endpoint `uxstudio/v1/mcp` itself.
 *
 *   GET  mcp-admin          state: switch, endpoint, tokens, abilities
 *   POST mcp-admin/enabled  turn the server on / off
 *   POST mcp-admin/tokens   create a token (the secret is returned once)
 *   POST mcp-admin/revoke   revoke a token
 */
final class AdminRest extends Controller {

	public function register_routes(): void {
		$this->route( '/mcp-admin', 'GET', array( $this, 'state' ) );
		$this->route(
			'/mcp-admin/enabled',
			'POST',
			array( $this, 'set_enabled' ),
			array(
				'enabled' => array(
					'required'          => true,
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
		$this->route(
			'/mcp-admin/tokens',
			'POST',
			array( $this, 'create_token' ),
			array(
				'label'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'scopes' => array(
					'required' => true,
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
				),
				'days'   => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'ips'    => array(
					'required' => false,
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'default'  => array(),
				),
			)
		);
		$this->route(
			'/mcp-admin/revoke',
			'POST',
			array( $this, 'revoke_token' ),
			array(
				'id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	/**
	 * GET state.
	 */
	public function state(): WP_REST_Response {
		return $this->ok( self::payload() );
	}

	/**
	 * Everything the screen needs.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$abilities = array();
		foreach ( Registry::all() as $def ) {
			$abilities[] = array(
				'name'        => $def['name'],
				'tool'        => $def['tool'],
				'label'       => $def['label'],
				'category'    => $def['category'],
				'scope'       => $def['scope'],
				'readonly'    => ! empty( $def['annotations']['readonly'] ),
				'confirm'     => (bool) $def['confirm'],
			);
		}
		$tokens = array_merge( Tokens::all(), LegacyJwt::all() );
		return array(
			'enabled'       => Config::enabled(),
			'endpoint'      => Config::endpoint_url(),
			'site_name'     => sanitize_title( (string) get_bloginfo( 'name' ) ) ?: 'wordpress',
			'tokens'        => $tokens,
			'abilities'     => $abilities,
			'max_days'      => Tokens::MAX_DAYS,
			'rate_limit'    => (int) apply_filters( 'uxstudio_mcp_rate_limit', Tokens::RATE_PER_MINUTE ),
			'abilities_api' => function_exists( 'wp_register_ability' ),
			'ai_assistant'  => Registry::module_enabled( 'ai-assistant' ),
			'adapter'       => class_exists( '\WP\MCP\Core\McpAdapter' ),
		);
	}

	/**
	 * POST enabled.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function set_enabled( WP_REST_Request $request ): WP_REST_Response {
		$enabled = (bool) $request['enabled'];
		Config::set_enabled( $enabled );
		ActivityLog::log( 'mcp', $enabled ? 'server_enabled' : 'server_disabled', 'server', 0 );
		return $this->ok( array( 'enabled' => $enabled ) );
	}

	/**
	 * POST tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function create_token( WP_REST_Request $request ) {
		$ips = array();
		foreach ( (array) $request['ips'] as $line ) {
			foreach ( preg_split( '/[\s,;]+/', (string) $line ) ?: array() as $ip ) {
				$ips[] = $ip;
			}
		}
		try {
			$created = Tokens::create(
				array(
					'label'   => (string) $request['label'],
					'user_id' => get_current_user_id(),
					'scopes'  => (array) $request['scopes'],
					'days'    => (int) $request['days'],
					'ips'     => $ips,
				)
			);
		} catch ( McpError $e ) {
			return new \WP_Error( 'uxstudio_mcp_' . $e->error_code(), $e->getMessage(), array( 'status' => 400 ) + $e->data() );
		}
		$record = $created['record'];
		ActivityLog::log(
			'mcp',
			'token_created',
			'token',
			0,
			array(
				'token_id'   => $record['id'],
				'label'      => $record['label'],
				'scopes'     => $record['scopes'],
				'expires_at' => $record['expires_at'],
				'ip_limited' => (bool) $record['ips'],
			)
		);
		return $this->ok(
			array(
				'token'  => $created['token'],
				'record' => $record,
			)
		);
	}

	/**
	 * POST revoke.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revoke_token( WP_REST_Request $request ) {
		$id      = (string) $request['id'];
		$revoked = LegacyJwt::is_legacy_id( $id ) ? LegacyJwt::revoke( $id ) : Tokens::revoke( $id );
		if ( ! $revoked ) {
			return new \WP_Error( 'uxstudio_mcp_token_not_found', __( 'Token not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		ActivityLog::log( 'mcp', 'token_revoked', 'token', 0, array( 'token_id' => $id ) );
		return $this->ok( array( 'revoked' => true ) );
	}
}
