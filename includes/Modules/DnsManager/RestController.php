<?php
/**
 * DNS Management REST controller (admin SPA).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\DnsManager;

use UxStudio\Core\ClientIp;
use UxStudio\Modules\BotThrottle\Guard;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET    uxstudio/v1/dns/status               - module state + current user's DNS rights
 * GET    uxstudio/v1/dns/records              - records of the zone (?fresh=1 skips the 60 s cache)
 * POST   uxstudio/v1/dns/records              - create {record}
 * POST   uxstudio/v1/dns/records/{id}         - update {record, modified_on}
 * DELETE uxstudio/v1/dns/records/{id}         - delete {modified_on}
 * GET    uxstudio/v1/dns/log                  - change history (from the central app)
 * POST   uxstudio/v1/dns/revert               - revert a logged change {log_id}
 * POST   uxstudio/v1/dns/ack                  - user acknowledged the risks (first entry)
 *
 * Everything is a thin pass-through to the central app, which is authoritative
 * for validation, locks, rate limit and the log. There are deliberately NO AI
 * abilities for writing DNS (a wrong MX silently loses mail).
 *
 * Writes need: capability uxstudio_manage_dns + REST nonce (core) + a stored
 * risk acknowledgement + a login younger than 15 minutes (`reauth_required`).
 */
final class RestController extends Controller {

	private const ID = '(?P<id>[A-Za-z0-9]{1,64})';

	/**
	 * @param Module $module Owning module.
	 */
	public function __construct( private Module $module ) {}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$view   = Module::CAP_VIEW;
		$manage = Module::CAP_MANAGE;

		$this->route( '/dns/status', 'GET', array( $this, 'status' ), array(), $view );
		$this->route( '/dns/records', 'GET', array( $this, 'records' ), array(), $view );
		$this->route( '/dns/records', 'POST', array( $this, 'create' ), array(), $manage );
		$this->route( '/dns/records/' . self::ID, 'POST', array( $this, 'update' ), array(), $manage );
		$this->route( '/dns/records/' . self::ID, 'DELETE', array( $this, 'delete' ), array(), $manage );
		$this->route( '/dns/log', 'GET', array( $this, 'log' ), array(), $view );
		$this->route( '/dns/revert', 'POST', array( $this, 'revert' ), array(), $manage );
		$this->route( '/dns/ack', 'POST', array( $this, 'ack' ), array(), $manage );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function status( WP_REST_Request $request ) {
		unset( $request );
		$ca = CaClient::call( 'status', 'GET' );

		$state = is_wp_error( $ca )
			? array(
				'active'  => false,
				'reason'  => 'ca_unreachable' === $ca->get_error_code() ? 'ca_unreachable' : 'not_configured',
				'message' => $ca->get_error_message(),
			)
			: array(
				'active' => ! empty( $ca['active'] ),
				'reason' => (string) ( $ca['reason'] ?? '' ),
				'zone'   => (string) ( $ca['zone'] ?? '' ),
			);

		$ack = (int) get_user_meta( get_current_user_id(), Module::META_ACK, true );

		return $this->ok(
			$state + array(
				'can_manage'   => current_user_can( Module::CAP_MANAGE ),
				'acknowledged' => $ack > 0,
				'user_id'      => get_current_user_id(),
				'login_fresh'  => Reauth::is_fresh(),
				'reauth_url'   => Reauth::login_url(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function records( WP_REST_Request $request ) {
		$ca = CaClient::call( 'list', 'GET', array(), $request->get_param( 'fresh' ) ? array( 'fresh' => 1 ) : array() );
		if ( is_wp_error( $ca ) ) {
			return $this->fail( $ca );
		}
		unset( $ca['success'] );
		return $this->ok( $ca );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function create( WP_REST_Request $request ) {
		return $this->write( 'create', array( 'record' => $this->record_input( $request ) ), 'create' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function update( WP_REST_Request $request ) {
		return $this->write(
			'update',
			array(
				'id'          => (string) $request['id'],
				'record'      => $this->record_input( $request ),
				'modified_on' => (string) $request->get_param( 'modified_on' ),
			),
			'update'
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function delete( WP_REST_Request $request ) {
		return $this->write(
			'delete',
			array(
				'id'          => (string) $request['id'],
				'modified_on' => (string) $request->get_param( 'modified_on' ),
			),
			'delete'
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function revert( WP_REST_Request $request ) {
		return $this->write( 'revert', array( 'log_id' => absint( $request->get_param( 'log_id' ) ) ), 'revert' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 */
	public function ack( WP_REST_Request $request ) {
		unset( $request );
		$ca = CaClient::call( 'ack', 'POST', array( 'actor' => $this->actor() ) );
		if ( is_wp_error( $ca ) ) {
			return $this->fail( $ca );
		}
		update_user_meta( get_current_user_id(), Module::META_ACK, time() );
		return $this->ok( array( 'acknowledged' => true ) );
	}

	/**
	 * History from the central app (authoritative); local copy only when it is down.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function log( WP_REST_Request $request ) {
		$query = array();
		foreach ( array( 'user_id', 'log_action', 'log_status', 'from', 'to', 'page_no', 'per_page' ) as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$query[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		$ca = CaClient::call( 'log', 'GET', array(), $query );
		if ( is_wp_error( $ca ) ) {
			$status = (int) ( $ca->get_error_data()['status'] ?? 0 );
			if ( $status >= 500 || 424 === $status ) {
				$rows = LocalLog::recent( 50 );
				return $this->ok(
					array(
						'rows'   => $rows,
						'total'  => count( $rows ),
						'users'  => array(),
						'source' => 'local',
					)
				);
			}
			return $this->fail( $ca );
		}
		unset( $ca['success'] );
		$ca['source'] = 'central';
		return $this->ok( $ca );
	}

	// ------------------------------------------------------------------

	/**
	 * Guarded write: throttle, acknowledgement, fresh login, then the broker call.
	 *
	 * @param string $op     Broker action (create|update|delete|revert).
	 * @param array  $body   Extra body.
	 * @param string $log_as Action name for the local log.
	 */
	private function write( string $op, array $body, string $log_as ) {
		if ( Guard::exceeded( 'dns_write', 40, 60, 600 ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests, slow down.', 'ux-studio' ), array( 'status' => 429 ) );
		}
		if ( (int) get_user_meta( get_current_user_id(), Module::META_ACK, true ) <= 0 ) {
			return new WP_Error( 'ack_required', __( 'Confirm that you understand the risks of changing DNS first.', 'ux-studio' ), array( 'status' => 403 ) );
		}
		if ( ! Reauth::is_fresh() ) {
			return new WP_Error(
				'reauth_required',
				__( 'To change DNS, please log in again (your last login is older than 15 minutes).', 'ux-studio' ),
				array(
					'status'     => 403,
					'reauth_url' => Reauth::login_url(),
				)
			);
		}

		$ca = CaClient::call(
			$op,
			'POST',
			$body + array(
				'actor'            => $this->actor(),
				'site_admin_email' => (string) get_option( 'admin_email' ),
			)
		);
		if ( is_wp_error( $ca ) ) {
			$data = (array) $ca->get_error_data();
			if ( 'reauth_required' === $ca->get_error_code() ) {
				$data['reauth_url'] = Reauth::login_url();
				return new WP_Error( 'reauth_required', $ca->get_error_message(), $data );
			}
			return $this->fail( $ca );
		}

		LocalLog::record( $log_as, (array) ( $ca['results'] ?? array() ), (string) ( $ca['batch_id'] ?? '' ) );
		unset( $ca['success'] );
		return $this->ok( $ca );
	}

	/**
	 * Only whitelisted record fields go to the central app.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function record_input( WP_REST_Request $request ): array {
		$in = (array) $request->get_param( 'record' );
		return array(
			'type'     => isset( $in['type'] ) ? sanitize_text_field( (string) $in['type'] ) : '',
			'name'     => isset( $in['name'] ) ? sanitize_text_field( (string) $in['name'] ) : '',
			'content'  => isset( $in['content'] ) ? trim( wp_unslash( (string) $in['content'] ) ) : '',
			'ttl'      => isset( $in['ttl'] ) ? (int) $in['ttl'] : 1,
			'proxied'  => ! empty( $in['proxied'] ),
			'priority' => isset( $in['priority'] ) && '' !== $in['priority'] ? (int) $in['priority'] : null,
			'comment'  => isset( $in['comment'] ) ? sanitize_text_field( (string) $in['comment'] ) : '',
		);
	}

	/**
	 * Who is making the change (snapshot stored in the central log).
	 *
	 * @return array<string, mixed>
	 */
	private function actor(): array {
		$user = wp_get_current_user();
		return array(
			'id'        => $user->ID,
			'login'     => $user->user_login,
			'name'      => $user->display_name,
			'email'     => $user->user_email,
			'ip'        => ClientIp::get(),
			'ua'        => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'auth_time' => Reauth::login_time(),
		);
	}

	/**
	 * Re-wrap a broker error so the SPA gets the code, message and per-change results.
	 *
	 * @param WP_Error $error Error from CaClient.
	 */
	private function fail( WP_Error $error ): WP_Error {
		$data           = (array) $error->get_error_data();
		$data['status'] = (int) ( $data['status'] ?? 502 );
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
