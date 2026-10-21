<?php
/**
 * Source: WP Mail SMTP, Easy WP SMTP, Post SMTP, FluentSMTP.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `smtp-email`: mailer (smtp | brevo | gmail), sender, host / port / encryption / user and the
 * encrypted secrets (SMTP password, Brevo key, Gmail client secret) through Security::store_secret.
 *
 * BEHAVIOUR-CHANGING (it changes how every e-mail leaves the site): with the module on, the values and the
 * encrypted secrets wait in the staging area.
 *
 * Reading, each plugin with its own scheme:
 *  - WP Mail SMTP (`wp_mail_smtp`) and Easy WP SMTP (`easy_wp_smtp`, a fork): groups `mail`, `smtp`, `sendinblue`,
 *    `gmail`; the password is a libsodium secretbox (key in option `<option>_mail_key` or constant `WPMS_CRYPTO_KEY`).
 *    Settings fixed by constants in wp-config.php (`WPMS_ON` / `EASY_WP_SMTP_ON` plus `WPMS_SMTP_HOST` ...) are the
 *    effective values and win over the stored ones.
 *  - Post SMTP (`postman_options`): password and API keys are base64, constants `POST_SMTP_AUTH_USERNAME|PASSWORD`.
 *  - FluentSMTP (`fluentmail-settings`): default connection; secrets AES-256-CTR with the site's LOGGED_IN_KEY / SALT
 *    when `use_encrypt` is on.
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
	 * What the plugin is configured to do, in one shape.
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

		$out = array(
			'mailer'       => $mailer,
			'from_email'   => (string) $this->constant_or( $on, $prefix . 'MAIL_FROM', $mail['from_email'] ?? '' ),
			'from_name'    => (string) $this->constant_or( $on, $prefix . 'MAIL_FROM_NAME', $mail['from_name'] ?? '' ),
			'force_email'  => $yes( $this->constant_or( $on, $prefix . 'MAIL_FROM_FORCE', $mail['from_email_force'] ?? false ) ),
			'force_name'   => $yes( $this->constant_or( $on, $prefix . 'MAIL_FROM_NAME_FORCE', $mail['from_name_force'] ?? false ) ),
			'host'         => (string) $this->constant_or( $on, $prefix . 'SMTP_HOST', $smtp['host'] ?? '' ),
			'port'         => (int) $this->constant_or( $on, $prefix . 'SMTP_PORT', $smtp['port'] ?? 587 ),
			'encryption'   => (string) $this->constant_or( $on, $prefix . 'SSL', $smtp['encryption'] ?? 'tls' ),
			'auth'         => $yes( $this->constant_or( $on, $prefix . 'SMTP_AUTH', $smtp['auth'] ?? true ) ),
			'user'         => (string) $this->constant_or( $on, $prefix . 'SMTP_USER', $smtp['user'] ?? '' ),
			'pass'         => (string) $pass_u,
			'pass_failed'  => null === $pass_u,
			'brevo_key'    => '',
			'gmail_id'     => '',
			'gmail_secret' => '',
			'provider'     => $mailer,
			'extras'       => array(),
		);
		$brevo = (array) ( $option['sendinblue'] ?? array() );
		$gmail = (array) ( $option['gmail'] ?? array() );
		if ( 'sendinblue' === $mailer ) {
			$k                  = $this->constant_or( $on, $prefix . 'SENDINBLUE_API_KEY', null );
			$plain              = null !== $k ? (string) $k : self::sodium_decrypt( (string) ( $brevo['api_key'] ?? '' ), $key );
			$out['brevo_key']   = (string) $plain;
			$out['pass_failed'] = $out['pass_failed'] || null === $plain;
		}
		if ( 'gmail' === $mailer ) {
			$out['gmail_id']     = (string) $this->constant_or( $on, $prefix . 'GMAIL_CLIENT_ID', $gmail['client_id'] ?? '' );
			$secret              = $this->constant_or( $on, $prefix . 'GMAIL_CLIENT_SECRET', null );
			$plain               = null !== $secret ? (string) $secret : self::sodium_decrypt( (string) ( $gmail['client_secret'] ?? '' ), $key );
			$out['gmail_secret'] = (string) $plain;
			$out['pass_failed']  = $out['pass_failed'] || null === $plain;
		}
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
		$user = defined( 'POST_SMTP_AUTH_USERNAME' ) ? (string) POST_SMTP_AUTH_USERNAME : (string) ( $o['basic_auth_username'] ?? '' );
		$pass = defined( 'POST_SMTP_AUTH_PASSWORD' ) ? (string) POST_SMTP_AUTH_PASSWORD : $decode( $o['basic_auth_password'] ?? '' );
		$map  = array(
			'smtp'            => 'smtp',
			'sendinblue_api'  => 'sendinblue',
			'gmail_api'       => 'gmail',
		);
		$out  = array(
			'mailer'       => $map[ $transport ] ?? $transport,
			'from_email'   => (string) ( $o['sender_email'] ?? '' ),
			'from_name'    => (string) ( $o['sender_name'] ?? '' ),
			'force_email'  => 'true' === (string) ( $o['prevent_sender_email_override'] ?? '' ) || true === ( $o['prevent_sender_email_override'] ?? false ),
			'force_name'   => 'true' === (string) ( $o['prevent_sender_name_override'] ?? '' ) || true === ( $o['prevent_sender_name_override'] ?? false ),
			'host'         => (string) ( $o['hostname'] ?? '' ),
			'port'         => (int) ( $o['port'] ?? 587 ),
			'encryption'   => (string) ( $o['enc_type'] ?? 'tls' ),
			'auth'         => 'none' !== (string) ( $o['auth_type'] ?? 'plain' ),
			'user'         => $user,
			'pass'         => $pass,
			'pass_failed'  => false,
			'brevo_key'    => $decode( $o['sendinblue_api_key'] ?? '' ),
			'gmail_id'     => (string) ( $o['oauth_client_id'] ?? '' ),
			'gmail_secret' => (string) ( $o['oauth_client_secret'] ?? '' ),
			'provider'     => $transport,
			'extras'       => array(),
		);
		if ( 'oauth2' === (string) ( $o['auth_type'] ?? '' ) && 'smtp' === $transport ) {
			$out['extras'][] = __( 'The SMTP server used OAuth 2.0 sign-in. The module uses a user name and password, so enter them again.', 'ux-studio' );
		}
		if ( ! empty( $o['fallback_smtp_enabled'] ) && 'false' !== (string) $o['fallback_smtp_enabled'] ) {
			$out['extras'][] = __( 'The fallback SMTP server of the plugin has no equivalent.', 'ux-studio' );
		}
		if ( in_array( (string) ( $o['run_mode'] ?? 'production' ), array( 'log_only', 'ignore' ), true ) ) {
			$out['extras'][] = __( 'The plugin was set not to send e-mail (log only or ignore). The module will send e-mail normally once it is on.', 'ux-studio' );
		}
		return $out;
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
		$encrypted   = ! empty( $o['use_encrypt'] ) && 'yes' !== (string) ( $p['disable_encryption'] ?? '' );
		$secret      = static function ( $v ) use ( $encrypted ): ?string {
			if ( ! $encrypted || '' === (string) $v ) {
				return (string) $v;
			}
			return self::fluent_decrypt( (string) $v );
		};
		$provider = (string) ( $p['provider'] ?? 'smtp' );
		$pass     = $secret( $p['password'] ?? '' );
		$brevo    = $secret( $p['api_key'] ?? '' );
		$gsecret  = $secret( $p['client_secret'] ?? '' );
		$yes      = static fn( $v ): bool => in_array( $v, array( 'yes', true, '1', 1 ), true );
		$out      = array(
			'mailer'       => 'sendinblue' === $provider ? 'sendinblue' : $provider,
			'from_email'   => (string) ( $p['sender_email'] ?? '' ),
			'from_name'    => (string) ( $p['sender_name'] ?? '' ),
			'force_email'  => $yes( $p['force_from_email'] ?? 'no' ),
			'force_name'   => $yes( $p['force_from_name'] ?? 'no' ),
			'host'         => (string) ( $p['host'] ?? '' ),
			'port'         => (int) ( $p['port'] ?? 587 ),
			'encryption'   => (string) ( $p['encryption'] ?? 'tls' ),
			'auth'         => $yes( $p['auth'] ?? 'yes' ),
			'user'         => (string) ( $p['username'] ?? '' ),
			'pass'         => (string) $pass,
			'pass_failed'  => null === $pass || null === $brevo || null === $gsecret,
			'brevo_key'    => (string) $brevo,
			'gmail_id'     => (string) ( $p['client_id'] ?? '' ),
			'gmail_secret' => (string) $gsecret,
			'provider'     => $provider,
			'extras'       => array(),
		);
		if ( count( $connections ) > 1 ) {
			$out['extras'][] = sprintf(
				/* translators: %d: number of connections. */
				__( 'The plugin had %d connections (several senders). The module has one, so only the default connection is transferred.', 'ux-studio' ),
				count( $connections )
			);
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		$c = $this->read();
		if ( null === $c ) {
			$m->note( __( 'The plugin has no stored settings, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}

		$supported = array( 'smtp', 'sendinblue', 'gmail' );
		$mailer    = in_array( $c['mailer'], $supported, true ) ? $c['mailer'] : 'smtp';
		$origin    = $this->option_names()[0];

		if ( ! in_array( $c['mailer'], $supported, true ) ) {
			if ( in_array( $c['mailer'], array( 'mail', 'default', '' ), true ) ) {
				$m->note( __( 'The plugin sent e-mail with the PHP mail function, which has no counterpart. The module keeps the WordPress default until you enter an SMTP server; the sender is transferred.', 'ux-studio' ) );
			} else {
				$m->lossy(
					sprintf(
						/* translators: %s: name of the sending service. */
						__( 'The sending method "%s" is not available in the module (SMTP, Brevo and Gmail are). Only the sender is transferred; set up the connection in the module.', 'ux-studio' ),
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

		if ( 'smtp' === $c['mailer'] ) {
			$enc = in_array( $c['encryption'], array( 'tls', 'ssl', 'none' ), true ) ? $c['encryption'] : 'tls';
			$m->set( self::MODULE, 'host', $c['host'], __( 'SMTP host', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'port', $c['port'], __( 'SMTP port', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'encryption', $enc, __( 'Encryption', 'ux-studio' ), $origin );
			$m->set( self::MODULE, 'username', $c['user'], __( 'SMTP username', 'ux-studio' ), $origin );
			$m->secret( self::SECRET_PASSWORD, $c['pass'], __( 'SMTP password', 'ux-studio' ), $origin, self::MODULE );
			if ( ! $c['auth'] && '' === $c['user'] ) {
				$m->lossy( __( 'The plugin sent without logging in to the server. The module always logs in, so a server that needs no login will not work with it.', 'ux-studio' ) );
			}
		} elseif ( 'sendinblue' === $c['mailer'] ) {
			$m->secret( self::SECRET_BREVO, $c['brevo_key'], __( 'Brevo API key', 'ux-studio' ), $origin, self::MODULE );
		} elseif ( 'gmail' === $c['mailer'] ) {
			$m->set( self::MODULE, 'gmail_client_id', $c['gmail_id'], __( 'Gmail OAuth client ID', 'ux-studio' ), $origin );
			$m->secret( self::SECRET_GMAIL_SECRET, $c['gmail_secret'], __( 'Gmail OAuth client secret', 'ux-studio' ), $origin, self::MODULE );
			$m->lossy( __( 'The Gmail sign-in (access and refresh token) belongs to the old plugin and is not transferred. Open the module and use "Connect to Google" once.', 'ux-studio' ) );
		}
		if ( $c['pass_failed'] ) {
			$m->lossy( __( 'The stored password or key could not be decrypted (the encryption key of the plugin is missing). Enter it again in the module.', 'ux-studio' ) );
		}
		foreach ( $c['extras'] as $text ) {
			$m->lossy( $text );
		}
		$m->warn( __( 'Once the module is on, all e-mail of the site is sent with these settings. Send a test message in the module before you deactivate the old plugin.', 'ux-studio' ) );
		return $m;
	}
}
