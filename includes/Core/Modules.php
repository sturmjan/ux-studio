<?php
/**
 * Module registry: discovery, enable/disable state, lazy boot.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers modules (includes/Modules/<id>/Module.php + meta.json), keeps
 * their enabled state in one option and boots only the enabled ones.
 */
final class Modules {

	private const OPTION = 'uxstudio_active_modules';

	/** Cached discovery result (see discover()). */
	private const META_CACHE_OPTION = 'uxstudio_modules_meta_cache';

	/** @var array<string, array> Module id => meta.json contents. */
	private array $meta = array();

	/** @var array<string, BaseModule> Booted module instances. */
	private array $instances = array();

	/** @var array<string, string> Module id => error, for modules skipped this request. */
	private array $boot_failures = array();

	/**
	 * Discover modules and boot the enabled ones.
	 */
	public function boot(): void {
		$this->meta = self::discover();

		/**
		 * Extension API: add-on plugins register additional modules.
		 * Each entry: id => ['dir' => ..., 'meta' => [...], 'class' => FQCN].
		 *
		 * @param array $meta Discovered module metadata, keyed by id.
		 */
		$this->meta = apply_filters( 'ux_studio/modules', $this->meta );
		$this->meta = self::link_groups( $this->meta );

		$enabled = $this->enabled_ids();

		// Settings of enabled modules are autoloaded (DB v2); this batches the
		// rest - never-saved (non-existent) or oversized ones - into a single
		// query instead of one SELECT per module when each reads its settings.
		if ( function_exists( 'wp_prime_option_caches' ) ) {
			wp_prime_option_caches(
				array_map(
					static function ( string $id ): string {
						return 'uxstudio_' . str_replace( '-', '_', $id );
					},
					$enabled
				)
			);
		}

		foreach ( $enabled as $id ) {
			$this->boot_module( $id );
		}
	}

	/**
	 * All known modules with metadata (enabled or not).
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		return $this->meta;
	}

	/**
	 * Enabled module ids.
	 *
	 * @return string[]
	 */
	public function enabled_ids(): array {
		$enabled = get_option( self::OPTION, array() );
		return array_values( array_intersect( (array) $enabled, array_keys( $this->meta ) ) );
	}

	/**
	 * Enable or disable a module (persisted).
	 *
	 * @param string $id     Module id.
	 * @param bool   $enable New state.
	 */
	public function set_enabled( string $id, bool $enable ): bool {
		if ( ! isset( $this->meta[ $id ] ) ) {
			return false;
		}
		// A virtual entry is never stored itself - even one whose children all
		// disappeared (removed module, filtered out) must not end up "enabled".
		if ( ! empty( $this->meta[ $id ]['virtual'] ) && ! self::is_group( $this->meta[ $id ] ) ) {
			return false;
		}
		// A group has no state of its own: switching it switches every feature in it.
		if ( self::is_group( $this->meta[ $id ] ) ) {
			foreach ( $this->meta[ $id ]['children'] as $child ) {
				$this->set_enabled( (string) $child, $enable );
			}
			return true;
		}
		$enabled     = $this->enabled_ids();
		$was_enabled = in_array( $id, $enabled, true );
		if ( $enable ) {
			$enabled[] = $id;
		} else {
			$enabled = array_diff( $enabled, array( $id ) );
		}
		update_option( self::OPTION, array_values( array_unique( $enabled ) ) );
		if ( ! $enable && $was_enabled ) {
			$this->run_on_disable( $id );
		}
		return true;
	}

	/**
	 * Whether a meta entry is a virtual group: a tile that bundles several
	 * modules ("features") which keep their own enabled state and settings.
	 *
	 * @param array $meta Module meta.
	 */
	public static function is_group( array $meta ): bool {
		return ! empty( $meta['virtual'] ) && ! empty( $meta['children'] ) && is_array( $meta['children'] );
	}

	/**
	 * Resolve group membership: drop group children that don't exist and tag
	 * each child with `parent`, so the UI can nest it under its group tile.
	 *
	 * @param array<string, array> $meta Module meta keyed by id.
	 * @return array<string, array>
	 */
	private static function link_groups( array $meta ): array {
		foreach ( $meta as $id => $data ) {
			if ( ! self::is_group( $data ) ) {
				continue;
			}
			$children = array_values( array_filter( $data['children'], static fn( $c ) => isset( $meta[ $c ] ) && ! self::is_group( $meta[ $c ] ) ) );
			$meta[ $id ]['children'] = $children;
			foreach ( $children as $child ) {
				$meta[ $child ]['parent'] = $id;
			}
		}
		return $meta;
	}

	/**
	 * Disable every module at once. Returns how many were active.
	 */
	public function deactivate_all(): int {
		$ids = $this->enabled_ids();
		update_option( self::OPTION, array() );
		foreach ( $ids as $id ) {
			$this->run_on_disable( $id );
		}
		return count( $ids );
	}

	/**
	 * Plugin deactivation: undo lasting side effects of every enabled module but
	 * keep the enabled list, so reactivating the plugin restores the same setup.
	 */
	public function cleanup_for_plugin_deactivation(): void {
		foreach ( $this->enabled_ids() as $id ) {
			$this->run_on_disable( $id );
		}
	}

	/**
	 * @param string $id Module id.
	 */
	private function run_on_disable( string $id ): void {
		$module = $this->instance( $id );
		if ( ! $module ) {
			return;
		}
		try {
			$module->on_disable();
		} catch ( \Throwable $e ) {
			// A failing cleanup must never block switching a module off.
			error_log( 'UX Studio: on_disable failed for ' . $id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Ids of all built-in modules (discovered, cached), without booting the
	 * registry - e.g. for DB migrations that run before Modules::boot().
	 *
	 * @return string[]
	 */
	public static function module_ids(): array {
		return array_keys( self::discover() );
	}

	/**
	 * Built-in module meta, cached in one autoloaded option so a normal request
	 * doesn't glob + read + json_decode ~70 meta.json files.
	 *
	 * Cache key = plugin version + install path + mtime of includes/Modules
	 * (changes when a module folder is added/removed). A plugin update always
	 * bumps UXSTUDIO_VERSION, which invalidates it. With WP_DEBUG on (dev) the
	 * newest meta.json mtime joins the key too, so editing a meta.json shows up
	 * immediately; in production an in-place meta.json edit without a version
	 * bump needs the option `uxstudio_modules_meta_cache` deleted.
	 *
	 * @return array<string, array>
	 */
	private static function discover(): array {
		$base = UXSTUDIO_PATH . 'includes/Modules';
		$key  = UXSTUDIO_VERSION . '|' . UXSTUDIO_PATH . '|' . (int) @filemtime( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$newest = 0;
			foreach ( glob( $base . '/*/meta.json' ) ?: array() as $file ) {
				$newest = max( $newest, (int) filemtime( $file ) );
			}
			$key .= '|' . $newest;
		}

		$cached = get_option( self::META_CACHE_OPTION );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $key && is_array( $cached['meta'] ?? null ) ) {
			return $cached['meta'];
		}

		$meta = self::scan();
		update_option(
			self::META_CACHE_OPTION,
			array(
				'key'  => $key,
				'meta' => $meta,
			),
			true
		);
		return $meta;
	}

	/**
	 * Scan includes/Modules for module folders with meta.json.
	 *
	 * @return array<string, array>
	 */
	private static function scan(): array {
		$meta = array();
		$dirs = glob( UXSTUDIO_PATH . 'includes/Modules/*', GLOB_ONLYDIR ) ?: array();
		foreach ( $dirs as $dir ) {
			$file = $dir . '/meta.json';
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$data = json_decode( (string) file_get_contents( $file ), true );
			if ( ! is_array( $data ) || empty( $data['id'] ) ) {
				continue;
			}
			$data['dir']          = $dir;
			$meta[ $data['id'] ] = $data;
		}
		return $meta;
	}

	/**
	 * Instantiate and boot a single module (lazy: only enabled ones).
	 *
	 * @param string $id Module id.
	 */
	private function boot_module( string $id ): void {
		try {
			$module = $this->instance( $id );
			if ( $module ) {
				$module->boot();
			}
		} catch ( \Throwable $e ) {
			// A broken module (missing class file, parse error, fatal in boot) must
			// never take the whole site down: skip it for this request and report
			// it in wp-admin. Not persisted as "disabled" - once the file is back
			// (restored, plugin updated) the module boots again on its own.
			unset( $this->instances[ $id ] );
			$this->record_boot_failure( $id, $e );
		}
	}

	/**
	 * Remember a module boot failure and show it to admins.
	 *
	 * @param string     $id Module id.
	 * @param \Throwable $e  What went wrong.
	 */
	private function record_boot_failure( string $id, \Throwable $e ): void {
		$message = sprintf( '%s: %s (%s:%d)', get_class( $e ), $e->getMessage(), wp_basename( $e->getFile() ), $e->getLine() );
		error_log( 'UX Studio: module ' . $id . ' failed to boot and was skipped - ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		$this->boot_failures[ $id ] = $message;
		if ( 1 === count( $this->boot_failures ) ) {
			add_action( 'admin_notices', array( $this, 'render_boot_failures' ) );
		}
	}

	/**
	 * Admin notice listing modules skipped in this request.
	 */
	public function render_boot_failures(): void {
		if ( empty( $this->boot_failures ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'UX Studio: some modules failed to start and were turned off for now. The rest of the site keeps running.', 'ux-studio' ) . '</strong></p><ul>';
		foreach ( $this->boot_failures as $id => $message ) {
			$name = (string) ( $this->meta[ $id ]['name'] ?? $id );
			echo '<li><code>' . esc_html( $name ) . '</code> - ' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Modules that failed to boot in this request (id => error message).
	 *
	 * @return array<string, string>
	 */
	public function boot_failures(): array {
		return $this->boot_failures;
	}

	/**
	 * Get (or lazily create) a module instance, e.g. for reading its settings
	 * schema. Does NOT call boot() - hooks are only registered for enabled
	 * modules during boot().
	 *
	 * @param string $id Module id.
	 */
	public function instance( string $id ): ?BaseModule {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( ! isset( $this->meta[ $id ] ) ) {
			return null;
		}
		$meta  = $this->meta[ $id ];
		$class = $meta['class'] ?? 'UxStudio\\Modules\\' . self::class_slug( $id ) . '\\Module';
		if ( ! class_exists( $class ) || ! is_subclass_of( $class, BaseModule::class ) ) {
			return null;
		}
		$this->instances[ $id ] = new $class( $id, $meta );
		return $this->instances[ $id ];
	}

	/**
	 * kebab-case id => PascalCase namespace segment (opening-hours => OpeningHours).
	 */
	public static function class_slug( string $id ): string {
		return str_replace( ' ', '', ucwords( str_replace( '-', ' ', $id ) ) );
	}
}
