<?php
/**
 * Shared CAPTCHA plumbing: Cloudflare Turnstile, Google reCAPTCHA and the key-less Shield.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic CAPTCHA helpers shared by CaptchaHandler (widget inline in the WordPress login form),
 * CaptchaGate (standalone "verify you're not a robot" page shown before the login form), CaptchaForms (comments and
 * WooCommerce) and the CAPTCHA field of the Forms module.
 *
 * Everything works on a CONFIG: which provider, with which keys and look. config() builds the one the Security
 * module's own forms use (the `captcha_provider` setting, `auto` included); forms_config() builds the one of the Forms
 * module (keys first, the Shield when there are none, whatever the Security CAPTCHA switch says). The methods that
 * take a Module are the old entry points and use config().
 */
final class CaptchaVerifier {

	/** @var array<int, array<string, mixed>> Per request, by module object. */
	private static array $configs = array();

	/** Forget the remembered configs (settings or keys changed in this request). */
	public static function flush(): void {
		self::$configs = array();
	}

	/**
	 * Config of the Security module's forms.
	 *
	 * @return array{provider:string,source:string,site_key:string,secret:string,theme:string,size:string,v3_threshold:float}
	 */
	public static function config( Module $module ): array {
		$id = spl_object_id( $module );
		if ( ! isset( self::$configs[ $id ] ) ) {
			$state              = $module->captcha_state();
			$resolved           = CaptchaResolver::provider( $state['setting'], $state['own_provider'], $state['connected'], $state['ca_keys'], $state['own_keys'] );
			self::$configs[ $id ] = self::build( $module, $resolved );
		}
		return self::$configs[ $id ];
	}

	/**
	 * Config of the CAPTCHA field in the Forms module: keys of the central application while the site is connected,
	 * then the site's own keys, then the Shield. Independent of `captcha_enabled`.
	 *
	 * @return array{provider:string,source:string,site_key:string,secret:string,theme:string,size:string,v3_threshold:float}
	 */
	public static function forms_config( Module $module ): array {
		$state    = $module->captcha_state();
		$resolved = CaptchaResolver::for_forms( $state['own_provider'], $state['setting'], $state['connected'], $state['ca_keys'], $state['own_keys'] );
		return self::build( $module, $resolved );
	}

	/**
	 * @param array{provider:string,source:string} $resolved Provider and where its keys come from.
	 * @return array{provider:string,source:string,site_key:string,secret:string,theme:string,size:string,v3_threshold:float}
	 */
	private static function build( Module $module, array $resolved ): array {
		$provider = $resolved['provider'];
		$theme    = (string) $module->setting( 'captcha_theme', 'auto' );
		$size     = (string) $module->setting( 'captcha_size', 'normal' );
		$theme    = in_array( $theme, array( 'auto', 'light', 'dark' ), true ) ? $theme : 'auto';
		$size     = in_array( $size, array( 'normal', 'compact', 'flexible' ), true ) ? $size : 'normal';
		if ( 'recaptcha_v2' === $provider || 'recaptcha_v3' === $provider ) {
			// reCAPTCHA has no automatic theme and no flexible size.
			$theme = 'auto' === $theme ? 'light' : $theme;
			$size  = 'flexible' === $size ? 'normal' : $size;
		}
		$keyed = 'shield' !== $provider;
		return array(
			'provider'     => $provider,
			'source'       => $resolved['source'],
			'site_key'     => $keyed ? trim( (string) $module->setting( 'captcha_site_key', '' ) ) : '',
			'secret'       => $keyed ? trim( $module->captcha_secret_key() ) : '',
			'theme'        => $theme,
			'size'         => $size,
			'v3_threshold' => (float) $module->setting( 'captcha_recaptcha_v3_threshold', 0.5 ),
		);
	}

	/* ── Old entry points (Security module's forms) ── */

	public static function provider( Module $module ): string {
		return self::config( $module )['provider'];
	}

	public static function site_key( Module $module ): string {
		return self::config( $module )['site_key'];
	}

	private static function secret_key( Module $module ): string {
		return self::config( $module )['secret'];
	}

	public static function is_configured( Module $module ): bool {
		return self::config_ready( self::config( $module ) );
	}

	/**
	 * A provider that needs keys works with both; the Shield needs none.
	 *
	 * @param array<string,mixed> $config Config.
	 */
	public static function config_ready( array $config ): bool {
		return 'shield' === $config['provider'] || ( '' !== $config['site_key'] && '' !== $config['secret'] );
	}

	/**
	 * Client IP in the module's configured proxy mode (Core\ClientIp) - behind
	 * Cloudflare REMOTE_ADDR alone would lump every visitor onto a few edge IPs.
	 */
	public static function get_client_ip( ?Module $module = null ): string {
		return ClientIp::get( $module ? $module->proxy_mode() : 'none' );
	}

	/**
	 * Enqueue the provider's JS SDK. Safe to call from login_enqueue_scripts.
	 */
	public static function enqueue_provider_script( Module $module ): void {
		self::enqueue( self::config( $module ) );
	}

	/**
	 * @param array<string,mixed> $config Config.
	 */
	public static function enqueue( array $config ): void {
		switch ( $config['provider'] ) {
			case 'shield':
				ShieldRuntime::enqueue();
				break;
			case 'recaptcha_v2':
				wp_enqueue_script( 'uxstudio-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true );
				break;
			case 'recaptcha_v3':
				wp_enqueue_script(
					'uxstudio-recaptcha',
					'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( (string) $config['site_key'] ),
					array(),
					null,
					true
				);
				break;
			default:
				wp_enqueue_script( 'uxstudio-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
				break;
		}
	}

	/**
	 * Widget look chosen in the settings: theme (`auto` | `light` | `dark`) and size (`normal` | `compact` | `flexible`).
	 * Only the visible widgets use it (Turnstile, reCAPTCHA v2, the Shield takes the theme); `flexible` exists only in
	 * Turnstile and reCAPTCHA falls back to `normal`; reCAPTCHA has no `auto` theme and keeps its default (light).
	 *
	 * @return array{theme:string,size:string}
	 */
	public static function appearance( Module $module ): array {
		$config = self::config( $module );
		return array(
			'theme' => $config['theme'],
			'size'  => $config['size'],
		);
	}

	/**
	 * Widget markup (checkbox / invisible token field, depending on provider).
	 *
	 * @param Module $module  Security module.
	 * @param string $context Where it is shown (login, register, lostpassword, gate, comment, woo_*): the Shield offers
	 *                        its typed-question fallback for the forms that may have one.
	 * @param bool   $autostart The Shield starts at once (the gate page has nothing else to do).
	 */
	public static function render_widget( Module $module, string $context = 'login', bool $autostart = false ): void {
		self::render( self::config( $module ), $context, array( 'autostart' => $autostart ) );
	}

	/**
	 * @param array<string,mixed> $config  Config.
	 * @param string              $context Where it is shown.
	 * @param array<string,mixed> $opts    Shield options: field, autostart.
	 */
	public static function render( array $config, string $context = 'login', array $opts = array() ): void {
		$provider = (string) $config['provider'];
		$site_key = (string) $config['site_key'];

		if ( 'shield' === $provider ) {
			// The wrapper of the other widgets is part of the Shield markup.
			echo ShieldRuntime::render( $context, array_merge( array( 'theme' => $config['theme'] ), $opts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in ShieldRuntime.
			return;
		}

		echo '<div class="uxstudio-captcha-field">';

		if ( 'recaptcha_v2' === $provider ) {
			printf( '<div class="g-recaptcha" data-sitekey="%s" data-theme="%s" data-size="%s"></div>', esc_attr( $site_key ), esc_attr( $config['theme'] ), esc_attr( $config['size'] ) );
		} elseif ( 'recaptcha_v3' === $provider ) {
			echo '<input type="hidden" name="g-recaptcha-response" class="uxstudio-recaptcha-v3-token" value="">';
		} else {
			printf( '<div class="cf-turnstile" data-sitekey="%s" data-theme="%s" data-size="%s"></div>', esc_attr( $site_key ), esc_attr( $config['theme'] ), esc_attr( $config['size'] ) );
		}

		echo '</div>';
	}

	/**
	 * Name of the form field / request parameter that carries the token of the chosen provider.
	 */
	public static function token_field( Module $module ): string {
		return self::config_token_field( self::config( $module ) );
	}

	/**
	 * @param array<string,mixed> $config Config.
	 */
	public static function config_token_field( array $config ): string {
		if ( 'shield' === $config['provider'] ) {
			return CaptchaShield::FIELD;
		}
		return 'turnstile' === $config['provider'] ? 'cf-turnstile-response' : 'g-recaptcha-response';
	}

	/**
	 * reCAPTCHA v3 has no visible widget - it silently (re)executes on every
	 * form submit and drops the score token into a hidden field.
	 */
	public static function render_v3_footer( Module $module ): void {
		$site_key = self::site_key( $module );
		?>
		<script>
		(function () {
			if ( typeof grecaptcha === 'undefined' ) { return; }
			var siteKey = <?php echo wp_json_encode( $site_key ); ?>;
			function refresh() {
				grecaptcha.ready( function () {
					grecaptcha.execute( siteKey, { action: 'login' } ).then( function ( token ) {
						document.querySelectorAll( '.uxstudio-recaptcha-v3-token' ).forEach( function ( el ) {
							el.value = token;
						} );
					} );
				} );
			}
			refresh();
			document.addEventListener( 'submit', function () { refresh(); }, true );
			setInterval( refresh, 90000 );
		})();
		</script>
		<?php
	}

	/**
	 * Verify the token from $_POST against the provider's siteverify endpoint.
	 * Fails OPEN on a provider outage (5xx / network error) - a CAPTCHA outage
	 * must never turn into a site-wide login lockout.
	 *
	 * The Shield is checked here too (proof of work, no network): it has no outage to fail open on.
	 *
	 * @param Module      $module  Security module.
	 * @param string|null $token   Token to check; null = the one posted by the widget (form field of the provider).
	 * @param string      $context Where it is checked (see render_widget()).
	 */
	public static function verify_token( Module $module, ?string $token = null, string $context = 'login' ): bool {
		return self::verify( self::config( $module ), $token, $context );
	}

	/**
	 * @param array<string,mixed> $config  Config.
	 * @param string|null         $token   Token to check; null = the posted one.
	 * @param string              $context Where it is checked.
	 * @param string              $field   Name of the posted Shield answer (default: the shared one).
	 */
	public static function verify( array $config, ?string $token = null, string $context = 'login', string $field = CaptchaShield::FIELD ): bool {
		return '' === self::verify_code( $config, $token, $context, $field );
	}

	/**
	 * Like verify(), but says why. The reason is for the places that may be specific, never for the login form.
	 *
	 * @param array<string,mixed> $config  Config.
	 * @param string|null         $token   Token to check; null = the posted one.
	 * @param string              $context Where it is checked.
	 * @param string              $field   Name of the posted Shield answer.
	 * @return string '' when it passes, otherwise 'failed' or (Shield) the reason.
	 */
	public static function verify_code( array $config, ?string $token = null, string $context = 'login', string $field = CaptchaShield::FIELD ): string {
		$provider = (string) $config['provider'];
		static $verdicts = array();

		if ( 'shield' === $provider ) {
			// An answer is single use, so when two handlers check the same request (the login handler and a WooCommerce
			// form) the second one must see the first one's verdict instead of burning it twice.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$post = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
			$key  = md5( 'shield|' . $context . '|' . ( $token ?? $post( $field ) ) . '|' . $post( CaptchaShield::NOJS_TOKEN_FIELD ) . '|' . $post( CaptchaShield::NOJS_FIELD ) );
			if ( ! isset( $verdicts[ $key ] ) ) {
				$verdicts[ $key ] = ShieldRuntime::check( $context, $field, $token );
			}
			return $verdicts[ $key ];
		}

		if ( 'turnstile' === $provider ) {
			$name     = 'cf-turnstile-response';
			$endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		} else {
			$name     = 'g-recaptcha-response';
			$endpoint = 'https://www.google.com/recaptcha/api/siteverify';
		}
		if ( null === $token ) {
			$token = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( '' === $token ) {
			return 'failed';
		}

		// A token is single use at the provider: when two handlers check the same request (for example the
		// login form handler and a WooCommerce form), the second one must see the first one's verdict.
		$key = md5( $provider . '|' . $token );
		if ( ! isset( $verdicts[ $key ] ) ) {
			$verdicts[ $key ] = self::siteverify( $config, $token, $endpoint ) ? '' : 'failed';
		}
		return $verdicts[ $key ];
	}

	/**
	 * One call to the provider's siteverify endpoint.
	 *
	 * @param array<string,mixed> $config   Config.
	 * @param string              $token    Token from the widget.
	 * @param string              $endpoint Siteverify URL.
	 */
	private static function siteverify( array $config, string $token, string $endpoint ): bool {
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => (string) $config['secret'],
					'response' => $token,
					'remoteip' => ShieldRuntime::client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return false;
		}

		if ( 'recaptcha_v3' === $config['provider'] ) {
			$score = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
			if ( $score < (float) $config['v3_threshold'] ) {
				return false;
			}
		}

		return true;
	}
}
