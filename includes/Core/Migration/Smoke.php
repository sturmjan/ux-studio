<?php
/**
 * Automated smoke tests of the safe preview and of the live site after the switch.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.10. The server asks its own site over HTTP (loopback), twice per check:
 *
 *   BASELINE  no header     = what every visitor gets now (the old plugins)
 *   PREVIEW   X-UXS-Preview = the site without the old plugins and with the target modules
 *
 * and compares. After the switch the same checks run in `live` mode: no header (the old plugins
 * are gone for everybody), compared with the baseline recorded during the preview.
 *
 * Every check is one call (`run()`), so the wizard can show rows filling in one by one and a
 * slow check never hits a PHP time limit. A result is `{id,label,status,detail,ms,data?}` with
 * status `ok` | `warn` | `fail` | `info`.
 */
final class Smoke {

	public const MODE_PREVIEW = 'preview';
	public const MODE_LIVE    = 'live';

	/** @var callable|null Test seam: replaces the HTTP transport, receives (url, args) and returns the wp_remote_request() shape. */
	private static $transport = null;

	/**
	 * Replace the HTTP transport (tests only).
	 *
	 * @param callable|null $transport Callable or null for the real one.
	 */
	public static function set_transport( ?callable $transport ): void {
		self::$transport = $transport;
	}

	/* ── Plan ── */

	/**
	 * The checks of a run.
	 *
	 * @param string $run_id Run id.
	 * @return array<int, array<string, mixed>> Each: id, label, type, group (`site` | source id), ...type parameters.
	 */
	public static function plan( string $run_id ): array {
		$record  = History::find( Snapshot::clean_id( $run_id ) );
		$sources = $record ? Preview::sources_of( $record ) : array();
		$checks  = array();

		$descriptors = array();
		$replaces    = array();
		foreach ( $sources as $source_id ) {
			$source = SourceRegistry::get( $source_id );
			if ( $source && method_exists( $source, 'smoke_checks' ) ) {
				foreach ( (array) $source->smoke_checks() as $d ) {
					$d['group']    = $source_id;
					$d['source']   = $source->label();
					$descriptors[] = $d;
					foreach ( (array) ( $d['replaces'] ?? array() ) as $what ) {
						$replaces[ (string) $what ] = true;
					}
				}
			}
		}

		$checks[] = array(
			'id'    => 'home',
			'label' => __( 'Home page', 'ux-studio' ),
			'type'  => 'page',
			'group' => 'site',
			'url'   => home_url( '/' ),
		);
		foreach ( array( 'post' => __( 'Post: %s', 'ux-studio' ), 'page' => __( 'Page: %s', 'ux-studio' ) ) as $type => $format ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 3,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => true,
				)
			);
			foreach ( (array) $posts as $post ) {
				$title    = '' !== trim( (string) $post->post_title ) ? wp_html_excerpt( (string) $post->post_title, 48, '…' ) : '#' . (int) $post->ID;
				$checks[] = array(
					'id'    => $type . '-' . (int) $post->ID,
					'label' => sprintf( $format, $title ),
					'type'  => 'page',
					'group' => 'site',
					'url'   => (string) get_permalink( $post ),
				);
			}
		}
		if ( empty( $replaces['wp-login'] ) ) {
			$checks[] = array(
				'id'    => 'login',
				'label' => __( 'Login page (wp-login.php)', 'ux-studio' ),
				'type'  => 'page',
				'group' => 'site',
				'url'   => site_url( 'wp-login.php', 'login' ),
			);
		}
		$checks[] = array(
			'id'    => 'admin',
			'label' => __( 'Administration (wp-admin)', 'ux-studio' ),
			'type'  => 'admin',
			'group' => 'site',
			'url'   => admin_url( '/' ),
		);
		foreach ( $descriptors as $d ) {
			$d['id']    = $d['group'] . ':' . $d['id'];
			$checks[]   = $d;
		}
		return $checks;
	}

	/* ── One check ── */

	/**
	 * Run one check.
	 *
	 * @param string $run_id   Run id.
	 * @param string $check_id Id from plan().
	 * @param string $mode     `preview` or `live`.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( string $run_id, string $check_id, string $mode ) {
		$run_id = Snapshot::clean_id( $run_id );
		$token  = null;
		if ( self::MODE_PREVIEW === $mode ) {
			$entry = Preview::entry( $run_id );
			if ( ! $entry || (int) $entry['expires'] <= time() ) {
				return new \WP_Error( 'uxstudio_preview_expired', __( 'The preview has expired. Start it again.', 'ux-studio' ), array( 'status' => 410 ) );
			}
			$token = PreviewBoot::make_token( $run_id, (int) $entry['user_id'], (int) $entry['expires'], 'h' );
			if ( '' === $token ) {
				return new \WP_Error( 'uxstudio_preview_unavailable', __( 'The preview token could not be created.', 'ux-studio' ), array( 'status' => 409 ) );
			}
		} elseif ( self::MODE_LIVE !== $mode ) {
			return new \WP_Error( 'uxstudio_smoke_mode', __( 'Unknown test mode.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		$check = null;
		foreach ( self::plan( $run_id ) as $candidate ) {
			if ( $candidate['id'] === $check_id ) {
				$check = $candidate;
				break;
			}
		}
		if ( ! $check ) {
			return new \WP_Error( 'uxstudio_smoke_unknown', __( 'Unknown test.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$started = microtime( true );
		try {
			$result = self::execute( $run_id, $check, $token, $mode );
		} catch ( \Throwable $e ) {
			$result = array(
				'status' => 'fail',
				'detail' => $e->getMessage(),
			);
		}
		$result['id']    = $check['id'];
		$result['label'] = $check['label'];
		$result['group'] = $check['group'];
		$result['ms']    = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( self::MODE_PREVIEW === $mode ) {
			Preview::record_result( $run_id, $result );
		} else {
			Switcher::record_live( $run_id, $result );
		}
		return $result;
	}

	/**
	 * @param string               $run_id Run id.
	 * @param array<string, mixed> $check  Check.
	 * @param string|null          $token  Preview header token, null in live mode.
	 * @param string               $mode   Mode.
	 * @return array{status:string,detail:string,data?:array<string, mixed>}
	 */
	private static function execute( string $run_id, array $check, ?string $token, string $mode ): array {
		switch ( $check['type'] ) {
			case 'page':
				return self::check_page( $check, $token, $mode );
			case 'admin':
				return self::check_page( $check, $token, $mode, true );
			case 'redirect_sample':
				return self::check_redirects( $run_id, $check, $token, $mode );
			case 'comment_form_absent':
				return self::check_comments( $token );
			case 'login_slug':
				return self::check_login( $check, $token );
			case 'smtp_test':
				return self::check_mail( $token );
			case 'block_editor_off':
				return self::check_editor( $check, $token );
			case 'pages_clean':
				return self::check_clean( $token );
			case 'admin_access':
				return self::check_admin_access( $check, $token );
		}
		return array(
			'status' => 'info',
			'detail' => __( 'No test for this item.', 'ux-studio' ),
		);
	}

	/* ── HTTP ── */

	/**
	 * Cookies of the current admin session, to forward to wp-admin and REST loopbacks.
	 *
	 * @return array<string, string>
	 */
	private static function session_cookies(): array {
		$out = array();
		foreach ( (array) $_COOKIE as $name => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_string( $value ) && ( 0 === strpos( (string) $name, 'wordpress_' ) || 0 === strpos( (string) $name, 'wp-settings' ) ) ) {
				$out[ (string) $name ] = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;
			}
		}
		return $out;
	}

	/**
	 * One loopback request.
	 *
	 * @param string               $url   URL.
	 * @param string|null          $token Preview header token (null = as a visitor).
	 * @param array<string, mixed> $o     Options: cookies (bool), bust (bool, default true), method, body (array, sent as JSON), nonce (bool).
	 * @return array{code:int,headers:array<string,string>,body:string,error:string,ms:int}
	 */
	public static function fetch( string $url, ?string $token, array $o = array() ): array {
		if ( ! isset( $o['bust'] ) || $o['bust'] ) {
			$url = add_query_arg( 'uxs_cb', substr( md5( (string) microtime( true ) . $url ), 0, 10 ), $url );
		}
		$headers = array(
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => 'cs-CZ,cs;q=0.9,en;q=0.7',
			'Cache-Control'   => 'no-cache',
			'Pragma'          => 'no-cache',
		);
		if ( null !== $token ) {
			$headers['X-UXS-Preview'] = $token;
		}
		if ( ! empty( $o['nonce'] ) ) {
			$headers['X-WP-Nonce'] = wp_create_nonce( 'wp_rest' );
			$headers['Accept']     = 'application/json';
		}
		$args = array(
			'timeout'     => 25,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 UXStudioMigrationCheck/1',
			'headers'     => $headers,
			'method'      => (string) ( $o['method'] ?? 'GET' ),
		);
		if ( ! empty( $o['cookies'] ) ) {
			$args['cookies'] = self::session_cookies();
		}
		if ( isset( $o['body'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $o['body'] );
		}

		$started = microtime( true );
		$res     = self::$transport ? ( self::$transport )( $url, $args ) : wp_remote_request( $url, $args );
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array(
				'code'    => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => $res->get_error_message(),
				'ms'      => $ms,
			);
		}
		$headers_out = array();
		$all = wp_remote_retrieve_headers( $res );
		$all = is_object( $all ) && method_exists( $all, 'getAll' ) ? $all->getAll() : (array) $all;
		foreach ( $all as $name => $value ) {
			$headers_out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) end( $value ) : (string) $value;
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $res ),
			'headers' => $headers_out,
			'body'    => (string) wp_remote_retrieve_body( $res ),
			'error'   => '',
			'ms'      => $ms,
		);
	}

	/**
	 * Does a response carry the sign of a PHP fatal error?
	 *
	 * @param array{code:int,body:string} $res Response.
	 */
	public static function is_fatal( array $res ): bool {
		if ( $res['code'] >= 500 ) {
			return true;
		}
		return (bool) preg_match( '/There has been a critical error|Fatal error:|Parse error:|Uncaught (?:Error|Exception|TypeError|ValueError)|Stack trace:|<b>Fatal error<\/b>/i', $res['body'] );
	}

	/**
	 * Number of PHP warnings / notices printed into the page.
	 *
	 * @param string $body Response body.
	 */
	public static function notices( string $body ): int {
		return (int) preg_match_all( '/(?:<b>)?(?:Warning|Notice|Deprecated)(?:<\/b>)?:\s.{1,300}? on line (?:<b>)?\d+/is', $body );
	}

	/**
	 * Did the response come from a request the preview really handled?
	 *
	 * @param array{headers:array<string,string>} $res Response.
	 */
	private static function was_preview( array $res ): bool {
		return isset( $res['headers'][ strtolower( PreviewBoot::ACTIVE_HEADER ) ] );
	}

	/**
	 * Result helper.
	 *
	 * @param string               $status ok | warn | fail | info.
	 * @param string               $detail Text.
	 * @param array<string, mixed> $data   Extra data kept with the result.
	 * @return array{status:string,detail:string,data?:array<string, mixed>}
	 */
	private static function result( string $status, string $detail, array $data = array() ): array {
		$out = array(
			'status' => $status,
			'detail' => $detail,
		);
		if ( $data ) {
			$out['data'] = $data;
		}
		return $out;
	}

	/**
	 * Same Location after the scheme and host of this site are ignored.
	 *
	 * @param string $location Location header.
	 */
	public static function normalize_location( string $location ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return '';
		}
		$home = rtrim( (string) home_url(), '/' );
		$bare = preg_replace( '#^https?://#i', '', $home );
		if ( 0 === stripos( (string) preg_replace( '#^https?://#i', '', $location ), (string) $bare ) ) {
			$location = (string) substr( (string) preg_replace( '#^https?://#i', '', $location ), strlen( (string) $bare ) );
		}
		$location = '' === $location ? '/' : $location;
		return strlen( $location ) > 1 ? rtrim( $location, '/' ) : $location;
	}

	/* ── Check types ── */

	/**
	 * A page: baseline versus preview (preview mode) or just healthy (live mode).
	 *
	 * @param array<string, mixed> $check Check.
	 * @param string|null          $token Token.
	 * @param string               $mode  Mode.
	 * @param bool                 $admin Forward the admin cookies.
	 * @return array{status:string,detail:string,data?:array<string, mixed>}
	 */
	private static function check_page( array $check, ?string $token, string $mode, bool $admin = false ): array {
		$opts = array( 'cookies' => $admin );
		$now  = self::fetch( (string) $check['url'], $token, $opts );

		if ( '' !== $now['error'] ) {
			return self::result( 'fail', sprintf( /* translators: %s: error message. */ __( 'The request failed: %s', 'ux-studio' ), $now['error'] ) );
		}
		if ( null !== $token && ! self::was_preview( $now ) ) {
			return self::result( 'fail', __( 'The preview did not take effect on this page (the preview file is not loaded or the token was refused).', 'ux-studio' ) );
		}
		if ( self::is_fatal( $now ) ) {
			return self::result(
				'fail',
				sprintf(
					/* translators: %d: HTTP status. */
					__( 'The page fails with a server error (HTTP %d) or a PHP fatal error.', 'ux-studio' ),
					$now['code']
				)
			);
		}

		$data = array( 'code' => $now['code'] );
		if ( self::MODE_LIVE === $mode ) {
			$was = self::baseline_code( (string) $check['id'] );
			if ( null !== $was && $was < 400 && $now['code'] >= 400 ) {
				return self::result( 'fail', sprintf( /* translators: 1: HTTP status before, 2: after. */ __( 'The page answered %1$d before the switch and answers %2$d now.', 'ux-studio' ), $was, $now['code'] ), $data );
			}
			return self::result( $now['code'] >= 400 ? 'warn' : 'ok', sprintf( /* translators: %d: HTTP status. */ __( 'HTTP %d', 'ux-studio' ), $now['code'] ), $data );
		}

		$base = self::fetch( (string) $check['url'], null, $opts );
		if ( '' !== $base['error'] ) {
			return self::result( 'warn', sprintf( /* translators: %s: error message. */ __( 'The page works in the preview, but the current site could not be requested for comparison: %s', 'ux-studio' ), $base['error'] ), $data );
		}
		$data['code'] = $base['code'];
		$warn         = '';
		if ( $base['code'] !== $now['code'] ) {
			if ( $base['code'] < 400 && $now['code'] >= 400 ) {
				return self::result( 'fail', sprintf( /* translators: 1: HTTP status now, 2: in the preview. */ __( 'The page answers %1$d now and %2$d in the preview.', 'ux-studio' ), $base['code'], $now['code'] ), $data );
			}
			$warn = sprintf( /* translators: 1: HTTP status now, 2: in the preview. */ __( 'The page answers %1$d now and %2$d in the preview.', 'ux-studio' ), $base['code'], $now['code'] );
		}
		$extra = self::notices( $now['body'] ) - self::notices( $base['body'] );
		if ( $extra > 0 ) {
			$warn = trim( $warn . ' ' . sprintf( /* translators: %d: number of PHP notices. */ _n( '%d new PHP warning is printed into the page.', '%d new PHP warnings are printed into the page.', $extra, 'ux-studio' ), $extra ) );
		}
		if ( '' !== $warn ) {
			return self::result( 'warn', $warn, $data );
		}
		return self::result( 'ok', sprintf( /* translators: 1: HTTP status, 2: milliseconds. */ __( 'HTTP %1$d, %2$d ms', 'ux-studio' ), $now['code'], $now['ms'] ), $data );
	}

	/**
	 * Status the page had in the preview baseline (kept in the run history).
	 *
	 * @param string $check_id Check id.
	 */
	private static function baseline_code( string $check_id ): ?int {
		$runs = History::all();
		foreach ( $runs as $run ) {
			$code = $run['preview']['results'][ $check_id ]['data']['code'] ?? null;
			if ( null !== $code && ! empty( $run['switched'] ) && empty( $run['switch_reverted'] ) ) {
				return (int) $code;
			}
		}
		return null;
	}

	/**
	 * Sample of the migrated redirect rules: same status and Location as the old plugin gave.
	 *
	 * @param string               $run_id Run id.
	 * @param array<string, mixed> $check  Check with `paths`.
	 * @param string|null          $token  Token.
	 * @param string               $mode   Mode.
	 * @return array{status:string,detail:string,data?:array<string, mixed>}
	 */
	private static function check_redirects( string $run_id, array $check, ?string $token, string $mode ): array {
		$paths = array_slice( array_values( array_unique( array_map( 'strval', (array) ( $check['paths'] ?? array() ) ) ) ), 0, 20 );
		if ( ! $paths ) {
			return self::result( 'info', __( 'There is no simple rule to sample.', 'ux-studio' ) );
		}
		$home = rtrim( (string) home_url(), '/' );
		$was  = self::MODE_LIVE === $mode ? (array) self::stored_baseline( (string) $check['id'] ) : array();

		$tested   = 0;
		$same     = 0;
		$bad      = array();
		$baseline = array();
		foreach ( $paths as $path ) {
			$url = $home . '/' . ltrim( $path, '/' );
			if ( self::MODE_LIVE === $mode ) {
				$old = $was[ $path ] ?? null;
			} else {
				$b   = self::fetch( $url, null, array( 'bust' => false ) );
				$old = array(
					'code' => $b['code'],
					'to'   => self::normalize_location( $b['headers']['location'] ?? '' ),
				);
			}
			$baseline[ $path ] = $old;
			if ( ! is_array( $old ) || $old['code'] < 300 || $old['code'] >= 400 ) {
				continue; // The old plugin did not redirect this one (rule off, regex ...): nothing to compare.
			}
			++$tested;
			$n = self::fetch( $url, $token, array( 'bust' => false ) );
			if ( null !== $token && ! self::was_preview( $n ) ) {
				return self::result( 'fail', __( 'The preview did not take effect on this page (the preview file is not loaded or the token was refused).', 'ux-studio' ) );
			}
			$got = array(
				'code' => $n['code'],
				'to'   => self::normalize_location( $n['headers']['location'] ?? '' ),
			);
			if ( $got === $old ) {
				++$same;
			} else {
				$bad[] = sprintf( '%s: %d %s -> %d %s', $path, $old['code'], $old['to'], $got['code'], $got['to'] );
			}
		}
		$data = array( 'baseline' => $baseline );
		if ( 0 === $tested ) {
			return self::result( 'warn', __( 'None of the sampled addresses was redirected by the old plugin, so there was nothing to compare.', 'ux-studio' ), $data );
		}
		if ( $bad ) {
			return self::result(
				'fail',
				sprintf(
					/* translators: 1: number of different redirects, 2: tested, 3: examples. */
					__( '%1$d of %2$d redirects differ from the old plugin: %3$s', 'ux-studio' ),
					count( $bad ),
					$tested,
					implode( '; ', array_slice( $bad, 0, 3 ) )
				),
				$data
			);
		}
		return self::result(
			'ok',
			sprintf(
				/* translators: %d: number of redirects. */
				_n( '%d redirect gives the same status and target as the old plugin.', '%d redirects give the same status and target as the old plugin.', $same, 'ux-studio' ),
				$same
			),
			$data
		);
	}

	/**
	 * Baseline of a check recorded during the preview of the switched run.
	 *
	 * @param string $check_id Check id.
	 * @return array<string, mixed>|null
	 */
	private static function stored_baseline( string $check_id ): ?array {
		foreach ( History::all() as $run ) {
			if ( ! empty( $run['switched'] ) && empty( $run['switch_reverted'] ) && isset( $run['preview']['results'][ $check_id ]['data']['baseline'] ) ) {
				return (array) $run['preview']['results'][ $check_id ]['data']['baseline'];
			}
		}
		return null;
	}

	/**
	 * The comment form must be gone from a post.
	 *
	 * @param string|null $token Token.
	 * @return array{status:string,detail:string}
	 */
	private static function check_comments( ?string $token ): array {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);
		if ( ! $posts ) {
			return self::result( 'info', __( 'The site has no published post to look at.', 'ux-studio' ) );
		}
		$res = self::fetch( (string) get_permalink( $posts[0] ), $token );
		if ( '' !== $res['error'] || self::is_fatal( $res ) ) {
			return self::result( 'fail', '' !== $res['error'] ? $res['error'] : __( 'The post fails with a server error.', 'ux-studio' ) );
		}
		if ( null !== $token && ! self::was_preview( $res ) ) {
			return self::result( 'fail', __( 'The preview did not take effect on this page (the preview file is not loaded or the token was refused).', 'ux-studio' ) );
		}
		if ( preg_match( '/id=["\']commentform["\']|class=["\'][^"\']*comment-form|id=["\']respond["\']/i', $res['body'] ) ) {
			return self::result( 'fail', __( 'The comment form is still on the post.', 'ux-studio' ) );
		}
		return self::result( 'ok', __( 'The comment form is not on the post.', 'ux-studio' ) );
	}

	/**
	 * The new login address works, and wp-login.php behaves as set.
	 *
	 * @param array<string, mixed> $check Check with `slug`, `behavior`, `redirect`.
	 * @param string|null          $token Token.
	 * @return array{status:string,detail:string}
	 */
	private static function check_login( array $check, ?string $token ): array {
		$slug = trim( (string) ( $check['slug'] ?? '' ), '/' );
		if ( '' === $slug ) {
			return self::result( 'info', __( 'No custom login address is set.', 'ux-studio' ) );
		}
		$new = self::fetch( home_url( '/' . $slug . '/' ), $token );
		if ( '' !== $new['error'] || self::is_fatal( $new ) ) {
			return self::result( 'fail', '' !== $new['error'] ? $new['error'] : __( 'The new login address fails with a server error.', 'ux-studio' ) );
		}
		if ( null !== $token && ! self::was_preview( $new ) ) {
			return self::result( 'fail', __( 'The preview did not take effect on this page (the preview file is not loaded or the token was refused).', 'ux-studio' ) );
		}
		if ( 200 !== $new['code'] || false === stripos( $new['body'], 'user_login' ) ) {
			return self::result( 'fail', sprintf( /* translators: 1: address, 2: HTTP status. */ __( 'The new login address %1$s does not show the login form (HTTP %2$d).', 'ux-studio' ), '/' . $slug . '/', $new['code'] ) );
		}
		$old = self::fetch( site_url( 'wp-login.php', 'login' ), $token );
		if ( '' !== $old['error'] ) {
			return self::result( 'fail', $old['error'] );
		}
		$behavior = (string) ( $check['behavior'] ?? '404' );
		if ( 'url_redirect' === $behavior ) {
			if ( $old['code'] < 300 || $old['code'] >= 400 ) {
				return self::result( 'fail', sprintf( /* translators: %d: HTTP status. */ __( 'wp-login.php should redirect, but answers %d.', 'ux-studio' ), $old['code'] ) );
			}
		} elseif ( 404 !== $old['code'] ) {
			return self::result( 'fail', sprintf( /* translators: %d: HTTP status. */ __( 'wp-login.php should answer 404, but answers %d.', 'ux-studio' ), $old['code'] ) );
		}
		return self::result( 'ok', sprintf( /* translators: %s: login address. */ __( 'The login form is at %s and wp-login.php is closed as set.', 'ux-studio' ), '/' . $slug . '/' ) );
	}

	/**
	 * Does an admin screen answer "you are not allowed"?
	 *
	 * @param array{code:int,body:string} $res Response.
	 */
	public static function is_denied( array $res ): bool {
		if ( 403 === (int) $res['code'] ) {
			return true;
		}
		return (bool) preg_match( '/Sorry, you are not allowed|you are not allowed to (?:access|view|manage)|nem[aá]te opr[aá]vn[eě]n[ií]|You need a higher level of permission|wp-die-message[^>]*>[^<]*(?:not allowed|opr[aá]vn)/iu', $res['body'] );
	}

	/**
	 * The administrator who runs the migration can still open Plugins, Users, Settings and UX Studio
	 * (the lockout guard of the Admin Customiser sources). Preview: the screens are fetched with the admin's
	 * cookies and the preview header; a screen that is refused there but opened on the current site is a failure.
	 *
	 * @param array<string, mixed> $check Check with `urls` (relative to wp-admin).
	 * @param string|null          $token Token (null = live site).
	 * @return array{status:string,detail:string,data?:array<string, mixed>}
	 */
	private static function check_admin_access( array $check, ?string $token ): array {
		$urls = array_values( array_filter( array_map( 'strval', (array) ( $check['urls'] ?? array() ) ) ) );
		if ( ! $urls ) {
			return self::result( 'info', __( 'No admin screens to look at.', 'ux-studio' ) );
		}
		$open  = 0;
		$bad   = array();
		$warn  = array();
		$codes = array();
		foreach ( $urls as $rel ) {
			$url = admin_url( ltrim( $rel, '/' ) );
			$now = self::fetch( $url, $token, array( 'cookies' => true ) );
			if ( '' !== $now['error'] ) {
				$bad[] = $rel . ': ' . $now['error'];
				continue;
			}
			if ( null !== $token && ! self::was_preview( $now ) ) {
				$bad[] = $rel . ': ' . __( 'the preview did not take effect on this page', 'ux-studio' );
				continue;
			}
			$codes[ $rel ] = $now['code'];
			if ( self::is_fatal( $now ) ) {
				$bad[] = $rel . ': ' . __( 'server error', 'ux-studio' );
				continue;
			}
			if ( $now['code'] >= 300 && $now['code'] < 400 ) {
				$warn[] = $rel . ': ' . sprintf( /* translators: %d: HTTP status. */ __( 'redirected (HTTP %d), the session was not accepted', 'ux-studio' ), $now['code'] );
				continue;
			}
			if ( 200 === $now['code'] && ! self::is_denied( $now ) ) {
				++$open;
				continue;
			}
			// Refused or missing in the preview: only a failure of the migration when the current site opens it.
			if ( null !== $token ) {
				$base = self::fetch( $url, null, array( 'cookies' => true ) );
				if ( '' === $base['error'] && 200 === $base['code'] && ! self::is_denied( $base ) ) {
					$bad[] = $rel . ': ' . sprintf( /* translators: %d: HTTP status. */ __( 'refused in the preview (HTTP %d), but open on the current site', 'ux-studio' ), $now['code'] );
				} else {
					$warn[] = $rel . ': ' . __( 'refused here already before the migration (the old plugins or the role limit it)', 'ux-studio' );
				}
			} else {
				$bad[] = $rel . ': ' . sprintf( /* translators: %d: HTTP status. */ __( 'refused (HTTP %d)', 'ux-studio' ), $now['code'] );
			}
		}
		$data = array( 'code' => 200, 'codes' => $codes );
		if ( $bad ) {
			return self::result( 'fail', implode( '; ', $bad ), $data );
		}
		if ( $warn ) {
			return self::result( 'warn', implode( '; ', $warn ), $data );
		}
		return self::result(
			'ok',
			sprintf(
				/* translators: 1: number of screens opened, 2: number of screens checked. */
				__( '%1$d of %2$d admin screens open for the administrator.', 'ux-studio' ),
				$open,
				count( $urls )
			),
			$data
		);
	}

	/**
	 * Send a test e-mail through the module (to the site administrator).
	 *
	 * @param string|null $token Token.
	 * @return array{status:string,detail:string}
	 */
	private static function check_mail( ?string $token ): array {
		$to  = (string) get_option( 'admin_email' );
		$res = self::fetch(
			rest_url( 'uxstudio/v1/smtp-email/test' ),
			$token,
			array(
				'method'  => 'POST',
				'cookies' => true,
				'nonce'   => true,
				'bust'    => false,
				'body'    => array( 'to' => $to ),
			)
		);
		if ( '' !== $res['error'] ) {
			return self::result( 'fail', $res['error'] );
		}
		if ( null !== $token && ! self::was_preview( $res ) ) {
			return self::result( 'fail', __( 'The preview did not take effect on this request.', 'ux-studio' ) );
		}
		$json = json_decode( $res['body'], true );
		if ( 200 !== $res['code'] || ! is_array( $json ) ) {
			return self::result( 'fail', sprintf( /* translators: %d: HTTP status. */ __( 'The test e-mail could not be requested (HTTP %d). Is the SMTP module available?', 'ux-studio' ), $res['code'] ) );
		}
		$data = (array) ( $json['data'] ?? array() );
		if ( empty( $data['success'] ) ) {
			return self::result( 'fail', sprintf( /* translators: %s: error message. */ __( 'The test e-mail was not sent: %s', 'ux-studio' ), (string) ( $data['error'] ?? '' ) ) );
		}
		return self::result( 'ok', sprintf( /* translators: %s: e-mail address. */ __( 'A test e-mail was sent to %s through the module.', 'ux-studio' ), $to ) );
	}

	/**
	 * The block editor is off for the configured post types.
	 *
	 * @param array<string, mixed> $check Check with `post_types` (empty = every public type).
	 * @param string|null          $token Token.
	 * @return array{status:string,detail:string}
	 */
	private static function check_editor( array $check, ?string $token ): array {
		$res = self::fetch(
			rest_url( 'uxstudio/v1/migration/probe' ),
			$token,
			array(
				'cookies' => true,
				'nonce'   => true,
				'bust'    => false,
			)
		);
		if ( '' !== $res['error'] ) {
			return self::result( 'fail', $res['error'] );
		}
		if ( null !== $token && ! self::was_preview( $res ) ) {
			return self::result( 'fail', __( 'The preview did not take effect on this request.', 'ux-studio' ) );
		}
		$json = json_decode( $res['body'], true );
		$map  = is_array( $json ) ? (array) ( $json['data']['block_editor'] ?? array() ) : array();
		if ( 200 !== $res['code'] || ! $map ) {
			return self::result( 'fail', sprintf( /* translators: %d: HTTP status. */ __( 'The editor settings could not be read (HTTP %d).', 'ux-studio' ), $res['code'] ) );
		}
		$wanted = array_values( array_filter( array_map( 'strval', (array) ( $check['post_types'] ?? array() ) ) ) );
		// The module never touches these (same list as the module itself).
		$skip   = (array) apply_filters( 'ux_studio/classic_editor/excluded_post_types', array( 'attachment', 'elementor_library' ) );
		$wanted = $wanted ? array_values( array_intersect( $wanted, array_keys( $map ) ) ) : array_keys( $map );
		$wanted = array_values( array_diff( $wanted, $skip ) );
		$still  = array_values( array_filter( $wanted, static fn( string $type ): bool => ! empty( $map[ $type ] ) ) );
		if ( $still ) {
			return self::result( 'fail', sprintf( /* translators: %s: post types. */ __( 'The block editor is still on for: %s', 'ux-studio' ), implode( ', ', $still ) ) );
		}
		return self::result(
			'ok',
			sprintf(
				/* translators: %d: number of post types. */
				_n( 'The classic editor is used for %d post type.', 'The classic editor is used for all %d post types.', count( $wanted ), 'ux-studio' ),
				count( $wanted )
			)
		);
	}

	/**
	 * Snippets: the front end still renders without a fatal error.
	 *
	 * @param string|null $token Token.
	 * @return array{status:string,detail:string}
	 */
	private static function check_clean( ?string $token ): array {
		$urls = array( home_url( '/' ) );
		$post = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);
		if ( $post ) {
			$urls[] = (string) get_permalink( $post[0] );
		}
		foreach ( $urls as $url ) {
			$res = self::fetch( $url, $token );
			if ( '' !== $res['error'] || self::is_fatal( $res ) ) {
				return self::result( 'fail', sprintf( /* translators: %s: address. */ __( 'The page %s fails with the snippets switched on.', 'ux-studio' ), $url ) );
			}
			if ( null !== $token && ! self::was_preview( $res ) ) {
				return self::result( 'fail', __( 'The preview did not take effect on this page (the preview file is not loaded or the token was refused).', 'ux-studio' ) );
			}
		}
		return self::result( 'ok', __( 'The pages render without errors with the snippets switched on.', 'ux-studio' ) );
	}

	/* ── Summary ── */

	/**
	 * Worst status of a list of results.
	 *
	 * @param array<int|string, array<string, mixed>> $results Results.
	 */
	public static function worst( array $results ): string {
		$rank  = array(
			'info' => 0,
			'ok'   => 1,
			'warn' => 2,
			'fail' => 3,
		);
		$worst = 'ok';
		foreach ( $results as $row ) {
			$status = (string) ( $row['status'] ?? 'ok' );
			if ( ( $rank[ $status ] ?? 0 ) > $rank[ $worst ] ) {
				$worst = $status;
			}
		}
		return $worst;
	}
}
