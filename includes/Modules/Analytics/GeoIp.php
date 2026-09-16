<?php
/**
 * GeoIP — IP-to-country mapping for the Analytics module, no license key.
 *
 * The public-domain database (ip-location-db / geo-whois-asn-country) is
 * downloaded once and stored as a compact binary index (10 B/record: uint32
 * start, uint32 end, 2x ASCII country code). Lookup is a binary search
 * directly in the file (fseek/fread), so the whole DB never has to be loaded
 * into memory — fast enough to run on every request.
 *
 * The raw IP is never stored; a lookup only ever yields a 2-letter country code.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

final class GeoIp {

	private const OPTION = 'uxstudio_analytics_geoip';

	/** Public-domain source, no API key required. */
	private const SRC = 'https://cdn.jsdelivr.net/npm/@ip-location-db/geo-whois-asn-country/geo-whois-asn-country-ipv4.csv';

	/** Bytes per record: N(start) N(end) A2(cc). */
	private const REC = 10;

	/** @var resource|null Cached open file handle for the duration of the request. */
	private static $fh = null;

	public static function dir(): string {
		return WP_CONTENT_DIR . '/uxstudio-analytics-geoip';
	}

	public static function bin_path(): string {
		return self::dir() . '/country-ipv4.bin';
	}

	public static function status(): array {
		$s = get_option( self::OPTION, array() );
		return is_array( $s ) ? $s : array();
	}

	public static function is_enabled(): bool {
		return ! empty( self::status()['enabled'] );
	}

	public static function set_enabled( bool $on ): void {
		$s            = self::status();
		$s['enabled'] = $on;
		update_option( self::OPTION, $s );
	}

	public static function is_ready(): bool {
		return is_file( self::bin_path() ) && (int) ( self::status()['rows'] ?? 0 ) > 0;
	}

	/** Create the data directory and lock it against web access. */
	private static function ensure_dir(): void {
		$d = self::dir();
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
		}
		if ( ! is_file( $d . '/.htaccess' ) ) {
			@file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		if ( ! is_file( $d . '/index.html' ) ) {
			@file_put_contents( $d . '/index.html', '' ); // phpcs:ignore
		}
	}

	/**
	 * Download the source and build the binary index.
	 *
	 * @return array{0:bool,1:string} [ ok, message ].
	 */
	public static function update(): array {
		self::ensure_dir();

		$resp = wp_remote_get( self::SRC, array( 'timeout' => 60 ) );
		if ( is_wp_error( $resp ) ) {
			return array( false, $resp->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return array( false, 'HTTP ' . wp_remote_retrieve_response_code( $resp ) );
		}
		$csv = wp_remote_retrieve_body( $resp );
		if ( '' === $csv ) {
			return array( false, __( 'empty response', 'ux-studio' ) );
		}

		$tmp = self::bin_path() . '.tmp';
		$fh  = @fopen( $tmp, 'wb' ); // phpcs:ignore
		if ( ! $fh ) {
			return array( false, 'cannot write to ' . self::dir() );
		}

		$rows   = 0;
		$prev   = -1;
		$sorted = true;
		$line   = strtok( $csv, "\n" );
		while ( false !== $line ) {
			$row  = trim( $line );
			$line = strtok( "\n" );
			if ( '' === $row ) {
				continue;
			}
			$parts = explode( ',', $row );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			$start = ip2long( $parts[0] );
			$end   = ip2long( $parts[1] );
			$cc    = strtoupper( substr( trim( $parts[2] ), 0, 2 ) );
			if ( false === $start || false === $end || 2 !== strlen( $cc ) ) {
				continue; // Skip IPv6 / invalid rows.
			}
			$start &= 0xFFFFFFFF;
			$end   &= 0xFFFFFFFF;
			if ( $start < $prev ) {
				$sorted = false;
			}
			$prev = $start;
			fwrite( $fh, pack( 'NN', $start, $end ) . $cc );
			++$rows;
		}
		fclose( $fh );

		if ( ! $sorted ) {
			@unlink( $tmp ); // phpcs:ignore
			return array( false, __( 'source is not sorted (index would not work)', 'ux-studio' ) );
		}
		if ( $rows < 1000 ) {
			@unlink( $tmp ); // phpcs:ignore
			return array( false, __( 'too few records', 'ux-studio' ) . ' (' . $rows . ')' );
		}

		@unlink( self::bin_path() ); // phpcs:ignore
		@rename( $tmp, self::bin_path() ); // phpcs:ignore

		$s            = self::status();
		$s['rows']    = $rows;
		$s['bytes']   = (int) filesize( self::bin_path() );
		$s['updated'] = gmdate( 'c' );
		$s['enabled'] = true;
		update_option( self::OPTION, $s );

		return array( true, $rows . ' ' . __( 'ranges', 'ux-studio' ) );
	}

	/** Returns the uppercase ISO country code for an IPv4 address, '' otherwise. */
	public static function lookup( string $ip ): string {
		if ( '' === $ip || ! self::is_ready() ) {
			return '';
		}
		$n = ip2long( $ip );
		if ( false === $n ) {
			return ''; // IPv6 not supported yet.
		}
		$n &= 0xFFFFFFFF;

		if ( null === self::$fh ) {
			self::$fh = @fopen( self::bin_path(), 'rb' ); // phpcs:ignore
		}
		$fh = self::$fh;
		if ( ! $fh ) {
			return '';
		}

		$size  = (int) ( self::status()['bytes'] ?? filesize( self::bin_path() ) );
		$count = intdiv( $size, self::REC );
		$lo    = 0;
		$hi    = $count - 1;

		while ( $lo <= $hi ) {
			$mid = intdiv( $lo + $hi, 2 );
			if ( 0 !== fseek( $fh, $mid * self::REC ) ) {
				break;
			}
			$rec = fread( $fh, self::REC );
			if ( strlen( $rec ) < self::REC ) {
				break;
			}
			$u     = unpack( 'Nstart/Nend', substr( $rec, 0, 8 ) );
			$start = $u['start'];
			$end   = $u['end'];
			if ( $n < $start ) {
				$hi = $mid - 1;
			} elseif ( $n > $end ) {
				$lo = $mid + 1;
			} else {
				return substr( $rec, 8, 2 );
			}
		}
		return '';
	}
}
