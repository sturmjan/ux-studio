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
	 * @param int $max_bytes Max file size in bytes.
	 */
	public function __construct( int $max_bytes = 10485760 ) {
		$this->max_bytes = $max_bytes > 0 ? $max_bytes : 10485760;
		$this->site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
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

		$content = preg_replace_callback(
			'#<img\b([^>]*)\bsrc=(["\'])([^"\']+)\2([^>]*)>#i',
			function ( $m ) use ( &$map, &$processed, $post_id, $max_images ) {
				$before = $m[1];
				$quote  = $m[2];
				$src    = $m[3];
				$after  = $m[4];

				if ( ! $this->is_external( $src ) ) {
					return $m[0];
				}

				if ( $processed >= $max_images ) {
					return $m[0];
				}

				$key = md5( $src );
				if ( isset( $map[ $key ] ) ) {
					$new_url = $map[ $key ];
				} else {
					$result = $this->sideload( $src, $post_id );
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

				return '<img ' . trim( $combined ) . ' src=' . $quote . esc_url( $new_url ) . $quote . '>';
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
	 * @return array|WP_Error { id, url, source_url } on success.
	 */
	public function sideload( string $url, int $post_id = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$result = $this->fetch_to_temp_file( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$file_array = array(
			'name'     => $result['name'],
			'tmp_name' => $result['path'],
		);

		$filetype = wp_check_filetype( $file_array['name'] );
		if ( empty( $filetype['ext'] ) || ! in_array( strtolower( $filetype['ext'] ), self::$allowed_ext, true ) ) {
			@unlink( $file_array['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new WP_Error( 'invalid_type', __( 'The file is not an allowed image format.', 'ux-studio' ) );
		}

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $file_array['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $attachment_id;
		}

		$new_url = wp_get_attachment_url( $attachment_id );
		update_post_meta( $attachment_id, self::SOURCE_META_KEY, esc_url_raw( $url ) );

		return array(
			'id'         => (int) $attachment_id,
			'url'        => $new_url,
			'source_url' => $url,
		);
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
