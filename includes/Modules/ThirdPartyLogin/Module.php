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

defined( 'ABSPATH' ) || exit;

/**
 * Narrower re-implementation of the legacy third-party-login module: OAuth
 * credentials live in the central app, not here. This module only renders
 * the login buttons that kick off the flow and exposes the public REST
 * callback that the central app posts the signed result back to.
 *
 * The HMAC secret shared with the central app is never stored in the plain
 * settings option; it goes through Security::store_secret() and is never
 * echoed back via REST (only a boolean "has_hmac_secret" is exposed).
 */
final class Module extends BaseModule {

	public const SECRET_HMAC = 'uxstudio_secret_third_party_login_hmac';

	private const PROVIDERS = array( 'google', 'facebook', 'apple' );

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
	 */
	public function settings_schema(): array {
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
		$values                 = parent::settings_values();
		$values['hmac_secret']  = '';
		$values['has_hmac_secret'] = '' !== Security::get_secret( self::SECRET_HMAC );
		return $values;
	}

	/**
	 * Enabled provider keys (subset of google/facebook/apple). Exposed as a
	 * public accessor since BaseModule::$settings is protected and the REST
	 * controller lives in a separate class.
	 *
	 * @return array<int, string>
	 */
	public function enabled_providers(): array {
		return (array) $this->settings->get( 'enabled_providers', array() );
	}

	/**
	 * Renders "Sign in with ..." buttons on the login form for every enabled
	 * provider, linking to the central app with query args identifying this
	 * site and the REST callback the central app should post the result to.
	 */
	public function render_login_buttons(): void {
		$central_app_url = (string) $this->settings->get( 'central_app_url', '' );
		$has_secret       = '' !== Security::get_secret( self::SECRET_HMAC );
		$enabled          = (array) $this->settings->get( 'enabled_providers', array() );

		if ( '' === $central_app_url || ! $has_secret || empty( $enabled ) ) {
			return;
		}

		$labels = array(
			'google'   => __( 'Sign in with Google', 'ux-studio' ),
			'facebook' => __( 'Sign in with Facebook', 'ux-studio' ),
			'apple'    => __( 'Sign in with Apple', 'ux-studio' ),
		);

		echo '<p class="uxstudio-third-party-login" style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">';
		foreach ( self::PROVIDERS as $provider ) {
			if ( ! in_array( $provider, $enabled, true ) ) {
				continue;
			}

			$url = add_query_arg(
				array(
					'site'      => rawurlencode( home_url( '/' ) ),
					'return_to' => rawurlencode( rest_url( 'uxstudio/v1/third-party-login/callback' ) ),
					'provider'  => rawurlencode( $provider ),
				),
				untrailingslashit( esc_url_raw( $central_app_url ) )
			);

			printf(
				'<a class="button button-secondary" style="width:100%%;text-align:center;" href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html( $labels[ $provider ] ?? $provider )
			);
		}
		echo '</p>';
	}
}
