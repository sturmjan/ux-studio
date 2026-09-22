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
	private const DB_VERSION = 2;

	public function boot(): void {
		DB::ensure_module_tables( 'forms', self::DB_VERSION, array( $this, 'migrate' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_filter( 'uxstudio_rest_public_routes', array( $this, 'allow_public_routes' ) );

		DashboardWidget::register();
		GutenbergBlock::register( $this );
		// Only ever fires when Elementor itself calls it - see ElementorWidget's
		// class docblock for why that makes the `extends \Elementor\Widget_Base`
		// safe without Elementor being present (PLAN.md 20.7/F3).
		ElementorWidget::register( $this );
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

		// v2 (F3, PLAN.md 20.11): form definition revision history.
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}uxstudio_form_revisions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				form_id BIGINT UNSIGNED NOT NULL,
				title VARCHAR(190) NOT NULL DEFAULT '',
				fields_json LONGTEXT NOT NULL,
				settings_json LONGTEXT NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY form_id (form_id)
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

		// Snapshot the state that is about to be replaced - but only when the
		// definition itself (title/fields/settings) actually changes; a
		// status-only toggle (e.g. draft -> active) isn't a definition edit
		// and would just spam the history (PLAN.md 20.11/F3 Revisions).
		if ( array_key_exists( 'title', $data ) || array_key_exists( 'fields', $data ) || array_key_exists( 'settings', $data ) ) {
			Revisions::snapshot( $id, $existing );
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
			// Unlike submissions (kept on purpose, PLAN.md 20.3), revision
			// history has no value once the definition it documents is gone.
			Revisions::delete_for_form( $id );
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
	// Revisions (PLAN.md 20.11/F3)
	// =====================================================================

	/**
	 * @return array<int, array{id:int,title:string,field_count:int,created_by:int,created_by_name:string,created_at:string}>
	 */
	public function list_revisions( int $form_id ): array {
		return Revisions::list_for( $form_id );
	}

	/**
	 * Restores an older revision as the form's live definition. Snapshots the
	 * current (about-to-be-discarded) state first, so restoring is itself
	 * just another entry in the history - never a one-way, unrecoverable action.
	 */
	public function restore_revision( int $form_id, int $revision_id ): ?array {
		$existing = $this->get_form( $form_id );
		if ( null === $existing ) {
			return null;
		}
		$revision = Revisions::get( $form_id, $revision_id );
		if ( null === $revision ) {
			return null;
		}

		Revisions::snapshot( $form_id, $existing );

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}uxstudio_forms",
			array(
				'title'         => $revision['title'],
				'fields_json'   => wp_json_encode( Fields::sanitize_fields( $revision['fields'] ) ),
				'settings_json' => wp_json_encode( $this->sanitize_settings( $revision['settings'] ) ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $form_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		ActivityLog::log( 'forms', 'restore_revision', 'form', $form_id, array( 'revision_id' => $revision_id ) );

		return $this->get_form( $form_id );
	}

	// =====================================================================
	// AI-assisted field generation (PLAN.md 20.11/F3)
	// =====================================================================

	/**
	 * Drafts fields from a plain-language description via the plugin's
	 * shared AI core (AiAssistant\ContentGenerator - no bespoke AI client
	 * here) and sanitizes the result through the exact same allowlist real
	 * builder input goes through, so an AI response can never smuggle an
	 * invalid type or a key collision into a form (PLAN.md 20.8 baseline:
	 * server-side validation always, never trust the input - AI output is
	 * just another untrusted input).
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error Sanitized field definitions, or an error.
	 */
	public function generate_ai_fields( int $form_id, string $description ) {
		if ( ! class_exists( \UxStudio\Modules\AiAssistant\ContentGenerator::class ) ) {
			return new \WP_Error(
				'uxstudio_forms_ai_unavailable',
				__( 'The AI Assistant module is not active - enable it to use AI form generation.', 'ux-studio' ),
				array( 'status' => 424 )
			);
		}

		$form          = $this->get_form( $form_id );
		$existing_keys = null !== $form ? wp_list_pluck( (array) $form['fields'], 'key' ) : array();

		try {
			$generator = new \UxStudio\Modules\AiAssistant\ContentGenerator();
			$result    = $generator->generate_form_fields( $description );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'uxstudio_forms_ai_failed', $e->getMessage(), array( 'status' => 400 ) );
		}

		$raw_fields = array();
		foreach ( (array) ( $result['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			// The AI returns options as plain strings; Fields::sanitize_field()
			// already accepts that shape (see sanitize_options()).
			$raw_fields[] = $field;
		}

		return Fields::sanitize_fields( $raw_fields, array_map( 'strval', $existing_keys ) );
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

		$subject  = EmailTemplateRenderer::merge_tags( sanitize_text_field( (string) ( $action['subject'] ?? '' ) ) ?: (string) ( $form['title'] ?? '' ), $context );
		$template = (string) ( $action['template'] ?? '' );

		// Custom template (PLAN.md 20.11/F3): the admin's own complete HTML
		// document IS the email body - no built-in wrapper/layout is applied,
		// same rule Actions::run_email() follows for the real send, so a
		// preview can never drift from what actually goes out.
		if ( 'custom' === $template ) {
			return array(
				'subject' => $subject,
				'html'    => EmailTemplateRenderer::merge_tags( EmailTemplateRenderer::sanitize_custom_html( (string) ( $action['custom_html'] ?? '' ) ), $context ),
			);
		}

		$template  = EmailTemplateRenderer::is_valid_template( $template ) ? $template : 'branded';
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
			if ( ! is_array( $action ) ) {
				continue;
			}
			switch ( $action['type'] ?? '' ) {
				case 'email':
					$actions[] = $this->sanitize_email_action( $action );
					break;
				case 'webhook':
					$actions[] = $this->sanitize_webhook_action( $action );
					break;
				case 'redirect':
					$actions[] = $this->sanitize_redirect_action( $action );
					break;
				default:
					// Unknown/future action types (create_post - F4) are dropped.
					continue 2;
			}
			if ( count( $actions ) >= 5 ) {
				break;
			}
		}

		return array(
			'label_display'   => $label_display,
			'progress_style'  => $progress,
			'success_text'    => $success_text,
			'captcha_enabled' => ! empty( $settings['captcha_enabled'] ),
			// Per-form HMAC secret for the webhook action (PLAN.md 20.2/20.8) -
			// generated once and kept stable across saves; never accepted as
			// client input, only ever round-tripped or (re)generated here, so
			// it can never be set to an attacker-chosen value.
			'webhook_secret'  => $this->sanitize_webhook_secret( $settings['webhook_secret'] ?? '' ),
			'actions'         => $actions,
		);
	}

	/**
	 * @param array $action { to, subject, message, template, include_table, cta_text, cta_url, custom_html }.
	 */
	private function sanitize_email_action( array $action ): array {
		$template = (string) ( $action['template'] ?? '' );
		$is_custom = 'custom' === $template;

		return array(
			'type'          => 'email',
			'to'            => sanitize_text_field( (string) ( $action['to'] ?? '' ) ),
			'subject'       => sanitize_text_field( (string) ( $action['subject'] ?? '' ) ),
			'message'       => wp_kses_post( (string) ( $action['message'] ?? '' ) ),
			'template'      => $is_custom || EmailTemplateRenderer::is_valid_template( $template ) ? $template : 'branded',
			'include_table' => ! isset( $action['include_table'] ) || ! empty( $action['include_table'] ),
			'cta_text'      => sanitize_text_field( (string) ( $action['cta_text'] ?? '' ) ),
			'cta_url'       => esc_url_raw( (string) ( $action['cta_url'] ?? '' ) ),
			// User-authored full HTML email document (PLAN.md 20.11/F3 "custom
			// HTML template editor") - only ever used when template === 'custom'
			// (Actions::run_email()/preview_email() below), but always
			// sanitized/stored regardless so switching template back and forth
			// never silently loses what was written.
			'custom_html'   => EmailTemplateRenderer::sanitize_custom_html( (string) ( $action['custom_html'] ?? '' ) ),
		);
	}

	/**
	 * @param array $action { url }.
	 */
	private function sanitize_webhook_action( array $action ): array {
		return array(
			'type' => 'webhook',
			'url'  => esc_url_raw( (string) ( $action['url'] ?? '' ) ),
		);
	}

	/**
	 * @param array $action { url }.
	 */
	private function sanitize_redirect_action( array $action ): array {
		return array(
			'type' => 'redirect',
			'url'  => esc_url_raw( (string) ( $action['url'] ?? '' ) ),
		);
	}

	/**
	 * Keeps an already-valid 64 hex char secret as-is (round-tripped from a
	 * previous read), otherwise generates a fresh cryptographically random
	 * one - so every form always has a stable secret available the moment a
	 * webhook action is added, without ever trusting a client-supplied value.
	 */
	private function sanitize_webhook_secret( $secret ): string {
		$secret = (string) $secret;
		if ( 64 === strlen( $secret ) && ctype_xdigit( $secret ) ) {
			return $secret;
		}
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Minimal { id, title, status } list for pickers that only need
	 * `edit_posts` (e.g. the Gutenberg block's form select) - unlike every
	 * other route here, which stays gated behind `manage_options` since it
	 * exposes full form configuration.
	 *
	 * @return array<int, array{id:int,title:string,status:string}>
	 */
	public function list_form_options(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, title, status FROM {$wpdb->prefix}uxstudio_forms ORDER BY title ASC", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			static function ( array $row ): array {
				return array(
					'id'     => (int) $row['id'],
					'title'  => (string) $row['title'],
					'status' => (string) $row['status'],
				);
			},
			$rows
		);
	}
}
