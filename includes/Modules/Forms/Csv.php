<?php
/**
 * CSV export for the submissions archive, with formula-injection protection.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a CSV of submissions directly to output and terminates the
 * request - ported 1:1 from the Destima Forms pattern (same
 * formula-injection guard), adapted to UxStudio's submission row shape.
 */
final class Csv {

	/**
	 * @param object[] $rows        Submission rows ($wpdb objects: id, form_title,
	 *                               values_json, fields_snapshot_json, status, created_at).
	 * @param string   $export_name Base filename (already slugified by the caller).
	 */
	public static function stream( array $rows, string $export_name ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $export_name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		// UTF-8 BOM so Excel on Windows doesn't mangle diacritics.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		// Union of every field label that appears across the exported rows,
		// in first-seen order, so a mixed export (e.g. "all forms") still
		// gets one coherent column set instead of only the first row's fields.
		$columns = array();
		$decoded = array();
		foreach ( $rows as $row ) {
			$snapshot = json_decode( (string) $row->fields_snapshot_json, true );
			$values   = json_decode( (string) $row->values_json, true );
			$snapshot = is_array( $snapshot ) ? $snapshot : array();
			$values   = is_array( $values ) ? $values : array();
			foreach ( $snapshot as $key => $meta ) {
				if ( ! isset( $columns[ $key ] ) ) {
					$columns[ $key ] = is_array( $meta ) ? (string) ( $meta['label'] ?? $key ) : (string) $key;
				}
			}
			$decoded[] = array( 'snapshot' => $snapshot, 'values' => $values );
		}

		$header = array( __( 'ID', 'ux-studio' ), __( 'Form', 'ux-studio' ) );
		foreach ( $columns as $label ) {
			$header[] = $label;
		}
		$header[] = __( 'Status', 'ux-studio' );
		$header[] = __( 'Submitted at', 'ux-studio' );
		fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), $header ), ';', '"', '\\' );

		foreach ( $rows as $i => $row ) {
			$line = array( (string) $row->id, (string) $row->form_title );
			foreach ( array_keys( $columns ) as $key ) {
				$meta  = $decoded[ $i ]['snapshot'][ $key ] ?? array();
				$value = $decoded[ $i ]['values'][ $key ] ?? '';
				$line[] = self::format_value( is_array( $meta ) ? (string) ( $meta['type'] ?? '' ) : '', $value );
			}
			$line[] = (string) $row->status;
			$line[] = (string) $row->created_at;
			fputcsv( $out, array_map( array( __CLASS__, 'safe_cell' ), $line ), ';', '"', '\\' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Formula-injection protection (OWASP): a cell whose first character
	 * could be interpreted by Excel/Sheets as a formula (=, +, -, @, tab, CR)
	 * is prefixed with an apostrophe so it always opens as plain text.
	 */
	public static function safe_cell( string $value ): string {
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param mixed $value Raw stored value for one field.
	 */
	private static function format_value( string $type, $value ): string {
		switch ( $type ) {
			case 'checkbox':
			case 'acceptance':
				return $value ? __( 'yes', 'ux-studio' ) : __( 'no', 'ux-studio' );
			case 'checkbox_group':
			case 'multiselect':
				return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			case 'file':
			case 'signature':
				if ( is_array( $value ) ) {
					$names = array_map(
						static fn( $f ) => is_array( $f ) ? (string) ( $f['original_name'] ?? '' ) : '',
						array_is_list( $value ) ? $value : array( $value )
					);
					return implode( ', ', array_filter( $names ) );
				}
				return '';
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}
}
