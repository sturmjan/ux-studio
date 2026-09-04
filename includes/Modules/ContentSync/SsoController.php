<?php
/**
 * Node-side SSO endpoints: issue / revoke / sync operators.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\Security;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on the node. The hub (or central app) calls these over the same HMAC
 * channel as the other node endpoints. issue() mints a short-lived single-use
 * token that the operator's browser later redeems via SsoRedeemer.
 * All routes live under uxstudio/v1/content-sync/node/sso.
 */
final class SsoController {

	private const NS   = 'uxstudio/v1';
	private const BASE = '/content-sync/node/sso';

	/**
	 * Register SSO routes (HMAC-authenticated).
	 */
	public function register_routes(): void {
		$verify = array( $this, 'verify' );

		register_rest_route( self::NS, self::BASE . '/issue', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'issue' ),
			'permission_callback' => $verify,
		) );
		register_rest_route( self::NS, self::BASE . '/revoke-all', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'revoke_all' ),
			'permission_callback' => $verify,
		) );
		register_rest_route( self::NS, self::BASE . '/sync-operators', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'sync_operators' ),
			'permission_callback' => $verify,
		) );
	}

	/**
	 * Same HMAC verification as the node data endpoints.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function verify( WP_REST_Request $request ) {
		return HmacAuth::verify_request( $request, Security::get_secret( Module::SECRET_NODE_KEY ) );
	}

	/**
	 * Mint a single-use SSO login token for an operator.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function issue( WP_REST_Request $request ): WP_REST_Response {
		$params  = (array) $request->get_json_params();
		$email   = sanitize_email( (string) ( $params['operator_email'] ?? '' ) );
		$op_id   = (int) ( $params['operator_id'] ?? 0 );
		$c_role  = sanitize_text_field( (string) ( $params['central_role'] ?? '' ) );
		$wp_role = sanitize_key( (string) ( $params['target_wp_role'] ?? 'editor' ) );
		$action  = sanitize_key( (string) ( $params['action'] ?? 'dashboard' ) );
		$ret_to  = (string) ( $params['return_to'] ?? '/wp-admin/' );
		$ua_hash = sanitize_text_field( (string) ( $params['ua_hash'] ?? '' ) );
		$ip      = sanitize_text_field( (string) ( $params['ip'] ?? '' ) );

		if ( '' === $email || $op_id <= 0 ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'invalid_payload' ), 400 );
		}

		$mapped = SsoOperatorMapper::resolve( $email, $op_id, $wp_role );
		if ( empty( $mapped['user_id'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'user_mapping_failed:' . $mapped['source'] ), 422 );
		}

		// Per-operator issue rate limit.
		$rate_key = 'uxstudio_cs_sso_rate_' . md5( $email );
		$rate     = (int) get_transient( $rate_key );
		$max      = max( 1, (int) Module::setting( 'sso_max_issue_per_operator_hour', 30 ) );
		if ( $rate >= $max ) {
			return new WP_REST_Response( array( 'success' => false, 'error' => 'rate_limited_operator' ), 429 );
		}
		set_transient( $rate_key, $rate + 1, HOUR_IN_SECONDS );

		$issued = SsoTokenStore::issue(
			array(
				'operator_email'    => $email,
				'operator_id'       => $op_id,
				'central_role'      => $c_role,
				'target_wp_role'    => $wp_role,
				'target_wp_user_id' => (int) $mapped['user_id'],
				'action'            => $action,
				'return_to'         => $ret_to,
				'ua_hash'           => $ua_hash,
				'ip'                => $ip,
				'ttl_seconds'       => (int) Module::setting( 'sso_token_ttl_seconds', 60 ),
			)
		);

		// wp-login.php is intentionally never cached; SsoRedeemer runs on init there too.
		$login_url = add_query_arg( array( SsoRedeemer::QUERY_VAR => $issued['token'] ), wp_login_url() );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'login_url'  => $login_url,
				'token'      => $issued['token'],
				'expires_in' => max( 1, strtotime( $issued['expires_at'] ) - time() ),
				'mapped_via' => $mapped['source'],
			),
			200
		);
	}

	/**
	 * Revoke all outstanding tokens.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function revoke_all( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => true, 'revoked' => SsoTokenStore::revoke_all() ), 200 );
	}

	/**
	 * Refresh the operator->user mapping cache.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function sync_operators( WP_REST_Request $request ): WP_REST_Response {
		$params = (array) $request->get_json_params();
		$ops    = is_array( $params['operators'] ?? null ) ? $params['operators'] : array();
		return new WP_REST_Response( array( 'success' => true, 'updated' => SsoOperatorMapper::apply_sync( $ops ) ), 200 );
	}
}
