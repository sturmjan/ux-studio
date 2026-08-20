<?php
/**
 * SMTP Email module - route wp_mail() through SMTP or the Brevo API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy smtp-email module as a group-C module
 * with its own SPA screen. Gmail OAuth is intentionally NOT ported (large
 * standalone feature, left for later) - the mailer choice is limited to
 * plain SMTP or the Brevo transactional email API.
 *
 * Secrets (SMTP password, Brevo API key) are never stored in the regular
 * uxstudio_smtp-email settings option; they go through Security::store_secret()
 * in dedicated uxstudio_secret_* options and are never echoed back via REST.
 */
final class Module extends BaseModule {

	private const SECRET_PASSWORD = 'uxstudio_secret_smtp_password';
	private const SECRET_BREVO    = 'uxstudio_secret_smtp_brevo_api_key';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'smtp-email',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_smtp_logs (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						to_email VARCHAR(255) NOT NULL DEFAULT '',
						subject VARCHAR(255) NOT NULL DEFAULT '',
						status VARCHAR(20) NOT NULL DEFAULT '',
						error TEXT NULL,
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		$mailer = (string) $this->settings->get( 'mailer', 'smtp' );
		if ( 'brevo' === $mailer ) {
			add_filter( 'pre_wp_mail', array( $this, 'send_via_brevo' ), 10, 2 );
		} else {
			add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
		}

		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
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
	 * Settings schema for the generic renderer / embedded Settings tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'mailer',
				'type'    => 'select',
				'label'   => __( 'Mailer', 'ux-studio' ),
				'help'    => __( 'How outgoing mail is sent.', 'ux-studio' ),
				'options' => array(
					'smtp'  => __( 'SMTP', 'ux-studio' ),
					'brevo' => __( 'Brevo API', 'ux-studio' ),
				),
				'default' => 'smtp',
			),
			array(
				'key'     => 'host',
				'type'    => 'text',
				'label'   => __( 'SMTP host', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'port',
				'type'    => 'number',
				'label'   => __( 'SMTP port', 'ux-studio' ),
				'default' => 587,
			),
			array(
				'key'     => 'encryption',
				'type'    => 'select',
				'label'   => __( 'Encryption', 'ux-studio' ),
				'options' => array(
					'tls'  => __( 'TLS', 'ux-studio' ),
					'ssl'  => __( 'SSL', 'ux-studio' ),
					'none' => __( 'None', 'ux-studio' ),
				),
				'default' => 'tls',
			),
			array(
				'key'     => 'username',
				'type'    => 'text',
				'label'   => __( 'SMTP username', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'password',
				'type'    => 'text',
				'label'   => __( 'SMTP password', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current password.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'brevo_api_key',
				'type'    => 'text',
				'label'   => __( 'Brevo API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
		);
	}

	/**
	 * Intercept the two secret fields before they reach the plain settings
	 * option; everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'password', $input ) && '' !== (string) $input['password'] ) {
			Security::store_secret( self::SECRET_PASSWORD, (string) $input['password'] );
		}
		unset( $input['password'] );

		if ( array_key_exists( 'brevo_api_key', $input ) && '' !== (string) $input['brevo_api_key'] ) {
			Security::store_secret( self::SECRET_BREVO, (string) $input['brevo_api_key'] );
		}
		unset( $input['brevo_api_key'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak the secrets back to the client; expose only whether they're set.
	 */
	public function settings_values(): array {
		$values                       = parent::settings_values();
		$values['password']           = '';
		$values['brevo_api_key']      = '';
		$values['has_password']       = '' !== Security::get_secret( self::SECRET_PASSWORD );
		$values['has_brevo_api_key']  = '' !== Security::get_secret( self::SECRET_BREVO );
		return $values;
	}

	/**
	 * Configure PHPMailer for SMTP transport (mailer = smtp).
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_phpmailer( $phpmailer ): void {
		$host = (string) $this->settings->get( 'host', '' );
		if ( '' === $host ) {
			return;
		}

		$encryption = (string) $this->settings->get( 'encryption', 'tls' );

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = (int) $this->settings->get( 'port', 587 );
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = (string) $this->settings->get( 'username', '' );
		$phpmailer->Password   = Security::get_secret( self::SECRET_PASSWORD );
		$phpmailer->SMTPSecure = 'none' === $encryption ? '' : $encryption;
		$phpmailer->SMTPAutoTLS = 'none' !== $encryption;
	}

	/**
	 * Send via the Brevo transactional email API instead of SMTP/mail()
	 * (mailer = brevo). Hooked on pre_wp_mail: returning a bool short-circuits
	 * wp_mail(); returning null falls back to the default transport.
	 *
	 * @param mixed $return Unused (always null coming in).
	 * @param array $atts   wp_mail() call arguments (to, subject, message, headers, attachments).
	 * @return bool|null
	 */
	public function send_via_brevo( $return, array $atts ) {
		$api_key = Security::get_secret( self::SECRET_BREVO );
		if ( '' === $api_key ) {
			return null;
		}

		$to = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
		$to = array_values(
			array_filter(
				array_map(
					static function ( $address ) {
						$address = trim( (string) $address );
						return is_email( $address ) ? array( 'email' => $address ) : null;
					},
					$to
				)
			)
		);
		if ( empty( $to ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/smtp/email',
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'sender'      => array(
							'name'  => get_bloginfo( 'name' ),
							'email' => (string) get_option( 'admin_email' ),
						),
						'to'          => $to,
						'subject'     => (string) $atts['subject'],
						'htmlContent' => wpautop( (string) $atts['message'] ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Log a successful send (hooked on wp_mail_succeeded, WP 5.9+).
	 *
	 * @param array $mail_data Same shape as the wp_mail() arguments.
	 */
	public function log_success( array $mail_data ): void {
		$this->insert_log( $mail_data['to'] ?? '', $mail_data['subject'] ?? '', 'success', '' );
	}

	/**
	 * Log a failed send (hooked on wp_mail_failed).
	 *
	 * @param WP_Error $error Error carrying the original mail_data.
	 */
	public function log_failure( WP_Error $error ): void {
		$data = $error->get_error_data();
		$to      = is_array( $data ) ? ( $data['to'] ?? '' ) : '';
		$subject = is_array( $data ) ? ( $data['subject'] ?? '' ) : '';
		$this->insert_log( $to, $subject, 'error', $error->get_error_message() );
	}

	/**
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $status  success|error.
	 * @param string          $error   Error message, if any.
	 */
	private function insert_log( $to, string $subject, string $status, string $error ): void {
		global $wpdb;
		$to_string = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_smtp_logs",
			array(
				'created_at' => current_time( 'mysql' ),
				'to_email'   => mb_substr( $to_string, 0, 255 ),
				'subject'    => mb_substr( $subject, 0, 255 ),
				'status'     => $status,
				'error'      => '' === $error ? null : $error,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Last 50 log rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, created_at, to_email, subject, status, error FROM {$wpdb->prefix}uxstudio_smtp_logs ORDER BY id DESC LIMIT 50",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Send a test email through the currently configured transport.
	 *
	 * @param string $to Recipient; defaults to the site admin email.
	 * @return array{success:bool,to:string,error:string}
	 */
	public function send_test( string $to ): array {
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$error_message = '';
		$capture       = static function ( WP_Error $error ) use ( &$error_message ): void {
			$error_message = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture, 1 );

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] SMTP test email', 'ux-studio' ),
			get_bloginfo( 'name' )
		);
		$body = __( 'This is a test email sent from the UX Studio SMTP Email module.', 'ux-studio' );

		$success = wp_mail( $to, $subject, $body );

		remove_action( 'wp_mail_failed', $capture, 1 );

		return array(
			'success' => (bool) $success,
			'to'      => $to,
			'error'   => $error_message,
		);
	}
}
