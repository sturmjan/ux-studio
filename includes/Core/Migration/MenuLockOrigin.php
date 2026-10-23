<?php
/**
 * Where the setting "Block access to hidden pages" came from, when a migration switched it on.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * The migration from Adminimize (and Admin Menu Editor with "Access" unticked) switches `menu_lock_enabled` of
 * Admin Customiser on, because the source plugin really blocked the pages. A site owner who later wonders why a hidden
 * admin page answers "not allowed" must be able to see why, so the migration leaves a note in this option:
 * `{ source, label, run, time }`.
 *
 * The note is part of the migration snapshot (AbstractSource::target_options) and of the switch undo record
 * (Switcher), so a rollback removes it together with the setting. A manual change of the setting clears it
 * (AdminCustomiser\Module::save_settings).
 */
final class MenuLockOrigin {

	public const OPTION = 'uxstudio_menu_lock_origin';

	/**
	 * Remember that a migration run switched the lock on.
	 *
	 * @param string $source Source id (plugin folder slug).
	 * @param string $label  Plugin name for display.
	 * @param string $run_id Run id.
	 * @param int    $time   Unix time (0 = now).
	 */
	public static function record( string $source, string $label, string $run_id, int $time = 0 ): void {
		update_option( self::OPTION, self::clean( array( 'source' => $source, 'label' => $label, 'run' => $run_id, 'time' => $time ?: time() ) ), false );
	}

	/**
	 * Write a note kept in the staging area (the switch applies it together with the settings).
	 *
	 * @param array<string, mixed> $note Note as `clean()` returns it.
	 */
	public static function write( array $note ): void {
		$note = self::clean( $note );
		if ( $note ) {
			update_option( self::OPTION, $note, false );
		}
	}

	/**
	 * Forget the origin (the user changed the setting by hand, or it is off).
	 */
	public static function clear(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * The stored origin, or null.
	 *
	 * @return array{source:string,label:string,run:string,time:int}|null
	 */
	public static function get(): ?array {
		$note = self::clean( get_option( self::OPTION, array() ) );
		return $note ? $note : null;
	}

	/**
	 * Valid note or an empty array.
	 *
	 * @param mixed $note Raw value.
	 * @return array{source:string,label:string,run:string,time:int}|array{}
	 */
	public static function clean( $note ): array {
		if ( ! is_array( $note ) || empty( $note['source'] ) ) {
			return array();
		}
		return array(
			'source' => (string) $note['source'],
			'label'  => (string) ( $note['label'] ?? $note['source'] ),
			'run'    => Snapshot::clean_id( (string) ( $note['run'] ?? '' ) ),
			'time'   => (int) ( $note['time'] ?? 0 ),
		);
	}
}
