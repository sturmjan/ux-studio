<?php
/**
 * The file format of a settings export: build, serialise, parse and validate the envelope.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * JSON, UTF-8, pretty-printed. JSON keeps exact types (a toggle is `true`, never "1" or "on"), has no
 * attributes versus elements ambiguity and cannot carry executable content, which is why no XML is offered.
 * The class is the only place that knows the layout, so another serialiser (YAML, a zip with media) would
 * only have to provide `encode()` / `decode()` of the same array.
 *
 *   {
 *     "format": "uxstudio-settings", "format_version": 1, "plugin_version": "0.8.0",
 *     "exported_at": "2026-10-26T10:00:00+00:00", "site": { "name": "...", "host": "..." },
 *     "options": { "include_enabled": true, "include_data": false, "has_secrets": false },
 *     "modules": { "<id>": { "enabled": true, "settings": {...}, "data": {...}, "needs_manual": ["key"] } },
 *     "secrets": null | { "kdf": "argon2id", "v": 1, "salt": "...", "nonce": "...", "ciphertext": "..." },
 *     "checksum": "sha256:<hex of the canonical JSON of modules + secrets>"
 *   }
 *
 * Parsing never evaluates anything: json_decode with a depth limit, no unserialize, no include.
 *
 * Pure (no WordPress): tests/transfer-envelope.php.
 */
final class Envelope {

	public const FORMAT    = 'uxstudio-settings';
	public const VERSION   = 1;
	public const MAX_BYTES = 2097152; // 2 MB.
	public const MAX_DEPTH = 32;

	/** Pattern of a module id in a file (matches the ids of includes/Modules/<id>). */
	public const MODULE_ID = '/^[a-z0-9][a-z0-9-]{0,60}$/';

	/**
	 * Build an envelope.
	 *
	 * @param array<string, mixed> $args plugin_version, exported_at, site_name, site_host, include_enabled,
	 *                                   include_data, modules, secrets (block or null).
	 * @return array<string, mixed>
	 */
	public static function build( array $args ): array {
		$modules = (array) ( $args['modules'] ?? array() );
		$secrets = isset( $args['secrets'] ) && is_array( $args['secrets'] ) ? $args['secrets'] : null;
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => (string) ( $args['plugin_version'] ?? '' ),
			'exported_at'    => (string) ( $args['exported_at'] ?? gmdate( 'c' ) ),
			'site'           => array(
				'name' => (string) ( $args['site_name'] ?? '' ),
				'host' => (string) ( $args['site_host'] ?? '' ),
			),
			'options'        => array(
				'include_enabled' => ! empty( $args['include_enabled'] ),
				'include_data'    => ! empty( $args['include_data'] ),
				'has_secrets'     => null !== $secrets,
			),
			'modules'        => $modules,
			'secrets'        => $secrets,
			'checksum'       => self::checksum( $modules, $secrets ),
		);
	}

	/**
	 * Canonical JSON: object keys sorted, lists kept in order, no escaping of slashes or unicode, an empty
	 * array always `[]`. The same data gives the same bytes on the exporting and the importing site.
	 *
	 * @param mixed $value Any decoded JSON value.
	 */
	public static function canonical( $value ): string {
		return (string) json_encode( self::sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function sort( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array() === $value ) {
			return array();
		}
		if ( array_is_list( $value ) ) {
			return array_map( array( self::class, 'sort' ), $value );
		}
		ksort( $value, SORT_STRING );
		return array_map( array( self::class, 'sort' ), $value );
	}

	/**
	 * Checksum of the part of the file that matters (modules and the secrets block).
	 *
	 * @param array<string, mixed>      $modules Modules.
	 * @param array<string, mixed>|null $secrets Secrets block or null.
	 */
	public static function checksum( array $modules, ?array $secrets ): string {
		return 'sha256:' . hash( 'sha256', self::canonical( array( 'modules' => $modules, 'secrets' => $secrets ) ) );
	}

	/**
	 * The file text.
	 *
	 * @param array<string, mixed> $envelope Envelope.
	 */
	public static function encode( array $envelope ): string {
		// An empty "settings" or "data" is an object in the file.
		foreach ( (array) ( $envelope['modules'] ?? array() ) as $id => $module ) {
			foreach ( array( 'settings', 'data' ) as $part ) {
				if ( isset( $module[ $part ] ) && array() === $module[ $part ] ) {
					$envelope['modules'][ $id ][ $part ] = new \stdClass();
				}
			}
		}
		return (string) json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) . "\n";
	}

	/**
	 * Read and check a file text. Nothing is written anywhere.
	 *
	 * @param string $raw            File text.
	 * @param string $plugin_version Version of this plugin (for the "newer plugin" note).
	 * @return array{ok:bool,error:string,message:string,envelope:array<string,mixed>|null,warnings:array<int,array{code:string,message:string}>}
	 */
	public static function parse( string $raw, string $plugin_version = '' ): array {
		$fail = static fn( string $code, string $message ): array => array(
			'ok'       => false,
			'error'    => $code,
			'message'  => $message,
			'envelope' => null,
			'warnings' => array(),
		);

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return $fail( 'too_large', __( 'The file is larger than 2 MB, so it is not a settings export of UX Studio.', 'ux-studio' ) );
		}
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( '' === trim( $raw ) ) {
			return $fail( 'empty', __( 'The file is empty.', 'ux-studio' ) );
		}
		if ( ! function_exists( 'mb_check_encoding' ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return $fail( 'encoding', __( 'The file is not valid UTF-8 text.', 'ux-studio' ) );
		}
		try {
			$data = json_decode( $raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $e ) {
			$depth = JSON_ERROR_DEPTH === $e->getCode();
			return $fail(
				$depth ? 'depth' : 'json',
				$depth
					? __( 'The file is nested too deeply to be a settings export.', 'ux-studio' )
					: __( 'The file is not valid JSON.', 'ux-studio' )
			);
		}
		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return $fail( 'shape', __( 'The file is JSON, but not a settings export of UX Studio.', 'ux-studio' ) );
		}
		return self::validate( $data, $plugin_version );
	}

	/**
	 * Check the structure of a decoded file.
	 *
	 * @param array<string, mixed> $data           Decoded file.
	 * @param string               $plugin_version Version of this plugin.
	 * @return array{ok:bool,error:string,message:string,envelope:array<string,mixed>|null,warnings:array<int,array{code:string,message:string}>}
	 */
	public static function validate( array $data, string $plugin_version = '' ): array {
		$fail = static fn( string $code, string $message ): array => array(
			'ok'       => false,
			'error'    => $code,
			'message'  => $message,
			'envelope' => null,
			'warnings' => array(),
		);

		if ( self::FORMAT !== ( $data['format'] ?? null ) ) {
			return $fail( 'format', __( 'This is not a settings export of UX Studio (the "format" field is missing or different).', 'ux-studio' ) );
		}
		$version = $data['format_version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			return $fail( 'version', __( 'The file has no valid format version.', 'ux-studio' ) );
		}
		if ( $version > self::VERSION ) {
			return $fail(
				'too_new',
				sprintf(
					/* translators: 1: format version of the file, 2: format version this plugin reads. */
					__( 'The file uses format version %1$d, but this plugin reads version %2$d at most. Update UX Studio on this site and try again.', 'ux-studio' ),
					$version,
					self::VERSION
				)
			);
		}
		$modules = $data['modules'] ?? null;
		if ( ! is_array( $modules ) || ( array() !== $modules && array_is_list( $modules ) ) ) {
			return $fail( 'modules', __( 'The file has no "modules" section.', 'ux-studio' ) );
		}

		$clean_modules = array();
		foreach ( $modules as $id => $module ) {
			if ( ! is_string( $id ) || 1 !== preg_match( self::MODULE_ID, $id ) || ! is_array( $module ) ) {
				return $fail( 'module', __( 'The file has a module entry in a shape that UX Studio does not accept.', 'ux-studio' ) );
			}
			$entry = array();
			if ( array_key_exists( 'enabled', $module ) ) {
				if ( ! is_bool( $module['enabled'] ) ) {
					return $fail( 'module', __( 'The file has a module entry in a shape that UX Studio does not accept.', 'ux-studio' ) );
				}
				$entry['enabled'] = $module['enabled'];
			}
			foreach ( array( 'settings', 'data' ) as $part ) {
				if ( array_key_exists( $part, $module ) ) {
					if ( ! is_array( $module[ $part ] ) || ( array() !== $module[ $part ] && array_is_list( $module[ $part ] ) ) ) {
						return $fail( 'module', __( 'The file has a module entry in a shape that UX Studio does not accept.', 'ux-studio' ) );
					}
					$entry[ $part ] = $module[ $part ];
				}
			}
			$manual = array();
			foreach ( (array) ( $module['needs_manual'] ?? array() ) as $key ) {
				if ( is_string( $key ) && strlen( $key ) <= 100 ) {
					$manual[] = $key;
				}
			}
			$entry['needs_manual']  = $manual;
			$clean_modules[ $id ] = $entry;
		}

		$secrets = $data['secrets'] ?? null;
		if ( null !== $secrets && ! Crypto::valid_block( $secrets ) ) {
			return $fail( 'secrets', __( 'The encrypted block of secrets in the file is damaged.', 'ux-studio' ) );
		}

		$warnings = array();
		$file_ver = (string) ( $data['plugin_version'] ?? '' );
		if ( '' !== $plugin_version && '' !== $file_ver && version_compare( $file_ver, $plugin_version, '>' ) ) {
			$warnings[] = array(
				'code'    => 'newer_plugin',
				'message' => sprintf(
					/* translators: 1: UX Studio version that wrote the file, 2: version on this site. */
					__( 'The file was written by UX Studio %1$s, this site runs %2$s. Settings that this version does not know are skipped.', 'ux-studio' ),
					$file_ver,
					$plugin_version
				),
			);
		}
		$expected = self::checksum( $modules, $secrets );
		if ( ! is_string( $data['checksum'] ?? null ) || ! hash_equals( $expected, (string) $data['checksum'] ) ) {
			$warnings[] = array(
				'code'    => 'checksum',
				'message' => __( 'The checksum of the file does not match its content. The file was probably edited by hand or damaged. You can still look at what it would change.', 'ux-studio' ),
			);
		}

		$site = is_array( $data['site'] ?? null ) ? $data['site'] : array();
		return array(
			'ok'       => true,
			'error'    => '',
			'message'  => '',
			'warnings' => $warnings,
			'envelope' => array(
				'format_version' => $version,
				'plugin_version' => $file_ver,
				'exported_at'    => is_string( $data['exported_at'] ?? null ) ? $data['exported_at'] : '',
				'site'           => array(
					'name' => is_string( $site['name'] ?? null ) ? $site['name'] : '',
					'host' => is_string( $site['host'] ?? null ) ? $site['host'] : '',
				),
				'options'        => array(
					'include_enabled' => ! empty( $data['options']['include_enabled'] ),
					'include_data'    => ! empty( $data['options']['include_data'] ),
					'has_secrets'     => null !== $secrets,
				),
				'modules'        => $clean_modules,
				'secrets'        => $secrets,
			),
		);
	}
}
