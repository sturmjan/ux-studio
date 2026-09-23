<?php
/**
 * Post-submit action execution: `email`, `webhook`, `redirect` (F2) and
 * `create_post` (F4, PLAN.md 20.11) + the per-submission action log.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a form's `settings.actions[]` chain synchronously right after a
 * submission is stored. One action failing never stops the rest - each
 * action logs its own ok|fail row to uxstudio_form_action_log.
 */
final class Actions {

	/**
	 * @param array $form       Form row (array form, see Module::form_to_array()).
	 * @param array $submission { id, values, fields_snapshot, created_at }.
	 */
	public static function run( array $form, array $submission ): void {
		$actions = (array) ( $form['settings']['actions'] ?? array() );
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['type'] ) ) {
				continue;
			}
			switch ( $action['type'] ) {
				case 'email':
					self::run_email( $form, $submission, $action );
					break;
				case 'webhook':
					self::run_webhook( $form, $submission, $action );
					break;
				case 'redirect':
					// The actual browser redirect is carried in the REST
					// response (see RestController::submit()/redirect_url())
					// - this just records that the action fired.
					self::run_redirect( $submission, $action );
					break;
				case 'create_post':
					self::run_create_post( $form, $submission, $action );
					break;
				default:
					// Unknown/future action types are stored but simply
					// skipped by the runner.
					break;
			}
		}
	}

	/**
	 * POST JSON to the form's configured webhook URL, HMAC-signed the same
	 * way as ContentSync\HmacAuth (hub<->node channel) so the receiver can
	 * verify the request really came from this site - PLAN.md 20.2/20.8.
	 * The secret lives only in the form's own settings_json (Module::
	 * sanitize_settings()), never in code.
	 *
	 * @param array $action { url }.
	 */
	private static function run_webhook( array $form, array $submission, array $action ): void {
		$url = (string) ( $action['url'] ?? '' );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			self::log( (int) ( $submission['id'] ?? 0 ), 'webhook', 'fail', __( 'Invalid or missing webhook URL.', 'ux-studio' ) );
			return;
		}

		$secret = (string) ( $form['settings']['webhook_secret'] ?? '' );
		$body   = (string) wp_json_encode(
			array(
				'event'         => 'form_submission',
				'form_id'       => (int) ( $form['id'] ?? 0 ),
				'form_title'    => (string) ( $form['title'] ?? '' ),
				'submission_id' => (int) ( $submission['id'] ?? 0 ),
				'submitted_at'  => (string) ( $submission['created_at'] ?? current_time( 'mysql' ) ),
				'fields'        => (array) ( $submission['fields_snapshot'] ?? array() ),
				'values'        => (array) ( $submission['values'] ?? array() ),
			)
		);
		$signature = hash_hmac( 'sha256', $body, $secret );

		// wp_safe_remote_post() (not wp_remote_post()) rejects loopback/private/
		// link-local targets (e.g. cloud metadata endpoints) via the
		// http_request_host_is_external filter — the webhook URL is admin-set
		// but still an outbound request to an arbitrary user-supplied host.
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'          => 'application/json',
					'X-UxStudio-Signature'  => $signature,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( (int) ( $submission['id'] ?? 0 ), 'webhook', 'fail', $response->get_error_message() );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$ok   = $code >= 200 && $code < 300;
		self::log(
			(int) ( $submission['id'] ?? 0 ),
			'webhook',
			$ok ? 'ok' : 'fail',
			/* translators: %d: HTTP status code returned by the receiving webhook. */
			sprintf( __( 'HTTP %d.', 'ux-studio' ), $code )
		);
	}

	/**
	 * @param array $action { url }.
	 */
	private static function run_redirect( array $submission, array $action ): void {
		$url = (string) ( $action['url'] ?? '' );
		self::log(
			(int) ( $submission['id'] ?? 0 ),
			'redirect',
			'' !== $url ? 'ok' : 'fail',
			'' !== $url ? $url : __( 'No redirect URL configured.', 'ux-studio' )
		);
	}

	/**
	 * Creates a WP post/CPT from a submission (PLAN.md 20.2/20.11 F4). Every
	 * submitted scalar value is also written as post meta (`_uxsf_{key}`,
	 * mirroring the `{key}` merge-tag naming used everywhere else in this
	 * module) so the created post carries the raw data, not just the
	 * title/content template's rendering of it - useful for a theme template,
	 * ACF field group or another plugin to pick up later without re-parsing text.
	 *
	 * @param array $action { post_type, post_status, title_template, content_template, author_id }.
	 */
	private static function run_create_post( array $form, array $submission, array $action ): void {
		$post_type = (string) ( $action['post_type'] ?? 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			self::log( (int) ( $submission['id'] ?? 0 ), 'create_post', 'fail', __( 'The configured post type no longer exists.', 'ux-studio' ) );
			return;
		}

		$context = array(
			'form_title'      => (string) ( $form['title'] ?? '' ),
			'submission_date' => (string) ( $submission['created_at'] ?? current_time( 'mysql' ) ),
			'fields_snapshot' => (array) ( $submission['fields_snapshot'] ?? array() ),
			'values'          => (array) ( $submission['values'] ?? array() ),
		);

		$title = trim( EmailTemplateRenderer::merge_tags( (string) ( $action['title_template'] ?? '' ), $context ) );
		if ( '' === $title ) {
			/* translators: 1: form title, 2: submission date. */
			$title = sprintf( __( '%1$s submission - %2$s', 'ux-studio' ), $context['form_title'], $context['submission_date'] );
		}
		$content = EmailTemplateRenderer::merge_tags( (string) ( $action['content_template'] ?? '' ), $context );

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => (string) ( $action['post_status'] ?? 'draft' ),
				'post_title'   => wp_strip_all_tags( $title ),
				'post_content' => $content,
				'post_author'  => self::resolve_post_author( $form, (int) ( $action['author_id'] ?? 0 ) ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			self::log( (int) ( $submission['id'] ?? 0 ), 'create_post', 'fail', $post_id->get_error_message() );
			return;
		}

		update_post_meta( $post_id, '_uxstudio_forms_form_id', (int) ( $form['id'] ?? 0 ) );
		update_post_meta( $post_id, '_uxstudio_forms_submission_id', (int) ( $submission['id'] ?? 0 ) );
		foreach ( (array) $context['values'] as $key => $value ) {
			$meta_value = self::meta_safe_value( $value );
			if ( null !== $meta_value ) {
				update_post_meta( $post_id, '_uxsf_' . sanitize_key( (string) $key ), $meta_value );
			}
		}

		self::log(
			(int) ( $submission['id'] ?? 0 ),
			'create_post',
			'ok',
			/* translators: 1: post type, 2: post ID. */
			sprintf( __( 'Created %1$s #%2$d.', 'ux-studio' ), $post_type, $post_id )
		);
	}

	/**
	 * @param mixed $value Raw submitted value.
	 * @return string|null Null when the value has no sensible flat text form (file/signature entries).
	 */
	private static function meta_safe_value( $value ): ?string {
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) && array_is_list( $value ) && ! isset( $value['stored_name'] ) ) {
			$scalars = array_filter( $value, 'is_scalar' );
			if ( count( $scalars ) === count( $value ) && ! empty( $scalars ) ) {
				return implode( ', ', array_map( 'strval', $scalars ) );
			}
		}
		return null;
	}

	/**
	 * A public submit request has no logged-in user, so `post_author` must be
	 * resolved explicitly - an admin-picked `author_id` (validated it still
	 * exists), else the form creator, else the site's first administrator.
	 */
	private static function resolve_post_author( array $form, int $configured_id ): int {
		if ( $configured_id > 0 && false !== get_userdata( $configured_id ) ) {
			return $configured_id;
		}
		$created_by = (int) ( $form['created_by'] ?? 0 );
		if ( $created_by > 0 && false !== get_userdata( $created_by ) ) {
			return $created_by;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}

	/**
	 * First `redirect` action's URL in the chain, if any - used by
	 * RestController::submit() to tell the public form runtime where to send
	 * the browser after a successful submission (PLAN.md 20.11).
	 */
	public static function redirect_url( array $form ): string {
		foreach ( (array) ( $form['settings']['actions'] ?? array() ) as $action ) {
			if ( is_array( $action ) && 'redirect' === ( $action['type'] ?? '' ) ) {
				return (string) ( $action['url'] ?? '' );
			}
		}
		return '';
	}

	/**
	 * @param array $action { to, subject, template, include_table, cta_text, cta_url }.
	 */
	private static function run_email( array $form, array $submission, array $action ): void {
		$context = array(
			'form_title'      => (string) ( $form['title'] ?? '' ),
			'submission_date' => (string) ( $submission['created_at'] ?? current_time( 'mysql' ) ),
			'fields_snapshot' => (array) ( $submission['fields_snapshot'] ?? array() ),
			'values'          => (array) ( $submission['values'] ?? array() ),
		);

		$to = trim( (string) ( $action['to'] ?? '' ) );
		if ( '' === $to ) {
			$to = get_option( 'admin_email' );
		}
		// Allow the recipient itself to be a merge tag, e.g. {email} to
		// reply to the submitter for an autoresponder-style action.
		$to = EmailTemplateRenderer::merge_tags( $to, $context );

		if ( ! is_email( $to ) ) {
			self::log( (int) ( $submission['id'] ?? 0 ), 'email', 'fail', __( 'Invalid recipient address.', 'ux-studio' ) );
			return;
		}

		$subject = EmailTemplateRenderer::merge_tags(
			sanitize_text_field( (string) ( $action['subject'] ?? '' ) ) ?: (string) ( $form['title'] ?? '' ),
			$context
		);

		$template = (string) ( $action['template'] ?? '' );

		// Custom template (PLAN.md 20.11/F3): the admin's own complete HTML
		// document IS the email - no built-in wrapper applies, same rule
		// Module::preview_email() follows so a preview can never drift from
		// the real send.
		if ( 'custom' === $template ) {
			$html = EmailTemplateRenderer::merge_tags( EmailTemplateRenderer::sanitize_custom_html( (string) ( $action['custom_html'] ?? '' ) ), $context );
		} else {
			$template  = EmailTemplateRenderer::is_valid_template( $template ) ? $template : 'branded';
			$body_text = EmailTemplateRenderer::merge_tags( wp_kses_post( (string) ( $action['message'] ?? '' ) ), $context );
			$body_html = wpautop( $body_text );

			$html = EmailTemplateRenderer::render(
				$template,
				array(
					'heading'         => (string) ( $form['title'] ?? '' ),
					'body_html'       => $body_html,
					'include_table'   => ! isset( $action['include_table'] ) || ! empty( $action['include_table'] ),
					'fields_snapshot' => $context['fields_snapshot'],
					'values'          => $context['values'],
					'cta_text'        => sanitize_text_field( (string) ( $action['cta_text'] ?? '' ) ),
					'cta_url'         => esc_url_raw( (string) ( $action['cta_url'] ?? '' ) ),
				)
			);
		}
		$text = EmailTemplateRenderer::html_to_text( $html );

		// Sent through the normal wp_mail() pipe on purpose: if the SMTP
		// Email module is enabled it reroutes/authenticates delivery via its
		// own phpmailer_init hook, and if Email Log is enabled it captures
		// this exact call via its own wp_mail filter - neither needs (or
		// wants) to be invoked directly here, see PLAN.md 20.2.
		$boundary = md5( uniqid( '', true ) );
		$headers  = array(
			'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
		);
		$body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n\r\n";
		$body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n";
		$body .= "--{$boundary}--";

		$sent = wp_mail( $to, $subject, $body, $headers );

		self::log(
			(int) ( $submission['id'] ?? 0 ),
			'email',
			$sent ? 'ok' : 'fail',
			$sent ? sprintf( /* translators: %s: recipient email. */ __( 'Sent to %s.', 'ux-studio' ), $to ) : __( 'wp_mail() reported failure.', 'ux-studio' )
		);
	}

	/**
	 * Re-run a form's whole action chain for an already-stored submission
	 * ("Resend" button in the archive) - email, webhook and redirect alike;
	 * a redirect action just logs again (there is no browser to redirect).
	 */
	public static function resend( array $form, array $submission ): void {
		self::run( $form, $submission );
	}

	private static function log( int $submission_id, string $action_type, string $status, string $detail = '' ): void {
		if ( $submission_id <= 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_form_action_log",
			array(
				'submission_id' => $submission_id,
				'action_type'   => $action_type,
				'status'        => $status,
				'detail'        => $detail,
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Action log rows for one submission, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function log_for_submission( int $submission_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, action_type, status, detail, created_at FROM {$wpdb->prefix}uxstudio_form_action_log WHERE submission_id = %d ORDER BY id DESC",
				$submission_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
