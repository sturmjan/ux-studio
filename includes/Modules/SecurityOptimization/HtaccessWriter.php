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
				$rules[] = 'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [L,R=301]';
			}

			if ( $canonical['uses_www'] ) {
				$rules[] = '';
				$rules[] = 'RewriteCond %{HTTP_HOST} !^www\. [NC]';
				$rules[] = 'RewriteRule ^(.*)$ ' . ( $canonical['uses_https'] ? 'https' : 'http' ) . '://www.%{HTTP_HOST}/$1 [L,R=301]';
			} else {
				$rules[] = '';
				$rules[] = 'RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]';
				$rules[] = 'RewriteRule ^(.*)$ ' . ( $canonical['uses_https'] ? 'https' : 'http' ) . '://%1/$1 [L,R=301]';
			}
		} elseif ( ! empty( $settings['force_https'] ) ) {
			$rules[] = '# Force HTTPS';
			$rules[] = 'RewriteEngine On';
			$rules[] = 'RewriteCond %{HTTPS} off';
			$rules[] = 'RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [L,R=301]';
		}

		if ( ! empty( $settings['disable_directory_listing'] ) ) {
			$rules[] = '';
			$rules[] = 'Options -Indexes';
		}

		if ( ! empty( $settings['protect_wp_config'] ) ) {
			$rules[] = '';
			$rules[] = '<Files wp-config.php>';
			array_push( $rules, ...self::deny_lines() );
			$rules[] = '</Files>';
			// Backups/copies left by editors or tools (wp-config.php.bak, .old, .save~ ...)
			// are served as plain text by Apache - block them too.
			$rules[] = '<FilesMatch "(?i)^\.?wp-config(\.php.+|.*\.(bak|bk|backup|old|orig|save|swp|swo|tmp|txt|zip|7z|rar|tar|gz|tgz|bz2|sql)|.*~)$">';
			array_push( $rules, ...self::deny_lines() );
			$rules[] = '</FilesMatch>';
		}

		if ( ! empty( $settings['block_sensitive_files'] ) ) {
			$rules[] = '';
			$rules[] = '<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php)$">';
			array_push( $rules, ...self::deny_lines() );
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
	 * "Nobody may open this" for both Apache generations. Apache 2.4 without mod_access_compat answers 500 to the old
	 * `Order`/`Deny` directives (the WHOLE site then fails), so each syntax sits in the module check of its own.
	 *
	 * @return string[]
	 */
	public static function deny_lines(): array {
		return array(
			'<IfModule mod_authz_core.c>',
			'Require all denied',
			'</IfModule>',
			'<IfModule !mod_authz_core.c>',
			'Order allow,deny',
			'Deny from all',
			'</IfModule>',
		);
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
	 * Does the block in `.htaccess` already hold exactly the rules these settings produce? (Comment lines
	 * WordPress adds inside the block do not count.) Lets the migration switch leave the file untouched
	 * when it changes nothing.
	 *
	 * @param array<string, mixed> $settings Settings the rules are built from.
	 */
	public function matches_file( array $settings ): bool {
		if ( ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$htaccess = get_home_path() . '.htaccess';
		if ( ! is_file( $htaccess ) || ! is_readable( $htaccess ) ) {
			return false;
		}
		$clean = static fn( array $lines ): array => array_values(
			array_filter(
				array_map( 'trim', $lines ),
				static fn( string $line ): bool => '' !== $line && '#' !== $line[0]
			)
		);
		return $clean( extract_from_markers( $htaccess, self::MARKER ) ) === $clean( $this->build_rules( $settings ) );
	}

	/**
	 * Store the hash of the rules that are in the file already.
	 *
	 * @param array<string, mixed> $settings Settings the rules were built from.
	 */
	public function remember( array $settings ): void {
		update_option( self::HASH_OPTION, $this->compute_hash( $settings ), true );
	}

	/**
	 * Write the current rules into .htaccess and store the new hash.
	 *
	 * @return true|\WP_Error
	 */
	public function apply( array $settings ) {
		// A safe-preview request persists nothing.
		if ( \UxStudio\Core\Migration\Preview::is_active() ) {
			return new \WP_Error( 'uxstudio_preview_active', __( 'The .htaccess file is not written during a preview.', 'ux-studio' ) );
		}
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
		if ( array() === $rules ) {
			// Nothing to write: leave no empty marker block (and its WordPress comment lines) behind.
			$this->remove_block_from_file( $htaccess );
		} else {
			insert_with_markers( $htaccess, self::MARKER, $rules );
		}
		update_option( self::HASH_OPTION, $this->compute_hash( $settings ), true );

		return true;
	}

	/**
	 * Remove our marker block (module switched off / plugin deactivated) and
	 * forget the hash, so re-enabling writes the rules again. No-op when the
	 * file is missing or unwritable.
	 */
	public function remove(): void {
		if ( \UxStudio\Core\Migration\Preview::is_active() ) {
			return;
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		delete_option( self::HASH_OPTION );

		$htaccess = get_home_path() . '.htaccess';
		if ( ! is_file( $htaccess ) || ! is_writable( $htaccess ) ) {
			return;
		}
		$this->remove_block_from_file( $htaccess );
	}

	/**
	 * Take our whole marked section out of the file (markers and the comment lines WordPress adds included).
	 * Does not touch the file when the section is not in it.
	 *
	 * @param string $path Absolute path of the .htaccess file.
	 */
	private function remove_block_from_file( string $path ): void {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return;
		}
		$clean = self::strip_block( $content );
		if ( $clean !== $content ) {
			file_put_contents( $path, $clean, LOCK_EX );
		}
	}

	/**
	 * Pure: file content without our `# BEGIN ... # END ...` section. A blank line that only separated the
	 * section from the rest is dropped with it, so a file the section was appended to comes back as it was
	 * (a trailing newline may differ). Content without the section is returned unchanged.
	 *
	 * @param string $content .htaccess content.
	 */
	public static function strip_block( string $content ): string {
		$begin = '# BEGIN ' . self::MARKER;
		$end   = '# END ' . self::MARKER;
		$lines = preg_split( '/\R/', $content );
		$out   = array();
		$skip  = false;
		$at    = -1;
		foreach ( $lines as $line ) {
			$trim = trim( $line );
			if ( ! $skip && -1 === $at && $trim === $begin ) {
				$skip = true;
				$at   = count( $out );
				continue;
			}
			if ( $skip ) {
				if ( $trim === $end ) {
					$skip = false;
				}
				continue;
			}
			$out[] = $line;
		}
		if ( -1 === $at || $skip ) {
			return $content;
		}
		// A blank line that only separated the removed section from its neighbour goes with it.
		if ( $at > 0 && '' === trim( $out[ $at - 1 ] ) && ( ! isset( $out[ $at ] ) || '' === trim( $out[ $at ] ) ) ) {
			array_splice( $out, $at - 1, 1 );
		}
		$text = rtrim( implode( "\n", $out ) );
		return '' === $text ? '' : $text . "\n";
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
