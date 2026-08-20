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

	/** @var array<string, array> Module id => meta.json contents. */
	private array $meta = array();

	/** @var array<string, BaseModule> Booted module instances. */
	private array $instances = array();

	/**
	 * Discover modules and boot the enabled ones.
	 */
	public function boot(): void {
		$this->meta = $this->discover();

		/**
		 * Extension API: add-on plugins register additional modules.
		 * Each entry: id => ['dir' => ..., 'meta' => [...], 'class' => FQCN].
		 *
		 * @param array $meta Discovered module metadata, keyed by id.
		 */
		$this->meta = apply_filters( 'ux_studio/modules', $this->meta );

		$enabled = $this->enabled_ids();
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
		$enabled = $this->enabled_ids();
		if ( $enable ) {
			$enabled[] = $id;
		} else {
			$enabled = array_diff( $enabled, array( $id ) );
		}
		update_option( self::OPTION, array_values( array_unique( $enabled ) ) );
		return true;
	}

	/**
	 * Disable every module at once. Returns how many were active.
	 */
	public function deactivate_all(): int {
		$count = count( $this->enabled_ids() );
		update_option( self::OPTION, array() );
		return $count;
	}

	/**
	 * Scan includes/Modules for module folders with meta.json.
	 *
	 * @return array<string, array>
	 */
	private function discover(): array {
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
		$module = $this->instance( $id );
		if ( $module ) {
			$module->boot();
		}
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
