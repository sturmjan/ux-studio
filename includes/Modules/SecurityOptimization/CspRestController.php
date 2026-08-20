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
 * GET    uxstudio/v1/security-optimization/csp-violations           - list violations (filter by status)
 * POST   uxstudio/v1/security-optimization/csp-violations/{id}/resolve - mark a violation resolved
 * DELETE uxstudio/v1/security-optimization/csp-violations/{id}      - delete a violation
 *
 * The report endpoint must stay public with permission_callback '__return_true'
 * because browsers send Content-Security-Policy-Report-Only violations
 * without any WordPress auth context. To keep it from being used as a DoS
 * vector against the DB it is rate-limited per client IP (hashed, never
 * stored raw) via a transient - independent from Controller::route()'s
 * rate limiter, which only applies to authenticated write routes.
 */
final class CspRestController extends Controller {

	private const REPORT_RATE_LIMIT  = 60;
	private const REPORT_RATE_WINDOW = 60;

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

		$this->route( '/security-optimization/csp-violations', 'GET', array( $this, 'list_violations' ) );
		$this->route(
			'/security-optimization/csp-violations/(?P<id>\d+)/resolve',
			'POST',
			array( $this, 'resolve_violation' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
		$this->route(
			'/security-optimization/csp-violations/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_violation' ),
			array(
				'id' => array(
					'required' => true,
					'type'     => 'integer',
				),
			)
		);
	}

	/**
	 * Public CSP report sink. Accepts both the legacy `application/csp-report`
	 * shape ({"csp-report": {...}}) and the modern Reporting API
	 * (`application/reports+json`, array of {"type":"csp-violation","body":{...}}).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function receive_report( WP_REST_Request $request ): WP_REST_Response {
		$ip = $this->client_ip();

		if ( ! $this->check_report_rate_limit( $ip ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$raw = $request->get_body();
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_REST_Response( null, 204 );
		}

		$max = CspLogger::max_body_bytes();
		if ( strlen( $raw ) > $max ) {
			$raw = substr( $raw, 0, $max );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$reports = $this->normalize_reports( $data );
		if ( empty( $reports ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		foreach ( $reports as $report ) {
			CspLogger::record( $report, $ip, $ua );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function list_violations( WP_REST_Request $request ) {
		$args = array(
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'orderby'  => sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'last_seen' ) ),
			'order'    => sanitize_key( (string) ( $request->get_param( 'order' ) ?: 'desc' ) ),
			'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 25 ),
			'paged'    => (int) ( $request->get_param( 'paged' ) ?: 1 ),
		);

		$items = CspLogger::get_violations( $args );
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
	 * @param array $data Decoded JSON body.
	 * @return array<int,array<string,string>>
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
					$out[] = $this->map_reporting_api( $entry['body'] );
				} elseif ( isset( $entry['csp-report'] ) && is_array( $entry['csp-report'] ) ) {
					$out[] = $entry['csp-report'];
				}
			}
		}
		return $out;
	}

	/**
	 * @param array $body Reporting API report body.
	 * @return array<string,string>
	 */
	private function map_reporting_api( array $body ): array {
		return array(
			'document-uri'        => (string) ( $body['documentURL'] ?? '' ),
			'blocked-uri'         => (string) ( $body['blockedURL'] ?? '' ),
			'effective-directive' => (string) ( $body['effectiveDirective'] ?? ( $body['violatedDirective'] ?? '' ) ),
			'violated-directive'  => (string) ( $body['violatedDirective'] ?? '' ),
			'source-file'         => (string) ( $body['sourceFile'] ?? '' ),
		);
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
}
