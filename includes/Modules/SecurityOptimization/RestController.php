<?php
/**
 * Security - Access Control REST controller (login attempts, IP bans, .htaccess).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/security-optimization/login-attempts       - paginated blocked/attempt rows
 * DELETE uxstudio/v1/security-optimization/login-attempts/{id}  - unlock one row
 * POST   uxstudio/v1/security-optimization/login-attempts/clear - clear all attempts/locks
 * GET    uxstudio/v1/security-optimization/ip-bans              - paginated ban list
 * POST   uxstudio/v1/security-optimization/ip-bans              - create/update a local ban
 * DELETE uxstudio/v1/security-optimization/ip-bans/{id}         - delete a local ban
 * POST   uxstudio/v1/security-optimization/ip-bans/{id}/toggle  - enable/disable a local ban
 * GET    uxstudio/v1/security-optimization/countries            - country code => name list
 * GET    uxstudio/v1/security-optimization/htaccess/status      - applied?/hash
 * POST   uxstudio/v1/security-optimization/htaccess/apply       - regenerate + write .htaccess rules
 *
 * CSP violations and Upload Guard endpoints belong to a different concurrent
 * module port and are NOT registered here.
 */
final class RestController extends Controller {

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	public function register_routes(): void {
		$this->route(
			'/security-optimization/login-attempts',
			'GET',
			array( $this, 'list_login_attempts' ),
			array(
				'status'   => array( 'required' => false ),
				'search'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'page'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			)
		);
		$this->route(
			'/security-optimization/login-attempts/(?P<id>\d+)',
			'DELETE',
			array( $this, 'unlock_login_attempt' ),
			array( 'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ) )
		);
		$this->route( '/security-optimization/login-attempts/clear', 'POST', array( $this, 'clear_login_attempts' ) );

		$this->route(
			'/security-optimization/ip-bans',
			'GET',
			array( $this, 'list_ip_bans' ),
			array(
				'type'     => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'search'   => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'page'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			)
		);
		$this->route( '/security-optimization/ip-bans', 'POST', array( $this, 'create_ip_ban' ) );
		$this->route(
			'/security-optimization/ip-bans/(?P<id>\d+)',
			'DELETE',
			array( $this, 'delete_ip_ban' ),
			array( 'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ) )
		);
		$this->route(
			'/security-optimization/ip-bans/(?P<id>\d+)/toggle',
			'POST',
			array( $this, 'toggle_ip_ban' ),
			array( 'id' => array( 'required' => true, 'sanitize_callback' => 'absint' ) )
		);

		$this->route( '/security-optimization/countries', 'GET', array( $this, 'countries' ) );

		$this->route( '/security-optimization/htaccess/status', 'GET', array( $this, 'htaccess_status' ) );
		$this->route( '/security-optimization/htaccess/apply', 'POST', array( $this, 'htaccess_apply' ) );
	}

	/* ═══════════════════════════════════════════════════
	   Login attempts
	   ═══════════════════════════════════════════════════ */

	public function list_login_attempts( WP_REST_Request $request ) {
		$result = $this->module->attempts_handler()->get_blocked_accounts(
			array(
				'status'   => $request->get_param( 'status' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 10 ),
			)
		);
		return $this->ok(
			$result['items'],
			array(
				'total'       => $result['total'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'total_pages' => $result['total_pages'],
			)
		);
	}

	public function unlock_login_attempt( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->module->attempts_handler()->unblock_account( $id ) ) {
			return new WP_Error( 'uxstudio_unlock_failed', __( 'Could not unlock this account.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		\UxStudio\Core\ActivityLog::log( 'security-optimization', 'login_attempt_unlocked', 'login_failed', $id );
		return $this->ok( array( 'id' => $id ) );
	}

	public function clear_login_attempts( WP_REST_Request $request ) {
		$this->module->attempts_handler()->clear_all_attempts();
		\UxStudio\Core\ActivityLog::log( 'security-optimization', 'login_attempts_cleared' );
		return $this->ok( array( 'cleared' => true ) );
	}

	/* ═══════════════════════════════════════════════════
	   IP bans
	   ═══════════════════════════════════════════════════ */

	public function list_ip_bans( WP_REST_Request $request ) {
		$result = $this->module->ip_ban_manager()->list_bans(
			array(
				'type'     => (string) $request->get_param( 'type' ),
				'search'   => (string) $request->get_param( 'search' ),
				'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
				'per_page' => (int) ( $request->get_param( 'per_page' ) ?: 20 ),
			)
		);
		return $this->ok(
			$result['items'],
			array(
				'total'       => $result['total'],
				'page'        => $result['page'],
				'per_page'    => $result['per_page'],
				'total_pages' => $result['total_pages'],
			)
		);
	}

	public function create_ip_ban( WP_REST_Request $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$result = $this->module->ip_ban_manager()->save_ban( $data );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return $this->ok( array( 'id' => $result ) );
	}

	public function delete_ip_ban( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->module->ip_ban_manager()->delete_ban( $id ) ) {
			return new WP_Error( 'uxstudio_ban_not_found', __( 'Ban not found or cannot be deleted.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'id' => $id ) );
	}

	public function toggle_ip_ban( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$result = $this->module->ip_ban_manager()->toggle_ban( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $this->ok( $result );
	}

	/* ═══════════════════════════════════════════════════
	   Countries / .htaccess
	   ═══════════════════════════════════════════════════ */

	public function countries( WP_REST_Request $request ) {
		return $this->ok( CountryBlocklist::get_countries() );
	}

	public function htaccess_status( WP_REST_Request $request ) {
		$writer   = $this->module->htaccess_writer();
		$settings = $this->module->settings_values();
		return $this->ok(
			array(
				'applied'      => $writer->is_applied( $settings ),
				'stored_hash'  => $writer->stored_hash(),
				'current_hash' => $writer->compute_hash( $settings ),
			)
		);
	}

	public function htaccess_apply( WP_REST_Request $request ) {
		$writer   = $this->module->htaccess_writer();
		$settings = $this->module->settings_values();
		$result   = $writer->apply( $settings );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		\UxStudio\Core\ActivityLog::log( 'security-optimization', 'htaccess_applied' );

		return $this->ok(
			array(
				'applied' => true,
				'hash'    => $writer->stored_hash(),
			)
		);
	}
}
