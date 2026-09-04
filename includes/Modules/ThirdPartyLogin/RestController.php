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
 * Routes:
 *  - POST uxstudio/v1/third-party-login/callback  (PUBLIC) - central app posts
 *      the signed OAuth result here for both `login` and `link` modes.
 *  - GET  uxstudio/v1/third-party-login/identities (cap: read) - the current
 *      user's linked providers.
 *  - POST uxstudio/v1/third-party-login/link/{provider}   (cap: read) - start a
 *      link handshake for the CURRENT user; returns the central-app redirect.
 *  - POST uxstudio/v1/third-party-login/unlink/{provider} (cap: read) -
 *      disconnect a provider from the CURRENT user.
 *
 * The callback is registered directly (not via Controller::route()) because it
 * must stay reachable by anonymous clients; all trust there comes from the HMAC
 * signature + replay/state checks, never current_user_can(). The self-service
 * routes act ONLY on get_current_user_id() and never accept a user id from the
 * request, so capability 'read' is safe (least privilege).
 */
final class RestController extends Controller {

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

		$this->route( '/third-party-login/identities', 'GET', array( $this, 'get_identities' ), array(), 'read' );

		$provider_arg = array(
			'provider' => array(
				'required' => true,
				'type'     => 'string',
			),
		);

		$this->route( '/third-party-login/link/(?P<provider>[\w-]+)', 'POST', array( $this, 'start_link' ), $provider_arg, 'read' );
		$this->route( '/third-party-login/unlink/(?P<provider>[\w-]+)', 'POST', array( $this, 'unlink' ), $provider_arg, 'read' );
	}

	/**
	 * List the current user's provider identities (enabled providers only).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function get_identities( WP_REST_Request $request ) {
		$user    = wp_get_current_user();
		$labels  = $this->module->provider_labels();
		$enabled = $this->module->enabled_providers();

		$identities = array();
		foreach ( $enabled as $provider ) {
			$sub = (string) get_user_meta( $user->ID, $this->module->sub_meta_key( $provider ), true );
			$identities[] = array(
				'provider'  => $provider,
				'label'     => $labels[ $provider ] ?? $provider,
				'linked'    => '' !== $sub,
				'email'     => (string) get_user_meta( $user->ID, $this->module->email_meta_key( $provider ), true ),
				'linked_at' => (int) get_user_meta( $user->ID, $this->module->linked_at_meta_key( $provider ), true ),
			);
		}

		return $this->ok(
			array(
				'identities'   => $identities,
				'can_link'     => '' !== $this->module->central_app_url() && $this->module->has_secret(),
				'role_allowed' => $this->module->is_user_allowed( $user ),
			)
		);
	}

	/**
	 * Start a link handshake for the current user: returns the central-app URL
	 * carrying a signed state token that binds the eventual callback to this
	 * user + provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function start_link( WP_REST_Request $request ) {
		$provider = $this->request_provider( $request );
		if ( null === $provider || ! in_array( $provider, $this->module->enabled_providers(), true ) ) {
			return $this->bad_request();
		}

		if ( '' === $this->module->central_app_url() || ! $this->module->has_secret() ) {
			return $this->bad_request();
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) || 0 === $user->ID || ! $this->module->is_user_allowed( $user ) ) {
			return new WP_Error( 'uxstudio_tpl_forbidden', __( 'Your role is not allowed to use third-party login.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		$state = $this->module->create_link_state( $user->ID, $provider );
		if ( '' === $state ) {
			return $this->bad_request();
		}

		return $this->ok( array( 'redirect' => $this->module->handshake_url( $provider, 'link', $state ) ) );
	}

	/**
	 * Disconnect a provider identity from the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function unlink( WP_REST_Request $request ) {
		$provider = $this->request_provider( $request );
		if ( null === $provider ) {
			return $this->bad_request();
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->bad_request();
		}

		delete_user_meta( $user_id, $this->module->sub_meta_key( $provider ) );
		delete_user_meta( $user_id, $this->module->email_meta_key( $provider ) );
		delete_user_meta( $user_id, $this->module->linked_at_meta_key( $provider ) );

		ActivityLog::log( 'third-party-login', 'unlink', 'user', $user_id, array( 'provider' => $provider ) );

		return $this->ok(
			array(
				'provider' => $provider,
				'linked'   => false,
			)
		);
	}

	/**
	 * Verify the signed callback from the central app and either log the user in
	 * (mode=login) or link the provider identity to the initiating user
	 * (mode=link).
	 *
	 * Every failure collapses into the same generic 403 - no information about
	 * which check failed leaks, and no login/creation/link happens unless every
	 * check passes.
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
		$mode      = isset( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : 'login';
		$state     = isset( $params['state'] ) ? (string) $params['state'] : '';

		if ( ! in_array( $mode, array( 'login', 'link' ), true ) ) {
			return $generic_error;
		}

		if ( ! in_array( $provider, Module::PROVIDERS, true ) ) {
			return $generic_error;
		}

		if ( ! in_array( $provider, $this->module->enabled_providers(), true ) ) {
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
		$expected = hash_hmac( 'sha256', http_build_query( $signed_fields ), $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return $generic_error;
		}

		// Anti-replay: this exact signature must not have been used before.
		$replay_key = 'uxstudio_tpl_used_' . md5( $signature );
		if ( false !== get_transient( $replay_key ) ) {
			return $generic_error;
		}
		set_transient( $replay_key, 1, self::MAX_AGE + 10 );

		if ( 'link' === $mode ) {
			return $this->handle_link( $provider, $email_raw, $sub, $state, $generic_error );
		}

		return $this->handle_login( $provider, $email_raw, $sub, $generic_error );
	}

	/**
	 * Login / auto-create flow (mode=login).
	 *
	 * @param string   $provider      Provider id (validated).
	 * @param string   $email_raw     Verified email (validated).
	 * @param string   $sub           Provider subject id.
	 * @param WP_Error $generic_error Shared opaque error.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function handle_login( string $provider, string $email_raw, string $sub, WP_Error $generic_error ) {
		$user             = $this->find_by_sub( $provider, $sub );
		$created_new_user = false;

		if ( ! ( $user instanceof WP_User ) ) {
			// Unknown identity: only create when auto-create is enabled.
			if ( ! $this->module->auto_create_enabled() ) {
				return $generic_error;
			}

			// Takeover protection: never attach to an existing account by email;
			// linking to an existing user only happens via the explicit,
			// authenticated link flow. Placeholder emails are rejected outright.
			if ( false !== stripos( $email_raw, '@unknown.local' ) ) {
				return $generic_error;
			}
			if ( get_user_by( 'email', $email_raw ) instanceof WP_User ) {
				return $generic_error;
			}

			$role = $this->module->auto_create_role();
			if ( '' === $role ) {
				return $generic_error;
			}

			$username = $this->unique_username( $email_raw );
			$user_id  = wp_insert_user(
				array(
					'user_login' => $username,
					'user_email' => $email_raw,
					'user_pass'  => wp_generate_password( 32, true, true ),
					'role'       => $role,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $generic_error;
			}

			$this->store_identity( (int) $user_id, $provider, $sub, $email_raw );
			$user             = get_user_by( 'id', $user_id );
			$created_new_user = true;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return $generic_error;
		}

		// Role gate applies to every login, existing or freshly created.
		if ( ! $this->module->is_user_allowed( $user ) ) {
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

	/**
	 * Link flow (mode=link): bind the authenticated provider identity to the
	 * user carried by the signed state token.
	 *
	 * @param string   $provider      Provider id (validated).
	 * @param string   $email_raw     Verified email (validated).
	 * @param string   $sub           Provider subject id.
	 * @param string   $state         Signed link-state token.
	 * @param WP_Error $generic_error Shared opaque error.
	 * @return \WP_REST_Response|WP_Error
	 */
	private function handle_link( string $provider, string $email_raw, string $sub, string $state, WP_Error $generic_error ) {
		$user_id = $this->module->verify_link_state( $state, $provider );
		if ( $user_id <= 0 ) {
			return $generic_error;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return $generic_error;
		}

		if ( ! $this->module->is_user_allowed( $user ) ) {
			return $generic_error;
		}

		// Anti-hijack: refuse if this sub is already owned by a different user.
		$owner = $this->find_by_sub( $provider, $sub );
		if ( $owner instanceof WP_User && $owner->ID !== $user->ID ) {
			return $generic_error;
		}

		$this->store_identity( $user->ID, $provider, $sub, $email_raw );

		ActivityLog::log( 'third-party-login', 'account_linked', 'user', $user->ID, array( 'provider' => $provider ) );

		return $this->ok( array( 'redirect' => home_url() ) );
	}

	/**
	 * Find the single user linked to a provider `sub`, or null.
	 *
	 * @param string $provider Provider id.
	 * @param string $sub      Provider subject id.
	 */
	private function find_by_sub( string $provider, string $sub ): ?WP_User {
		$users = get_users(
			array(
				'meta_key'   => $this->module->sub_meta_key( $provider ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
			)
		);
		return empty( $users ) ? null : $users[0];
	}

	/**
	 * Persist the provider identity meta on a user.
	 *
	 * @param int    $user_id  User id.
	 * @param string $provider Provider id.
	 * @param string $sub      Provider subject id.
	 * @param string $email    Verified email.
	 */
	private function store_identity( int $user_id, string $provider, string $sub, string $email ): void {
		update_user_meta( $user_id, $this->module->sub_meta_key( $provider ), $sub );
		update_user_meta( $user_id, $this->module->email_meta_key( $provider ), $email );
		update_user_meta( $user_id, $this->module->linked_at_meta_key( $provider ), time() );
	}

	/**
	 * Derive a unique, sanitized username from an email address.
	 *
	 * @param string $email Email.
	 */
	private function unique_username( string $email ): string {
		$username = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $username || username_exists( $username ) ) {
			$username = sanitize_user( 'user_' . wp_generate_password( 8, false ), true );
		}
		return $username;
	}

	/**
	 * Validate and return the provider id from the request path, or null.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function request_provider( WP_REST_Request $request ): ?string {
		$provider = sanitize_key( (string) $request->get_param( 'provider' ) );
		return in_array( $provider, Module::PROVIDERS, true ) ? $provider : null;
	}

	/**
	 * Generic 400 for malformed self-service requests.
	 */
	private function bad_request(): WP_Error {
		return new WP_Error( 'uxstudio_tpl_bad_request', __( 'Invalid request.', 'ux-studio' ), array( 'status' => 400 ) );
	}
}
