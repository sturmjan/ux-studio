<?php
/**
 * AI Markdown module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

use UxStudio\Core\DB;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a locally-generated (no external AI call) markdown version of posts
 * for AI bots, via /llms.txt (index) and ?format=markdown (per post), with a
 * cache table keyed by content hash and an access log.
 */
final class Module extends BaseModule {

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'ai-markdown',
			1,
			function ( int $from ): void {
				global $wpdb;
				$charset = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_ai_markdown_cache (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						post_id BIGINT UNSIGNED NOT NULL,
						content_hash VARCHAR(64) NOT NULL DEFAULT '',
						markdown_content LONGTEXT NULL,
						generated_at DATETIME NOT NULL,
						PRIMARY KEY  (id),
						UNIQUE KEY post_id (post_id)
					) {$charset};"
				);
				dbDelta(
					"CREATE TABLE {$wpdb->prefix}uxstudio_ai_markdown_log (
						id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
						created_at DATETIME NOT NULL,
						post_id BIGINT UNSIGNED NULL,
						user_agent VARCHAR(255) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at)
					) {$charset};"
				);
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_markdown' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_llms_txt' ) );
		add_action( 'save_post', array( $this, 'maybe_invalidate_cache' ), 10, 2 );
	}

	/**
	 * REST controller class.
	 */
	public function rest_controller(): ?string {
		return RestController::class;
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	/**
	 * Settings schema.
	 */
	public function settings_schema(): array {
		return array(
			array(
				'key'     => 'auto_regenerate',
				'type'    => 'toggle',
				'label'   => __( 'Auto-regenerate on save', 'ux-studio' ),
				'help'    => __( 'Regenerate the cached markdown whenever a post is updated.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'excluded_post_types',
				'type'    => 'multiselect',
				'label'   => __( 'Excluded post types', 'ux-studio' ),
				'help'    => __( 'These post types never get a markdown version.', 'ux-studio' ),
				'options' => $this->post_type_options(),
				'default' => array( 'attachment' ),
			),
		);
	}

	/**
	 * Public post types as select options.
	 *
	 * @return array<string, string>
	 */
	private function post_type_options(): array {
		$options = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$options[ $pt->name ] = $pt->label;
		}
		return $options;
	}

	/**
	 * Whether a post type is eligible for markdown generation.
	 */
	private function is_eligible( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}
		$excluded = (array) $this->settings->get( 'excluded_post_types', array( 'attachment' ) );
		return ! in_array( $post->post_type, $excluded, true );
	}

	/**
	 * Get (from cache, regenerating if the content changed) the markdown for
	 * one post.
	 *
	 * @return array{post_id:int,markdown:string,generated_at:string}|null
	 */
	public function get_cached( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		global $wpdb;
		$hash = hash( 'sha256', $post->post_title . '|' . $post->post_content );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}uxstudio_ai_markdown_cache WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( $row && $row['content_hash'] === $hash ) {
			return array(
				'post_id'      => $post_id,
				'markdown'     => (string) $row['markdown_content'],
				'generated_at' => (string) $row['generated_at'],
			);
		}

		return $this->regenerate( $post_id );
	}

	/**
	 * Force-regenerate and store the markdown for one post.
	 *
	 * @return array{post_id:int,markdown:string,generated_at:string}|null
	 */
	public function regenerate( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$markdown = self::html_to_markdown( $post->post_title, $post->post_content );
		$hash     = hash( 'sha256', $post->post_title . '|' . $post->post_content );
		$now      = current_time( 'mysql', true );

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}uxstudio_ai_markdown_cache (post_id, content_hash, markdown_content, generated_at)
				VALUES (%d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash), markdown_content = VALUES(markdown_content), generated_at = VALUES(generated_at)",
				$post_id,
				$hash,
				$markdown,
				$now
			)
		);

		return array( 'post_id' => $post_id, 'markdown' => $markdown, 'generated_at' => $now );
	}

	/**
	 * Regenerate markdown for every eligible published post.
	 */
	public function regenerate_all(): int {
		$excluded = (array) $this->settings->get( 'excluded_post_types', array( 'attachment' ) );
		$post_types = array_diff( array_keys( $this->post_type_options() ), $excluded );
		if ( empty( $post_types ) ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $ids as $id ) {
			$this->regenerate( (int) $id );
		}

		return count( $ids );
	}

	/**
	 * Cache + log stats for the admin screen.
	 *
	 * @return array{cached_posts:int,log_entries_24h:int}
	 */
	public function get_stats(): array {
		global $wpdb;
		$cached = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_ai_markdown_cache" );
		$since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$log_24h = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_ai_markdown_log WHERE created_at >= %s", $since )
		);
		return array( 'cached_posts' => $cached, 'log_entries_24h' => $log_24h );
	}

	/**
	 * List cached posts with title, for the admin table.
	 *
	 * @return array<int, array{post_id:int,title:string,generated_at:string}>
	 */
	public function list_cache(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT post_id, generated_at FROM {$wpdb->prefix}uxstudio_ai_markdown_cache ORDER BY generated_at DESC LIMIT 100",
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$post = get_post( (int) $row['post_id'] );
			$out[] = array(
				'post_id'      => (int) $row['post_id'],
				'title'        => $post ? $post->post_title : __( '(deleted)', 'ux-studio' ),
				'generated_at' => (string) $row['generated_at'],
			);
		}
		return $out;
	}

	/**
	 * Invalidate/regenerate the cache when a post is saved (if enabled).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post object.
	 */
	public function maybe_invalidate_cache( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! (bool) $this->settings->get( 'auto_regenerate', true ) ) {
			return;
		}
		if ( $this->is_eligible( $post_id ) ) {
			$this->regenerate( $post_id );
		}
	}

	/**
	 * Serve ?format=markdown on a single post's normal URL.
	 */
	public function maybe_serve_markdown(): void {
		if ( ! is_singular() || 'markdown' !== ( $_GET['format'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! $this->is_eligible( $post_id ) ) {
			return;
		}

		$cached = $this->get_cached( $post_id );
		$this->log_access( $post_id );

		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		echo $cached ? $cached['markdown'] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Serve a virtual /llms.txt (no rewrite rules needed - matched directly
	 * against the parsed request path).
	 *
	 * @param \WP $wp Main query object.
	 */
	public function maybe_serve_llms_txt( \WP $wp ): void {
		if ( 'llms.txt' !== trim( (string) $wp->request, '/' ) ) {
			return;
		}

		$excluded   = (array) $this->settings->get( 'excluded_post_types', array( 'attachment' ) );
		$post_types = array_diff( array_keys( $this->post_type_options() ), $excluded );
		$ids        = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		$lines   = array( '# ' . get_bloginfo( 'name' ), '' );
		foreach ( $ids as $id ) {
			$lines[] = '- [' . get_the_title( $id ) . '](' . add_query_arg( 'format', 'markdown', get_permalink( $id ) ) . ')';
		}

		$this->log_access( null );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo implode( "\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Log a bot access (never blocks the response on failure).
	 *
	 * @param int|null $post_id Post id, or null for the /llms.txt index.
	 */
	private function log_access( ?int $post_id ): void {
		global $wpdb;
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_ai_markdown_log",
			array(
				'created_at' => current_time( 'mysql', true ),
				'post_id'    => $post_id,
				'user_agent' => substr( $ua, 0, 255 ),
			),
			array( '%s', '%d', '%s' )
		);
	}

	/**
	 * Minimal local (non-AI) HTML -> markdown conversion. Deliberately simple:
	 * headings, bold/italic, list items and paragraph breaks; everything else
	 * is stripped to plain text. No external service is called.
	 */
	public static function html_to_markdown( string $title, string $html ): string {
		$content = $html;
		$content = preg_replace( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', "\n" . '$1_HASH$2' . "\n", $content ) ?? $content;
		$content = preg_replace_callback(
			'/(\d)_HASH(.*)/',
			static fn ( array $m ): string => str_repeat( '#', (int) $m[1] ) . ' ' . trim( $m[2] ),
			$content
		);
		$content = preg_replace( '/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $content ) ?? $content;
		$content = preg_replace( '/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $content ) ?? $content;
		$content = preg_replace( '/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $content ) ?? $content;
		$content = preg_replace( '/<br\s*\/?>/i', "\n", $content ) ?? $content;
		$content = preg_replace( '/<\/p>/i', "\n\n", $content ) ?? $content;
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( "/\n{3,}/", "\n\n", trim( $content ) ) ?? $content;

		return "# {$title}\n\n{$content}\n";
	}
}
