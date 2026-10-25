<?php
/**
 * Small JSON Schema subset validator for ability input.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Supports what the abilities declare: type, enum, minimum/maximum, minLength/maxLength,
 * minItems/maxItems, items, properties, required, additionalProperties=false and default.
 */
final class Schema {

	/**
	 * Fill top-level defaults.
	 *
	 * @param array<string, mixed> $schema Object schema.
	 * @param array<string, mixed> $input  Input.
	 * @return array<string, mixed>
	 */
	public static function with_defaults( array $schema, array $input ): array {
		foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $prop ) {
			if ( ! array_key_exists( $key, $input ) && is_array( $prop ) && array_key_exists( 'default', $prop ) ) {
				$input[ $key ] = $prop['default'];
			}
		}
		return $input;
	}

	/**
	 * Validate a value; returns the list of problems (empty = valid).
	 *
	 * @param array<string, mixed> $schema Schema.
	 * @param mixed                $value  Value.
	 * @param string               $path   Path for messages.
	 * @return string[]
	 */
	public static function validate( array $schema, $value, string $path = 'input' ): array {
		$errors = array();
		$type   = $schema['type'] ?? null;

		if ( null !== $type && ! self::type_ok( (string) $type, $value ) ) {
			return array( sprintf( '%s must be of type %s.', $path, $type ) );
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, (array) $schema['enum'], true ) ) {
			$errors[] = sprintf( '%s must be one of: %s.', $path, implode( ', ', array_map( 'strval', (array) $schema['enum'] ) ) );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && ! is_bool( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				$errors[] = sprintf( '%s must be at least %s.', $path, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				$errors[] = sprintf( '%s must be at most %s.', $path, $schema['maximum'] );
			}
		}
		if ( is_string( $value ) ) {
			if ( isset( $schema['minLength'] ) && strlen( $value ) < (int) $schema['minLength'] ) {
				$errors[] = sprintf( '%s is too short.', $path );
			}
			if ( isset( $schema['maxLength'] ) && strlen( $value ) > (int) $schema['maxLength'] ) {
				$errors[] = sprintf( '%s is too long.', $path );
			}
		}
		if ( is_array( $value ) && 'array' === $type ) {
			if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
				$errors[] = sprintf( '%s needs at least %d item(s).', $path, $schema['minItems'] );
			}
			if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
				$errors[] = sprintf( '%s allows at most %d item(s).', $path, $schema['maxItems'] );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( array_values( $value ) as $i => $item ) {
					$errors = array_merge( $errors, self::validate( $schema['items'], $item, $path . '[' . $i . ']' ) );
				}
			}
		}
		if ( 'object' === $type && is_array( $value ) ) {
			$props = (array) ( $schema['properties'] ?? array() );
			foreach ( (array) ( $schema['required'] ?? array() ) as $req ) {
				if ( ! array_key_exists( $req, $value ) ) {
					$errors[] = sprintf( '%s.%s is required.', $path, $req );
				}
			}
			foreach ( $value as $key => $item ) {
				if ( isset( $props[ $key ] ) && is_array( $props[ $key ] ) ) {
					$errors = array_merge( $errors, self::validate( $props[ $key ], $item, $path . '.' . $key ) );
				} elseif ( array_key_exists( 'additionalProperties', $schema ) && false === $schema['additionalProperties'] ) {
					$errors[] = sprintf( '%s.%s is not a known parameter.', $path, $key );
				}
			}
		}
		return $errors;
	}

	/**
	 * Does the value have the JSON type?
	 *
	 * @param string $type  JSON type.
	 * @param mixed  $value Value.
	 */
	private static function type_ok( string $type, $value ): bool {
		switch ( $type ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'array':
				return is_array( $value ) && ( array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
			case 'object':
				return is_array( $value );
		}
		return true;
	}
}
