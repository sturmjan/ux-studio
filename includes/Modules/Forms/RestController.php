<?php
/**
 * Form Builder REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use UxStudio\Core\ActivityLog;
use UxStudio\Modules\BotThrottle\Guard;
use UxStudio\Modules\SecurityOptimization\CaptchaVerifier;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin routes (capability-gated, nonce via wp_rest as usual) manage form
 * definitions and the submissions archive. `/forms/submit` is the one public
 * route - registered directly (permission_callback => '__return_true') and
 * exempted from the login-required REST lockdown via the
 * uxstudio_rest_public_routes filter (see Module::allow_public_routes()).
 */
final class RestController extends Controller {

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	public function register_routes(): void {
		$cap = $this->module->capability();

		$this->route( '/forms', 'GET', array( $this, 'list_forms' ), array(), $cap );
		$this->route( '/forms', 'POST', array( $this, 'create_form' ), array(), $cap );
		$this->route( '/forms/(?P<id>\d+)', 'GET', array( $this, 'get_form' ), $this->id_arg(), $cap );
		$this->route( '/forms/(?P<id>\d+)', 'POST', array( $this, 'update_form' ), $this->id_arg(), $cap );
		$this->route( '/forms/(?P<id>\d+)', 'DELETE', array( $this, 'delete_form' ), $this->id_arg(), $cap );
		$this->route( '/forms/(?P<id>\d+)/delete-with-submissions', 'POST', array( $this, 'delete_form_and_submissions' ), $this->id_arg(), $cap );
		$this->route_readonly( '/forms/(?P<id>\d+)/preview-email', array( $this, 'preview_email' ), $this->id_arg(), $cap );

		$this->route( '/forms/submissions', 'GET', array( $this, 'list_submissions' ), array(), $cap );
		$this->route( '/forms/submissions/export', 'GET', array( $this, 'export_submissions' ), array(), $cap );
		$this->route( '/forms/submissions/(?P<id>\d+)', 'GET', array( $this, 'get_submission' ), $this->id_arg(), $cap );
		$this->route( '/forms/submissions/(?P<id>\d+)', 'DELETE', array( $this, 'delete_submission' ), $this->id_arg(), $cap );
		$this->route( '/forms/submissions/(?P<id>\d+)/status', 'POST', array( $this, 'set_submission_status' ), $this->id_arg(), $cap );
		$this->route( '/forms/submissions/(?P<id>\d+)/resend', 'POST', array( $this, 'resend_submission' ), $this->id_arg(), $cap );
		$this->route(
			'/forms/submissions/(?P<id>\d+)/files/(?P<file_id>\d+)',
			'GET',
			array( $this, 'download_file' ),
			array(
				'id'      => array( 'required' => true, 'type' => 'integer' ),
				'file_id' => array( 'required' => true, 'type' => 'integer' ),
			),
			$cap
		);

		register_rest_route(
			self::NS,
			'/forms/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array( 'required' => true ),
				),
			)
		);
	}

	private function id_arg(): array {
		return array( 'id' => array( 'required' => true, 'type' => 'integer' ) );
	}

	// =====================================================================
	// Form CRUD
	// =====================================================================

	public function list_forms(): WP_REST_Response {
		return $this->ok( $this->module->list_forms() );
	}

	public function create_form( WP_REST_Request $request ) {
		$form = $this->module->create_form( (array) $request->get_json_params() );
		if ( null === $form ) {
			return new WP_Error( 'uxstudio_forms_invalid', __( 'A form title is required.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		return $this->ok( $form );
	}

	public function get_form( WP_REST_Request $request ) {
		$form = $this->module->get_form( (int) $request->get_param( 'id' ) );
		if ( null === $form ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'Form not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $form );
	}

	public function update_form( WP_REST_Request $request ) {
		$form = $this->module->update_form( (int) $request->get_param( 'id' ), (array) $request->get_json_params() );
		if ( null === $form ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'Form not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( $form );
	}

	public function delete_form( WP_REST_Request $request ) {
		$deleted = $this->module->delete_form( (int) $request->get_param( 'id' ) );
		if ( ! $deleted ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'Form not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	public function delete_form_and_submissions( WP_REST_Request $request ) {
		$deleted = $this->module->delete_form_and_submissions( (int) $request->get_param( 'id' ) );
		if ( ! $deleted ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'Form not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	public function preview_email( WP_REST_Request $request ) {
		$form = $this->module->get_form( (int) $request->get_param( 'id' ) );
		if ( null === $form ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'Form not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$action = (array) $request->get_json_params();
		return $this->ok( $this->module->preview_email( $form, $action ) );
	}

	// =====================================================================
	// Submissions archive (admin)
	// =====================================================================

	public function list_submissions( WP_REST_Request $request ): WP_REST_Response {
		$result = Submissions::query( $this->submission_query_args( $request ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ?: 20 ) );

		return $this->ok(
			$result['items'],
			array(
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	public function get_submission( WP_REST_Request $request ) {
		$id         = (int) $request->get_param( 'id' );
		$submission = Submissions::get( $id );
		if ( null === $submission ) {
			return new WP_Error( 'uxstudio_forms_sub_not_found', __( 'Submission not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$submission['files']      = Submissions::files_for( $id );
		$submission['action_log'] = Actions::log_for_submission( $id );

		if ( 'unread' === $submission['status'] ) {
			Submissions::set_status( $id, 'read' );
			$submission['status'] = 'read';
		}

		return $this->ok( $submission );
	}

	public function set_submission_status( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! Submissions::set_status( $id, $status ) ) {
			return new WP_Error( 'uxstudio_forms_status_invalid', __( 'Invalid status or submission not found.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		ActivityLog::log( 'forms', 'status', 'submission', $id, array( 'status' => $status ) );
		return $this->ok( array( 'status' => $status ) );
	}

	public function delete_submission( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( ! Submissions::delete( $id ) ) {
			return new WP_Error( 'uxstudio_forms_sub_not_found', __( 'Submission not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		ActivityLog::log( 'forms', 'delete', 'submission', $id );
		return $this->ok( array( 'deleted' => true ) );
	}

	public function resend_submission( WP_REST_Request $request ) {
		$id         = (int) $request->get_param( 'id' );
		$submission = Submissions::get( $id );
		if ( null === $submission ) {
			return new WP_Error( 'uxstudio_forms_sub_not_found', __( 'Submission not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$form = $this->module->get_form( $submission['form_id'] );
		if ( null === $form ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'The parent form no longer exists.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		Actions::resend( $form, $submission );
		return $this->ok( array( 'log' => Actions::log_for_submission( $id ) ) );
	}

	public function export_submissions( WP_REST_Request $request ): WP_REST_Response {
		$rows = Submissions::query_raw_for_export( $this->submission_query_args( $request ) );
		$form_id = (int) $request->get_param( 'form_id' );
		$name    = $form_id ? sanitize_title( (string) ( $this->module->get_form( $form_id )['title'] ?? 'form' ) ) : 'uxstudio-forms';
		ActivityLog::log( 'forms', 'export', 'submissions', $form_id, array( 'count' => count( $rows ) ) );
		Csv::stream( $rows, $name . '-' . gmdate( 'Y-m-d' ) . '.csv' );
		exit; // Csv::stream() already exits; kept as an explicit safety net.
	}

	public function download_file( WP_REST_Request $request ) {
		$submission_id = (int) $request->get_param( 'id' );
		$file_id       = (int) $request->get_param( 'file_id' );

		$submission = Submissions::get( $submission_id );
		if ( null === $submission ) {
			return new WP_Error( 'uxstudio_forms_sub_not_found', __( 'Submission not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$file = null;
		foreach ( Submissions::files_for( $submission_id ) as $candidate ) {
			if ( (int) $candidate['id'] === $file_id ) {
				$file = $candidate;
				break;
			}
		}
		if ( null === $file ) {
			return new WP_Error( 'uxstudio_forms_file_not_found', __( 'File not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		ActivityLog::log( 'forms', 'file_download', 'submission', $submission_id, array( 'field' => $file['field_key'] ) );
		FileStorage::stream( (string) $file['stored_path'], (string) $file['original_name'], (string) $file['mime'] );
		exit; // FileStorage::stream() already exits; kept as an explicit safety net.
	}

	/**
	 * @return array{form_id:int,status:string,q:string,from:string,to:string,page:int,per_page:int}
	 */
	private function submission_query_args( WP_REST_Request $request ): array {
		return array(
			'form_id'  => (int) $request->get_param( 'form_id' ),
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'q'        => sanitize_text_field( (string) $request->get_param( 'q' ) ),
			'from'     => sanitize_text_field( (string) $request->get_param( 'from' ) ),
			'to'       => sanitize_text_field( (string) $request->get_param( 'to' ) ),
			'page'     => (int) $request->get_param( 'page' ) ?: 1,
			'per_page' => (int) $request->get_param( 'per_page' ) ?: 20,
		);
	}

	// =====================================================================
	// Public submit
	// =====================================================================

	/**
	 * Public, unauthenticated. Anti-abuse layers, in order: burst rate limit
	 * (BotThrottle\Guard, shared infra - see PLAN.md 20.8), honeypot (always
	 * on, checked inside Submissions::submit()), then CAPTCHA if the form
	 * has a captcha field and a provider is configured.
	 */
	public function submit( WP_REST_Request $request ) {
		if ( Guard::exceeded( 'forms_submit', 20, 60, 300 ) ) {
			return new WP_Error( 'uxstudio_forms_rate_limited', __( 'Too many submissions, please try again later.', 'ux-studio' ), array( 'status' => 429 ) );
		}

		$form_id = (int) $request->get_param( 'id' );
		$form    = $this->module->get_form( $form_id );
		if ( null === $form || 'active' !== $form['status'] ) {
			return new WP_Error( 'uxstudio_forms_not_found', __( 'This form is not available.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		if ( $this->form_has_captcha( $form ) && ! $this->verify_captcha() ) {
			return new WP_Error( 'uxstudio_forms_captcha', __( 'Spam verification failed, please try again.', 'ux-studio' ), array( 'status' => 422 ) );
		}

		$raw_data = $request->get_param( 'data' );
		$input    = is_array( $raw_data ) ? $raw_data : (array) json_decode( (string) $raw_data, true );
		$files    = $request->get_file_params();
		$honeypot = (string) $request->get_param( Submissions::HONEYPOT_FIELD );

		$result = Submissions::submit( $form, $input, $files, $honeypot );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['discarded'] ) ) {
			Actions::run( $form, $result );
		}

		return $this->ok(
			array(
				'message' => (string) ( $form['settings']['success_text'] ?? __( 'Thank you, your submission has been received.', 'ux-studio' ) ),
			)
		);
	}

	private function form_has_captcha( array $form ): bool {
		foreach ( (array) $form['fields'] as $field ) {
			if ( 'captcha' === ( $field['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private function verify_captcha(): bool {
		if ( ! class_exists( \UxStudio\Modules\SecurityOptimization\Module::class ) ) {
			return true; // Module not installed - nothing to verify against, fail open (see PLAN.md 20.11: full captcha enforcement is F2).
		}
		$module = \UxStudio\Plugin::instance()->modules->instance( 'security-optimization' );
		if ( ! $module instanceof \UxStudio\Modules\SecurityOptimization\Module || ! CaptchaVerifier::is_configured( $module ) ) {
			return true;
		}
		return CaptchaVerifier::verify_token( $module );
	}
}
