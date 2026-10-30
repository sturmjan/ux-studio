<?php
/**
 * Shared registry of the plain-language help of module settings.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * ONE registry for the "Details" shown under a setting (what it does, what changes, the risk, when to switch it
 * off). Every module keeps its texts in `includes/Modules/<Module>/help.php`, a file that returns
 * `key => parts`; this class finds them, normalises them and attaches them to the settings schema that the REST
 * layer serves (the SPA renders `field.explain` generically).
 *
 * Parts of one entry (all strings, translated; see docs/help-texts.md):
 *   does    what the setting does, in plain words (required).
 *   effect  what changes for the site and its visitors once it is on (required for new entries; empty only for
 *           entries that predate it, such as the Security ones).
 *   risk    what can go wrong (optional).
 *   off     when to switch it off or leave it (optional).
 *   skip    the condition under which the onboarding starter pack leaves the setting out (optional, never part
 *           of the settings screen, only of for(..., true)).
 *
 * Sources, strongest first: an inline `'explain' => array(...)` in the schema field, the filter
 * `uxstudio_setting_help`, the module's `help.php`.
 */
final class SettingHelp {

	/** The parts an entry may carry, in display order. */
	public const PARTS = array( 'does', 'effect', 'risk', 'off', 'skip' );

	/**
	 * Evaluated help.php files, per locale (the texts are translated while the file is included).
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $loaded = array();

	/**
	 * Help of one setting, or null when there is none.
	 *
	 * @param string $module_id Module id (kebab-case, as in meta.json).
	 * @param string $key       Setting key.
	 * @param bool   $with_skip Keep the starter pack's `skip` part (the settings screen leaves it out).
	 * @return array{does:string,effect:string,risk?:string,off?:string,skip?:string}|null
	 */
	public static function for( string $module_id, string $key, bool $with_skip = false ): ?array {
		$raw = self::file_entries( $module_id )[ $key ] ?? null;

		/**
		 * Extension point: add or replace the help of a setting (add-ons, tests).
		 *
		 * @param array|null $parts     The parts from help.php, or null.
		 * @param string     $module_id Module id.
		 * @param string     $key       Setting key.
		 */
		$raw = apply_filters( 'uxstudio_setting_help', $raw, $module_id, $key );

		return self::normalize( $raw, $with_skip );
	}

	/**
	 * Keys that have an entry in the module's help.php (the filter can add more, they are not listed here).
	 *
	 * @param string $module_id Module id.
	 * @return string[]
	 */
	public static function keys( string $module_id ): array {
		return array_keys( self::file_entries( $module_id ) );
	}

	/**
	 * The schema with `explain` added to every field that has help. A field that carries its own `explain` keeps
	 * it (normalised); a field without a key or without help is left untouched.
	 *
	 * @param string                           $module_id Module id.
	 * @param array<int, array<string, mixed>> $schema    Module settings schema.
	 * @return array<int, array<string, mixed>>
	 */
	public static function attach( string $module_id, array $schema ): array {
		foreach ( $schema as $i => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$explain = null;
			if ( isset( $field['explain'] ) && is_array( $field['explain'] ) ) {
				$explain = self::normalize( $field['explain'], false );
			}
			if ( null === $explain && isset( $field['key'] ) && is_string( $field['key'] ) ) {
				$explain = self::for( $module_id, $field['key'] );
			}
			if ( null !== $explain ) {
				$schema[ $i ]['explain'] = $explain;
			} else {
				unset( $schema[ $i ]['explain'] );
			}
		}
		return $schema;
	}

	/**
	 * Whether a schema field is explained: a `help` line, an inline `explain` or an entry in the registry.
	 * Used by the coverage test.
	 *
	 * @param string               $module_id Module id.
	 * @param array<string, mixed> $field     Schema field.
	 */
	public static function has_help( string $module_id, array $field ): bool {
		return '' !== trim( (string) ( $field['help'] ?? '' ) )
			|| null !== self::normalize( is_array( $field['explain'] ?? null ) ? $field['explain'] : null, false )
			|| ( isset( $field['key'] ) && null !== self::for( $module_id, (string) $field['key'] ) );
	}

	/**
	 * Bring raw parts into the public shape: strings only, known parts only, `does` required, `effect` always
	 * present ('' when the entry has none).
	 *
	 * @param mixed $raw       Parts from help.php, the filter or an inline `explain`.
	 * @param bool  $with_skip Keep `skip`.
	 * @return array{does:string,effect:string,risk?:string,off?:string,skip?:string}|null
	 */
	public static function normalize( $raw, bool $with_skip = false ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$does = isset( $raw['does'] ) && is_scalar( $raw['does'] ) ? trim( (string) $raw['does'] ) : '';
		if ( '' === $does ) {
			return null;
		}
		$out = array(
			'does'   => $does,
			'effect' => isset( $raw['effect'] ) && is_scalar( $raw['effect'] ) ? trim( (string) $raw['effect'] ) : '',
		);
		foreach ( array( 'risk', 'off', 'skip' ) as $part ) {
			if ( 'skip' === $part && ! $with_skip ) {
				continue;
			}
			if ( isset( $raw[ $part ] ) && is_scalar( $raw[ $part ] ) && '' !== trim( (string) $raw[ $part ] ) ) {
				$out[ $part ] = trim( (string) $raw[ $part ] );
			}
		}
		return $out;
	}

	/**
	 * Path of a module's help file. Add-on modules can point elsewhere with the filter.
	 *
	 * @param string $module_id Module id.
	 */
	public static function file_for( string $module_id ): string {
		$path = UXSTUDIO_PATH . 'includes/Modules/' . Modules::class_slug( $module_id ) . '/help.php';

		/**
		 * Where the help.php of a module lives (add-on modules outside includes/Modules).
		 *
		 * @param string $path      Default path.
		 * @param string $module_id Module id.
		 */
		return (string) apply_filters( 'uxstudio_setting_help_file', $path, $module_id );
	}

	/**
	 * Entries of a module's help.php, cached per locale (the locale can be switched in the middle of a request,
	 * see Core\Locale).
	 *
	 * @param string $module_id Module id.
	 * @return array<string, array<string, mixed>>
	 */
	private static function file_entries( string $module_id ): array {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : 'en_US';
		if ( isset( self::$loaded[ $locale ][ $module_id ] ) ) {
			return self::$loaded[ $locale ][ $module_id ];
		}
		$entries = array();
		$file    = self::file_for( $module_id );
		if ( preg_match( '/^[a-z0-9-]+$/', $module_id ) && is_file( $file ) ) {
			$data = include $file;
			if ( is_array( $data ) ) {
				$entries = $data;
			}
		}
		self::$loaded[ $locale ][ $module_id ] = $entries;
		return $entries;
	}

	/**
	 * Forget the evaluated files (tests that change the locale or the filter).
	 */
	public static function flush(): void {
		self::$loaded = array();
	}
}
