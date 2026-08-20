<?php
/**
 * Per-module settings persistence with schema-based sanitization.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One option row per module (uxstudio_<module_id>), values validated against
 * the module's settings schema before writing.
 */
final class Settings {

	private string $option;

	/** @var array|null Lazy-loaded values. */
	private ?array $values = null;

	/**
	 * @param string $option Option name (already prefixed uxstudio_).
	 */
	public function __construct( string $option ) {
		$this->option = $option;
	}

	/**
	 * Read one value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 */
	public function get( string $key, $default = null ) {
		if ( null === $this->values ) {
			$this->values = (array) get_option( $this->option, array() );
		}
		return $this->values[ $key ] ?? $default;
	}

	/** All stored values. */
	public function all(): array {
		if ( null === $this->values ) {
			$this->values = (array) get_option( $this->option, array() );
		}
		return $this->values;
	}

	/**
	 * Validate against schema and persist. Unknown keys are dropped.
	 *
	 * @param array $input  Raw input (from REST).
	 * @param array $schema Module settings schema (see BaseModule::settings_schema).
	 */
	public function save( array $input, array $schema ): array {
		$clean = array();
		foreach ( $schema as $field ) {
			$key = $field['key'];
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$clean[ $key ] = self::sanitize_field( $input[ $key ], $field );
		}
		$this->values = array_merge( $this->all(), $clean );
		update_option( $this->option, $this->values );
		return $this->values;
	}

	/**
	 * Sanitize one value by declared field type.
	 *
	 * @param mixed $value Raw value.
	 * @param array $field Schema field definition.
	 */
	private static function sanitize_field( $value, array $field ) {
		switch ( $field['type'] ) {
			case 'toggle':
				return (bool) $value;
			case 'number':
				return is_numeric( $value ) ? +$value : 0;
			case 'select':
				// Compare as strings: option keys may be ints (e.g. page IDs) while
				// the submitted value arrives as a string from JSON.
				$allowed = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				$value   = (string) $value;
				return in_array( $value, $allowed, true ) ? $value : (string) ( $field['default'] ?? '' );
			case 'multiselect':
				$allowed = array_map( 'strval', array_keys( $field['options'] ?? array() ) );
				return array_values( array_intersect( array_map( 'strval', (array) $value ), $allowed ) );
			case 'color':
				return (string) sanitize_hex_color( (string) $value );
			case 'richtext':
				return wp_kses_post( (string) $value );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'media':
				return absint( $value );
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
