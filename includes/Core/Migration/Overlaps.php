<?php
/**
 * Detection of overlaps between enabled UX Studio modules and active foreign plugins.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Two kinds of answer, both cached per request:
 *
 *  - current()    : the module is ENABLED and the foreign plugin is ACTIVE (and the
 *                   catalog condition on the module's settings is met). This is what
 *                   the Modules screen and the admin notice report.
 *  - for_module() : "potential" overlaps for one module regardless of whether it is
 *                   enabled - what the ActivationGuard asks before switching it on.
 *
 * Nothing here changes any state except the ignore list.
 */
final class Overlaps {

	public const GROUP_FULL       = 'full';
	public const GROUP_CUSTOMISER = 'customiser';
	public const GROUP_PARTIAL    = 'partial';

	private const IGNORED_OPTION = 'uxstudio_overlap_ignored';
	// Same option Core\Modules keeps its enabled ids in (read directly: this
	// class must stay usable before/without the registry being booted).
	private const MODULES_OPTION = 'uxstudio_active_modules';

	/** @var string[]|null Active plugin basenames. */
	private static ?array $active = null;

	/** @var string[]|null Enabled module ids. */
	private static ?array $enabled = null;

	/** @var array<string, array<string, mixed>> Resolved module settings by module id. */
	private static array $settings = array();

	/** @var array<int, array<string, mixed>>|null */
	private static ?array $current = null;

	/** @var array<string, array<int, array<string, mixed>>> */
	private static array $potential = array();

	/** @var callable|null Test seam: module id => settings array. */
	private static $settings_resolver = null;

	/** @var callable|null Test seam: foreign condition (array) => bool. */
	private static $foreign_reader = null;

	/**
	 * Forget everything cached for this request (after plugins/modules/settings changed).
	 */
	public static function flush(): void {
		self::$active    = null;
		self::$enabled   = null;
		self::$settings  = array();
		self::$current   = null;
		self::$potential = array();
	}

	/**
	 * Replace how module settings are read (tests only).
	 *
	 * @param callable|null $resolver Receives a module id, returns its settings.
	 */
	public static function set_settings_resolver( ?callable $resolver ): void {
		self::$settings_resolver = $resolver;
		self::flush();
	}

	/**
	 * Replace how conditions on a foreign plugin's options / files are read (tests only).
	 *
	 * @param callable|null $reader Receives a condition array, returns whether it holds.
	 */
	public static function set_foreign_reader( ?callable $reader ): void {
		self::$foreign_reader = $reader;
		self::flush();
	}

	/**
	 * Does one condition on a foreign plugin hold? Shapes:
	 *  - `array( 'option' => 'x' )`                         the option is truthy,
	 *  - `array( 'option' => 'x', 'key' => 'k' )`           the option is an array (or JSON) with a truthy `k`,
	 *  - `array( 'option' => 'x', 'contains' => array(..) )` the option is a list holding any of the values,
	 *  - `array( 'file' => 'llms.txt' )`                     the file exists in the site root.
	 *
	 * @param array<string, mixed> $cond Condition.
	 */
	public static function foreign_condition_met( array $cond ): bool {
		if ( null !== self::$foreign_reader ) {
			return (bool) call_user_func( self::$foreign_reader, $cond );
		}
		if ( isset( $cond['file'] ) ) {
			return defined( 'ABSPATH' ) && '' !== (string) $cond['file'] && file_exists( ABSPATH . ltrim( (string) $cond['file'], '/' ) );
		}
		if ( ! isset( $cond['option'] ) ) {
			return false;
		}
		$value = get_option( (string) $cond['option'], null );
		if ( is_string( $value ) && '' !== $value && '{' === $value[0] ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : $value;
		}
		if ( isset( $cond['contains'] ) ) {
			return is_array( $value ) && (bool) array_intersect( array_map( 'strval', (array) $cond['contains'] ), array_map( 'strval', $value ) );
		}
		if ( isset( $cond['key'] ) ) {
			return is_array( $value ) && isset( $value[ $cond['key'] ] ) && self::truthy( $value[ $cond['key'] ] );
		}
		return self::truthy( $value );
	}

	/**
	 * Option values meaning "on" in the wild: true, 1, '1', 'on', 'yes', 'true'.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function truthy( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'on', 'yes', 'true', 'enabled' ), true );
	}

	/**
	 * Apply the foreign-feature condition of an entry: the entry as it counts right now, or null when it
	 * does not apply at all. Entries without `foreign_when` are returned unchanged.
	 *
	 * @param array<string, mixed> $entry Catalog entry.
	 * @return array<string, mixed>|null
	 */
	private static function effective_entry( array $entry ): ?array {
		if ( empty( $entry['foreign_when'] ) ) {
			return $entry;
		}
		foreach ( (array) $entry['foreign_when'] as $cond ) {
			if ( is_array( $cond ) && self::foreign_condition_met( $cond ) ) {
				return $entry;
			}
		}
		if ( 'none' === ( $entry['foreign_else'] ?? 'partial' ) ) {
			return null;
		}
		$entry['type'] = Catalog::TYPE_PARTIAL;
		return $entry;
	}

	/**
	 * Settings of a module that an active foreign plugin blocks, as setting keys: the `when` keys of full
	 * catalog entries whose plugin is active (and whose foreign feature is on). The onboarding starter pack
	 * leaves these settings out instead of creating an overlap the activation guard would have refused.
	 *
	 * @param string $module_id Module id.
	 * @return array<string, string[]> Setting key => names of the plugins that block it.
	 */
	public static function blocked_settings( string $module_id ): array {
		$active = self::active_by_slug();
		$out    = array();
		if ( ! $active ) {
			return $out;
		}
		foreach ( Catalog::entries() as $entry ) {
			if ( $entry['module'] !== $module_id || Catalog::TYPE_FULL !== $entry['type'] || empty( $entry['when'] ) ) {
				continue;
			}
			$entry = self::effective_entry( $entry );
			if ( null === $entry || Catalog::TYPE_FULL !== $entry['type'] ) {
				continue;
			}
			foreach ( array_keys( $entry['plugins'] ) as $slug ) {
				if ( ! isset( $active[ (string) $slug ] ) ) {
					continue;
				}
				foreach ( (array) $entry['when'] as $key ) {
					$out[ (string) $key ][] = self::plugin_name( $active[ (string) $slug ], (string) $entry['plugins'][ $slug ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Basenames of active plugins, network-wide ones included.
	 *
	 * @return string[]
	 */
	public static function active_plugins(): array {
		if ( null !== self::$active ) {
			return self::$active;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		self::$active = array_values( array_unique( array_map( 'strval', $active ) ) );
		return self::$active;
	}

	/**
	 * Active plugins indexed by folder slug (slug => first matching basename).
	 *
	 * @return array<string, string>
	 */
	private static function active_by_slug(): array {
		$by_slug = array();
		foreach ( self::active_plugins() as $basename ) {
			$slug = Catalog::slug_of( $basename );
			if ( ! isset( $by_slug[ $slug ] ) ) {
				$by_slug[ $slug ] = $basename;
			}
		}
		return $by_slug;
	}

	/**
	 * Enabled module ids.
	 *
	 * @return string[]
	 */
	private static function enabled_modules(): array {
		if ( null === self::$enabled ) {
			self::$enabled = array_values( array_map( 'strval', (array) get_option( self::MODULES_OPTION, array() ) ) );
		}
		return self::$enabled;
	}

	/**
	 * Settings of a module, defaults merged in when the registry is available.
	 *
	 * @param string $module_id Module id.
	 * @return array<string, mixed>
	 */
	private static function module_settings( string $module_id ): array {
		if ( isset( self::$settings[ $module_id ] ) ) {
			return self::$settings[ $module_id ];
		}
		$values = array();
		if ( null !== self::$settings_resolver ) {
			$values = (array) call_user_func( self::$settings_resolver, $module_id );
		} elseif ( class_exists( '\UxStudio\Plugin' ) && isset( \UxStudio\Plugin::instance()->modules ) ) {
			$module = \UxStudio\Plugin::instance()->modules->instance( $module_id );
			$values = $module ? $module->settings_values() : array();
		} else {
			$values = (array) get_option( 'uxstudio_' . str_replace( '-', '_', $module_id ), array() );
		}
		self::$settings[ $module_id ] = $values;
		return $values;
	}

	/**
	 * Whether the entry's setting condition holds (no condition = always).
	 *
	 * @param array<string, mixed> $entry Catalog entry.
	 */
	private static function condition_met( array $entry ): bool {
		if ( empty( $entry['when'] ) ) {
			return true;
		}
		$settings = self::module_settings( (string) $entry['module'] );
		foreach ( (array) $entry['when'] as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Display name of an installed plugin (falls back to the catalog label).
	 *
	 * @param string $basename Plugin basename.
	 * @param string $fallback Catalog label.
	 */
	private static function plugin_name( string $basename, string $fallback ): string {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$all = get_plugins();
			if ( ! empty( $all[ $basename ]['Name'] ) ) {
				return (string) $all[ $basename ]['Name'];
			}
		}
		return $fallback;
	}

	/**
	 * Build the result row for one matching plugin.
	 *
	 * @param array<string, mixed> $entry    Catalog entry.
	 * @param string               $slug     Folder slug.
	 * @param string               $basename Active plugin basename.
	 * @return array<string, mixed>
	 */
	private static function row( array $entry, string $slug, string $basename ): array {
		$type = (string) $entry['type'];
		if ( Catalog::TYPE_FULL === $type ) {
			$group = self::GROUP_FULL;
		} elseif ( ! empty( $entry['confirm'] ) ) {
			$group = self::GROUP_CUSTOMISER;
		} else {
			$group = self::GROUP_PARTIAL;
		}
		return array(
			'key'      => $basename . '>' . $entry['module'],
			'basename' => $basename,
			'slug'     => $slug,
			'name'     => self::plugin_name( $basename, (string) ( $entry['plugins'][ $slug ] ?? $slug ) ),
			'module'   => (string) $entry['module'],
			'type'     => $type,
			'group'    => $group,
			'confirm'  => ! empty( $entry['confirm'] ),
			'feature'  => (string) ( $entry['feature'] ?? '' ),
		);
	}

	/**
	 * Overlaps of enabled modules with active plugins.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$enabled = self::enabled_modules();
		$active  = self::active_by_slug();
		$rows    = array();
		if ( $enabled && $active ) {
			foreach ( Catalog::entries() as $entry ) {
				if ( Catalog::ANY_MODULE === $entry['module'] ? ! $enabled : ! in_array( $entry['module'], $enabled, true ) ) {
					continue;
				}
				if ( ! self::condition_met( $entry ) ) {
					continue;
				}
				$entry = self::effective_entry( $entry );
				if ( null === $entry ) {
					continue;
				}
				foreach ( array_keys( $entry['plugins'] ) as $slug ) {
					if ( isset( $active[ (string) $slug ] ) ) {
						$rows[] = self::row( $entry, (string) $slug, $active[ (string) $slug ] );
					}
				}
			}
		}
		self::$current = $rows;
		return $rows;
	}

	/**
	 * Overlaps a module WOULD have with the currently active plugins, whether or
	 * not it is enabled right now. Entries for the wildcard module apply to every id.
	 *
	 * @param string $module_id Module id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_module( string $module_id ): array {
		if ( isset( self::$potential[ $module_id ] ) ) {
			return self::$potential[ $module_id ];
		}
		$active = self::active_by_slug();
		$rows   = array();
		if ( $active ) {
			foreach ( Catalog::entries() as $entry ) {
				if ( $entry['module'] !== $module_id && Catalog::ANY_MODULE !== $entry['module'] ) {
					continue;
				}
				// The condition looks at the module the guard is about, not at the wildcard.
				$probe = Catalog::ANY_MODULE === $entry['module'] ? array_merge( $entry, array( 'module' => $module_id ) ) : $entry;
				if ( ! self::condition_met( $probe ) ) {
					continue;
				}
				$entry = self::effective_entry( $entry );
				if ( null === $entry ) {
					continue;
				}
				foreach ( array_keys( $entry['plugins'] ) as $slug ) {
					if ( isset( $active[ (string) $slug ] ) ) {
						$rows[] = self::row( $entry, (string) $slug, $active[ (string) $slug ] );
					}
				}
			}
		}
		self::$potential[ $module_id ] = $rows;
		return $rows;
	}

	/**
	 * Overlap keys the admin chose to ignore.
	 *
	 * @return string[]
	 */
	public static function ignored_keys(): array {
		return array_values( array_filter( array_map( 'strval', (array) get_option( self::IGNORED_OPTION, array() ) ) ) );
	}

	/**
	 * Whether a key has the `<plugin basename>><module id>` shape.
	 *
	 * @param string $key Candidate key.
	 */
	public static function is_valid_key( string $key ): bool {
		return strlen( $key ) <= 200 && 1 === preg_match( '#^[A-Za-z0-9_.\-/]+>(?:\*|[a-z0-9\-]+)$#', $key );
	}

	/**
	 * Add keys to the ignore list.
	 *
	 * @param string[] $keys Keys to ignore.
	 * @return string[] Keys that were really added.
	 */
	public static function ignore( array $keys ): array {
		$keys    = array_values( array_filter( $keys, static fn( $k ) => is_string( $k ) && self::is_valid_key( $k ) ) );
		$stored  = self::ignored_keys();
		$added   = array_values( array_diff( $keys, $stored ) );
		if ( $added ) {
			update_option( self::IGNORED_OPTION, array_values( array_unique( array_merge( $stored, $added ) ) ), false );
		}
		return $added;
	}

	/**
	 * Remove keys from the ignore list.
	 *
	 * @param string[] $keys Keys to restore.
	 * @return string[] Keys that were really removed.
	 */
	public static function unignore( array $keys ): array {
		$stored  = self::ignored_keys();
		$removed = array_values( array_intersect( $stored, array_map( 'strval', $keys ) ) );
		if ( $removed ) {
			update_option( self::IGNORED_OPTION, array_values( array_diff( $stored, $removed ) ), false );
		}
		return $removed;
	}

	/**
	 * Current overlaps grouped for the UI; ignored ones are split off.
	 *
	 * @return array{full:array,customiser:array,partial:array,ignored:array}
	 */
	public static function grouped(): array {
		$out     = array(
			self::GROUP_FULL       => array(),
			self::GROUP_CUSTOMISER => array(),
			self::GROUP_PARTIAL    => array(),
			'ignored'              => array(),
		);
		$ignored = self::ignored_keys();
		foreach ( self::current() as $row ) {
			if ( in_array( $row['key'], $ignored, true ) ) {
				$out['ignored'][] = $row;
			} else {
				$out[ $row['group'] ][] = $row;
			}
		}
		return $out;
	}
}
