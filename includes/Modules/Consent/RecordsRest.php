<?php
/**
 * REST routes of the consent records: the public write endpoint and the admin read endpoints.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BotThrottle\Guard;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST uxstudio/v1/consent/record            public: the banner reports a decision (see handle())
 * GET  uxstudio/v1/consent/records/summary   manage_options: counts per action and category
 * GET  uxstudio/v1/consent/records/find      manage_options: every record of one consent id
 * GET  uxstudio/v1/consent/records/export-url manage_options: nonce-signed CSV link (admin-post)
 *
 * The public route declares `permission_callback => __return_true`, which is how the REST lock of Security Optimization
 * (SecurityOptimization\Module::is_own_public_endpoint) recognises a deliberately public route of this plugin, the same
 * way it lets `consent/region` through.
 */
final class RecordsRest extends Controller {

	/** Largest request body we look at, in bytes (a real one is about 120). */
	public const MAX_BODY = 1024;

	/** Per consent id: at most this many records... */
	private const ID_LIMIT = 8;
	/** ...within this many seconds. */
	private const ID_WINDOW = 600;
	/** Whole site: records per minute (filter `uxstudio_consent_record_cap`). */
	private const SITE_CAP = 120;

	/** Hook callback. */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/** Register the routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/consent/record',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // Public by design: an anonymous visitor reports their own choice. Guarded in handle().
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static fn( $v ): bool => is_string( $v ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $v ),
					),
					'v'  => array(
						'required'          => true,
						'validate_callback' => static fn( $v ): bool => null !== Records::clean( array( 'id' => str_repeat( 'a', 32 ), 'v' => $v, 'c' => 0, 'a' => 'reject_all' ) ),
					),
					'c'  => array(
						'required'          => true,
						'validate_callback' => static fn( $v ): bool => null !== Records::clean( array( 'id' => str_repeat( 'a', 32 ), 'v' => 1, 'c' => $v, 'a' => 'custom' ) ),
					),
					'a'  => array(
						'required'          => true,
						'validate_callback' => static fn( $v ): bool => is_string( $v ) && in_array( $v, Records::ACTIONS, true ),
					),
					'p'  => array(
						'required'          => false,
						'validate_callback' => static fn( $v ): bool => is_string( $v ) && strlen( $v ) <= 2000,
					),
				),
			)
		);

		$this->route( '/consent/records/summary', 'GET', array( $this, 'summary' ) );
		$this->route( '/consent/records/find', 'GET', array( $this, 'find' ) );
		$this->route( '/consent/records/export-url', 'GET', array( $this, 'export_url' ) );
	}

	/* ---------- public ---------- */

	/**
	 * Store one decision. Layers, cheapest first: the module switch, the body size, the shared IP burst guard of Bot
	 * Throttle (it keeps only a salted hash of the IP, in APCu, a bucket row or a transient, for minutes), a limit per
	 * consent id and a cap for the whole site per minute. The IP is never stored with a record.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		if ( ! Records::enabled() ) {
			return self::empty_response();
		}
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'uxstudio_consent_too_large', __( 'The request is too large.', 'ux-studio' ), array( 'status' => 413 ) );
		}
		if ( class_exists( Guard::class ) && Guard::exceeded( 'consent_record', 30, 60, 10 * MINUTE_IN_SECONDS ) ) {
			return self::limited();
		}
		$row = Records::clean(
			array(
				'id' => $request->get_param( 'id' ),
				'v'  => $request->get_param( 'v' ),
				'c'  => $request->get_param( 'c' ),
				'a'  => $request->get_param( 'a' ),
				'p'  => $request->get_param( 'p' ),
			)
		);
		if ( null === $row ) {
			return new WP_Error( 'uxstudio_consent_invalid', __( 'Invalid consent record.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		if ( self::over_limits( $row['consent_id'] ) ) {
			return self::limited();
		}
		Records::insert( $row );
		return self::empty_response();
	}

	/** 204: nothing to say to the browser. */
	private static function empty_response(): WP_REST_Response {
		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );
		return $response;
	}

	/** 429. */
	private static function limited(): WP_Error {
		return new WP_Error( 'uxstudio_consent_rate_limited', __( 'Too many requests, please try again later.', 'ux-studio' ), array( 'status' => 429 ) );
	}

	/**
	 * Limit per consent id and for the whole site. Counters live in short transients (the id is anonymous and random).
	 *
	 * @param string $consent_id Consent id.
	 */
	private static function over_limits( string $consent_id ): bool {
		$cap = max( 10, (int) apply_filters( 'uxstudio_consent_record_cap', self::SITE_CAP ) );

		$minute = (int) floor( time() / 60 );
		$site   = get_transient( 'uxstudio_cr_site' );
		$site   = is_array( $site ) && ( $site['m'] ?? 0 ) === $minute ? $site : array( 'm' => $minute, 'n' => 0 );
		if ( $site['n'] >= $cap ) {
			return true;
		}
		++$site['n'];
		set_transient( 'uxstudio_cr_site', $site, 120 );

		$key  = 'uxstudio_cr_' . $consent_id;
		$seen = (int) get_transient( $key );
		if ( $seen >= self::ID_LIMIT ) {
			return true;
		}
		set_transient( $key, $seen + 1, self::ID_WINDOW );
		return false;
	}

	/* ---------- admin ---------- */

	/**
	 * Counts for the overview.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function summary( WP_REST_Request $request ): WP_REST_Response {
		$days = (int) $request->get_param( 'days' );
		return $this->ok( Records::summary( $days > 0 ? $days : 30 ) );
	}

	/**
	 * Records of one consent id (the visitor can read it out from the banner).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function find( WP_REST_Request $request ) {
		$raw = (string) $request->get_param( 'id' );
		$id  = Records::normalize_id( $raw );
		if ( '' === $id ) {
			return new WP_Error( 'uxstudio_consent_bad_id', __( 'A consent ID has 32 letters and digits (0-9, a-f).', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( Records::find( $id ) );
	}

	/**
	 * Nonce-signed link to the CSV export.
	 *
	 * @param WP_REST_Request $request Request (from, to: Y-m-d, optional; id: optional).
	 * @return WP_REST_Response|WP_Error
	 */
	public function export_url( WP_REST_Request $request ) {
		$args = array( 'action' => 'uxstudio_consent_export' );
		foreach ( array( 'from', 'to' ) as $key ) {
			$value = (string) $request->get_param( $key );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				$args[ $key ] = $value;
			}
		}
		$raw_id = (string) $request->get_param( 'id' );
		if ( '' !== trim( $raw_id ) ) {
			$id = Records::normalize_id( $raw_id );
			if ( '' === $id ) {
				return new WP_Error( 'uxstudio_consent_bad_id', __( 'A consent ID has 32 letters and digits (0-9, a-f).', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$args['id'] = $id;
		}
		return $this->ok( array( 'url' => wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'uxstudio_consent_export' ) ) );
	}

	/**
	 * Admin-post handler: stream the CSV.
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'uxstudio_consent_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked above by check_admin_referer().
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['to'] ) ) : '';
		$id   = isset( $_GET['id'] ) ? Records::normalize_id( sanitize_text_field( wp_unslash( (string) $_GET['id'] ) ) ) : '';
		// phpcs:enable

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consent-records-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.
		$rows = Records::stream_csv( $out, $from, $to, $id );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		ActivityLog::log( 'consent', 'export', 'consent_log', 0, array( 'rows' => $rows ) );
		exit;
	}
}
