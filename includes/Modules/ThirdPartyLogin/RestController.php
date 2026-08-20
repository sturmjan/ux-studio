<?php
/**
 * Third-Party Login REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ThirdPartyLogin;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/third-party-login/callback - PUBLIC endpoint the central
 * app posts the signed OAuth result to. Registered directly via
 * register_rest_route() (not Controller::route()) because it must stay
 * reachable by anonymous visitors; all authentication happens inside the
 * callback via HMAC + replay verification, never via current_user_can().
 */
final class RestController extends Controller {

	private const ALLOWED_PROVIDERS = array( 'google', 'facebook', 'apple' );

	private const MAX_AGE = 300; // seconds.

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/third-party-login/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * Verify the signed callback from the central app and log the user in.
	 *
	 * Failure modes (bad signature, expired/replayed timestamp, disabled
	 * provider, missing secret, etc.) are all collapsed into the same
	 * generic 403 error - no information about which check failed is
	 * leaked, and no login/user-creation happens unless every check passes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function callback( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$generic_error = new WP_Error(
			'uxstudio_tpl_invalid',
			__( 'Invalid request.', 'ux-studio' ),
			array( 'status' => 403 )
		);

		$provider  = isset( $params['provider'] ) ? sanitize_text_field( (string) $params['provider'] ) : '';
		$email_raw = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		$sub       = isset( $params['sub'] ) ? sanitize_text_field( (string) $params['sub'] ) : '';
		$timestamp = isset( $params['timestamp'] ) ? absint( $params['timestamp'] ) : 0;
		$signature = isset( $params['signature'] ) ? sanitize_text_field( (string) $params['signature'] ) : '';

		if ( ! in_array( $provider, self::ALLOWED_PROVIDERS, true ) ) {
			return $generic_error;
		}

		$enabled_providers = $this->module->enabled_providers();
		if ( ! in_array( $provider, $enabled_providers, true ) ) {
			return $generic_error;
		}

		if ( '' === $email_raw || ! is_email( $email_raw ) ) {
			return $generic_error;
		}

		if ( '' === $sub ) {
			return $generic_error;
		}

		if ( 0 === $timestamp || abs( time() - $timestamp ) > self::MAX_AGE ) {
			return $generic_error;
		}

		if ( '' === $signature || ! ctype_xdigit( $signature ) ) {
			return $generic_error;
		}

		$secret = Security::get_secret( Module::SECRET_HMAC );
		if ( '' === $secret ) {
			return $generic_error;
		}

		$signed_fields = array(
			'provider'  => $provider,
			'email'     => $email_raw,
			'sub'       => $sub,
			'timestamp' => $timestamp,
		);
		ksort( $signed_fields );
		$base     = http_build_query( $signed_fields );
		$expected = hash_hmac( 'sha256', $base, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return $generic_error;
		}

		// Anti-replay: this exact signature must not have been used before.
		$replay_key = 'uxstudio_tpl_used_' . md5( $signature );
		if ( false !== get_transient( $replay_key ) ) {
			return $generic_error;
		}
		set_transient( $replay_key, 1, self::MAX_AGE + 10 );

		$meta_key = "uxstudio_tpl_{$provider}_sub";

		$existing = get_users(
			array(
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
			)
		);

		$created_new_user = false;

		if ( ! empty( $existing ) ) {
			$user = $existing[0];
		} else {
			$user = get_user_by( 'email', $email_raw );

			if ( $user instanceof WP_User ) {
				update_user_meta( $user->ID, $meta_key, $sub );
			} else {
				$username = sanitize_user( current( explode( '@', $email_raw ) ), true );
				if ( '' === $username || username_exists( $username ) ) {
					$username = sanitize_user( 'user_' . wp_generate_password( 8, false ), true );
				}

				$user_id = wp_insert_user(
					array(
						'user_login' => $username,
						'user_email' => $email_raw,
						'user_pass'  => wp_generate_password( 32, true, true ),
					)
				);

				if ( is_wp_error( $user_id ) ) {
					return $generic_error;
				}

				update_user_meta( $user_id, $meta_key, $sub );
				$user             = get_user_by( 'id', $user_id );
				$created_new_user = true;
			}
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return $generic_error;
		}

		wp_set_auth_cookie( $user->ID, true );
		wp_set_current_user( $user->ID );

		ActivityLog::log(
			'third-party-login',
			$created_new_user ? 'account_created' : 'login',
			'user',
			$user->ID,
			array( 'provider' => $provider )
		);

		return $this->ok( array( 'redirect' => home_url() ) );
	}
}
