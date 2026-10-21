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
			$mapping->stage = $this->changes_behavior();
			$module         = $this->target_module();
			if ( $mapping->stage && '' !== $module && ! $mapping->is_empty() && $this->module_enabled( $module ) ) {
				$mapping->warn(
					sprintf(
						/* translators: %s: module name. */
						__( 'The module "%s" is already switched on, so the transferred settings are only prepared. They take effect at the switch, after the safe preview.', 'ux-studio' ),
						ActivationGuard::module_name( $module )
					)
				);
			}
			foreach ( $mapping->secrets as $option => $secret ) {
				if ( '' === $secret['module'] ) {
					$mapping->secrets[ $option ]['module'] = $module;
				}
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
		return Staging::module_enabled( $module );
	}

	/**
	 * Throw away the cached mapping (after the options changed).
	 */
	public function forget(): void {
		$this->mapping = null;
	}

	/**
	 * Whether the migrated values / data change how the site behaves while the old plugin still
	 * runs. True = they wait (staging / inactive data) when the target module is already on.
	 * False = harmless (folders, log history, retention, last login times), written directly.
	 */
	protected function changes_behavior(): bool {
		return true;
	}

	/* ── Data phase (sources with objects: redirects, snippets, folders ...) ── */

	/** Items per batch of a real run. */
	protected const BATCH = 500;

	/** @var callable|null Test seam: replaces apply / preview / verify of every data item. */
	private static $data_double = null;

	/**
	 * Replace how data items are written (tests only; the real writers talk to WordPress).
	 *
	 * @param callable|null $double Receives (mode `apply|preview|verify`, source id, item, context) and returns the result array of that mode.
	 */
	public static function set_data_double( ?callable $double ): void {
		self::$data_double = $double;
	}

	/**
	 * Run one item through the writer of the given mode (or the test double).
	 *
	 * @param string               $mode `apply`, `preview` or `verify`.
	 * @param array<string, mixed> $item Normalized object.
	 * @param array<string, mixed> $ctx  Context.
	 * @return array<string, mixed>
	 */
	private function run_item( string $mode, array $item, array $ctx ): array {
		if ( null !== self::$data_double ) {
			return (array) call_user_func( self::$data_double, $mode, $this->slug, $item, $ctx );
		}
		switch ( $mode ) {
			case 'apply':
				return $this->apply_item( $item, $ctx );
			case 'preview':
				return $this->preview_item( $item, $ctx );
			default:
				return $this->verify_item( $item );
		}
	}

	/**
	 * Rows of the plan / scan: what data exists in the foreign plugin.
	 *
	 * @param array<string, bool> $options Chosen source options.
	 * @return array<int, array{key:string,label:string,count:int}> `count` 0 rows are hidden.
	 */
	protected function data_plan( array $options ): array {
		return array();
	}

	/**
	 * How many objects the data phase will go through (0 = the source has no data phase).
	 *
	 * @param array<string, bool> $options Chosen source options.
	 */
	protected function data_total( array $options ): int {
		return 0;
	}

	/**
	 * Read one batch of normalized objects from the foreign storage (never writes).
	 *
	 * @param mixed               $position Where the previous batch stopped (null = start).
	 * @param int                 $limit    Max objects.
	 * @param array<string, bool> $options  Chosen source options.
	 * @return array{items:array<int, array<string, mixed>>,next:mixed,done:bool}
	 */
	protected function read_batch( $position, int $limit, array $options ): array {
		return array(
			'items' => array(),
			'next'  => null,
			'done'  => true,
		);
	}

	/**
	 * Make the target ready for writing (tables, taxonomy). Called once per batch of a real run.
	 */
	protected function prepare_data(): void {}

	/**
	 * Write one object into UX Studio (real run). Must be idempotent: an object that is already
	 * there reports `unchanged`, nothing existing is overwritten.
	 *
	 * @param array<string, mixed> $item Normalized object (always has `key`).
	 * @param array<string, mixed> $ctx  `run_id`, `options`.
	 * @return array{status:string,map_type?:string,map_id?:string,maps?:array<int, array{0:string,1:string}>,activate?:string,payload?:mixed,message?:string}
	 *         status: created | updated | unchanged | skipped | error. `activate` = id the switch step must
	 *         switch on (the object was created inactive), `payload` = what to write then.
	 */
	protected function apply_item( array $item, array $ctx ): array {
		return array( 'status' => 'skipped' );
	}

	/**
	 * What apply_item() would do (dry run, writes nothing).
	 *
	 * @param array<string, mixed> $item Normalized object.
	 * @param array<string, mixed> $ctx  `options`.
	 * @return array{status:string,message?:string}
	 */
	protected function preview_item( array $item, array $ctx ): array {
		return array( 'status' => 'created' );
	}

	/**
	 * Is one object correctly in UX Studio?
	 *
	 * @param array<string, mixed> $item Normalized object.
	 * @return array{ok:bool,detail:string}
	 */
	protected function verify_item( array $item ): array {
		return array(
			'ok'     => true,
			'detail' => '',
		);
	}

	/**
	 * Kind of the objects (`redirect`, `snippet` ...); also the switch step's `kind` for Activatable.
	 */
	protected function data_kind(): string {
		return 'item';
	}

	/**
	 * Plural, translated name of the objects for the summary rows ("redirects").
	 */
	protected function data_label(): string {
		return __( 'Items', 'ux-studio' );
	}

	/**
	 * Options of the current run for a read-only call (plan, scan, verify): the defaults.
	 *
	 * @return array<string, bool>
	 */
	protected function default_options(): array {
		$out = array();
		foreach ( $this->options() as $option ) {
			$out[ (string) $option['key'] ] = (bool) $option['default'];
		}
		return $out;
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
		$data     = array();
		if ( 'absent' !== $status ) {
			$settings = count( $this->mapping()->items );
			$data     = array_values( array_filter( $this->data_plan( $this->default_options() ), static fn( array $row ): bool => $row['count'] > 0 ) );
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
			'summary'     => array(
				'settings' => $settings,
				'data'     => $data,
			),
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
		$data = array_values( array_filter( $this->data_plan( $this->default_options() ), static fn( array $row ): bool => $row['count'] > 0 ) );

		return array(
			'id'          => $this->id(),
			'label'       => $this->label(),
			'module'      => $module,
			'module_name' => ActivationGuard::module_name( $module ),
			'transfer'    => $preview['items'],
			'data'        => $data,
			'options'     => $this->options(),
			'staged'      => $this->changes_behavior() && '' !== $module && $this->module_enabled( $module ),
			'lossy'       => $mapping->lossy,
			'warnings'    => array_values( array_unique( $warnings ) ),
			'notes'       => $mapping->notes,
			'counts'      => array(
				'transfer' => count( $preview['items'] ),
				'changed'  => $changed,
				'lossy'    => count( $mapping->lossy ),
				'data'     => array_sum( array_column( $data, 'count' ) ),
			),
		);
	}

	public function target_tables(): array {
		return array();
	}

	public function smoke_checks(): array {
		return array();
	}

	public function options(): array {
		return array();
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
		return array_values( array_unique( array_merge( $names, $this->extra_target_options() ) ) );
	}

	/**
	 * Further options the data phase may write (cached engine state of the target module ...).
	 *
	 * @return string[]
	 */
	protected function extra_target_options(): array {
		return array();
	}

	/**
	 * One batch. Settings are written in the first call; sources with a data phase then
	 * continue batch by batch (`done` false, `cursor` carries the position).
	 */
	public function migrate( array $state, bool $dry_run ): array {
		$options = (array) ( $state['options'] ?? array() ) + $this->default_options();
		$cursor  = $state['cursor'] ?? null;
		$total   = $this->data_total( $options );

		if ( null === $cursor ) {
			$result = $this->migrate_settings( $state, $dry_run );
			if ( 0 === $total ) {
				return $result;
			}
			$result['done']      = false;
			$result['progress']  = 0;
			$result['total']     = $total;
			$result['processed'] = 0;
			$result['cursor']    = array(
				'position' => null,
				'seen'     => 0,
				'stats'    => array(),
				'problems' => array(),
			);
			return $result;
		}

		return $this->migrate_data( $state, (array) $cursor, $total, $options, $dry_run );
	}

	/**
	 * The settings part of migrate().
	 *
	 * @param array<string, mixed> $state   Runner state.
	 * @param bool                 $dry_run Compute only.
	 * @return array<string, mixed>
	 */
	protected function migrate_settings( array $state, bool $dry_run ): array {
		$mapping = $this->mapping();
		if ( $dry_run ) {
			$preview = SettingsWriter::preview( $mapping );
			$write   = 0;
			$staged  = 0;
			foreach ( $preview['items'] as $row ) {
				if ( $row['write'] ) {
					++$write;
					$staged += $row['staged'] ? 1 : 0;
				}
			}
			return array(
				'done'      => true,
				'progress'  => 100,
				'items'     => $preview['items'],
				'written'   => $write,
				'unchanged' => count( $preview['items'] ) - $write,
				'staged'    => $staged,
				'errors'    => $preview['errors'],
			);
		}

		$run_id = (string) ( $state['run_id'] ?? '' );
		$result = SettingsWriter::apply( $mapping, $run_id );
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
			'staged'    => $result['staged'],
			'errors'    => $result['errors'],
		);
	}

	/**
	 * One batch of the data phase.
	 *
	 * @param array<string, mixed> $state   Runner state.
	 * @param array<string, mixed> $cursor  Position + running totals saved by the previous batch.
	 * @param int                  $total   Objects in the source.
	 * @param array<string, bool>  $options Chosen options.
	 * @param bool                 $dry_run Compute only.
	 * @return array<string, mixed>
	 */
	private function migrate_data( array $state, array $cursor, int $total, array $options, bool $dry_run ): array {
		$run_id = (string) ( $state['run_id'] ?? '' );
		$ctx    = array(
			'run_id'  => $run_id,
			'options' => $options,
		);
		// A dry run writes nothing, so one pass over everything is cheap; a real run goes in small batches.
		$limit = $dry_run ? 100000 : static::BATCH;
		$batch = $this->read_batch( $cursor['position'] ?? null, $limit, $options );
		$stats = (array) ( $cursor['stats'] ?? array() );
		$probs = (array) ( $cursor['problems'] ?? array() );
		$errs  = array();
		$ids   = array();
		$data  = array();

		if ( ! $dry_run && null === self::$data_double ) {
			$this->prepare_data();
		}
		foreach ( $batch['items'] as $item ) {
			try {
				$res = $this->run_item( $dry_run ? 'preview' : 'apply', $item, $ctx );
			} catch ( \Throwable $e ) {
				$res = array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				);
			}
			$status           = (string) ( $res['status'] ?? 'skipped' );
			$stats[ $status ] = (int) ( $stats[ $status ] ?? 0 ) + 1;
			if ( ! empty( $res['message'] ) && count( $probs ) < 12 && in_array( $status, array( 'skipped', 'error' ), true ) ) {
				$probs[] = (string) ( $item['label'] ?? $item['key'] ) . ': ' . $res['message'];
			}
			if ( 'error' === $status && count( $errs ) < 3 ) {
				$errs[] = (string) ( $res['message'] ?? __( 'An item could not be written.', 'ux-studio' ) );
			}
			if ( ! $dry_run && 'created' === $status && '' !== $run_id ) {
				// Only objects this run CREATED are recorded: the rollback removes exactly those.
				$maps = isset( $res['maps'] ) ? (array) $res['maps'] : ( ! empty( $res['map_type'] ) ? array( array( (string) $res['map_type'], (string) ( $res['map_id'] ?? '' ) ) ) : array() );
				foreach ( array_values( $maps ) as $n => $map ) {
					// The mapping key is unique per (plugin, key, type): several rows of one type need distinct keys.
					MigrationMap::put( $this->slug, (string) $item['key'] . ( $n > 0 ? '#' . $n : '' ), (string) $map[0], (string) $map[1], $run_id );
				}
			}
			if ( ! $dry_run && ! empty( $res['activate'] ) ) {
				$ids[]                         = (string) $res['activate'];
				$data[ (string) $res['activate'] ] = $res['payload'] ?? null;
			}
		}
		if ( $ids && '' !== $run_id ) {
			Staging::put_activation( $run_id, $this->slug, $this->data_kind(), $ids, array_filter( $data, static fn( $v ): bool => null !== $v ) );
		}

		$seen = (int) ( $cursor['seen'] ?? 0 ) + count( $batch['items'] );
		$done = (bool) $batch['done'];

		$out = array(
			'done'      => $done,
			'progress'  => $done ? 100 : (int) min( 99, floor( 100 * $seen / max( 1, $total ) ) ),
			'total'     => $total,
			'processed' => $done ? max( $seen, $total ) : $seen,
			'written'   => (int) ( $stats['created'] ?? 0 ) - (int) ( $cursor['stats']['created'] ?? 0 ) + (int) ( $stats['updated'] ?? 0 ) - (int) ( $cursor['stats']['updated'] ?? 0 ),
			'unchanged' => (int) ( $stats['unchanged'] ?? 0 ) - (int) ( $cursor['stats']['unchanged'] ?? 0 ) + (int) ( $stats['skipped'] ?? 0 ) - (int) ( $cursor['stats']['skipped'] ?? 0 ),
			'staged'    => count( $ids ),
			'cursor'    => array(
				'position' => $batch['next'],
				'seen'     => $seen,
				'stats'    => $stats,
				'problems' => $probs,
			),
			'items'     => array(),
			'errors'    => $errs,
		);
		if ( $done ) {
			$out['items'] = $this->summary_rows( $stats, $probs, $dry_run ? 0 : $this->pending_activations( $run_id ) );
		}
		return $out;
	}

	/**
	 * Objects waiting for the switch that this source created in a run.
	 *
	 * @param string $run_id Run id.
	 */
	private function pending_activations( string $run_id ): int {
		$entry = Staging::entry( $run_id );
		$count = 0;
		foreach ( (array) ( $entry['activations'] ?? array() ) as $row ) {
			if ( $row['source'] === $this->slug ) {
				$count += count( (array) $row['ids'] );
			}
		}
		return $count;
	}

	/**
	 * Result rows of a finished data phase (shown in the dry run and the migration report).
	 *
	 * @param array<string, int> $stats    Counts per status.
	 * @param string[]           $problems What was skipped and why (first few).
	 * @param int                $waiting  Objects created inactive, waiting for the switch.
	 * @return array<int, array<string, mixed>>
	 */
	private function summary_rows( array $stats, array $problems, int $waiting ): array {
		$parts = array();
		foreach ( array(
			'created'   => __( 'created', 'ux-studio' ),
			'updated'   => __( 'updated', 'ux-studio' ),
			'unchanged' => __( 'already there', 'ux-studio' ),
			'skipped'   => __( 'skipped', 'ux-studio' ),
			'error'     => __( 'failed', 'ux-studio' ),
		) as $status => $text ) {
			if ( ! empty( $stats[ $status ] ) ) {
				$parts[] = $stats[ $status ] . ' ' . $text;
			}
		}
		$rows   = array();
		$rows[] = array(
			'module'  => '',
			'key'     => 'data:' . $this->data_kind(),
			'label'   => $this->data_label(),
			'secret'  => false,
			'from'    => '',
			'to'      => implode( ', ', $parts ),
			'changed' => ! empty( $stats['created'] ) || ! empty( $stats['updated'] ),
			'write'   => ! empty( $stats['created'] ) || ! empty( $stats['updated'] ),
			'dropped' => false,
			'staged'  => $waiting > 0,
			'origin'  => $this->data_kind(),
			'target'  => 'data:' . $this->data_kind(),
		);
		foreach ( $problems as $i => $text ) {
			$rows[] = array(
				'module'  => '',
				'key'     => 'problem:' . $i,
				'label'   => __( 'Skipped', 'ux-studio' ),
				'secret'  => false,
				'from'    => '',
				'to'      => $text,
				'changed' => false,
				'write'   => false,
				'dropped' => true,
				'staged'  => false,
				'origin'  => $this->data_kind(),
				'target'  => 'problem:' . $i,
			);
		}
		return $rows;
	}

	public function verify(): array {
		$this->forget();
		$mapping = $this->mapping();
		$options = Runner::options_of( $this->slug ) + $this->default_options();
		$total   = $this->data_total( $options );
		$checks  = array();

		if ( $mapping->is_empty() ) {
			if ( 0 === $total ) {
				return array(
					array(
						'id'     => 'nothing',
						'label'  => __( 'Nothing to compare', 'ux-studio' ),
						'ok'     => true,
						'detail' => implode( ' ', $mapping->notes ),
					),
				);
			}
		} else {
			$checks = SettingsWriter::verify( $mapping );
		}
		if ( 0 === $total ) {
			return $checks;
		}

		$ok       = 0;
		$bad      = array();
		$position = null;
		do {
			$batch = $this->read_batch( $position, static::BATCH, $options );
			foreach ( $batch['items'] as $item ) {
				$res = $this->run_item( 'verify', $item, array() );
				if ( $res['ok'] ) {
					++$ok;
				} elseif ( count( $bad ) < 15 ) {
					$bad[] = array(
						'label'  => (string) ( $item['label'] ?? $item['key'] ),
						'detail' => $res['detail'],
					);
				} else {
					$bad[] = null;
				}
			}
			$position = $batch['next'];
		} while ( ! $batch['done'] );

		$failed  = count( $bad );
		$waiting = $this->waiting_in_staging();
		$checks[] = array(
			'id'     => 'data:' . $this->data_kind(),
			'label'  => $this->data_label(),
			'ok'     => 0 === $failed,
			'staged' => 0 === $failed && $waiting > 0,
			'detail' => sprintf(
				/* translators: 1: number of objects found in UX Studio, 2: number of objects in the old plugin. */
				__( '%1$d of %2$d found in UX Studio', 'ux-studio' ),
				$ok,
				$ok + $failed
			),
		);
		foreach ( array_filter( $bad ) as $i => $row ) {
			$checks[] = array(
				'id'     => 'data-bad:' . $i,
				'label'  => $row['label'],
				'ok'     => false,
				'detail' => $row['detail'],
			);
		}
		return $checks;
	}

	/**
	 * Objects of this source that wait for the switch (any run).
	 */
	protected function waiting_in_staging(): int {
		$count = 0;
		foreach ( Staging::all() as $entry ) {
			foreach ( (array) ( $entry['activations'] ?? array() ) as $row ) {
				if ( $row['source'] === $this->slug ) {
					$count += count( (array) $row['ids'] );
				}
			}
		}
		return $count;
	}
}
