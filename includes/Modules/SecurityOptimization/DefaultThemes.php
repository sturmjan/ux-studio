<?php
/**
 * Origin tracking of the default WordPress themes (Twenty*), so only the ones WordPress itself brought get deleted.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Every Twenty* theme gets an origin, stored in ONE option (`uxstudio_default_theme_origins`):
 *
 *  - `initial`: part of the WordPress install, or already there when tracking started (first enable of the
 *    setting, starter pack, or the first core update, whichever comes first),
 *  - `core`: copied by a WordPress CORE update (update_core() copies the new bundled themes itself, not through the
 *    theme upgrader),
 *  - `manual`: installed by a person: ZIP upload or wp.org install from the admin or WP-CLI (Theme_Upgrader, action
 *    `install`), or it simply appeared on disk between two snapshots without a core update (FTP, file manager).
 *
 * The option also keeps a snapshot of all installed theme slugs and its time. How the origin is learned:
 *
 *  1. `upgrader_pre_install` (type core/none): runs BEFORE a core update copies anything. It refreshes the snapshot, so
 *     a theme added by FTP earlier is already `manual` and cannot be mistaken for a core theme.
 *  2. `_core_updated_successfully` (+ `upgrader_process_complete` type core as a fallback): a Twenty* theme that is
 *     present now but absent from the snapshot is `core`.
 *  3. `upgrader_process_complete` type theme, action install: the installed slug(s) are `manual`.
 *  4. Every daily run / save of the setting: a Twenty* theme not in the snapshot, with no core update in between, is
 *     `manual`; with no record at all, everything present is `initial`.
 *
 * Only `initial` and `core` are ever deletion candidates. `manual` and unknown (not recorded) are never deleted.
 * The decision logic ({@see classify()}, {@see select_deletions()}) is pure (no WordPress calls) and covered by
 * tests/default-themes-origin.php.
 */
final class DefaultThemes {

	public const OPTION = 'uxstudio_default_theme_origins';

	public const ORIGIN_INITIAL = 'initial';
	public const ORIGIN_CORE    = 'core';
	public const ORIGIN_MANUAL  = 'manual';

	public const EVENT_SYNC          = 'sync';
	public const EVENT_THEME_INSTALL = 'theme_install';
	public const EVENT_CORE_UPDATE   = 'core_update';

	/* ═══════════════════════════════════════════════════
	   Pure logic
	   ═══════════════════════════════════════════════════ */

	/**
	 * Slug of a WordPress default theme (twentytwentysix, twentyfifteen...).
	 */
	public static function is_default_slug( string $slug ): bool {
		return 1 === preg_match( '/^twenty[a-z]+$/', $slug );
	}

	/**
	 * Next record from the previous one and what is installed now.
	 *
	 * @param array<string,mixed>|null $record    Previous record ({origins, snapshot, time}) or null when none yet.
	 * @param string[]                 $current   Slugs of all installed themes now.
	 * @param string                   $event     One of the EVENT_* constants.
	 * @param string[]                 $installed Slugs installed through the theme upgrader (EVENT_THEME_INSTALL).
	 * @param int                      $now       Timestamp stored in the record.
	 * @return array{origins: array<string,string>, snapshot: string[], time: int}
	 */
	public static function classify( ?array $record, array $current, string $event, array $installed = array(), int $now = 0 ): array {
		$current  = array_values( array_unique( array_map( 'strval', $current ) ) );
		$has_prev = is_array( $record ) && isset( $record['snapshot'] ) && is_array( $record['snapshot'] );
		$snapshot = $has_prev ? array_map( 'strval', $record['snapshot'] ) : array();
		$prev     = ( is_array( $record ) && isset( $record['origins'] ) && is_array( $record['origins'] ) ) ? $record['origins'] : array();

		$origins = array();
		foreach ( $current as $slug ) {
			if ( ! self::is_default_slug( $slug ) ) {
				continue;
			}
			if ( isset( $prev[ $slug ] ) && in_array( $prev[ $slug ], array( self::ORIGIN_INITIAL, self::ORIGIN_CORE, self::ORIGIN_MANUAL ), true ) ) {
				$origins[ $slug ] = $prev[ $slug ];
				continue;
			}
			// Not recorded yet.
			if ( ! $has_prev || in_array( $slug, $snapshot, true ) ) {
				$origins[ $slug ] = self::ORIGIN_INITIAL; // Part of the install / there since before tracking began.
			} elseif ( self::EVENT_CORE_UPDATE === $event ) {
				$origins[ $slug ] = self::ORIGIN_CORE;    // New since the snapshot and a core update just ran.
			} else {
				$origins[ $slug ] = self::ORIGIN_MANUAL;  // Appeared with no core update in between (FTP, file manager).
			}
		}

		if ( self::EVENT_THEME_INSTALL === $event ) {
			foreach ( $installed as $slug ) {
				$slug = (string) $slug;
				if ( self::is_default_slug( $slug ) ) {
					$origins[ $slug ] = self::ORIGIN_MANUAL; // Also when it was there before: somebody installed it on purpose.
				}
			}
		}

		ksort( $origins );
		return array(
			'origins'  => $origins,
			'snapshot' => $current,
			'time'     => $now,
		);
	}

	/**
	 * Which themes to delete: origin initial/core, not kept, and a candidate (bundled default theme).
	 *
	 * @param array<string,string> $origins    slug => origin.
	 * @param string[]             $candidates Installed themes that look like genuine bundled default themes.
	 * @param string[]             $keep       Slugs that must stay (active, parent, child's parent, newest fallback).
	 * @return array<string,string> slug => origin of the themes to delete.
	 */
	public static function select_deletions( array $origins, array $candidates, array $keep ): array {
		$out = array();
		foreach ( $candidates as $slug ) {
			$origin = $origins[ $slug ] ?? '';
			if ( ! in_array( $origin, array( self::ORIGIN_INITIAL, self::ORIGIN_CORE ), true ) || in_array( $slug, $keep, true ) ) {
				continue;
			}
			$out[ $slug ] = $origin;
		}
		return $out;
	}

	/* ═══════════════════════════════════════════════════
	   WordPress glue
	   ═══════════════════════════════════════════════════ */

	/**
	 * Wire the hooks. Always on (not only with the setting), so the history is right when the setting is enabled later.
	 */
	public static function register(): void {
		add_filter( 'upgrader_pre_install', array( self::class, 'on_pre_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( self::class, 'on_upgrader_complete' ), 10, 2 );
		add_action( '_core_updated_successfully', array( self::class, 'on_core_updated' ) );
	}

	/**
	 * Stored record, null when none.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function record(): ?array {
		$record = get_option( self::OPTION, null );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Slugs of all installed themes (fresh scan).
	 *
	 * @return string[]
	 */
	public static function current_slugs(): array {
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}
		return array_map( 'strval', array_keys( wp_get_themes() ) );
	}

	/**
	 * Re-classify and store.
	 *
	 * @param string   $event     EVENT_* constant.
	 * @param string[] $installed Slugs installed by the theme upgrader.
	 * @return array{origins: array<string,string>, snapshot: string[], time: int}
	 */
	public static function track( string $event = self::EVENT_SYNC, array $installed = array() ): array {
		$current = self::current_slugs();
		if ( self::EVENT_THEME_INSTALL === $event ) {
			$current = array_values( array_unique( array_merge( $current, $installed ) ) );
		}
		$next = self::classify( self::record(), $current, $event, $installed, time() );
		update_option( self::OPTION, $next, false );
		return $next;
	}

	/**
	 * Before a core update copies anything: make the snapshot current.
	 *
	 * @param mixed                $response   Passed through untouched.
	 * @param array<string,mixed>  $hook_extra Upgrader context.
	 * @return mixed
	 */
	public static function on_pre_install( $response, $hook_extra = array() ) {
		$type = is_array( $hook_extra ) ? ( $hook_extra['type'] ?? '' ) : '';
		if ( '' === $type || 'core' === $type ) {
			self::track( self::EVENT_SYNC );
		}
		return $response;
	}

	/**
	 * Core update finished: new Twenty* themes are `core`.
	 */
	public static function on_core_updated(): void {
		self::track( self::EVENT_CORE_UPDATE );
	}

	/**
	 * A theme was installed by the theme upgrader (or a core update finished: fallback).
	 *
	 * @param \WP_Upgrader        $upgrader Upgrader instance.
	 * @param array<string,mixed> $options  Upgrader context.
	 */
	public static function on_upgrader_complete( $upgrader, $options = array() ): void {
		if ( ! is_array( $options ) ) {
			return;
		}
		$type   = $options['type'] ?? '';
		$action = $options['action'] ?? '';
		if ( 'core' === $type ) {
			self::track( self::EVENT_CORE_UPDATE ); // No-op when _core_updated_successfully already did it.
			return;
		}
		if ( 'theme' !== $type || 'install' !== $action ) {
			return;
		}
		$slugs = array();
		if ( isset( $options['themes'] ) && is_array( $options['themes'] ) ) {
			$slugs = array_merge( $slugs, $options['themes'] ); // Bulk.
		}
		$result = ( is_object( $upgrader ) && isset( $upgrader->result ) ) ? $upgrader->result : null;
		if ( is_array( $result ) && ! empty( $result['destination_name'] ) ) {
			$slugs[] = $result['destination_name'];
		}
		$slugs = array_values( array_filter( array_map( 'strval', $slugs ), 'strlen' ) );
		if ( $slugs ) {
			self::track( self::EVENT_THEME_INSTALL, $slugs );
		}
	}
}
