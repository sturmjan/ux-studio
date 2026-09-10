<?php
/**
 * Third-Party Login module - sign in via Google/Facebook/Apple/Seznam through
 * the central app as an OAuth proxy.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ThirdPartyLogin;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Narrower re-implementation of the legacy third-party-login module: OAuth
 * credentials live in the central app, not here. This module renders the
 * login buttons that kick off the flow and exposes the public REST callback
 * the central app redirects back to.
 *
 * HANDSHAKE PROTOCOL. The central app implements exactly one third-party-login
 * protocol (`BaseAuthController`), and this module speaks it:
 *
 *   1. WP -> CA   GET {CA}/?page={provider}_auth&action=init
 *                 &site_url&return_url&mode&nonce&ts[&user_id]&sig
 *   2. CA -> IdP  the provider's own OAuth dance (credentials never touch WP)
 *   3. CA -> WP   GET {return_url}?provider&mode&{provider}_sub&email
 *                 &nonce&ts[&user_id]&sig
 *
 * `sig` is HMAC-SHA256 over the ksort()ed, http_build_query()-encoded params
 * (minus `sig`) with the per-site shared secret; both directions use the same
 * routine. `nonce` is single-use, lives in a transient, and carries the mode,
 * provider and initiating user so the callback cannot be swapped for another.
 *
 * On top of the OAuth-proxy core this module adds:
 *  - ROLE GATING: only users whose role is in `allowed_roles` may log in or be
 *    created via a provider (fail-closed: empty list = nobody).
 *  - opt-in AUTO-CREATE of a WordPress user on first login, with a configurable
 *    default role (off by default; administrator can NEVER be auto-assigned).
 *  - LINK/UNLINK self-service: a logged-in user connects/disconnects a provider
 *    identity, stored as user meta, via authenticated REST routes.
 *
 * The HMAC secret shared with the central app is never stored in the plain
 * settings option; it goes through Security::store_secret() and is never echoed
 * back via REST (only a boolean "has_hmac_secret" is exposed).
 */
final class Module extends BaseModule {

	public const SECRET_HMAC = 'uxstudio_secret_third_party_login_hmac';

	/** Providers this module understands. Order drives the login-form buttons. */
	public const PROVIDERS = array( 'google', 'facebook', 'apple', 'seznam' );

	/** Query var that starts a login handshake from the front end. */
	public const INIT_QUERY_VAR = 'uxstudio_tpl';

	/** Transient prefix for the single-use handshake nonce. */
	public const NONCE_TRANSIENT_PREFIX = 'uxstudio_tpl_nonce_';

	/** How long a started handshake may stay unfinished (seconds). */
	public const NONCE_TTL = 900;

	/** Replay window the central app is allowed to sign within (seconds). */
	public const MAX_AGE = 300;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'login_form', array( $this, 'render_login_buttons' ) );
		add_action( 'template_redirect', array( $this, 'maybe_start_handshake' ) );
		add_action( 'login_init', array( $this, 'maybe_start_handshake' ) );
		add_filter( 'uxstudio_rest_public_routes', array( $this, 'allow_public_callback_route' ) );
	}

	/**
	 * Keep the callback reachable when Security Optimization restricts the REST
	 * API to logged-in users: the visitor arriving here is anonymous by
	 * definition - logging them in is the point. The route is protected by the
	 * HMAC signature and the single-use nonce, not by the session.
	 *
	 * @param array<int, string> $routes Route prefixes already exempted.
	 * @return array<int, string>
	 */
	public function allow_public_callback_route( $routes ): array {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = '/uxstudio/v1/third-party-login/callback';
		return $routes;
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Admin manages settings for this module; the login itself is for
	 * anonymous visitors and handled separately by the public REST callback.
	 */
	public function capability(): string {
		return 'manage_options';
	}

	/**
	 * Settings schema for the generic renderer / embedded Settings tab.
	 *
	 * Role option lists are built from the live role registry so the admin can
	 * only pick roles that actually exist on the site.
	 */
	public function settings_schema(): array {
		$all_roles = $this->role_names();

		// Auto-create must never be able to grant administrator.
		$create_roles = $all_roles;
		unset( $create_roles['administrator'] );

		return array(
			array(
				'key'     => 'central_app_url',
				'type'    => 'text',
				'label'   => __( 'Central app URL', 'ux-studio' ),
				'help'    => __( 'Base URL of the central app that performs the OAuth handshake, e.g. https://ux1.cz/app/.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hmac_secret',
				'type'    => 'text',
				'label'   => __( 'HMAC secret', 'ux-studio' ),
				'help'    => __( 'Shared secret from the central app (site detail, "Third-party login"). Signs the handshake in both directions. Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'enabled_providers',
				'type'    => 'multiselect',
				'label'   => __( 'Enabled providers', 'ux-studio' ),
				'help'    => __( 'A provider also has to be configured centrally and enabled for this site in the central app.', 'ux-studio' ),
				'options' => array(
					'google'   => __( 'Google', 'ux-studio' ),
					'facebook' => __( 'Facebook', 'ux-studio' ),
					'apple'    => __( 'Apple', 'ux-studio' ),
					'seznam'   => __( 'Seznam', 'ux-studio' ),
				),
				'default' => array(),
			),
			array(
				'key'     => 'allowed_roles',
				'type'    => 'multiselect',
				'label'   => __( 'Allowed roles', 'ux-studio' ),
				'help'    => __( 'Only users whose role is selected here may log in (or be auto-created) via a provider. Leave empty to block third-party login for everyone.', 'ux-studio' ),
				'options' => $all_roles,
				'default' => array(),
			),
			array(
				'key'     => 'auto_create_enabled',
				'type'    => 'toggle',
				'label'   => __( 'Auto-create accounts on first login', 'ux-studio' ),
				'help'    => __( 'When on, an unknown verified provider identity creates a new WordPress user. When off, unknown identities are rejected and users must link a provider from their profile after logging in.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'auto_create_role',
				'type'    => 'select',
				'label'   => __( 'Default role for auto-created accounts', 'ux-studio' ),
				'help'    => __( 'Role assigned to auto-created users. Administrator is intentionally not selectable. This role should also be in "Allowed roles", otherwise the new account cannot log in.', 'ux-studio' ),
				'options' => $create_roles,
				'default' => 'subscriber',
			),
		);
	}

	/**
	 * Intercept the hmac_secret field before it reaches the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'hmac_secret', $input ) && '' !== (string) $input['hmac_secret'] ) {
			Security::store_secret( self::SECRET_HMAC, (string) $input['hmac_secret'] );
		}
		unset( $input['hmac_secret'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secret back to the client; expose only whether it's set.
	 */
	public function settings_values(): array {
		$values                    = parent::settings_values();
		$values['hmac_secret']     = '';
		$values['has_hmac_secret'] = '' !== Security::get_secret( self::SECRET_HMAC );
		return $values;
	}

	/**
	 * Enabled provider keys (subset of self::PROVIDERS).
	 *
	 * @return array<int, string>
	 */
	public function enabled_providers(): array {
		return array_values(
			array_intersect(
				self::PROVIDERS,
				array_map( 'strval', (array) $this->settings->get( 'enabled_providers', array() ) )
			)
		);
	}

	/**
	 * Configured central app base URL (may be empty).
	 */
	public function central_app_url(): string {
		return (string) $this->settings->get( 'central_app_url', '' );
	}

	/**
	 * Whether the shared HMAC secret is configured.
	 */
	public function has_secret(): bool {
		return '' !== Security::get_secret( self::SECRET_HMAC );
	}

	/**
	 * Roles allowed to authenticate via a provider.
	 *
	 * @return array<int, string>
	 */
	public function allowed_roles(): array {
		return array_map( 'strval', (array) $this->settings->get( 'allowed_roles', array() ) );
	}

	/**
	 * Whether auto-create on first login is enabled.
	 */
	public function auto_create_enabled(): bool {
		return (bool) $this->settings->get( 'auto_create_enabled', false );
	}

	/**
	 * Resolved default role for auto-created accounts. Never administrator;
	 * falls back to 'subscriber' (or '' if that role is absent).
	 */
	public function auto_create_role(): string {
		$role = sanitize_key( (string) $this->settings->get( 'auto_create_role', 'subscriber' ) );
		if ( 'administrator' === $role || '' === $role || ! get_role( $role ) ) {
			$role = get_role( 'subscriber' ) ? 'subscriber' : '';
		}
		return $role;
	}

	/**
	 * Role gate: is this user allowed to authenticate via a provider?
	 * Fail-closed: an empty allow-list denies everyone.
	 *
	 * @param WP_User $user User to test.
	 */
	public function is_user_allowed( WP_User $user ): bool {
		$allowed = $this->allowed_roles();
		if ( empty( $allowed ) ) {
			return false;
		}
		foreach ( (array) $user->roles as $role ) {
			if ( in_array( (string) $role, $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * User-meta key storing the provider `sub` (stable subject id) for a user.
	 *
	 * @param string $provider Provider id.
	 */
	public function sub_meta_key( string $provider ): string {
		return 'uxstudio_tpl_' . $provider . '_sub';
	}

	/**
	 * User-meta key storing the email recorded at link/create time.
	 *
	 * @param string $provider Provider id.
	 */
	public function email_meta_key( string $provider ): string {
		return 'uxstudio_tpl_' . $provider . '_email';
	}

	/**
	 * User-meta key storing the link timestamp.
	 *
	 * @param string $provider Provider id.
	 */
	public function linked_at_meta_key( string $provider ): string {
		return 'uxstudio_tpl_' . $provider . '_linked_at';
	}

	/**
	 * Query parameter the central app puts the provider subject id in.
	 *
	 * @param string $provider Provider id.
	 */
	public function sub_param( string $provider ): string {
		return $provider . '_sub';
	}

	/**
	 * Human-readable provider labels.
	 *
	 * @return array<string, string>
	 */
	public function provider_labels(): array {
		return array(
			'google'   => __( 'Google', 'ux-studio' ),
			'facebook' => __( 'Facebook', 'ux-studio' ),
			'apple'    => __( 'Apple', 'ux-studio' ),
			'seznam'   => __( 'Seznam', 'ux-studio' ),
		);
	}

	/**
	 * Public REST endpoint the central app redirects back to.
	 */
	public function callback_url(): string {
		return rest_url( 'uxstudio/v1/third-party-login/callback' );
	}

	// ---------------------------------------------------------------------
	//  Handshake signing - must stay byte-identical to the central app's
	//  signer, otherwise every request is rejected as a bad signature.
	// ---------------------------------------------------------------------

	/**
	 * HMAC-SHA256 over the ksort()ed params (minus `sig`).
	 *
	 * @param array  $params Parameters to sign.
	 * @param string $secret Shared secret.
	 */
	public static function sign( array $params, string $secret ): string {
		unset( $params['sig'] );
		ksort( $params );
		return hash_hmac( 'sha256', http_build_query( $params ), $secret );
	}

	/**
	 * Constant-time signature check plus the replay window on `ts`.
	 *
	 * @param array  $params Parameters as received (including `sig` and `ts`).
	 * @param string $secret Shared secret.
	 */
	public static function verify( array $params, string $secret ): bool {
		if ( empty( $params['sig'] ) || empty( $params['ts'] ) ) {
			return false;
		}
		$ts = (int) $params['ts'];
		if ( $ts <= 0 || abs( time() - $ts ) > self::MAX_AGE ) {
			return false;
		}
		return hash_equals( self::sign( $params, $secret ), (string) $params['sig'] );
	}

	// ---------------------------------------------------------------------
	//  Handshake start
	// ---------------------------------------------------------------------

	/**
	 * Front-end entry point: `?uxstudio_tpl=login&provider=google` starts a
	 * login handshake. The indirection exists so the login form can render a
	 * plain link without minting a nonce for every page view.
	 */
	public function maybe_start_handshake(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public entry point, no state is changed here.
		if ( ! isset( $_GET[ self::INIT_QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'login' !== sanitize_key( wp_unslash( $_GET[ self::INIT_QUERY_VAR ] ) ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';

		if ( ! in_array( $provider, $this->enabled_providers(), true ) ) {
			wp_safe_redirect( add_query_arg( 'uxstudio_tpl_error', 'provider', wp_login_url() ) );
			exit;
		}

		$url = $this->handshake_url( $provider, 'login' );
		if ( '' === $url ) {
			wp_safe_redirect( add_query_arg( 'uxstudio_tpl_error', 'config', wp_login_url() ) );
			exit;
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the central app is an external, admin-configured host.
		exit;
	}

	/**
	 * Build (and arm) a signed central-app handshake URL.
	 *
	 * Side effect: stores the single-use nonce, so call this only when the
	 * user is actually being sent to the central app. Returns '' when the
	 * module is not configured or `link` is requested without a user.
	 *
	 * @param string $provider Provider id (assumed to be enabled).
	 * @param string $mode     'login' or 'link'.
	 * @param int    $user_id  Initiating user for 'link' mode.
	 */
	public function handshake_url( string $provider, string $mode, int $user_id = 0 ): string {
		$app_url = $this->central_app_url();
		$secret  = Security::get_secret( self::SECRET_HMAC );
		if ( '' === $app_url || '' === $secret ) {
			return '';
		}
		if ( ! in_array( $mode, array( 'login', 'link' ), true ) ) {
			return '';
		}
		if ( 'link' === $mode && $user_id <= 0 ) {
			return '';
		}

		$nonce = wp_generate_password( 32, false, false );
		set_transient(
			self::NONCE_TRANSIENT_PREFIX . $nonce,
			array(
				'mode'     => $mode,
				'provider' => $provider,
				'user_id'  => $user_id,
				'issued'   => time(),
			),
			self::NONCE_TTL
		);

		$params = array(
			'site_url'   => home_url(),
			'return_url' => $this->callback_url(),
			'mode'       => $mode,
			'nonce'      => $nonce,
			'ts'         => (string) time(),
		);
		if ( 'link' === $mode ) {
			$params['user_id'] = (string) $user_id;
		}
		$params['sig'] = self::sign( $params, $secret );

		$init_url = untrailingslashit( esc_url_raw( $app_url ) ) . '/?page=' . rawurlencode( $provider ) . '_auth&action=init';

		return $init_url . '&' . http_build_query( $params );
	}

	/**
	 * Read (and burn) a handshake nonce. Returns null when it is unknown,
	 * expired or already used.
	 *
	 * @param string $nonce Nonce from the callback.
	 * @return array{mode:string,provider:string,user_id:int,issued:int}|null
	 */
	public function consume_nonce( string $nonce ): ?array {
		$key  = self::NONCE_TRANSIENT_PREFIX . sanitize_key( $nonce );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return null;
		}
		delete_transient( $key );

		return array(
			'mode'     => isset( $data['mode'] ) ? (string) $data['mode'] : '',
			'provider' => isset( $data['provider'] ) ? (string) $data['provider'] : '',
			'user_id'  => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'issued'   => isset( $data['issued'] ) ? (int) $data['issued'] : 0,
		);
	}

	/**
	 * URL that starts a login handshake for a provider (no nonce minted yet).
	 *
	 * @param string $provider Provider id.
	 */
	public function login_start_url( string $provider ): string {
		return add_query_arg(
			array(
				self::INIT_QUERY_VAR => 'login',
				'provider'           => $provider,
			),
			home_url( '/' )
		);
	}

	/**
	 * Renders "Sign in with ..." buttons on the login form for every enabled
	 * provider.
	 */
	public function render_login_buttons(): void {
		if ( '' === $this->central_app_url() || ! $this->has_secret() ) {
			return;
		}
		$enabled = $this->enabled_providers();
		if ( empty( $enabled ) ) {
			return;
		}

		$labels = $this->provider_labels();

		echo '<p class="uxstudio-third-party-login" style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">';
		foreach ( self::PROVIDERS as $provider ) {
			if ( ! in_array( $provider, $enabled, true ) ) {
				continue;
			}

			printf(
				'<a class="button button-secondary" style="width:100%%;text-align:center;" href="%1$s">%2$s</a>',
				esc_url( $this->login_start_url( $provider ) ),
				esc_html(
					sprintf(
						/* translators: %s = provider name */
						__( 'Sign in with %s', 'ux-studio' ),
						$labels[ $provider ] ?? $provider
					)
				)
			);
		}
		echo '</p>';
	}

	/**
	 * Role registry as id => display-name.
	 *
	 * @return array<string, string>
	 */
	private function role_names(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		return array_map( 'strval', wp_roles()->get_names() );
	}
}
