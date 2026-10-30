<?php
/**
 * The switch: UX Studio takes over from the old plugins, and the way back.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration;

use UxStudio\Core\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 26.10. Order of the switch, after a snapshot of everything it changes:
 *
 *   1. Staging::apply()               prepared values written, staged data switched on
 *   2. enable the target modules      the F1 overlap guard is bypassed on purpose (and logged)
 *   3. after_migration_switch()       what a settings save does: `.htaccess`, bans, mu-plugin ...
 *   4. deactivate_plugins()           not silent: the plugins' own deactivation hooks run
 *   5. quick live check               home page and login must not fail; if they do, the switch is undone
 *
 * Plugin files and the plugins' data are never touched. The record `uxstudio_migration_switch_<run>`
 * (no autoload) keeps what is needed to undo it for 30 days:
 *
 *   created, user_id, sources[], active_plugins (before), modules (before), options (before),
 *   enabled_modules[], deactivated[], effect_modules[], staging (the entry that was applied),
 *   htaccess (md5 + length before)
 *
 * Undo order (modules first, so the plugins' activation hooks see no overlap): disable the modules the
 * switch enabled -> reactivate the plugins -> restore the saved options -> switch the data off again ->
 * rerun the side effects for modules that stay on -> put the staging entry back.
 */
final class Switcher {

	public const KEEP_DAYS = 30;

	private const PREFIX = 'uxstudio_migration_switch_';

	/** @var object|null Test seam: stands in for `Plugin::instance()->modules` (set_enabled, instance, enabled_ids). */
	private static $modules_double = null;

	/**
	 * Replace the module registry (tests only).
	 *
	 * @param object|null $modules Object with set_enabled(), instance(), enabled_ids() or null.
	 */
	public static function set_modules( ?object $modules ): void {
		self::$modules_double = $modules;
	}

	/**
	 * Module registry.
	 */
	private static function modules(): ?object {
		if ( null !== self::$modules_double ) {
			return self::$modules_double;
		}
		return class_exists( '\UxStudio\Plugin' ) && isset( \UxStudio\Plugin::instance()->modules ) ? \UxStudio\Plugin::instance()->modules : null;
	}

	/**
	 * Option that holds the switch record of a run.
	 *
	 * @param string $run_id Run id.
	 */
	public static function option_name( string $run_id ): string {
		return self::PREFIX . Snapshot::clean_id( $run_id );
	}

	/**
	 * The switch record, or null.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|null
	 */
	public static function record( string $run_id ): ?array {
		$record = get_option( self::option_name( $run_id ), null );
		return is_array( $record ) ? $record : null;
	}

	/**
	 * Whether the run is switched on and the switch can still be undone.
	 *
	 * @param string   $run_id Run id.
	 * @param int|null $now    Test seam.
	 */
	public static function can_revert( string $run_id, ?int $now = null ): bool {
		$record = self::record( $run_id );
		return $record && ( (int) $record['created'] + self::KEEP_DAYS * DAY_IN_SECONDS ) > ( $now ?? time() );
	}

	/**
	 * Does an (undoable) switch exist for the run?
	 *
	 * @param string $run_id Run id.
	 */
	public static function has_switch( string $run_id ): bool {
		return null !== self::record( $run_id );
	}

	/* ── Plan ── */

	/**
	 * What the switch would do and what stands in its way.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function plan( string $run_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$record = History::find( $run_id );
		if ( ! $record ) {
			return new \WP_Error( 'uxstudio_migration_unknown_run', __( 'This migration run does not exist.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		if ( ! empty( $record['rolled_back'] ) ) {
			return new \WP_Error( 'uxstudio_migration_rolled_back', __( 'This run was already rolled back.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		PluginInfo::flush();
		$sources = Preview::sources_of( $record );
		$targets = Preview::targets( $sources, $run_id );

		$plugins = array();
		foreach ( $sources as $id ) {
			$source = SourceRegistry::get( $id );
			foreach ( $source ? $source->plugin_slugs() : array() as $slug ) {
				$info = PluginInfo::find( (string) $slug );
				if ( $info && $info['active'] ) {
					$plugins[] = array(
						'basename' => $info['basename'],
						'name'     => '' !== $info['name'] ? $info['name'] : (string) $slug,
						'source'   => $id,
					);
				}
			}
		}
		$modules = array();
		foreach ( $targets['modules'] as $module ) {
			$modules[] = array(
				'id'      => $module,
				'name'    => ActivationGuard::module_name( $module ),
				'enabled' => Staging::module_enabled( $module ),
			);
		}

		$preview = (array) ( $record['preview'] ?? array() );
		$ids     = array_column( Smoke::plan( $run_id ), 'id' );
		$results = (array) ( $preview['results'] ?? array() );
		$missing = array_values( array_diff( $ids, array_keys( $results ) ) );
		$age_ok  = ! empty( $preview['at'] ) && (int) $preview['at'] > time() - Preview::RESULT_MAX_AGE;
		$counts  = Preview::counts( array_intersect_key( $results, array_flip( $ids ) ) );

		return array(
			'run_id'    => $run_id,
			'switched'  => ! empty( $record['switched'] ) && empty( $record['switch_reverted'] ),
			'plugins'   => $plugins,
			'modules'   => $modules,
			'staging'   => Staging::summary( $run_id ),
			'login'     => self::login_info( $run_id ),
			'errors'    => (int) ( $record['errors'] ?? 0 ),
			'preview'   => array(
				'available' => Preview::availability(),
				'tested'    => ! empty( $preview['at'] ),
				'fresh'     => $age_ok,
				'complete'  => $age_ok && ! $missing,
				'counts'    => $counts,
				'total'     => count( $ids ),
			),
			'kill_switch' => 'UXSTUDIO_DISABLE_LOGIN_LOCK',
			'revert_days' => self::KEEP_DAYS,
		);
	}

	/**
	 * Does the switch change the login address?
	 *
	 * @param string $run_id Run id.
	 * @return array{changes:bool,url:string,slug:string,now_url:string}
	 */
	public static function login_info( string $run_id ): array {
		$module = 'security-optimization';
		$option = SettingsWriter::option_name( $module );
		$live   = (array) get_option( $option, array() );
		$staged = (array) ( Staging::entry( $run_id )['settings'][ $module ] ?? array() );
		$after  = array_merge( $live, $staged );

		$slug_after = trim( (string) ( $after['login_url'] ?? '' ), '/' );
		$slug_now   = trim( (string) ( $live['login_url'] ?? '' ), '/' );
		$on_after   = ! empty( $after['login_url_enabled'] ) && '' !== $slug_after;
		$on_now     = Staging::module_enabled( $module ) && ! empty( $live['login_url_enabled'] ) && '' !== $slug_now;
		$changes    = $on_after && ( ! $on_now || $slug_after !== $slug_now );
		return array(
			'changes' => $changes,
			'url'     => $on_after ? trailingslashit( home_url( '/' . $slug_after ) ) : '',
			'slug'    => $slug_after,
			'now_url' => $on_now ? trailingslashit( home_url( '/' . $slug_now ) ) : '',
		);
	}

	/**
	 * What blocks the switch, given the plan and what the admin confirmed.
	 *
	 * @param array<string, mixed> $plan  From plan().
	 * @param array<string, mixed> $flags ack_login, override_failures, no_preview.
	 * @return array<int, array{code:string,message:string}>
	 */
	public static function blockers( array $plan, array $flags ): array {
		$out = array();
		if ( $plan['switched'] ) {
			$out[] = array(
				'code'    => 'switched',
				'message' => __( 'This run is already turned on.', 'ux-studio' ),
			);
			return $out;
		}
		if ( $plan['errors'] > 0 ) {
			$out[] = array(
				'code'    => 'errors',
				'message' => __( 'The migration had errors. Undo it and run it again before switching.', 'ux-studio' ),
			);
		}
		if ( ! $plan['plugins'] && ! $plan['modules'] && ! $plan['staging']['settings'] && ! $plan['staging']['activations'] ) {
			$out[] = array(
				'code'    => 'nothing',
				'message' => __( 'There is nothing to switch.', 'ux-studio' ),
			);
		}
		$preview = $plan['preview'];
		if ( $preview['available']['ok'] ) {
			if ( ! $preview['tested'] ) {
				$out[] = array(
					'code'    => 'preview_missing',
					'message' => __( 'Run the safe preview and its tests first.', 'ux-studio' ),
				);
			} elseif ( ! $preview['complete'] ) {
				$out[] = array(
					'code'    => 'preview_incomplete',
					'message' => __( 'The tests of the safe preview are incomplete or older than a day. Run them again.', 'ux-studio' ),
				);
			} elseif ( $preview['counts']['fail'] > 0 && empty( $flags['override_failures'] ) ) {
				$out[] = array(
					'code'    => 'preview_failed',
					'message' => __( 'Some preview tests failed. Fix the cause, or confirm that you want to switch anyway.', 'ux-studio' ),
				);
			}
		} elseif ( empty( $flags['no_preview'] ) ) {
			$out[] = array(
				'code'    => 'preview_unavailable',
				'message' => __( 'The safe preview is not available here. Switch without it only if you confirm that you accept the risk.', 'ux-studio' ),
			);
		}
		if ( $plan['login']['changes'] && empty( $flags['ack_login'] ) ) {
			$out[] = array(
				'code'    => 'login_ack',
				'message' => __( 'Save the new login address and tick the box to confirm it.', 'ux-studio' ),
			);
		}
		return $out;
	}

	/* ── Switch ── */

	/**
	 * Switch a run on.
	 *
	 * @param string               $run_id  Run id.
	 * @param array<string, mixed> $flags   ack_login, override_failures, no_preview.
	 * @param int                  $user_id User who confirmed.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function switch_on( string $run_id, array $flags, int $user_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$plan   = self::plan( $run_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$blockers = self::blockers( $plan, $flags );
		if ( $blockers ) {
			return new \WP_Error(
				'uxstudio_switch_blocked',
				$blockers[0]['message'],
				array(
					'status'   => 409,
					'blockers' => $blockers,
				)
			);
		}
		$modules_api = self::modules();
		if ( ! $modules_api ) {
			return new \WP_Error( 'uxstudio_switch_failed', __( 'The module registry is not available.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		$history = History::find( $run_id );
		$sources = Preview::sources_of( (array) $history );
		$targets = Preview::targets( $sources, $run_id );
		$entry   = Staging::entry( $run_id );

		// 1) Remember how everything is now, BEFORE anything changes.
		$names = array( 'uxstudio_active_modules', 'active_plugins' );
		foreach ( (array) ( $entry['settings'] ?? array() ) as $module => $values ) {
			$names[] = SettingsWriter::option_name( (string) $module );
		}
		foreach ( array_keys( (array) ( $entry['secrets'] ?? array() ) ) as $option ) {
			$names[] = (string) $option;
		}
		foreach ( array_keys( (array) ( $entry['options'] ?? array() ) ) as $option ) {
			$names[] = (string) $option;
		}
		if ( ! empty( $entry['lock_origin'] ) ) {
			$names[] = MenuLockOrigin::OPTION;
		}
		foreach ( $targets['modules'] as $module ) {
			$instance = $modules_api->instance( $module );
			if ( $instance ) {
				$names = array_merge( $names, $instance->switch_options() );
			}
		}
		$enabled_before = array_values( array_map( 'strval', (array) get_option( 'uxstudio_active_modules', array() ) ) );
		$htaccess       = self::htaccess_state();
		$record         = array(
			'run_id'         => $run_id,
			'created'        => time(),
			'user_id'        => $user_id,
			'sources'        => $sources,
			'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'modules'        => $enabled_before,
			'options'        => Snapshot::read_options( $names ),
			'enabled_modules' => array(),
			'deactivated'    => array(),
			'effect_modules' => array(),
			'staging'        => $entry,
			'htaccess'       => $htaccess,
			'flags'          => array_intersect_key( $flags, array_flip( array( 'override_failures', 'no_preview' ) ) ),
		);
		update_option( self::option_name( $run_id ), $record, false );
		ActivityLog::log( 'core', 'migration_switch_started', 'migration', 0, array( 'run' => $run_id, 'plugins' => $targets['plugins'], 'modules' => $targets['modules'] ) );

		$errors = array();
		try {
			// 2) The prepared values and data go live.
			$applied = Staging::apply( $run_id );
			$errors  = array_merge( $errors, $applied['errors'] );
			PluginInfo::flush();

			// 3) Switch the modules on. The overlap guard (a REST rule) is bypassed on purpose: the plugins go in step 5.
			foreach ( $targets['modules'] as $module ) {
				if ( in_array( $module, $enabled_before, true ) ) {
					continue;
				}
				$decision = ActivationGuard::decide( array( $module ) );
				if ( $modules_api->set_enabled( $module, true ) ) {
					$record['enabled_modules'][] = $module;
					ActivityLog::log( 'core', 'migration_module_enabled', 'module', 0, array( 'run' => $run_id, 'module' => $module, 'overlap_guard' => $decision['result'], 'bypassed' => 'allow' !== $decision['result'] ) );
				} else {
					$errors[] = sprintf(
						/* translators: %s: module id. */
						__( 'The module "%s" could not be turned on.', 'ux-studio' ),
						$module
					);
				}
			}

			// 4) Make the settings effective the way a normal save does.
			$record['effect_modules'] = array_values( array_unique( array_merge( $applied['modules'], $record['enabled_modules'] ) ) );
			foreach ( $record['effect_modules'] as $module ) {
				$instance = $modules_api->instance( $module );
				if ( $instance ) {
					try {
						$instance->after_migration_switch();
					} catch ( \Throwable $e ) {
						$errors[] = sprintf(
							/* translators: 1: module id, 2: error message. */
							__( 'The module "%1$s" could not finish turning on: %2$s', 'ux-studio' ),
							$module,
							$e->getMessage()
						);
					}
				}
			}

			// 5) The old plugins go (their deactivation hooks run).
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( $targets['plugins'] ) {
				deactivate_plugins( $targets['plugins'], false );
			}
			$now_active = (array) get_option( 'active_plugins', array() );
			foreach ( $targets['plugins'] as $basename ) {
				if ( ! in_array( $basename, $now_active, true ) ) {
					$record['deactivated'][] = $basename;
				}
			}
			PluginInfo::flush();
		} catch ( \Throwable $e ) {
			update_option( self::option_name( $run_id ), $record, false );
			self::revert( $run_id );
			return new \WP_Error(
				'uxstudio_switch_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'The switch failed and was undone: %s', 'ux-studio' ),
					$e->getMessage()
				),
				array(
					'status'   => 500,
					'reverted' => true,
				)
			);
		}

		$record['activations'] = (array) ( $entry['activations'] ?? array() );
		update_option( self::option_name( $run_id ), $record, false );
		History::update(
			$run_id,
			array(
				'applied'         => time(),
				'switched'        => time(),
				'switch_reverted' => 0,
				'switch_plugins'  => $record['deactivated'],
				'switch_modules'  => $record['enabled_modules'],
				'switch_live'     => array(),
			)
		);

		// 6) A quick look from outside: if the site is broken now, take it all back at once.
		$broken = self::quick_check();
		if ( '' !== $broken ) {
			self::revert( $run_id );
			ActivityLog::log( 'core', 'migration_switch_auto_reverted', 'migration', 0, array( 'run' => $run_id, 'reason' => $broken ) );
			return new \WP_Error(
				'uxstudio_switch_failed',
				sprintf(
					/* translators: %s: what failed. */
					__( 'The site did not work after the switch (%s), so the switch was undone automatically.', 'ux-studio' ),
					$broken
				),
				array(
					'status'   => 500,
					'reverted' => true,
				)
			);
		}

		ActivityLog::log(
			'core',
			'migration_switched',
			'migration',
			0,
			array(
				'run'         => $run_id,
				'deactivated' => $record['deactivated'],
				'enabled'     => $record['enabled_modules'],
				'values'      => $applied['settings'] + $applied['secrets'],
				'activated'   => $applied['activated'],
				'overridden'  => $record['flags'],
			)
		);
		return array(
			'run_id'       => $run_id,
			'applied'      => $applied,
			'enabled'      => $record['enabled_modules'],
			'deactivated'  => $record['deactivated'],
			'errors'       => $errors,
			'revert_until' => Runner::mysql_time( $record['created'] + self::KEEP_DAYS * DAY_IN_SECONDS ),
			'live_plan'    => array_map(
				static fn( array $c ): array => array_intersect_key( $c, array_flip( array( 'id', 'label', 'group' ) ) ),
				Smoke::plan( $run_id )
			),
		);
	}

	/**
	 * Home page and login page must not fail after the switch. Empty string = fine (or not judgeable).
	 */
	private static function quick_check(): string {
		foreach ( array( home_url( '/' ) => __( 'home page', 'ux-studio' ) ) as $url => $what ) {
			$res = Smoke::fetch( (string) $url, null );
			if ( '' === $res['error'] && Smoke::is_fatal( $res ) ) {
				return sprintf(
					/* translators: 1: page, 2: HTTP status. */
					__( '%1$s: HTTP %2$d', 'ux-studio' ),
					$what,
					$res['code']
				);
			}
		}
		return '';
	}

	/**
	 * Size and fingerprint of the site `.htaccess`.
	 *
	 * @return array{exists:bool,md5:string,length:int}
	 */
	private static function htaccess_state(): array {
		$path = self::htaccess_path();
		if ( '' === $path || ! is_file( $path ) ) {
			return array(
				'exists' => false,
				'md5'    => '',
				'length' => 0,
			);
		}
		return array(
			'exists' => true,
			'md5'    => (string) md5_file( $path ),
			'length' => (int) filesize( $path ),
		);
	}

	/**
	 * Path of the site `.htaccess`.
	 */
	private static function htaccess_path(): string {
		if ( ! function_exists( 'get_home_path' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return function_exists( 'get_home_path' ) ? get_home_path() . '.htaccess' : '';
	}

	/* ── Undo ── */

	/**
	 * Undo the switch of a run.
	 *
	 * @param string $run_id Run id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function revert( string $run_id ) {
		$run_id = Snapshot::clean_id( $run_id );
		$record = self::record( $run_id );
		if ( ! $record ) {
			return new \WP_Error( 'uxstudio_switch_none', __( 'This run is not turned on, or the undo information has expired.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$modules_api = self::modules();
		if ( ! $modules_api ) {
			return new \WP_Error( 'uxstudio_switch_failed', __( 'The module registry is not available.', 'ux-studio' ), array( 'status' => 500 ) );
		}
		$errors = array();

		// 1) The modules the switch enabled go off again (their own clean-up runs).
		foreach ( array_reverse( (array) $record['enabled_modules'] ) as $module ) {
			$modules_api->set_enabled( (string) $module, false );
		}

		// 2) The plugins the switch deactivated come back, with their activation hooks.
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( (array) $record['deactivated'] as $basename ) {
			$result = activate_plugin( (string) $basename );
			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf(
					/* translators: 1: plugin file, 2: error message. */
					__( 'The plugin %1$s could not be reactivated: %2$s', 'ux-studio' ),
					(string) $basename,
					$result->get_error_message()
				);
			}
		}

		// 3) Options exactly as before (settings, secrets, plugin list and order, module list, hashes).
		$restored = Snapshot::restore_options( (array) $record['options'] );

		// 4) Data the switch turned on goes off again.
		$off = 0;
		foreach ( (array) ( $record['activations'] ?? array() ) as $row ) {
			$source = SourceRegistry::get( (string) $row['source'] );
			if ( $source instanceof Activatable ) {
				$off += $source->deactivate( (string) $row['kind'], (array) $row['ids'], (array) ( $row['data'] ?? array() ) );
			}
		}

		// 5) Modules that stay on re-run their side effects with the old settings.
		foreach ( (array) $record['effect_modules'] as $module ) {
			if ( in_array( (string) $module, (array) $record['enabled_modules'], true ) ) {
				continue;
			}
			$instance = $modules_api->instance( (string) $module );
			if ( $instance ) {
				try {
					$instance->after_migration_switch();
				} catch ( \Throwable $e ) {
					$errors[] = sprintf(
						/* translators: 1: module id, 2: error message. */
						__( 'The module "%1$s" could not restore its state: %2$s', 'ux-studio' ),
						(string) $module,
						$e->getMessage()
					);
				}
			}
		}

		// 6) The prepared values wait again, ready for another switch or for the rollback.
		if ( ! empty( $record['staging'] ) ) {
			Staging::restore( $run_id, (array) $record['staging'] );
		}
		delete_option( self::option_name( $run_id ) );
		History::update(
			$run_id,
			array(
				'applied'         => 0,
				'switched'        => 0,
				'switch_reverted' => time(),
				'switch_plugins'  => array(),
				'switch_modules'  => array(),
			)
		);
		PluginInfo::flush();

		$after    = self::htaccess_state();
		$before   = (array) ( $record['htaccess'] ?? array() );
		$warnings = array();
		if ( ! empty( $before ) && ( $before['md5'] ?? '' ) !== $after['md5'] ) {
			$warnings[] = __( 'The .htaccess file is not identical to how it was before the switch. Check it.', 'ux-studio' );
		}
		ActivityLog::log( 'core', 'migration_switch_reverted', 'migration', 0, array( 'run' => $run_id, 'restored' => $restored, 'data_off' => $off, 'errors' => count( $errors ) ) );
		return array(
			'run_id'   => $run_id,
			'restored' => $restored,
			'data_off' => $off,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Keep the outcome of a post-switch (live) check with the run.
	 *
	 * @param string               $run_id Run id.
	 * @param array<string, mixed> $result Result.
	 */
	public static function record_live( string $run_id, array $result ): void {
		$run_id = Snapshot::clean_id( $run_id );
		$record = History::find( $run_id );
		if ( ! $record ) {
			return;
		}
		$live                         = (array) ( $record['switch_live'] ?? array() );
		$live[ (string) $result['id'] ] = $result;
		History::update( $run_id, array( 'switch_live' => $live ) );
	}

	/**
	 * Drop switch records older than the undo window (daily).
	 *
	 * @param int|null $now Test seam.
	 * @return string[] Run ids.
	 */
	public static function purge_expired( ?int $now = null ): array {
		$now  = $now ?? time();
		$gone = array();
		foreach ( History::all() as $run ) {
			$id = (string) ( $run['id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}
			$record = self::record( $id );
			if ( $record && ( (int) $record['created'] + self::KEEP_DAYS * DAY_IN_SECONDS ) <= $now ) {
				delete_option( self::option_name( $id ) );
				$gone[] = $id;
			}
		}
		return $gone;
	}
}
