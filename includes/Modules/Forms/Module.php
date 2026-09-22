<?php
/**
 * Form Builder module - definitions, public rendering (shortcode) and the
 * submissions archive. See PLAN.md §20 for the full design.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Forms are stored in their own tables (not CPT+postmeta) - they are
 * configuration+data, same reasoning as Destima's sister module. Definitions
 * live in uxstudio_forms; every submission snapshots the field labels/types
 * it was answered against so the archive stays readable even after the form
 * is edited or deleted (see Submissions/PLAN.md 20.3).
 */
final class Module extends BaseModule {

	/** Schema version for this module's own tables. */
	private const DB_VERSION = 1;

	public function boot(): void {
		DB::ensure_module_tables( 'forms', self::DB_VERSION, array( $this, 'migrate' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_filter( 'uxstudio_rest_public_routes', array( $this, 'allow_public_routes' ) );

		DashboardWidget::register();
	}

	/**
	 * @param int $from Previously installed schema version (0 = fresh install).
	 */
	public function migrate( int $from ): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_forms (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(190) NOT NULL DEFAULT '',
				description TEXT NULL,
				fields_json LONGTEXT NOT NULL,
				settings_json LONGTEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'active',
				created_by BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_form_submissions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id BIGINT UNSIGNED NOT NULL,
				form_title VARCHAR(190) NOT NULL DEFAULT '',
				values_json LONGTEXT NOT NULL,
				fields_snapshot_json LONGTEXT NOT NULL,
				meta_json LONGTEXT NULL,
				search_text MEDIUMTEXT NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'unread',
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id),
				KEY status (status),
				KEY created_at (created_at),
				FULLTEXT KEY search_text (search_text)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_form_submission_files (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				submission_id BIGINT UNSIGNED NOT NULL,
				field_key VARCHAR(190) NOT NULL DEFAULT '',
				original_name VARCHAR(255) NOT NULL DEFAULT '',
				stored_path VARCHAR(500) NOT NULL DEFAULT '',
				mime VARCHAR(100) NOT NULL DEFAULT '',
				size INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_form_action_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				submission_id BIGINT UNSIGNED NOT NULL,
				action_type VARCHAR(30) NOT NULL DEFAULT '',
				status VARCHAR(10) NOT NULL DEFAULT '',
				detail TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY submission_id (submission_id)
			) {$charset};"
		);
	}

	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	public function rest_controller(): ?string {
		return RestController::class;
	}

	public function capability(): string {
		return 'manage_options';
	}

	/**
	 * The public submit route has no logged-in user - it must stay reachable
	 * even when Security Optimization restricts the whole REST API to
	 * logged-in requests (see SecurityOptimization\Module::is_public_rest_route()).
	 *
	 * @param mixed $routes Route prefixes already registered as public.
	 */
	public function allow_public_routes( $routes ): array {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = '/uxstudio/v1/forms/submit';
		return $routes;
	}

	public function register_shortcode(): void {
		add_shortcode( 'uxstudio_form', array( $this, 'render_shortcode' ) );
	}

	// =====================================================================
	// Form CRUD
	// =====================================================================

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_forms(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, title, description, status, created_at, updated_at FROM {$wpdb->prefix}uxstudio_forms ORDER BY updated_at DESC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			function ( array $row ): array {
				global $wpdb;
				$count = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = %d AND status != 'trash'", $row['id'] )
				);
				$unread = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = %d AND status = 'unread'", $row['id'] )
				);
				return array(
					'id'               => (int) $row['id'],
					'title'            => (string) $row['title'],
					'description'      => (string) $row['description'],
					'status'           => (string) $row['status'],
					'created_at'       => (string) $row['created_at'],
					'updated_at'       => (string) $row['updated_at'],
					'submissions'      => $count,
					'unread'           => $unread,
				);
			},
			$rows
		);
	}

	public function get_form( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}uxstudio_forms WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $this->row_to_array( $row ) : null;
	}

	/**
	 * @param array $data { title, description, fields, settings, status }.
	 */
	public function create_form( array $data ): ?array {
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			return null;
		}

		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_forms",
			array(
				'title'         => $title,
				'description'   => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
				'fields_json'   => wp_json_encode( Fields::sanitize_fields( $data['fields'] ?? array() ) ),
				'settings_json' => wp_json_encode( $this->sanitize_settings( $data['settings'] ?? array() ) ),
				'status'        => $this->sanitize_status( $data['status'] ?? 'draft' ),
				'created_by'    => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			ActivityLog::log( 'forms', 'create', 'form', $id );
		}
		return $this->get_form( $id );
	}

	/**
	 * @param array $data Partial update payload.
	 */
	public function update_form( int $id, array $data ): ?array {
		$existing = $this->get_form( $id );
		if ( null === $existing ) {
			return null;
		}

		global $wpdb;
		$update = array( 'updated_at' => current_time( 'mysql' ) );
		$format = array( '%s' );

		if ( array_key_exists( 'title', $data ) ) {
			$title = sanitize_text_field( (string) $data['title'] );
			if ( '' === $title ) {
				return null;
			}
			$update['title'] = $title;
			$format[]         = '%s';
		}
		if ( array_key_exists( 'description', $data ) ) {
			$update['description'] = sanitize_textarea_field( (string) $data['description'] );
			$format[]               = '%s';
		}
		if ( array_key_exists( 'fields', $data ) ) {
			$update['fields_json'] = wp_json_encode( Fields::sanitize_fields( $data['fields'] ) );
			$format[]               = '%s';
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$update['settings_json'] = wp_json_encode( $this->sanitize_settings( $data['settings'] ) );
			$format[]                 = '%s';
		}
		if ( array_key_exists( 'status', $data ) ) {
			$update['status'] = $this->sanitize_status( $data['status'] );
			$format[]          = '%s';
		}

		$wpdb->update( "{$wpdb->prefix}uxstudio_forms", $update, array( 'id' => $id ), $format, array( '%d' ) );
		ActivityLog::log( 'forms', 'update', 'form', $id );

		return $this->get_form( $id );
	}

	/**
	 * Deletes only the form definition. Submissions are kept on purpose
	 * (PLAN.md 20.3) - clearing them is a separate, explicit archive action.
	 */
	public function delete_form( int $id ): bool {
		if ( null === $this->get_form( $id ) ) {
			return false;
		}
		global $wpdb;
		$deleted = false !== $wpdb->delete( "{$wpdb->prefix}uxstudio_forms", array( 'id' => $id ), array( '%d' ) );
		if ( $deleted ) {
			ActivityLog::log( 'forms', 'delete', 'form', $id );
		}
		return $deleted;
	}

	/**
	 * Also deletes all of the form's submissions + attached files. Called
	 * from a dedicated REST route so the UI can require explicit, separate
	 * confirmation from a plain "delete form" action.
	 */
	public function delete_form_and_submissions( int $id ): bool {
		Submissions::delete_for_form( $id );
		return $this->delete_form( $id );
	}

	// =====================================================================
	// Public rendering (shortcode)
	// =====================================================================

	/**
	 * [uxstudio_form id="1"] - scoped inline styles, vanilla-JS multi-step
	 * navigation + client-side condition preview (server re-validates
	 * everything at submit, see Submissions::submit()). Pattern mirrors
	 * NoticeBoard::render_shortcode()/PopupManager's front-end delivery -
	 * the public site never loads the admin React bundle.
	 *
	 * @param mixed $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), is_array( $atts ) ? $atts : array(), 'uxstudio_form' );
		$id   = (int) $atts['id'];
		if ( $id <= 0 ) {
			return '';
		}

		$form = $this->get_form( $id );
		if ( null === $form || 'active' !== $form['status'] ) {
			return '';
		}

		return ( new PublicRenderer() )->render( $form );
	}

	// =====================================================================
	// Email action preview (builder "Preview" button)
	// =====================================================================

	/**
	 * Renders one email action with sample data pulled from the form's own
	 * field labels - no real submission required, no email is sent. Reuses
	 * the exact same renderer the real send uses (Actions::run()), so the
	 * preview can never drift from what actually goes out.
	 *
	 * @param array $form   Full form array.
	 * @param array $action Raw (unsaved) action config from the builder.
	 * @return array{subject:string,html:string}
	 */
	public function preview_email( array $form, array $action ): array {
		$fields_snapshot = array();
		$values          = array();
		foreach ( (array) $form['fields'] as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$key  = (string) ( $field['key'] ?? '' );
			if ( '' === $key || in_array( $type, array( 'html', 'step', 'captcha' ), true ) ) {
				continue;
			}
			$fields_snapshot[ $key ] = array(
				'label' => (string) ( $field['label'] ?? $key ),
				'type'  => $type,
			);
			$values[ $key ] = $this->sample_value( $field );
		}

		$context = array(
			'form_title'      => (string) ( $form['title'] ?? '' ),
			'submission_date' => current_time( 'mysql' ),
			'fields_snapshot' => $fields_snapshot,
			'values'          => $values,
		);

		$subject = EmailTemplateRenderer::merge_tags( sanitize_text_field( (string) ( $action['subject'] ?? '' ) ) ?: (string) ( $form['title'] ?? '' ), $context );
		$template  = EmailTemplateRenderer::is_valid_template( (string) ( $action['template'] ?? '' ) ) ? (string) $action['template'] : 'branded';
		$body_html = wpautop( EmailTemplateRenderer::merge_tags( wp_kses_post( (string) ( $action['message'] ?? '' ) ), $context ) );

		$html = EmailTemplateRenderer::render(
			$template,
			array(
				'heading'         => (string) ( $form['title'] ?? '' ),
				'body_html'       => $body_html,
				'include_table'   => ! isset( $action['include_table'] ) || ! empty( $action['include_table'] ),
				'fields_snapshot' => $fields_snapshot,
				'values'          => $values,
				'cta_text'        => sanitize_text_field( (string) ( $action['cta_text'] ?? '' ) ),
				'cta_url'         => esc_url_raw( (string) ( $action['cta_url'] ?? '' ) ),
			)
		);

		return array(
			'subject' => $subject,
			'html'    => $html,
		);
	}

	/**
	 * @param array $field Field definition.
	 * @return mixed Realistic placeholder value for the email preview.
	 */
	private function sample_value( array $field ) {
		switch ( (string) ( $field['type'] ?? '' ) ) {
			case 'checkbox':
			case 'acceptance':
				return '1';
			case 'checkbox_group':
			case 'multiselect':
				return array_slice( array_column( (array) ( $field['options'] ?? array() ), 'label' ), 0, 2 );
			case 'select':
			case 'radio':
				return (string) ( $field['options'][0]['label'] ?? '' );
			case 'number':
				return '42';
			case 'email':
				return 'jana.novakova@example.com';
			case 'date':
				return current_time( 'Y-m-d' );
			default:
				return (string) ( $field['label'] ?? $field['key'] ?? '' );
		}
	}

	// =====================================================================
	// Internal helpers
	// =====================================================================

	/**
	 * @param array $row Raw DB row.
	 * @return array<string, mixed>
	 */
	private function row_to_array( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'title'        => (string) $row['title'],
			'description'  => (string) $row['description'],
			'fields'       => (array) ( json_decode( (string) $row['fields_json'], true ) ?: array() ),
			'settings'     => $this->sanitize_settings( (array) ( json_decode( (string) $row['settings_json'], true ) ?: array() ) ),
			'status'       => (string) $row['status'],
			'created_by'   => (int) $row['created_by'],
			'created_at'   => (string) $row['created_at'],
			'updated_at'   => (string) $row['updated_at'],
		);
	}

	private function sanitize_status( $status ): string {
		$status = (string) $status;
		return in_array( $status, array( 'active', 'draft', 'archived' ), true ) ? $status : 'draft';
	}

	/**
	 * @param mixed $settings Raw settings payload.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $settings ): array {
		$settings = is_array( $settings ) ? $settings : array();

		$label_display = in_array( $settings['label_display'] ?? 'visible', array( 'visible', 'placeholder_only' ), true ) ? $settings['label_display'] : 'visible';
		$progress      = in_array( $settings['progress_style'] ?? 'steps', array( 'steps', 'bar', 'none' ), true ) ? $settings['progress_style'] : 'steps';
		$success_text  = sanitize_textarea_field( (string) ( $settings['success_text'] ?? __( 'Thank you, your submission has been received.', 'ux-studio' ) ) );

		$actions = array();
		foreach ( (array) ( $settings['actions'] ?? array() ) as $action ) {
			if ( ! is_array( $action ) || 'email' !== ( $action['type'] ?? '' ) ) {
				continue;
			}
			$actions[] = array(
				'type'           => 'email',
				'to'             => sanitize_text_field( (string) ( $action['to'] ?? '' ) ),
				'subject'        => sanitize_text_field( (string) ( $action['subject'] ?? '' ) ),
				'message'        => wp_kses_post( (string) ( $action['message'] ?? '' ) ),
				'template'       => EmailTemplateRenderer::is_valid_template( (string) ( $action['template'] ?? '' ) ) ? $action['template'] : 'branded',
				'include_table'  => ! isset( $action['include_table'] ) || ! empty( $action['include_table'] ),
				'cta_text'       => sanitize_text_field( (string) ( $action['cta_text'] ?? '' ) ),
				'cta_url'        => esc_url_raw( (string) ( $action['cta_url'] ?? '' ) ),
			);
			if ( count( $actions ) >= 5 ) {
				break;
			}
		}

		return array(
			'label_display'  => $label_display,
			'progress_style' => $progress,
			'success_text'   => $success_text,
			'captcha_enabled' => ! empty( $settings['captcha_enabled'] ),
			'actions'        => $actions,
		);
	}
}
