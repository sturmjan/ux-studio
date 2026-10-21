<?php
/**
 * Shared behaviour of the settings-only migration sources.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * A concrete source implements `map()` (what to write, what is lost) and
 * `option_names()` (which options of the foreign plugin hold its data). Everything else -
 * detection, plan, dry run, write, verify - is generic.
 */
abstract class AbstractSource implements Source {

	protected string $slug;

	private ?Mapping $mapping = null;

	/**
	 * @param string $slug Folder slug of the plugin this instance covers.
	 */
	public function __construct( string $slug ) {
		$this->slug = $slug;
	}

	/**
	 * Build the mapping from the foreign plugin's current options. Read-only.
	 *
	 * @param bool $active Whether the plugin is active (some plugins act on defaults only while active).
	 */
	abstract protected function map( bool $active ): Mapping;

	/**
	 * Options of the foreign plugin that hold its settings (used to tell "has data").
	 *
	 * @return string[]
	 */
	protected function option_names(): array {
		return array();
	}

	public function id(): string {
		return $this->slug;
	}

	public function plugin_slugs(): array {
		return array( $this->slug );
	}

	public function target_module(): string {
		$entry = Catalog::entry_for_slug( $this->slug );
		return (string) ( $entry['module'] ?? '' );
	}

	public function match_type(): string {
		$entry = Catalog::entry_for_slug( $this->slug );
		return (string) ( $entry['type'] ?? Catalog::TYPE_FULL );
	}

	public function label(): string {
		$info = PluginInfo::find( $this->slug );
		if ( $info && '' !== $info['name'] ) {
			return $info['name'];
		}
		$entry = Catalog::entry_for_slug( $this->slug );
		return (string) ( $entry['plugins'][ $this->slug ] ?? $this->slug );
	}

	/**
	 * Read an option of the foreign plugin; null when it does not exist.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	protected function opt( string $name ) {
		$missing = new \stdClass();
		$value   = get_option( $name, $missing );
		return $value === $missing ? null : $value;
	}

	/**
	 * Whether any of the plugin's option rows exist.
	 */
	protected function has_data(): bool {
		foreach ( $this->option_names() as $name ) {
			if ( null !== $this->opt( $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * All role ids of the site (empty when roles are not loaded, e.g. in unit tests).
	 *
	 * @return string[]
	 */
	protected function role_ids(): array {
		return function_exists( 'wp_roles' ) ? array_map( 'strval', array_keys( (array) wp_roles()->roles ) ) : array();
	}

	/**
	 * Role ids holding a capability.
	 *
	 * @param string $cap Capability.
	 * @return string[]
	 */
	protected function roles_with_cap( string $cap ): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) wp_roles()->roles as $id => $role ) {
			if ( ! empty( $role['capabilities'][ $cap ] ) ) {
				$out[] = (string) $id;
			}
		}
		return $out;
	}

	/**
	 * The mapping, built once per source instance.
	 */
	protected function mapping(): Mapping {
		if ( null === $this->mapping ) {
			$info    = PluginInfo::find( $this->slug );
			$active  = (bool) ( $info['active'] ?? false );
			$mapping = $this->map( $active );

			if ( ! $active && ! $mapping->is_empty() ) {
				$mapping->warn( __( 'The plugin is not active, so these settings do not apply on the site right now. They are transferred as they were left in the plugin; they take effect once the module is switched on.', 'ux-studio' ) );
			}
			$module = $this->target_module();
			if ( '' !== $module && ! $mapping->is_empty() && $this->module_enabled( $module ) ) {
				$mapping->warn(
					sprintf(
						/* translators: %s: module name. */
						__( 'The module "%s" is already switched on, so the transferred settings take effect immediately.', 'ux-studio' ),
						ActivationGuard::module_name( $module )
					)
				);
			}
			$this->mapping = $mapping;
		}
		return $this->mapping;
	}

	/**
	 * Whether a module is currently switched on.
	 *
	 * @param string $module Module id.
	 */
	protected function module_enabled( string $module ): bool {
		return in_array( $module, array_map( 'strval', (array) get_option( 'uxstudio_active_modules', array() ) ), true );
	}

	/**
	 * Throw away the cached mapping (after the options changed).
	 */
	public function forget(): void {
		$this->mapping = null;
	}

	public function detect(): array {
		$info      = PluginInfo::find( $this->slug );
		$installed = null !== $info;
		$active    = (bool) ( $info['active'] ?? false );
		$has_data  = $this->has_data();

		if ( $active ) {
			$status = 'active';
		} elseif ( $installed ) {
			$status = 'inactive';
		} elseif ( $has_data ) {
			$status = 'data_only';
		} else {
			$status = 'absent';
		}

		$settings = 0;
		if ( 'absent' !== $status ) {
			$settings = count( $this->mapping()->items );
		}
		$module = $this->target_module();

		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'module'      => $module,
			'module_name' => ActivationGuard::module_name( $module ),
			'match'       => $this->match_type(),
			'installed'   => $installed,
			'active'      => $active,
			'version'     => (string) ( $info['version'] ?? '' ),
			'basename'    => (string) ( $info['basename'] ?? '' ),
			'has_data'    => $has_data,
			'status'      => $status,
			'summary'     => array( 'settings' => $settings ),
		);
	}

	public function plan(): array {
		$mapping = $this->mapping();
		$preview = SettingsWriter::preview( $mapping );
		$module  = $this->target_module();

		$warnings = $mapping->warnings;
		foreach ( $preview['items'] as $row ) {
			if ( $row['dropped'] && ! $row['secret'] ) {
				$warnings[] = sprintf(
					/* translators: %s: setting name. */
					__( 'The module will not accept the whole value of "%s" (for example a post type or role that does not exist on this site). The rest is transferred.', 'ux-studio' ),
					$row['label']
				);
			}
		}
		$warnings = array_merge( $warnings, $preview['errors'] );

		$changed = 0;
		foreach ( $preview['items'] as $row ) {
			if ( $row['changed'] ) {
				++$changed;
			}
		}

		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'module'      => $module,
			'module_name' => ActivationGuard::module_name( $module ),
			'transfer'    => $preview['items'],
			'lossy'       => $mapping->lossy,
			'warnings'    => array_values( array_unique( $warnings ) ),
			'notes'       => $mapping->notes,
			'counts'      => array(
				'transfer' => count( $preview['items'] ),
				'changed'  => $changed,
				'lossy'    => count( $mapping->lossy ),
			),
		);
	}

	public function target_options(): array {
		$mapping = $this->mapping();
		$names   = array();
		foreach ( array_keys( $mapping->settings ) as $module ) {
			$names[] = SettingsWriter::option_name( (string) $module );
		}
		foreach ( array_keys( $mapping->secrets ) as $option ) {
			$names[] = (string) $option;
		}
		return array_values( array_unique( $names ) );
	}

	public function migrate( array $state, bool $dry_run ): array {
		$mapping = $this->mapping();
		if ( $dry_run ) {
			$preview = SettingsWriter::preview( $mapping );
			$write   = 0;
			foreach ( $preview['items'] as $row ) {
				if ( $row['write'] ) {
					++$write;
				}
			}
			return array(
				'done'      => true,
				'progress'  => 100,
				'items'     => $preview['items'],
				'written'   => $write,
				'unchanged' => count( $preview['items'] ) - $write,
				'errors'    => $preview['errors'],
			);
		}

		$result = SettingsWriter::apply( $mapping );
		$run_id = (string) ( $state['run_id'] ?? '' );
		if ( '' !== $run_id ) {
			foreach ( $result['items'] as $row ) {
				MigrationMap::put( $this->slug, (string) $row['origin'] . ' > ' . (string) $row['key'], 'setting', (string) $row['target'], $run_id );
			}
		}
		$this->forget();

		return array(
			'done'      => true,
			'progress'  => 100,
			'items'     => $result['items'],
			'written'   => $result['written'],
			'unchanged' => $result['unchanged'],
			'errors'    => $result['errors'],
		);
	}

	public function verify(): array {
		$this->forget();
		$mapping = $this->mapping();
		if ( $mapping->is_empty() ) {
			return array(
				array(
					'id'     => 'nothing',
					'label'  => __( 'Nothing to compare', 'ux-studio' ),
					'ok'     => true,
					'detail' => implode( ' ', $mapping->notes ),
				),
			);
		}
		return SettingsWriter::verify( $mapping );
	}
}
