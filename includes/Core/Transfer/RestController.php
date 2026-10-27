<?php
/**
 * REST routes and the download endpoint of the settings export / import (uxstudio/v1/transfer/*).
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Plugin;
use UxStudio\Rest\Controller;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * All routes need `manage_options` (base controller) and a REST nonce; writes spend the shared per-user write
 * budget. These routes are authenticated, so the "restrict REST API" public-route allow-list is not involved.
 *
 *   GET  transfer/status           modules that can be exported, what each offers, capabilities, last snapshot
 *   POST transfer/export-preview   ids, options: what an export would hold (counts, no file, nothing logged)
 *   POST transfer/export           ids, options: builds the file; returns a one-time download URL (+ the text if small)
 *   POST transfer/import/preview   content, passphrase?, options: diff per module (writes nothing)
 *   POST transfer/import/apply     content, passphrase?, options (with the selection): snapshot, then module save paths
 *   POST transfer/import/undo      put the snapshot back
 *
 * The file download itself is `admin-post.php?action=uxstudio_transfer_download` (nonce, one use, five minutes),
 * because a browser can only save a streamed file from a normal GET.
 */
final class RestController extends Controller {

	public const DOWNLOAD_ACTION = 'uxstudio_transfer_download';
	private const TOKEN_PREFIX   = 'uxstudio_tr_dl_';
	private const INLINE_MAX     = 262144;

	/**
	 * Hook the download endpoint (the routes are registered with rest_api_init by the migration bootstrap).
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( self::class, 'download' ) );
	}

	public function register_routes(): void {
		$this->route( '/transfer/status', 'GET', array( $this, 'status' ) );
		$export_args = array(
			'modules' => array(
				'required' => true,
				'type'     => 'array',
				'items'    => array( 'type' => 'string' ),
			),
			'options' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => array(),
			),
		);
		$this->route_readonly( '/transfer/export-preview', array( $this, 'export_preview' ), $export_args );
		$this->route( '/transfer/export', 'POST', array( $this, 'export' ), $export_args );

		$import_args = array(
			'content'    => array(
				'required' => true,
				'type'     => 'string',
			),
			'passphrase' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
			),
			'options'    => array(
				'required' => false,
				'type'     => 'object',
				'default'  => array(),
			),
		);
		$this->route_readonly( '/transfer/import/preview', array( $this, 'import_preview' ), $import_args );
		$this->route( '/transfer/import/apply', 'POST', array( $this, 'import_apply' ), $import_args );
		$this->route( '/transfer/import/undo', 'POST', array( $this, 'import_undo' ) );
	}

	private function exporter(): Exporter {
		return new Exporter( Plugin::instance()->modules );
	}

	private function importer(): Importer {
		return new Importer( Plugin::instance()->modules );
	}

	/**
	 * GET transfer/status.
	 */
	public function status( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'modules'           => $this->exporter()->catalog(),
				'sodium'            => Crypto::available(),
				'min_passphrase'    => Crypto::MIN_PASS,
				'can_unfiltered_html' => current_user_can( 'unfiltered_html' ),
				'max_bytes'         => Envelope::MAX_BYTES,
				'snapshot'          => ImportSnapshot::describe(),
				'format'            => Envelope::FORMAT,
				'format_version'    => Envelope::VERSION,
				'non_portable'      => array(
					'keys'    => NonPortable::KEYS,
					'options' => NonPortable::STATE_OPTION_PATTERNS,
				),
			)
		);
	}

	/**
	 * Export options from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function export_options( WP_REST_Request $request ): array {
		$raw = $request['options'];
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'include_enabled'    => ! empty( $raw['include_enabled'] ),
			'include_data'       => ! empty( $raw['include_data'] ),
			'include_secrets'    => ! empty( $raw['include_secrets'] ),
			'passphrase'         => is_string( $raw['passphrase'] ?? null ) ? $raw['passphrase'] : '',
			'passphrase_confirm' => is_string( $raw['passphrase_confirm'] ?? null ) ? $raw['passphrase_confirm'] : '',
		);
	}

	/**
	 * POST transfer/export-preview.
	 */
	public function export_preview( WP_REST_Request $request ) {
		$result = $this->exporter()->build( array_map( 'strval', (array) $request['modules'] ), array( 'dry' => true ) + $this->export_options( $request ) );
		return is_wp_error( $result ) ? $result : $this->ok( $result['summary'] );
	}

	/**
	 * POST transfer/export.
	 */
	public function export( WP_REST_Request $request ) {
		$result = $this->exporter()->build( array_map( 'strval', (array) $request['modules'] ), $this->export_options( $request ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::TOKEN_PREFIX . $token,
			array(
				'user'     => get_current_user_id(),
				'filename' => $result['filename'],
				'json'     => $result['json'],
			),
			5 * MINUTE_IN_SECONDS
		);
		$response = $this->ok(
			array(
				'filename'     => $result['filename'],
				'size'         => strlen( $result['json'] ),
				'download_url' => add_query_arg(
					array(
						'action'   => self::DOWNLOAD_ACTION,
						'token'    => $token,
						'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . $token ),
					),
					admin_url( 'admin-post.php' )
				),
				'content'      => strlen( $result['json'] ) <= self::INLINE_MAX ? $result['json'] : null,
				'summary'      => $result['summary'],
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * admin-post.php?action=uxstudio_transfer_download: stream the file once.
	 */
	public static function download(): void {
		$token = isset( $_GET['token'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		$nonce = isset( $_GET['_wpnonce'] ) ? (string) wp_unslash( $_GET['_wpnonce'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! current_user_can( 'manage_options' ) || 32 !== strlen( $token ) || ! wp_verify_nonce( $nonce, self::DOWNLOAD_ACTION . $token ) ) {
			wp_die( esc_html__( 'This download link is not valid.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		$data = get_transient( self::TOKEN_PREFIX . $token );
		delete_transient( self::TOKEN_PREFIX . $token ); // One use.
		if ( ! is_array( $data ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() || ! is_string( $data['json'] ?? null ) ) {
			wp_die( esc_html__( 'This download link has expired. Export again.', 'ux-studio' ), '', array( 'response' => 410 ) );
		}
		$filename = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $data['filename'] );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Content-Length: ' . strlen( $data['json'] ) );
		echo $data['json']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file, not HTML.
		exit;
	}

	/**
	 * The fields shared by the import routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{0:string,1:string,2:array<string,mixed>}
	 */
	private function import_input( WP_REST_Request $request ): array {
		return array(
			(string) $request['content'],
			(string) $request['passphrase'],
			Importer::options( $request['options'] ),
		);
	}

	/**
	 * POST transfer/import/preview.
	 */
	public function import_preview( WP_REST_Request $request ) {
		[ $content, $passphrase, $options ] = $this->import_input( $request );
		$result                            = $this->importer()->preview( $content, $passphrase, $options );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST transfer/import/apply.
	 */
	public function import_apply( WP_REST_Request $request ) {
		[ $content, $passphrase, $options ] = $this->import_input( $request );
		$result                            = $this->importer()->apply( $content, $passphrase, $options );
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}

	/**
	 * POST transfer/import/undo.
	 */
	public function import_undo( WP_REST_Request $request ) {
		$result = $this->importer()->undo();
		return is_wp_error( $result ) ? $result : $this->ok( $result );
	}
}
