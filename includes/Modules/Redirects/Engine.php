<?php
/**
 * Front-end redirect engine.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs late on `init` (priority 99: after custom post types and taxonomies
 * are registered, so post-bound targets resolve to their pretty permalink,
 * but still before WordPress resolves the request), so a rule wins even
 * when the old URL would now render something. Only plain front-end GET/HEAD
 * requests are touched - never admin, REST, AJAX, cron, login or POSTs (a
 * 301 on a POST would drop the body).
 *
 * SEO behaviour:
 * - 301/308 = permanent (ranking is passed), 302/307 = temporary;
 * - 410 = the content is gone for good (faster de-indexing than 404),
 *   rendered with the theme's 404 template and `noindex`;
 * - the visitor's query string (utm_*, gclid…) is carried over unless the
 *   rule matched on a specific query;
 * - permanent redirects get a bounded browser cache (default 1 h) so a wrong
 *   rule can still be fixed - browsers cache a bare 301 forever.
 */
final class Engine {

	/** Whether this request was answered by a rule (read by other modules). */
	private static bool $handled = false;

	private Settings $settings;

	/**
	 * @param Settings $settings Module settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether a rule handled the current request (redirect or 410).
	 */
	public static function handled(): bool {
		return self::$handled;
	}

	/**
	 * Hook into the request.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_redirect' ), 99 );
	}

	/**
	 * Match the request and act on it.
	 */
	public function maybe_redirect(): void {
		if ( ! self::is_front_get() ) {
			return;
		}

		$request = Url::request();
		$match   = Repository::match( $request['path'], $request['query'] );
		if ( ! $match ) {
			return;
		}

		$rule = $match['rule'];
		$code = (int) $rule['code'];

		if ( (bool) $this->settings->get( 'track_hits', true ) ) {
			Repository::record_hit( (int) $rule['id'] );
		}

		if ( 410 === $code ) {
			self::$handled = true;
			add_action( 'wp', array( $this, 'send_gone' ), 0 );
			return;
		}

		$target = $match['target'];
		if ( '' === $target ) {
			return;
		}

		// Carry the visitor's query over (campaign tags) unless the rule
		// matched on its own query or the target already defines one.
		$rule_has_query = str_contains( (string) $rule['source_key'], '?' );
		$raw_query      = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through wp_redirect's sanitiser.
		if ( '' !== $raw_query && ! $rule_has_query && 'regex' !== $rule['match_type'] && (bool) $this->settings->get( 'pass_query', true ) ) {
			$target .= ( str_contains( $target, '?' ) ? '&' : '?' ) . $raw_query;
		}

		// Last-line loop guard: never redirect a URL to itself.
		$target_key = Url::target_key( $target );
		if ( '' !== $target_key && Url::key( $request['path'], $request['query'] ) === $target_key ) {
			return;
		}

		self::$handled = true;

		if ( '' !== (string) ( $rule['cond_key'] ?? '' ) ) {
			// The answer depends on who asks (login, cookie, referrer ...): neither a browser nor a shared cache
			// may serve it to somebody else or keep it after the visitor changes.
			nocache_headers();
		} elseif ( in_array( $code, array( 301, 308 ), true ) ) {
			$ttl = max( 0, (int) $this->settings->get( 'permanent_cache_ttl', HOUR_IN_SECONDS ) );
			header( 'Cache-Control: ' . ( $ttl > 0 ? 'public, max-age=' . $ttl : 'no-cache' ) );
		} else {
			nocache_headers();
		}

		add_filter( 'x_redirect_by', static fn (): string => 'UX Studio Redirects' );
		wp_redirect( $target, $code ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- admin-defined targets may be on another domain.
		exit;
	}

	/**
	 * 410 Gone through the theme's 404 template.
	 */
	public function send_gone(): void {
		global $wp_query;
		$wp_query->set_404();
		status_header( 410 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Plain front-end GET/HEAD request served by index.php.
	 */
	public static function is_front_get(): bool {
		global $pagenow;
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( 'index.php' !== $pagenow ) {
			return false;
		}
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		// REST_REQUEST is defined only later (parse_request) - catch REST by path.
		$request = Url::request();
		$prefix  = '/' . trim( rest_get_url_prefix(), '/' );
		return ! ( $request['path'] === $prefix || str_starts_with( $request['path'], $prefix . '/' ) || isset( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
