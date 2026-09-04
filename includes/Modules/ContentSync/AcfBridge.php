<?php
/**
 * ACF read/apply bridge (server side only).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal ACF interop for content sync. The legacy module also rendered ACF
 * field editors as admin HTML; in the studio SPA the hub sends raw field
 * values and the node applies them, so only the read/apply/detect logic is
 * ported here. All methods are no-ops when ACF is not installed.
 */
final class AcfBridge {

	/**
	 * Whether ACF (with the value API) is available on this site.
	 */
	public static function is_active(): bool {
		return function_exists( 'get_fields' ) && function_exists( 'update_field' );
	}

	/**
	 * Read all ACF field values for a post (hub side, before pushing).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>
	 */
	public static function read( int $post_id ): array {
		if ( ! function_exists( 'get_fields' ) ) {
			return array();
		}
		$values = get_fields( $post_id );
		return is_array( $values ) ? $values : array();
	}

	/**
	 * Apply incoming ACF field values to a post (node side, on receive).
	 * Values are keyed by ACF field key or field name; update_field() accepts
	 * both. Values are sanitised recursively before writing.
	 *
	 * @param int   $post_id Post id.
	 * @param array $fields  field => value pairs.
	 * @return int Number of fields written.
	 */
	public static function apply( int $post_id, array $fields ): int {
		if ( ! function_exists( 'update_field' ) || empty( $fields ) ) {
			return 0;
		}
		$written = 0;
		foreach ( $fields as $key => $value ) {
			$field_key = sanitize_text_field( (string) $key );
			if ( '' === $field_key ) {
				continue;
			}
			update_field( $field_key, self::sanitize( $value ), $post_id );
			++$written;
		}
		return $written;
	}

	/**
	 * Recursively sanitise an incoming ACF value.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sanitize( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$key         = is_int( $k ) ? $k : sanitize_text_field( (string) $k );
				$out[ $key ] = self::sanitize( $v );
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return sanitize_textarea_field( (string) $value );
	}
}
