<?php
/**
 * CSP violation reporting REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST   uxstudio/v1/security-optimization/csp-report              - PUBLIC, browsers post CSP violation reports here
 * GET    uxstudio/v1/security-optimization/csp-violations           - list violations (filter by status/level)
 * POST   uxstudio/v1/security-optimization/csp-violations/{id}/resolve - mark a violation resolved
 * POST   uxstudio/v1/security-optimization/csp-violations/{id}/allow   - approve its origin for its directive + resolve
 * POST   uxstudio/v1/security-optimization/csp-violations/approve-safe - approve every open row without a risk signal
 * DELETE uxstudio/v1/security-optimization/csp-violations/{id}      - delete a violation
 * GET    uxstudio/v1/security-optimization/csp/status                - mode, self-test, stats, Enforce gate
 * POST   uxstudio/v1/security-optimization/csp/probe                 - run the header self-test now
 *
 * The report endpoint must stay public with permission_callback '__return_true'
 * because browsers send violation reports without any WordPress auth context.
 * To keep it from being used as a DoS vector against the DB:
 * - the URL carries a token derived from wp_salt() (CspPolicy::report_token()),
 * - reports for documents of other hosts and from browser extensions are dropped,
 * - it is rate-limited per client IP (hashed, never stored raw) via a transient,
 * - over a site-wide hourly cap the Report-Only header pauses for the rest of the hour.
 */
final class CspRestController extends Controller {

	private const REPORT_RATE_LIMIT  = 60;
	private const REPORT_RATE_WINDOW = 60;

	/** Site-wide reports per hour before Report-Only sampling pauses. */
	private const HOURLY_CAP = 600;

	/** URI schemes of browser extensions - their injections are not the site's. */
	private const NOISE_SCHEMES = array( 'chrome-extension', 'moz-extension', 'safari-extension', 'safari-web-extension', 'ms-browser-extension', 'edge-extension', 'about', 'android-webview-video-poster', 'chromeinvoke', 'chromenull', 'webviewprogressproxy', 'mxaddon-pkg', 'jar', 'resource' );

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/security-optimization/csp-report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive_report' ),
				'permission_callback' => '__return_true',
			)
		);

		$id_arg = array(
			'id' => array(
				'required' => true,
				'type'     => 'integer',
			),
		);

		$this->route( '/security-optimization/csp-violations', 'GET', array( $this, 'list_violations' ) );
		$this->route( '/security-optimization/csp-violations/approve-safe', 'POST', array( $this, 'approve_safe' ) );
		$this->route( '/security-optimization/csp-violations/(?P<id>\d+)/resolve', 'POST', array( $this, 'resolve_violation' ), $id_arg );
		$this->route( '/security-optimization/csp-violations/(?P<id>\d+)/allow', 'POST', array( $this, 'allow_violation' ), $id_arg );
		$this->route( '/security-optimization/csp-violations/(?P<id>\d+)', 'DELETE', array( $this, 'delete_violation' ), $id_arg );
		$this->route( '/security-optimization/csp/status', 'GET', array( $this, 'status' ) );
		$this->route( '/security-optimization/csp/probe', 'POST', array( $this, 'probe' ) );
	}

	/**
	 * Public CSP report sink. Accepts both the legacy `application/csp-report`
	 * shape ({"csp-report": {...}}) and the modern Reporting API
	 * (`application/reports+json`, array of {"type":"csp-violation","body":{...}}).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function receive_report( WP_REST_Request $request ): WP_REST_Response {
		$token = (string) $request->get_param( 'k' );
		if ( '' === $token || ! hash_equals( CspPolicy::report_token(), $token ) ) {
			// Same answer as success: nothing to learn for a prober.
			return new WP_REST_Response( null, 204 );
		}

		$ip = $this->client_ip();
		if ( ! $this->check_report_rate_limit( $ip ) ) {
			return new WP_REST_Response( null, 429 );
		}
		if ( ! $this->count_towards_hourly_cap() ) {
			return new WP_REST_Response( null, 204 );
		}

		$raw = $request->get_body();
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_REST_Response( null, 204 );
		}

		$max = CspLogger::max_body_bytes();
		if ( strlen( $raw ) > $max ) {
			return new WP_REST_Response( null, 204 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$reports = $this->normalize_reports( $data );
		if ( empty( $reports ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$policy = sanitize_key( (string) $request->get_param( 'p' ) );
		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$notify = false;

		foreach ( array_slice( $reports, 0, 20 ) as $report ) {
			if ( ! $this->is_own_document( (string) ( $report['document-uri'] ?? '' ) ) || $this->is_noise( $report ) ) {
				continue;
			}
			$result = CspLogger::record( $report, $policy, $ua );
			if ( null !== $result && $result['new'] && CspRisk::LEVEL_HIGH === $result['level'] ) {
				$notify = true;
			}
		}

		if ( $notify ) {
			CspMaintenance::queue_notification();
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_violations( WP_REST_Request $request ) {
		$args = array(
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'level'    => sanitize_key( (string) $request->get_param( 'level' ) ),
			'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'  => sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'risk' ) ),
			'order'    => sanitize_key( (string) ( $request->get_param( 'order' ) ?: 'desc' ) ),
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 200 ),
			'paged'    => (int) ( $request->get_param( 'paged' ) ?: 1 ),
		);

		$policy = $this->policy();
		$items  = array_map(
			static function ( array $row ) use ( $policy ): array {
				$row['allowed'] = '' !== $row['source'] && null !== $policy && $policy->candidate_allows( (string) $row['directive'], (string) $row['blocked_host'] );
				return $row;
			},
			CspLogger::get_violations( $args )
		);
		$total = CspLogger::get_violations_count( $args );

		return $this->ok( $items, array( 'total' => $total ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function resolve_violation( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$updated = CspLogger::set_status( array( $id ), 'resolved' );
		if ( 0 === $updated ) {
			return new WP_Error( 'uxstudio_csp_not_found', __( 'Violation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'resolved' => true ) );
	}

	/**
	 * Approve a violation's origin for the directive it was reported on (not
	 * for every directive - a host that only serves images must not become
	 * allowed to run scripts) and mark it resolved.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function allow_violation( WP_REST_Request $request ) {
		$violation = CspLogger::get( (int) $request->get_param( 'id' ) );
		if ( null === $violation ) {
			return new WP_Error( 'uxstudio_csp_not_found', __( 'Violation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$source = CspLogger::uri_to_source( (string) ( $violation['blocked_uri'] ?? '' ) );
		if ( '' === $source ) {
			return new WP_Error(
				'uxstudio_csp_not_allowable',
				__( 'This violation has no external source that can be allowlisted. Inline code, eval() and data: URIs have to be fixed in the code.', 'ux-studio' ),
				array( 'status' => 422 )
			);
		}

		$added = CspPolicy::approve_source( (string) $violation['directive'], $source );
		CspLogger::set_status( array( (int) $violation['id'] ), 'resolved' );

		return $this->ok(
			array(
				'source' => $source,
				'added'  => $added,
			)
		);
	}

	/**
	 * Approve every open row without any risk signal (own subdomains, known
	 * services, unknown hosts with score 0). Anything rated medium/high stays
	 * for a human decision.
	 */
	public function approve_safe() {
		$approved = 0;
		$ids      = array();
		foreach ( CspLogger::safe_open_rows() as $row ) {
			$source = CspLogger::uri_to_source( (string) $row['blocked_uri'] );
			if ( '' === $source ) {
				continue;
			}
			if ( CspPolicy::approve_source( (string) $row['directive'], $source ) ) {
				++$approved;
			}
			$ids[] = (int) $row['id'];
		}
		CspLogger::set_status( $ids, 'resolved' );

		return $this->ok(
			array(
				'approved' => $approved,
				'resolved' => count( $ids ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete_violation( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$deleted = CspLogger::delete( array( $id ) );
		if ( 0 === $deleted ) {
			return new WP_Error( 'uxstudio_csp_not_found', __( 'Violation not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Everything the CSP tab header and the Enforce dialog need.
	 */
	public function status() {
		$state    = CspPolicy::state();
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );

		return $this->ok(
			array(
				'mode'        => (string) ( $settings['csp_mode'] ?? 'off' ),
				'since'       => $state['since'],
				'paused'      => $state['paused_until'] > time(),
				'kill_switch' => CspPolicy::disabled_by_constant(),
				'probe'       => CspHeaderProbe::last(),
				'stats'       => CspLogger::stats(),
				'gate'        => array(
					'ready'     => CspGate::ready(),
					'checks'    => CspGate::checks(),
					'test_days' => CspGate::TEST_DAYS,
				),
				'approved'    => CspPolicy::approved_sources(),
			)
		);
	}

	/**
	 * Run the header self-test now.
	 */
	public function probe() {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		return $this->ok( CspHeaderProbe::run( (string) ( $settings['csp_mode'] ?? 'off' ), (int) ( $settings['csp_sample_rate'] ?? 100 ) ) );
	}

	/**
	 * Candidate policy built from the saved settings, for the "allowed" flag.
	 */
	private function policy(): ?CspPolicy {
		$settings = (array) get_option( 'uxstudio_security_optimization', array() );
		return new CspPolicy(
			static function ( string $key, $fallback = null ) use ( $settings ) {
				return $settings[ $key ] ?? $fallback;
			}
		);
	}

	/**
	 * @param array $data Decoded JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_reports( array $data ): array {
		if ( isset( $data['csp-report'] ) && is_array( $data['csp-report'] ) ) {
			return array( $data['csp-report'] );
		}

		$out = array();
		if ( isset( $data[0] ) ) {
			foreach ( $data as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				if ( 'csp-violation' === ( $entry['type'] ?? '' ) && is_array( $entry['body'] ?? null ) ) {
					$out[] = $this->map_reporting_api( $entry['body'], (string) ( $entry['url'] ?? '' ) );
				} elseif ( isset( $entry['csp-report'] ) && is_array( $entry['csp-report'] ) ) {
					$out[] = $entry['csp-report'];
				}
			}
		}
		return $out;
	}

	/**
	 * @param array  $body Reporting API report body.
	 * @param string $url  Report-level URL (document) as a fallback.
	 * @return array<string,mixed>
	 */
	private function map_reporting_api( array $body, string $url ): array {
		return array(
			'document-uri'        => (string) ( $body['documentURL'] ?? $url ),
			'blocked-uri'         => (string) ( $body['blockedURL'] ?? '' ),
			'effective-directive' => (string) ( $body['effectiveDirective'] ?? ( $body['violatedDirective'] ?? '' ) ),
			'violated-directive'  => (string) ( $body['violatedDirective'] ?? '' ),
			'source-file'         => (string) ( $body['sourceFile'] ?? '' ),
			'line-number'         => (int) ( $body['lineNumber'] ?? 0 ),
			'script-sample'       => (string) ( $body['sample'] ?? '' ),
		);
	}

	/**
	 * Only reports about pages of this site (own host or its subdomains).
	 */
	private function is_own_document( string $document_uri ): bool {
		$host = strtolower( (string) wp_parse_url( $document_uri, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$site = CspLogger::site_host();
		return $host === $site || CspRisk::registrable_domain( $host ) === CspRisk::registrable_domain( $site );
	}

	/**
	 * Violations caused by browser extensions, not by the site.
	 *
	 * @param array<string,mixed> $report Normalized report.
	 */
	private function is_noise( array $report ): bool {
		foreach ( array( 'blocked-uri', 'source-file' ) as $field ) {
			$value  = strtolower( trim( (string) ( $report[ $field ] ?? '' ) ) );
			$colon  = strpos( $value, ':' );
			$scheme = false === $colon ? $value : substr( $value, 0, $colon );
			if ( '' !== $scheme && in_array( $scheme, self::NOISE_SCHEMES, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string Validated REMOTE_ADDR, or '' if missing/invalid.
	 */
	private function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Sliding-window rate limit keyed by a salted hash of the client IP
	 * (never the raw IP), so an attacker can't grow the transients table
	 * or discover raw IP storage.
	 */
	private function check_report_rate_limit( string $ip ): bool {
		if ( '' === $ip ) {
			// Can't rate-limit an unknown client; allow but the caller still
			// enforces per-report size/shape limits.
			return true;
		}
		$key   = 'uxstudio_csp_rl_' . md5( $ip . wp_salt() );
		$count = (int) get_transient( $key );
		if ( $count >= self::REPORT_RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::REPORT_RATE_WINDOW );
		return true;
	}

	/**
	 * Count a report towards the site-wide hourly cap. Over the cap the
	 * Report-Only header is paused until the next full hour (CspPolicy reads
	 * the autoloaded state, so pausing costs page views nothing) and further
	 * reports are dropped. Enforce keeps sending its header regardless.
	 */
	private function count_towards_hourly_cap(): bool {
		$key   = 'uxstudio_csp_hour_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 2 * HOUR_IN_SECONDS );
		if ( $count <= self::HOURLY_CAP ) {
			return true;
		}
		if ( self::HOURLY_CAP + 1 === $count ) {
			CspPolicy::update_state( array( 'paused_until' => ( intdiv( time(), HOUR_IN_SECONDS ) + 1 ) * HOUR_IN_SECONDS ) );
		}
		return false;
	}
}
