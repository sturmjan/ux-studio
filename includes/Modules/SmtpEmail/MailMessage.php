<?php
/**
 * One outgoing message in a form the API mailers can use.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SmtpEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Built from the arguments of `wp_mail()` the way WordPress builds its own message: recipients (also "Name <address>"),
 * the headers From / Cc / Bcc / Reply-To / Content-Type, the content type filter and the From filters, and the
 * attachments (file paths, read into memory).
 */
final class MailMessage {

	/** Upper bound of one attachment read into memory (bytes). Larger files are refused by every provider anyway. */
	public const MAX_ATTACHMENT = 25 * 1024 * 1024;

	/** @var array<int, array{email:string,name:string}> */
	public array $to = array();

	/** @var array<int, array{email:string,name:string}> */
	public array $cc = array();

	/** @var array<int, array{email:string,name:string}> */
	public array $bcc = array();

	/** @var array<int, array{email:string,name:string}> */
	public array $reply_to = array();

	public string $from_email = '';

	public string $from_name = '';

	public string $subject = '';

	public string $body = '';

	/** `text/html` or `text/plain`. */
	public string $content_type = 'text/plain';

	/** @var array<int, array{name:string,type:string,content:string}> */
	public array $attachments = array();

	public function is_html(): bool {
		return 'text/html' === $this->content_type;
	}

	/**
	 * Build the message from the arguments of wp_mail().
	 *
	 * @param array<string, mixed> $atts to, subject, message, headers, attachments.
	 * @return self|null Null when there is no valid recipient.
	 */
	public static function from_atts( array $atts ): ?self {
		$m          = new self();
		$m->subject = (string) ( $atts['subject'] ?? '' );
		$m->body    = (string) ( $atts['message'] ?? '' );
		$m->to      = self::parse_addresses( $atts['to'] ?? '' );

		$content_type = '';
		$from_email   = '';
		$from_name    = '';
		foreach ( self::header_lines( $atts['headers'] ?? '' ) as $line ) {
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$value                = trim( $value );
			switch ( strtolower( trim( $name ) ) ) {
				case 'content-type':
					if ( preg_match( '/^([^;]+)/', $value, $found ) ) {
						$content_type = strtolower( trim( $found[1] ) );
					}
					break;
				case 'from':
					$from = self::parse_addresses( $value );
					if ( $from ) {
						$from_email = $from[0]['email'];
						$from_name  = $from[0]['name'];
					}
					break;
				case 'cc':
					$m->cc = array_merge( $m->cc, self::parse_addresses( $value ) );
					break;
				case 'bcc':
					$m->bcc = array_merge( $m->bcc, self::parse_addresses( $value ) );
					break;
				case 'reply-to':
					$m->reply_to = array_merge( $m->reply_to, self::parse_addresses( $value ) );
					break;
			}
		}

		// The same defaults and filters WordPress applies in wp_mail() (it applies them AFTER the headers).
		if ( '' === $from_email ) {
			$from_email = self::default_from_email();
		}
		if ( '' === $from_name ) {
			$from_name = 'WordPress';
		}
		$m->from_email = (string) self::filter( 'wp_mail_from', $from_email );
		$m->from_name  = (string) self::filter( 'wp_mail_from_name', $from_name );

		$type            = (string) self::filter( 'wp_mail_content_type', '' !== $content_type ? $content_type : 'text/plain' );
		$m->content_type = 0 === stripos( $type, 'text/html' ) ? 'text/html' : 'text/plain';

		foreach ( self::attachment_files( $atts['attachments'] ?? array() ) as $name => $path ) {
			$size = filesize( $path );
			if ( false === $size || $size > self::MAX_ATTACHMENT ) {
				continue;
			}
			$content = file_get_contents( $path );
			if ( false === $content ) {
				continue;
			}
			$m->attachments[] = array(
				'name'    => (string) $name,
				'type'    => self::mime_type( $path ),
				'content' => $content,
			);
		}

		return array() === $m->to || '' === $m->from_email ? null : $m;
	}

	/**
	 * Addresses of a header value or of the `to` argument.
	 *
	 * @param string|array<int, string> $value One string (comma separated) or a list.
	 * @return array<int, array{email:string,name:string}> Only valid addresses.
	 */
	public static function parse_addresses( $value ): array {
		$parts = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $one ) {
			$one = (string) $one;
			// A comma inside quotes belongs to the name.
			foreach ( preg_split( '/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $one ) ?: array() as $piece ) {
				$parts[] = trim( $piece );
			}
		}
		$out = array();
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$name  = '';
			$email = $part;
			if ( preg_match( '/^(.*?)<([^>]+)>\s*$/', $part, $found ) ) {
				$name  = trim( $found[1], " \t\"'" );
				$email = trim( $found[2] );
			}
			$valid = function_exists( 'is_email' ) ? false !== is_email( $email ) : false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
			if ( $valid ) {
				$out[] = array(
					'email' => $email,
					'name'  => $name,
				);
			}
		}
		return $out;
	}

	/**
	 * `"Name" <address>` or the bare address.
	 *
	 * @param array{email:string,name:string} $address Address.
	 */
	public static function format( array $address ): string {
		if ( '' === $address['name'] ) {
			return $address['email'];
		}
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $address['name'] ) . '" <' . $address['email'] . '>';
	}

	/**
	 * The addresses of a list, formatted, comma separated.
	 *
	 * @param array<int, array{email:string,name:string}> $list Addresses.
	 */
	public static function format_list( array $list ): string {
		return implode( ', ', array_map( array( self::class, 'format' ), $list ) );
	}

	/**
	 * Only the bare addresses.
	 *
	 * @param array<int, array{email:string,name:string}> $list Addresses.
	 * @return string[]
	 */
	public static function emails( array $list ): array {
		return array_values( array_map( static fn( array $a ): string => $a['email'], $list ) );
	}

	/**
	 * Raw RFC 822 message (UTF-8, base64 bodies). Bcc is NOT a header: the provider gets the bcc list separately.
	 */
	public function raw_mime(): string {
		$eol     = "\r\n";
		$head    = array();
		$head[]  = 'From: ' . self::encode_address( array( 'email' => $this->from_email, 'name' => $this->from_name ) );
		$head[]  = 'To: ' . implode( ', ', array_map( array( self::class, 'encode_address' ), $this->to ) );
		if ( $this->cc ) {
			$head[] = 'Cc: ' . implode( ', ', array_map( array( self::class, 'encode_address' ), $this->cc ) );
		}
		if ( $this->reply_to ) {
			$head[] = 'Reply-To: ' . implode( ', ', array_map( array( self::class, 'encode_address' ), $this->reply_to ) );
		}
		$head[] = 'Subject: ' . self::encode_header( $this->subject );
		$head[] = 'MIME-Version: 1.0';

		$body = rtrim( chunk_split( base64_encode( $this->body ), 76, $eol ) );
		if ( ! $this->attachments ) {
			$head[] = 'Content-Type: ' . $this->content_type . '; charset=UTF-8';
			$head[] = 'Content-Transfer-Encoding: base64';
			return implode( $eol, $head ) . $eol . $eol . $body;
		}

		$boundary = 'uxsmixed_' . md5( uniqid( '', true ) );
		$head[]   = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
		$parts    = array(
			'--' . $boundary . $eol
			. 'Content-Type: ' . $this->content_type . '; charset=UTF-8' . $eol
			. 'Content-Transfer-Encoding: base64' . $eol . $eol . $body,
		);
		foreach ( $this->attachments as $file ) {
			$name    = self::encode_header( $file['name'] );
			$parts[] = '--' . $boundary . $eol
				. 'Content-Type: ' . $file['type'] . '; name="' . $name . '"' . $eol
				. 'Content-Transfer-Encoding: base64' . $eol
				. 'Content-Disposition: attachment; filename="' . $name . '"' . $eol . $eol
				. rtrim( chunk_split( base64_encode( $file['content'] ), 76, $eol ) );
		}
		return implode( $eol, $head ) . $eol . $eol . implode( $eol, $parts ) . $eol . '--' . $boundary . '--';
	}

	/**
	 * Header value with non-ASCII text as an RFC 2047 word.
	 *
	 * @param string $text Text.
	 */
	private static function encode_header( string $text ): string {
		$text = str_replace( array( "\r", "\n" ), ' ', $text );
		if ( ! preg_match( '/[^\x20-\x7E]/', $text ) ) {
			return $text;
		}
		return '=?UTF-8?B?' . base64_encode( $text ) . '?=';
	}

	/**
	 * Address as a header value.
	 *
	 * @param array{email:string,name:string} $address Address.
	 */
	private static function encode_address( array $address ): string {
		if ( '' === $address['name'] ) {
			return $address['email'];
		}
		$name = self::encode_header( $address['name'] );
		if ( $name === $address['name'] ) {
			$name = '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $name ) . '"';
		}
		return $name . ' <' . $address['email'] . '>';
	}

	/**
	 * Header lines of the `headers` argument (string or list).
	 *
	 * @param string|array<int|string, string> $headers Headers.
	 * @return string[]
	 */
	private static function header_lines( $headers ): array {
		if ( is_string( $headers ) ) {
			return '' === trim( $headers ) ? array() : (array) preg_split( "/\r\n|\n|\r/", $headers );
		}
		$out = array();
		foreach ( is_array( $headers ) ? $headers : array() as $name => $value ) {
			$out[] = is_int( $name ) ? (string) $value : $name . ': ' . $value;
		}
		return $out;
	}

	/**
	 * Readable attachment files, keyed by the name the recipient sees.
	 *
	 * @param string|array<int|string, string> $attachments wp_mail() argument (paths, or name => path).
	 * @return array<string, string>
	 */
	private static function attachment_files( $attachments ): array {
		$out = array();
		foreach ( is_array( $attachments ) ? $attachments : explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) ) as $name => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$out[ is_string( $name ) && '' !== $name ? $name : basename( $path ) ] = $path;
		}
		return $out;
	}

	private static function mime_type( string $path ): string {
		$map = array(
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'zip'  => 'application/zip',
			'csv'  => 'text/csv',
			'txt'  => 'text/plain',
			'ics'  => 'text/calendar',
		);
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$found = mime_content_type( $path );
			if ( is_string( $found ) && '' !== $found ) {
				return $found;
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * WordPress' default sender address (wordpress@host).
	 */
	private static function default_from_email(): string {
		$url  = function_exists( 'network_home_url' ) ? network_home_url() : ( function_exists( 'home_url' ) ? home_url() : '' );
		$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return 'wordpress@' . ( '' !== $host ? $host : 'localhost' );
	}

	/**
	 * apply_filters() where WordPress is loaded.
	 *
	 * @param string $hook  Filter.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function filter( string $hook, $value ) {
		return function_exists( 'apply_filters' ) ? apply_filters( $hook, $value ) : $value;
	}
}
