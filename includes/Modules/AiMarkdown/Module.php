<?php
/**
 * AI Markdown module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

use UxStudio\Core\DB;
use UxStudio\Core\Retention;
use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Serves a locally-generated (no external AI call) markdown version of posts
 * for AI bots, via /llms.txt (index) and ?format=markdown (per post), with a
 * cache table keyed by content hash and an access log.
 */
final class Module extends BaseModule {

	/** Daily purge of the access log (see Core\Retention). */
	private const RETENTION_HOOK = 'uxstudio_ai_markdown_retention';

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

		add_action( self::RETENTION_HOOK, array( $this, 'purge_log' ) );
		Retention::ensure_scheduled( self::RETENTION_HOOK );
	}

	/**
	 * Unschedule the log purge when the module is switched off.
	 */
	public function on_disable(): void {
		Retention::unschedule( self::RETENTION_HOOK );
	}

	/**
	 * Cron callback: drop access-log rows older than the retention setting.
	 */
	public function purge_log(): void {
		Retention::purge( 'uxstudio_ai_markdown_log', max( 0, (int) $this->settings->get( 'log_retention_days', Retention::DEFAULT_DAYS ) ), 'created_at', true );
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
		$schema = array(
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
			array(
				'key'     => 'site_name',
				'type'    => 'text',
				'label'   => __( 'Site name', 'ux-studio' ),
				'help'    => __( 'Used as the heading of llms.txt. Empty = the WordPress site title.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'site_description',
				'type'    => 'textarea',
				'label'   => __( 'Site description', 'ux-studio' ),
				'help'    => __( 'Short description for AI chatbots, shown in llms.txt. Empty = the WordPress tagline.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'enable_llms_txt',
				'type'    => 'toggle',
				'label'   => __( 'Serve /llms.txt', 'ux-studio' ),
				'help'    => __( 'Structured overview of the site for AI crawlers (llmstxt.org).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'enable_markdown_content',
				'type'    => 'toggle',
				'label'   => __( 'Markdown version of content', 'ux-studio' ),
				'help'    => __( 'Makes the markdown version of any post available by adding ?format=markdown to its URL.', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'include_meta_header',
				'type'    => 'toggle',
				'label'   => __( 'Metadata header', 'ux-studio' ),
				'help'    => __( 'Start the markdown with a YAML header (title, URL, type, date, author).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'cache_ttl',
				'type'    => 'select',
				'label'   => __( 'Cache validity', 'ux-studio' ),
				'help'    => __( 'How long generated markdown stays valid. After that it is regenerated on the next access. Editing the content always regenerates it.', 'ux-studio' ),
				'options' => array(
					'0'   => __( 'Unlimited (only when the content changes)', 'ux-studio' ),
					'1'   => __( '1 hour', 'ux-studio' ),
					'6'   => __( '6 hours', 'ux-studio' ),
					'12'  => __( '12 hours', 'ux-studio' ),
					'24'  => __( '24 hours', 'ux-studio' ),
					'48'  => __( '2 days', 'ux-studio' ),
					'168' => __( '7 days', 'ux-studio' ),
					'720' => __( '30 days', 'ux-studio' ),
				),
				'default' => '0',
			),
			array(
				'key'     => 'exclude_ids',
				'type'    => 'text',
				'label'   => __( 'Excluded IDs', 'ux-studio' ),
				'help'    => __( 'Comma-separated IDs of posts/pages that never get a markdown version, e.g. 12, 45, 78.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'enable_logging',
				'type'    => 'toggle',
				'label'   => __( 'Log accesses', 'ux-studio' ),
				'help'    => __( 'Record accesses to the markdown endpoints (user agent, post, time).', 'ux-studio' ),
				'default' => true,
			),
			array(
				'key'     => 'log_retention_days',
				'type'    => 'number',
				'label'   => __( 'Keep access log (days)', 'ux-studio' ),
				'help'    => __( 'Older bot-access log entries are deleted daily. 0 = keep forever.', 'ux-studio' ),
				'default' => Retention::DEFAULT_DAYS,
			),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$woo = array(
				'enable_woocommerce'      => array( __( 'WooCommerce support', 'ux-studio' ), __( 'Use a special markdown template for products with price, stock and attributes.', 'ux-studio' ) ),
				'show_price'              => array( __( 'Show price', 'ux-studio' ), __( 'Include the product price in the YAML header.', 'ux-studio' ) ),
				'show_currency'           => array( __( 'Show currency', 'ux-studio' ), __( 'Include the currency code (CZK, EUR...) in the header.', 'ux-studio' ) ),
				'show_stock_status'       => array( __( 'Stock availability', 'ux-studio' ), __( 'Include whether the product is in stock.', 'ux-studio' ) ),
				'show_product_categories' => array( __( 'Product categories', 'ux-studio' ), __( 'Include the product categories in the header.', 'ux-studio' ) ),
				'show_product_attributes' => array( __( 'Product attributes', 'ux-studio' ), __( 'Include product attributes (size, colour...) in the header.', 'ux-studio' ) ),
			);
			foreach ( $woo as $key => $texts ) {
				$schema[] = array(
					'key'     => $key,
					'type'    => 'toggle',
					'label'   => $texts[0],
					'help'    => $texts[1],
					'default' => true,
				);
			}
		}

		return $schema;
	}

	/**
	 * Boolean setting; accepts true/false and the legacy '1'/'0'/'' strings.
	 */
	private function flag( string $key, bool $default ): bool {
		$all = $this->settings->all();
		return array_key_exists( $key, $all ) ? filter_var( $all[ $key ], FILTER_VALIDATE_BOOLEAN ) : $default;
	}

	/**
	 * Toggles migrated from the legacy plugin are '1'/'0' strings - hand the UI real booleans.
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		foreach ( $this->settings_schema() as $field ) {
			if ( 'toggle' === $field['type'] ) {
				$values[ $field['key'] ] = filter_var( $values[ $field['key'] ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		return $values;
	}

	/**
	 * Normalise the excluded-IDs list to digits and commas before storing.
	 *
	 * @param array $input Raw input.
	 */
	public function save_settings( array $input ): array {
		if ( array_key_exists( 'exclude_ids', $input ) ) {
			$input['exclude_ids'] = implode( ', ', array_filter( array_map( 'absint', explode( ',', (string) $input['exclude_ids'] ) ) ) );
		}
		return parent::save_settings( $input );
	}

	/**
	 * IDs excluded from markdown generation (setting `exclude_ids`).
	 *
	 * @return array<int, int>
	 */
	private function exclude_ids(): array {
		$raw = $this->settings->get( 'exclude_ids', '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * Part of the per-post cache hash: every setting that changes the generated
	 * markdown, so toggling e.g. the YAML header regenerates stale entries.
	 */
	private function output_signature(): string {
		$keys = array( 'include_meta_header', 'enable_woocommerce', 'show_price', 'show_currency', 'show_stock_status', 'show_product_categories', 'show_product_attributes' );
		$sig  = '';
		foreach ( $keys as $key ) {
			$sig .= $this->flag( $key, true ) ? '1' : '0';
		}
		return $sig;
	}

	private function content_hash( \WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . '|' . $post->post_content . '|' . $post->post_modified_gmt . '|' . $this->output_signature() );
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
	 * Whether a post is eligible for markdown generation: published, not
	 * password-protected (the markdown would bypass the password form) and
	 * not an excluded post type.
	 */
	private function is_eligible( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}
		if ( in_array( $post_id, $this->exclude_ids(), true ) ) {
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
		$hash = $this->content_hash( $post );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}uxstudio_ai_markdown_cache WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( $row && $row['content_hash'] === $hash && ! $this->is_cache_expired( (string) $row['generated_at'] ) ) {
			return array(
				'post_id'      => $post_id,
				'markdown'     => (string) $row['markdown_content'],
				'generated_at' => (string) $row['generated_at'],
			);
		}

		return $this->regenerate( $post_id );
	}

	/**
	 * Cache TTL (setting `cache_ttl`, hours; 0 = never expires). generated_at is stored in GMT.
	 */
	private function is_cache_expired( string $generated_at ): bool {
		$ttl = (int) $this->settings->get( 'cache_ttl', 0 );
		if ( $ttl <= 0 ) {
			return false;
		}
		$ts = strtotime( $generated_at . ' UTC' );
		return ! $ts || ( time() - $ts ) > $ttl * HOUR_IN_SECONDS;
	}

	/**
	 * Markdown of one post: WooCommerce template for products (when enabled),
	 * otherwise optional YAML header + converted content.
	 */
	private function build_markdown( \WP_Post $post ): string {
		if ( 'product' === $post->post_type && $this->flag( 'enable_woocommerce', true ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				return $this->build_product_markdown( $post, $product );
			}
		}

		$front = '';
		if ( $this->flag( 'include_meta_header', true ) ) {
			$front  = "---\n";
			$front .= 'title: ' . self::escape_yaml( $post->post_title ) . "\n";
			$front .= 'url: ' . get_permalink( $post ) . "\n";
			$front .= 'type: ' . $post->post_type . "\n";
			$front .= 'date: ' . get_the_date( 'Y-m-d', $post ) . "\n";
			$author = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( $author ) {
				$front .= 'author: ' . self::escape_yaml( $author ) . "\n";
			}
			$modified = get_the_modified_date( 'Y-m-d', $post );
			if ( $modified && $modified !== get_the_date( 'Y-m-d', $post ) ) {
				$front .= 'modified: ' . $modified . "\n";
			}
			$front .= "---\n\n";
		}

		return $front . self::html_to_markdown( $post->post_title, $post->post_content );
	}

	/**
	 * Product markdown (port of the legacy Generator::generateProductMarkdown()).
	 *
	 * @param \WP_Post    $post    Product post.
	 * @param \WC_Product $product Product.
	 */
	private function build_product_markdown( \WP_Post $post, $product ): string {
		$plain = static fn ( string $html ): string => trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );

		$md  = "---\n";
		$md .= 'title: ' . self::escape_yaml( $post->post_title ) . "\n";
		$md .= 'url: ' . get_permalink( $post ) . "\n";
		$md .= "type: product\n";
		$md .= 'date: ' . get_the_date( 'Y-m-d', $post ) . "\n";

		if ( $this->flag( 'show_price', true ) ) {
			$price = $product->get_price();
			if ( $price ) {
				$md .= 'price: ' . $plain( wc_price( $price ) ) . "\n";
			}
			if ( $product->is_on_sale() && $product->get_regular_price() ) {
				$md .= 'regular_price: ' . $plain( wc_price( $product->get_regular_price() ) ) . "\n";
			}
		}

		if ( $this->flag( 'show_currency', true ) ) {
			$md .= 'currency: ' . get_woocommerce_currency() . "\n";
		}

		if ( $this->flag( 'show_stock_status', true ) ) {
			$md .= 'stock: ' . ( $product->is_in_stock() ? __( 'In stock', 'ux-studio' ) : __( 'Out of stock', 'ux-studio' ) ) . "\n";
			$qty = $product->get_stock_quantity();
			if ( null !== $qty ) {
				$md .= 'stock_quantity: ' . (int) $qty . "\n";
			}
		}

		if ( $this->flag( 'show_product_categories', true ) ) {
			$cats = wp_get_post_terms( $post->ID, 'product_cat', array( 'fields' => 'names' ) );
			if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
				$md .= 'categories: ' . self::escape_yaml( implode( ', ', $cats ) ) . "\n";
			}
		}

		if ( $this->flag( 'show_product_attributes', true ) ) {
			foreach ( $product->get_attributes() as $attr ) {
				if ( ! is_object( $attr ) || ! method_exists( $attr, 'is_taxonomy' ) ) {
					continue; // Variation attributes come as plain strings.
				}
				if ( $attr->is_taxonomy() ) {
					$label = wc_attribute_label( $attr->get_name() );
					$terms = $attr->get_terms();
					$names = $terms ? wp_list_pluck( $terms, 'name' ) : array();
				} else {
					$label = $attr->get_name();
					$names = $attr->get_options();
				}
				if ( ! empty( $names ) ) {
					$md .= strtolower( sanitize_title( $label ) ) . ': ' . self::escape_yaml( implode( ', ', array_map( 'strval', $names ) ) ) . "\n";
				}
			}
		}

		$sku = $product->get_sku();
		if ( $sku ) {
			$md .= 'sku: ' . self::escape_yaml( $sku ) . "\n";
		}

		$md .= "---\n\n";
		$md .= '# ' . $post->post_title . "\n\n";

		$short = $product->get_short_description();
		if ( $short ) {
			$md .= trim( preg_replace( '/^# .*\n+/', '', self::html_to_markdown( '', $short ) ) ) . "\n\n";
		}
		if ( '' !== trim( $post->post_content ) ) {
			$md .= '## ' . __( 'Description', 'ux-studio' ) . "\n\n";
			$md .= trim( preg_replace( '/^# .*\n+/', '', self::html_to_markdown( '', $post->post_content ) ) ) . "\n";
		}

		return $md;
	}

	/**
	 * Quote a YAML scalar when it contains characters that would break the header.
	 */
	private static function escape_yaml( string $value ): string {
		$value = str_replace( array( "\r", "\n" ), ' ', $value );
		if ( preg_match( '/[:#\[\]{}|>&*!,?"\']/', $value ) || str_starts_with( $value, '-' ) ) {
			return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
		}
		return $value;
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

		$markdown = $this->build_markdown( $post );
		$hash     = $this->content_hash( $post );
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
				'has_password'   => false,
				'post__not_in'   => $this->exclude_ids(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$this->purge_ineligible_cache();

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
		} else {
			// E.g. the post just got a password or was unpublished - never keep a stale copy.
			$this->delete_cache( $post_id );
		}
	}

	/**
	 * Remove one post's cached markdown.
	 */
	private function delete_cache( int $post_id ): void {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}uxstudio_ai_markdown_cache", array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Remove cached markdown of posts that are gone, unpublished or
	 * password-protected (cached before protected posts were excluded).
	 */
	private function purge_ineligible_cache(): void {
		global $wpdb;
		$wpdb->query(
			"DELETE c FROM {$wpdb->prefix}uxstudio_ai_markdown_cache c
			 LEFT JOIN {$wpdb->posts} p ON p.ID = c.post_id
			 WHERE p.ID IS NULL OR p.post_status <> 'publish' OR p.post_password <> ''"
		);
	}

	/**
	 * Serve ?format=markdown on a single post's normal URL.
	 */
	public function maybe_serve_markdown(): void {
		if ( ! $this->flag( 'enable_markdown_content', true ) ) {
			return;
		}
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
		if ( 'llms.txt' !== trim( (string) $wp->request, '/' ) || ! $this->flag( 'enable_llms_txt', true ) ) {
			return;
		}

		$excluded   = (array) $this->settings->get( 'excluded_post_types', array( 'attachment' ) );
		$post_types = array_diff( array_keys( $this->post_type_options() ), $excluded );
		$ids        = get_posts(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'post__not_in'   => $this->exclude_ids(),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		// Name/description overrides (plain text; a newline would break the heading/blockquote).
		$one_line  = static fn ( string $v ): string => trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $v ) ) );
		$site_name = $one_line( (string) $this->settings->get( 'site_name', '' ) );
		$site_desc = $one_line( (string) $this->settings->get( 'site_description', '' ) );
		$site_name = '' !== $site_name ? $site_name : $one_line( get_bloginfo( 'name' ) );
		$site_desc = '' !== $site_desc ? $site_desc : $one_line( get_bloginfo( 'description' ) );

		$lines = array( '# ' . $site_name, '' );
		if ( '' !== $site_desc ) {
			$lines[] = '> ' . $site_desc;
			$lines[] = '';
		}
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
		if ( ! $this->flag( 'enable_logging', true ) ) {
			return;
		}
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
