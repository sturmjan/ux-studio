<?php
/**
 * Runtime execution of enabled code snippets.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Includes PHP snippet files and prints non-PHP snippets on the appropriate
 * WordPress hooks. Every PHP snippet is re-verified (path containment +
 * hash_equals() integrity check) immediately before its file is include()'d,
 * independently of any check already done when the Snippet entity was
 * hydrated - this is the actual security boundary for code execution.
 */
final class SnippetExecutor {

	private ?SnippetManager $snippetManager = null;
	private Settings $settings;
	private bool $safeMode = false;
	private array $processedSnippets = array();
	private array $snippetQueue = array();

	/**
	 * @param Settings $settings Module settings (used for the persisted safe-mode default).
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;

		// In wp-admin, only users who could manage snippets anyway get to run
		// them; on the frontend snippets must run for anonymous visitors too.
		if ( is_admin() && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$this->snippetManager = new SnippetManager();
		$this->safeMode       = $this->isSafeMode();
		$this->init();
	}

	/**
	 * Resolve the current safe-mode state.
	 *
	 * Safe mode's persisted default lives in module settings (replacing the
	 * legacy wp-config.php constant with a UI-editable toggle - functionally
	 * equivalent, no weaker). Admins can additionally flip it on/off for
	 * their own session via a nonce-verified query param, stored in a cookie.
	 */
	private function isSafeMode(): bool {
		$persisted_default = (bool) $this->settings->get( 'safe_mode', false );

		if ( ! current_user_can( 'manage_options' ) ) {
			if ( isset( $_COOKIE['uxstudio_safe_mode'] ) && '1' === $_COOKIE['uxstudio_safe_mode'] ) {
				return true;
			}
			return $persisted_default;
		}

		if ( isset( $_GET['uxstudio_safe_mode'] ) && '1' === $_GET['uxstudio_safe_mode'] ) {
			setcookie( 'uxstudio_safe_mode', '1', time() + ( 30 * DAY_IN_SECONDS ), '/', '', is_ssl(), true );
			return true;
		}

		if ( isset( $_GET['uxstudio_safe_mode'] ) && '0' === $_GET['uxstudio_safe_mode'] ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'uxstudio_safe_mode' ) ) {
				return $persisted_default;
			}

			setcookie( 'uxstudio_safe_mode', '', time() - HOUR_IN_SECONDS, '/', '', is_ssl(), true );
			return false;
		}

		if ( isset( $_COOKIE['uxstudio_safe_mode'] ) && '1' === $_COOKIE['uxstudio_safe_mode'] ) {
			return true;
		}

		return $persisted_default;
	}

	/**
	 * Initialize execution for the current request.
	 */
	private function init(): void {
		if ( $this->safeMode ) {
			$this->displaySafeModeNotice();
			return;
		}

		if ( $this->shouldSkipExecution() ) {
			return;
		}

		$this->loadPhpSnippets();
		$this->processNonPhpSnippets();
	}

	/**
	 * Skip execution during REST/AJAX/CRON requests, to avoid unintended
	 * output being mixed into API responses or background jobs.
	 */
	private function shouldSkipExecution(): bool {
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

	/**
	 * Include enabled PHP snippets early so they can register their own hooks.
	 */
	public function loadPhpSnippets(): void {
		foreach ( $this->snippetManager->getAllSnippets() as $snippet ) {
			if ( ! $snippet->isEnabled() || ! $snippet->canExecute() ) {
				continue;
			}
			if ( 'php' === $snippet->getType() ) {
				$this->includePhpSnippet( $snippet );
			}
		}
	}

	/**
	 * Queue enabled non-PHP snippets for output on their appropriate hooks.
	 */
	private function processNonPhpSnippets(): void {
		foreach ( $this->snippetManager->getAllSnippets() as $snippet ) {
			if ( ! $snippet->isEnabled() || ! $snippet->canExecute() ) {
				continue;
			}
			if ( 'php' !== $snippet->getType() ) {
				$this->processNonPhpSnippet( $snippet );
			}
		}
		$this->registerQueuedSnippetHooks();
	}

	/**
	 * Detect a REST API request even before REST_REQUEST is defined.
	 */
	private function isRestApiRequest(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wp-json/' ) ) {
			return true;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_ACCEPT'] ) && false !== strpos( $_SERVER['HTTP_ACCEPT'], 'application/json' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Include a single PHP snippet file, re-verifying path containment and
	 * the integrity hash immediately beforehand. This check is independent
	 * of whatever SnippetManager already did when hydrating the entity.
	 */
	private function includePhpSnippet( Snippet $snippet ): void {
		$file_path = $snippet->getFilePath();
		if ( ! $file_path || ! $this->isSnippetFileValid( $file_path ) ) {
			return;
		}

		if ( ! $snippet->verifyIntegrity() ) {
			return;
		}

		$real_path   = realpath( $file_path );
		$allowed_dir = realpath( WP_CONTENT_DIR . '/uxstudio-snippets' );
		if ( false === $real_path || false === $allowed_dir || 0 !== strpos( $real_path, $allowed_dir ) ) {
			return;
		}

		$location       = $snippet->getRunLocation();
		$should_execute = true;
		switch ( $location ) {
			case 'admin_only':
				$should_execute = is_admin();
				break;
			case 'frontend_only':
				$should_execute = ! is_admin();
				break;
			case 'everywhere':
			default:
				$should_execute = true;
				break;
		}
		if ( ! $should_execute ) {
			return;
		}

		try {
			if ( $this->isRestApiRequest() ) {
				ob_start();
			}
			include $file_path;
			if ( $this->isRestApiRequest() ) {
				ob_end_clean();
			}
		} catch ( \Throwable $e ) {
			if ( $this->isRestApiRequest() && ob_get_level() > 0 ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Extract and enqueue a non-PHP snippet for later output on its hook.
	 */
	private function processNonPhpSnippet( Snippet $snippet ): void {
		$id = $snippet->getId();
		if ( isset( $this->processedSnippets[ $id ] ) ) {
			return;
		}

		$file_path = $snippet->getFilePath();
		if ( ! $file_path || ! file_exists( $file_path ) || ! $this->isSnippetFileValid( $file_path ) ) {
			return;
		}

		if ( ! $snippet->verifyIntegrity() ) {
			return;
		}

		$content        = file_get_contents( $file_path );
		$extracted_code = false === $content ? false : $this->extractCode( $content, $snippet->getType() );
		if ( false === $extracted_code || '' === $extracted_code ) {
			return;
		}

		$hook = $this->getHookForSnippet( $snippet );
		if ( ! $hook ) {
			return;
		}

		if ( ! isset( $this->snippetQueue[ $hook ] ) ) {
			$this->snippetQueue[ $hook ] = array();
		}
		$this->snippetQueue[ $hook ][] = array(
			'snippet' => $snippet,
			'code'    => $extracted_code,
		);
		$this->processedSnippets[ $id ] = true;
	}

	/**
	 * Extract code from a non-PHP snippet file's content (strip generated
	 * header + wrapper tags). Mirrors SnippetManager::extractCode() - kept
	 * separate here since the executor never touches the DB layer.
	 */
	private function extractCode( string $content, string $type ) {
		$content = trim( $content );
		if ( in_array( $type, array( 'html', 'js', 'css' ), true ) ) {
			$content = preg_replace( '/<\?php.*?\?>\s*/s', '', $content );
		}

		switch ( $type ) {
			case 'js':
				if ( preg_match( '/<script[^>]*>(.*?)<\/script>/is', (string) $content, $matches ) ) {
					return trim( $matches[1] );
				}
				return (string) $content;
			case 'css':
				if ( preg_match( '/<style[^>]*>(.*?)<\/style>/is', (string) $content, $matches ) ) {
					return trim( $matches[1] );
				}
				return (string) $content;
			case 'html':
				return (string) $content;
			default:
				return false;
		}
	}

	/**
	 * Register one output callback per hook that has queued snippets.
	 */
	private function registerQueuedSnippetHooks(): void {
		foreach ( $this->snippetQueue as $hook => $snippets ) {
			add_action(
				$hook,
				function () use ( $hook ) {
					$this->outputQueuedSnippets( $hook );
				},
				10
			);
		}
	}

	/**
	 * Output all snippets queued for a given hook.
	 */
	private function outputQueuedSnippets( string $hook ): void {
		if ( ! isset( $this->snippetQueue[ $hook ] ) ) {
			return;
		}
		foreach ( $this->snippetQueue[ $hook ] as $item ) {
			$this->outputSnippet( $item['snippet'], $item['code'] );
		}
	}

	/**
	 * Echo a snippet's extracted code wrapped for its type.
	 */
	private function outputSnippet( Snippet $snippet, string $code ): void {
		switch ( strtolower( $snippet->getType() ) ) {
			case 'js':
				printf( '<script>%s</script>', "\n" . $code . "\n" );
				break;
			case 'css':
				printf( '<style>%s</style>', "\n" . $code . "\n" );
				break;
			case 'html':
				echo "\n" . $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional: user-authored HTML snippet, gated behind manage_options at creation time.
				break;
		}
	}

	/**
	 * Ensure a snippet file resolves within the allowed snippets directory.
	 */
	private function isSnippetFileValid( string $filePath ): bool {
		$allowed_dir = realpath( WP_CONTENT_DIR . '/uxstudio-snippets' );
		$real_path   = realpath( $filePath );
		if ( false === $real_path || false === $allowed_dir ) {
			return false;
		}
		return 0 === strpos( $real_path, $allowed_dir );
	}

	/**
	 * Map a snippet's run_location to the WordPress hook it should print on.
	 */
	private function getHookForSnippet( Snippet $snippet ): ?string {
		$location     = $snippet->getRunLocation();
		$location_map = array(
			'site_header'    => 'wp_head',
			'site_body_open' => 'wp_body_open',
			'site_footer'    => 'wp_footer',
			'admin_header'   => 'admin_head',
			'admin_footer'   => 'admin_footer',
			'everywhere'     => is_admin() ? 'admin_head' : 'wp_head',
			'frontend_only'  => 'wp_head',
			'admin_only'     => 'admin_head',
		);
		return $location_map[ $location ] ?? null;
	}

	/**
	 * Admin-only warning banner while safe mode is active.
	 */
	public function displaySafeModeNotice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! is_admin() ) {
			return;
		}

		$current_url = remove_query_arg( array( 'uxstudio_safe_mode', '_wpnonce' ) );
		$disable_url = add_query_arg(
			array(
				'uxstudio_safe_mode' => '0',
				'_wpnonce'           => wp_create_nonce( 'uxstudio_safe_mode' ),
			),
			$current_url
		);

		add_action(
			'admin_notices',
			static function () use ( $disable_url ): void {
				printf(
					'<div class="notice notice-warning"><p>%s</p></div>',
					wp_kses_post(
						sprintf(
							/* translators: %s: disable safe mode URL */
							__( 'UX Studio is running in safe mode. Code snippets are disabled. <a href="%s">Disable Safe Mode</a>', 'ux-studio' ),
							esc_url( $disable_url )
						)
					)
				);
			}
		);
	}

	public function isSafeModeEnabled(): bool {
		return $this->safeMode;
	}
}
