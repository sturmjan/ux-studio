<?php
/**
 * REST routes of the migration wizard (uxstudio/v1/migration/*).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Rest\Controller;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * All routes need `manage_options` (base controller). Writes spend the shared per-user
 * write budget; scan / plan / verify / status / runs do not write anything.
 *
 *   GET  migration/scan      installed or leftover sources
 *   POST migration/plan      plan of the selected sources (nothing written)
 *   POST migration/run       start (sources[], dry_run) or continue (continue=true) a run
 *   GET  migration/status    state of the current / last run (a reload resumes from it) + what is staged for it
 *   POST migration/verify    compare source and UX Studio
 *   POST migration/rollback  restore the snapshot of a run (run_id)
 *   GET  migration/runs      history
 */
final class MigrationRestController extends Controller {

	public function register_routes(): void {
		$sources = array(
			'sources' => array(
				'required'          => false,
				'type'              => 'array',
				'items'             => array( 'type' => 'string' ),
				'default'           => array(),
				'sanitize_callback' => static fn( $v ): array => array_values( array_filter( array_map( 'sanitize_key', (array) $v ) ) ),
			),
		);

		$this->route( '/migration/scan', 'GET', array( $this, 'scan' ) );
		$this->route_readonly( '/migration/plan', array( $this, 'plan' ), $sources );
		$this->route(
			'/migration/run',
			'POST',
			array( $this, 'run' ),
			$sources + array(
				'dry_run'  => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => true,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'continue' => array(
					'required'          => false,
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
			)
		);
		$this->route( '/migration/status', 'GET', array( $this, 'status' ) );
		$this->route_readonly( '/migration/verify', array( $this, 'verify' ), $sources );
		$this->route(
			'/migration/rollback',
			'POST',
			array( $this, 'rollback' ),
			array(
				'run_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => static fn( $v ): string => Snapshot::clean_id( (string) $v ),
				),
			)
		);
		$this->route( '/migration/runs', 'GET', array( $this, 'runs' ) );
	}

	/**
	 * GET scan.
	 */
	public function scan( WP_REST_Request $request ): WP_REST_Response {
		MigrationMap::ensure_table();
		return $this->ok( array( 'sources' => SourceRegistry::scan() ) );
	}

	/**
	 * POST plan.
	 */
	public function plan( WP_REST_Request $request ) {
		PluginInfo::flush();
		$plans = array();
		foreach ( (array) $request['sources'] as $id ) {
			$source = SourceRegistry::get( (string) $id );
			if ( ! $source ) {
				return new \WP_Error( 'uxstudio_migration_unknown', __( 'Unknown migration source.', 'ux-studio' ), array( 'status' => 400 ) );
			}
			$plans[] = $source->plan() + array( 'status' => $source->detect()['status'] );
		}
		return $this->ok( array( 'plans' => $plans ) );
	}

	/**
	 * POST run: `continue` processes the next batch of the current run, otherwise a new run
	 * is started and its first batch processed.
	 */
	public function run( WP_REST_Request $request ) {
		MigrationMap::ensure_table();
		PluginInfo::flush();
		if ( (bool) $request['continue'] ) {
			$state = Runner::step();
		} else {
			$state = Runner::start( (array) $request['sources'], (bool) $request['dry_run'], (array) $request['options'] );
			if ( ! is_wp_error( $state ) ) {
				$state = Runner::step();
			}
		}
		return is_wp_error( $state ) ? $state : $this->ok( array( 'run' => $state ) );
	}

	/**
	 * GET status.
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		$run = Runner::state();
		return $this->ok(
			array(
				'run'     => $run,
				'staging' => $run ? Staging::summary( (string) $run['id'] ) : null,
			)
		);
	}

	/**
	 * `options` = { source id: { option key: bool } } - anything else is dropped (the Runner
	 * keeps only the keys each source declares).
	 *
	 * @param mixed $value Raw request value.
	 * @return array<string, array<string, bool>>
	 */
	public static function sanitize_options( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $source => $options ) {
			if ( ! is_array( $options ) ) {
				continue;
			}
			foreach ( $options as $key => $flag ) {
				$out[ sanitize_key( (string) $source ) ][ sanitize_key( (string) $key ) ] = rest_sanitize_boolean( $flag );
			}
		}
		return $out;
	}

	/**
	 * POST verify.
	 */
	public function verify( WP_REST_Request $request ) {
		$result = Runner::verify( (array) $request['sources'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'verify' => $result ) );
	}

	/**
	 * POST rollback.
	 */
	public function rollback( WP_REST_Request $request ) {
		$result = Runner::rollback( (string) $request['run_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		PluginInfo::flush();
		return $this->ok(
			array(
				'result' => $result,
				'runs'   => Runner::history(),
			)
		);
	}

	/**
	 * GET runs.
	 */
	public function runs( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( array( 'runs' => Runner::history() ) );
	}
}
