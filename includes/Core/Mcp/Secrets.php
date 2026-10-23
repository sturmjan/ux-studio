<?php
/**
 * Keeps secrets away from MCP clients.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Anything whose key looks like a credential is never returned: settings values become
 * a `has_<key>` flag, every other output gets the value replaced by a marker.
 */
final class Secrets {

	public const MARK = '[redacted]';

	private const PATTERN = '/(pass(word|wd)?|secret|token|api[_-]?key|private[_-]?key|credential|hmac|salt|bearer|authorization|auth[_-]?key|license[_-]?key)/i';

	/**
	 * Does this key name look like a credential?
	 *
	 * @param string $key Key.
	 */
	public static function is_secret_key( string $key ): bool {
		if ( 0 === strpos( $key, 'has_' ) || 1 === preg_match( '/(_id|_ids|_count|_at|_enabled|_length|_expires)$/i', $key ) ) {
			return false; // Flags, ids, counts and dates about a credential are not the credential.
		}
		return 1 === preg_match( self::PATTERN, $key );
	}

	/**
	 * Module settings for a client: credential values removed, a `has_<key>` flag put instead.
	 *
	 * @param array<string, mixed> $values Settings values.
	 * @return array{values:array<string, mixed>,redacted:string[]}
	 */
	public static function mask_settings( array $values ): array {
		$out      = array();
		$redacted = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( self::is_secret_key( $key ) && ! is_bool( $value ) ) {
				$redacted[]         = $key;
				$out[ 'has_' . $key ] = is_array( $value ) ? ! empty( $value ) : '' !== trim( (string) $value );
				continue;
			}
			if ( is_string( $value ) && 0 === strpos( $value, 'uxs1:' ) ) {
				// Sealed value (Security::seal): never leaves the site, whatever its key is called.
				$redacted[]           = $key;
				$out[ 'has_' . $key ] = true;
				continue;
			}
			if ( is_array( $value ) ) {
				$inner = self::mask_settings( $value );
				$out[ $key ] = $inner['values'];
				foreach ( $inner['redacted'] as $r ) {
					$redacted[] = $key . '.' . $r;
				}
				continue;
			}
			$out[ $key ] = $value;
		}
		return array(
			'values'   => $out,
			'redacted' => $redacted,
		);
	}

	/**
	 * Scrub any ability output: values under credential-like keys are replaced by a marker.
	 *
	 * @param mixed $data Output.
	 * @return mixed
	 */
	public static function scrub( $data ) {
		if ( ! is_array( $data ) ) {
			if ( is_string( $data ) && 0 === strpos( $data, 'uxs1:' ) ) {
				return self::MARK;
			}
			return $data;
		}
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_secret_key( $key ) && ! is_bool( $value ) && ! is_int( $value ) && null !== $value && '' !== $value && array() !== $value ) {
				$out[ $key ] = self::MARK;
				continue;
			}
			$out[ $key ] = self::scrub( $value );
		}
		return $out;
	}

	/**
	 * Keys of a settings payload that look like credentials.
	 *
	 * @param array<string, mixed> $values Payload.
	 * @return string[]
	 */
	public static function secret_keys( array $values ): array {
		return array_values( array_filter( array_map( 'strval', array_keys( $values ) ), array( self::class, 'is_secret_key' ) ) );
	}
}
