<?php
/**
 * Source: WP Extended (origin of the UX1 plugin) - reuses Core\Migrator.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\MigrationMap;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Core\Migration\SettingsWriter;
use UxStudio\Core\Migrator;

defined( 'ABSPATH' ) || exit;

/**
 * Settings of every module (`wpextended__<id>_settings` => `uxstudio_<id>`) and the standalone
 * keys. Migrator only ADDS what is missing (add_option), so settings already present
 * in UX Studio are kept. The run uses the settings-only mode (no table copies); the
 * result is judged by Migrator's own mapping (verify = does every target exist).
 */
final class WpExtendedSource extends AbstractSource {

	protected function option_names(): array {
		return array_keys( Migrator::option_pairs() );
	}

	public function target_module(): string {
		return '*';
	}

	protected function map( bool $active ): Mapping {
		return new Mapping();
	}

	/**
	 * Pairs that exist in the source, with the state of their target.
	 *
	 * @return array<int, array{old:string,new:string,exists:bool}>
	 */
	private function pairs(): array {
		$out = array();
		foreach ( Migrator::option_pairs() as $old => $new ) {
			if ( null === $this->opt( $old ) ) {
				continue;
			}
			$out[] = array(
				'old'    => $old,
				'new'    => $new,
				'exists' => null !== $this->opt( $new ),
			);
		}
		return $out;
	}

	/**
	 * Rows in the same shape the generic sources return.
	 *
	 * @param array<int, array{old:string,new:string,exists:bool}> $pairs Pairs.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $pairs ): array {
		$rows = array();
		foreach ( $pairs as $p ) {
			$rows[] = array(
				'module'  => '',
				'key'     => $p['new'],
				'label'   => $p['new'],
				'secret'  => false,
				'from'    => $p['old'],
				'to'      => $p['exists'] ? __( 'kept as it is', 'ux-studio' ) : __( 'copied', 'ux-studio' ),
				'changed' => ! $p['exists'],
				'write'   => ! $p['exists'],
				'dropped' => false,
				'origin'  => $p['old'],
				'target'  => $p['new'],
			);
		}
		return $rows;
	}

	public function detect(): array {
		$detect                       = parent::detect();
		$detect['module_name']        = __( 'All UX Studio modules', 'ux-studio' );
		$detect['summary']['settings'] = 'absent' === $detect['status'] ? 0 : count( $this->pairs() );
		$detect['summary']['data']     = array();
		return $detect;
	}

	public function plan(): array {
		$pairs = $this->pairs();
		$rows  = $this->rows( $pairs );
		$new   = count( array_filter( $pairs, static fn( array $p ): bool => ! $p['exists'] ) );

		$notes = array(
			__( 'Settings that already exist in UX Studio are kept - only missing ones are copied. Renamed keys inside the copied settings are fixed up and API keys are carried over into the encrypted store.', 'ux-studio' ),
		);
		$lossy = array(
			__( 'Data tables (query log, push segments, activity log and the other module data) are not copied in this step.', 'ux-studio' ),
		);
		$warn = array();
		if ( ! $pairs ) {
			$notes[] = __( 'No WP Extended settings were found.', 'ux-studio' );
		}
		if ( $pairs && 0 === $new ) {
			$notes[] = __( 'Every setting is already present in UX Studio, so this step probably changes nothing.', 'ux-studio' );
		}

		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'module'      => '*',
			'module_name' => __( 'All UX Studio modules', 'ux-studio' ),
			'transfer'    => $rows,
			'data'        => array(),
			'options'     => array(),
			'staged'      => false,
			'lossy'       => $lossy,
			'warnings'    => $warn,
			'notes'       => $notes,
			'counts'      => array(
				'transfer' => count( $rows ),
				'changed'  => $new,
				'lossy'    => count( $lossy ),
				'data'     => 0,
			),
		);
	}

	public function target_options(): array {
		return Migrator::target_options();
	}

	public function migrate( array $state, bool $dry_run ): array {
		$pairs = $this->pairs();
		$rows  = $this->rows( $pairs );
		$write = count( array_filter( $rows, static fn( array $r ): bool => $r['write'] ) );

		if ( $dry_run ) {
			Migrator::run( true, true, true ); // Must not throw or write; its log is not shown.
			return array(
				'done'      => true,
				'progress'  => 100,
				'items'     => $rows,
				'written'   => $write,
				'unchanged' => count( $rows ) - $write,
				'errors'    => array(),
			);
		}

		Migrator::run( false, true, true );
		$run_id = (string) ( $state['run_id'] ?? '' );
		if ( '' !== $run_id ) {
			foreach ( $pairs as $p ) {
				MigrationMap::put( $this->slug, $p['old'], 'option', $p['new'], $run_id );
			}
		}
		return array(
			'done'      => true,
			'progress'  => 100,
			'items'     => $rows,
			'written'   => $write,
			'unchanged' => count( $rows ) - $write,
			'errors'    => array(),
		);
	}

	public function verify(): array {
		$checks = array();
		foreach ( $this->pairs() as $p ) {
			$checks[] = array(
				'id'     => $p['new'],
				'label'  => $p['new'],
				'ok'     => $p['exists'],
				'detail' => $p['exists']
					? sprintf(
						/* translators: %s: legacy option name. */
						__( 'Present (from %s).', 'ux-studio' ),
						$p['old']
					)
					: __( 'Missing in UX Studio.', 'ux-studio' ),
			);
		}
		if ( ! $checks ) {
			$checks[] = array(
				'id'     => 'nothing',
				'label'  => __( 'Nothing to compare', 'ux-studio' ),
				'ok'     => true,
				'detail' => __( 'No WP Extended settings were found.', 'ux-studio' ),
			);
		}
		return $checks;
	}
}
