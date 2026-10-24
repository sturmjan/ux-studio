<?php
/**
 * Source: WP Mail SMTP, Easy WP SMTP, Post SMTP, FluentSMTP.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;
use UxStudio\Modules\SmtpEmail\Module as SmtpModule;
use UxStudio\Modules\SmtpEmail\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `smtp-email`: mailer (smtp | brevo | gmail | sendgrid | mailgun | postmark | ses), the fallback
 * connection, sender, host / port / encryption / user and the encrypted secrets (SMTP password, API keys, SES keys,
 * Gmail client secret) through Security::store_secret.
 *
 * BEHAVIOUR-CHANGING (it changes how every e-mail leaves the site): with the module on, the values and the
 * encrypted secrets wait in the staging area.
 *
 * Reading, each plugin with its own scheme:
 *  - WP Mail SMTP (`wp_mail_smtp`) and Easy WP SMTP (`easy_wp_smtp`, a fork): groups `mail`, `smtp`, `sendinblue`,
 *    `gmail`, `sendgrid`, `mailgun`, `postmark`, `amazonses`; the SMTP password is a libsodium secretbox (key in option
 *    `<option>_mail_key` or constant `WPMS_CRYPTO_KEY`), API keys are stored as they are (a blob is decrypted anyway).
 *    Settings fixed by constants in wp-config.php (`WPMS_ON` / `EASY_WP_SMTP_ON` plus `WPMS_SMTP_HOST` ...) are the
 *    effective values and win over the stored ones.
 *  - Post SMTP (`postman_options`): password and API keys are base64, constants `POST_SMTP_AUTH_USERNAME|PASSWORD`; its
 *    fallback server (always SMTP) becomes the module's fallback when the primary is another service.
 *  - FluentSMTP (`fluentmail-settings`): default connection = primary, `misc.fallback_connection` = fallback, further
 *    connections are reported; secrets AES-256-CTR with the site's LOGGED_IN_KEY / SALT when `use_encrypt` is on.
 * Gmail / Outlook OAuth tokens are never copied (they belong to the old plugin's app): the client id and secret are
 * transferred and the admin connects again in UX Studio.
 */
final class SmtpSource extends AbstractSource {

	private const MODULE = 'smtp-email';

	private const SECRET_PASSWORD     = 'uxstudio_secret_smtp_password';
	private const SECRET_BREVO        = 'uxstudio_secret_smtp_brevo_api_key';
	private const SECRET_GMAIL_SECRET = 'uxstudio_secret_smtp_gmail_client_secret';

	protected function option_names(): array {
		switch ( $this->slug ) {
			case 'easy-wp-smtp':
				return array( 'easy_wp_smtp' );
			case 'post-smtp':
				return array( 'postman_options' );
			case 'fluent-smtp':
				return array( 'fluentmail-settings' );
			default:
				return array( 'wp_mail_smtp' );
		}
	}

	/**
	 * Decrypt a WP Mail SMTP / Easy WP SMTP secret (libsodium secretbox, base64 of nonce + cipher).
	 *
	 * @param string $stored Stored value.
	 * @param string $key    Raw key bytes ('' = none).
	 * @return string|null Plain text, or null when it cannot be decrypted. A value that is not an encrypted blob is returned as it is.
	 */
	public static function sodium_decrypt( string $stored, string $key ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		$decoded = base64_decode( $stored, true );
		if ( false === $decoded || ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return $stored; // Not encrypted (set through a constant or stored plain).
		}
		if ( '' === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Decrypt a FluentSMTP secret (AES-256-CTR, value + salt, base64 of iv + cipher).
	 *
	 * @param string $stored Stored value.
	 * @return string|null Plain text, null when it cannot be decrypted.
	 */
	public static function fluent_decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		$salt = defined( 'FLUENTMAIL_ENCRYPT_SALT' ) ? (string) FLUENTMAIL_ENCRYPT_SALT : ( defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ? (string) LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure' );
		$key  = defined( 'FLUENTMAIL_ENCRYPT_KEY' ) ? (string) FLUENTMAIL_ENCRYPT_KEY : ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ? (string) LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure' );
		$raw  = base64_decode( $stored, true );
		if ( false === $raw || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}
		$ivlen = openssl_cipher_iv_length( 'aes-256-ctr' );
		$iv    = str_pad( substr( $raw, 0, $ivlen ), 16, "\0" );
		$plain = openssl_decrypt( substr( $raw, $ivlen ), 'aes-256-ctr', $key, 0, $iv );
		if ( ! $plain || substr( $plain, -strlen( $salt ) ) !== $salt ) {
			return null;
		}
		return substr( $plain, 0, -strlen( $salt ) );
	}

	/**
	 * A connection with every field empty: the common shape the readers fill and `map_connection()` writes.
	 *
	 * @return array<string, mixed>
	 */
	private function blank_conn(): array {
		return array(
			'mailer'          => '',
			'host'            => '',
			'port'            => 587,
			'encryption'      => 'tls',
			'auth'            => true,
			'user'            => '',
			'pass'            => '',
			'brevo_key'       => '',
			'gmail_id'        => '',
			'gmail_secret'    => '',
			'sendgrid_key'    => '',
			'sendgrid_region' => 'global',
			'mailgun_key'     => '',
			'mailgun_domain'  => '',
			'mailgun_region'  => 'us',
			'postmark_token'  => '',
			'postmark_stream' => '',
			'ses_access'      => '',
			'ses_secret'      => '',
			'ses_region'      => 'us-east-1',
			'failed'          => false,
		);
	}

	/**
	 * What the plugin is configured to do, in one shape: the sender, the primary connection (flat keys, see blank_conn()) and
	 * optionally a `fallback` connection of the same shape.
	 *
	 * @return array<string, mixed>|null Null when the plugin has no settings.
	 */
	private function read(): ?array {
		switch ( $this->slug ) {
			case 'post-smtp':
				return $this->read_post_smtp();
			case 'fluent-smtp':
				return $this->read_fluent();
			default:
				return $this->read_wpms_family();
		}
	}

	/**
	 * A value, the constant of wp-config.php first when constants are switched on.
	 *
	 * @param bool   $const_on Whether the plugin honours its constants.
	 * @param string $constant Constant name.
	 * @param mixed  $stored   Stored value.
	 * @return mixed
	 */
	private function constant_or( bool $const_on, string $constant, $stored ) {
		return $const_on && defined( $constant ) && '' !== (string) constant( $constant ) ? constant( $constant ) : $stored;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_wpms_family(): ?array {
		$easy   = 'easy-wp-smtp' === $this->slug;
		$option = $this->opt( $easy ? 'easy_wp_smtp' : 'wp_mail_smtp' );
		$prefix = $easy ? 'EASY_WP_SMTP_' : 'WPMS_';
		$on     = defined( $prefix . 'ON' ) && true === constant( $prefix . 'ON' );
		if ( ! is_array( $option ) && ! $on ) {
			return null;
		}
		$option = is_array( $option ) ? $option : array();
		$mail   = (array) ( $option['mail'] ?? array() );
		$smtp   = (array) ( $option['smtp'] ?? array() );
		$yes    = static fn( $v ): bool => true === $v || 'true' === $v || 1 === $v || '1' === $v;

		$key = '';
		if ( defined( $prefix . 'CRYPTO_KEY' ) ) {
			$key = (string) constant( $prefix . 'CRYPTO_KEY' );
		} else {
			$stored = $this->opt( $easy ? 'easy_wp_smtp_mail_key' : 'wp_mail_smtp_mail_key' );
			$key    = is_string( $stored ) ? (string) base64_decode( $stored, true ) : '';
		}

		$mailer = (string) $this->constant_or( $on, $prefix . 'MAILER', $mail['mailer'] ?? 'mail' );
		$pass   = $this->constant_or( $on, $prefix . 'SMTP_PASS', null );
		$pass_u = null !== $pass ? (string) $pass : self::sodium_decrypt( (string) ( $smtp['pass'] ?? '' ), $key );

		$conn           = $this->blank_conn();
		$conn['mailer'] = $mailer;
		// The constant of wp-config.php (when the plugin honours them) wins, else the stored value; API keys are stored as they
		// are, the SMTP password and old releases' secrets are libsodium blobs - sodium_decrypt() hands a plain value back unchanged.
		$read = function ( string $const, $stored, bool $encrypted = true ) use ( $on, $prefix, $key, &$conn ): string {
			$c = $this->constant_or( $on, $prefix . $const, null );
			if ( null !== $c ) {
				return (string) $c;
			}
			if ( ! $encrypted ) {
				return (string) $stored;
			}
			$plain = self::sodium_decrypt( (string) $stored, $key );
			if ( null === $plain ) {
				$conn['failed'] = true;
				return '';
			}
			return $plain;
		};
		$conn['host']       = (string) $this->constant_or( $on, $prefix . 'SMTP_HOST', $smtp['host'] ?? '' );
		$conn['port']       = (int) $this->constant_or( $on, $prefix . 'SMTP_PORT', $smtp['port'] ?? 587 );
		$conn['encryption'] = (string) $this->constant_or( $on, $prefix . 'SSL', $smtp['encryption'] ?? 'tls' );
		$conn['auth']       = $yes( $this->constant_or( $on, $prefix . 'SMTP_AUTH', $smtp['auth'] ?? true ) );
		$conn['user']       = (string) $this->constant_or( $on, $prefix . 'SMTP_USER', $smtp['user'] ?? '' );
		$conn['pass']       = (string) $pass_u;
		$conn['failed']     = 'smtp' === $mailer && null === $pass_u;

		$group = static fn( string $name ): array => (array) ( $option[ $name ] ?? array() );
		switch ( $mailer ) {
			case 'sendinblue':
				$conn['brevo_key'] = $read( 'SENDINBLUE_API_KEY', $group( 'sendinblue' )['api_key'] ?? '' );
				break;
			case 'gmail':
				$conn['gmail_id']     = (string) $this->constant_or( $on, $prefix . 'GMAIL_CLIENT_ID', $group( 'gmail' )['client_id'] ?? '' );
				$conn['gmail_secret'] = $read( 'GMAIL_CLIENT_SECRET', $group( 'gmail' )['client_secret'] ?? '' );
				break;
			case 'sendgrid':
				$conn['sendgrid_key'] = $read( 'SENDGRID_API_KEY', $group( 'sendgrid' )['api_key'] ?? '' );
				break;
			case 'mailgun':
				$conn['mailgun_key']    = $read( 'MAILGUN_API_KEY', $group( 'mailgun' )['api_key'] ?? '' );
				$conn['mailgun_domain'] = (string) $this->constant_or( $on, $prefix . 'MAILGUN_DOMAIN', $group( 'mailgun' )['domain'] ?? '' );
				$conn['mailgun_region'] = 'EU' === strtoupper( (string) $this->constant_or( $on, $prefix . 'MAILGUN_REGION', $group( 'mailgun' )['region'] ?? 'US' ) ) ? 'eu' : 'us';
				break;
			case 'postmark':
				$conn['postmark_token']  = $read( 'POSTMARK_SERVER_API_TOKEN', $group( 'postmark' )['server_api_token'] ?? '' );
				$conn['postmark_stream'] = (string) $this->constant_or( $on, $prefix . 'POSTMARK_MESSAGE_STREAM', $group( 'postmark' )['message_stream'] ?? '' );
				break;
			case 'amazonses':
				$conn['mailer']     = 'ses';
				$conn['ses_access'] = $read( 'AMAZONSES_CLIENT_ID', $group( 'amazonses' )['client_id'] ?? '' );
				$conn['ses_secret'] = $read( 'AMAZONSES_CLIENT_SECRET', $group( 'amazonses' )['client_secret'] ?? '' );
				$conn['ses_region'] = (string) $this->constant_or( $on, $prefix . 'AMAZONSES_REGION', $group( 'amazonses' )['region'] ?? 'us-east-1' );
				break;
		}

		$out = array(
			'from_email'  => (string) $this->constant_or( $on, $prefix . 'MAIL_FROM', $mail['from_email'] ?? '' ),
			'from_name'   => (string) $this->constant_or( $on, $prefix . 'MAIL_FROM_NAME', $mail['from_name'] ?? '' ),
			'force_email' => $yes( $this->constant_or( $on, $prefix . 'MAIL_FROM_FORCE', $mail['from_email_force'] ?? false ) ),
			'force_name'  => $yes( $this->constant_or( $on, $prefix . 'MAIL_FROM_NAME_FORCE', $mail['from_name_force'] ?? false ) ),
			'provider'    => $mailer,
			'conn'        => $conn,
			'fallback'    => null,
			'extras'      => array(),
		);
		if ( ! empty( $mail['return_path'] ) ) {
			$out['extras'][] = __( 'The "return path" setting (bounce address) has no counterpart in the module.', 'ux-studio' );
		}
		if ( ! empty( $option['logs']['enabled'] ) ) {
			$out['extras'][] = __( 'The e-mail log of the plugin and its tracking are not transferred. Use the Email Log module.', 'ux-studio' );
		}
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_post_smtp(): ?array {
		$o = $this->opt( 'postman_options' );
		if ( ! is_array( $o ) ) {
			return null;
		}
		$transport = (string) ( $o['transport_type'] ?? 'smtp' );
		$decode    = static function ( $v ): string {
			$d = base64_decode( (string) $v, true );
			return false === $d ? (string) $v : $d;
		};
		$truthy = static fn( $v ): bool => ! in_array( $v, array( '', null, false, 'false', '0', 0, 'none' ), true );
		$map    = array(
			'smtp'           => 'smtp',
			'sendinblue_api' => 'sendinblue',
			'gmail_api'      => 'gmail',
			'sendgrid_api'   => 'sendgrid',
			'mailgun_api'    => 'mailgun',
			'postmark_api'   => 'postmark',
		);
		$user = defined( 'POST_SMTP_AUTH_USERNAME' ) ? (string) POST_SMTP_AUTH_USERNAME : (string) ( $o['basic_auth_username'] ?? '' );
		$pass = defined( 'POST_SMTP_AUTH_PASSWORD' ) ? (string) POST_SMTP_AUTH_PASSWORD : $decode( $o['basic_auth_password'] ?? '' );

		$conn                    = $this->blank_conn();
		$conn['mailer']          = $map[ $transport ] ?? $transport;
		$conn['host']            = (string) ( $o['hostname'] ?? '' );
		$conn['port']            = (int) ( $o['port'] ?? 587 );
		$conn['encryption']      = (string) ( $o['enc_type'] ?? 'tls' );
		$conn['auth']            = 'none' !== (string) ( $o['auth_type'] ?? 'plain' );
		$conn['user']            = $user;
		$conn['pass']            = $pass;
		$conn['brevo_key']       = $decode( $o['sendinblue_api_key'] ?? '' );
		$conn['gmail_id']        = (string) ( $o['oauth_client_id'] ?? '' );
		$conn['gmail_secret']    = (string) ( $o['oauth_client_secret'] ?? '' );
		$conn['sendgrid_key']    = $decode( $o['sendgrid_api_key'] ?? '' );
		$conn['sendgrid_region'] = 'EU' === strtoupper( (string) ( $o['sendgrid_region'] ?? 'Global' ) ) ? 'eu' : 'global';
		$conn['mailgun_key']     = $decode( $o['mailgun_api_key'] ?? '' );
		$conn['mailgun_domain']  = (string) ( $o['mailgun_domain_name'] ?? '' );
		// The region is a check box "Mailgun Europe Region?": present = Europe.
		$conn['mailgun_region']  = $truthy( $o['mailgun_region'] ?? '' ) ? 'eu' : 'us';
		$conn['postmark_token']  = $decode( $o['postmark_api_key'] ?? '' );

		$out = array(
			'from_email'  => (string) ( $o['sender_email'] ?? '' ),
			'from_name'   => (string) ( $o['sender_name'] ?? '' ),
			'force_email' => 'true' === (string) ( $o['prevent_sender_email_override'] ?? '' ) || true === ( $o['prevent_sender_email_override'] ?? false ),
			'force_name'  => 'true' === (string) ( $o['prevent_sender_name_override'] ?? '' ) || true === ( $o['prevent_sender_name_override'] ?? false ),
			'provider'    => $transport,
			'conn'        => $conn,
			'fallback'    => null,
			'extras'      => array(),
		);
		if ( 'oauth2' === (string) ( $o['auth_type'] ?? '' ) && 'smtp' === $transport ) {
			$out['extras'][] = __( 'The SMTP server used OAuth 2.0 sign-in. The module uses a user name and password, so enter them again.', 'ux-studio' );
		}
		// Post SMTP's fallback is a second SMTP server used when the primary send fails.
		if ( ! empty( $o['fallback_smtp_enabled'] ) && 'false' !== (string) $o['fallback_smtp_enabled'] && '' !== (string) ( $o['fallback_smtp_hostname'] ?? '' ) ) {
			$fb                = $this->blank_conn();
			$fb['mailer']      = 'smtp';
			$fb['host']        = (string) $o['fallback_smtp_hostname'];
			$fb['port']        = (int) ( $o['fallback_smtp_port'] ?? 587 );
			$fb['encryption']  = '' === (string) ( $o['fallback_smtp_security'] ?? '' ) ? 'none' : (string) $o['fallback_smtp_security'];
			$fb['auth']        = $truthy( $o['fallback_smtp_use_auth'] ?? 'true' );
			$fb['user']        = defined( 'POST_SMTP_FALLBACK_AUTH_USERNAME' ) ? (string) POST_SMTP_FALLBACK_AUTH_USERNAME : (string) ( $o['fallback_smtp_username'] ?? '' );
			$fb['pass']        = defined( 'POST_SMTP_FALLBACK_AUTH_PASSWORD' ) ? (string) POST_SMTP_FALLBACK_AUTH_PASSWORD : $this->post_smtp_fallback_password( (string) ( $o['fallback_smtp_password'] ?? '' ) );
			$out['fallback']   = $fb;
		}
		if ( in_array( (string) ( $o['run_mode'] ?? 'production' ), array( 'log_only', 'ignore' ), true ) ) {
			$out['extras'][] = __( 'The plugin was set not to send e-mail (log only or ignore). The module will send e-mail normally once it is on.', 'ux-studio' );
		}
		return $out;
	}

	/**
	 * Post SMTP stores the fallback password as base64, sometimes encoded twice.
	 *
	 * @param string $stored Stored value.
	 */
	private function post_smtp_fallback_password( string $stored ): string {
		$once = base64_decode( $stored, true );
		if ( false === $once ) {
			return $stored;
		}
		if ( preg_match( '/^[A-Za-z0-9\/\r\n+]*={0,2}$/', $once ) && 0 === strlen( $once ) % 4 ) {
			$twice = base64_decode( $once, true );
			return false === $twice ? $once : $twice;
		}
		return $once;
	}

	/**
	 * One FluentSMTP connection (`provider_settings`) in the common shape.
	 *
	 * @param array<string, mixed> $p       Provider settings.
	 * @param bool                 $encrypt Whether the plugin encrypts the secrets of its connections.
	 * @return array<string, mixed>
	 */
	private function fluent_conn( array $p, bool $encrypt ): array {
		$encrypted = $encrypt && 'yes' !== (string) ( $p['disable_encryption'] ?? '' );
		$conn      = $this->blank_conn();
		$secret    = static function ( $v ) use ( $encrypted, &$conn ): string {
			if ( ! $encrypted || '' === (string) $v ) {
				return (string) $v;
			}
			$plain = self::fluent_decrypt( (string) $v );
			if ( null === $plain ) {
				$conn['failed'] = true;
				return '';
			}
			return $plain;
		};
		$yes      = static fn( $v ): bool => in_array( $v, array( 'yes', true, '1', 1 ), true );
		$provider = (string) ( $p['provider'] ?? 'smtp' );

		$conn['mailer']       = 'ses' === $provider ? 'ses' : $provider;
		$conn['host']         = (string) ( $p['host'] ?? '' );
		$conn['port']         = (int) ( $p['port'] ?? 587 );
		$conn['encryption']   = (string) ( $p['encryption'] ?? 'tls' );
		$conn['auth']         = $yes( $p['auth'] ?? 'yes' );
		$conn['user']         = (string) ( $p['username'] ?? '' );
		$conn['pass']         = 'smtp' === $provider ? $secret( $p['password'] ?? '' ) : '';
		$conn['gmail_id']     = (string) ( $p['client_id'] ?? '' );
		$conn['gmail_secret'] = 'gmail' === $provider ? $secret( $p['client_secret'] ?? '' ) : '';
		switch ( $provider ) {
			case 'sendinblue':
				$conn['brevo_key'] = $secret( $p['api_key'] ?? '' );
				break;
			case 'sendgrid':
				$conn['sendgrid_key'] = $secret( $p['api_key'] ?? '' );
				break;
			case 'mailgun':
				$conn['mailgun_key']    = $secret( $p['api_key'] ?? '' );
				$conn['mailgun_domain'] = (string) ( $p['domain_name'] ?? '' );
				$conn['mailgun_region'] = 'eu' === strtolower( (string) ( $p['region'] ?? 'us' ) ) ? 'eu' : 'us';
				break;
			case 'postmark':
				$conn['postmark_token']  = $secret( $p['api_key'] ?? '' );
				$conn['postmark_stream'] = (string) ( $p['message_stream'] ?? '' );
				break;
			case 'ses':
				$conn['ses_access'] = $secret( $p['access_key'] ?? '' );
				$conn['ses_secret'] = $secret( $p['secret_key'] ?? '' );
				$conn['ses_region'] = (string) ( $p['region'] ?? 'us-east-1' );
				break;
		}
		return $conn;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_fluent(): ?array {
		$o = $this->opt( 'fluentmail-settings' );
		if ( ! is_array( $o ) || empty( $o['connections'] ) || ! is_array( $o['connections'] ) ) {
			return null;
		}
		$connections = $o['connections'];
		$default     = (string) ( $o['misc']['default_connection'] ?? '' );
		$key         = isset( $connections[ $default ] ) ? $default : (string) array_key_first( $connections );
		$p           = (array) ( $connections[ $key ]['provider_settings'] ?? array() );
		$encrypt     = ! empty( $o['use_encrypt'] );
		$conn        = $this->fluent_conn( $p, $encrypt );

		$out = array(
			'from_email'  => (string) ( $p['sender_email'] ?? '' ),
			'from_name'   => (string) ( $p['sender_name'] ?? '' ),
			'force_email' => in_array( $p['force_from_email'] ?? 'no', array( 'yes', true, '1', 1 ), true ),
			'force_name'  => in_array( $p['force_from_name'] ?? 'no', array( 'yes', true, '1', 1 ), true ),
			'provider'    => (string) ( $p['provider'] ?? 'smtp' ),
			'conn'        => $conn,
			'fallback'    => null,
			'extras'      => array(),
		);

		// The plugin's own "fallback connection": another of its connections, used when sending fails.
		$used        = 1;
		$fallback_id = (string) ( $o['misc']['fallback_connection'] ?? '' );
		if ( '' !== $fallback_id && $fallback_id !== $key && isset( $connections[ $fallback_id ] ) ) {
			$out['fallback'] = $this->fluent_conn( (array) ( $connections[ $fallback_id ]['provider_settings'] ?? array() ), $encrypt );
			$used            = 2;
		}
		if ( count( $connections ) > $used ) {
			$out['extras'][] = sprintf(
				/* translators: 1: number of connections, 2: number of connections that are transferred. */
				__( 'The plugin had %1$d connections (several senders, routed by sender address). The module has a primary and a fallback connection, so only %2$d of them are transferred.', 'ux-studio' ),
				count( $connections ),
				$used
			);
		}
		return $out;
	}

	public function smoke_checks(): array {
		if ( $this->mapping()->is_empty() ) {
			return array();
		}
		return array(
			array(
				'id'    => 'mail',
				'label' => __( 'Test e-mail through the SMTP module', 'ux-studio' ),
				'type'  => 'smtp_test',
			),
		);
	}

	/** Mailer ids of the plugins -> ids of the module. */
	private const MAILER_IDS = array(
		'smtp'       => 'smtp',
		'sendinblue' => 'brevo',
		'gmail'      => 'gmail',
		'sendgrid'   => 'sendgrid',
		'mailgun'    => 'mailgun',
		'postmark'   => 'postmark',
		'ses'        => 'ses',
	);

	/**
	 * Write the settings and secrets of one connection.
	 *
	 * @param Mapping              $m      Mapping.
	 * @param array<string, mixed> $c      Connection.
	 * @param string               $origin Origin label.
	 */
	private function map_connection( Mapping $m, array $c, string $origin ): void {
		switch ( $c['mailer'] ) {
			case 'smtp':
				$enc = in_array( $c['encryption'], array( 'tls', 'ssl', 'none' ), true ) ? $c['encryption'] : 'tls';
				$m->set( self::MODULE, 'host', $c['host'], __( 'SMTP host', 'ux-studio' ), $origin );
				$m->set( self::MODULE, 'port', $c['port'], __( 'SMTP port', 'ux-studio' ), $origin );
				$m->set( self::MODULE, 'encryption', $enc, __( 'Encryption', 'ux-studio' ), $origin );
				$m->set( self::MODULE, 'username', $c['user'], __( 'SMTP username', 'ux-studio' ), $origin );
				$m->secret( self::SECRET_PASSWORD, $c['pass'], __( 'SMTP password', 'ux-studio' ), $origin, self::MODULE );
				if ( ! $c['auth'] && '' === $c['user'] ) {
					$m->lossy( __( 'The plugin sent without logging in to the server. The module always logs in, so a server that needs no login will not work with it.', 'ux-studio' ) );
				}
				break;
			case 'sendinblue':
				$m->secret( self::SECRET_BREVO, $c['brevo_key'], __( 'Brevo API key', 'ux-studio' ), $origin, self::MODULE );
				break;
			case 'gmail':
				$m->set( self::MODULE, 'gmail_client_id', $c['gmail_id'], __( 'Gmail OAuth client ID', 'ux-studio' ), $origin );
				$m->secret( self::SECRET_GMAIL_SECRET, $c['gmail_secret'], __( 'Gmail OAuth client secret', 'ux-studio' ), $origin, self::MODULE );
				$m->lossy( __( 'The Gmail sign-in (access and refresh token) belongs to the old plugin and is not transferred. Open the module and use "Connect to Google" once.', 'ux-studio' ) );
				break;
			case 'sendgrid':
				$m->secret( SmtpModule::SECRET_SENDGRID, $c['sendgrid_key'], __( 'SendGrid API key', 'ux-studio' ), $origin, self::MODULE );
				$m->set( self::MODULE, 'sendgrid_region', $c['sendgrid_region'], __( 'SendGrid data region', 'ux-studio' ), $origin );
				break;
			case 'mailgun':
				$m->secret( SmtpModule::SECRET_MAILGUN, $c['mailgun_key'], __( 'Mailgun API key', 'ux-studio' ), $origin, self::MODULE );
				$m->set( self::MODULE, 'mailgun_domain', $c['mailgun_domain'], __( 'Mailgun sending domain', 'ux-studio' ), $origin );
				$m->set( self::MODULE, 'mailgun_region', $c['mailgun_region'], __( 'Mailgun region', 'ux-studio' ), $origin );
				break;
			case 'postmark':
				$m->secret( SmtpModule::SECRET_POSTMARK, $c['postmark_token'], __( 'Postmark server API token', 'ux-studio' ), $origin, self::MODULE );
				$m->set( self::MODULE, 'postmark_message_stream', $c['postmark_stream'], __( 'Postmark message stream', 'ux-studio' ), $origin );
				break;
			case 'ses':
				$region = in_array( $c['ses_region'], Providers::SES_REGIONS, true ) ? $c['ses_region'] : 'us-east-1';
				if ( $region !== $c['ses_region'] ) {
					$m->lossy(
						sprintf(
							/* translators: %s: region of the plugin. */
							__( 'The Amazon SES region "%s" is not offered by the module. Choose the region again in the module.', 'ux-studio' ),
							(string) $c['ses_region']
						)
					);
				}
				$m->secret( SmtpModule::SECRET_SES_ACCESS, $c['ses_access'], __( 'Amazon SES access key ID', 'ux-studio' ), $origin, self::MODULE );
				$m->secret( SmtpModule::SECRET_SES_SECRET, $c['ses_secret'], __( 'Amazon SES secret access key', 'ux-studio' ), $origin, self::MODULE );
				$m->set( self::MODULE, 'ses_region', $region, __( 'Amazon SES region', 'ux-studio' ), $origin );
				break;
		}
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->read();
		if ( null === $c ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		$conn   = $c['conn'];
		$id     = (string) $conn['mailer'];
		$ours   = self::MAILER_IDS[ $id ] ?? null;
		$mailer = $ours ?? 'smtp';
		$origin = $this->option_names()[0];

		if ( null === $ours ) {
			if ( in_array( $id, array( 'mail', 'default', '' ), true ) ) {
				$m->note( __( 'The plugin sent e-mail with the PHP mail function, which has no counterpart. The module keeps the WordPress default until you enter an SMTP server; the sender is transferred.', 'ux-studio' ) );
			} else {
				$m->lossy(
					sprintf(
						/* translators: %s: name of the sending service. */
						__( 'The sending method "%s" is not available in the module (SMTP, Brevo, Gmail, SendGrid, Mailgun, Postmark and Amazon SES are). Only the sender is transferred; set up the connection in the module.', 'ux-studio' ),
						(string) $c['provider']
					)
				);
			}
		}

		$m->set( self::MODULE, 'mailer', $mailer, __( 'Mailer', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'from_email', $c['from_email'], __( 'From email', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'force_from_email', $c['force_email'], __( 'Force From email', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'from_name', $c['from_name'], __( 'From name', 'ux-studio' ), $origin );
		$m->set( self::MODULE, 'force_from_name', $c['force_name'], __( 'Force From name', 'ux-studio' ), $origin );

		$failed = (bool) $conn['failed'];
		if ( null !== $ours ) {
			$this->map_connection( $m, $conn, $origin );
		}

		// The plugin's own fallback connection becomes the module's fallback.
		$fb = $c['fallback'] ?? null;
		if ( is_array( $fb ) ) {
			$fb_id  = (string) $fb['mailer'];
			$fb_our = self::MAILER_IDS[ $fb_id ] ?? null;
			if ( null === $fb_our ) {
				$m->lossy(
					sprintf(
						/* translators: %s: name of the sending service. */
						__( 'The fallback connection uses "%s", which the module does not have. It is not transferred.', 'ux-studio' ),
						$fb_id
					)
				);
			} elseif ( $fb_our === $mailer ) {
				$m->lossy(
					sprintf(
						/* translators: %s: name of the sending service. */
						__( 'The fallback connection is a second "%s" account. The module keeps one account per service, so it is not transferred; choose another service as the fallback.', 'ux-studio' ),
						$fb_our
					)
				);
			} else {
				$this->map_connection( $m, $fb, $origin . ' (fallback)' );
				$m->set( self::MODULE, 'fallback_mailer', $fb_our, __( 'Fallback connection', 'ux-studio' ), $origin . ' (fallback)' );
				$failed = $failed || (bool) $fb['failed'];
			}
		}

		if ( $failed ) {
			$m->lossy( __( 'The stored password or key could not be decrypted (the encryption key of the plugin is missing). Enter it again in the module.', 'ux-studio' ) );
		}
		foreach ( $c['extras'] as $text ) {
			$m->lossy( $text );
		}
		$m->warn( __( 'Once the module is on, all e-mail of the site is sent with these settings. Send a test message in the module before you deactivate the old plugin.', 'ux-studio' ) );
		return $m;
	}
}
