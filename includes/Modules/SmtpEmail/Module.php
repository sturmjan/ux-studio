<?php
/**
 * SMTP Email module - route wp_mail() through SMTP, the Brevo API or the Gmail API.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\Security;
use UxStudio\Modules\BaseModule;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported/redesigned from the legacy smtp-email module as a group-C module with
 * its own SPA screen. Three transports are supported:
 *   - smtp  : PHPMailer over SMTP (phpmailer_init).
 *   - brevo : Brevo transactional email API (pre_wp_mail).
 *   - gmail : Gmail API via the site owner's own Google OAuth app (pre_wp_mail).
 *
 * On top of transport selection the module can override the From address/name
 * for all outgoing mail (from_email/from_name + force_from_* toggles) and resend
 * the last message that went through wp_mail().
 *
 * Secrets (SMTP password, Brevo API key, Gmail client secret + refresh token) are
 * never stored in the plain uxstudio_smtp_email settings option; they go through
 * Security::store_secret() and are never echoed back via REST (has_* flags only).
 */
final class Module extends BaseModule {

	private const SECRET_PASSWORD      = 'uxstudio_secret_smtp_password';
	private const SECRET_BREVO         = 'uxstudio_secret_smtp_brevo_api_key';
	private const SECRET_GMAIL_SECRET  = 'uxstudio_secret_smtp_gmail_client_secret';
	private const SECRET_GMAIL_REFRESH = 'uxstudio_secret_smtp_gmail_refresh_token';

	private const OAUTH_OPTION        = 'uxstudio_smtp_gmail_oauth';
	private const LAST_MESSAGE_OPTION = 'uxstudio_smtp_last_message';

	/** Admin page slug hosting the SPA (also the OAuth redirect target). */
	private const ADMIN_PAGE = 'ux-studio';

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
		} elseif ( 'gmail' === $mailer ) {
			add_filter( 'pre_wp_mail', array( $this, 'send_via_gmail' ), 10, 2 );
		} else {
			add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ) );
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
					'gmail' => __( 'Gmail API (OAuth)', 'ux-studio' ),
				),
				'default' => 'smtp',
			),
			array(
				'key'     => 'from_email',
				'type'    => 'text',
				'label'   => __( 'From email', 'ux-studio' ),
				'help'    => __( 'Sender address used for outgoing mail. Leave blank to keep WordPress defaults.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'force_from_email',
				'type'    => 'toggle',
				'label'   => __( 'Force From email', 'ux-studio' ),
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
	}

	/**
	 * Intercept the secret fields before they reach the plain settings option;
	 * everything else goes through the normal schema-based save.
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

		if ( array_key_exists( 'gmail_client_secret', $input ) && '' !== (string) $input['gmail_client_secret'] ) {
			Security::store_secret( self::SECRET_GMAIL_SECRET, (string) $input['gmail_client_secret'] );
		}
		unset( $input['gmail_client_secret'] );

		return parent::save_settings( $input );
	}

	/**
	 * Never leak secrets back to the client; expose only whether they're set,
	 * plus the Gmail OAuth connection state and connect/disconnect links.
	 */
	public function settings_values(): array {
		$values                          = parent::settings_values();
		$values['password']              = '';
		$values['brevo_api_key']         = '';
		$values['gmail_client_secret']   = '';
		$values['has_password']          = '' !== Security::get_secret( self::SECRET_PASSWORD );
		$values['has_brevo_api_key']     = '' !== Security::get_secret( self::SECRET_BREVO );
		$values['has_gmail_client_secret'] = '' !== Security::get_secret( self::SECRET_GMAIL_SECRET );

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

		$last                      = (array) get_option( self::LAST_MESSAGE_OPTION, array() );
		$values['has_last_message'] = ! empty( $last['to'] ) || ! empty( $last['subject'] );

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
			$this->insert_log( $atts['to'] ?? '', (string) ( $atts['subject'] ?? '' ), 'error', 'No valid recipient.' );
			return false;
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
			$this->insert_log( $atts['to'] ?? '', (string) ( $atts['subject'] ?? '' ), 'error', $response->get_error_message() );
			return false;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$success = $code >= 200 && $code < 300;
		$this->insert_log(
			$atts['to'] ?? '',
			(string) ( $atts['subject'] ?? '' ),
			$success ? 'success' : 'error',
			$success ? '' : 'Brevo HTTP ' . $code
		);
		return $success;
	}

	/**
	 * Send via the Gmail API (mailer = gmail). Hooked on pre_wp_mail.
	 *
	 * @param mixed $return Unused (always null coming in).
	 * @param array $atts   wp_mail() call arguments.
	 * @return bool|null
	 */
	public function send_via_gmail( $return, array $atts ) {
		$token = $this->gmail_access_token();
		$email = $this->gmail_connected_email();
		if ( '' === $token || '' === $email ) {
			$this->insert_log(
				$atts['to'] ?? '',
				(string) ( $atts['subject'] ?? '' ),
				'error',
				'Gmail API: no connected account or token refresh failed.'
			);
			return false;
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

		$result  = GmailClient::send_raw( $token, $raw );
		$success = ! empty( $result['ok'] );

		$this->insert_log(
			$atts['to'] ?? '',
			(string) ( $atts['subject'] ?? '' ),
			$success ? 'success' : 'error',
			$success ? '' : (string) ( $result['error'] ?? 'Gmail API send failed.' )
		);

		return $success;
	}

	/**
	 * Remember the last outgoing message so the UI can resend it.
	 *
	 * @param array $atts wp_mail() arguments.
	 * @return array Unmodified arguments.
	 */
	public function capture_last_message( array $atts ): array {
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

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] SMTP test email', 'ux-studio' ),
			get_bloginfo( 'name' )
		);
		$body = __( 'This is a test email sent from the UX Studio SMTP Email module.', 'ux-studio' );

		return $this->dispatch( $to, $subject, $body, '' );
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
