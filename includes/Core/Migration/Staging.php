<?php
/**
 * Staging area: migrated values that must NOT take effect while the old plugin still runs.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The rule (PLAN.md 26.1): a UX Studio module takes over from the foreign plugin only at the
 * switch after the safe preview (F4), never during the copy.
 *
 *  - Target module DISABLED: its settings option has no effect until the module is switched
 *    on, so the migration writes it directly (the previous behaviour).
 *  - Target module ENABLED: the values go here instead, and `apply()` writes them at the
 *    switch. Example: WPS Hide Login migrated while Security Optimization is on would turn on
 *    the custom login URL next to WPS Hide Login (lockout); the same for the CAPTCHA.
 *
 * Option `uxstudio_migration_staged` (no autoload), keyed by run id:
 *
 *   created, settings[module][key] = clean value,
 *   secrets[option] = { module, sealed }   (AES-GCM via Security::seal, never plain text),
 *   activations[] = { source, kind, ids[] } (data created inactive, see Activatable)
 *
 * Pending entries are laid over the live option when previewing and verifying, so a second
 * run of the same source compares with what is already prepared and writes nothing new.
 */
final class Staging {

	public const OPTION = 'uxstudio_migration_staged';

	/**
	 * Whether a module is currently switched on.
	 *
	 * @param string $module Module id.
	 */
	public static function module_enabled( string $module ): bool {
		return '' !== $module && in_array( $module, array_map( 'strval', (array) get_option( 'uxstudio_active_modules', array() ) ), true );
	}

	/**
	 * Everything staged, oldest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_filter( $all, 'is_array' ) : array();
	}

	/**
	 * One run's entry.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function entry( string $run_id ): ?array {
		return self::all()[ Snapshot::clean_id( $run_id ) ] ?? null;
	}

	/**
	 * Store an entry (removes the option when nothing is left).
	 *
	 * @param array<string, array<string, mixed>> $all Whole staging area.
	 */
	private static function save( array $all ): void {
		if ( ! $all ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Entry for a run, created on first use.
	 *
	 * @param array<string, array<string, mixed>> $all    Whole staging area.
	 * @param string                              $run_id Run id.
	 * @return array<string, mixed>
	 */
	private static function fresh( array $all, string $run_id ): array {
		return $all[ $run_id ] ?? array(
			'created'     => time(),
			'settings'    => array(),
			'secrets'     => array(),
			'activations' => array(),
		);
	}

	/**
	 * Stage clean module settings.
	 *
	 * @param string               $run_id Run id.
	 * @param string               $module Module id.
	 * @param array<string, mixed> $values Values already cleaned against the module schema.
	 */
	public static function put_settings( string $run_id, string $module, array $values ): void {
		$run_id = Snapshot::clean_id( $run_id );
		if ( '' === $run_id || ! $values ) {
			return;
		}
		$all                                 = self::all();
		$entry                               = self::fresh( $all, $run_id );
		$entry['settings'][ $module ]        = array_merge( (array) ( $entry['settings'][ $module ] ?? array() ), $values );
		$all[ $run_id ]                      = $entry;
		self::save( $all );
	}

	/**
	 * Stage a secret (encrypted).
	 *
	 * @param string $run_id Run id.
	 * @param string $module Module id.
	 * @param string $option Secret option the value belongs to.
	 * @param string $plain  Plain value.
	 */
	public static function put_secret( string $run_id, string $module, string $option, string $plain ): void {
		$run_id = Snapshot::clean_id( $run_id );
		if ( '' === $run_id || '' === $plain ) {
			return;
		}
		$all                          = self::all();
		$entry                        = self::fresh( $all, $run_id );
		$entry['secrets'][ $option ]  = array(
			'module' => $module,
			'sealed' => Security::seal( $plain ),
		);
		$all[ $run_id ]               = $entry;
		self::save( $all );
	}

	/**
	 * Remember data that was created inactive and must be switched on by `apply()`.
	 *
	 * @param string                 $run_id Run id.
	 * @param string                 $source Source id (plugin slug) that implements Activatable.
	 * @param string                 $kind   Object kind.
	 * @param array<int, int|string> $ids    Target ids.
	 * @param array<string, mixed>   $data   Optional payload per id (what to write at the switch, e.g. a meta value).
	 */
	public static function put_activation( string $run_id, string $source, string $kind, array $ids, array $data = array() ): void {
		$run_id = Snapshot::clean_id( $run_id );
		$ids    = array_values( array_unique( array_map( 'strval', $ids ) ) );
		if ( '' === $run_id || ! $ids ) {
			return;
		}
		$all   = self::all();
		$entry = self::fresh( $all, $run_id );
		$found = false;
		foreach ( $entry['activations'] as $i => $row ) {
			if ( $row['source'] === $source && $row['kind'] === $kind ) {
				$entry['activations'][ $i ]['ids']  = array_values( array_unique( array_merge( (array) $row['ids'], $ids ) ) );
				$entry['activations'][ $i ]['data'] = array_merge( (array) ( $row['data'] ?? array() ), $data );
				$found                              = true;
			}
		}
		if ( ! $found ) {
			$entry['activations'][] = array(
				'source' => $source,
				'kind'   => $kind,
				'ids'    => $ids,
				'data'   => $data,
			);
		}
		$all[ $run_id ] = $entry;
		self::save( $all );
	}

	/**
	 * Staged settings of a module from every pending run (newest run wins).
	 *
	 * @param string $module Module id.
	 * @return array<string, mixed>
	 */
	public static function pending_settings( string $module ): array {
		$out = array();
		foreach ( self::all() as $entry ) {
			$out = array_merge( $out, (array) ( $entry['settings'][ $module ] ?? array() ) );
		}
		return $out;
	}

	/**
	 * Staged plain value of a secret option, or null when nothing is staged for it.
	 *
	 * @param string $option Secret option name.
	 */
	public static function pending_secret( string $option ): ?string {
		$value = null;
		foreach ( self::all() as $entry ) {
			if ( isset( $entry['secrets'][ $option ]['sealed'] ) ) {
				$value = Security::unseal( (string) $entry['secrets'][ $option ]['sealed'] );
			}
		}
		return $value;
	}

	/**
	 * Counts for the status endpoint and the wizard.
	 *
	 * @param string $run_id Run id.
	 * @return array{settings:int,secrets:int,activations:int,modules:string[]}
	 */
	public static function summary( string $run_id ): array {
		$entry   = self::entry( $run_id );
		$modules = array();
		$count   = 0;
		$secrets = 0;
		$acts    = 0;
		if ( $entry ) {
			foreach ( (array) $entry['settings'] as $module => $values ) {
				$count    += count( (array) $values );
				$modules[] = (string) $module;
			}
			foreach ( (array) $entry['secrets'] as $secret ) {
				++$secrets;
				$modules[] = (string) ( $secret['module'] ?? '' );
			}
			foreach ( (array) $entry['activations'] as $row ) {
				$acts += count( (array) $row['ids'] );
			}
		}
		return array(
			'settings'    => $count,
			'secrets'     => $secrets,
			'activations' => $acts,
			'modules'     => array_values( array_unique( array_filter( $modules ) ) ),
		);
	}

	/**
	 * Write the staged values of a run into the live module options and switch the staged
	 * data on. Used by the switch step (F4). Idempotent: the entry is removed, a second call
	 * finds nothing. Does not call the modules' own save hooks (they may rewrite `.htaccess`,
	 * sync bans, provision keys) - the switch step does that after enabling the module.
	 *
	 * @param string $run_id Run id.
	 * @return array{settings:int,secrets:int,activated:int,modules:string[],errors:string[]}
	 */
	public static function apply( string $run_id ): array {
		$run_id = Snapshot::clean_id( $run_id );
		$out    = array(
			'settings'  => 0,
			'secrets'   => 0,
			'activated' => 0,
			'modules'   => array(),
			'errors'    => array(),
		);
		$all    = self::all();
		$entry  = $all[ $run_id ] ?? null;
		if ( ! $entry ) {
			return $out;
		}

		foreach ( (array) $entry['settings'] as $module => $values ) {
			$schema = SettingsWriter::schema( (string) $module );
			if ( null === $schema ) {
				$out['errors'][] = sprintf(
					/* translators: %s: module id. */
					__( 'Module "%s" is not available, its prepared settings were not applied.', 'ux-studio' ),
					(string) $module
				);
				continue;
			}
			( new Settings( SettingsWriter::option_name( (string) $module ) ) )->save( (array) $values, $schema );
			$out['settings'] += count( (array) $values );
			$out['modules'][] = (string) $module;
		}
		foreach ( (array) $entry['secrets'] as $option => $secret ) {
			$plain = Security::unseal( (string) ( $secret['sealed'] ?? '' ) );
			if ( '' === $plain ) {
				continue;
			}
			Security::store_secret( (string) $option, $plain );
			++$out['secrets'];
			$out['modules'][] = (string) ( $secret['module'] ?? '' );
		}
		foreach ( (array) $entry['activations'] as $row ) {
			$source = SourceRegistry::get( (string) $row['source'] );
			if ( $source instanceof Activatable ) {
				$done = $source->activate( (string) $row['kind'], (array) $row['ids'], (array) ( $row['data'] ?? array() ) );
				$out['activated'] += $done;
				if ( $done < count( (array) $row['ids'] ) ) {
					$out['errors'][] = sprintf(
						/* translators: 1: plugin slug, 2: number switched on, 3: number prepared. */
						__( 'Of the data of "%1$s", %2$d of %3$d items were switched on, the rest could not be switched on (check the permissions and the logs).', 'ux-studio' ),
						(string) $row['source'],
						$done,
						count( (array) $row['ids'] )
					);
				}
			} else {
				$out['errors'][] = sprintf(
					/* translators: %s: plugin slug. */
					__( 'The data of "%s" could not be switched on.', 'ux-studio' ),
					(string) $row['source']
				);
			}
		}

		unset( $all[ $run_id ] );
		self::save( $all );
		$out['modules'] = array_values( array_unique( array_filter( $out['modules'] ) ) );
		History::update( $run_id, array( 'applied' => time() ) );
		ActivityLog::log( 'core', 'migration_applied', 'migration', 0, array( 'run' => $run_id ) + array_diff_key( $out, array( 'errors' => 1 ) ) );
		do_action( 'uxstudio_migration_staged_applied', $run_id, $out['modules'] );
		return $out;
	}

	/**
	 * Put a run's entry back after the switch was undone: the values are prepared again and the run
	 * no longer counts as applied.
	 *
	 * @param string               $run_id Run id.
	 * @param array<string, mixed> $entry  The entry `apply()` consumed.
	 */
	public static function restore( string $run_id, array $entry ): void {
		$run_id = Snapshot::clean_id( $run_id );
		$all    = self::all();
		if ( '' === $run_id || ! $entry ) {
			return;
		}
		$all[ $run_id ] = $entry;
		self::save( $all );
		History::update( $run_id, array( 'applied' => 0 ) );
	}

	/**
	 * Throw a run's staged values away (rollback). The inactive data itself is removed by the
	 * snapshot restore of its tables.
	 *
	 * @param string $run_id Run id.
	 * @return bool Whether something was staged.
	 */
	public static function discard( string $run_id ): bool {
		$run_id = Snapshot::clean_id( $run_id );
		$all    = self::all();
		if ( ! isset( $all[ $run_id ] ) ) {
			return false;
		}
		unset( $all[ $run_id ] );
		self::save( $all );
		return true;
	}

	/**
	 * Drop entries older than the snapshot window (daily purge).
	 *
	 * @param int $now Current time.
	 * @return string[] Dropped run ids.
	 */
	public static function purge_expired( int $now ): array {
		$all  = self::all();
		$gone = array();
		foreach ( $all as $run_id => $entry ) {
			if ( Snapshot::expires( (int) ( $entry['created'] ?? 0 ) ) <= $now ) {
				unset( $all[ $run_id ] );
				$gone[] = (string) $run_id;
			}
		}
		if ( $gone ) {
			self::save( $all );
		}
		return $gone;
	}
}
