<?php
/**
 * Shared CSV helpers.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Formula-injection guard shared by every CSV export (same rule as
 * Forms\Csv::safe_cell, which predates this helper).
 */
final class Csv {

	/**
	 * Formula-injection protection (OWASP): a cell whose first character
	 * could be interpreted by Excel/Sheets as a formula (=, +, -, @, tab, CR)
	 * is prefixed with an apostrophe so it always opens as plain text.
	 *
	 * @param mixed $value Cell value (scalars are cast to string).
	 */
	public static function safe_cell( $value ): string {
		$value = is_scalar( $value ) || null === $value ? (string) $value : '';
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * safe_cell() applied to a whole row.
	 *
	 * @param array $row Row values.
	 * @return string[]
	 */
	public static function safe_row( array $row ): array {
		return array_map( array( __CLASS__, 'safe_cell' ), array_values( $row ) );
	}
}
