<?php
/**
 * Snapshot taken before an import writes anything, so "Undo import" can put everything back.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

use UxStudio\Core\Migration\MenuLockOrigin;
use UxStudio\Core\Migration\Snapshot;
use UxStudio\Modules\SecurityOptimization\CspPolicy;
use UxStudio\Modules\SecurityOptimization\HtaccessWriter;
use UxStudio\Modules\SecurityOptimization\LoginUrlPending;

defined( 'ABSPATH' ) || exit;

/**
 * One option (`uxstudio_import_snapshot`, not autoloaded), like the starter pack's snapshot, kept 30 days
 * (the same as the migration snapshots). It holds:
 *
 *  - `modules`  the list of enabled modules before,
 *  - `options`  the options of every module the import may touch (settings, encrypted secret options) and the
 *               bookkeeping options that go with them (login address pending state, .htaccess hash, menu lock
 *               note, CSP state), in the format of `Migration\Snapshot::read_options()`,
 *  - `data`     for each module with content: what the import created and the earlier version of what it updated.
 *
 * Only the LAST import can be undone: a new import replaces the snapshot. The snapshot contains secrets in
 * the same encrypted form they have in the database; it is never sent to the browser.
 */
final class ImportSnapshot {

	public const OPTION = 'uxstudio_import_snapshot';

	/**
	 * Option names the import may change for these modules.
	 *
	 * @param string[] $modules Module ids.
	 * @return string[]
	 */
	public static function option_names( array $modules ): array {
		$names = array( 'uxstudio_active_modules', LoginUrlPending::OPTION, HtaccessWriter::HASH_OPTION, MenuLockOrigin::OPTION, CspPolicy::STATE_OPTION );
		foreach ( $modules as $module ) {
			$names[] = 'uxstudio_' . str_replace( '-', '_', (string) $module );
			foreach ( NonPortable::secret_fields( (string) $module ) as $option ) {
				$names[] = $option;
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Remember the state before the import. Replaces an earlier snapshot.
	 *
	 * @param string[] $modules Module ids that may be touched.
	 * @return string Snapshot id.
	 */
	public static function create( array $modules ): string {
		$id = bin2hex( random_bytes( 8 ) );
		update_option(
			self::OPTION,
			array(
				'id'      => $id,
				'time'    => time(),
				'user_id' => get_current_user_id(),
				'modules' => array_values( array_map( 'strval', (array) get_option( 'uxstudio_active_modules', array() ) ) ),
				'options' => Snapshot::read_options( self::option_names( $modules ) ),
				'touched' => array_values( array_map( 'strval', $modules ) ),
				'data'    => array(),
			),
			false
		);
		return $id;
	}

	/**
	 * The snapshot, or null (none, or older than the retention; an expired one is deleted).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(): ?array {
		$snap = get_option( self::OPTION, null );
		if ( ! is_array( $snap ) || ! isset( $snap['options'], $snap['modules'], $snap['time'] ) ) {
			return null;
		}
		if ( Snapshot::expires( (int) $snap['time'] ) <= time() ) {
			delete_option( self::OPTION );
			return null;
		}
		return $snap;
	}

	/**
	 * Add what a data provider did.
	 *
	 * @param string                          $module   Module id.
	 * @param array<int, mixed>               $created  References of created objects.
	 * @param array<int, array<string,mixed>> $previous Earlier versions of updated objects.
	 */
	public static function add_data( string $module, array $created, array $previous ): void {
		$snap = get_option( self::OPTION, null );
		if ( ! is_array( $snap ) ) {
			return;
		}
		$snap['data'][ $module ] = array(
			'created'  => $created,
			'previous' => $previous,
		);
		update_option( self::OPTION, $snap, false );
	}

	/** Forget the snapshot. */
	public static function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Daily clean-up (same hook as the migration snapshots).
	 */
	public static function purge_expired(): void {
		self::get();
	}

	/**
	 * Short description for the UI (no values).
	 *
	 * @return array{available:bool,time:int,expires:int,modules:string[],user_id:int}
	 */
	public static function describe(): array {
		$snap = self::get();
		if ( ! $snap ) {
			return array(
				'available' => false,
				'time'      => 0,
				'expires'   => 0,
				'modules'   => array(),
				'user_id'   => 0,
			);
		}
		return array(
			'available' => true,
			'time'      => (int) $snap['time'],
			'expires'   => Snapshot::expires( (int) $snap['time'] ),
			'modules'   => array_values( array_map( 'strval', (array) ( $snap['touched'] ?? array() ) ) ),
			'user_id'   => (int) ( $snap['user_id'] ?? 0 ),
		);
	}
}
