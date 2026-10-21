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

		// F4: safe preview, tests, switch.
		$run_arg = array(
			'run_id' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => static fn( $v ): string => Snapshot::clean_id( (string) $v ),
			),
		);
		$flag    = static fn(): array => array(
			'required'          => false,
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		);
		$this->route( '/migration/preview', 'GET', array( $this, 'preview_status' ), $run_arg );
		$this->route( '/migration/preview', 'POST', array( $this, 'preview_start' ), $run_arg );
		$this->route( '/migration/preview/end', 'POST', array( $this, 'preview_end' ), $run_arg );
		$this->route_readonly(
			'/migration/smoke',
			array( $this, 'smoke' ),
			$run_arg + array(
				'mode'  => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( Smoke::MODE_PREVIEW, Smoke::MODE_LIVE ),
				),
				'check' => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static fn( $v ): string => (string) preg_replace( '/[^a-zA-Z0-9_:\-]/', '', (string) $v ),
				),
			)
		);
		$this->route( '/migration/switch-plan', 'GET', array( $this, 'switch_plan' ), $run_arg );
		$this->route(
			'/migration/switch',
			'POST',
			array( $this, 'switch_on' ),
			$run_arg + array(
				'ack_login'         => $flag(),
				'override_failures' => $flag(),
				'no_preview'        => $flag(),
			)
		);
		$this->route( '/migration/revert-switch', 'POST', array( $this, 'revert_switch' ), $run_arg );
		$this->route( '/migration/probe', 'GET', array( $this, 'probe' ) );
	}

	/**
	 * Entry of a preview as the wizard needs it.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>
	 */
	private function preview_payload( string $run_id ): array {
		Preview::purge_expired();
		$entry = Preview::entry( $run_id );
		$out   = array(
			'available' => Preview::availability(),
			'entry'     => null,
		);
		if ( $entry && (int) $entry['expires'] > time() ) {
			$out['entry'] = array(
				'run_id'     => $entry['run_id'],
				'expires'    => (int) $entry['expires'],
				'expires_at' => Runner::mysql_time( (int) $entry['expires'] ),
				'plugins'    => $entry['plugins'],
				'modules'    => array_map(
					static fn( string $id ): array => array(
						'id'   => $id,
						'name' => ActivationGuard::module_name( $id ),
					),
					$entry['modules']
				),
				'results'    => $entry['results'],
				'enter_url'  => Preview::enter_url( $run_id ),
				'exit_url'   => Preview::exit_url( $run_id ),
			);
			$out['plan']  = array_map(
				static fn( array $c ): array => array_intersect_key( $c, array_flip( array( 'id', 'label', 'group' ) ) ),
				Smoke::plan( $run_id )
			);
		}
		return $out;
	}

	/**
	 * GET preview.
	 */
	public function preview_status( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->preview_payload( (string) $request['run_id'] ) );
	}

	/**
	 * POST preview: start.
	 */
	public function preview_start( WP_REST_Request $request ) {
		$result = Preview::start( (string) $request['run_id'], (int) get_current_user_id() );
		return is_wp_error( $result ) ? $result : $this->ok( $this->preview_payload( (string) $request['run_id'] ) );
	}

	/**
	 * POST preview/end.
	 */
	public function preview_end( WP_REST_Request $request ): WP_REST_Response {
		Preview::stop( (string) $request['run_id'] );
		Preview::clear_cookie();
		return $this->ok( $this->preview_payload( (string) $request['run_id'] ) );
	}

	/**
	 * POST smoke: without `check` the list of checks, with it the result of that one.
	 */
	public function smoke( WP_REST_Request $request ) {
		$run_id = (string) $request['run_id'];
		if ( '' === (string) $request['check'] ) {
			return $this->ok(
				array(
					'plan' => array_map(
						static fn( array $c ): array => array_intersect_key( $c, array_flip( array( 'id', 'label', 'group' ) ) ),
						Smoke::plan( $run_id )
					),
				)
			);
		}
		$result = Smoke::run( $run_id, (string) $request['check'], (string) $request['mode'] );
		return is_wp_error( $result ) ? $result : $this->ok( array( 'result' => $result ) );
	}

	/**
	 * GET switch-plan.
	 */
	public function switch_plan( WP_REST_Request $request ) {
		$plan = Switcher::plan( (string) $request['run_id'] );
		return is_wp_error( $plan ) ? $plan : $this->ok( $plan );
	}

	/**
	 * POST switch.
	 */
	public function switch_on( WP_REST_Request $request ) {
		$result = Switcher::switch_on(
			(string) $request['run_id'],
			array(
				'ack_login'         => (bool) $request['ack_login'],
				'override_failures' => (bool) $request['override_failures'],
				'no_preview'        => (bool) $request['no_preview'],
			),
			(int) get_current_user_id()
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// The preview is over: from now on everybody gets the new setup.
		Preview::stop( (string) $request['run_id'] );
		Preview::clear_cookie();
		PluginInfo::flush();
		return $this->ok( $result + array( 'runs' => Runner::history() ) );
	}

	/**
	 * POST revert-switch.
	 */
	public function revert_switch( WP_REST_Request $request ) {
		$result = Switcher::revert( (string) $request['run_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		PluginInfo::flush();
		return $this->ok( $result + array( 'runs' => Runner::history() ) );
	}

	/**
	 * GET probe: facts the smoke tests cannot see from the outside (which editor each post type gets).
	 * Answered by whatever setup this very request runs with, so in a preview request it shows the preview.
	 */
	public function probe( WP_REST_Request $request ): WP_REST_Response {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$map = array();
		foreach ( array_keys( get_post_types( array( 'public' => true, 'show_ui' => true ) ) ) as $type ) {
			$map[ (string) $type ] = (bool) use_block_editor_for_post_type( (string) $type );
		}
		return $this->ok(
			array(
				'preview'        => Preview::is_active(),
				'block_editor'   => $map,
				'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
				'modules'        => array_values( (array) get_option( 'uxstudio_active_modules', array() ) ),
			)
		);
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
