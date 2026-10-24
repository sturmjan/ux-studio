<?php
/**
 * Requests of the API mailers: SendGrid, Mailgun, Postmark and Amazon SES.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Per provider two pure functions (the request that carries a MailMessage, and the verdict on the response) plus `send()`,
 * which runs the request through the WordPress HTTP API (so `pre_http_request` can mock it in tests). The hosts are fixed
 * per provider; what the admin enters (Mailgun domain, SES region) is validated before it reaches an address.
 *
 * Amazon SES: the SES v2 HTTPS API (`SendEmail`), signed with Signature Version 4 in PHP, no SDK. Chosen over SES SMTP
 * credentials because it needs no SMTP connection (shared hosts often block ports 465/587), no second set of credentials
 * (SMTP passwords are derived from the secret key) and it shows the real error text.
 */
final class Providers {

	public const SENDGRID = 'sendgrid';
	public const MAILGUN  = 'mailgun';
	public const POSTMARK = 'postmark';
	public const SES      = 'ses';

	/** @var string[] */
	public const IDS = array( self::SENDGRID, self::MAILGUN, self::POSTMARK, self::SES );

	/** Regions the SES region setting offers. */
	public const SES_REGIONS = array(
		'us-east-1',
		'us-east-2',
		'us-west-1',
		'us-west-2',
		'ca-central-1',
		'sa-east-1',
		'eu-west-1',
		'eu-west-2',
		'eu-west-3',
		'eu-central-1',
		'eu-central-2',
		'eu-north-1',
		'eu-south-1',
		'af-south-1',
		'me-south-1',
		'il-central-1',
		'ap-south-1',
		'ap-northeast-1',
		'ap-northeast-2',
		'ap-northeast-3',
		'ap-southeast-1',
		'ap-southeast-2',
		'ap-east-1',
	);

	/**
	 * The request for a provider.
	 *
	 * @param string               $provider One of IDS.
	 * @param MailMessage          $m        Message.
	 * @param array<string, mixed> $cfg      Credentials and options of the provider (see the build_* methods). `time` (UTC seconds) is optional, for SES.
	 * @return array{url:string,args:array<string,mixed>}|null Null for an unknown provider or one with a malformed setting (domain).
	 */
	public static function request( string $provider, MailMessage $m, array $cfg ): ?array {
		switch ( $provider ) {
			case self::SENDGRID:
				return self::build_sendgrid( $m, $cfg );
			case self::MAILGUN:
				return self::build_mailgun( $m, $cfg );
			case self::POSTMARK:
				return self::build_postmark( $m, $cfg );
			case self::SES:
				return self::build_ses( $m, $cfg );
		}
		return null;
	}

	/**
	 * Send a message and say whether the provider took it.
	 *
	 * @param string               $provider One of IDS.
	 * @param MailMessage          $m        Message.
	 * @param array<string, mixed> $cfg      Credentials and options.
	 * @return array{ok:bool,error:string,http:int}
	 */
	public static function send( string $provider, MailMessage $m, array $cfg ): array {
		$req = self::request( $provider, $m, $cfg );
		if ( null === $req ) {
			return array(
				'ok'    => false,
				'error' => __( 'The settings of this provider are incomplete.', 'ux-studio' ),
				'http'  => 0,
			);
		}
		$response = wp_remote_request( $req['url'], $req['args'] );
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
				'http'  => 0,
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$out  = self::verdict( $provider, $code, (string) wp_remote_retrieve_body( $response ) );
		return $out + array( 'http' => $code );
	}

	/**
	 * Verdict on a response.
	 *
	 * @param string $provider One of IDS.
	 * @param int    $code     HTTP status.
	 * @param string $body     Response body.
	 * @return array{ok:bool,error:string}
	 */
	public static function verdict( string $provider, int $code, string $body ): array {
		$data = json_decode( $body, true );
		$data = is_array( $data ) ? $data : array();
		$ok   = $code >= 200 && $code < 300;

		if ( self::POSTMARK === $provider ) {
			// Postmark answers 200 with ErrorCode 0 when it took the message; other codes are rejections.
			$ok = 200 === $code && 0 === (int) ( $data['ErrorCode'] ?? 0 );
		}
		if ( $ok ) {
			return array(
				'ok'    => true,
				'error' => '',
			);
		}

		$text = '';
		switch ( $provider ) {
			case self::SENDGRID:
				$first = $data['errors'][0] ?? null;
				$text  = is_array( $first ) ? (string) ( $first['message'] ?? '' ) : '';
				break;
			case self::MAILGUN:
				$text = (string) ( $data['message'] ?? '' );
				break;
			case self::POSTMARK:
				$text = (string) ( $data['Message'] ?? '' );
				break;
			case self::SES:
				$text = (string) ( $data['message'] ?? $data['Message'] ?? '' );
				break;
		}
		if ( '' === $text ) {
			$text = 0 === $code ? __( 'No answer from the provider.', 'ux-studio' ) : 'HTTP ' . $code;
		} else {
			$text = 'HTTP ' . $code . ': ' . $text;
		}
		return array(
			'ok'    => false,
			'error' => mb_substr( $text, 0, 300 ),
		);
	}

	/* ── SendGrid ── */

	/**
	 * SendGrid v3 `mail/send`. Config: api_key, region (`global` or `eu`).
	 *
	 * @param MailMessage          $m   Message.
	 * @param array<string, mixed> $cfg Config.
	 * @return array{url:string,args:array<string,mixed>}
	 */
	private static function build_sendgrid( MailMessage $m, array $cfg ): array {
		$seen = array();
		$pick = static function ( array $list ) use ( &$seen ): array {
			$out = array();
			foreach ( $list as $a ) {
				$key = strtolower( $a['email'] );
				// SendGrid refuses an address that is in more than one of to / cc / bcc.
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = '' === $a['name'] ? array( 'email' => $a['email'] ) : array(
					'email' => $a['email'],
					'name'  => $a['name'],
				);
			}
			return $out;
		};
		$personalization = array( 'to' => $pick( $m->to ) );
		$cc              = $pick( $m->cc );
		$bcc             = $pick( $m->bcc );
		if ( $cc ) {
			$personalization['cc'] = $cc;
		}
		if ( $bcc ) {
			$personalization['bcc'] = $bcc;
		}

		$body = array(
			'personalizations' => array( $personalization ),
			'from'             => '' === $m->from_name ? array( 'email' => $m->from_email ) : array(
				'email' => $m->from_email,
				'name'  => $m->from_name,
			),
			'subject'          => $m->subject,
			'content'          => array(
				array(
					'type'  => $m->content_type,
					'value' => '' === $m->body ? ' ' : $m->body,
				),
			),
		);
		if ( $m->reply_to ) {
			$reply            = $m->reply_to[0];
			$body['reply_to'] = '' === $reply['name'] ? array( 'email' => $reply['email'] ) : array(
				'email' => $reply['email'],
				'name'  => $reply['name'],
			);
		}
		if ( $m->attachments ) {
			$body['attachments'] = array();
			foreach ( $m->attachments as $file ) {
				$body['attachments'][] = array(
					'content'     => base64_encode( $file['content'] ),
					'filename'    => $file['name'],
					'type'        => $file['type'],
					'disposition' => 'attachment',
				);
			}
		}

		$host = 'eu' === ( $cfg['region'] ?? '' ) ? 'api.eu.sendgrid.com' : 'api.sendgrid.com';
		return array(
			'url'  => 'https://' . $host . '/v3/mail/send',
			'args' => array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . (string) ( $cfg['api_key'] ?? '' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => self::json( $body ),
			),
		);
	}

	/* ── Mailgun ── */

	/**
	 * Mailgun `messages`. Config: api_key, domain, region (`us` or `eu`). A malformed domain gives null.
	 *
	 * @param MailMessage          $m   Message.
	 * @param array<string, mixed> $cfg Config.
	 * @return array{url:string,args:array<string,mixed>}|null
	 */
	private static function build_mailgun( MailMessage $m, array $cfg ): ?array {
		$domain = strtolower( trim( (string) ( $cfg['domain'] ?? '' ) ) );
		if ( strlen( $domain ) > 253 || ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain ) ) {
			return null;
		}
		$fields = array(
			array( 'from', MailMessage::format( array( 'email' => $m->from_email, 'name' => $m->from_name ) ) ),
			array( 'to', MailMessage::format_list( $m->to ) ),
		);
		if ( $m->cc ) {
			$fields[] = array( 'cc', MailMessage::format_list( $m->cc ) );
		}
		if ( $m->bcc ) {
			$fields[] = array( 'bcc', MailMessage::format_list( $m->bcc ) );
		}
		if ( $m->reply_to ) {
			$fields[] = array( 'h:Reply-To', MailMessage::format_list( $m->reply_to ) );
		}
		$fields[] = array( 'subject', $m->subject );
		$fields[] = array( $m->is_html() ? 'html' : 'text', $m->body );

		$boundary = 'uxs' . md5( uniqid( '', true ) );
		$eol      = "\r\n";
		$body     = '';
		foreach ( $fields as $field ) {
			$body .= '--' . $boundary . $eol . 'Content-Disposition: form-data; name="' . $field[0] . '"' . $eol . $eol . $field[1] . $eol;
		}
		foreach ( $m->attachments as $file ) {
			$name  = str_replace( array( '"', "\r", "\n" ), '', $file['name'] );
			$body .= '--' . $boundary . $eol
				. 'Content-Disposition: form-data; name="attachment"; filename="' . $name . '"' . $eol
				. 'Content-Type: ' . $file['type'] . $eol . $eol . $file['content'] . $eol;
		}
		$body .= '--' . $boundary . '--' . $eol;

		$host = 'eu' === ( $cfg['region'] ?? '' ) ? 'api.eu.mailgun.net' : 'api.mailgun.net';
		return array(
			'url'  => 'https://' . $host . '/v3/' . $domain . '/messages',
			'args' => array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( 'api:' . (string) ( $cfg['api_key'] ?? '' ) ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			),
		);
	}

	/* ── Postmark ── */

	/**
	 * Postmark `email`. Config: token (server API token), stream (message stream id, '' = the account default).
	 *
	 * @param MailMessage          $m   Message.
	 * @param array<string, mixed> $cfg Config.
	 * @return array{url:string,args:array<string,mixed>}
	 */
	private static function build_postmark( MailMessage $m, array $cfg ): array {
		$body = array(
			'From'    => MailMessage::format( array( 'email' => $m->from_email, 'name' => $m->from_name ) ),
			'To'      => MailMessage::format_list( $m->to ),
			'Subject' => $m->subject,
		);
		if ( $m->cc ) {
			$body['Cc'] = MailMessage::format_list( $m->cc );
		}
		if ( $m->bcc ) {
			$body['Bcc'] = MailMessage::format_list( $m->bcc );
		}
		if ( $m->reply_to ) {
			$body['ReplyTo'] = MailMessage::format_list( $m->reply_to );
		}
		$body[ $m->is_html() ? 'HtmlBody' : 'TextBody' ] = $m->body;
		$stream = trim( (string) ( $cfg['stream'] ?? '' ) );
		if ( '' !== $stream ) {
			$body['MessageStream'] = $stream;
		}
		if ( $m->attachments ) {
			$body['Attachments'] = array();
			foreach ( $m->attachments as $file ) {
				$body['Attachments'][] = array(
					'Name'        => $file['name'],
					'Content'     => base64_encode( $file['content'] ),
					'ContentType' => $file['type'],
				);
			}
		}
		return array(
			'url'  => 'https://api.postmarkapp.com/email',
			'args' => array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array(
					'X-Postmark-Server-Token' => (string) ( $cfg['token'] ?? '' ),
					'Accept'                  => 'application/json',
					'Content-Type'            => 'application/json',
				),
				'body'    => self::json( $body ),
			),
		);
	}

	/* ── Amazon SES ── */

	/**
	 * SES v2 `SendEmail`, signed with SigV4. Config: access_key, secret_key, region, time (optional, UTC seconds).
	 * Simple content for a message without attachments, raw MIME with them.
	 *
	 * @param MailMessage          $m   Message.
	 * @param array<string, mixed> $cfg Config.
	 * @return array{url:string,args:array<string,mixed>}|null Null for an unknown region.
	 */
	private static function build_ses( MailMessage $m, array $cfg ): ?array {
		$region = (string) ( $cfg['region'] ?? '' );
		if ( ! in_array( $region, self::SES_REGIONS, true ) ) {
			return null;
		}
		$destination = array( 'ToAddresses' => MailMessage::emails( $m->to ) );
		if ( $m->cc ) {
			$destination['CcAddresses'] = MailMessage::emails( $m->cc );
		}
		if ( $m->bcc ) {
			$destination['BccAddresses'] = MailMessage::emails( $m->bcc );
		}
		$body = array(
			'FromEmailAddress' => MailMessage::format( array( 'email' => $m->from_email, 'name' => $m->from_name ) ),
			'Destination'      => $destination,
		);
		if ( $m->reply_to ) {
			$body['ReplyToAddresses'] = MailMessage::emails( $m->reply_to );
		}
		if ( $m->attachments ) {
			$body['Content'] = array( 'Raw' => array( 'Data' => base64_encode( $m->raw_mime() ) ) );
		} else {
			$body['Content'] = array(
				'Simple' => array(
					'Subject' => array(
						'Data'    => $m->subject,
						'Charset' => 'UTF-8',
					),
					'Body'    => array(
						( $m->is_html() ? 'Html' : 'Text' ) => array(
							'Data'    => $m->body,
							'Charset' => 'UTF-8',
						),
					),
				),
			);
		}
		$payload = self::json( $body );

		$host     = 'email.' . $region . '.amazonaws.com';
		$path     = '/v2/email/outbound-emails';
		$amz_date = gmdate( 'Ymd\THis\Z', (int) ( $cfg['time'] ?? time() ) );
		$headers  = array(
			'content-type' => 'application/json',
			'host'         => $host,
			'x-amz-date'   => $amz_date,
		);
		$signed   = SigV4::sign( 'POST', $path, '', $headers, $payload, (string) ( $cfg['access_key'] ?? '' ), (string) ( $cfg['secret_key'] ?? '' ), $region, 'ses', $amz_date );

		return array(
			'url'  => 'https://' . $host . $path,
			'args' => array(
				'method'  => 'POST',
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'X-Amz-Date'    => $amz_date,
					'Authorization' => $signed['authorization'],
				),
				'body'    => $payload,
			),
		);
	}

	/**
	 * JSON the way the providers want it: UTF-8 and slashes as they are.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	private static function json( array $data ): string {
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? '{}' : $json;
	}
}
