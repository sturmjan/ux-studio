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
 *  - GET  uxstudio/v1/third-party-login/callback  (PUBLIC) - the central app
 *      redirects the browser here with the signed OAuth result, for both
 *      `login` and `link` modes. Responds with a redirect, not JSON: the
 *      visitor's browser is what lands on this URL.
 *  - GET  uxstudio/v1/third-party-login/identities (cap: read) - the current
 *      user's linked providers.
 *  - POST uxstudio/v1/third-party-login/link/{provider}   (cap: read) - start a
 *      link handshake for the CURRENT user; returns the central-app redirect.
 *  - POST uxstudio/v1/third-party-login/unlink/{provider} (cap: read) -
 *      disconnect a provider from the CURRENT user.
 *
 * The callback is registered directly (not via Controller::route()) because it
 * must stay reachable by anonymous clients; all trust there comes from the HMAC
 * signature + single-use nonce, never current_user_can(). The self-service
 * routes act ONLY on get_current_user_id() and never accept a user id from the
 * request, so capability 'read' is safe (least privilege).
 */
final class RestController extends Controller {

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
				'methods'             => 'GET',
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
	 * Start a link handshake for the current user: returns the signed
	 * central-app URL, which carries a single-use nonce bound to this user and
	 * provider.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function start_link( WP_REST_Request $request ) {
		$provider = $this->request_provider( $request );
		if ( null === $provider || ! in_array( $provider, $this->module->enabled_providers(), true ) ) {
			return $this->bad_request();
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof WP_User ) || 0 === $user->ID || ! $this->module->is_user_allowed( $user ) ) {
			return new WP_Error( 'uxstudio_tpl_forbidden', __( 'Your role is not allowed to use third-party login.', 'ux-studio' ), array( 'status' => 403 ) );
		}

		$url = $this->module->handshake_url( $provider, 'link', $user->ID );
		if ( '' === $url ) {
			return $this->bad_request();
		}

		return $this->ok( array( 'redirect' => $url ) );
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
	 * The visitor's browser lands here, so every outcome is a redirect: on
	 * success to the profile / home, on failure back to wp-login.php with an
	 * opaque `uxstudio_tpl_error` code. The codes deliberately say nothing
	 * about which check failed beyond what the user can act on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void Always terminates with a redirect.
	 */
	public function callback( WP_REST_Request $request ) {
		$secret = Security::get_secret( Module::SECRET_HMAC );
		if ( '' === $secret || '' === $this->module->central_app_url() ) {
			return $this->fail( 'config' );
		}

		$params = $request->get_query_params();

		$provider = $this->detect_provider( $params );
		if ( null === $provider ) {
			return $this->fail( 'provider' );
		}

		$sub_param = $this->module->sub_param( $provider );
		foreach ( array( 'mode', $sub_param, 'email', 'nonce', 'ts', 'sig' ) as $required ) {
			if ( empty( $params[ $required ] ) ) {
				return $this->fail( 'invalid' );
			}
		}

		$mode = sanitize_key( (string) $params['mode'] );
		if ( ! in_array( $mode, array( 'login', 'link' ), true ) ) {
			return $this->fail( 'invalid' );
		}

		// Verify over exactly the fields the central app signed - the REST stack
		// may add its own (`rest_route` with plain permalinks), and those were
		// never part of the signature.
		$signed = array(
			'mode'     => (string) $params['mode'],
			$sub_param => (string) $params[ $sub_param ],
			'email'    => (string) $params['email'],
			'nonce'    => (string) $params['nonce'],
			'ts'       => (string) $params['ts'],
			'sig'      => (string) $params['sig'],
		);
		if ( ! empty( $params['provider'] ) ) {
			$signed['provider'] = (string) $params['provider'];
		}
		if ( 'link' === $mode ) {
			if ( empty( $params['user_id'] ) ) {
				return $this->fail( 'invalid' );
			}
			$signed['user_id'] = (string) $params['user_id'];
		}

		if ( ! Module::verify( $signed, $secret ) ) {
			return $this->fail( 'signature' );
		}

		// Single-use nonce: also pins the mode and provider chosen at start.
		$nonce_data = $this->module->consume_nonce( (string) $params['nonce'] );
		if ( null === $nonce_data ) {
			return $this->fail( 'expired' );
		}
		if ( $nonce_data['mode'] !== $mode ) {
			return $this->fail( 'invalid' );
		}
		if ( '' !== $nonce_data['provider'] && $nonce_data['provider'] !== $provider ) {
			return $this->fail( 'invalid' );
		}

		if ( ! in_array( $provider, $this->module->enabled_providers(), true ) ) {
			return $this->fail( 'provider' );
		}

		$sub   = sanitize_text_field( (string) $params[ $sub_param ] );
		$email = sanitize_email( (string) $params['email'] );
		if ( '' === $sub || ! is_email( $email ) ) {
			return $this->fail( 'invalid' );
		}

		if ( 'link' === $mode ) {
			return $this->handle_link( $provider, $email, $sub, $nonce_data['user_id'], (int) $params['user_id'] );
		}

		return $this->handle_login( $provider, $email, $sub );
	}

	/**
	 * Login / auto-create flow (mode=login).
	 *
	 * @param string $provider  Provider id (validated).
	 * @param string $email_raw Verified email (validated).
	 * @param string $sub       Provider subject id.
	 * @return void Always terminates with a redirect.
	 */
	private function handle_login( string $provider, string $email_raw, string $sub ) {
		$user             = $this->find_by_sub( $provider, $sub );
		$created_new_user = false;

		if ( ! ( $user instanceof WP_User ) ) {
			// Unknown identity: only create when auto-create is enabled.
			if ( ! $this->module->auto_create_enabled() ) {
				return $this->fail( 'notlinked' );
			}

			// Takeover protection: never attach to an existing account by email;
			// linking to an existing user only happens via the explicit,
			// authenticated link flow. Placeholder emails are rejected outright.
			if ( false !== stripos( $email_raw, '@unknown.local' ) ) {
				return $this->fail( 'placeholder_email' );
			}
			if ( get_user_by( 'email', $email_raw ) instanceof WP_User ) {
				return $this->fail( 'email_taken' );
			}

			$role = $this->module->auto_create_role();
			if ( '' === $role ) {
				return $this->fail( 'config' );
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
				return $this->fail( 'invalid' );
			}

			$this->store_identity( (int) $user_id, $provider, $sub, $email_raw );
			$user             = get_user_by( 'id', $user_id );
			$created_new_user = true;
		}

		if ( ! ( $user instanceof WP_User ) ) {
			return $this->fail( 'invalid' );
		}

		// Role gate applies to every login, existing or freshly created.
		if ( ! $this->module->is_user_allowed( $user ) ) {
			return $this->fail( 'role' );
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

		return $this->redirect( admin_url() );
	}

	/**
	 * Link flow (mode=link): bind the authenticated provider identity to the
	 * user that started the handshake.
	 *
	 * @param string $provider         Provider id (validated).
	 * @param string $email_raw        Verified email (validated).
	 * @param string $sub              Provider subject id.
	 * @param int    $expected_user_id User id recorded when the handshake started.
	 * @param int    $received_user_id User id echoed back by the central app.
	 * @return void Always terminates with a redirect.
	 */
	private function handle_link( string $provider, string $email_raw, string $sub, int $expected_user_id, int $received_user_id ) {
		if ( $expected_user_id <= 0 || $expected_user_id !== $received_user_id ) {
			return $this->fail( 'invalid' );
		}

		$user = get_user_by( 'id', $expected_user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return $this->fail( 'invalid' );
		}

		if ( ! $this->module->is_user_allowed( $user ) ) {
			return $this->fail( 'role' );
		}

		// Anti-hijack: refuse if this sub is already owned by a different user.
		$owner = $this->find_by_sub( $provider, $sub );
		if ( $owner instanceof WP_User && $owner->ID !== $user->ID ) {
			return $this->fail( 'sub_taken' );
		}

		$this->store_identity( $user->ID, $provider, $sub, $email_raw );

		ActivityLog::log( 'third-party-login', 'account_linked', 'user', $user->ID, array( 'provider' => $provider ) );

		return $this->redirect(
			add_query_arg(
				array(
					'uxstudio_tpl_linked'   => '1',
					'uxstudio_tpl_provider' => $provider,
				),
				admin_url( 'profile.php' )
			)
		);
	}

	/**
	 * Which provider does this callback belong to? Prefers the explicit
	 * `provider` parameter, falls back to whichever `{provider}_sub` is present
	 * (the central app's Google flow predates the explicit parameter).
	 *
	 * @param array $params Query parameters.
	 */
	private function detect_provider( array $params ): ?string {
		if ( ! empty( $params['provider'] ) ) {
			$candidate = sanitize_key( (string) $params['provider'] );
			return in_array( $candidate, Module::PROVIDERS, true ) ? $candidate : null;
		}
		foreach ( Module::PROVIDERS as $provider ) {
			if ( ! empty( $params[ $this->module->sub_param( $provider ) ] ) ) {
				return $provider;
			}
		}
		return null;
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
	 * Send the browser somewhere and stop - the callback is a navigation, not
	 * an API call, so a JSON body would just be shown as text.
	 *
	 * @param string $url Destination.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Bounce back to the login form with an opaque error code.
	 *
	 * @param string $code Error code for the login screen.
	 */
	private function fail( string $code ): void {
		$this->redirect( add_query_arg( 'uxstudio_tpl_error', $code, wp_login_url() ) );
	}

	/**
	 * Generic 400 for malformed self-service requests.
	 */
	private function bad_request(): WP_Error {
		return new WP_Error( 'uxstudio_tpl_bad_request', __( 'Invalid request.', 'ux-studio' ), array( 'status' => 400 ) );
	}
}
