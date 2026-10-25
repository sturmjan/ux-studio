<?php
/**
 * Base class for all UX Studio modules.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A module = meta.json + Module.php (extends this) + optional REST controller
 * + optional settings schema consumed by the generic SPA settings renderer.
 */
abstract class BaseModule {

	protected string $id;

	/** @var array meta.json contents. */
	protected array $meta;

	protected Settings $settings;

	/**
	 * @param string $id   Module id (kebab-case).
	 * @param array  $meta meta.json contents.
	 */
	public function __construct( string $id, array $meta ) {
		$this->id       = $id;
		$this->meta     = $meta;
		$this->settings = new Settings( 'uxstudio_' . str_replace( '-', '_', $id ) );
	}

	/** Module id. */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Register hooks. Called once for enabled modules on plugins_loaded.
	 */
	abstract public function boot(): void;

	/**
	 * Undo side effects that outlive the request (mu-plugins, .htaccess blocks,
	 * cron events) when the module is switched off or the plugin deactivated.
	 * Data (tables, options) is kept, so re-enabling restores the previous state.
	 * Runs on a module instance that was NOT necessarily booted.
	 */
	public function on_disable(): void {
	}

	/**
	 * Options (besides the module settings) the module changes when its settings take effect, for example
	 * the hash of the last written `.htaccess` block. The migration switch saves them first, so undoing the
	 * switch restores them exactly.
	 *
	 * @return string[]
	 */
	public function switch_options(): array {
		return array();
	}

	/**
	 * Make newly written settings effective the way a normal settings save would: rewrite `.htaccess`
	 * blocks, sync bans, schedule events. Called by the migration switch (and by its undo) AFTER the
	 * module was switched on / the options were written. Default: just re-read the settings.
	 */
	public function after_migration_switch(): void {
		$this->settings = new Settings( 'uxstudio_' . str_replace( '-', '_', $this->id ) );
	}

	/**
	 * Settings schema for the generic SPA settings renderer. Field types:
	 * toggle | text | textarea | css | number | select | multiselect | color | media | datetime | richtext.
	 * Empty array = module has no settings screen.
	 *
	 * @return array<int, array{key:string,type:string,label:string,default?:mixed,options?:array,help?:string}>
	 */
	public function settings_schema(): array {
		return array();
	}

	/**
	 * Current settings values merged over schema defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_values(): array {
		$values = array();
		foreach ( $this->settings_schema() as $field ) {
			$values[ $field['key'] ] = $this->settings->get( $field['key'], $field['default'] ?? null );
		}
		return $values;
	}

	/**
	 * Validate + persist settings input (from REST).
	 *
	 * @param array $input Raw input.
	 * @return array<string, mixed> Stored values.
	 */
	public function save_settings( array $input ): array {
		$this->settings->save( $input, $this->settings_schema() );
		return $this->settings_values();
	}

	/**
	 * REST controller class to register, or null.
	 *
	 * @return class-string|null
	 */
	public function rest_controller(): ?string {
		return null;
	}

	/**
	 * Capability required to manage this module. Modules with riskier surface
	 * (code execution, filesystem) must override with a stricter capability.
	 */
	public function capability(): string {
		return 'manage_options';
	}
}
