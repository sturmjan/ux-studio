<?php
/**
 * Exit Popup REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ExitPopup;

use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/exit-popup/subscribers  - last 200 captured emails (manage_options)
 * GET  uxstudio/v1/exit-popup/export-url   - nonce-signed CSV export URL (manage_options)
 * POST uxstudio/v1/exit-popup/subscribe    - public, IP rate-limited email capture
 */
final class RestController extends Controller {

	private const RATE_LIMIT  = 5; // subscribe attempts...
	private const RATE_WINDOW = 600; // ...per 10 minutes, per hashed IP.

	private Module $module;

	/**
	 * @param Module $module Owning module instance.
	 */
	public function __construct( Module $module ) {
		$this->module = $module;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/exit-popup/subscribers', 'GET', array( $this, 'subscribers' ) );
		$this->route( '/exit-popup/export-url', 'GET', array( $this, 'export_url' ) );

		// Public, unauthenticated endpoint: registered directly (bypassing the
		// protected route() helper, which defaults to manage_options) and
		// protected instead by a per-IP transient rate limit below.
		register_rest_route(
			Controller::NS,
			'/exit-popup/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static function ( $value ): bool {
							return is_email( (string) $value ) !== false;
						},
					),
					'page_url' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Last 200 captured subscribers.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function subscribers( WP_REST_Request $request ) {
		return $this->ok( $this->module->get_subscribers() );
	}

	/**
	 * Nonce-signed admin-post CSV export URL for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function export_url( WP_REST_Request $request ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=uxstudio_exit_popup_export' ),
			'uxstudio_exit_popup_export'
		);
		return $this->ok( array( 'url' => $url ) );
	}

	/**
	 * Public exit-intent email capture, rate-limited per IP.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function subscribe( WP_REST_Request $request ) {
		$ip      = $this->client_ip();
		$ip_hash = hash( 'sha256', $ip . wp_salt() );

		if ( ! $this->check_rate_limit( $ip_hash ) ) {
			return new WP_Error(
				'uxstudio_exit_popup_rate_limited',
				__( 'Too many requests, please try again later.', 'ux-studio' ),
				array( 'status' => 429 )
			);
		}

		$email    = (string) $request->get_param( 'email' );
		$page_url = mb_substr( (string) $request->get_param( 'page_url' ), 0, 500 );

		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_exit_popup_emails";

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) );
		if ( ! $existing ) {
			$wpdb->insert(
				$table,
				array(
					'created_at' => current_time( 'mysql' ),
					'email'      => $email,
					'page_url'   => $page_url,
					'ip_hash'    => $ip_hash,
				),
				array( '%s', '%s', '%s', '%s' )
			);
		}

		// Idempotent by design: an existing email is treated as success too,
		// so we never leak subscription state to a caller probing addresses.
		return $this->ok( array( 'success' => true ) );
	}

	/**
	 * Sliding-window per-IP rate limit for the public subscribe endpoint.
	 *
	 * @param string $ip_hash Salted hash of the caller's IP.
	 */
	private function check_rate_limit( string $ip_hash ): bool {
		$key   = 'uxstudio_exit_popup_rl_' . $ip_hash;
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Best-effort client IP. Proxy headers (X-Forwarded-For etc.) are attacker
	 * controlled unless a trusted reverse proxy is configured in front of WP,
	 * so REMOTE_ADDR is used as the primary, most trustworthy source and the
	 * headers are only a fallback for shared/proxied hosting setups.
	 */
	private function client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		}

		// Fallback only: these headers can be spoofed by the client unless a
		// trusted reverse proxy strips/overwrites them before WP sees the request.
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
				$parts = explode( ',', $value );
				return trim( $parts[0] );
			}
		}

		return '0.0.0.0';
	}
}
