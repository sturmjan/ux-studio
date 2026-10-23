<?php
/**
 * `uxstudio/migration-*` abilities: the migration wizard for agents.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\Migration\Switcher;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability dispatches the wizard's own REST route, so the safety rules of the wizard
 * (snapshots, the F4 preview and test requirements, the login lock check) apply unchanged.
 * Read abilities (scan, plan, status, runs, switch plan) need `read`, everything that writes
 * needs `migration`. Switching on and the two undo operations need an explicit `confirm`.
 */
final class MigrationAbilities {

	private const RUN_ID = array(
		'type'        => 'string',
		'minLength'   => 3,
		'maxLength'   => 64,
		'description' => 'Run id from migration-run / migration-runs.',
	);

	/**
	 * Definitions of this group.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		$R = Scopes::READ;
		$M = Scopes::MIGRATION;

		$ro      = array( 'annotations' => array( 'readonly' => true ) );
		$sources = array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'description' => 'Source ids from migration-scan (e.g. disable-comments).',
		);
		$write   = array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ) );

		return array(
			Registry::define(
				'uxstudio/migration-scan',
				__( 'Scan for migratable plugins', 'ux-studio' ),
				'Installed (or leftover) plugins whose settings or data UX Studio can take over, with their state and the target module. First step of a migration.',
				$R,
				array(),
				array(),
				static fn(): array => (array) Internal::data( 'GET', '/migration/scan' ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-plan',
				__( 'Plan a migration', 'ux-studio' ),
				'What would be transferred and what would NOT, per source. Writes nothing.',
				$R,
				array( 'sources' => $sources ),
				array( 'sources' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/plan', array( 'sources' => array_values( $in['sources'] ) ) ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-status',
				__( 'Migration status', 'ux-studio' ),
				'State of the current or last run (a long run continues with migration-run continue=true) and what is staged for it.',
				$R,
				array(),
				array(),
				static fn(): array => (array) Internal::data( 'GET', '/migration/status' ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-runs',
				__( 'Migration history', 'ux-studio' ),
				'Past runs with their result, whether they can still be rolled back and whether they were switched on.',
				$R,
				array(),
				array(),
				static fn(): array => (array) Internal::data( 'GET', '/migration/runs' ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-switch-plan',
				__( 'Plan the switch', 'ux-studio' ),
				'What switching a run on would do: plugins to deactivate, modules to enable, login URL change, preview test results, and what still blocks it.',
				$R,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				array( self::class, 'switch_plan' ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-preview-status',
				__( 'Preview status', 'ux-studio' ),
				'State of the safe preview of a run: expiry, plugins switched off in it, saved test results and the list of tests.',
				$R,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				static fn( array $in ): array => (array) Internal::data( 'GET', '/migration/preview', array( 'run_id' => $in['run_id'] ) ),
				$ro
			),

			Registry::define(
				'uxstudio/migration-run',
				__( 'Run a migration', 'ux-studio' ),
				'Copy settings and data of the chosen sources into UX Studio (the source plugin is never changed). dry_run defaults to TRUE: nothing is written and the result shows what would happen. A real run needs dry_run=false; it takes a snapshot first and stays staged - nothing is switched on until migration-switch. A long run returns status "running": call again with continue=true until it is done.',
				$M,
				array(
					'sources'  => $sources,
					'dry_run'  => array( 'type' => 'boolean', 'default' => true, 'description' => 'true (default) = rehearsal, writes nothing. false = real run.' ),
					'continue' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Process the next batch of the current run instead of starting one.' ),
				),
				array(),
				array( self::class, 'run' ),
				$write
			),
			Registry::define(
				'uxstudio/migration-verify',
				__( 'Verify a migration', 'ux-studio' ),
				'Compare the source plugin with what UX Studio now holds, per source. Differences are reported, never hidden.',
				$M,
				array( 'sources' => $sources ),
				array(),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/verify', array( 'sources' => array_values( (array) ( $in['sources'] ?? array() ) ) ) ),
				$ro
			),
			Registry::define(
				'uxstudio/migration-preview-start',
				__( 'Start the safe preview', 'ux-studio' ),
				'Start the safe preview of a real run: for an admin who opens enter_url the old plugins are off and the UX Studio modules are on, visitors still get the old setup. Then run migration-smoke with mode=preview.',
				$M,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/preview', array( 'run_id' => $in['run_id'] ) ),
				$write
			),
			Registry::define(
				'uxstudio/migration-preview-end',
				__( 'End the safe preview', 'ux-studio' ),
				'End the preview of a run (test results stay).',
				$M,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/preview/end', array( 'run_id' => $in['run_id'] ) ),
				array( 'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) )
			),
			Registry::define(
				'uxstudio/migration-smoke',
				__( 'Run smoke tests', 'ux-studio' ),
				'Automatic tests of a run. Without check: the list of tests. With check=<id>: that test. With check="all": every test one after another. mode=preview tests the safe preview (before the switch), mode=live tests the real site (after the switch). The switch needs fresh, passing preview results.',
				$M,
				array(
					'run_id' => self::RUN_ID,
					'mode'   => array( 'type' => 'string', 'enum' => array( 'preview', 'live' ) ),
					'check'  => array( 'type' => 'string', 'maxLength' => 64, 'description' => 'Test id, or "all". Omit to list the tests.' ),
				),
				array( 'run_id', 'mode' ),
				array( self::class, 'smoke' ),
				$write
			),
			Registry::define(
				'uxstudio/migration-switch',
				__( 'Switch a migration on', 'ux-studio' ),
				'Make the migrated setup live: deactivate the old plugins and enable the UX Studio modules for EVERYBODY. Needs confirm=true - without it nothing happens and the reply lists the consequences, including a change of the login URL (then ack_login=true is needed too). Requires fresh, passing preview tests; override=true (with confirm) accepts failed or unavailable tests at the user\'s own risk. Can be undone for 30 days with migration-revert.',
				$M,
				array(
					'run_id'    => self::RUN_ID,
					'ack_login' => array( 'type' => 'boolean', 'default' => false, 'description' => 'The user noted the new login address (needed when the plan says login.changes).' ),
					'override'  => array( 'type' => 'boolean', 'default' => false, 'description' => 'Switch although preview tests failed or the preview is unavailable. Only on the user\'s explicit instruction.' ),
				),
				array( 'run_id' ),
				array( self::class, 'switch_on' ),
				array(
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'confirm'     => array( self::class, 'switch_confirmation' ),
				)
			),
			Registry::define(
				'uxstudio/migration-revert',
				__( 'Undo the switch', 'ux-studio' ),
				'Undo a switch: the old plugins are activated again, the modules the switch enabled go off, options return to their state before. Needs confirm=true. Possible for 30 days after the switch.',
				$M,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/revert-switch', array( 'run_id' => $in['run_id'] ) ),
				array(
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'confirm'     => array( self::class, 'revert_confirmation' ),
				)
			),
			Registry::define(
				'uxstudio/migration-rollback',
				__( 'Roll a migration back', 'ux-studio' ),
				'Restore the snapshot taken before a real run: the options and tables UX Studio had are put back and the staged data is discarded. A switched-on run is un-switched first. Needs confirm=true.',
				$M,
				array( 'run_id' => self::RUN_ID ),
				array( 'run_id' ),
				static fn( array $in ): array => (array) Internal::data( 'POST', '/migration/rollback', array( 'run_id' => $in['run_id'] ) ),
				array(
					'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
					'confirm'     => array( self::class, 'rollback_confirmation' ),
				)
			),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function switch_plan( array $in ): array {
		$plan             = (array) Internal::data( 'GET', '/migration/switch-plan', array( 'run_id' => $in['run_id'] ) );
		$plan['blockers'] = Switcher::blockers( $plan, array() );
		return $plan;
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function run( array $in ): array {
		$continue = ! empty( $in['continue'] );
		$dry_run  = array_key_exists( 'dry_run', $in ) ? (bool) $in['dry_run'] : true;
		if ( ! $continue && empty( $in['sources'] ) ) {
			throw new McpError( 'invalid_input', 'sources is required to start a run (ids from migration-scan).' );
		}
		return (array) Internal::data(
			'POST',
			'/migration/run',
			array(
				'sources'  => array_values( (array) ( $in['sources'] ?? array() ) ),
				'dry_run'  => $dry_run,
				'continue' => $continue,
			)
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function smoke( array $in ): array {
		$run_id = (string) $in['run_id'];
		$mode   = (string) $in['mode'];
		$check  = (string) ( $in['check'] ?? '' );

		$plan = (array) Internal::data( 'POST', '/migration/smoke', array( 'run_id' => $run_id, 'mode' => $mode ) );
		if ( '' === $check ) {
			return $plan;
		}
		if ( 'all' !== $check ) {
			return (array) Internal::data( 'POST', '/migration/smoke', array( 'run_id' => $run_id, 'mode' => $mode, 'check' => $check ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		$results = array();
		foreach ( (array) ( $plan['plan'] ?? array() ) as $item ) {
			$id             = (string) $item['id'];
			$results[ $id ] = (array) Internal::data( 'POST', '/migration/smoke', array( 'run_id' => $run_id, 'mode' => $mode, 'check' => $id ) );
		}
		return array(
			'mode'    => $mode,
			'count'   => count( $results ),
			'results' => $results,
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array<string, mixed>
	 */
	public static function switch_on( array $in ): array {
		$override = ! empty( $in['override'] );
		return (array) Internal::data(
			'POST',
			'/migration/switch',
			array(
				'run_id'            => $in['run_id'],
				'ack_login'         => ! empty( $in['ack_login'] ),
				'override_failures' => $override,
				'no_preview'        => $override,
			)
		);
	}

	/**
	 * Explanation returned instead of switching when confirm is missing.
	 *
	 * @param array<string, mixed> $in Input.
	 * @return array{message:string,data:array<string, mixed>}
	 */
	public static function switch_confirmation( array $in ): array {
		$plan    = self::switch_plan( $in ); // An unknown or rolled back run surfaces as its own error.
		$plugins = array_column( (array) ( $plan['plugins'] ?? array() ), 'name' );
		$modules = array_column( (array) ( $plan['modules'] ?? array() ), 'name' );
		$login   = (array) ( $plan['login'] ?? array() );
		$parts   = array(
			'Switching on is NOT done yet. It would deactivate ' . ( $plugins ? implode( ', ', $plugins ) : 'no plugin' ) . ' and enable the UX Studio module(s) ' . ( $modules ? implode( ', ', $modules ) : '(none)' ) . ' for all visitors.',
		);
		if ( ! empty( $login['changes'] ) ) {
			$parts[] = 'The login address CHANGES to ' . ( $login['url'] ?? '' ) . ' (now ' . ( $login['now_url'] ?? '' ) . '). The user must save the new address first; then pass ack_login=true.';
		}
		if ( ! empty( $plan['blockers'] ) ) {
			$parts[] = 'Currently blocked by: ' . implode( ' ', array_column( $plan['blockers'], 'message' ) );
		}
		$parts[] = 'It can be undone with migration-revert for ' . ( $plan['revert_days'] ?? 30 ) . ' days. Ask the user, then call again with confirm=true.';
		return array(
			'message' => implode( ' ', $parts ),
			'data'    => array( 'plan' => $plan ),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array{message:string,data:array<string, mixed>}
	 */
	public static function revert_confirmation( array $in ): array {
		$record = Switcher::record( \UxStudio\Core\Migration\Snapshot::clean_id( (string) $in['run_id'] ) );
		return array(
			'message' => 'Undoing the switch is NOT done yet. It would re-activate ' . ( $record && $record['deactivated'] ? implode( ', ', (array) $record['deactivated'] ) : 'the plugins the switch deactivated' ) . ', switch the modules the switch enabled off again and restore the options (including the login address) to their state before the switch. Ask the user, then call again with confirm=true.',
			'data'    => array(
				'plugins' => $record ? array_values( (array) $record['deactivated'] ) : array(),
				'modules' => $record ? array_values( (array) $record['enabled_modules'] ) : array(),
			),
		);
	}

	/**
	 * @param array<string, mixed> $in Input.
	 * @return array{message:string,data:array<string, mixed>}
	 */
	public static function rollback_confirmation( array $in ): array {
		return array(
			'message' => 'Rolling back is NOT done yet. It would restore the UX Studio options and tables to the snapshot taken before this run, discard the data staged for it and (if it was switched on) undo the switch first, which re-activates the old plugins and may change the login address back. Ask the user, then call again with confirm=true.',
			'data'    => array( 'run_id' => (string) $in['run_id'] ),
		);
	}
}
