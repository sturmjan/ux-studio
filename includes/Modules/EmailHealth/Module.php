<?php
/**
 * Email Health module - verify outgoing mail delivery.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\EmailHealth;

use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a test email through wp_mail(), detects the transport (PHP mail vs.
 * SMTP), captures any failure and stores the outcome. The SPA drives manual
 * tests via the module REST endpoints; an optional scheduled test runs on a
 * fixed interval. Ported from the legacy module (the mail-tester.com weekly
 * scraper was intentionally dropped - see the port notes).
 */
final class Module extends BaseModule {

	/**
	 * Option holding the last test result.
	 */
	public const OPTION_RESULT = 'uxstudio_email_health_result';

	/**
	 * Option holding the last scheduled-run timestamp (compare-and-swap gate).
	 */
	private const OPTION_LAST_RUN = 'uxstudio_email_health_last_run';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( $this->settings->get( 'auto_test', false ) ) {
			add_action( 'admin_init', array( $this, 'maybe_run_scheduled_test' ) );
		}
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Run the periodic test when the configured interval has elapsed. Uses an
	 * atomic option-based compare-and-swap so concurrent admin requests only
	 * fire one test.
	 */
	public function maybe_run_scheduled_test(): void {
		$interval_hours   = max( 1, (int) $this->settings->get( 'test_interval', 24 ) );
		$interval_seconds = $interval_hours * HOUR_IN_SECONDS;

		global $wpdb;

		$last_run = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_LAST_RUN
			)
		);

		if ( ( time() - $last_run ) < $interval_seconds ) {
			return;
		}

		$now = time();

		if ( 0 === $last_run ) {
			$rows = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					self::OPTION_LAST_RUN,
					(string) $now,
					'no'
				)
			);
		} else {
			$rows = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) = %d",
					(string) $now,
					self::OPTION_LAST_RUN,
					$last_run
				)
			);
		}

		wp_cache_delete( self::OPTION_LAST_RUN, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( (int) $rows > 0 ) {
			$this->run_test();
		}
	}

	/**
	 * Send a test email, detect the transport, and persist the result.
	 *
	 * @return array{success:bool,error:string,method:string,recipient:string,tested_at:string,timestamp:int}
	 */
	public function run_test(): array {
		$recipient = (string) $this->settings->get( 'test_recipient', '' );
		if ( '' === $recipient ) {
			$recipient = (string) get_option( 'admin_email' );
		}

		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return $this->save_result( false, __( 'Invalid recipient email address.', 'ux-studio' ), '', $recipient );
		}

		$mail_method   = 'unknown';
		$detect_method = static function ( $phpmailer ) use ( &$mail_method ): void {
			$mail_method = $phpmailer->Mailer;
			if ( 'smtp' === $phpmailer->Mailer && ! empty( $phpmailer->Host ) ) {
				$mail_method = 'smtp (' . $phpmailer->Host . ')';
			}
		};
		add_action( 'phpmailer_init', $detect_method, 999 );

		$error_message = '';
		$on_failure    = static function ( WP_Error $error ) use ( &$error_message ): void {
			$error_message = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $on_failure, 1 );

		$subject = sprintf(
			/* translators: 1: site name, 2: date/time */
			__( '[%1$s] Email Health Check - %2$s', 'ux-studio' ),
			get_bloginfo( 'name' ),
			wp_date( 'Y-m-d H:i' )
		);

		$body = sprintf(
			/* translators: 1: site name, 2: date/time */
			__( "This is an automated test email from %1\$s.\nTime: %2\$s\n\nIf you can read this, outgoing email is working correctly.", 'ux-studio' ),
			get_bloginfo( 'name' ),
			wp_date( 'Y-m-d H:i:s' )
		);

		$success = wp_mail( $recipient, $subject, $body );

		remove_action( 'phpmailer_init', $detect_method, 999 );
		remove_action( 'wp_mail_failed', $on_failure, 1 );

		return $this->save_result( (bool) $success, $error_message, $mail_method, $recipient );
	}

	/**
	 * Read the last stored result.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_last_result(): ?array {
		$result = get_option( self::OPTION_RESULT, null );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Persist and return a result record.
	 *
	 * @param bool   $success   Whether wp_mail() reported success.
	 * @param string $error     Error message, if any.
	 * @param string $method    Detected transport.
	 * @param string $recipient Recipient address.
	 * @return array{success:bool,error:string,method:string,recipient:string,tested_at:string,timestamp:int}
	 */
	private function save_result( bool $success, string $error, string $method, string $recipient ): array {
		$result = array(
			'success'   => $success,
			'error'     => $error,
			'method'    => $method,
			'recipient' => $recipient,
			'tested_at' => current_time( 'mysql' ),
			'timestamp' => time(),
		);

		update_option( self::OPTION_RESULT, $result, false );

		return $result;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'test_recipient',
				'type'    => 'text',
				'label'   => __( 'Test recipient', 'ux-studio' ),
				'help'    => __( 'Email address that receives the test message. Defaults to the site admin email.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'auto_test',
				'type'    => 'toggle',
				'label'   => __( 'Scheduled test', 'ux-studio' ),
				'help'    => __( 'Automatically send a test email on a fixed interval.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'test_interval',
				'type'    => 'select',
				'label'   => __( 'Test interval', 'ux-studio' ),
				'help'    => __( 'How often the scheduled test email is sent.', 'ux-studio' ),
				'options' => array(
					'1'  => __( 'Every hour', 'ux-studio' ),
					'3'  => __( 'Every 3 hours', 'ux-studio' ),
					'6'  => __( 'Every 6 hours', 'ux-studio' ),
					'12' => __( 'Every 12 hours', 'ux-studio' ),
					'24' => __( 'Once a day', 'ux-studio' ),
				),
				'default' => '24',
			),
		);
	}
}
