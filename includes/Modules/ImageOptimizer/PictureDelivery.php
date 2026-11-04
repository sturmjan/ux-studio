<?php
/**
 * Next-gen (WebP/AVIF) delivery by wrapping images in a <picture> element.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Alternative to the .htaccess rewrite for nginx and CDNs: an <img> that points
 * into the uploads folder is wrapped in <picture> with an AVIF and a WebP
 * <source>. A source is only added when EVERY file of its srcset exists on disk
 * as a sibling (name.avif / name.webp), so the browser never gets a broken
 * candidate; the original <img> stays untouched as the fallback. Images outside
 * uploads, admin screens, feeds, REST and AMP are left alone.
 */
final class PictureDelivery {

	/** @var array<string, bool> Existing siblings, per request (absolute path => exists). */
	private array $exists = array();

	/** @var array<string, bool> src values already wrapped by the attachment-image filter. */
	private array $wrapped = array();

	/**
	 * Register the filters.
	 */
	public function register(): void {
		add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img' ), 20 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20 );
	}

	/**
	 * Whether the current request renders front-end HTML that may be changed.
	 */
	private function applicable(): bool {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_is_json_request() ) {
			return false;
		}
		if ( did_action( 'parse_query' ) && is_feed() ) {
			return false;
		}
		if ( ( function_exists( 'amp_is_request' ) && amp_is_request() ) || ( function_exists( 'is_amp_endpoint' ) && is_amp_endpoint() ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Filter wp_content_img_tag: wrap an image of the post content.
	 *
	 * @param mixed $tag The <img> tag.
	 * @return mixed
	 */
	public function filter_content_img( $tag ) {
		if ( ! is_string( $tag ) || ! $this->applicable() ) {
			return $tag;
		}

		// Markup of wp_get_attachment_image() that runs through the content filter again: wrapped already.
		// The mark is used up once, so a later image with the same src in the content is still wrapped.
		$src = $this->attr( $tag, 'src' );
		if ( '' !== $src && isset( $this->wrapped[ $src ] ) ) {
			unset( $this->wrapped[ $src ] );
			return $tag;
		}

		return $this->wrap( $tag );
	}

	/**
	 * Filter wp_get_attachment_image: wrap an image printed by themes/plugins
	 * (featured images, galleries, ...). Remembers the src so the content filter
	 * does not wrap the same markup a second time.
	 *
	 * @param mixed $html Output of wp_get_attachment_image().
	 * @return mixed
	 */
	public function filter_attachment_image( $html ) {
		if ( ! is_string( $html ) || ! $this->applicable() || 1 !== preg_match( '/^\s*<img\s[^>]*>\s*$/i', $html ) ) {
			return $html;
		}

		$wrapped = $this->wrap( trim( $html ) );
		if ( $wrapped !== trim( $html ) ) {
			$this->wrapped[ $this->attr( $html, 'src' ) ] = true;
		}

		return $wrapped;
	}

	/**
	 * Wrap one <img> tag when next-gen siblings exist.
	 *
	 * @param string $tag The <img> tag.
	 */
	public function wrap( string $tag ): string {
		if ( 0 !== stripos( ltrim( $tag ), '<img' ) ) {
			return $tag;
		}

		$src    = $this->attr( $tag, 'src' );
		$srcset = $this->attr( $tag, 'srcset' );
		// "auto" only means something on the lazy <img> itself; a <source> gets the fallback list.
		$sizes  = trim( (string) preg_replace( '/^\s*auto\s*(,|$)/i', '', $this->attr( $tag, 'sizes' ) ) );
		if ( '' === $src ) {
			return $tag;
		}

		$sources = '';
		foreach ( array( 'image/avif' => 'avif', 'image/webp' => 'webp' ) as $type => $extension ) {
			$set = $this->sibling_srcset( $src, $srcset, $extension );
			if ( '' === $set ) {
				continue;
			}
			$sources .= '<source type="' . esc_attr( $type ) . '" srcset="' . esc_attr( $set ) . '"'
				. ( '' !== $srcset && '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
		}

		return '' === $sources ? $tag : '<picture>' . $sources . $tag . '</picture>';
	}

	/**
	 * Value of an attribute of the first tag, entities decoded; '' when missing.
	 *
	 * @param string $tag  HTML tag.
	 * @param string $name Attribute name.
	 */
	private function attr( string $tag, string $name ): string {
		if ( 1 !== preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $tag, $m ) ) {
			return '';
		}

		return wp_specialchars_decode( trim( '' !== ( $m[1] ?? '' ) ? $m[1] : ( $m[2] ?? '' ) ), ENT_QUOTES );
	}

	/**
	 * srcset of a <source>: every candidate of the image's srcset (or just its
	 * src) mapped to the sibling file. Empty when any of them has no sibling.
	 *
	 * @param string $src       Image src.
	 * @param string $srcset    Image srcset ('' when none).
	 * @param string $extension 'avif' or 'webp'.
	 */
	private function sibling_srcset( string $src, string $srcset, string $extension ): string {
		$candidates = array();
		if ( '' === $srcset ) {
			$candidates[] = array( $src, '' );
		} else {
			foreach ( preg_split( '/,\s+/', trim( $srcset ) ) ?: array() as $part ) {
				if ( 1 !== preg_match( '/^(\S+)(?:\s+(\d+(?:\.\d+)?[wx]))?$/', trim( $part ), $m ) ) {
					return '';
				}
				$candidates[] = array( $m[1], $m[2] ?? '' );
			}
		}

		$out = array();
		foreach ( $candidates as $candidate ) {
			$url = $this->sibling_url( $candidate[0], $extension );
			if ( null === $url ) {
				return '';
			}
			$out[] = esc_url( $url ) . ( '' !== $candidate[1] ? ' ' . $candidate[1] : '' );
		}

		return implode( ', ', $out );
	}

	/**
	 * URL of the sibling of an uploads image, or null when the URL is outside
	 * uploads, is not a JPEG/PNG/GIF or the sibling does not exist on disk.
	 *
	 * @param string $url       Image URL.
	 * @param string $extension 'avif' or 'webp'.
	 */
	private function sibling_url( string $url, string $extension ): ?string {
		static $uploads = null;
		if ( null === $uploads ) {
			$dir     = wp_get_upload_dir();
			$real    = empty( $dir['basedir'] ) ? false : realpath( $dir['basedir'] );
			$uploads = array(
				'url' => preg_replace( '#^https?:#i', '', untrailingslashit( (string) ( $dir['baseurl'] ?? '' ) ) ),
				'dir' => false === $real ? '' : wp_normalize_path( $real ),
			);
		}

		$clean = (string) preg_replace( '/[?#].*$/', '', $url );
		$plain = (string) preg_replace( '#^https?:#i', '', $clean );
		if ( '' === $uploads['dir'] || '' === $uploads['url'] || 0 !== strpos( $plain, $uploads['url'] . '/' ) || 1 !== preg_match( '/\.(jpe?g|png|gif)$/i', $plain ) ) {
			return null;
		}

		$relative = rawurldecode( substr( $plain, strlen( $uploads['url'] ) + 1 ) );
		if ( false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '\\' ) ) {
			return null;
		}

		$path = $uploads['dir'] . '/' . (string) preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $extension, $relative );
		if ( ! isset( $this->exists[ $path ] ) ) {
			$real                  = is_file( $path ) ? realpath( $path ) : false;
			$this->exists[ $path ] = false !== $real && 0 === strpos( wp_normalize_path( $real ), $uploads['dir'] . '/' );
		}

		return $this->exists[ $path ] ? (string) preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $extension, $clean ) : null;
	}
}
