<?php
/**
 * .htaccess hardening rules writer (canonical URL, protocols, headers, files).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Writes/removes a single, clearly-marked block of rules into the site's
 * .htaccess using WordPress's own insert_with_markers() (never a full
 * rewrite of the file, and never touches anything outside the marker).
 *
 * This is a deliberate, user-approved exception to the "never write to
 * .htaccess" rule elsewhere in this project - see task briefing.
 */
final class HtaccessWriter {

	public const MARKER      = 'UX Studio Security';
	public const HASH_OPTION = 'uxstudio_security_htaccess_hash';

	/**
	 * Build the rule lines for the current settings (used both to write and
	 * to compute the comparison hash).
	 *
	 * @param array $settings Module settings array.
	 * @return string[] Rule lines (no markers - insert_with_markers adds those).
	 */
	public function build_rules( array $settings ): array {
		$rules = array();

		if ( ! empty( $settings['canonical_redirect'] ) ) {
			$canonical = $this->detect_canonical_url();
			$rules[]   = '# Canonical URL';
			$rules[]   = 'RewriteEngine On';

			if ( $canonical['uses_https'] ) {
				$rules[] = '';
				$rules[] = 'RewriteCond %{HTTPS} off';
				$rules[] = 'RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]';
			}

			if ( $canonical['uses_www'] ) {
				$rules[] = '';
				$rules[] = 'RewriteCond %{HTTP_HOST} !^www\. [NC]';
				$rules[] = 'RewriteRule ^(.*)$ ' . ( $canonical['uses_https'] ? 'https' : 'http' ) . '://www.%{HTTP_HOST}%{REQUEST_URI} [L,R=301]';
			} else {
				$rules[] = '';
				$rules[] = 'RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]';
				$rules[] = 'RewriteRule ^(.*)$ ' . ( $canonical['uses_https'] ? 'https' : 'http' ) . '://%1%{REQUEST_URI} [L,R=301]';
			}
		} elseif ( ! empty( $settings['force_https'] ) ) {
			$rules[] = '# Force HTTPS';
			$rules[] = 'RewriteEngine On';
			$rules[] = 'RewriteCond %{HTTPS} off';
			$rules[] = 'RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]';
		}

		if ( ! empty( $settings['disable_directory_listing'] ) ) {
			$rules[] = '';
			$rules[] = 'Options -Indexes';
		}

		if ( ! empty( $settings['protect_wp_config'] ) ) {
			$rules[] = '';
			$rules[] = '<files wp-config.php>';
			$rules[] = 'order allow,deny';
			$rules[] = 'deny from all';
			$rules[] = '</files>';
		}

		if ( ! empty( $settings['block_sensitive_files'] ) ) {
			$rules[] = '';
			$rules[] = '<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php)$">';
			$rules[] = 'order allow,deny';
			$rules[] = 'deny from all';
			$rules[] = '</FilesMatch>';
		}

		if ( ! empty( $settings['security_headers'] ) ) {
			$rules[] = '';
			$rules[] = '<IfModule mod_headers.c>';
			$rules[] = 'Header always set X-Frame-Options "SAMEORIGIN"';
			$rules[] = 'Header always set X-Content-Type-Options "nosniff"';
			$rules[] = 'Header always set Referrer-Policy "strict-origin-when-cross-origin"';
			if ( ! empty( $settings['enable_hsts'] ) ) {
				$rules[] = 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"';
			}
			$rules[] = '</IfModule>';
		}

		return $rules;
	}

	/**
	 * Hash used to detect drift between the last applied config and the
	 * currently stored settings (mirrors the legacy ux1_security_htaccess_hash).
	 */
	public function compute_hash( array $settings ): string {
		return md5( wp_json_encode( $this->build_rules( $settings ) ) . home_url() );
	}

	public function stored_hash(): string {
		return (string) get_option( self::HASH_OPTION, '' );
	}

	public function is_applied( array $settings ): bool {
		$stored = $this->stored_hash();
		return '' !== $stored && $stored === $this->compute_hash( $settings );
	}

	/**
	 * Write the current rules into .htaccess and store the new hash.
	 *
	 * @return true|\WP_Error
	 */
	public function apply( array $settings ) {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$htaccess = get_home_path() . '.htaccess';
		if ( ! is_writable( $htaccess ) && ! is_writable( dirname( $htaccess ) ) ) {
			return new \WP_Error( 'uxstudio_htaccess_not_writable', __( '.htaccess is not writable.', 'ux-studio' ) );
		}

		$rules = $this->build_rules( $settings );
		insert_with_markers( $htaccess, self::MARKER, $rules );
		update_option( self::HASH_OPTION, $this->compute_hash( $settings ), true );

		return true;
	}

	/**
	 * Canonical URL detection based on the WordPress site address setting.
	 *
	 * @return array{uses_https:bool,uses_www:bool}
	 */
	public function detect_canonical_url(): array {
		$home = home_url();
		return array(
			'uses_https' => 0 === strpos( $home, 'https://' ),
			'uses_www'   => (bool) preg_match( '#^https?://www\.#i', $home ),
		);
	}
}
