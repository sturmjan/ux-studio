<?php
/**
 * IP list entries of foreign plugins turned into what the Firewall accepts.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions (no WordPress). The Firewall knows single addresses and CIDR ranges (IPv4 and IPv6);
 * Limit Login Attempts Reloaded and Loginizer also store `start-end` ranges and Loginizer an explicit
 * start/end pair. A range is split into the shortest list of CIDR blocks that covers it exactly.
 */
final class IpEntries {

	/** A range that needs more blocks than this is reported as not transferred (not silently widened). */
	private const MAX_BLOCKS = 64;

	/**
	 * Turn one entry into Firewall entries.
	 *
	 * @param string $raw Entry as the plugin stores it: `1.2.3.4`, `1.2.3.0/24`, `1.2.3.4-1.2.3.9`, `1.2.3.*`, an IPv6 address.
	 * @return array{entries:string[],ok:bool} `ok` false = the entry could not be understood or is too irregular.
	 */
	public static function convert( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array(
				'entries' => array(),
				'ok'      => true,
			);
		}
		// Wildcard in the last octets: 1.2.3.* = 1.2.3.0/24, 1.2.*.* = 1.2.0.0/16.
		if ( false !== strpos( $raw, '*' ) && preg_match( '/^(\d{1,3})\.(\d{1,3}|\*)\.(\d{1,3}|\*)\.\*$/', $raw, $m ) ) {
			$fixed = 1;
			if ( '*' !== $m[2] ) {
				++$fixed;
				if ( '*' !== $m[3] ) {
					++$fixed;
				}
			}
			$octets = array( $m[1], '*' === $m[2] ? '0' : $m[2], '*' === $m[2] || '*' === $m[3] ? '0' : $m[3], '0' );
			return self::convert( implode( '.', $octets ) . '/' . ( 8 * $fixed ) );
		}
		if ( false !== strpos( $raw, '/' ) ) {
			return self::valid_cidr( $raw ) ? array(
				'entries' => array( self::canonical_cidr( $raw ) ),
				'ok'      => true,
			) : array(
				'entries' => array(),
				'ok'      => false,
			);
		}
		if ( false !== strpos( $raw, '-' ) && false === strpos( $raw, ':' ) ) {
			$ends = array_map( 'trim', explode( '-', $raw, 2 ) );
			return self::range( $ends[0], $ends[1] ?? '' );
		}
		if ( false !== @inet_pton( $raw ) ) {
			return array(
				'entries' => array( $raw ),
				'ok'      => true,
			);
		}
		return array(
			'entries' => array(),
			'ok'      => false,
		);
	}

	/**
	 * Entries of a start/end pair.
	 *
	 * @param string $start First address.
	 * @param string $end   Last address.
	 * @return array{entries:string[],ok:bool}
	 */
	public static function range( string $start, string $end ): array {
		$fail = array(
			'entries' => array(),
			'ok'      => false,
		);
		$a    = @inet_pton( trim( $start ) );
		$b    = @inet_pton( trim( $end ) );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return $fail;
		}
		if ( $a === $b ) {
			return array(
				'entries' => array( trim( $start ) ),
				'ok'      => true,
			);
		}
		if ( 4 !== strlen( $a ) || strcmp( $a, $b ) > 0 ) {
			return $fail; // IPv6 ranges are not expanded; a reversed range is a typo.
		}
		$lo     = (int) sprintf( '%u', ip2long( trim( $start ) ) );
		$hi     = (int) sprintf( '%u', ip2long( trim( $end ) ) );
		$blocks = array();
		while ( $lo <= $hi ) {
			$size = $lo > 0 ? ( $lo & -$lo ) : ( 1 << 32 );
			while ( $size > $hi - $lo + 1 ) {
				$size >>= 1;
			}
			$bits = 32 - (int) round( log( $size, 2 ) );
			if ( $bits < 8 ) {
				return $fail; // Wider than /8: see valid_cidr().
			}
			$blocks[] = long2ip( $lo ) . '/' . $bits;
			$lo      += $size;
			if ( count( $blocks ) > self::MAX_BLOCKS ) {
				return $fail;
			}
		}
		return array(
			'entries' => array_map( static fn( string $b ): string => str_ends_with( $b, '/32' ) ? substr( $b, 0, -3 ) : $b, $blocks ),
			'ok'      => true,
		);
	}

	/**
	 * Split a list of raw entries.
	 *
	 * @param array<int|string, mixed> $raw_entries Strings (or start/end pairs as `array( 'start' =>, 'end' => )`).
	 * @return array{entries:string[],skipped:string[]} Unique Firewall entries and the raw entries that could not be used.
	 */
	public static function convert_all( array $raw_entries ): array {
		$entries = array();
		$skipped = array();
		foreach ( $raw_entries as $raw ) {
			if ( is_array( $raw ) ) {
				$res   = self::range( (string) ( $raw['start'] ?? '' ), (string) ( $raw['end'] ?? ( $raw['start'] ?? '' ) ) );
				$label = trim( (string) ( $raw['start'] ?? '' ) ) . '-' . trim( (string) ( $raw['end'] ?? '' ) );
			} else {
				$res   = self::convert( (string) $raw );
				$label = trim( (string) $raw );
			}
			if ( ! $res['ok'] ) {
				$skipped[] = $label;
				continue;
			}
			foreach ( $res['entries'] as $entry ) {
				$entries[ $entry ] = $entry;
			}
		}
		return array(
			'entries' => array_values( $entries ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Union of an existing newline separated list and new entries, existing lines first (no duplicates).
	 *
	 * @param string   $existing Current textarea value.
	 * @param string[] $add      New entries.
	 */
	public static function merge_lines( string $existing, array $add ): string {
		$lines = array();
		foreach ( preg_split( '/[\r\n,]+/', $existing ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[ $line ] = $line;
			}
		}
		foreach ( $add as $entry ) {
			$lines[ $entry ] = $entry;
		}
		return implode( "\n", array_values( $lines ) );
	}

	/**
	 * Type of a Firewall entry: `cidr` or `single`.
	 *
	 * @param string $entry Entry.
	 */
	public static function type_of( string $entry ): string {
		return false !== strpos( $entry, '/' ) ? 'cidr' : 'single';
	}

	private static function valid_cidr( string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		$bin   = @inet_pton( trim( $parts[0] ) );
		$bits  = trim( $parts[1] ?? '' );
		// Blocks wider than /8 (IPv4) or /16 (IPv6) are most likely a typo or a catch-all; they are reported, not moved.
		return false !== $bin && ctype_digit( $bits ) && (int) $bits <= strlen( $bin ) * 8 && (int) $bits >= ( 4 === strlen( $bin ) ? 8 : 16 );
	}

	private static function canonical_cidr( string $cidr ): string {
		$parts = explode( '/', $cidr, 2 );
		return trim( $parts[0] ) . '/' . (int) trim( $parts[1] );
	}
}
