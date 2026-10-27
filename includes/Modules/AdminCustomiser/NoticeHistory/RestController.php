<?php
/**
 * REST API of the admin notice history: capture from the bell panel and the history page.
 *
 * Every route needs `manage_options` and the REST nonce (cookie authentication). The two capture routes
 * answer "recorded 0" while the hide-notices feature is off, nothing is stored then.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AdminCustomiser\NoticeHistory;

use UxStudio\Core\Settings;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class RestController extends Controller {

	/** Largest body of a capture request in bytes. */
	private const MAX_BODY = 200000;

	/** Capture requests per user and minute (one is sent per page load). */
	private const CAPTURE_PER_MINUTE = 40;

	public function register_routes(): void {
		$filters = array(
			'state'    => array(
				'type'              => 'string',
				'enum'              => array_merge( array( '' ), Rules::STATES ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'type'     => array(
				'type'              => 'string',
				'enum'              => array_merge( array( '' ), Rules::TYPES ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'source'   => array(
				'type'              => 'string',
				'maxLength'         => 100,
				'pattern'           => '^[a-z0-9_\-.]*$',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'period'   => array(
				'type'              => 'string',
				'enum'              => array_keys( Rules::PERIODS ),
				'validate_callback' => 'rest_validate_request_arg',
			),
			'q'        => array(
				'type'              => 'string',
				'maxLength'         => 100,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);

		$this->route(
			'/admin-notices',
			'GET',
			array( $this, 'list_items' ),
			$filters + array(
				'page'     => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 100000,
					'validate_callback' => 'rest_validate_request_arg',
				),
				'per_page' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => Rules::PER_PAGE_MAX,
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);
		$this->route( '/admin-notices', 'DELETE', array( $this, 'delete_all' ) );
		$this->route( '/admin-notices/stats', 'GET', array( $this, 'stats' ) );
		$this->route( '/admin-notices/export.csv', 'GET', array( $this, 'export_csv' ), $filters );

		$id = array(
			'id' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
		$this->route( '/admin-notices/(?P<id>\d+)', 'DELETE', array( $this, 'delete_one' ), $id );
		$this->route( '/admin-notices/(?P<id>\d+)/reshow', 'POST', array( $this, 'reshow' ), $id );
		$this->route(
			'/admin-notices/bulk-delete',
			'POST',
			array( $this, 'bulk_delete' ),
			array(
				'ids' => array(
					'required'          => true,
					'type'              => 'array',
					'maxItems'          => 200,
					'items'             => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);

		// Capture from the panel. Not counted against the shared write budget: one request per page load.
		$item = array(
			'type'       => 'object',
			'properties' => array(
				'ckey'        => array( 'type' => 'string', 'maxLength' => 400 ),
				'type'        => array( 'type' => 'string', 'maxLength' => 20 ),
				'title'       => array( 'type' => 'string', 'maxLength' => 2000 ),
				'text'        => array( 'type' => 'string', 'maxLength' => 20000 ),
				'markup'      => array( 'type' => 'string', 'maxLength' => 4000 ),
				'id'          => array( 'type' => 'string', 'maxLength' => 200 ),
				'plugin'      => array( 'type' => 'string', 'maxLength' => 300 ),
				'screen'      => array( 'type' => 'string', 'maxLength' => 200 ),
				'dismissible' => array( 'type' => 'boolean' ),
			),
		);
		$this->route(
			'/admin-notices/log',
			'POST',
			array( $this, 'log' ),
			array(
				'items' => array(
					'required'          => true,
					'type'              => 'array',
					'maxItems'          => Rules::BATCH_MAX,
					'items'             => $item,
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			'manage_options',
			false
		);
		$this->route(
			'/admin-notices/dismiss',
			'POST',
			array( $this, 'dismiss' ),
			array(
				'item'   => array(
					'required'          => true,
					'type'              => 'object',
					'properties'        => $item['properties'],
					'validate_callback' => 'rest_validate_request_arg',
				),
				'method' => array(
					'type'              => 'string',
					'enum'              => Rules::METHODS,
					'validate_callback' => 'rest_validate_request_arg',
				),
			),
			'manage_options',
			false
		);
	}

	/** Is the hide-notices feature on and showing the panel (the capture point)? */
	public static function capturing(): bool {
		$settings = new Settings( 'uxstudio_admin_customiser' );
		return (bool) $settings->get( 'hide_notices_enabled', false ) && (bool) $settings->get( 'hide_notices_relocate', false );
	}

	/** Capture requests are limited per user and minute. */
	private function capture_allowed(): bool {
		$key   = 'uxstudio_rl_notices_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::CAPTURE_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Common guard of the two capture routes. Null = go on, otherwise the response to return.
	 *
	 * @return WP_REST_Response|WP_Error|null
	 */
	private function capture_guard( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error( 'uxstudio_payload_too_large', __( 'The request is too large.', 'ux-studio' ), array( 'status' => 413 ) );
		}
		if ( ! $this->capture_allowed() ) {
			return new WP_Error( 'uxstudio_rate_limited', __( 'Too many requests, slow down.', 'ux-studio' ), array( 'status' => 429 ) );
		}
		if ( ! self::capturing() ) {
			return $this->ok(
				array(
					'recorded' => 0,
					'enabled'  => false,
				)
			);
		}
		return null;
	}

	public function log( WP_REST_Request $request ) {
		$guard = $this->capture_guard( $request );
		if ( null !== $guard ) {
			return $guard;
		}
		$items = array();
		foreach ( (array) $request->get_param( 'items' ) as $raw ) {
			$clean = Rules::sanitize_item( $raw );
			if ( null !== $clean ) {
				$items[] = $clean;
			}
		}
		return $this->ok(
			array(
				'recorded' => Store::log( $items, get_current_user_id() ),
				'enabled'  => true,
			)
		);
	}

	public function dismiss( WP_REST_Request $request ) {
		$guard = $this->capture_guard( $request );
		if ( null !== $guard ) {
			return $guard;
		}
		$item = Rules::sanitize_item( $request->get_param( 'item' ) );
		if ( null === $item ) {
			return $this->ok(
				array(
					'recorded' => 0,
					'enabled'  => true,
				)
			);
		}
		$method = (string) ( $request->get_param( 'method' ) ?? 'panel' );
		return $this->ok(
			array(
				'recorded' => Store::dismiss( $item, $method, get_current_user_id() ) > 0 ? 1 : 0,
				'enabled'  => true,
			)
		);
	}

	/**
	 * Filter parameters of a request as one array for Rules::sanitize_filters().
	 *
	 * @return array<string, mixed>
	 */
	private function filters( WP_REST_Request $request ): array {
		$out = array();
		foreach ( array( 'state', 'type', 'source', 'period', 'q', 'page', 'per_page' ) as $name ) {
			$value = $request->get_param( $name );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	public function list_items( WP_REST_Request $request ) {
		$result = Store::query( $this->filters( $request ) );
		$f      = $result['filters'];
		return $this->ok(
			$result['items'],
			array(
				'total'    => $result['total'],
				'page'     => $f['page'],
				'per_page' => $f['per_page'],
				'pages'    => max( 1, (int) ceil( $result['total'] / $f['per_page'] ) ),
			)
		);
	}

	public function stats( WP_REST_Request $request ) {
		unset( $request );
		$settings = new Settings( 'uxstudio_admin_customiser' );
		return $this->ok(
			Store::stats() + array(
				'enabled'  => (bool) $settings->get( 'hide_notices_enabled', false ),
				'relocate' => (bool) $settings->get( 'hide_notices_relocate', false ),
				'capture'  => self::capturing(),
				'gone_days' => max( 1, (int) apply_filters( 'uxstudio_admin_notices_gone_days', Rules::GONE_DAYS ) ),
				'retention_days' => max( 1, (int) apply_filters( 'uxstudio_admin_notices_retention_days', Rules::RETENTION_DAYS ) ),
			)
		);
	}

	public function delete_one( WP_REST_Request $request ) {
		$ok = Store::delete( (int) $request->get_param( 'id' ) );
		if ( ! $ok ) {
			return new WP_Error( 'uxstudio_not_found', __( 'The record does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => 1 ) );
	}

	public function bulk_delete( WP_REST_Request $request ) {
		return $this->ok( array( 'deleted' => Store::delete_many( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) ) );
	}

	public function delete_all( WP_REST_Request $request ) {
		unset( $request );
		return $this->ok( array( 'deleted' => Store::delete_all() ) );
	}

	public function reshow( WP_REST_Request $request ) {
		$result = Store::reshow( (int) $request->get_param( 'id' ) );
		if ( 'not_found' === $result ) {
			return new WP_Error( 'uxstudio_not_found', __( 'The record does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( 'not_restorable' === $result ) {
			return new WP_Error( 'uxstudio_not_restorable', __( 'The plugin closed this notice, it cannot be shown again from here.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		return $this->ok( array( 'reshown' => 1 ) );
	}

	/** CSV download of the filtered history. Nothing else is printed, the request ends here. */
	public function export_csv( WP_REST_Request $request ) {
		$rows = Store::export_rows( $this->filters( $request ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="admin-notices-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv(
			$out,
			array(
				__( 'ID', 'ux-studio' ),
				__( 'Type', 'ux-studio' ),
				__( 'Title', 'ux-studio' ),
				__( 'Text', 'ux-studio' ),
				__( 'Source (estimate)', 'ux-studio' ),
				__( 'Source slug', 'ux-studio' ),
				__( 'Screen', 'ux-studio' ),
				__( 'State', 'ux-studio' ),
				__( 'First seen', 'ux-studio' ),
				__( 'Last seen', 'ux-studio' ),
				__( 'Times shown', 'ux-studio' ),
				__( 'Closed at', 'ux-studio' ),
				__( 'Closed by', 'ux-studio' ),
				__( 'Closed through', 'ux-studio' ),
			),
			';',
			'"',
			'\\'
		);
		foreach ( $rows as $row ) {
			$row['dismissed_by'] = (string) ( $row['dismissed_by_name'] ?? '' );
			fputcsv( $out, Rules::csv_row( $row ), ';', '"', '\\' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
