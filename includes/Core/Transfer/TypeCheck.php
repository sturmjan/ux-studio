<?php
/**
 * Strict type check of an imported value against the field type of the module's settings schema.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Modules\DashboardWidgets\WidgetRules;

defined( 'ABSPATH' ) || exit;

/**
 * `Settings::clean()` is forgiving on purpose (it casts what a form sends). A file is different: it is
 * input from outside, so a value of the wrong JSON type is REJECTED for that key (with a message) instead
 * of being cast into something nobody wrote. What passes is later cleaned by the module's own save path.
 *
 * Pure (no WordPress): tests/transfer-import-rules.php.
 */
final class TypeCheck {

	/** Longest text value accepted for one key. */
	public const MAX_TEXT = 200000;

	/** Most items accepted in a multiselect. */
	public const MAX_ITEMS = 500;

	/**
	 * Check one value.
	 *
	 * @param mixed                $value Decoded value from the file.
	 * @param array<string, mixed> $field Schema field.
	 * @return array{ok:bool,value:mixed,error:string,dropped:int} `dropped`: multiselect items this site does not know.
	 */
	public static function check( $value, array $field ): array {
		$type = (string) ( $field['type'] ?? 'text' );
		$bad  = static fn( string $message ): array => array(
			'ok'      => false,
			'value'   => null,
			'error'   => $message,
			'dropped' => 0,
		);
		$good = static fn( $v, int $dropped = 0 ): array => array(
			'ok'      => true,
			'value'   => $v,
			'error'   => '',
			'dropped' => $dropped,
		);

		switch ( $type ) {
			case 'toggle':
				return is_bool( $value ) ? $good( $value ) : $bad( __( 'Expected true or false.', 'ux-studio' ) );

			case 'number':
				if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
					return $good( $value );
				}
				return $bad( __( 'Expected a number.', 'ux-studio' ) );

			case 'select':
				if ( ! is_string( $value ) && ! is_int( $value ) ) {
					return $bad( __( 'Expected one of the listed choices.', 'ux-studio' ) );
				}
				$allowed = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
				if ( $allowed && ! in_array( (string) $value, $allowed, true ) ) {
					return $bad( __( 'This choice does not exist on this site.', 'ux-studio' ) );
				}
				return $good( (string) $value );

			case 'multiselect':
				if ( ! is_array( $value ) || ( array() !== $value && ! array_is_list( $value ) ) ) {
					return $bad( __( 'Expected a list of choices.', 'ux-studio' ) );
				}
				if ( count( $value ) > self::MAX_ITEMS ) {
					return $bad( __( 'The list is too long.', 'ux-studio' ) );
				}
				$allowed = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
				$kept    = array();
				$dropped = 0;
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) && ! is_int( $item ) ) {
						return $bad( __( 'Expected a list of choices.', 'ux-studio' ) );
					}
					if ( $allowed && ! in_array( (string) $item, $allowed, true ) ) {
						++$dropped; // E.g. a role or post type this site does not have.
						continue;
					}
					$kept[] = (string) $item;
				}
				return $good( array_values( array_unique( $kept ) ), $dropped );

			case 'widget_rules':
				if ( ! is_array( $value ) ) {
					return $bad( __( 'Expected a list of dashboard widget rules.', 'ux-studio' ) );
				}
				if ( count( $value ) > WidgetRules::MAX_ENTRIES ) {
					return $bad( __( 'The list is too long.', 'ux-studio' ) );
				}
				foreach ( $value as $entry ) {
					if ( ! is_array( $entry ) || ( isset( $entry['hidden'] ) && ! is_bool( $entry['hidden'] ) ) || ( isset( $entry['roles'] ) && ( ! is_array( $entry['roles'] ) || ( array() !== $entry['roles'] && ! array_is_list( $entry['roles'] ) ) ) ) ) {
						return $bad( __( 'Expected a list of dashboard widget rules.', 'ux-studio' ) );
					}
				}
				$allowed = array_map( 'strval', array_keys( (array) ( $field['options'] ?? array() ) ) );
				$report  = array();
				$clean   = WidgetRules::sanitize( $value, $allowed ?: null, $report );
				return $good( $clean, $report['dropped_roles'] + $report['dropped_ids'] );

			case 'color':
				if ( ! is_string( $value ) ) {
					return $bad( __( 'Expected a colour such as #1a2b3c.', 'ux-studio' ) );
				}
				return '' === $value || 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value )
					? $good( $value )
					: $bad( __( 'Expected a colour such as #1a2b3c.', 'ux-studio' ) );

			case 'media':
				// Resolved before this check (see Planner::resolve_media); what arrives here is an attachment id or 0.
				return is_int( $value ) && $value >= 0 ? $good( $value ) : $bad( __( 'Expected an image.', 'ux-studio' ) );

			case 'datetime':
				if ( ! is_string( $value ) ) {
					return $bad( __( 'Expected a date and time.', 'ux-studio' ) );
				}
				return '' === $value || 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value )
					? $good( $value )
					: $bad( __( 'Expected a date and time.', 'ux-studio' ) );

			case 'text':
			case 'textarea':
			case 'richtext':
			case 'css':
			default:
				if ( ! is_string( $value ) ) {
					return $bad( __( 'Expected text.', 'ux-studio' ) );
				}
				if ( strlen( $value ) > self::MAX_TEXT ) {
					return $bad( __( 'The text is too long.', 'ux-studio' ) );
				}
				return false !== strpos( $value, "\0" ) ? $bad( __( 'The text contains a forbidden character.', 'ux-studio' ) ) : $good( $value );
		}
	}
}
