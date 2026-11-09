<?php
/**
 * REST routes of the legal pages wizard (all administrator only).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Core\ActivityLog;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/consent/legal            wizard data, documents and their state
 * POST uxstudio/v1/consent/legal/profile    {profile: {...}} validate and save the wizard data
 * POST uxstudio/v1/consent/legal/publish    {docs: [...], live: bool} make or update the pages and versions
 * GET  uxstudio/v1/consent/legal/preview?doc=   the document as it would be published now
 * GET  uxstudio/v1/consent/legal/versions?doc=  archive of a document (without the text)
 * GET  uxstudio/v1/consent/legal/version?id=    one archived version with its text
 *
 * Every route needs `manage_options` (Controller::route), writes spend the per-user write budget. Input is validated in
 * LegalProfile::sanitize() and in the allowlists below; HTML sent back is passed through wp_kses_post().
 */
final class LegalRest extends Controller {

	/** Hook callback. */
	public static function register(): void {
		( new self() )->register_routes();
	}

	/** Register the routes. */
	public function register_routes(): void {
		$doc = array(
			'doc' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => LegalProfile::DOCS,
			),
		);
		$this->route( '/consent/legal', 'GET', array( $this, 'get_state' ) );
		$this->route(
			'/consent/legal/profile',
			'POST',
			array( $this, 'save_profile' ),
			array(
				'profile' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);
		$this->route(
			'/consent/legal/publish',
			'POST',
			array( $this, 'publish' ),
			array(
				'docs' => array(
					'type'     => 'array',
					'required' => true,
					'items'    => array(
						'type' => 'string',
						'enum' => LegalProfile::DOCS,
					),
				),
				'live' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		$this->route( '/consent/legal/preview', 'GET', array( $this, 'preview' ), $doc );
		$this->route( '/consent/legal/versions', 'GET', array( $this, 'versions' ), $doc );
		$this->route(
			'/consent/legal/version',
			'GET',
			array( $this, 'version' ),
			array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/** Wizard data and the state of the documents. */
	public function get_state(): WP_REST_Response {
		return $this->ok( Legal::state() );
	}

	/**
	 * Save the wizard data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_profile( WP_REST_Request $request ) {
		$input  = (array) $request->get_param( 'profile' );
		$result = Legal::save_profile( $input );
		if ( ! $result['ok'] ) {
			return new WP_Error(
				'uxstudio_legal_invalid',
				__( 'Some details are not valid. Nothing was saved.', 'ux-studio' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}
		ActivityLog::log( 'consent', 'legal_profile', 'consent_legal', 0, array() );
		return $this->ok( Legal::state() );
	}

	/**
	 * Create or update pages and versions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish( WP_REST_Request $request ) {
		$docs   = array_map( 'strval', (array) $request->get_param( 'docs' ) );
		$result = Legal::publish( $docs, (bool) $request->get_param( 'live' ) );
		if ( ! $result['ok'] ) {
			return new WP_Error(
				'uxstudio_legal_incomplete',
				__( 'The pages were not made: some details are missing.', 'ux-studio' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}
		ActivityLog::log(
			'consent',
			'legal_publish',
			'consent_legal',
			0,
			array(
				'docs'    => $docs,
				'results' => $result['results'],
			)
		);
		return $this->ok(
			array(
				'results' => $result['results'],
				'state'   => Legal::state(),
			)
		);
	}

	/**
	 * Preview of a document.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$doc = (string) $request->get_param( 'doc' );
		return $this->ok(
			array(
				'doc'     => $doc,
				'html'    => Legal::preview( $doc ),
				'missing' => LegalProfile::missing( $doc, Legal::profile_for_form() ),
			)
		);
	}

	/**
	 * Versions of one document.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function versions( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( Legal::versions( (string) $request->get_param( 'doc' ) ) );
	}

	/**
	 * One archived version.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function version( WP_REST_Request $request ) {
		$row = Legal::version( (int) $request->get_param( 'id' ) );
		if ( null === $row ) {
			return new WP_Error( 'uxstudio_legal_not_found', __( 'This version does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $row );
	}
}
