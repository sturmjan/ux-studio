<?php
/**
 * SMTP Email module - route wp_mail() through SMTP, an e-mail API (Brevo, SendGrid, Mailgun, Postmark, Amazon SES) or the Gmail API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Csv;
use UxStudio\Core\Retention;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy smtp-email module as a group-C module with
 * its own SPA screen. The transports:
 *   - smtp     : PHPMailer over SMTP (phpmailer_init).
 *   - brevo    : Brevo transactional email API (pre_wp_mail).
 *   - gmail    : Gmail API via the site owner's own Google OAuth app (pre_wp_mail).
 *   - sendgrid, mailgun (US / EU), postmark, ses : provider HTTPS APIs (pre_wp_mail, see Providers; SES is signed with SigV4 in PHP).
 *
 * An optional FALLBACK connection (setting `fallback_mailer`) takes over when the primary send fails; both attempts are
 * written to the delivery log (columns `mailer` and `attempt`). A primary that is not set up at all is skipped the same way.
 *
 * On top of transport selection the module can override the From address/name
 * for all outgoing mail (from_email/from_name + force_from_* toggles) and resend
 * the last message that went through wp_mail().
 *
 * Secrets (SMTP password, API keys, SES access / secret key, Gmail client secret + refresh token) are
 * never stored in the plain uxstudio_smtp_email settings option; they go through
 * Security::store_secret() and are never echoed back via REST (has_* flags only).
 */
final class Module extends BaseModule {

	private const SECRET_PASSWORD      = 'uxstudio_secret_smtp_password';
	private const SECRET_BREVO         = 'uxstudio_secret_smtp_brevo_api_key';
	private const SECRET_GMAIL_SECRET  = 'uxstudio_secret_smtp_gmail_client_secret';
	private const SECRET_GMAIL_REFRESH = 'uxstudio_secret_smtp_gmail_refresh_token';
	public const SECRET_SENDGRID       = 'uxstudio_secret_smtp_sendgrid_api_key';
	public const SECRET_MAILGUN        = 'uxstudio_secret_smtp_mailgun_api_key';
	public const SECRET_POSTMARK       = 'uxstudio_secret_smtp_postmark_server_token';
	public const SECRET_SES_ACCESS     = 'uxstudio_secret_smtp_ses_access_key';
	public const SECRET_SES_SECRET     = 'uxstudio_secret_smtp_ses_secret_key';

	/** Every transport a message can leave through. */
	public const MAILERS = array( 'smtp', 'brevo', 'gmail', 'sendgrid', 'mailgun', 'postmark', 'ses' );

	/**
	 * Set while the SMTP transport is tried through wp_mail() as the fallback (or a per-mailer test), so the pre_wp_mail
	 * handler lets that inner call through and phpmailer_init configures SMTP even when SMTP is not the primary.
	 */
	private bool $forced_smtp = false;

	/** @var array{mailer:string,attempt:string}|null Transport and attempt of the send in progress (for the log rows the wp_mail hooks write). */
	private ?array $attempt = null;

	private const BREVO_STATUS_OPTION = 'uxstudio_smtp_brevo_status';
	private const BREVO_ACCOUNT_URL   = 'https://api.brevo.com/v3/account';

	private const OAUTH_OPTION        = 'uxstudio_smtp_gmail_oauth';
	private const LAST_MESSAGE_OPTION = 'uxstudio_smtp_last_message';

	/** Admin page slug hosting the SPA (also the OAuth redirect target). */
	private const ADMIN_PAGE = 'ux-studio';

	/** Daily cron hook purging old smtp_logs rows. */
	public const CRON_RETENTION = 'uxstudio_smtp_logs_retention';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		\UxStudio\Core\DB::ensure_module_tables(
			'smtp-email',
			2,
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
						mailer VARCHAR(20) NOT NULL DEFAULT '',
						attempt VARCHAR(10) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		// SMTP goes through PHPMailer: as the primary always, as the fallback only while it is being tried (forced_smtp).
		if ( 'smtp' === $this->primary_mailer() || 'smtp' === $this->fallback_mailer() ) {
			add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
		}
		// Every other transport, and any fallback, takes over wp_mail() here. Plain SMTP without a fallback stays the WordPress default path.
		if ( 'smtp' !== $this->primary_mailer() || '' !== $this->fallback_mailer() ) {
			add_filter( 'pre_wp_mail', array( $this, 'handle_pre_wp_mail' ), 10, 2 );
		}

		// From / Force-From overrides apply regardless of transport (SMTP/default
		// path via these filters; Brevo/Gmail read the same settings internally).
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 999 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 999 );

		// Remember the last outgoing message so it can be resent from the UI.
		add_filter( 'wp_mail', array( $this, 'capture_last_message' ), 999 );

		// Gmail OAuth connect/callback/disconnect (full-page redirects, not REST).
		add_action( 'admin_init', array( $this, 'handle_gmail_oauth' ) );

		add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );

		add_action( self::CRON_RETENTION, array( $this, 'purge_old_logs' ) );
		Retention::ensure_scheduled( self::CRON_RETENTION );

		// Nonce-signed CSV download of the delivery log (link from the Log tab).
		add_action( 'admin_post_uxstudio_smtp_logs_export', array( $this, 'export_logs_csv' ) );
	}

	/**
	 * Remove the retention cron when the module is switched off.
	 */
	public function on_disable(): void {
		Retention::unschedule( self::CRON_RETENTION );
	}

	/**
	 * Daily retention purge of the delivery log.
	 */
	public function purge_old_logs(): void {
		Retention::purge( 'uxstudio_smtp_logs', (int) $this->settings->get( 'retention_days', Retention::DEFAULT_DAYS ) );
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
		$general = array(
			array(
				'key'     => 'mailer',
				'type'    => 'select',
				'label'   => __( 'Mailer', 'ux-studio' ),
				'help'    => __( 'How outgoing mail is sent. Set up the chosen service in its own tab.', 'ux-studio' ),
				'options' => self::mailer_labels(),
				'default' => 'smtp',
			),
			array(
				'key'     => 'fallback_mailer',
				'type'    => 'select',
				'label'   => __( 'Fallback connection', 'ux-studio' ),
				'help'    => __( 'Used when sending with the mailer above fails or is not set up. Both attempts are written to the log. Set it up in its own tab; choose a different service than the primary one.', 'ux-studio' ),
				'options' => array( 'none' => __( 'None', 'ux-studio' ) ) + self::mailer_labels(),
				'default' => 'none',
			),
			array(
				'key'     => 'from_email',
				'type'    => 'text',
				'label'   => __( 'From e-mail', 'ux-studio' ),
				'help'    => __( 'Sender address used for outgoing mail. Leave blank to keep WordPress defaults.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'force_from_email',
				'type'    => 'toggle',
				'label'   => __( 'Force From e-mail', 'ux-studio' ),
				'help'    => __( 'Override the From address on every outgoing message, even if a plugin sets its own.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'from_name',
				'type'    => 'text',
				'label'   => __( 'From name', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'force_from_name',
				'type'    => 'toggle',
				'label'   => __( 'Force From name', 'ux-studio' ),
				'help'    => __( 'Override the From name on every outgoing message.', 'ux-studio' ),
				'default' => false,
			),
		);

		$smtp = array(
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
		);

		$brevo = array(
			array(
				'key'     => 'brevo_api_key',
				'type'    => 'text',
				'label'   => __( 'Brevo API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
		);

		$gmail = array(
			array(
				'key'     => 'gmail_client_id',
				'type'    => 'text',
				'label'   => __( 'Gmail OAuth client ID', 'ux-studio' ),
				'help'    => __( 'From your Google Cloud OAuth client. Add the redirect URI shown below to that client.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'gmail_client_secret',
				'type'    => 'text',
				'label'   => __( 'Gmail OAuth client secret', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current secret.', 'ux-studio' ),
				'default' => '',
			),
		);

		$sendgrid = array(
			array(
				'key'     => 'sendgrid_api_key',
				'type'    => 'text',
				'label'   => __( 'SendGrid API key', 'ux-studio' ),
				'help'    => __( 'A key with the "Mail Send" permission. Stored encrypted. Leave blank to keep the current key. The From address must be a verified sender in SendGrid.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'sendgrid_region',
				'type'    => 'select',
				'label'   => __( 'SendGrid data region', 'ux-studio' ),
				'options' => array(
					'global' => __( 'Global (api.sendgrid.com)', 'ux-studio' ),
					'eu'     => __( 'EU (api.eu.sendgrid.com)', 'ux-studio' ),
				),
				'default' => 'global',
			),
		);

		$mailgun = array(
			array(
				'key'     => 'mailgun_api_key',
				'type'    => 'text',
				'label'   => __( 'Mailgun API key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'mailgun_domain',
				'type'    => 'text',
				'label'   => __( 'Mailgun sending domain', 'ux-studio' ),
				'help'    => __( 'The domain as set up in Mailgun, for example mg.example.com.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'mailgun_region',
				'type'    => 'select',
				'label'   => __( 'Mailgun region', 'ux-studio' ),
				'options' => array(
					'us' => __( 'US (api.mailgun.net)', 'ux-studio' ),
					'eu' => __( 'EU (api.eu.mailgun.net)', 'ux-studio' ),
				),
				'default' => 'us',
			),
		);

		$postmark = array(
			array(
				'key'     => 'postmark_server_token',
				'type'    => 'text',
				'label'   => __( 'Postmark server API token', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current token. The From address must be a confirmed sender signature or belong to a verified domain.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'postmark_message_stream',
				'type'    => 'text',
				'label'   => __( 'Postmark message stream', 'ux-studio' ),
				'help'    => __( 'Leave blank for the default transactional stream (outbound).', 'ux-studio' ),
				'default' => '',
			),
		);

		$ses = array(
			array(
				'key'     => 'ses_access_key',
				'type'    => 'text',
				'label'   => __( 'Amazon SES access key ID', 'ux-studio' ),
				'help'    => __( 'The IAM user needs the ses:SendEmail permission. Stored encrypted. Leave blank to keep the current key.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ses_secret_key',
				'type'    => 'text',
				'label'   => __( 'Amazon SES secret access key', 'ux-studio' ),
				'help'    => __( 'Stored encrypted. Leave blank to keep the current key. The From address or its domain must be a verified identity in the chosen region.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'ses_region',
				'type'    => 'select',
				'label'   => __( 'Amazon SES region', 'ux-studio' ),
				'options' => array_combine( Providers::SES_REGIONS, Providers::SES_REGIONS ),
				'default' => 'us-east-1',
			),
		);

		$log = array(
			array(
				'key'     => 'enable_email_logs',
				'type'    => 'toggle',
				'label'   => __( 'Turn on e-mail logs', 'ux-studio' ),
				'help'    => __( 'Record every e-mail sent from the site in the delivery log (for debugging and overview).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'retention_days',
				'type'    => 'number',
				'label'   => __( 'Delivery log retention (days)', 'ux-studio' ),
				'help'    => __( 'Log entries older than this are deleted daily. 0 = keep forever.', 'ux-studio' ),
				'default' => Retention::DEFAULT_DAYS,
			),
		);

		$sections = array(
			''                      => $general,
			__( 'SMTP', 'ux-studio' )       => $smtp,
			__( 'Brevo', 'ux-studio' )      => $brevo,
			__( 'Gmail', 'ux-studio' )      => $gmail,
			__( 'SendGrid', 'ux-studio' )   => $sendgrid,
			__( 'Mailgun', 'ux-studio' )    => $mailgun,
			__( 'Postmark', 'ux-studio' )   => $postmark,
			__( 'Amazon SES', 'ux-studio' ) => $ses,
			'__log'                 => $log,
		);

		$out = array();
		foreach ( $sections as $section => $fields ) {
			foreach ( $fields as $field ) {
				// The log settings are shown with the log (they carry no section), the rest in the tab of their service.
				if ( '' !== $section && '__log' !== $section ) {
					$field['section'] = $section;
				}
				$out[] = $field;
			}
		}
		return $out;
	}

	/**
	 * Names of the transports.
	 *
	 * @return array<string, string>
	 */
	public static function mailer_labels(): array {
		return array(
			'smtp'     => __( 'SMTP', 'ux-studio' ),
			'brevo'    => __( 'Brevo API', 'ux-studio' ),
			'gmail'    => __( 'Gmail API (OAuth)', 'ux-studio' ),
			'sendgrid' => __( 'SendGrid API', 'ux-studio' ),
			'mailgun'  => __( 'Mailgun API', 'ux-studio' ),
			'postmark' => __( 'Postmark API', 'ux-studio' ),
			'ses'      => __( 'Amazon SES API', 'ux-studio' ),
		);
	}

	/**
	 * Secret settings: setting key => option that holds the encrypted value. They never reach the plain settings option.
	 *
	 * @return array<string, string>
	 */
	public static function secret_fields(): array {
		return array(
			'password'              => self::SECRET_PASSWORD,
			'brevo_api_key'         => self::SECRET_BREVO,
			'gmail_client_secret'   => self::SECRET_GMAIL_SECRET,
			'sendgrid_api_key'      => self::SECRET_SENDGRID,
			'mailgun_api_key'       => self::SECRET_MAILGUN,
			'postmark_server_token' => self::SECRET_POSTMARK,
			'ses_access_key'        => self::SECRET_SES_ACCESS,
			'ses_secret_key'        => self::SECRET_SES_SECRET,
		);
	}

	/**
	 * Intercept the secret fields before they reach the plain settings option;
	 * everything else goes through the normal schema-based save.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		foreach ( self::secret_fields() as $key => $option ) {
			if ( array_key_exists( $key, $input ) ) {
				// A password may contain blanks at the ends; the keys and tokens of the services never do.
				$value = 'password' === $key ? (string) $input[ $key ] : trim( (string) $input[ $key ] );
				if ( '' !== $value ) {
					Security::store_secret( $option, $value );
				}
			}
			unset( $input[ $key ] );
		}

		// A fallback that is the same service as the primary one does nothing useful.
		$primary = array_key_exists( 'mailer', $input ) ? (string) $input['mailer'] : (string) $this->settings->get( 'mailer', 'smtp' );
		if ( isset( $input['fallback_mailer'] ) && (string) $input['fallback_mailer'] === $primary ) {
			$input['fallback_mailer'] = 'none';
		}

		$values = parent::save_settings( $input );
		// Hooks are chosen at boot from the settings; this request keeps what it booted with.
		return $values;
	}

	/**
	 * Never leak secrets back to the client; expose only whether they're set,
	 * plus the Gmail OAuth connection state and connect/disconnect links.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		foreach ( self::secret_fields() as $key => $option ) {
			$values[ $key ]          = '';
			$values[ 'has_' . $key ] = '' !== Security::get_secret( $option );
		}

		// Which transports are set up (a badge in each tab; the fallback list only makes sense with a set-up service).
		$ready = array();
		foreach ( self::MAILERS as $id ) {
			$ready[ $id ] = $this->is_configured( $id );
		}
		$values['mailers_ready'] = $ready;

		$oauth                       = $this->gmail_oauth_data();
		$values['gmail_connected']   = '' !== Security::get_secret( self::SECRET_GMAIL_REFRESH );
		$values['gmail_email']       = (string) ( $oauth['email'] ?? '' );
		$values['gmail_status']      = (string) ( $oauth['status'] ?? '' );
		$values['gmail_last_error']  = (string) ( $oauth['last_error'] ?? '' );
		$values['gmail_redirect_uri'] = $this->gmail_redirect_uri();
		$values['gmail_connect_url'] = wp_nonce_url(
			add_query_arg(
				array(
					'page'             => self::ADMIN_PAGE,
					'uxs_gmail_action' => 'connect',
				),
				admin_url( 'admin.php' )
			),
			'uxs_gmail_connect'
		);
		$values['gmail_disconnect_url'] = wp_nonce_url(
			add_query_arg(
				array(
					'page'             => self::ADMIN_PAGE,
					'uxs_gmail_action' => 'disconnect',
				),
				admin_url( 'admin.php' )
			),
			'uxs_gmail_disconnect'
		);

		// Result of the last "Verify Brevo key" check (never contains the key).
		$values['brevo_status'] = $this->brevo_status_data();

		$last                      = (array) get_option( self::LAST_MESSAGE_OPTION, array() );
		$values['has_last_message'] = ! empty( $last['to'] ) || ! empty( $last['subject'] );

		return $values;
	}

	/* --------------------------------------------------------------------- */
	/* Transports: which one, is it set up                                     */
	/* --------------------------------------------------------------------- */

	/**
	 * The primary transport (SMTP when the setting is missing or unknown).
	 */
	public function primary_mailer(): string {
		$mailer = (string) $this->settings->get( 'mailer', 'smtp' );
		return in_array( $mailer, self::MAILERS, true ) ? $mailer : 'smtp';
	}

	/**
	 * The fallback transport, '' for none (also when it would be the same service as the primary one).
	 */
	public function fallback_mailer(): string {
		$mailer = (string) $this->settings->get( 'fallback_mailer', 'none' );
		return in_array( $mailer, self::MAILERS, true ) && $mailer !== $this->primary_mailer() ? $mailer : '';
	}

	/**
	 * Whether a transport has everything it needs to try sending.
	 *
	 * @param string $id One of MAILERS.
	 */
	public function is_configured( string $id ): bool {
		switch ( $id ) {
			case 'smtp':
				return '' !== (string) $this->settings->get( 'host', '' );
			case 'brevo':
				return '' !== Security::get_secret( self::SECRET_BREVO );
			case 'gmail':
				return '' !== $this->gmail_connected_email();
			case 'sendgrid':
				return '' !== Security::get_secret( self::SECRET_SENDGRID );
			case 'mailgun':
				return '' !== Security::get_secret( self::SECRET_MAILGUN ) && '' !== trim( (string) $this->settings->get( 'mailgun_domain', '' ) );
			case 'postmark':
				return '' !== Security::get_secret( self::SECRET_POSTMARK );
			case 'ses':
				return '' !== Security::get_secret( self::SECRET_SES_ACCESS ) && '' !== Security::get_secret( self::SECRET_SES_SECRET );
		}
		return false;
	}

	/**
	 * Credentials and options of an API provider, in the shape Providers::request() wants.
	 *
	 * @param string $id sendgrid | mailgun | postmark | ses.
	 * @return array<string, mixed>
	 */
	private function provider_config( string $id ): array {
		switch ( $id ) {
			case Providers::SENDGRID:
				return array(
					'api_key' => Security::get_secret( self::SECRET_SENDGRID ),
					'region'  => (string) $this->settings->get( 'sendgrid_region', 'global' ),
				);
			case Providers::MAILGUN:
				return array(
					'api_key' => Security::get_secret( self::SECRET_MAILGUN ),
					'domain'  => (string) $this->settings->get( 'mailgun_domain', '' ),
					'region'  => (string) $this->settings->get( 'mailgun_region', 'us' ),
				);
			case Providers::POSTMARK:
				return array(
					'token'  => Security::get_secret( self::SECRET_POSTMARK ),
					'stream' => (string) $this->settings->get( 'postmark_message_stream', '' ),
				);
			case Providers::SES:
				return array(
					'access_key' => Security::get_secret( self::SECRET_SES_ACCESS ),
					'secret_key' => Security::get_secret( self::SECRET_SES_SECRET ),
					'region'     => (string) $this->settings->get( 'ses_region', 'us-east-1' ),
				);
		}
		return array();
	}

	/**
	 * Configure PHPMailer for SMTP transport. Always when SMTP is the primary transport; as the fallback (or a
	 * per-mailer test) only while that attempt runs.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 */
	public function configure_phpmailer( $phpmailer ): void {
		if ( 'smtp' !== $this->primary_mailer() && ! $this->forced_smtp ) {
			return;
		}

		$host = (string) $this->settings->get( 'host', '' );
		if ( '' === $host ) {
			return;
		}

		$encryption = (string) $this->settings->get( 'encryption', 'tls' );

		$phpmailer->isSMTP();
		$phpmailer->Host        = $host;
		$phpmailer->Port        = (int) $this->settings->get( 'port', 587 );
		$phpmailer->SMTPAuth    = true;
		$phpmailer->Username    = (string) $this->settings->get( 'username', '' );
		$phpmailer->Password    = Security::get_secret( self::SECRET_PASSWORD );
		$phpmailer->SMTPSecure  = 'none' === $encryption ? '' : $encryption;
		$phpmailer->SMTPAutoTLS = 'none' !== $encryption;
	}

	/**
	 * Filter the From email for all outgoing mail (hooked on wp_mail_from).
	 *
	 * @param string $from_email Incoming From address.
	 * @return string
	 */
	public function filter_from_email( $from_email ): string {
		$configured = (string) $this->settings->get( 'from_email', '' );
		$configured = '' !== $configured ? sanitize_email( $configured ) : '';
		if ( '' === $configured ) {
			return (string) $from_email;
		}

		if ( $this->settings->get( 'force_from_email', false ) ) {
			return $configured;
		}

		// Replace the WordPress default (wordpress@<host>) with the configured one.
		if ( is_string( $from_email ) && 0 === strpos( $from_email, 'wordpress@' ) ) {
			return $configured;
		}

		return (string) $from_email;
	}

	/**
	 * Filter the From name for all outgoing mail (hooked on wp_mail_from_name).
	 *
	 * @param string $from_name Incoming From name.
	 * @return string
	 */
	public function filter_from_name( $from_name ): string {
		$configured = (string) $this->settings->get( 'from_name', '' );
		if ( '' === $configured ) {
			return (string) $from_name;
		}

		if ( $this->settings->get( 'force_from_name', false ) ) {
			return $configured;
		}

		// WordPress default From name is 'WordPress'; replace it when configured.
		if ( '' === (string) $from_name || 'WordPress' === (string) $from_name ) {
			return $configured;
		}

		return (string) $from_name;
	}

	/**
	 * Hooked on pre_wp_mail: send with the primary transport and, when that fails or is not set up, with the fallback.
	 * Returning a bool short-circuits wp_mail(); returning null leaves the message to the WordPress default transport.
	 *
	 * @param mixed $return Whatever an earlier handler returned (null when nobody took the message).
	 * @param mixed $atts   wp_mail() call arguments (to, subject, message, headers, attachments).
	 * @return bool|null
	 */
	public function handle_pre_wp_mail( $return, $atts = array() ) {
		// Somebody else took the message, or this is the inner wp_mail() call of an SMTP attempt.
		if ( null !== $return || $this->forced_smtp || ! is_array( $atts ) ) {
			return $return;
		}

		$primary  = $this->primary_mailer();
		$fallback = $this->fallback_mailer();

		// Nothing set up and nothing to fall back to: the WordPress default transport, as it always was.
		// (Gmail without a connected account has always been reported as a failure instead.)
		if ( '' === $fallback && 'gmail' !== $primary && ! $this->is_configured( $primary ) ) {
			return null;
		}

		return $this->send_chain( $atts, $primary, $fallback )['ok'];
	}

	/**
	 * Try the primary transport, then the fallback. Every attempt is logged.
	 *
	 * @param array<string, mixed> $atts     wp_mail() arguments.
	 * @param string               $primary  Transport id.
	 * @param string               $fallback Transport id, '' for none.
	 * @return array{ok:bool,error:string,mailer:string} `mailer` = the transport that took the message.
	 */
	public function send_chain( array $atts, string $primary, string $fallback ): array {
		$error = '';
		foreach ( array_filter(
			array(
				'primary'  => $primary,
				'fallback' => $fallback,
			)
		) as $kind => $id ) {
			$result = $this->attempt( $id, $atts, $kind );
			if ( $result['ok'] ) {
				return array(
					'ok'     => true,
					'error'  => '',
					'mailer' => $id,
				);
			}
			$error = $result['error'];
		}
		return array(
			'ok'     => false,
			'error'  => $error,
			'mailer' => '',
		);
	}

	/**
	 * One attempt with one transport, written to the log.
	 *
	 * @param string               $id      Transport id.
	 * @param array<string, mixed> $atts    wp_mail() arguments.
	 * @param string               $kind    primary | fallback | test.
	 * @return array{ok:bool,error:string}
	 */
	private function attempt( string $id, array $atts, string $kind ): array {
		$to      = $atts['to'] ?? '';
		$subject = (string) ( $atts['subject'] ?? '' );

		if ( ! $this->is_configured( $id ) ) {
			$error = __( 'This connection is not set up.', 'ux-studio' );
			$this->insert_log( $to, $subject, 'error', $error, $id, $kind );
			return array(
				'ok'    => false,
				'error' => $error,
			);
		}

		if ( 'smtp' === $id ) {
			// The inner wp_mail() writes the log row itself (wp_mail_succeeded / wp_mail_failed).
			return $this->attempt_smtp( $atts, $kind );
		}

		switch ( $id ) {
			case 'brevo':
				$result = $this->send_brevo( $atts );
				break;
			case 'gmail':
				$result = $this->send_gmail( $atts );
				break;
			default:
				$result = $this->send_api( $id, $atts );
		}
		$this->insert_log( $to, $subject, $result['ok'] ? 'success' : 'error', $result['error'], $id, $kind );
		return $result;
	}

	/**
	 * Send through SMTP by running wp_mail() again with the SMTP transport forced. The `wp_mail` filter already ran for this
	 * message, so it is taken off for the inner call (a plugin that appends a footer would otherwise do it twice).
	 *
	 * @param array<string, mixed> $atts wp_mail() arguments.
	 * @param string               $kind primary | fallback | test.
	 * @return array{ok:bool,error:string}
	 */
	private function attempt_smtp( array $atts, string $kind ): array {
		global $wp_filter;

		$error   = '';
		$capture = static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture, 1 );

		$stashed = isset( $wp_filter['wp_mail'] ) ? $wp_filter['wp_mail'] : null;
		unset( $wp_filter['wp_mail'] );
		$this->forced_smtp = true;
		$this->attempt     = array(
			'mailer'  => 'smtp',
			'attempt' => $kind,
		);

		try {
			$ok = wp_mail(
				$atts['to'] ?? '',
				(string) ( $atts['subject'] ?? '' ),
				(string) ( $atts['message'] ?? '' ),
				$atts['headers'] ?? '',
				$atts['attachments'] ?? array()
			);
		} finally {
			$this->forced_smtp = false;
			$this->attempt     = null;
			if ( null !== $stashed ) {
				$wp_filter['wp_mail'] = $stashed;
			}
			remove_action( 'wp_mail_failed', $capture, 1 );
		}

		return array(
			'ok'    => (bool) $ok,
			'error' => (bool) $ok ? '' : ( '' !== $error ? $error : __( 'The SMTP server did not accept the message.', 'ux-studio' ) ),
		);
	}

	/**
	 * Send through SendGrid, Mailgun, Postmark or Amazon SES.
	 *
	 * @param string               $id   Provider id.
	 * @param array<string, mixed> $atts wp_mail() arguments.
	 * @return array{ok:bool,error:string}
	 */
	private function send_api( string $id, array $atts ): array {
		$message = MailMessage::from_atts( $atts );
		if ( null === $message ) {
			return array(
				'ok'    => false,
				'error' => __( 'No valid recipient.', 'ux-studio' ),
			);
		}
		$result = Providers::send( $id, $message, $this->provider_config( $id ) );
		return array(
			'ok'    => $result['ok'],
			'error' => $result['error'],
		);
	}

	/**
	 * Send via the Brevo transactional email API.
	 *
	 * @param array<string, mixed> $atts wp_mail() call arguments (to, subject, message, headers, attachments).
	 * @return array{ok:bool,error:string}
	 */
	private function send_brevo( array $atts ): array {
		$api_key = Security::get_secret( self::SECRET_BREVO );

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
			return array(
				'ok'    => false,
				'error' => __( 'No valid recipient.', 'ux-studio' ),
			);
		}

		$from_email = $this->resolve_from_email();
		$sender     = array(
			'name'  => $this->resolve_from_name( get_bloginfo( 'name' ) ),
			'email' => '' !== $from_email ? $from_email : (string) get_option( 'admin_email' ),
		);

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
						'sender'      => $sender,
						'to'          => $to,
						'subject'     => (string) $atts['subject'],
						'htmlContent' => wpautop( (string) $atts['message'] ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$success = $code >= 200 && $code < 300;
		return array(
			'ok'    => $success,
			'error' => $success ? '' : 'Brevo HTTP ' . $code,
		);
	}

	/**
	 * Send via the Gmail API.
	 *
	 * @param array<string, mixed> $atts wp_mail() call arguments.
	 * @return array{ok:bool,error:string}
	 */
	private function send_gmail( array $atts ): array {
		$token = $this->gmail_access_token();
		$email = $this->gmail_connected_email();
		if ( '' === $token || '' === $email ) {
			return array(
				'ok'    => false,
				'error' => __( 'Gmail API: no connected account or token refresh failed.', 'ux-studio' ),
			);
		}

		$to = is_array( $atts['to'] ) ? array_values( $atts['to'] ) : array( (string) $atts['to'] );

		$parsed       = $this->parse_headers( $atts['headers'] ?? '' );
		$content_type = '' !== $parsed['content_type'] ? $parsed['content_type'] : 'text/html';
		$from_name    = $this->resolve_from_name( $parsed['from_name'] );

		$opts = array();
		if ( '' !== $parsed['reply_to'] ) {
			$opts['reply_to'] = $parsed['reply_to'];
		}
		if ( ! empty( $parsed['cc'] ) ) {
			$opts['cc'] = $parsed['cc'];
		}
		if ( ! empty( $parsed['bcc'] ) ) {
			$opts['bcc'] = $parsed['bcc'];
		}

		$attachments = isset( $atts['attachments'] ) && is_array( $atts['attachments'] )
			? array_values( $atts['attachments'] )
			: array();

		$raw = GmailClient::build_raw_message(
			$email,
			$from_name,
			$to,
			(string) $atts['subject'],
			(string) $atts['message'],
			$content_type,
			$opts,
			$attachments
		);

		$result = GmailClient::send_raw( $token, $raw );
		$ok     = ! empty( $result['ok'] );
		return array(
			'ok'    => $ok,
			'error' => $ok ? '' : (string) ( $result['error'] ?? 'Gmail API send failed.' ),
		);
	}

	/**
	 * Remember the last outgoing message so the UI can resend it.
	 *
	 * The `wp_mail` filter is NOT guaranteed to carry an array. Plugins that
	 * short-circuit mail (Disable Emails passes `false`) hand the filter their
	 * own value, and a typed `array` parameter turns that into a fatal error
	 * that takes down whatever was sending the mail. Found 10.9.2026: filing a
	 * service request on a site with Disable Emails active crashed the request.
	 *
	 * @param mixed $atts wp_mail() arguments, or whatever another plugin passed.
	 * @return mixed Unmodified arguments.
	 */
	public function capture_last_message( $atts ) {
		if ( ! is_array( $atts ) ) {
			return $atts;
		}

		update_option(
			self::LAST_MESSAGE_OPTION,
			array(
				'to'      => is_array( $atts['to'] ?? '' ) ? array_values( $atts['to'] ) : (string) ( $atts['to'] ?? '' ),
				'subject' => (string) ( $atts['subject'] ?? '' ),
				'message' => (string) ( $atts['message'] ?? '' ),
				'headers' => $atts['headers'] ?? '',
			),
			false
		);
		return $atts;
	}

	/**
	 * Resolve the From email honouring the configured override, else admin email.
	 */
	private function resolve_from_email(): string {
		$configured = (string) $this->settings->get( 'from_email', '' );
		return '' !== $configured ? sanitize_email( $configured ) : '';
	}

	/**
	 * Resolve the From name: forced setting > provided name > configured fallback.
	 *
	 * @param string $provided Name coming from the message headers, if any.
	 */
	private function resolve_from_name( string $provided ): string {
		$configured = (string) $this->settings->get( 'from_name', '' );
		if ( $this->settings->get( 'force_from_name', false ) && '' !== $configured ) {
			return $configured;
		}
		if ( '' !== $provided ) {
			return $provided;
		}
		return $configured;
	}

	/**
	 * Minimal RFC 2822 header parser for the Gmail transport.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array{content_type:string,from_name:string,reply_to:string,cc:string[],bcc:string[]}
	 */
	private function parse_headers( $headers ): array {
		$out = array(
			'content_type' => '',
			'from_name'    => '',
			'reply_to'     => '',
			'cc'           => array(),
			'bcc'          => array(),
		);

		if ( is_string( $headers ) ) {
			$headers = '' === trim( $headers ) ? array() : preg_split( "/\r\n|\n|\r/", $headers );
		}
		if ( ! is_array( $headers ) ) {
			return $out;
		}

		foreach ( $headers as $header ) {
			$header = (string) $header;
			if ( false === strpos( $header, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $header, 2 );
			$name                 = strtolower( trim( $name ) );
			$value                = trim( $value );

			switch ( $name ) {
				case 'content-type':
					if ( preg_match( '/^([^;]+)/', $value, $m ) ) {
						$out['content_type'] = trim( $m[1] );
					}
					break;
				case 'from':
					if ( preg_match( '/^(.*)<([^>]+)>/', $value, $m ) ) {
						$out['from_name'] = trim( $m[1], " \"'" );
					}
					break;
				case 'reply-to':
					$out['reply_to'] = $value;
					break;
				case 'cc':
					$out['cc'] = array_filter( array_map( 'trim', explode( ',', $value ) ) );
					break;
				case 'bcc':
					$out['bcc'] = array_filter( array_map( 'trim', explode( ',', $value ) ) );
					break;
			}
		}

		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Gmail OAuth flow                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Redirect URI registered in the Google Cloud OAuth client.
	 */
	public function gmail_redirect_uri(): string {
		return admin_url( 'admin.php?page=' . self::ADMIN_PAGE );
	}

	/**
	 * OAuth state option (email/name/status/expires/access_token/last_error).
	 *
	 * @return array<string, mixed>
	 */
	private function gmail_oauth_data(): array {
		return (array) get_option( self::OAUTH_OPTION, array() );
	}

	/**
	 * Connected Gmail account address, or empty string.
	 */
	private function gmail_connected_email(): string {
		if ( '' === Security::get_secret( self::SECRET_GMAIL_REFRESH ) ) {
			return '';
		}
		$oauth = $this->gmail_oauth_data();
		return (string) ( $oauth['email'] ?? '' );
	}

	/**
	 * Return a valid access token (refreshing when expired), or empty string.
	 */
	private function gmail_access_token(): string {
		$refresh = Security::get_secret( self::SECRET_GMAIL_REFRESH );
		if ( '' === $refresh ) {
			return '';
		}

		$oauth = $this->gmail_oauth_data();
		if ( ! empty( $oauth['access_token'] ) && (int) ( $oauth['expires'] ?? 0 ) > time() + 60 ) {
			return (string) $oauth['access_token'];
		}

		$client_id     = (string) $this->settings->get( 'gmail_client_id', '' );
		$client_secret = Security::get_secret( self::SECRET_GMAIL_SECRET );
		if ( '' === $client_id || '' === $client_secret ) {
			return '';
		}

		$resp = GmailClient::refresh_access_token( $refresh, $client_id, $client_secret );
		if ( ! $resp || empty( $resp['access_token'] ) ) {
			$oauth['status']     = 'error';
			$oauth['last_error'] = __( 'Access token refresh failed (refresh token may have been revoked).', 'ux-studio' );
			update_option( self::OAUTH_OPTION, $oauth, false );
			return '';
		}

		$oauth['access_token'] = (string) $resp['access_token'];
		$oauth['expires']      = time() + (int) ( $resp['expires_in'] ?? 3600 ) - 30;
		$oauth['status']       = 'connected';
		$oauth['last_error']   = '';
		update_option( self::OAUTH_OPTION, $oauth, false );

		return (string) $resp['access_token'];
	}

	/**
	 * Handle Gmail OAuth connect / disconnect / callback on the SPA admin page.
	 */
	public function handle_gmail_oauth(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::ADMIN_PAGE !== ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) {
			return;
		}

		$action = isset( $_GET['uxs_gmail_action'] ) ? sanitize_key( wp_unslash( $_GET['uxs_gmail_action'] ) ) : '';

		if ( 'connect' === $action ) {
			check_admin_referer( 'uxs_gmail_connect' );
			$this->gmail_start_connect();
			return;
		}

		if ( 'disconnect' === $action ) {
			check_admin_referer( 'uxs_gmail_disconnect' );
			$this->gmail_disconnect();
			return;
		}

		if ( '' === $action && isset( $_GET['code'], $_GET['state'] ) ) {
			$this->gmail_handle_callback();
		}
	}

	/**
	 * Build the authorize URL and redirect to Google.
	 */
	private function gmail_start_connect(): void {
		$client_id     = (string) $this->settings->get( 'gmail_client_id', '' );
		$client_secret = Security::get_secret( self::SECRET_GMAIL_SECRET );

		if ( '' === $client_id || '' === $client_secret ) {
			$this->gmail_set_status( 'error', __( 'Enter and save the Gmail client ID and secret first.', 'ux-studio' ) );
			wp_safe_redirect( $this->gmail_redirect_uri() );
			exit;
		}

		$state = wp_generate_password( 24, false );
		set_transient( 'uxs_smtp_gmail_state_' . $state, 1, 600 );

		wp_redirect( GmailClient::build_auth_url( $client_id, $this->gmail_redirect_uri(), $state ) );
		exit;
	}

	/**
	 * Handle the return from Google: verify state, exchange code, store account.
	 */
	private function gmail_handle_callback(): void {
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( '' !== $error ) {
			$this->gmail_set_status( 'error', sprintf( /* translators: %s: Google error code */ __( 'Google returned an error: %s', 'ux-studio' ), $error ) );
			wp_safe_redirect( $this->gmail_redirect_uri() );
			exit;
		}

		$state_key = 'uxs_smtp_gmail_state_' . $state;
		if ( '' === $state || ! get_transient( $state_key ) ) {
			$this->gmail_set_status( 'error', __( 'Invalid or expired OAuth state. Try connecting again.', 'ux-studio' ) );
			wp_safe_redirect( $this->gmail_redirect_uri() );
			exit;
		}
		delete_transient( $state_key );

		$client_id     = (string) $this->settings->get( 'gmail_client_id', '' );
		$client_secret = Security::get_secret( self::SECRET_GMAIL_SECRET );

		$resp = GmailClient::exchange_code( $code, $client_id, $client_secret, $this->gmail_redirect_uri() );
		if ( ! $resp || empty( $resp['access_token'] ) ) {
			$this->gmail_set_status( 'error', __( 'Code-for-token exchange failed. Check the client secret and redirect URI in Google Cloud.', 'ux-studio' ) );
			wp_safe_redirect( $this->gmail_redirect_uri() );
			exit;
		}

		if ( empty( $resp['refresh_token'] ) ) {
			$this->gmail_set_status( 'error', __( 'Google did not send a refresh token. Revoke this app at myaccount.google.com/permissions and connect again.', 'ux-studio' ) );
			wp_safe_redirect( $this->gmail_redirect_uri() );
			exit;
		}

		$identity = ! empty( $resp['id_token'] )
			? GmailClient::identity_from_id_token( (string) $resp['id_token'] )
			: array(
				'email' => '',
				'name'  => '',
			);

		Security::store_secret( self::SECRET_GMAIL_REFRESH, (string) $resp['refresh_token'] );

		update_option(
			self::OAUTH_OPTION,
			array(
				'email'        => $identity['email'],
				'name'         => $identity['name'],
				'access_token' => (string) $resp['access_token'],
				'expires'      => time() + (int) ( $resp['expires_in'] ?? 3600 ) - 30,
				'status'       => 'connected',
				'last_error'   => '',
			),
			false
		);

		ActivityLog::log( 'smtp-email', 'gmail_connect', 'account', 0, array( 'email' => $identity['email'] ) );

		$this->gmail_set_status(
			'success',
			sprintf(
				/* translators: %s: Google account email */
				__( 'Account %s connected.', 'ux-studio' ),
				'' !== $identity['email'] ? $identity['email'] : '(unknown)'
			)
		);
		wp_safe_redirect( $this->gmail_redirect_uri() );
		exit;
	}

	/**
	 * Disconnect the Gmail account (clear tokens).
	 */
	private function gmail_disconnect(): void {
		Security::store_secret( self::SECRET_GMAIL_REFRESH, '' );
		delete_option( self::OAUTH_OPTION );
		ActivityLog::log( 'smtp-email', 'gmail_disconnect', 'account', 0 );
		$this->gmail_set_status( 'success', __( 'Account disconnected.', 'ux-studio' ) );
		wp_safe_redirect( $this->gmail_redirect_uri() );
		exit;
	}

	/**
	 * Persist a short-lived connection status shown in the SPA after a redirect.
	 *
	 * @param string $type    success|error.
	 * @param string $message Human message.
	 */
	private function gmail_set_status( string $type, string $message ): void {
		$oauth               = $this->gmail_oauth_data();
		$oauth['last_error'] = 'error' === $type ? $message : '';
		if ( 'error' === $type ) {
			$oauth['status'] = 'error';
		}
		update_option( self::OAUTH_OPTION, $oauth, false );
	}

	/* --------------------------------------------------------------------- */
	/* Logging + tests                                                         */
	/* --------------------------------------------------------------------- */

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
		$data    = $error->get_error_data();
		$to      = is_array( $data ) ? ( $data['to'] ?? '' ) : '';
		$subject = is_array( $data ) ? ( $data['subject'] ?? '' ) : '';
		$this->insert_log( $to, $subject, 'error', $error->get_error_message() );
	}

	/**
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $status  success|error.
	 * @param string          $error   Error message, if any.
	 * @param string          $mailer  Transport id ('' = take it from the attempt in progress).
	 * @param string          $attempt primary | fallback | test.
	 */
	private function insert_log( $to, string $subject, string $status, string $error, string $mailer = '', string $attempt = '' ): void {
		if ( ! $this->settings->get( 'enable_email_logs', true ) ) {
			return;
		}

		// The wp_mail hooks do not know the transport: take it from the attempt in progress, else from the setting
		// (a primary that is not set up was handed to the WordPress default transport, written as `wp`).
		if ( '' === $mailer ) {
			$mailer  = (string) ( $this->attempt['mailer'] ?? ( 'smtp' === $this->primary_mailer() ? 'smtp' : 'wp' ) );
			$attempt = (string) ( $this->attempt['attempt'] ?? 'primary' );
		}

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
				'mailer'     => mb_substr( $mailer, 0, 20 ),
				'attempt'    => mb_substr( $attempt, 0, 10 ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Paginated / searchable delivery log, newest first.
	 *
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Rows per page (1-100).
	 * @param string $search   Optional substring matched against recipient, subject and error.
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public function get_logs( int $page = 1, int $per_page = 20, string $search = '' ): array {
		global $wpdb;
		$table    = "{$wpdb->prefix}uxstudio_smtp_logs";
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where = '1=1';
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (to_email LIKE %s OR subject LIKE %s OR error LIKE %s)', $like, $like, $like );
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, to_email, subject, status, error, mailer, attempt FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return array(
			'items'    => is_array( $rows ) ? $rows : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Delete every delivery log row.
	 */
	public function clear_logs(): bool {
		global $wpdb;
		$result = $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}uxstudio_smtp_logs" );
		ActivityLog::log( 'smtp-email', 'clear_logs', 'smtp_logs', 0 );
		return false !== $result;
	}

	/**
	 * Nonce-signed URL of the CSV export.
	 */
	public function export_logs_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=uxstudio_smtp_logs_export' ),
			'uxstudio_smtp_logs_export'
		);
	}

	/**
	 * Stream the whole delivery log as CSV (admin-post handler).
	 */
	public function export_logs_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ux-studio' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'uxstudio_smtp_logs_export' );

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT created_at, to_email, subject, status, error, mailer, attempt FROM {$wpdb->prefix}uxstudio_smtp_logs ORDER BY id DESC",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		ActivityLog::log( 'smtp-email', 'export_logs', 'smtp_logs', 0, array( 'rows' => count( $rows ) ) );

		$filename = sanitize_file_name( get_bloginfo( 'name' ) . '-smtp-email-logs-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'To', 'Subject', 'Status', 'Error', 'Mailer', 'Attempt' ) );
		foreach ( $rows as $row ) {
			// Subject / recipient / error originate from arbitrary mail callers.
			fputcsv(
				$out,
				Csv::safe_row(
					array(
						(string) $row['created_at'],
						(string) $row['to_email'],
						(string) $row['subject'],
						(string) $row['status'],
						(string) ( $row['error'] ?? '' ),
						(string) ( $row['mailer'] ?? '' ),
						(string) ( $row['attempt'] ?? '' ),
					)
				)
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Last stored result of the Brevo key check.
	 *
	 * @return array{state:string,account:string,error:string,checked:string}
	 */
	private function brevo_status_data(): array {
		$data = (array) get_option( self::BREVO_STATUS_OPTION, array() );
		return array(
			'state'   => (string) ( $data['state'] ?? '' ),
			'account' => (string) ( $data['account'] ?? '' ),
			'error'   => (string) ( $data['error'] ?? '' ),
			'checked' => (string) ( $data['checked'] ?? '' ),
		);
	}

	/**
	 * Verify the stored Brevo API key against GET /v3/account and remember the
	 * outcome. The key is read from the encrypted secret and never returned.
	 *
	 * @return array{state:string,account:string,error:string,checked:string}
	 */
	public function verify_brevo_key(): array {
		$api_key = Security::get_secret( self::SECRET_BREVO );
		$result  = array(
			'state'   => 'error',
			'account' => '',
			'error'   => '',
			'checked' => current_time( 'mysql' ),
		);

		if ( '' === $api_key ) {
			$result['error'] = __( 'Enter the API key and save the settings first.', 'ux-studio' );
		} else {
			$response = wp_remote_get(
				self::BREVO_ACCOUNT_URL,
				array(
					'timeout' => 15,
					'headers' => array(
						'api-key' => $api_key,
						'accept'  => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$result['error'] = $response->get_error_message();
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( 200 === $code && is_array( $data ) ) {
					$result['state']   = 'ok';
					$account           = (string) ( $data['email'] ?? '' );
					$result['account'] = '' !== $account ? $account : (string) ( $data['companyName'] ?? '' );
				} else {
					$result['error'] = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . $code;
				}
			}
		}

		update_option( self::BREVO_STATUS_OPTION, $result, false );
		return $result;
	}

	/**
	 * Send a test email. Without `$mailer` through the transports as they are set up (primary, then fallback); with
	 * `$mailer` through exactly that transport (saved settings), no fallback.
	 *
	 * @param string $to     Recipient; defaults to the site admin email.
	 * @param string $mailer One of MAILERS, or '' for the normal way.
	 * @return array{success:bool,to:string,error:string,mailer:string}
	 */
	public function send_test( string $to, string $mailer = '' ): array {
		if ( '' === $to || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] SMTP test e-mail', 'ux-studio' ),
			get_bloginfo( 'name' )
		);
		$body = __( 'This is a test e-mail sent from the UX Studio SMTP E-mail module.', 'ux-studio' );

		if ( '' === $mailer ) {
			return $this->dispatch( $to, $subject, $body, '' ) + array( 'mailer' => '' );
		}
		if ( ! in_array( $mailer, self::MAILERS, true ) ) {
			return array(
				'success' => false,
				'to'      => $to,
				'error'   => __( 'Unknown mailer.', 'ux-studio' ),
				'mailer'  => $mailer,
			);
		}

		$result = $this->attempt(
			$mailer,
			array(
				'to'          => $to,
				'subject'     => $subject,
				'message'     => $body,
				'headers'     => '',
				'attachments' => array(),
			),
			'test'
		);
		return array(
			'success' => $result['ok'],
			'to'      => $to,
			'error'   => $result['error'],
			'mailer'  => $mailer,
		);
	}

	/**
	 * Resend the last captured outgoing message.
	 *
	 * @return array{success:bool,to:string,error:string}
	 */
	public function resend_last(): array {
		$last = (array) get_option( self::LAST_MESSAGE_OPTION, array() );
		if ( empty( $last['to'] ) && empty( $last['subject'] ) ) {
			return array(
				'success' => false,
				'to'      => '',
				'error'   => __( 'No previous message to resend.', 'ux-studio' ),
			);
		}

		$to = $last['to'] ?? '';
		return $this->dispatch(
			$to,
			(string) ( $last['subject'] ?? '' ),
			(string) ( $last['message'] ?? '' ),
			$last['headers'] ?? ''
		);
	}

	/**
	 * Send one message and report the outcome, capturing any wp_mail_failed error.
	 *
	 * @param string|string[] $to      Recipient(s).
	 * @param string          $subject Subject.
	 * @param string          $body    Message body.
	 * @param string|string[] $headers Headers.
	 * @return array{success:bool,to:string,error:string}
	 */
	private function dispatch( $to, string $subject, string $body, $headers ): array {
		$error_message = '';
		$capture       = static function ( WP_Error $error ) use ( &$error_message ): void {
			$error_message = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture, 1 );

		$success = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $capture, 1 );

		return array(
			'success' => (bool) $success,
			'to'      => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
			'error'   => $error_message,
		);
	}
}
