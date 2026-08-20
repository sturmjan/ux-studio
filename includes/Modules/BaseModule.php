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
	 * Settings schema for the generic SPA settings renderer. Field types:
	 * toggle | text | textarea | number | select | multiselect | color | media | richtext.
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
