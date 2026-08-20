<?php
/**
 * Code Snippets module - run user-authored PHP/HTML/CSS/JS on the site.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * THE MOST SECURITY-CRITICAL MODULE IN THE PLUGIN: it runs user-supplied PHP
 * on the server. Ported 1:1 from the legacy ux1 code-snippets module, which
 * this rewrite deliberately does NOT try to "improve" on the security model:
 *
 *  - Snippets are stored as FILES under wp-content/uxstudio-snippets/, never
 *    as a DB blob fed to eval(). The directory carries an index.php +
 *    .htaccess (Deny from all / Require all denied) to block direct HTTP
 *    access to the files.
 *  - Every file carries a sha256 integrity hash of its code, salted with
 *    wp_salt('auth'), verified with hash_equals() immediately before every
 *    include() (see SnippetExecutor::includePhpSnippet()).
 *  - Every file operation re-checks realpath() containment inside the
 *    snippets directory.
 *  - PhpValidator (token-based, no eval()) must pass before any PHP snippet
 *    is written to disk.
 *  - Execution is skipped during REST/AJAX/CRON requests.
 *  - A safe-mode kill switch (cookie + nonce-verified query param, plus a
 *    persisted default in module settings) can disable all snippet execution.
 *  - Every route requires manage_options - there is no lower tier here.
 *
 * The one deliberate structural deviation from the legacy module: snippet
 * METADATA (title/type/enabled/run_location) now lives in a proper DB table
 * (`uxstudio_code_snippets`) instead of being parsed back out of the file's
 * docblock header. This is a hardening, not a weakening - it means a file
 * edited outside the application (e.g. over FTP) can no longer flip a
 * snippet to "enabled" or change where it runs merely by editing the header,
 * since that header is no longer trusted for anything but human-readability.
 * The code integrity hash (the actual execution gate) is unaffected.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		DB::ensure_module_tables(
			'code-snippets',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_code_snippets (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						title VARCHAR(191) NOT NULL DEFAULT '',
						type VARCHAR(20) NOT NULL DEFAULT 'php',
						run_location VARCHAR(50) NOT NULL DEFAULT '',
						enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
						file_path VARCHAR(500) NOT NULL DEFAULT '',
						created_at DATETIME NOT NULL,
						updated_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						KEY enabled (enabled)
					) {$charset};"
				);
			}
		);

		// Same request-context guard as the legacy Bootstrap: skip entirely
		// before ever touching the filesystem/DB for REST/AJAX/CRON requests.
		// SnippetExecutor performs the same check again internally.
		if ( ! $this->shouldSkipSnippetExecution() ) {
			new SnippetExecutor( $this->settings );
		}
	}

	/**
	 * Register the module REST controller.
	 */
	public function register_rest_routes(): void {
		( new RestController() )->register_routes();
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Settings schema for the generic SPA settings renderer / embedded tab.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'safe_mode',
				'type'    => 'toggle',
				'label'   => __( 'Safe mode', 'ux-studio' ),
				'help'    => __( 'When enabled, no code snippets are executed on the site. Use this to recover from a broken snippet.', 'ux-studio' ),
				'default' => false,
			),
		);
	}

	/**
	 * Whether snippet execution should be skipped for the current request.
	 */
	private function shouldSkipSnippetExecution(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}
		return false;
	}
}
