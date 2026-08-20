<?php
/**
 * WP-Cron scheduling for Blog Pilot: one recurring `wp_schedule_event()` per
 * active generator (hook uxstudio_ai_assistant_blog_pilot_generate, generator
 * id as the single cron arg). Generation always runs from the cron callback
 * or via run_manual() - never synchronously inside an HTTP request - and any
 * failure is caught and written to ErrorLogger so a broken generator can
 * never take the whole cron run down with it.
 *
 * Ported from the legacy ux1-wordpress-customizer AI Assistant module
 * (includes/blog-pilot/Scheduler.php).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiAssistant\BlogPilot;

use UxStudio\Modules\AiAssistant\ErrorLogger;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const CRON_HOOK = 'uxstudio_ai_assistant_blog_pilot_generate';

	/**
	 * Registers the custom weekly/monthly cron intervals used by weekly/monthly generators.
	 *
	 * @param array<string, array{interval:int,display:string}> $schedules
	 * @return array<string, array{interval:int,display:string}>
	 */
	public static function register_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['uxstudio_weekly'] ) ) {
			$schedules['uxstudio_weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Weekly (UX Studio)', 'ux-studio' ),
			);
		}

		if ( ! isset( $schedules['uxstudio_monthly'] ) ) {
			$schedules['uxstudio_monthly'] = array(
				'interval' => MONTH_IN_SECONDS,
				'display'  => __( 'Monthly (UX Studio)', 'ux-studio' ),
			);
		}

		return $schedules;
	}

	/**
	 * (Re)schedules a generator's cron event. Inactive/missing generators are unscheduled.
	 */
	public function schedule_generator( int $generator_id ): void {
		$manager   = new GeneratorManager();
		$generator = $manager->get( $generator_id );

		if ( ! $generator || 'active' !== $generator->status ) {
			$this->unschedule_generator( $generator_id );
			return;
		}

		$this->unschedule_generator( $generator_id );

		$next_run  = $this->calculate_next_run( $generator );
		$recurrence = $this->map_schedule_type( $generator->schedule_type );

		wp_schedule_event( $next_run, $recurrence, self::CRON_HOOK, array( $generator_id ) );
	}

	public function unschedule_generator( int $generator_id ): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK, array( $generator_id ) );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK, array( $generator_id ) );
		}
	}

	/**
	 * Schedules any active generator that isn't already on the cron - call on init/boot.
	 */
	public function reschedule_all(): void {
		$manager = new GeneratorManager();
		$result  = $manager->get_all( array( 'status' => 'active' ), 1, 100 );

		foreach ( $result['items'] as $generator ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK, array( $generator->id ) ) ) {
				$this->schedule_generator( (int) $generator->id );
			}
		}
	}

	/**
	 * Cron callback: generates posts_per_run article(s) for one generator.
	 * Never throws - any failure is logged (ErrorLogger + the generator's
	 * own last_error column) and generation simply stops for this run.
	 */
	public function execute_generation( int $generator_id ): void {
		$manager   = new GeneratorManager();
		$generator = $manager->get( $generator_id );

		if ( ! $generator || 'active' !== $generator->status ) {
			$this->unschedule_generator( $generator_id );
			return;
		}

		$post_creator  = new PostCreator();
		$posts_per_run = max( 1, (int) $generator->posts_per_run );

		for ( $i = 0; $i < $posts_per_run; $i++ ) {
			try {
				$topic        = $this->select_topic( $generator );
				$article_type = $this->select_article_type( $generator );

				$result = $post_creator->generate( $generator, $topic, $article_type );

				$manager->log_generated_post(
					(int) $generator->id,
					$result['post_id'],
					array(
						'topic'        => $topic,
						'article_type' => $article_type,
						'provider'     => $generator->provider,
						'model'        => $generator->model,
						'tokens_used'  => ( $result['usage']['input_tokens'] ?? 0 ) + ( $result['usage']['output_tokens'] ?? 0 ),
					)
				);
			} catch ( \Throwable $e ) {
				$this->log_failure( $generator_id, $e );
				$manager->log_error( $generator_id, $e->getMessage() );
				break; // Stop further attempts in this run on the first failure.
			}
		}
	}

	/**
	 * Runs a generator immediately (outside of cron), e.g. from the "Run now" REST action.
	 *
	 * @return array{success:bool,post_id?:int,title?:string,edit_url?:string,error?:string}
	 */
	public function run_manual( int $generator_id ): array {
		$manager   = new GeneratorManager();
		$generator = $manager->get( $generator_id );

		if ( ! $generator ) {
			return array(
				'success' => false,
				'error'   => __( 'Generator not found.', 'ux-studio' ),
			);
		}

		$post_creator = new PostCreator();

		try {
			$topic        = $this->select_topic( $generator );
			$article_type = $this->select_article_type( $generator );
			$result       = $post_creator->generate( $generator, $topic, $article_type );

			$manager->log_generated_post(
				(int) $generator->id,
				$result['post_id'],
				array(
					'topic'        => $topic,
					'article_type' => $article_type,
					'provider'     => $generator->provider,
					'model'        => $generator->model,
					'tokens_used'  => ( $result['usage']['input_tokens'] ?? 0 ) + ( $result['usage']['output_tokens'] ?? 0 ),
				)
			);

			return array(
				'success'  => true,
				'post_id'  => $result['post_id'],
				'title'    => $result['title'],
				'edit_url' => get_edit_post_link( $result['post_id'], 'raw' ),
			);
		} catch ( \Throwable $e ) {
			$this->log_failure( $generator_id, $e );
			$manager->log_error( $generator_id, $e->getMessage() );
			return array(
				'success' => false,
				'error'   => $e->getMessage(),
			);
		}
	}

	public function is_scheduled( int $generator_id ): ?int {
		$timestamp = wp_next_scheduled( self::CRON_HOOK, array( $generator_id ) );
		return $timestamp ?: null;
	}

	private function log_failure( int $generator_id, \Throwable $e ): void {
		$detail = ErrorLogger::parse_exception_detail( $e->getMessage() );
		ErrorLogger::log(
			array(
				'error_type'    => ErrorLogger::TYPE_PROVIDER,
				'error_message' => $e->getMessage(),
				'http_status'   => $detail['http_status'],
				'context'       => array(
					'scope'           => 'blog_pilot_scheduler',
					'generator_id'    => $generator_id,
					'exception_class' => get_class( $e ),
				),
			)
		);
	}

	private function select_topic( object $generator ): string {
		$topics = $generator->topics;
		if ( empty( $topics ) ) {
			return __( 'General article', 'ux-studio' );
		}

		return $topics[ array_rand( $topics ) ];
	}

	private function select_article_type( object $generator ): string {
		$types = $generator->article_types;
		if ( empty( $types ) ) {
			$all_types = array_keys( ArticleTypes::TYPES );
			return $all_types[ array_rand( $all_types ) ];
		}

		return $types[ array_rand( $types ) ];
	}

	private function calculate_next_run( object $generator ): int {
		$config     = $generator->schedule_config;
		$time_from  = $config['time_from'] ?? '08:00';
		$time_to    = $config['time_to'] ?? '18:00';

		$from_ts = strtotime( "today {$time_from}" );
		$to_ts   = strtotime( "today {$time_to}" );

		if ( false === $from_ts ) {
			$from_ts = time();
		}
		if ( false === $to_ts || $from_ts >= $to_ts ) {
			$to_ts = $from_ts + HOUR_IN_SECONDS;
		}

		$random_time = wp_rand( $from_ts, $to_ts );

		if ( $random_time <= time() ) {
			$random_time += DAY_IN_SECONDS;
		}

		if ( 'weekly' === $generator->schedule_type ) {
			$days_of_week = $config['days_of_week'] ?? array( 1 ); // Default: Monday.
			$current_day  = (int) gmdate( 'N' ); // 1=Mon, 7=Sun.
			$target_day   = $this->find_next_day( $current_day, $days_of_week );
			$days_ahead   = $target_day - $current_day;
			if ( $days_ahead <= 0 ) {
				$days_ahead += 7;
			}
			$random_time += $days_ahead * DAY_IN_SECONDS;
		}

		return $random_time;
	}

	/**
	 * @param array<int, int> $days
	 */
	private function find_next_day( int $current_day, array $days ): int {
		sort( $days );
		foreach ( $days as $day ) {
			if ( $day >= $current_day ) {
				return $day;
			}
		}
		return $days[0]; // Wrap to next week.
	}

	private function map_schedule_type( string $type ): string {
		$map = array(
			'daily'   => 'daily',
			'weekly'  => 'uxstudio_weekly',
			'monthly' => 'uxstudio_monthly',
		);

		return $map[ $type ] ?? 'daily';
	}
}
