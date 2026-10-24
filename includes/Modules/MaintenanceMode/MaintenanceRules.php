<?php
/**
 * Pure rules of the Maintenance Mode module (no WordPress calls, covered by tests/content-b3.php): which addresses stay
 * open, the countdown and when the mode is over.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MaintenanceMode;

defined( 'ABSPATH' ) || exit;

final class MaintenanceRules {

	/**
	 * Parse the list of addresses that stay available. One per line (commas also separate):
	 *  - `/kontakt`          exactly this page (a trailing slash does not matter, the letter case does not matter),
	 *  - `/kontakt/*`        the page and everything below it; `*` stands for any characters anywhere,
	 *  - `regex:^/(en|de)/`  a regular expression on the path (case insensitive).
	 * Lines starting with `#` are comments.
	 *
	 * @param string $text Textarea content.
	 * @return array{rules:array<int,array{type:string,value:string}>,invalid:string[]}
	 */
	public static function parse_paths( string $text ): array {
		$rules   = array();
		$invalid = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $raw ) {
			$line = trim( $raw );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( 0 === stripos( $line, 'regex:' ) ) {
				$pattern = trim( substr( $line, 6 ) );
				if ( '' !== $pattern && self::regex_ok( $pattern ) ) {
					$rules[] = array(
						'type'  => 'regex',
						'value' => $pattern,
					);
				} else {
					$invalid[] = $line;
				}
				continue;
			}
			foreach ( explode( ',', $line ) as $part ) {
				$part = trim( $part );
				if ( '' === $part ) {
					continue;
				}
				// A star at the start is kept (`*feed*` = any address with feed in it), everything else begins with a slash.
				$part    = '*' === $part[0] ? strtolower( $part ) : '/' . ltrim( strtolower( $part ), '/' );
				$part    = '/' === $part ? $part : rtrim( $part, '/' );
				$rules[] = array(
					'type'  => false !== strpos( $part, '*' ) ? 'wild' : 'exact',
					'value' => $part,
				);
			}
		}
		return array(
			'rules'   => $rules,
			'invalid' => $invalid,
		);
	}

	/**
	 * Parse the list of addresses that always see the site: IPs and CIDR ranges, one per line or separated by commas
	 * or spaces. Lines starting with `#` are comments; what is neither an address nor a range is left out.
	 *
	 * @param string $text Textarea content.
	 * @return string[]
	 */
	public static function parse_ips( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			foreach ( preg_split( '/[\s,]+/', $line ) ?: array() as $entry ) {
				if ( '' !== $entry && self::valid_ip_entry( $entry ) && ! in_array( $entry, $out, true ) ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * @param string $entry An address or a range such as 203.0.113.0/24.
	 */
	public static function valid_ip_entry( string $entry ): bool {
		if ( false === strpos( $entry, '/' ) ) {
			return false !== @inet_pton( $entry ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		list( $net, $bits ) = explode( '/', $entry, 2 );
		$bin                = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $bin && ctype_digit( $bits ) && (int) $bits <= strlen( $bin ) * 8;
	}

	/**
	 * @param string $pattern Pattern without delimiters.
	 */
	private static function regex_ok( string $pattern ): bool {
		return \UxStudio\Core\SafeRegex::probe( self::delimit( $pattern ) );
	}

	/**
	 * @param string $pattern Pattern without delimiters.
	 */
	private static function delimit( string $pattern ): string {
		return '~' . str_replace( '~', '\~', $pattern ) . '~i';
	}

	/**
	 * Path of a request relative to the site: no query, no site subdirectory, one leading slash, no trailing slash,
	 * lower case.
	 *
	 * @param string $request_uri REQUEST_URI.
	 * @param string $home_path   Path of the home URL (`/pobyty` or empty).
	 */
	public static function request_path( string $request_uri, string $home_path = '' ): string {
		$path = (string) parse_url( $request_uri, PHP_URL_PATH );
		$path = rawurldecode( $path );
		$home = rtrim( $home_path, '/' );
		$path = (string) preg_replace( '~/{2,}~', '/', str_replace( '\\', '/', $path ) );
		if ( '' !== $home && ( $path === $home || 0 === strpos( $path, $home . '/' ) ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$path = '/' . ltrim( strtolower( $path ), '/' );
		return '/' === $path ? $path : rtrim( $path, '/' );
	}

	/**
	 * Whether the path is on the list of addresses that stay available.
	 *
	 * @param string                                        $path  Result of request_path().
	 * @param array<int,array{type:string,value:string}> $rules Parsed rules.
	 */
	public static function path_excepted( string $path, array $rules ): bool {
		// A path with `..` segments or doubled slashes is not what the exceptions were written for
		// (`/kontakt/../admin` must not ride on `/kontakt/*`): never excepted.
		if ( 1 === preg_match( '~(^|/)\.\.?(/|$)|//~', $path ) || false !== strpos( $path, '\\' ) || false !== strpos( rawurldecode( $path ), '..' ) ) {
			return false;
		}
		foreach ( $rules as $rule ) {
			switch ( $rule['type'] ) {
				case 'exact':
					if ( $path === $rule['value'] ) {
						return true;
					}
					break;
				case 'wild':
					$regex = '/^' . str_replace( '\*', '.*', preg_quote( $rule['value'], '/' ) ) . '$/s';
					// `/kontakt/*` also covers `/kontakt` itself.
					$base = '/*' === substr( $rule['value'], -2 ) ? substr( $rule['value'], 0, -2 ) : null;
					if ( \UxStudio\Core\SafeRegex::match( $regex, $path ) || ( null !== $base && '' !== $base && $path === $base ) ) {
						return true;
					}
					break;
				case 'regex':
					// The path has no trailing slash; a pattern such as ^/en/ should still see the section address /en/.
					if ( \UxStudio\Core\SafeRegex::match( self::delimit( $rule['value'] ), $path ) || \UxStudio\Core\SafeRegex::match( self::delimit( $rule['value'] ), $path . '/' ) ) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/**
	 * Parse the stored countdown end (`YYYY-MM-DDTHH:MM` or `YYYY-MM-DD HH:MM[:SS]`, time of the site).
	 *
	 * @param string        $value Stored value.
	 * @param \DateTimeZone $tz    Time zone of the site.
	 * @return int|null Unix time, null = not set or not a date.
	 */
	public static function parse_end( string $value, \DateTimeZone $tz ): ?int {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			return null;
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', sprintf( '%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00' ), $tz );
		return $date ? $date->getTimestamp() : null;
	}

	/**
	 * Normalise a value the settings form sends to the stored `YYYY-MM-DDTHH:MM` or ''.
	 *
	 * @param string $value Raw value.
	 */
	public static function clean_end( string $value ): string {
		$value = trim( $value );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::\d{2})?$/', $value, $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return '';
		}
		return sprintf( '%s-%s-%sT%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5] );
	}

	/**
	 * Whether the mode is over because the countdown ran out (only when "switch off at the end" is on).
	 *
	 * @param int|null $end_ts       End of the countdown.
	 * @param bool     $auto_disable Switch the mode off at the end.
	 * @param int      $now          Current time.
	 */
	public static function expired( ?int $end_ts, bool $auto_disable, int $now ): bool {
		return $auto_disable && null !== $end_ts && $end_ts <= $now;
	}

	/**
	 * Seconds for the Retry-After header of a 503: until the end when it is known and in the future, otherwise an hour.
	 *
	 * @param int|null $end_ts End of the countdown.
	 * @param int      $now    Current time.
	 */
	public static function retry_after( ?int $end_ts, int $now ): int {
		if ( null !== $end_ts && $end_ts > $now ) {
			return min( $end_ts - $now, 30 * 86400 );
		}
		return 3600;
	}

	/**
	 * Days, hours, minutes and seconds left.
	 *
	 * @param int $seconds Seconds left (negative counts as zero).
	 * @return array{d:int,h:int,m:int,s:int}
	 */
	public static function split( int $seconds ): array {
		$seconds = max( 0, $seconds );
		return array(
			'd' => intdiv( $seconds, 86400 ),
			'h' => intdiv( $seconds % 86400, 3600 ),
			'm' => intdiv( $seconds % 3600, 60 ),
			's' => $seconds % 60,
		);
	}
}
