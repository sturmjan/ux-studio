<?php
/**
 * Version history for a form's definition (title/fields/settings) - PLAN.md
 * 20.11/F3 "revize definice formuláře (historie verzí formuláře s možností
 * obnovit starší verzi)".
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * A snapshot is written by Module::update_form() right before an update is
 * applied - so every stored revision is a point the form definition actually
 * was at, in save order, mirroring how WordPress' own post revisions capture
 * the state that is about to be replaced. Restoring a revision snapshots the
 * current (about-to-be-overwritten) state first, so a restore is itself
 * always undoable - never a one-way door.
 */
final class Revisions {

	/** Keep at most this many revisions per form; oldest beyond that are pruned. */
	private const MAX_PER_FORM = 30;

	/**
	 * @param array $snapshot { title, fields, settings } - already-sanitized shape (Module::row_to_array()).
	 */
	public static function snapshot( int $form_id, array $snapshot ): void {
		if ( $form_id <= 0 ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_form_revisions",
			array(
				'form_id'       => $form_id,
				'title'         => sanitize_text_field( (string) ( $snapshot['title'] ?? '' ) ),
				'fields_json'   => wp_json_encode( (array) ( $snapshot['fields'] ?? array() ) ),
				'settings_json' => wp_json_encode( (array) ( $snapshot['settings'] ?? array() ) ),
				'created_by'    => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		self::prune( $form_id );
	}

	/**
	 * Newest-first summary list for the Revisions tab.
	 *
	 * @return array<int, array{id:int,title:string,field_count:int,created_by:int,created_by_name:string,created_at:string}>
	 */
	public static function list_for( int $form_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, fields_json, created_by, created_at FROM {$wpdb->prefix}uxstudio_form_revisions WHERE form_id = %d ORDER BY id DESC",
				$form_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			static function ( array $row ): array {
				$fields = (array) ( json_decode( (string) $row['fields_json'], true ) ?: array() );
				$user   = get_userdata( (int) $row['created_by'] );
				return array(
					'id'              => (int) $row['id'],
					'title'           => (string) $row['title'],
					'field_count'     => count( $fields ),
					'created_by'      => (int) $row['created_by'],
					'created_by_name' => $user ? $user->display_name : __( '(unknown user)', 'ux-studio' ),
					'created_at'      => (string) $row['created_at'],
				);
			},
			$rows
		);
	}

	/**
	 * Full snapshot content for one revision (scoped to the given form so a
	 * revision id can never be used to read another form's data).
	 *
	 * @return array{id:int,title:string,fields:array,settings:array,created_at:string}|null
	 */
	public static function get( int $form_id, int $revision_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, title, fields_json, settings_json, created_at FROM {$wpdb->prefix}uxstudio_form_revisions WHERE id = %d AND form_id = %d",
				$revision_id,
				$form_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		return array(
			'id'         => (int) $row['id'],
			'title'      => (string) $row['title'],
			'fields'     => (array) ( json_decode( (string) $row['fields_json'], true ) ?: array() ),
			'settings'   => (array) ( json_decode( (string) $row['settings_json'], true ) ?: array() ),
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Drops the oldest rows beyond MAX_PER_FORM for one form - keeps the
	 * table bounded without needing a separate cron job.
	 */
	private static function prune( int $form_id ): void {
		global $wpdb;
		$table = "{$wpdb->prefix}uxstudio_form_revisions";
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d",
				$form_id,
				self::MAX_PER_FORM
			)
		);
		if ( empty( $ids ) ) {
			return;
		}
		$ids_sql = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_sql is an intval()-filtered list, not user input.
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$ids_sql})" );
	}

	/**
	 * Deletes every revision for a form - called when the form definition
	 * itself is deleted (Module::delete_form()), same lifecycle as the form.
	 */
	public static function delete_for_form( int $form_id ): void {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_form_revisions", array( 'form_id' => $form_id ), array( '%d' ) );
	}
}
