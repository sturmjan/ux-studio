<?php
/**
 * Snippets that produce output inside the page: the [uxstudio_snippet] shortcode and the placement before / after the
 * post content (PLAN.md 27, A2).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\CodeSnippets;

use UxStudio\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Same safety model as SnippetExecutor, nothing weaker:
 *
 *  - a snippet is rendered only when it is enabled, valid, its file lies inside the snippets folder and its integrity
 *    hash still matches (re-checked right before an include);
 *  - the safe mode kill switch silences everything;
 *  - PHP is NEVER executed in a REST, AJAX or cron request (rendering `the_content` there, e.g. for the block editor
 *    preview, shows nothing for a PHP snippet), and in wp-admin only for users who may manage snippets;
 *    HTML, CSS and JavaScript only print stored text, so they render everywhere a shortcode is expanded;
 *  - a snippet takes part only in the way its run location says: shortcode snippets only through the shortcode,
 *    before / after content snippets only around the main post content, never in a hook or by the shortcode;
 *  - a snippet cannot render itself (nested shortcode / content filter): recursion and depth are capped.
 */
final class SnippetRenderer {

	public const SHORTCODE = 'uxstudio_snippet';

	/** Deepest nesting of snippets that render other snippets. */
	private const MAX_DEPTH = 3;

	private SnippetManager $manager;
	private Settings $settings;

	/** @var array<string, bool> Ids being rendered right now. */
	private static array $active = array();

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->manager  = new SnippetManager();
	}

	/**
	 * Hook the shortcode and the content filter.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		// After wpautop (10) and do_shortcode (11): the output is not rewrapped in paragraphs.
		add_filter( 'the_content', array( $this, 'filter_content' ), 12 );
	}

	/**
	 * [uxstudio_snippet id="12"]
	 *
	 * @param array<string, string>|string $atts    Attributes. Everything but `id` is passed on to a PHP snippet as `$atts`.
	 * @param string|null                  $content Enclosed content, passed on as `$content`.
	 */
	public function shortcode( $atts = array(), $content = null ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$id   = isset( $atts['id'] ) ? (int) $atts['id'] : 0;
		if ( $id < 1 ) {
			return '';
		}
		$snippet = $this->find( (string) $id );
		if ( ! $snippet || SnippetManager::LOCATION_SHORTCODE !== $snippet->getRunLocation() ) {
			return '';
		}
		unset( $atts['id'] );
		return $this->render( $snippet, $atts, (string) $content );
	}

	/**
	 * Add the before / after-content snippets around the content of the main post of a singular page.
	 *
	 * @param string $content Post content.
	 */
	public function filter_content( $content ) {
		if ( ! is_string( $content ) || ! $this->is_main_content() ) {
			return $content;
		}
		$post_type = (string) get_post_type();
		$before    = '';
		$after     = '';
		foreach ( $this->manager->getEnabledSnippets() as $snippet ) {
			$where = $snippet->getRunLocation();
			if ( ! in_array( $where, array( SnippetManager::LOCATION_BEFORE_CONTENT, SnippetManager::LOCATION_AFTER_CONTENT ), true ) ) {
				continue;
			}
			$types = $snippet->getPostTypes() ?: SnippetManager::DEFAULT_POST_TYPES;
			if ( ! in_array( $post_type, $types, true ) ) {
				continue;
			}
			$html = $this->render( $snippet );
			if ( SnippetManager::LOCATION_BEFORE_CONTENT === $where ) {
				$before .= $html;
			} else {
				$after .= $html;
			}
		}
		return $before . $content . $after;
	}

	/**
	 * Whether the_content() is now filtering the content of the page's own post: front end, single post / page, inside
	 * the main loop, not a feed, and not the content of some other post printed inside that loop.
	 */
	private function is_main_content(): bool {
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return false;
		}
		return (int) get_the_ID() === (int) get_queried_object_id();
	}

	/**
	 * An enabled snippet by id (the safe preview also counts the ones the migration created switched off).
	 *
	 * @param string $id Snippet id.
	 */
	private function find( string $id ): ?Snippet {
		foreach ( $this->manager->getEnabledSnippets() as $snippet ) {
			if ( $snippet->getId() === $id ) {
				return $snippet;
			}
		}
		return null;
	}

	/**
	 * The output of one snippet, or '' when anything about it is not safe to run right now.
	 *
	 * @param Snippet              $snippet Snippet.
	 * @param array<string, mixed> $atts    Shortcode attributes (PHP snippets get them as `$atts`).
	 * @param string               $content Shortcode enclosed content (`$content`).
	 */
	public function render( Snippet $snippet, array $atts = array(), string $content = '' ): string {
		if ( SnippetExecutor::safeModeActive( $this->settings ) ) {
			return '';
		}
		if ( ! $snippet->isEnabled() || ! $snippet->canExecute() || ! $snippet->verifyIntegrity() ) {
			return '';
		}
		// Run conditions (page type, visitor, device, schedule) narrow even a shortcode: judged now, at the place it appears.
		if ( ! SnippetConditions::hold( $snippet->getConditions() ) ) {
			return '';
		}
		$id = $snippet->getId();
		if ( isset( self::$active[ $id ] ) || count( self::$active ) >= self::MAX_DEPTH ) {
			return '';
		}

		$type = $snippet->getType();
		if ( 'php' === $type && ! self::may_execute_php() ) {
			return '';
		}
		$file = $snippet->getFilePath();
		if ( '' === $file || ! self::file_is_inside( $file ) ) {
			return '';
		}

		self::$active[ $id ] = true;
		try {
			switch ( $type ) {
				case 'php':
					return $this->run_php( $file, $atts, $content );
				case 'js':
					return "<script>\n" . $snippet->getCode() . "\n</script>";
				case 'css':
					return "<style>\n" . $snippet->getCode() . "\n</style>";
				default:
					return "\n" . $snippet->getCode() . "\n";
			}
		} finally {
			unset( self::$active[ $id ] );
		}
	}

	/**
	 * Include a PHP snippet and return what it printed. An exception or error inside it costs the snippet's output,
	 * never the page.
	 *
	 * @param string               $file    Verified snippet file.
	 * @param array<string, mixed> $atts    Attributes.
	 * @param string               $content Enclosed content.
	 */
	private function run_php( string $file, array $atts, string $content ): string {
		$level = ob_get_level();
		ob_start();
		try {
			// A static closure: the snippet sees $atts and $content, not this object.
			( static function ( string $uxs_file, array $atts, string $content ): void {
				include $uxs_file;
			} )( $file, $atts, $content );
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			return '';
		}
	}

	/**
	 * PHP snippets never run in REST, AJAX or cron requests, and in wp-admin only for users who manage snippets.
	 */
	public static function may_execute_php(): bool {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		return ! is_admin() || current_user_can( 'manage_options' );
	}

	/**
	 * Whether a file lies inside the snippets folder.
	 *
	 * @param string $file Path.
	 */
	private static function file_is_inside( string $file ): bool {
		$allowed = realpath( WP_CONTENT_DIR . '/uxstudio-snippets' );
		$real    = realpath( $file );
		return false !== $allowed && false !== $real && 0 === strpos( $real, rtrim( $allowed, '/\\' ) . DIRECTORY_SEPARATOR );
	}
}
