<?php
/**
 * Reader of the bundled Open Cookie Database (fallback source of cookie descriptions).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Open Cookie Database, https://github.com/jkwakman/Open-Cookie-Database, Apache License 2.0 (copy of the licence in
 * data/OPEN-COOKIE-DATABASE-LICENSE.txt, attribution in the module help and README). The bundled file is a reduced,
 * modified copy produced by bin/import-open-cookie-database.php (the file says so in its `meta`).
 *
 * The descriptions are English. Our own catalogue (CspServices::consent_services()) has Czech and English texts for the
 * main services and always wins; this database only fills in what the catalogue does not know.
 *
 * The file (about 300 kB) is read only when a lookup needs it: in the admin inventory and in the REST call that fills the
 * banner detail for a service without its own cookie list. A normal page view never loads it.
 */
final class OpenCookieDb {

	/** Category of the database => our consent category. Functional is "preferences" (asks for consent) on purpose: it is the safer reading. */
	private const CATEGORIES = array(
		'a' => 'statistics',
		'm' => 'marketing',
		'p' => 'preferences',
		'f' => 'preferences',
		's' => 'necessary',
		'n' => 'necessary',
	);

	/** @var array<string, mixed>|null */
	private static ?array $data = null;

	/** @var array<string, int>|null Cookie name => row index (first row wins). */
	private static ?array $exact = null;

	/** @var list<array{0:string,1:int}>|null Wildcard prefix and row index, longest prefix first. */
	private static ?array $prefixes = null;

	/** @var string|null Path override (tests). */
	private static ?string $file = null;

	/**
	 * Point the reader at another file and forget what was loaded (tests).
	 *
	 * @param string|null $file Absolute path, or null for the bundled file.
	 */
	public static function use_file( ?string $file ): void {
		self::$file     = $file;
		self::$data     = null;
		self::$exact    = null;
		self::$prefixes = null;
	}

	/**
	 * Path of the bundled data file.
	 */
	public static function path(): string {
		return self::$file ?? __DIR__ . '/data/open-cookie-database.json';
	}

	/**
	 * Is the data available?
	 */
	public static function available(): bool {
		return array() !== self::data();
	}

	/**
	 * Provenance of the data (source, licence, date), for the help text and the admin page.
	 *
	 * @return array<string, mixed>
	 */
	public static function meta(): array {
		return (array) ( self::data()['meta'] ?? array() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		if ( null !== self::$data ) {
			return self::$data;
		}
		self::$data = array();
		$file       = self::path();
		if ( is_readable( $file ) ) {
			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
			if ( is_array( $json ) && isset( $json['cookies'], $json['platforms'] ) ) {
				self::$data = $json;
			}
		}
		return self::$data;
	}

	/**
	 * Row => readable array.
	 *
	 * @param int $i Row index.
	 * @return array{name:string, wildcard:bool, platform:string, category:string, retention:string, controller:string, description:string}
	 */
	private static function row( int $i ): array {
		$data = self::data();
		$r    = $data['cookies'][ $i ];
		return array(
			'name'        => (string) $r[0],
			'wildcard'    => 1 === (int) $r[1],
			'platform'    => (string) ( $data['platforms'][ (int) $r[2] ] ?? '' ),
			'category'    => self::CATEGORIES[ (string) $r[3] ] ?? 'preferences',
			'retention'   => (string) $r[4],
			'controller'  => (string) ( $data['controllers'][ (int) $r[5] ] ?? '' ),
			'description' => (string) $r[6],
		);
	}

	private static function index(): void {
		if ( null !== self::$exact ) {
			return;
		}
		self::$exact    = array();
		self::$prefixes = array();
		foreach ( (array) ( self::data()['cookies'] ?? array() ) as $i => $r ) {
			$name = (string) $r[0];
			if ( 1 === (int) $r[1] ) {
				self::$prefixes[] = array( $name, (int) $i );
			} elseif ( ! isset( self::$exact[ $name ] ) ) {
				self::$exact[ $name ] = (int) $i;
			}
		}
		usort( self::$prefixes, static fn( array $a, array $b ): int => strlen( $b[0] ) <=> strlen( $a[0] ) );
	}

	/**
	 * The database entry of a cookie name: an exact name, otherwise the longest wildcard entry whose name is a prefix
	 * of it. Null when the database does not know the cookie.
	 *
	 * @param string $name Cookie name as seen in the browser.
	 * @return array{name:string, wildcard:bool, platform:string, category:string, retention:string, controller:string, description:string}|null
	 */
	public static function lookup( string $name ): ?array {
		if ( '' === $name || ! self::available() ) {
			return null;
		}
		self::index();
		if ( isset( self::$exact[ $name ] ) ) {
			return self::row( self::$exact[ $name ] );
		}
		foreach ( (array) self::$prefixes as $pair ) {
			if ( 0 === strpos( $name, $pair[0] ) ) {
				return self::row( $pair[1] );
			}
		}
		return null;
	}

	/**
	 * Cookies of one platform (or several), in the order of the file.
	 *
	 * @param list<string> $platforms Platform names as in the database.
	 * @param int          $limit     Maximum rows.
	 * @return list<array{name:string, wildcard:bool, platform:string, category:string, retention:string, controller:string, description:string}>
	 */
	public static function platform_cookies( array $platforms, int $limit = 12 ): array {
		$data = self::data();
		if ( array() === $data || array() === $platforms ) {
			return array();
		}
		$wanted = array();
		foreach ( $platforms as $platform ) {
			$p = array_search( $platform, (array) $data['platforms'], true );
			if ( false !== $p ) {
				$wanted[ (int) $p ] = true;
			}
		}
		$out = array();
		foreach ( (array) $data['cookies'] as $i => $r ) {
			if ( isset( $wanted[ (int) $r[2] ] ) ) {
				$out[] = self::row( (int) $i );
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}
}
