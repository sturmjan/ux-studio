<?php
/**
 * Auto Image Upload processor (ported from the legacy module).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AutoImageUpload;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared logic for sideloading external images - used by the REST endpoint, the
 * save-time content filter, and the bulk fix tool. Includes SSRF protections:
 * only http/https, redirect re-validation via wp_safe_remote_get, and blocking
 * of private/reserved IP ranges.
 */
final class Processor {

	private const URL_MAP_OPTION = 'uxstudio_auto_image_upload_url_map';
	private const MAP_MAX_ENTRIES = 2000;
	private const SOURCE_META_KEY = '_uxstudio_auto_image_source_url';

	/** @var int Max file size in bytes. */
	private int $max_bytes;

	/** @var string[] Allowed image extensions. */
	private static array $allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif' );

	/** @var string Site host (used to detect external URLs). */
	private string $site_host;

	/**
	 * Options (PLAN.md 27.10): base_url ('' = the address WordPress gives), file_pattern, alt_pattern, max_width, max_height
	 * (0 = no limit) and excluded_domains (list of hosts, `*.example.com` for subdomains).
	 *
	 * @var array{base_url:string,file_pattern:string,alt_pattern:string,max_width:int,max_height:int,excluded_domains:string[]}
	 */
	private array $opts;

	/**
	 * @param int                  $max_bytes Max file size in bytes.
	 * @param array<string, mixed> $opts      Options, see above.
	 */
	public function __construct( int $max_bytes = 10485760, array $opts = array() ) {
		$this->max_bytes = $max_bytes > 0 ? $max_bytes : 10485760;
		$this->site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$this->opts      = array(
			'base_url'         => trim( (string) ( $opts['base_url'] ?? '' ) ),
			'file_pattern'     => '' !== trim( (string) ( $opts['file_pattern'] ?? '' ) ) ? trim( (string) $opts['file_pattern'] ) : '%filename%',
			'alt_pattern'      => '' !== trim( (string) ( $opts['alt_pattern'] ?? '' ) ) ? trim( (string) $opts['alt_pattern'] ) : '%image_alt%',
			'max_width'        => max( 0, (int) ( $opts['max_width'] ?? 0 ) ),
			'max_height'       => max( 0, (int) ( $opts['max_height'] ?? 0 ) ),
			'excluded_domains' => array_values( array_filter( array_map( 'strval', (array) ( $opts['excluded_domains'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Process post content: find external <img> tags and replace their src with
	 * locally-hosted URLs by sideloading. Returns modified content.
	 *
	 * @param string $content    Post content.
	 * @param int    $post_id    Post ID.
	 * @param int    $max_images Maximum images to process in this pass.
	 * @return string
	 */
	public function process_content( string $content, int $post_id = 0, int $max_images = 10 ): string {
		if ( false === stripos( $content, '<img' ) ) {
			return $content;
		}

		$map       = $this->load_url_map();
		$processed = 0;
		$post      = $this->post_context( $post_id );

		$content = preg_replace_callback(
			'#<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)>#i',
			function ( $m ) use ( &$map, &$processed, $post_id, $max_images, $post ) {
				$before = $m[1];
				$quote  = $m[2];
				$src    = $m[3];
				$after  = $m[4];

				if ( ! $this->is_external( $src ) || $this->is_excluded( $src ) ) {
					return $m[0];
				}

				if ( $processed >= $max_images ) {
					return $m[0];
				}

				// The alt text the tag had, for the %image_alt% placeholder of the pattern.
				$alt_before = preg_match( '#\balt=(["\'])([^"\']*)\1#i', $m[1] . ' ' . $m[4], $alt_match ) ? html_entity_decode( $alt_match[2], ENT_QUOTES ) : '';
				$alt_new    = trim( self::resolve_pattern( $this->opts['alt_pattern'], $this->pattern_context( $src, $alt_before, $post ) ) );

				$key = md5( $src );
				if ( isset( $map[ $key ] ) ) {
					$new_url = $map[ $key ];
				} else {
					$result = $this->sideload( $src, $post_id, $alt_before );
					if ( is_wp_error( $result ) || empty( $result['url'] ) ) {
						return $m[0];
					}
					$new_url     = $result['url'];
					$map[ $key ] = $new_url;
					++$processed;
				}

				// Strip srcset/sizes (they point at the external variants).
				$combined = $before . ' ' . $after;
				$combined = preg_replace( '#\s+srcset=(["\'])[^"\']*\1#i', '', $combined );
				$combined = preg_replace( '#\s+sizes=(["\'])[^"\']*\1#i', '', $combined );
				$combined = preg_replace( '#\s+data-lazy-srcset=(["\'])[^"\']*\1#i', '', $combined );
				$combined = preg_replace( '#\s+data-src=(["\'])[^"\']*\1#i', '', $combined );
				$combined = rtrim( $combined );
				if ( '' !== $alt_new && $alt_new !== $alt_before ) {
					$alt_attr = ' alt="' . esc_attr( $alt_new ) . '"';
					$combined = preg_match( '#\salt=(["\'])[^"\']*\1#i', $combined )
						? (string) preg_replace( '#\salt=(["\'])[^"\']*\1#i', $alt_attr, $combined, 1 )
						: $combined . $alt_attr;
				}

				return '<img ' . trim( $combined ) . ' src=' . $quote . esc_url( $this->public_url( $new_url ) ) . $quote . '>';
			},
			$content
		);

		if ( $processed > 0 ) {
			$this->save_url_map( $map );
		}

		return $content;
	}

	/**
	 * Determine whether a URL points at an external image.
	 *
	 * @param string $src Image URL.
	 */
	public function is_external( string $src ): bool {
		if ( '' === $src ) {
			return false;
		}
		if ( 0 === stripos( $src, 'data:image/' ) ) {
			return true;
		}
		if ( 0 === stripos( $src, 'blob:' ) ) {
			return false;
		}
		if ( 0 === stripos( $src, 'file:' ) ) {
			return false;
		}
		if ( '/' === $src[0] ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		return $host !== $this->site_host;
	}

	/**
	 * Sideload a single URL (or data: URI) into the media library.
	 *
	 * @param string $url     Source URL.
	 * @param int    $post_id Post ID to attach to.
	 * @param string $alt     Alt text the image had where it was found (for the alt pattern).
	 * @return array|WP_Error { id, url, source_url } on success.
	 */
	public function sideload( string $url, int $post_id = 0, string $alt = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( $this->is_excluded( $url ) ) {
			return new WP_Error( 'excluded_domain', __( 'Images from this domain are excluded.', 'ux-studio' ) );
		}

		$result = $this->fetch_to_temp_file( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post     = $this->post_context( $post_id );
		$ctx      = $this->pattern_context( $url, $alt, $post );
		$new_name = self::file_name( $this->opts['file_pattern'], $result['name'], $ctx );
		$alt_text = trim( self::resolve_pattern( $this->opts['alt_pattern'], $ctx ) );

		$resized = $this->resize_file( $result['path'], $new_name );
		if ( null !== $resized ) {
			$result['path'] = $resized;
		}

		$file_array = array(
			'name'     => $new_name,
			'tmp_name' => $result['path'],
		);

		$filetype = wp_check_filetype( $file_array['name'] );
		if ( empty( $filetype['ext'] ) || ! in_array( strtolower( $filetype['ext'] ), self::$allowed_ext, true ) ) {
			@unlink( $file_array['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'invalid_type', __( 'The file is not an allowed image format.', 'ux-studio' ) );
		}

		$post_data     = '' !== $alt_text ? array( 'post_title' => $alt_text ) : array();
		$attachment_id = media_handle_sideload( $file_array, $post_id, null, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_array['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		$new_url = wp_get_attachment_url( $attachment_id );
		update_post_meta( $attachment_id, self::SOURCE_META_KEY, esc_url_raw( $url ) );
		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		return array(
			'id'         => (int) $attachment_id,
			'url'        => $new_url,
			'source_url' => $url,
		);
	}

	/**
	 * The address an uploaded image is written into the content with: the base address setting replaces scheme and host of
	 * what WordPress gives (a CDN in front of the uploads folder), `/` leaves only the path.
	 *
	 * @param string $url Address of the attachment.
	 */
	public function public_url( string $url ): string {
		$base = $this->opts['base_url'];
		if ( '' === $base ) {
			return $url;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$qs   = wp_parse_url( $url, PHP_URL_QUERY );
		if ( '' === $path ) {
			return $url;
		}
		if ( '/' === $base ) {
			return $path . ( $qs ? '?' . $qs : '' );
		}
		if ( ! preg_match( '#^https?://[^/\s]+#i', $base ) ) {
			return $url;
		}
		return rtrim( $base, '/' ) . $path . ( $qs ? '?' . $qs : '' );
	}

	/**
	 * Is the host of an address on the exclusion list?
	 *
	 * @param string $url Image address.
	 */
	public function is_excluded( string $url ): bool {
		if ( array() === $this->opts['excluded_domains'] || 0 === stripos( $url, 'data:' ) ) {
			return false;
		}
		$host = self::bare_host( $url );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $this->opts['excluded_domains'] as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( 0 === strpos( $entry, '*.' ) ) {
				$suffix = substr( $entry, 1 );
				if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
				continue;
			}
			if ( self::bare_host( $entry ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Host of an address (or of a bare domain) without `www.`, lower case, with a port when there is one.
	 *
	 * @param string $url Address or domain.
	 */
	public static function bare_host( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) || 0 === strpos( $url, '//' ) ? $url : '//' . $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $host ) {
			return '';
		}
		$host = (string) preg_replace( '/^www\d?\./', '', $host );
		return isset( $parts['port'] ) ? $host . ':' . $parts['port'] : $host;
	}

	/**
	 * What the %post_*% placeholders need.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id:int,name:string,date:int}
	 */
	private function post_context( int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$gmt  = $post && ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ? strtotime( $post->post_date_gmt . ' UTC' ) : 0;
		return array(
			'id'   => $post_id,
			'name' => $post ? (string) $post->post_name : '',
			'date' => $gmt ? (int) $gmt : 0,
		);
	}

	/**
	 * Values for the placeholders of a pattern.
	 *
	 * @param string                                  $url  Source address of the image.
	 * @param string                                  $alt  Alt text the image had.
	 * @param array{id:int,name:string,date:int}      $post Post values.
	 * @return array<string, mixed>
	 */
	private function pattern_context( string $url, string $alt, array $post ): array {
		$path = 0 === stripos( $url, 'data:' ) ? '' : (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = '' !== $path ? pathinfo( $path, PATHINFO_FILENAME ) : '';
		return array(
			'filename'  => sanitize_file_name( $name ),
			'alt'       => $alt,
			'host'      => self::bare_host( home_url() ),
			'post_id'   => $post['id'],
			'post_name' => $post['name'],
			'post_date' => $post['date'],
			'now'       => time(),
		);
	}

	/**
	 * Fill in the placeholders of a pattern. Same placeholders as Auto Upload Images (so a migrated pattern keeps working):
	 * %filename% %image_alt% %date% %today_date% %year% %month% %day% %today_day% %post_date% %post_year% %post_month%
	 * %post_day% %url% %random% %timestamp% %post_id% %postname%. An unknown %word% stays as it is. Plain str_replace.
	 *
	 * @param string               $pattern Pattern.
	 * @param array<string, mixed> $ctx     filename, alt, host, post_id, post_name, post_date (timestamp, 0 = now), now.
	 */
	public static function resolve_pattern( string $pattern, array $ctx ): string {
		$now  = (int) ( $ctx['now'] ?? time() );
		$post = (int) ( $ctx['post_date'] ?? 0 ) ?: $now;
		$map  = array(
			'%filename%'   => (string) ( $ctx['filename'] ?? '' ),
			'%image_alt%'  => (string) ( $ctx['alt'] ?? '' ),
			'%date%'       => gmdate( 'Y-m-j', $now ),
			'%today_date%' => gmdate( 'Y-m-j', $now ),
			'%year%'       => gmdate( 'Y', $now ),
			'%month%'      => gmdate( 'm', $now ),
			'%day%'        => gmdate( 'j', $now ),
			'%today_day%'  => gmdate( 'j', $now ),
			'%post_date%'  => gmdate( 'Y-m-j', $post ),
			'%post_year%'  => gmdate( 'Y', $post ),
			'%post_month%' => gmdate( 'm', $post ),
			'%post_day%'   => gmdate( 'j', $post ),
			'%url%'        => (string) ( $ctx['host'] ?? '' ),
			'%random%'     => uniqid( 'img_', false ),
			'%timestamp%'  => (string) $now,
			'%post_id%'    => (string) (int) ( $ctx['post_id'] ?? 0 ),
			'%postname%'   => (string) ( $ctx['post_name'] ?? '' ),
		);
		return strtr( $pattern, $map );
	}

	/**
	 * The file name an image gets: the pattern with the extension of the downloaded file. The default pattern keeps the name.
	 *
	 * @param string               $pattern  File name pattern.
	 * @param string               $original Name of the downloaded file (with extension).
	 * @param array<string, mixed> $ctx      Placeholder values.
	 */
	public static function file_name( string $pattern, string $original, array $ctx ): string {
		if ( '%filename%' === trim( $pattern ) ) {
			return $original;
		}
		$ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		if ( '' === (string) ( $ctx['filename'] ?? '' ) ) {
			// The address says nothing about a file name (a pasted image, a script that serves images): use the generated one.
			$ctx['filename'] = pathinfo( $original, PATHINFO_FILENAME );
		}
		$base = trim( self::resolve_pattern( $pattern, $ctx ) );
		$base = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( $base ) : $base;
		$base = (string) preg_replace( '/\.(jpe?g|png|gif|webp|svg|bmp|avif)$/i', '', $base );
		if ( '' === $base || '-' === $base ) {
			$base = uniqid( 'img_', false );
		}
		return $base . ( '' !== $ext ? '.' . $ext : '' );
	}

	/**
	 * Size an image has to be scaled to so it fits in the box, keeping its proportions; null when it already fits (or no box).
	 *
	 * @param int $width      Image width.
	 * @param int $height     Image height.
	 * @param int $max_width  Largest width, 0 = no limit.
	 * @param int $max_height Largest height, 0 = no limit.
	 * @return array{0:int,1:int}|null
	 */
	public static function fit_size( int $width, int $height, int $max_width, int $max_height ): ?array {
		if ( $width < 1 || $height < 1 || ( $max_width < 1 && $max_height < 1 ) ) {
			return null;
		}
		$ratio = 1.0;
		if ( $max_width > 0 && $width > $max_width ) {
			$ratio = min( $ratio, $max_width / $width );
		}
		if ( $max_height > 0 && $height > $max_height ) {
			$ratio = min( $ratio, $max_height / $height );
		}
		if ( $ratio >= 1.0 ) {
			return null;
		}
		return array( max( 1, (int) round( $width * $ratio ) ), max( 1, (int) round( $height * $ratio ) ) );
	}

	/**
	 * Scale the downloaded file down to the size limit (never up). The original size is not kept.
	 *
	 * @param string $path File on disk (no extension).
	 * @param string $name Name the file will have (the extension tells the format).
	 * @return string|null Path of the resized file, null when nothing was done.
	 */
	private function resize_file( string $path, string $name ): ?string {
		if ( $this->opts['max_width'] < 1 && $this->opts['max_height'] < 1 ) {
			return null;
		}
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'svg', 'gif' ), true ) || ! function_exists( 'wp_get_image_editor' ) ) {
			return null; // Vector images need no scaling and a GIF would lose its frames.
		}
		$target = $path . '.' . $ext;
		if ( ! @copy( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}
		$editor = wp_get_image_editor( $target );
		if ( is_wp_error( $editor ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}
		$size = $editor->get_size();
		$fit  = self::fit_size( (int) ( $size['width'] ?? 0 ), (int) ( $size['height'] ?? 0 ), $this->opts['max_width'], $this->opts['max_height'] );
		if ( null === $fit || is_wp_error( $editor->resize( $fit[0], $fit[1], false ) ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}
		$saved = $editor->save( $target );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return null;
		}
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return (string) $saved['path'];
	}

	/**
	 * Download the given URL (http/https or data: URI) to a temp file.
	 *
	 * @param string $url Source URL.
	 * @return array|WP_Error { name, path } on success.
	 */
	private function fetch_to_temp_file( string $url ) {
		if ( 0 === strpos( $url, 'data:' ) ) {
			if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', $url, $m ) ) {
				return new WP_Error( 'invalid_data_uri', __( 'Invalid data: URL.', 'ux-studio' ) );
			}

			$mime = strtolower( $m[1] );
			$data = base64_decode( $m[2], true );
			if ( false === $data ) {
				return new WP_Error( 'invalid_base64', __( 'The image could not be decoded.', 'ux-studio' ) );
			}
			if ( strlen( $data ) > $this->max_bytes ) {
				return new WP_Error( 'too_large', __( 'The image exceeds the maximum allowed size.', 'ux-studio' ) );
			}

			$ext = $this->ext_from_mime( $mime );
			if ( ! $ext ) {
				return new WP_Error( 'invalid_mime', __( 'Unsupported image type.', 'ux-studio' ) );
			}

			$tmp = wp_tempnam( 'uxstudio-paste-' . $ext );
			if ( ! $tmp ) {
				return new WP_Error( 'tmp_failed', __( 'Could not create a temporary file.', 'ux-studio' ) );
			}
			if ( false === file_put_contents( $tmp, $data ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return new WP_Error( 'write_failed', __( 'Could not write the temporary file.', 'ux-studio' ) );
			}

			return array(
				'name' => 'pasted-image-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext,
				'path' => $tmp,
			);
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'unsupported_scheme', __( 'Unsupported URL scheme.', 'ux-studio' ) );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || $this->is_blocked_host( $host ) ) {
			return new WP_Error( 'blocked_host', __( 'This target is not allowed.', 'ux-studio' ) );
		}

		// SSRF: use wp_safe_remote_get with reject_unsafe_urls so WP re-validates the
		// URL and each redirect target against private/reserved ranges.
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 30,
				'redirection'         => 3,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => $this->max_bytes + 1024,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'fetch_failed', __( 'Downloading the image failed.', 'ux-studio' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > $this->max_bytes ) {
			return new WP_Error( 'too_large', __( 'The image is empty or exceeds the maximum allowed size.', 'ux-studio' ) );
		}

		$tmp_file = wp_tempnam( $url );
		if ( ! $tmp_file || false === file_put_contents( $tmp_file, $body ) ) {
			if ( $tmp_file ) {
				@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return new WP_Error( 'write_failed', __( 'Could not write the temporary file.', 'ux-studio' ) );
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = $path ? wp_basename( $path ) : '';
		$ext  = $base ? strtolower( pathinfo( $base, PATHINFO_EXTENSION ) ) : '';

		// Some CDNs append odd extensions; trust the mime type when the extension is unknown.
		if ( ! $ext || ! in_array( $ext, self::$allowed_ext, true ) ) {
			$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp_file ) : '';
			$ext  = $this->ext_from_mime( (string) $mime ) ?: 'jpg';
			$base = 'external-image-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) . '.' . $ext;
		}

		return array(
			'name' => sanitize_file_name( $base ),
			'path' => $tmp_file,
		);
	}

	/**
	 * Map an image mime type to a file extension.
	 *
	 * @param string $mime Mime type.
	 */
	private function ext_from_mime( string $mime ): string {
		$map = array(
			'image/jpeg'    => 'jpg',
			'image/jpg'     => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/svg+xml' => 'svg',
			'image/bmp'     => 'bmp',
			'image/avif'    => 'avif',
		);
		return $map[ strtolower( $mime ) ] ?? '';
	}

	/**
	 * SSRF denylist. Returns true (block) when the host resolves to an internal/
	 * private/reserved target, or cannot be resolved at all. Fail-closed.
	 *
	 * @param string $host Hostname or IP.
	 */
	private function is_blocked_host( string $host ): bool {
		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}

		$ips = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$a = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $a ) ) {
				$ips = array_merge( $ips, $a );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $aaaa ) ) {
					foreach ( $aaaa as $rec ) {
						if ( ! empty( $rec['ipv6'] ) ) {
							$ips[] = $rec['ipv6'];
						}
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			return true; // Cannot verify -> block.
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Load the URL mapping cache.
	 *
	 * @return array
	 */
	private function load_url_map(): array {
		$map = get_option( self::URL_MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Persist the URL mapping cache (evicting oldest entries beyond the cap).
	 *
	 * @param array $map Map.
	 */
	private function save_url_map( array $map ): void {
		if ( count( $map ) > self::MAP_MAX_ENTRIES ) {
			$map = array_slice( $map, -self::MAP_MAX_ENTRIES, null, true );
		}
		update_option( self::URL_MAP_OPTION, $map, false );
	}
}
