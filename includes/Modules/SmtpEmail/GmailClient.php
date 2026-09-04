<?php
/**
 * Thin client over the Google OAuth token endpoint and the Gmail API (send).
 *
 * Ported from the legacy smtp-email module. Sending runs directly from the site
 * through the site owner's own Google OAuth application (no intermediary), with
 * token persistence handled by the owning Module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helper: builds URLs / RFC 822 messages and talks to Google over HTTPS.
 */
final class GmailClient {

	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const SEND_URL  = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

	/** Scopes: openid+email to learn the account address, gmail.send to send. */
	private const SCOPE = 'openid email https://www.googleapis.com/auth/gmail.send';

	/**
	 * Build the Google authorize URL.
	 */
	public static function build_auth_url( string $client_id, string $redirect_uri, string $state ): string {
		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => $state,
		);
		return self::AUTH_URL . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @return array<string, mixed>|null Decoded response (access_token, expires_in, refresh_token?, id_token) or null.
	 */
	public static function exchange_code( string $code, string $client_id, string $client_secret, string $redirect_uri ): ?array {
		return self::post_form(
			array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			)
		);
	}

	/**
	 * Refresh an access token from a refresh token.
	 *
	 * @return array<string, mixed>|null (access_token, expires_in) or null.
	 */
	public static function refresh_access_token( string $refresh_token, string $client_id, string $client_secret ): ?array {
		return self::post_form(
			array(
				'refresh_token' => $refresh_token,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
			)
		);
	}

	/**
	 * Send a prepared RFC 822 message through the Gmail API.
	 *
	 * @return array{ok:bool,id?:string,error?:string,http?:int}
	 */
	public static function send_raw( string $access_token, string $raw_message ): array {
		$raw_b64_url = rtrim( strtr( base64_encode( $raw_message ), '+/', '-_' ), '=' );
		$body        = wp_json_encode( array( 'raw' => $raw_b64_url ) );

		$response = wp_remote_post(
			self::SEND_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => $response->get_error_message(),
				'http'  => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $data ) && ! empty( $data['id'] ) ) {
			return array(
				'ok'   => true,
				'id'   => (string) $data['id'],
				'http' => 200,
			);
		}

		$err_msg = is_array( $data ) && isset( $data['error'] )
			? ( is_array( $data['error'] ) ? ( $data['error']['message'] ?? wp_json_encode( $data['error'] ) ) : (string) $data['error'] )
			: ( 'HTTP ' . $code );

		return array(
			'ok'    => false,
			'error' => (string) $err_msg,
			'http'  => $code,
		);
	}

	/**
	 * Extract email + name from an id_token (JWT payload; channel verified via HTTPS).
	 *
	 * @return array{email:string,name:string}
	 */
	public static function identity_from_id_token( string $id_token ): array {
		$parts = explode( '.', $id_token );
		if ( 3 !== count( $parts ) ) {
			return array(
				'email' => '',
				'name'  => '',
			);
		}
		$payload = base64_decode( strtr( $parts[1], '-_', '+/' ), true );
		$data    = false !== $payload ? json_decode( $payload, true ) : null;
		if ( ! is_array( $data ) ) {
			return array(
				'email' => '',
				'name'  => '',
			);
		}
		return array(
			'email' => (string) ( $data['email'] ?? '' ),
			'name'  => (string) ( $data['name'] ?? '' ),
		);
	}

	/**
	 * Build an RFC 822 message (CRLF, UTF-8, base64 body).
	 *
	 * @param string[]                                                        $to          Recipients.
	 * @param array{cc?:string[],bcc?:string[],reply_to?:string}              $opts        Optional headers.
	 * @param string[]                                                        $attachments Absolute paths to attachment files.
	 */
	public static function build_raw_message(
		string $from_email,
		string $from_name,
		array $to,
		string $subject,
		string $body,
		string $content_type = 'text/html',
		array $opts = array(),
		array $attachments = array()
	): string {
		$eol = "\r\n";

		$from = '' !== $from_name
			? mb_encode_mimeheader( $from_name, 'UTF-8', 'B', $eol ) . ' <' . $from_email . '>'
			: $from_email;

		$headers   = array();
		$headers[] = 'From: ' . $from;
		$headers[] = 'To: ' . implode( ', ', array_filter( array_map( 'trim', $to ) ) );

		if ( ! empty( $opts['cc'] ) ) {
			$headers[] = 'Cc: ' . implode( ', ', array_filter( array_map( 'trim', (array) $opts['cc'] ) ) );
		}
		if ( ! empty( $opts['bcc'] ) ) {
			$headers[] = 'Bcc: ' . implode( ', ', array_filter( array_map( 'trim', (array) $opts['bcc'] ) ) );
		}
		if ( ! empty( $opts['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . trim( (string) $opts['reply_to'] );
		}

		$headers[] = 'Subject: ' . mb_encode_mimeheader( $subject, 'UTF-8', 'B', $eol );
		$headers[] = 'MIME-Version: 1.0';

		// Keep only attachments that actually exist and are readable.
		$files = array();
		foreach ( $attachments as $path ) {
			if ( is_string( $path ) && '' !== $path && is_file( $path ) && is_readable( $path ) ) {
				$files[] = $path;
			}
		}

		$encoded_body = rtrim( chunk_split( base64_encode( $body ), 76, $eol ) );

		// No attachments: simple single-part message.
		if ( empty( $files ) ) {
			$headers[] = 'Content-Type: ' . $content_type . '; charset=UTF-8';
			$headers[] = 'Content-Transfer-Encoding: base64';
			return implode( $eol, $headers ) . $eol . $eol . $encoded_body;
		}

		// With attachments: multipart/mixed (body + files).
		$boundary  = 'uxsmixed_' . md5( uniqid( '', true ) );
		$headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

		$mime_parts = array();

		$mime_parts[] = '--' . $boundary . $eol
			. 'Content-Type: ' . $content_type . '; charset=UTF-8' . $eol
			. 'Content-Transfer-Encoding: base64' . $eol . $eol
			. $encoded_body;

		foreach ( $files as $path ) {
			$data = file_get_contents( $path );
			if ( false === $data ) {
				continue;
			}

			$filename    = basename( $path );
			$header_name = preg_match( '/[^\x20-\x7E]/', $filename )
				? mb_encode_mimeheader( $filename, 'UTF-8', 'B', $eol )
				: $filename;
			$mime         = self::detect_mime_type( $path );
			$encoded_file = rtrim( chunk_split( base64_encode( $data ), 76, $eol ) );

			$mime_parts[] = '--' . $boundary . $eol
				. 'Content-Type: ' . $mime . '; name="' . $header_name . '"' . $eol
				. 'Content-Transfer-Encoding: base64' . $eol
				. 'Content-Disposition: attachment; filename="' . $header_name . '"' . $eol . $eol
				. $encoded_file;
		}

		$mime_body = implode( $eol, $mime_parts ) . $eol . '--' . $boundary . '--';

		return implode( $eol, $headers ) . $eol . $eol . $mime_body;
	}

	/**
	 * Guess an attachment MIME type by extension, falling back to mime_content_type().
	 */
	private static function detect_mime_type( string $path ): string {
		$map = array(
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
			'zip'  => 'application/zip',
			'csv'  => 'text/csv',
			'txt'  => 'text/plain',
			'ics'  => 'text/calendar',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		);

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$detected = @mime_content_type( $path );
			if ( is_string( $detected ) && '' !== $detected ) {
				return $detected;
			}
		}

		return 'application/octet-stream';
	}

	/**
	 * Internal: POST application/x-www-form-urlencoded to the token endpoint.
	 *
	 * @param array<string, string> $fields Form fields.
	 * @return array<string, mixed>|null
	 */
	private static function post_form( array $fields ): ?array {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				'body'    => $fields,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : null;
	}
}
