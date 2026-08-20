<?php
/**
 * Wires up the Blog Pilot REST routes and WP-Cron scheduling. Called
 * explicitly from Module::boot() once this wave is integrated (not
 * auto-registered here, so it can land independently of that change) - see
 * the "Later waves add: ... BlogPilotBootstrap::register();" comment there.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant;

use UxStudio\Modules\AiAssistant\BlogPilot\Scheduler;

defined( 'ABSPATH' ) || exit;

final class BlogPilotBootstrap {

	/**
	 * Throttles Scheduler::reschedule_all() (a handful of extra queries) to
	 * once per hour instead of running it on every single front-end request.
	 */
	private const RESCHEDULE_TRANSIENT = 'uxstudio_ai_assistant_blog_pilot_reschedule';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		add_filter( 'cron_schedules', array( Scheduler::class, 'register_cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( Scheduler::CRON_HOOK, array( self::class, 'run_cron_generation' ) );

		add_action( 'init', array( self::class, 'maybe_reschedule' ) );
	}

	public static function register_rest_routes(): void {
		( new BlogPilotRestController() )->register_routes();
	}

	/**
	 * WP-Cron entry point for Scheduler::CRON_HOOK. Scheduler::execute_generation()
	 * already catches and logs per-post failures; this outer guard also
	 * catches anything raised before that loop (e.g. DB errors constructing
	 * the manager) so a broken generator can never abort the whole cron run.
	 */
	public static function run_cron_generation( int $generator_id ): void {
		try {
			( new Scheduler() )->execute_generation( $generator_id );
		} catch ( \Throwable $e ) {
			ErrorLogger::log(
				array(
					'error_type'    => ErrorLogger::TYPE_UNKNOWN,
					'error_message' => $e->getMessage(),
					'context'       => array(
						'scope'           => 'blog_pilot_cron',
						'generator_id'    => $generator_id,
						'exception_class' => get_class( $e ),
					),
				)
			);
		}
	}

	/**
	 * Ensures every active generator has a scheduled cron event, at most
	 * once per hour (guarded by a transient) so it stays cheap on busy sites.
	 */
	public static function maybe_reschedule(): void {
		if ( false !== get_transient( self::RESCHEDULE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::RESCHEDULE_TRANSIENT, 1, HOUR_IN_SECONDS );

		try {
			( new Scheduler() )->reschedule_all();
		} catch ( \Throwable $e ) {
			ErrorLogger::log(
				array(
					'error_type'    => ErrorLogger::TYPE_UNKNOWN,
					'error_message' => $e->getMessage(),
					'context'       => array(
						'scope'           => 'blog_pilot_reschedule',
						'exception_class' => get_class( $e ),
					),
				)
			);
		}
	}
}
