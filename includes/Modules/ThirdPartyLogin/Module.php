<?php
/**
 * Third-Party Login module - sign in via Google/Facebook/Apple through the
 * central app as an OAuth proxy.
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
 * that the central app posts the signed result back to.
 *
 * On top of the OAuth-proxy core (HMAC, auto-login) this module adds:
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

	/** Providers this module understands. */
	public const PROVIDERS = array( 'google', 'facebook', 'apple' );

	/** Max age (seconds) of a signed link-state token. */
	public const LINK_STATE_MAX_AGE = 600;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'login_form', array( $this, 'render_login_buttons' ) );
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
				'help'    => __( 'Base URL of the central app that performs the OAuth handshake.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'hmac_secret',
				'type'    => 'text',
				'label'   => __( 'HMAC secret', 'ux-studio' ),
				'help'    => __( 'Shared secret used to verify signed callbacks from the central app. Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'enabled_providers',
				'type'    => 'multiselect',
				'label'   => __( 'Enabled providers', 'ux-studio' ),
				'options' => array(
					'google'   => __( 'Google', 'ux-studio' ),
					'facebook' => __( 'Facebook', 'ux-studio' ),
					'apple'    => __( 'Apple', 'ux-studio' ),
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
	 * Enabled provider keys (subset of google/facebook/apple).
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
	 * Create a signed, short-lived state token binding a link handshake to a
	 * specific logged-in user and provider. Its integrity is self-contained
	 * (its own HMAC over the payload), so the central app only has to echo it
	 * back verbatim in the callback. Returns '' when no secret is configured.
	 *
	 * @param int    $user_id  Initiating user id.
	 * @param string $provider Provider id.
	 */
	public function create_link_state( int $user_id, string $provider ): string {
		$secret = Security::get_secret( self::SECRET_HMAC );
		if ( '' === $secret || $user_id <= 0 ) {
			return '';
		}
		$payload = wp_json_encode(
			array(
				'uid'      => $user_id,
				'provider' => $provider,
				'ts'       => time(),
			)
		);
		if ( ! is_string( $payload ) ) {
			return '';
		}
		$b64 = self::b64url_encode( $payload );
		$sig = hash_hmac( 'sha256', $b64, $secret );
		return $b64 . '.' . $sig;
	}

	/**
	 * Verify a link-state token. Returns the bound user id on success, or 0.
	 *
	 * @param string $state    Token from the callback.
	 * @param string $provider Provider id the callback claims.
	 */
	public function verify_link_state( string $state, string $provider ): int {
		$secret = Security::get_secret( self::SECRET_HMAC );
		if ( '' === $secret || '' === $state ) {
			return 0;
		}
		$parts = explode( '.', $state );
		if ( 2 !== count( $parts ) ) {
			return 0;
		}
		list( $b64, $sig ) = $parts;
		if ( '' === $sig || ! ctype_xdigit( $sig ) ) {
			return 0;
		}
		$expected = hash_hmac( 'sha256', $b64, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return 0;
		}
		$data = json_decode( self::b64url_decode( $b64 ), true );
		if ( ! is_array( $data ) ) {
			return 0;
		}
		$uid = isset( $data['uid'] ) ? absint( $data['uid'] ) : 0;
		$ts  = isset( $data['ts'] ) ? absint( $data['ts'] ) : 0;
		$p   = isset( $data['provider'] ) ? (string) $data['provider'] : '';
		if ( $uid <= 0 || $ts <= 0 || $p !== $provider ) {
			return 0;
		}
		if ( abs( time() - $ts ) > self::LINK_STATE_MAX_AGE ) {
			return 0;
		}
		return $uid;
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
		);
	}

	/**
	 * Build the central-app handshake URL for a given mode/provider.
	 *
	 * @param string $provider Provider id (assumed validated).
	 * @param string $mode     'login' or 'link'.
	 * @param string $state    Optional signed link-state token (link mode).
	 */
	public function handshake_url( string $provider, string $mode, string $state = '' ): string {
		$args = array(
			'site'      => rawurlencode( home_url( '/' ) ),
			'return_to' => rawurlencode( rest_url( 'uxstudio/v1/third-party-login/callback' ) ),
			'provider'  => rawurlencode( $provider ),
			'mode'      => rawurlencode( $mode ),
		);
		if ( '' !== $state ) {
			$args['state'] = rawurlencode( $state );
		}
		return add_query_arg( $args, untrailingslashit( esc_url_raw( $this->central_app_url() ) ) );
	}

	/**
	 * Renders "Sign in with ..." buttons on the login form for every enabled
	 * provider, linking to the central app.
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
				esc_url( $this->handshake_url( $provider, 'login' ) ),
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

	/**
	 * URL-safe base64 encode (no padding).
	 */
	private static function b64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Inverse of b64url_encode(). Returns '' on malformed input.
	 */
	private static function b64url_decode( string $value ): string {
		$decoded = base64_decode( strtr( $value, '-_', '+/' ), true );
		return false === $decoded ? '' : $decoded;
	}
}
