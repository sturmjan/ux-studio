<?php
/**
 * Content-Security-Policy builder and header emitter (front end only).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Modes (setting `csp_mode`):
 *
 * - `audit`       Report-Only inventory policy: every external origin is
 *                 disallowed, so the browser reports each one the page loads.
 *                 Inline code and eval stay allowed - WordPress pages carry
 *                 dozens of inline scripts and style attributes and a report
 *                 for each would flood the server. The goal is foreign
 *                 domains (where injected code loads from or sends data to).
 * - `report-only` Report-Only candidate policy (presets + approved sources):
 *                 a dry run of what Enforce would block.
 * - `enforce`     The candidate policy, enforced.
 *
 * Never sent in wp-admin, in page-builder previews or the Customizer, and
 * never when UXSTUDIO_CSP_DISABLE is defined true in wp-config.php.
 */
final class CspPolicy {

	public const MODES = array( 'off', 'audit', 'report-only', 'enforce' );

	/** Directive => list of sources approved from the inventory (autoloaded, read on every page). */
	public const SOURCES_OPTION = 'uxstudio_csp_sources';

	/** Mode bookkeeping: mode, since, enforce_since, paused_until (autoloaded). */
	public const STATE_OPTION = 'uxstudio_csp_state';

	/** Kill-switch constant for wp-config.php. */
	public const KILL_SWITCH = 'UXSTUDIO_CSP_DISABLE';

	/** Name of the Reporting API endpoint group. */
	public const REPORT_GROUP = 'uxs-csp';

	/** Query flag that forces the header (self-test), value = probe token. */
	public const PROBE_PARAM = 'uxs_csp_probe';

	/** Directives that may receive approved sources. */
	public const SOURCE_DIRECTIVES = array( 'script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src', 'media-src', 'worker-src', 'manifest-src', 'form-action' );

	/** @var callable(string,mixed):mixed */
	private $setting;

	/**
	 * @param callable(string,mixed):mixed $setting Module setting getter.
	 */
	public function __construct( callable $setting ) {
		$this->setting = $setting;
	}

	/* ── Header emission ─────────────────────────────────────────── */

	/**
	 * Emit the CSP header for the current front-end request (send_headers).
	 */
	public function send(): void {
		if ( headers_sent() || is_admin() || self::disabled_by_constant() ) {
			return;
		}

		if ( $this->is_editor_preview() ) {
			return;
		}

		$mode  = $this->mode();
		$probe = $this->is_probe_request();
		if ( 'off' === $mode ) {
			if ( ! $probe ) {
				return;
			}
			// The self-test may run before CSP is switched on; a server-side
			// probe never produces reports, so the inventory policy is harmless.
			$mode = 'audit';
		}

		if ( 'enforce' !== $mode && ! $probe && ! $this->sampled_in() ) {
			return;
		}

		$policy = 'audit' === $mode ? $this->audit_policy() : $this->candidate_policy();
		$url    = self::report_url( self::policy_tag( $mode ) );

		$policy .= '; report-uri ' . $url . '; report-to ' . self::REPORT_GROUP;

		$header = 'enforce' === $mode ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
		header( 'Reporting-Endpoints: ' . self::REPORT_GROUP . '="' . $url . '"' );
		header( $header . ': ' . $policy );
	}

	/**
	 * Current mode, normalised.
	 */
	public function mode(): string {
		$mode = (string) ( $this->setting )( 'csp_mode', 'off' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	public static function disabled_by_constant(): bool {
		return defined( self::KILL_SWITCH ) && constant( self::KILL_SWITCH );
	}

	/**
	 * Short tag the report URL carries, so the receiver knows which policy
	 * produced a violation (a = audit, t = test, e = enforce).
	 */
	public static function policy_tag( string $mode ): string {
		return array(
			'audit'       => 'a',
			'report-only' => 't',
			'enforce'     => 'e',
		)[ $mode ] ?? 'a';
	}

	/**
	 * Report sink URL with the anti-spam token and the signed policy tag.
	 *
	 * @param string $tag Policy letter (a, t, e) of the mode that sends the header.
	 */
	public static function report_url( string $tag ): string {
		return esc_url_raw(
			add_query_arg(
				array(
					'k' => self::report_token(),
					'p' => self::report_tag( $tag ),
				),
				rest_url( 'uxstudio/v1/security-optimization/csp-report' )
			)
		);
	}

	/**
	 * Policy tag of the report URL: the policy letter plus an HMAC over
	 * (letter, mode, since of that mode). The receiver never trusts a plain
	 * letter from the request - a report counts for a policy only while that
	 * exact mode period is active, so tags of an earlier period (and forged
	 * ones) are dropped. The tag is still visible in the response header, so
	 * it only proves WHICH period a report claims, not who sent it; the
	 * rollback therefore also demands distinct reporters.
	 *
	 * @param string $letter Policy letter (a, t, e).
	 * @param int    $since  Start of the active mode period (default: state).
	 */
	public static function report_tag( string $letter, int $since = -1 ): string {
		$modes = array(
			'a' => 'audit',
			't' => 'report-only',
			'e' => 'enforce',
		);
		$letter = isset( $modes[ $letter ] ) ? $letter : 'a';
		$since  = $since < 0 ? self::state()['since'] : $since;
		return $letter . substr( hash_hmac( 'sha256', 'uxstudio-csp-tag|' . $letter . '|' . $modes[ $letter ] . '|' . $since, wp_salt( 'auth' ) ), 0, 12 );
	}

	/**
	 * Policy letter of a report-URL tag, or '' when the tag is forged, from
	 * an earlier mode period, or does not belong to the active mode.
	 *
	 * @param string $tag  Value of the `p` request parameter.
	 * @param string $mode Currently active mode (csp_mode setting).
	 */
	public static function verify_report_tag( string $tag, string $mode ): string {
		if ( 'off' === $mode || strlen( $tag ) !== 13 ) {
			return '';
		}
		$letter = self::policy_tag( $mode );
		if ( ! hash_equals( self::report_tag( $letter ), $tag ) ) {
			return '';
		}
		return $letter;
	}

	/**
	 * Token in the report URL. It is visible in the page's response header,
	 * so it is no secret against someone who loaded the site - it stops blind
	 * spam against the endpoint and reports for foreign sites.
	 */
	public static function report_token(): string {
		return substr( hash_hmac( 'sha256', 'uxstudio-csp-report', wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * Token for the self-test query flag (separate from the report token so
	 * the public header doesn't reveal it).
	 */
	public static function probe_token(): string {
		return substr( hash_hmac( 'sha256', 'uxstudio-csp-probe', wp_salt( 'auth' ) ), 0, 20 );
	}

	private function is_probe_request(): bool {
		$value = isset( $_GET[ self::PROBE_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PROBE_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '' !== $value && hash_equals( self::probe_token(), $value );
	}

	/**
	 * Page builders and the Customizer load their editor UI on the front end
	 * (often from their own CDNs); a policy there only produces noise or,
	 * enforced, a broken editor.
	 */
	private function is_editor_preview(): bool {
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}
		foreach ( array( 'elementor-preview', 'et_fb', 'fl_builder', 'vc_editable', 'vc_action', 'bricks', 'ct_builder', 'brizy-edit-iframe', 'tve', 'breakdance', 'oxygen_iframe' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		return false;
	}

	/**
	 * Report-Only sampling: `csp_sample_rate` percent of page views carry the
	 * header, and none while the hourly report cap has paused it.
	 */
	private function sampled_in(): bool {
		$state = self::state();
		if ( ! empty( $state['paused_until'] ) && (int) $state['paused_until'] > time() ) {
			return false;
		}
		$rate = (int) ( $this->setting )( 'csp_sample_rate', 100 );
		if ( $rate >= 100 ) {
			return true;
		}
		if ( $rate <= 0 ) {
			return false;
		}
		return wp_rand( 1, 100 ) <= $rate;
	}

	/* ── Policies ─────────────────────────────────────────────────── */

	/**
	 * Inventory policy for `audit`: own origin only for every fetch type,
	 * inline/eval allowed (see class doc).
	 */
	public function audit_policy(): string {
		return self::serialize(
			array(
				'default-src'  => array( "'self'" ),
				'script-src'   => array( "'self'", "'unsafe-inline'", "'unsafe-eval'", 'blob:' ),
				'style-src'    => array( "'self'", "'unsafe-inline'" ),
				'img-src'      => array( "'self'", 'data:', 'blob:' ),
				'font-src'     => array( "'self'", 'data:' ),
				'connect-src'  => array( "'self'" ),
				'frame-src'    => array( "'self'" ),
				'media-src'    => array( "'self'", 'data:', 'blob:' ),
				'worker-src'   => array( "'self'", 'blob:' ),
				'manifest-src' => array( "'self'" ),
				'object-src'   => array( "'none'" ),
				'base-uri'     => array( "'self'" ),
				'form-action'  => array( "'self'" ),
			)
		);
	}

	/**
	 * Candidate policy for `report-only`/`enforce`: level + presets + custom
	 * allowlist (all directives) + sources approved from the inventory (only
	 * the directive they were seen on). Individual directives can be filtered
	 * via uxstudio_csp_<directive>, the whole map via uxstudio_csp_directives.
	 */
	public function candidate_policy(): string {
		return self::serialize( $this->candidate_directives() );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	public function candidate_directives(): array {
		$level      = (string) ( $this->setting )( 'csp_level', 'basic' );
		$script_src = array( "'self'", "'unsafe-inline'" );
		if ( 'basic' === $level ) {
			$script_src[] = "'unsafe-eval'";
		}

		$directives = array(
			'default-src'     => array( "'self'" ),
			'script-src'      => $script_src,
			'style-src'       => array( "'self'", "'unsafe-inline'" ),
			'img-src'         => array( "'self'", 'data:', 'https:' ),
			'font-src'        => array( "'self'", 'data:', 'https:' ),
			'connect-src'     => array( "'self'" ),
			'frame-src'       => array( "'self'" ),
			'frame-ancestors' => array( "'self'" ),
			'base-uri'        => array( "'self'" ),
			'form-action'     => array( "'self'" ),
		);

		foreach ( (array) ( $this->setting )( 'csp_presets', array() ) as $preset ) {
			foreach ( CspServices::preset_sources( (string) $preset ) as $directive => $sources ) {
				$directives[ $directive ] = array_merge( $directives[ $directive ] ?? array(), $sources );
			}
		}

		$custom = self::parse_allowlist( (string) ( $this->setting )( 'csp_custom_allowlist', '' ) );
		if ( ! empty( $custom ) ) {
			foreach ( array( 'script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'frame-src' ) as $directive ) {
				$directives[ $directive ] = array_merge( $directives[ $directive ] ?? array(), $custom );
			}
		}

		foreach ( self::approved_sources() as $directive => $sources ) {
			// A directive missing from the map falls back to default-src
			// ('self'), so it has to start from there, not from nothing.
			$directives[ $directive ] = array_merge( $directives[ $directive ] ?? array( "'self'" ), $sources );
		}

		foreach ( $directives as $name => $sources ) {
			$directives[ $name ] = apply_filters( 'uxstudio_csp_' . str_replace( '-', '_', $name ), $sources );
		}

		/**
		 * Filter the full CSP directive map before it is serialized.
		 *
		 * @param array<string,array<int,string>> $directives Directive => sources.
		 */
		return self::with_inline_hashes( (array) apply_filters( 'uxstudio_csp_directives', $directives ), array( CspInlineScripts::class, 'sources' ) );
	}

	/**
	 * Is script-src (or default-src when script-src is missing) strict, i.e. without 'unsafe-inline'? Only then do our
	 * own inline scripts need a hash. The built-in levels always carry 'unsafe-inline' (WordPress pages are full of
	 * inline scripts), so this is true for a policy narrowed through the uxstudio_csp_* filters.
	 *
	 * @param array<string,array<int,string>> $directives Directive => sources.
	 */
	public static function is_strict_script_policy( array $directives ): bool {
		$effective = $directives['script-src'] ?? ( $directives['default-src'] ?? array() );
		return ! empty( $effective ) && ! in_array( "'unsafe-inline'", $effective, true );
	}

	/**
	 * Adds the hashes of UX Studio's own inline scripts - and only when that is safe. A browser ignores
	 * 'unsafe-inline' as soon as a hash or nonce is in the same directive, so a hash in a lenient policy would block
	 * every other inline script of the site. Hence: no 'unsafe-inline' in the effective script-src, hashes are added;
	 * otherwise the policy stays exactly as it was. An explicit script-src-elem is judged on its own.
	 *
	 * @param array<string,array<int,string>> $directives Directive => sources.
	 * @param callable():array<int,string>    $hashes     Quoted hash sources (called lazily, only for a strict policy).
	 * @return array<string,array<int,string>>
	 */
	public static function with_inline_hashes( array $directives, callable $hashes ): array {
		$wants_script_src = self::is_strict_script_policy( $directives );
		$wants_elem       = isset( $directives['script-src-elem'] ) && ! in_array( "'unsafe-inline'", (array) $directives['script-src-elem'], true ) && ! empty( $directives['script-src-elem'] );
		if ( ! $wants_script_src && ! $wants_elem ) {
			return $directives;
		}
		$list = array_values( array_filter( (array) $hashes(), 'is_string' ) );
		if ( empty( $list ) ) {
			return $directives;
		}
		if ( $wants_script_src ) {
			$base                     = $directives['script-src'] ?? $directives['default-src'];
			$directives['script-src'] = array_merge( (array) $base, $list );
		}
		if ( $wants_elem ) {
			$directives['script-src-elem'] = array_merge( (array) $directives['script-src-elem'], $list );
		}
		return $directives;
	}

	/**
	 * Is an origin already allowed for a directive by the candidate policy?
	 */
	public function candidate_allows( string $directive, string $host ): bool {
		$directive  = self::base_directive( $directive );
		$directives = $this->candidate_directives();
		$sources    = $directives[ $directive ] ?? ( $directives['default-src'] ?? array() );
		foreach ( (array) $sources as $source ) {
			if ( 'https:' === $source || CspServices::source_matches_host( (string) $source, strtolower( $host ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,array<int,string>> $directives Directive => sources.
	 */
	private static function serialize( array $directives ): string {
		$parts = array();
		foreach ( $directives as $name => $sources ) {
			$sources = array_values( array_unique( array_filter( array_map( 'trim', (array) $sources ), 'strlen' ) ) );
			if ( empty( $sources ) ) {
				continue;
			}
			$parts[] = $name . ' ' . implode( ' ', $sources );
		}
		return implode( '; ', $parts );
	}

	/**
	 * script-src-elem/-attr and style-src-elem/-attr map to their base
	 * directive (the policy only sets the base ones).
	 */
	public static function base_directive( string $directive ): string {
		$directive = strtolower( trim( $directive ) );
		return (string) preg_replace( '/^(script-src|style-src)-(elem|attr)$/', '$1', $directive );
	}

	/* ── Approved sources ─────────────────────────────────────────── */

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function approved_sources(): array {
		$out = array();
		foreach ( (array) get_option( self::SOURCES_OPTION, array() ) as $directive => $sources ) {
			if ( in_array( $directive, self::SOURCE_DIRECTIVES, true ) && is_array( $sources ) ) {
				$out[ $directive ] = array_values( array_filter( array_map( 'strval', $sources ), 'strlen' ) );
			}
		}
		return $out;
	}

	/**
	 * Add one origin to a directive's approved sources.
	 *
	 * @return bool True when it was added, false when it was already there or invalid.
	 */
	public static function approve_source( string $directive, string $source ): bool {
		$directive = self::base_directive( $directive );
		if ( ! in_array( $directive, self::SOURCE_DIRECTIVES, true ) || '' === $source ) {
			return false;
		}
		$all = self::approved_sources();
		$set = $all[ $directive ] ?? array();
		if ( in_array( $source, $set, true ) ) {
			return false;
		}
		$set[]             = $source;
		$all[ $directive ] = $set;
		update_option( self::SOURCES_OPTION, $all, true );
		return true;
	}

	/**
	 * Parse the newline/comma separated custom allowlist textarea into a list
	 * of validated CSP source tokens (quoted keywords, scheme URLs, wildcard hosts).
	 *
	 * @return array<int,string>
	 */
	public static function parse_allowlist( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( "/^'[a-z0-9\-]+'$/i", $line ) ) {
				$out[] = $line;
				continue;
			}
			if ( preg_match( '#^(https?:|wss?:|data:|blob:)#i', $line )
				|| preg_match( '#^\*\.[a-z0-9\-]+#i', $line )
				|| preg_match( '#^[a-z0-9\-]+\.[a-z]#i', $line ) ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ── State ────────────────────────────────────────────────────── */

	/**
	 * @return array{mode:string,since:int,enforce_since:int,paused_until:int}
	 */
	public static function state(): array {
		$state = (array) get_option( self::STATE_OPTION, array() );
		return array(
			'mode'          => (string) ( $state['mode'] ?? 'off' ),
			'since'         => (int) ( $state['since'] ?? 0 ),
			'enforce_since' => (int) ( $state['enforce_since'] ?? 0 ),
			'paused_until'  => (int) ( $state['paused_until'] ?? 0 ),
		);
	}

	/**
	 * @param array<string,int|string> $changes Keys to overwrite.
	 */
	public static function update_state( array $changes ): void {
		update_option( self::STATE_OPTION, array_merge( self::state(), $changes ), true );
	}

	/**
	 * Record a mode change (called after settings are saved).
	 */
	public static function record_mode( string $mode ): void {
		$state = self::state();
		if ( $state['mode'] === $mode && $state['since'] > 0 ) {
			return;
		}
		self::update_state(
			array(
				'mode'          => $mode,
				'since'         => time(),
				'enforce_since' => 'enforce' === $mode ? time() : 0,
				'paused_until'  => 0,
			)
		);
	}
}
