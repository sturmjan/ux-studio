<?php
/**
 * Shield of UX Studio on a WordPress site: secret, one-time storage, challenge route, widget and the request check.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ClientIp;
use UxStudio\Core\Settings;
use UxStudio\Modules\BotThrottle\Guard;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The protocol is CaptchaShield (pure). This is everything around it that needs WordPress:
 *
 *  - the server secret: HMAC of a per-site random option (created once, never sent anywhere) under wp_salt('auth'),
 *  - the one-time use store: a row in the options table written with INSERT IGNORE, so two requests racing with the
 *    same answer cannot both win; expired rows are swept now and then,
 *  - the public challenge route uxstudio/v1/shield/challenge (no login, rate limited per IP, never cached),
 *  - the widget markup and its two assets,
 *  - the request check, with the per-IP count of failed logins that makes the next puzzle harder.
 *
 * The Shield is used by the Security module (login, registration, lost password, comments, WooCommerce, the gate
 * page) and by the CAPTCHA field of the Forms module, so the settings it reads live in the Security options whether
 * or not the Security CAPTCHA switch is on.
 */
final class ShieldRuntime {

	public const SECRET_OPTION = 'uxstudio_shield_secret';

	private const USED_PREFIX = '_uxs_shield_used_';
	private const FAIL_PREFIX = 'uxstudio_shield_fail_';
	private const FAIL_WINDOW = 900;

	/** Settings live in the Security module's option. */
	private const OPTION = 'uxstudio_security_optimization';

	/* ── Settings ── */

	/**
	 * A Security option (read directly: the Shield must work when the Security module itself is off, for Forms).
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function setting( string $key, $default = null ) {
		return ( new Settings( self::OPTION ) )->get( $key, $default );
	}

	public static function level(): string {
		return CaptchaShield::normalize_level( self::setting( 'captcha_shield_level', CaptchaShield::DEFAULT_LEVEL ) );
	}

	public static function min_ms(): int {
		return CaptchaShield::min_ms( self::setting( 'captcha_shield_min_time', CaptchaShield::DEFAULT_MIN_MS / 1000 ) );
	}

	/** Client IP in the Security module's proxy mode. */
	public static function client_ip(): string {
		$mode = (string) self::setting( 'ip_firewall_proxy', 'none' );
		return ClientIp::get( in_array( $mode, array( 'none', 'cloudflare', 'xff' ), true ) ? $mode : 'none' );
	}

	/* ── Secret and one-time store ── */

	/** The key of the signatures. Created on first use, kept in an option that is not autoloaded and never exported. */
	public static function secret(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$stored = (string) get_option( self::SECRET_OPTION, '' );
		if ( strlen( $stored ) < 32 ) {
			add_option( self::SECRET_OPTION, bin2hex( random_bytes( 32 ) ), '', false );
			// Another request may have won the race: read what is really stored.
			wp_cache_delete( self::SECRET_OPTION, 'options' );
			$stored = (string) get_option( self::SECRET_OPTION, '' );
		}
		$cache = hash_hmac( 'sha256', 'uxstudio-shield-v1|' . $stored, wp_salt( 'auth' ) );
		return $cache;
	}

	/**
	 * First use of a challenge id? An INSERT IGNORE into the options table: the unique option name makes the second of
	 * two simultaneous requests lose.
	 *
	 * @param string $id  Challenge id (hash).
	 * @param int    $ttl Seconds the mark has to live.
	 */
	public static function consume( string $id, int $ttl ): bool {
		global $wpdb;
		$name = self::USED_PREFIX . substr( $id, 0, 48 );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				(string) ( time() + max( 60, $ttl ) )
			)
		);
		$first = 1 === (int) $wpdb->rows_affected;
		if ( 0 === wp_rand( 0, 24 ) ) {
			self::sweep();
		}
		return $first;
	}

	/** Delete the marks whose time is up. */
	public static function sweep(): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( self::USED_PREFIX ) . '%',
				time()
			)
		);
	}

	/* ── Failed logins of this address ── */

	private static function fail_key(): string {
		return self::FAIL_PREFIX . md5( self::client_ip() );
	}

	public static function failures(): int {
		return '' === self::client_ip() ? 0 : (int) get_transient( self::fail_key() );
	}

	/** wp_login_failed: remember it for the next puzzle of this address. */
	public static function record_login_failure( $username = '', $error = null ): void {
		if ( '' === self::client_ip() || ( class_exists( TwoFactor::class ) && TwoFactor::is_non_form_error( $error ) ) ) {
			return;
		}
		set_transient( self::fail_key(), self::failures() + 1, self::FAIL_WINDOW );
	}

	public static function clear_login_failures(): void {
		if ( '' !== self::client_ip() ) {
			delete_transient( self::fail_key() );
		}
	}

	/** Level for this visitor: the configured one, a step harder after recent failed logins. */
	public static function effective_level(): string {
		$raise = (bool) self::setting( 'captcha_shield_adaptive', true )
			&& self::failures() >= max( 1, (int) self::setting( 'captcha_adaptive_threshold', 2 ) );
		return CaptchaShield::effective_level( self::level(), $raise );
	}

	/* ── REST ── */

	public static function register_routes(): void {
		register_rest_route(
			'uxstudio/v1',
			'/shield/challenge',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'rest_challenge' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET uxstudio/v1/shield/challenge. Public on purpose: the visitor is not logged in yet. Costs the server one
	 * random number, one hash and one HMAC; rate limited per IP; never cached by a browser, a CDN or a page cache.
	 *
	 * @param WP_REST_Request $request Request (unused).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_challenge( $request ) {
		unset( $request );
		if ( Guard::exceeded( 'shield_challenge', 40, 60, 120 ) ) {
			return new WP_Error( 'uxstudio_shield_rate_limited', __( 'Too many requests, please try again in a moment.', 'ux-studio' ), array( 'status' => 429 ) );
		}
		$challenge = CaptchaShield::issue( self::secret(), CaptchaShield::number_max( self::effective_level() ), (int) floor( microtime( true ) * 1000 ) );
		$response  = new WP_REST_Response( $challenge );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/* ── Check ── */

	/**
	 * Check the shield part of the current request.
	 *
	 * @param string      $context Where it runs (see CaptchaShield::nojs_allowed()).
	 * @param string      $field   Name of the posted answer (the Forms module uses one per form).
	 * @param string|null $payload The answer when it did not come in $_POST (a REST parameter); null = read $_POST.
	 * @return string '' when it passes, otherwise the reason (see CaptchaShield::verify() and decide()).
	 */
	public static function check( string $context, string $field = CaptchaShield::FIELD, ?string $payload = null ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$post = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
		// phpcs:enable
		$req = array(
			'shield'     => null !== $payload ? $payload : $post( $field ),
			'trap'       => $post( CaptchaShield::TRAP_FIELD ),
			'nojs'       => $post( CaptchaShield::NOJS_FIELD ),
			'nojs_token' => $post( CaptchaShield::NOJS_TOKEN_FIELD ),
		);

		return CaptchaShield::decide(
			$req,
			$context,
			static fn( string $p ): string => CaptchaShield::verify( self::secret(), $p, (int) floor( microtime( true ) * 1000 ), self::min_ms(), array( self::class, 'consume' ) ),
			static function ( string $token, string $answer ): string {
				// The typed question is the stricter path: it is rate limited per address on top of everything else.
				if ( '' !== $token && '' !== $answer && Guard::exceeded( 'shield_nojs', 8, 60, 300 ) ) {
					return 'ratelimit';
				}
				return CaptchaShield::nojs_verify( self::secret(), $token, $answer, time(), array( self::class, 'consume' ) );
			}
		);
	}

	/**
	 * Visitor-facing words for a reason, for the places that may be specific (the Forms module). The login forms keep
	 * the one generic sentence, so a failed attempt tells an attacker nothing.
	 *
	 * @param string $code Reason from check().
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'expired':
				return __( 'The verification expired. Please try again.', 'ux-studio' );
			case 'toosoon':
				return __( 'That was a little too fast. Please wait a moment and send it again.', 'ux-studio' );
			case 'replay':
				return __( 'This verification was already used. Please try again.', 'ux-studio' );
			case 'missing':
				return __( 'The verification did not finish. Please wait for the check mark and try again.', 'ux-studio' );
			default:
				return __( 'CAPTCHA verification failed. Please try again.', 'ux-studio' );
		}
	}

	/* ── Widget ── */

	/**
	 * Enqueue the script and the style of the widget. Safe to call more than once.
	 */
	public static function enqueue(): void {
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$css    = self::asset( 'assets/css/shield', $suffix, 'css' );
		$js     = self::asset( 'assets/js/shield', $suffix, 'js' );
		$ver    = (string) max( self::mtime( $css ), self::mtime( $js ) );
		if ( '0' === $ver ) {
			$ver = UXSTUDIO_VERSION;
		}
		wp_enqueue_style( 'uxstudio-shield', UXSTUDIO_URL . $css, array(), $ver );
		wp_enqueue_script(
			'uxstudio-shield',
			UXSTUDIO_URL . $js,
			array(),
			$ver,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/** URL of the script, also what the page starts as the worker. */
	private static function worker_url(): string {
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		$js     = self::asset( 'assets/js/shield', $suffix, 'js' );
		return add_query_arg( 'ver', (string) max( 1, self::mtime( $js ) ), UXSTUDIO_URL . $js );
	}

	private static function asset( string $base, string $suffix, string $ext ): string {
		$rel = $base . $suffix . '.' . $ext;
		return ( '' !== $suffix && ! file_exists( UXSTUDIO_PATH . $rel ) ) ? $base . '.' . $ext : $rel;
	}

	private static function mtime( string $rel ): int {
		return file_exists( UXSTUDIO_PATH . $rel ) ? (int) filemtime( UXSTUDIO_PATH . $rel ) : 0;
	}

	/**
	 * Markup of the widget.
	 *
	 * @param string               $context Where it is placed (see CaptchaShield::nojs_allowed()).
	 * @param array<string,mixed>  $opts    field (name of the answer), autostart (bool), theme (auto | light | dark).
	 */
	public static function render( string $context, array $opts = array() ): string {
		self::enqueue();
		$field     = (string) ( $opts['field'] ?? CaptchaShield::FIELD );
		$theme     = in_array( $opts['theme'] ?? 'auto', array( 'auto', 'light', 'dark' ), true ) ? (string) ( $opts['theme'] ?? 'auto' ) : 'auto';
		// The login pages of WordPress are a white box whatever the visitor's system uses, so "automatic" means light there.
		if ( 'auto' === $theme && in_array( $context, array( 'login', 'register', 'lostpassword', 'gate' ), true ) ) {
			$theme = 'light';
		}
		$nojs      = CaptchaShield::nojs_allowed( $context );
		$retry     = __( 'The check did not work. Click the box to try again.', 'ux-studio' );
		$typed     = __( 'The check did not work. Answer the question below instead.', 'ux-studio' );
		$challenge = rest_url( 'uxstudio/v1/shield/challenge' );

		$html  = sprintf(
			'<div class="uxstudio-captcha-field uxs-shield" data-uxs-shield data-challenge="%1$s" data-worker="%2$s" data-min="%3$d" data-theme="%4$s" data-autostart="%5$d" data-l-idle="%6$s" data-l-working="%7$s" data-l-done="%8$s" data-l-error="%9$s">',
			esc_url( $challenge ),
			esc_url( self::worker_url() ),
			self::min_ms(),
			esc_attr( $theme ),
			empty( $opts['autostart'] ) ? 0 : 1,
			esc_attr__( 'I am not a robot', 'ux-studio' ),
			esc_attr__( 'Verifying…', 'ux-studio' ),
			esc_attr__( 'Verified', 'ux-studio' ),
			esc_attr( $nojs ? $typed : $retry )
		);
		$html .= '<label class="uxs-shield__box"><input type="checkbox" class="uxs-shield__check"> <span class="uxs-shield__label">' . esc_html__( 'I am not a robot', 'ux-studio' ) . '</span><span class="uxs-shield__spin" aria-hidden="true"></span></label>';
		$html .= '<span class="uxs-shield__status" role="status" aria-live="polite"></span>';
		$html .= '<input type="hidden" name="' . esc_attr( $field ) . '" value="" data-uxs-shield-payload>';
		$html .= '<input type="text" name="' . esc_attr( CaptchaShield::TRAP_FIELD ) . '" class="uxs-shield__trap" value="" tabindex="-1" autocomplete="off" aria-hidden="true">';

		if ( $nojs ) {
			$q     = CaptchaShield::nojs_issue( self::secret(), time() );
			$html .= '<noscript><style>.uxs-shield__fallback{visibility:visible!important;animation:none!important}.uxs-shield .uxs-shield__box,.uxs-shield .uxs-shield__status{display:none!important}</style></noscript>';
			$html .= '<div class="uxs-shield__fallback"><label>' . esc_html(
				sprintf(
					/* translators: 1, 2: numbers of a simple sum. */
					__( 'Check question: what is %1$d + %2$d?', 'ux-studio' ),
					$q['a'],
					$q['b']
				)
			) . ' <input type="text" name="' . esc_attr( CaptchaShield::NOJS_FIELD ) . '" value="" inputmode="numeric" autocomplete="off" size="4" maxlength="3"></label>'
				. '<input type="hidden" name="' . esc_attr( CaptchaShield::NOJS_TOKEN_FIELD ) . '" value="' . esc_attr( $q['token'] ) . '"></div>';
		}

		return $html . '</div>';
	}
}
