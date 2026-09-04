<?php
/**
 * Next-gen (WebP/AVIF) delivery via an uploads-scoped .htaccess rewrite.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ImageOptimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Writes/removes a marker-delimited block in wp-content/uploads/.htaccess that
 * serves a pre-generated .avif/.webp sibling when the browser advertises
 * support and the file exists on disk. All writes go through WordPress'
 * insert_with_markers() (which only ever replaces the region between our own
 * BEGIN/END markers, so existing rules are never clobbered) and are hard-gated
 * to the uploads directory - the target path is realpath-verified to sit inside
 * wp_upload_dir()['basedir'] before any write, so a misconfiguration can never
 * touch the site-root .htaccess. On Apache the rules take effect immediately;
 * on nginx .htaccess is ignored (see status()['server_note']).
 */
final class HtaccessDelivery {

	private const MARKER    = 'UX Studio Image Optimizer';
	private const LOCK      = 'uxstudio_io_htaccess_lock';
	private const LOCK_TIME = 30;

	/**
	 * Absolute path to the uploads .htaccess, or a WP_Error-free empty string if
	 * the uploads dir cannot be resolved safely.
	 */
	private function htaccess_path(): string {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$real_base = realpath( $uploads['basedir'] );
		if ( false === $real_base ) {
			return '';
		}
		return $real_base . '/.htaccess';
	}

	/**
	 * Confirm the resolved .htaccess path really lives inside uploads. When the
	 * file does not yet exist we validate its parent directory instead.
	 *
	 * @param string $path Candidate path.
	 */
	private function is_safe_target( string $path ): bool {
		if ( '' === $path ) {
			return false;
		}
		$real_base = realpath( wp_upload_dir()['basedir'] );
		if ( false === $real_base ) {
			return false;
		}
		$check = is_file( $path ) ? realpath( $path ) : realpath( dirname( $path ) );
		if ( false === $check ) {
			return false;
		}
		return 0 === strpos( $check, $real_base );
	}

	/**
	 * Write (or refresh) the delivery rules. Enabled formats are passed in so
	 * the block only advertises what we actually generate.
	 *
	 * @param bool $webp Serve .webp siblings.
	 * @param bool $avif Serve .avif siblings.
	 * @return bool True on success.
	 */
	public function write( bool $webp, bool $avif ): bool {
		if ( ! $webp && ! $avif ) {
			return $this->remove();
		}

		$path = $this->htaccess_path();
		if ( ! $this->is_safe_target( $path ) ) {
			return false;
		}

		// Serialize concurrent writers to avoid a torn .htaccess.
		if ( false !== get_transient( self::LOCK ) ) {
			return $this->is_active();
		}
		set_transient( self::LOCK, time(), self::LOCK_TIME );

		try {
			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once ABSPATH . 'wp-admin/includes/misc.php';
			}
			return (bool) insert_with_markers( $path, self::MARKER, $this->rules( $webp, $avif ) );
		} finally {
			delete_transient( self::LOCK );
		}
	}

	/**
	 * Remove our marker block, leaving any other rules intact.
	 */
	public function remove(): bool {
		$path = $this->htaccess_path();
		if ( '' === $path || ! is_file( $path ) ) {
			return true;
		}
		if ( ! $this->is_safe_target( $path ) ) {
			return false;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		return (bool) insert_with_markers( $path, self::MARKER, array() );
	}

	/**
	 * Whether our marker block is currently present in uploads/.htaccess.
	 */
	public function is_active(): bool {
		$path = $this->htaccess_path();
		if ( '' === $path || ! is_file( $path ) || ! $this->is_safe_target( $path ) ) {
			return false;
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_string( $content ) && false !== strpos( $content, self::MARKER );
	}

	/**
	 * Delivery status for the SPA.
	 *
	 * @return array{active:bool,writable:bool,path:string,server_note:string}
	 */
	public function status(): array {
		$path     = $this->htaccess_path();
		$uploads  = wp_upload_dir();
		$dir_ok   = ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );
		$file_ok  = '' === $path || ! is_file( $path ) || is_writable( $path );
		$is_apache = false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'apache' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			|| false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'litespeed' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		return array(
			'active'      => $this->is_active(),
			'writable'    => $dir_ok && $file_ok,
			'path'        => $path,
			'server_note' => $is_apache
				? ''
				: __( 'The web server does not look like Apache/LiteSpeed; .htaccess rules may be ignored (e.g. on nginx). Configure next-gen delivery at the server level instead.', 'ux-studio' ),
		);
	}

	/**
	 * Build the rewrite rules as an array of lines for insert_with_markers().
	 * AVIF is preferred over WebP (smaller) and therefore matched first; the
	 * [L] flag stops further rewriting once a match is served.
	 *
	 * @param bool $webp Include WebP rules.
	 * @param bool $avif Include AVIF rules.
	 * @return string[]
	 */
	private function rules( bool $webp, bool $avif ): array {
		$lines = array();

		$lines[] = '<IfModule mod_rewrite.c>';
		$lines[] = '  RewriteEngine On';

		if ( $avif ) {
			$lines[] = '  # Serve AVIF when the browser supports it and a sibling exists';
			$lines[] = '  RewriteCond %{HTTP_ACCEPT} image/avif';
			$lines[] = '  RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png|gif)$ [NC]';
			$lines[] = '  RewriteCond %1.avif -f';
			$lines[] = '  RewriteRule (.+)\.(jpe?g|png|gif)$ $1.avif [T=image/avif,L]';
		}

		if ( $webp ) {
			$lines[] = '  # Serve WebP when the browser supports it and a sibling exists';
			$lines[] = '  RewriteCond %{HTTP_ACCEPT} image/webp';
			$lines[] = '  RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png|gif)$ [NC]';
			$lines[] = '  RewriteCond %1.webp -f';
			$lines[] = '  RewriteRule (.+)\.(jpe?g|png|gif)$ $1.webp [T=image/webp,L]';
		}

		$lines[] = '</IfModule>';

		$lines[] = '<IfModule mod_headers.c>';
		$lines[] = '  <FilesMatch "\.(jpe?g|png|gif)$">';
		$lines[] = '    Header append Vary Accept';
		$lines[] = '  </FilesMatch>';
		$lines[] = '</IfModule>';

		$lines[] = '<IfModule mod_mime.c>';
		if ( $avif ) {
			$lines[] = '  AddType image/avif .avif';
		}
		if ( $webp ) {
			$lines[] = '  AddType image/webp .webp';
		}
		$lines[] = '</IfModule>';

		return $lines;
	}
}
