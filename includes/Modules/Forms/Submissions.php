<?php
/**
 * Public submit handling (validation + storage) and the admin-side
 * submissions archive queries.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * `submit()` is the ONLY place a row is written to uxstudio_form_submissions -
 * both the public REST route and any future channel funnel through it, so
 * validation/honeypot/search_text can never drift between callers.
 */
final class Submissions {

	/** Fixed honeypot field name - never part of a form's own fields[], always rendered hidden by the public template. */
	public const HONEYPOT_FIELD = '_uxs_hp';

	public const STATUSES = array( 'unread', 'read', 'spam', 'trash' );

	/**
	 * Validate + store one submission.
	 *
	 * @param array  $form     Full form array (id, title, fields, settings).
	 * @param array  $input    Raw POSTed field values, keyed by field key.
	 * @param array  $files    Raw $_FILES-shaped entries, keyed by "file_{field_key}" (single) - see REST controller.
	 * @param string $honeypot Raw value of the honeypot field (a plain multipart field, NOT part of $input - see REST controller).
	 * @return array{id:int,values:array,fields_snapshot:array,created_at:string}|WP_Error
	 */
	public static function submit( array $form, array $input, array $files, string $honeypot = '' ) {
		// Honeypot: a hidden field a real visitor never sees or fills. Any
		// value in it means a bot filled every field indiscriminately -
		// silently accept (no error the bot can learn from) but never store.
		$honeypot = trim( $honeypot );
		if ( '' !== $honeypot ) {
			return array(
				'id'              => 0,
				'values'          => array(),
				'fields_snapshot' => array(),
				'created_at'      => current_time( 'mysql' ),
				'discarded'       => true,
			);
		}

		$fields = (array) ( $form['fields'] ?? array() );
		$clean  = array();
		$errors = array();
		$stored_files = array(); // stored_name => field meta, for rollback on validation failure.

		foreach ( $fields as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$key  = (string) ( $field['key'] ?? '' );
			if ( '' === $key || in_array( $type, Fields::LAYOUT_TYPES, true ) || 'captcha' === $type ) {
				continue;
			}

			$active = Fields::is_active( $field, $input );

			if ( 'file' === $type ) {
				if ( ! $active ) {
					continue;
				}
				$result = self::handle_file_field( $field, $files );
				if ( is_wp_error( $result ) ) {
					$errors[ $key ] = $result->get_error_message();
					continue;
				}
				if ( null === $result ) {
					if ( ! empty( $field['required'] ) ) {
						$errors[ $key ] = __( 'This field is required.', 'ux-studio' );
					}
					continue;
				}
				foreach ( (array) $result as $one ) {
					$stored_files[ $one['stored_name'] ] = $one;
				}
				$clean[ $key ] = $result;
				continue;
			}

			if ( 'signature' === $type ) {
				if ( ! $active ) {
					continue;
				}
				$raw = (string) ( $input[ $key ] ?? '' );
				if ( '' === trim( $raw ) ) {
					if ( ! empty( $field['required'] ) ) {
						$errors[ $key ] = __( 'A signature is required.', 'ux-studio' );
					}
					continue;
				}
				$result = self::handle_signature_field( $raw );
				if ( is_wp_error( $result ) ) {
					$errors[ $key ] = $result->get_error_message();
					continue;
				}
				$stored_files[ $result['stored_name'] ] = $result;
				$clean[ $key ]                          = $result;
				continue;
			}

			if ( 'hidden' === $type ) {
				if ( ! $active ) {
					continue;
				}
				$clean[ $key ] = Fields::resolve_default_token( (string) ( $field['default_value'] ?? '' ) );
				continue;
			}

			$value = $input[ $key ] ?? '';
			$value = self::sanitize_value( $type, $value );

			if ( ! $active ) {
				continue;
			}

			$error = self::validate_value( $field, $value );
			if ( null !== $error ) {
				$errors[ $key ] = $error;
			}

			$clean[ $key ] = $value;
		}

		if ( ! empty( $errors ) ) {
			foreach ( array_keys( $stored_files ) as $stored_name ) {
				FileStorage::delete( $stored_name );
			}
			return new WP_Error( 'uxstudio_forms_validation', __( 'Please check the highlighted fields.', 'ux-studio' ), array(
				'status' => 422,
				'fields' => $errors,
			) );
		}

		$snapshot = array();
		foreach ( $fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );
			if ( '' === $key || in_array( $field['type'] ?? '', Fields::LAYOUT_TYPES, true ) ) {
				continue;
			}
			$snapshot[ $key ] = array(
				'label' => (string) ( $field['label'] ?? $key ),
				'type'  => (string) ( $field['type'] ?? '' ),
			);
		}

		global $wpdb;
		$now = current_time( 'mysql' );

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_form_submissions",
			array(
				'form_id'              => (int) $form['id'],
				'form_title'           => (string) $form['title'],
				'values_json'          => wp_json_encode( $clean ),
				'fields_snapshot_json' => wp_json_encode( $snapshot ),
				'meta_json'            => wp_json_encode( self::request_meta() ),
				'search_text'          => self::build_search_text( $snapshot, $clean ),
				'status'               => 'unread',
				'created_at'           => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$submission_id = (int) $wpdb->insert_id;

		foreach ( $clean as $key => $value ) {
			$items = is_array( $value ) && isset( $value['stored_name'] ) ? array( $value ) : ( is_array( $value ) && array_is_list( $value ) && isset( $value[0]['stored_name'] ) ? $value : array() );
			foreach ( $items as $file_meta ) {
				$wpdb->insert(
					"{$wpdb->prefix}uxstudio_form_submission_files",
					array(
						'submission_id' => $submission_id,
						'field_key'     => $key,
						'original_name' => (string) $file_meta['original_name'],
						'stored_path'   => (string) $file_meta['stored_name'],
						'mime'          => (string) $file_meta['mime'],
						'size'          => (int) $file_meta['size'],
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d' )
				);
			}
		}

		return array(
			'id'              => $submission_id,
			'values'          => $clean,
			'fields_snapshot' => $snapshot,
			'created_at'      => $now,
		);
	}

	/**
	 * @param array $field One 'file' field definition.
	 * @param array $files Raw file params, keyed "file_{key}" or "file_{key}[]" for multiple.
	 * @return array|array[]|WP_Error|null Null = no file supplied.
	 */
	private static function handle_file_field( array $field, array $files ) {
		$key    = (string) $field['key'];
		$accept = (array) ( $field['accept'] ?? array() );
		$max_mb = (int) ( $field['max_size_mb'] ?? FileStorage::DEFAULT_MAX_MB );

		$entry = $files[ 'file_' . $key ] ?? null;
		if ( empty( $entry ) || empty( $entry['name'] ) ) {
			return null;
		}

		// Normalize a possibly multi-file $_FILES entry (PHP's classic
		// array-of-arrays shape for `name`/`tmp_name`/...) into a flat list.
		$list = array();
		if ( is_array( $entry['name'] ) ) {
			foreach ( $entry['name'] as $i => $name ) {
				if ( '' === $name ) {
					continue;
				}
				$list[] = array(
					'name'     => $name,
					'type'     => $entry['type'][ $i ] ?? '',
					'tmp_name' => $entry['tmp_name'][ $i ] ?? '',
					'error'    => $entry['error'][ $i ] ?? UPLOAD_ERR_NO_FILE,
					'size'     => $entry['size'][ $i ] ?? 0,
				);
			}
		} else {
			$list[] = $entry;
		}

		if ( empty( $list ) ) {
			return null;
		}
		if ( empty( $field['multiple'] ) ) {
			$list = array( $list[0] );
		}

		$stored = array();
		foreach ( $list as $one ) {
			$result = FileStorage::store( $one, $accept, $max_mb );
			if ( is_wp_error( $result ) ) {
				foreach ( $stored as $s ) {
					FileStorage::delete( $s['stored_name'] );
				}
				return $result;
			}
			$stored[] = $result;
		}

		return ! empty( $field['multiple'] ) ? $stored : $stored[0];
	}

	/**
	 * Decode+validate a `signature` field's data URI (a small canvas PNG drawn
	 * client-side, see PublicRenderer's runtime script) and store it exactly
	 * like a `file` field upload - same private, deny-all directory, same
	 * capability-gated download route, same submission_files row shape
	 * (PLAN.md 20.11/F4). Never trusts the client's declared MIME - the bytes
	 * themselves are re-validated via getimagesize() in FileStorage::store_binary().
	 *
	 * @param string $data_uri Raw `data:image/png;base64,...` string.
	 * @return array{stored_name:string,original_name:string,mime:string,size:int}|WP_Error
	 */
	private static function handle_signature_field( string $data_uri ) {
		if ( ! preg_match( '/^data:image\/png;base64,([a-zA-Z0-9+\/=]+)$/', trim( $data_uri ), $matches ) ) {
			return new WP_Error( 'uxstudio_forms_signature_invalid', __( 'Invalid signature data.', 'ux-studio' ) );
		}
		$binary = base64_decode( $matches[1], true );
		if ( false === $binary || '' === $binary ) {
			return new WP_Error( 'uxstudio_forms_signature_invalid', __( 'Invalid signature data.', 'ux-studio' ) );
		}
		// A signature is a small canvas PNG - cap well below the generic file
		// upload limit to guard against an oversized/malicious payload.
		if ( strlen( $binary ) > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'uxstudio_forms_signature_too_large', __( 'The signature image is too large.', 'ux-studio' ) );
		}
		return FileStorage::store_binary( $binary, 'signature.png', 'png', 'image/png' );
	}

	/**
	 * @param mixed $value Raw input.
	 * @return mixed Sanitized value (scalar or array for choice groups).
	 */
	private static function sanitize_value( string $type, $value ) {
		switch ( $type ) {
			case 'checkbox':
			case 'acceptance':
				return $value ? '1' : '';
			case 'checkbox_group':
			case 'multiselect':
				$raw = is_array( $value ) ? $value : array();
				return array_values( array_map( 'sanitize_text_field', $raw ) );
			case 'email':
				return sanitize_email( (string) $value );
			case 'url':
				return esc_url_raw( (string) $value );
			case 'textarea':
			case 'html':
				return sanitize_textarea_field( (string) $value );
			case 'number':
				return '' === (string) $value ? '' : (float) $value;
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * @param array $field Sanitized field definition.
	 * @param mixed $value Already-sanitized value.
	 */
	private static function validate_value( array $field, $value ): ?string {
		$type     = (string) ( $field['type'] ?? '' );
		$required = ! empty( $field['required'] );
		$is_empty = is_array( $value ) ? empty( $value ) : ( '' === trim( (string) $value ) );

		if ( $required && $is_empty ) {
			return __( 'This field is required.', 'ux-studio' );
		}
		if ( $is_empty ) {
			return null;
		}

		if ( 'email' === $type && ! is_email( (string) $value ) ) {
			return __( 'Invalid email address.', 'ux-studio' );
		}
		if ( 'url' === $type && ! wp_http_validate_url( (string) $value ) ) {
			return __( 'Invalid URL.', 'ux-studio' );
		}
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$allowed = array_column( (array) ( $field['options'] ?? array() ), 'value' );
			if ( ! in_array( (string) $value, $allowed, true ) ) {
				return __( 'Invalid choice.', 'ux-studio' );
			}
		}
		if ( in_array( $type, array( 'checkbox_group', 'multiselect' ), true ) ) {
			$allowed = array_column( (array) ( $field['options'] ?? array() ), 'value' );
			foreach ( (array) $value as $v ) {
				if ( ! in_array( (string) $v, $allowed, true ) ) {
					return __( 'Invalid choice.', 'ux-studio' );
				}
			}
		}
		if ( 'number' === $type ) {
			if ( null !== $field['min'] && (float) $value < (float) $field['min'] ) {
				return __( 'Value is too low.', 'ux-studio' );
			}
			if ( null !== $field['max'] && (float) $value > (float) $field['max'] ) {
				return __( 'Value is too high.', 'ux-studio' );
			}
		}
		if ( in_array( $type, array( 'text', 'textarea', 'tel' ), true ) ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
			if ( ! empty( $field['minlength'] ) && $len < (int) $field['minlength'] ) {
				return __( 'Value is too short.', 'ux-studio' );
			}
			if ( ! empty( $field['maxlength'] ) && $len > (int) $field['maxlength'] ) {
				return __( 'Value is too long.', 'ux-studio' );
			}
		}
		if ( 'acceptance' === $type && $required && '1' !== $value ) {
			return __( 'You must accept this to continue.', 'ux-studio' );
		}

		return null;
	}

	/**
	 * Plain-text extract of every textual value, for FULLTEXT search - never
	 * includes file metadata or password-type fields.
	 */
	private static function build_search_text( array $snapshot, array $values ): string {
		$parts = array();
		foreach ( $values as $key => $value ) {
			$type = (string) ( $snapshot[ $key ]['type'] ?? '' );
			if ( in_array( $type, array_merge( Fields::FILE_LIKE_TYPES, array( 'password' ) ), true ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$parts[] = implode( ' ', array_map( 'strval', $value ) );
			} elseif ( is_scalar( $value ) ) {
				$parts[] = (string) $value;
			}
		}
		return wp_strip_all_tags( implode( ' ', $parts ) );
	}

	/**
	 * @return array{ip_hash:string,user_agent:string,referrer:string,page_url:string}
	 */
	private static function request_meta(): array {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return array(
			'ip_hash'    => $ip ? hash( 'sha256', $ip . wp_salt() ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'page_url'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
		);
	}

	// =====================================================================
	// Admin archive queries
	// =====================================================================

	/**
	 * @param array $args { form_id?, status?, q?, from?, to?, page, per_page }.
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_form_submissions";

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		} else {
			// Default view excludes trash, same convention as an email inbox.
			$where[] = "status != 'trash'";
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['from'] ) ?: 0 );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['to'] . ' 23:59:59' ) ?: 0 );
		}

		$q = trim( (string) ( $args['q'] ?? '' ) );
		if ( '' !== $q ) {
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $q ) >= 3 : strlen( $q ) >= 3 ) {
				$boolean_query = '+' . implode( ' +', array_map( static fn( $w ) => $w . '*', preg_split( '/\s+/', $q ) ?: array() ) );
				$where[]       = 'MATCH(search_text) AGAINST (%s IN BOOLEAN MODE)';
				$params[]      = $boolean_query;
			} else {
				$where[]  = 'search_text LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $q ) . '%';
			}
		}

		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( empty( $params ) ? $wpdb->get_var( $total_sql ) : $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;
		$list_sql      = "SELECT id, form_id, form_title, values_json, fields_snapshot_json, meta_json, status, created_at FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$rows          = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = array_map( array( __CLASS__, 'row_to_array' ), is_array( $rows ) ? $rows : array() );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * @return object[] Raw $wpdb rows, for CSV export (same filters as query()).
	 */
	public static function query_raw_for_export( array $args ): array {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_form_submissions";

		$where  = array( "status != 'trash'" );
		$params = array();

		if ( ! empty( $args['form_id'] ) ) {
			$where[]  = 'form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			array_shift( $where ); // status filter replaces the default trash exclusion.
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = 'search_text LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['from'] ) ?: 0 );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['to'] . ' 23:59:59' ) ?: 0 );
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT id, form_title, values_json, fields_snapshot_json, status, created_at FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT 10000";
		$rows      = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, form_id, form_title, values_json, fields_snapshot_json, meta_json, status, created_at FROM {$wpdb->prefix}uxstudio_form_submissions WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::row_to_array( $row ) : null;
	}

	/**
	 * @return array<int, array{id:int,field_key:string,original_name:string,mime:string,size:int}>
	 */
	public static function files_for( int $submission_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, field_key, original_name, stored_path, mime, size FROM {$wpdb->prefix}uxstudio_form_submission_files WHERE submission_id = %d",
				$submission_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function set_status( int $id, string $status ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		global $wpdb;
		return false !== $wpdb->update(
			"{$wpdb->prefix}uxstudio_form_submissions",
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		foreach ( self::files_for( $id ) as $file ) {
			FileStorage::delete( (string) $file['stored_path'] );
		}
		$wpdb->delete( "{$wpdb->prefix}uxstudio_form_submission_files", array( 'submission_id' => $id ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}uxstudio_form_action_log", array( 'submission_id' => $id ), array( '%d' ) );
		return false !== $wpdb->delete( "{$wpdb->prefix}uxstudio_form_submissions", array( 'id' => $id ), array( '%d' ) );
	}

	/** Delete every submission (+ files) belonging to a form (form deletion is a separate, explicit action - see Module::delete_form()). */
	public static function delete_for_form( int $form_id ): void {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = %d", $form_id ) );
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
	}

	/**
	 * Delete a form's submissions (+ files/action log, via delete()) older
	 * than `$days` - the GDPR/retention cron (PLAN.md 20.2/20.6/F4,
	 * Retention::run()). Never touches forms with no retention configured -
	 * that decision is made by the caller (it never passes $days <= 0 here).
	 *
	 * @return int Number of submissions deleted.
	 */
	public static function delete_older_than( int $form_id, int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( $days * DAY_IN_SECONDS ) );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}uxstudio_form_submissions WHERE form_id = %d AND created_at < %s",
				$form_id,
				$cutoff
			)
		);
		foreach ( $ids as $id ) {
			self::delete( (int) $id );
		}
		return count( $ids );
	}

	public static function count_unread(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_form_submissions WHERE status = 'unread'" );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_id, form_title, values_json, status, created_at FROM {$wpdb->prefix}uxstudio_form_submissions WHERE status != 'trash' ORDER BY created_at DESC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array $row Raw DB row.
	 * @return array<string, mixed>
	 */
	private static function row_to_array( array $row ): array {
		return array(
			'id'               => (int) $row['id'],
			'form_id'          => (int) $row['form_id'],
			'form_title'       => (string) $row['form_title'],
			'values'           => (array) ( json_decode( (string) $row['values_json'], true ) ?: array() ),
			'fields_snapshot'  => (array) ( json_decode( (string) $row['fields_snapshot_json'], true ) ?: array() ),
			'meta'             => (array) ( json_decode( (string) ( $row['meta_json'] ?? '' ), true ) ?: array() ),
			'status'           => (string) $row['status'],
			'created_at'       => (string) $row['created_at'],
		);
	}
}
