<?php
/**
 * AI Markdown module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiMarkdown;

use UxStudio\Core\ClientIp;
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

	/** Periodic full regeneration (setting `auto_regenerate_interval`). */
	private const AUTO_REGEN_HOOK = 'uxstudio_ai_markdown_auto_regenerate';

	/** Cached /ai-sitemap.md body. */
	private const SITEMAP_TRANSIENT = 'uxstudio_ai_markdown_sitemap';

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		DB::ensure_module_tables(
			'ai-markdown',
			2,
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
						bot_name VARCHAR(100) NOT NULL DEFAULT '',
						url VARCHAR(2048) NOT NULL DEFAULT '',
						content_type VARCHAR(20) NOT NULL DEFAULT '',
						ip_address VARCHAR(45) NOT NULL DEFAULT '',
						user_agent VARCHAR(255) NOT NULL DEFAULT '',
						PRIMARY KEY  (id),
						KEY created_at (created_at),
						KEY bot_name (bot_name)
					) {$charset};"
				);
				// v2 added bot_name/url/content_type/ip_address and logs bots only.
				if ( $from >= 1 && $from < 2 ) {
					AccessLog::backfill_v1();
				}
			}
		);

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve_markdown' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_llms_txt' ) );
		add_action( 'parse_request', array( $this, 'maybe_serve_ai_sitemap' ) );
		add_action( 'save_post', array( $this, 'maybe_invalidate_cache' ), 10, 2 );

		// Any content/term change makes the cached /ai-sitemap.md stale.
		foreach ( array( 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( $this, 'flush_sitemap' ) );
		}

		add_action( self::RETENTION_HOOK, array( $this, 'purge_log' ) );
		add_action( self::RETENTION_HOOK, array( $this, 'warm_sitemap' ) );
		Retention::ensure_scheduled( self::RETENTION_HOOK );

		// Periodic full regeneration (setting `auto_regenerate_interval`, hours; 0 = off).
		add_action( self::AUTO_REGEN_HOOK, array( $this, 'cron_auto_regenerate' ) );
		if ( $this->auto_interval() > 0 && ! wp_next_scheduled( self::AUTO_REGEN_HOOK ) ) {
			$this->schedule_auto_regenerate();
		}
	}

	/**
	 * Unschedule the log purge and auto-regeneration when the module is switched off.
	 */
	public function on_disable(): void {
		Retention::unschedule( self::RETENTION_HOOK );
		wp_clear_scheduled_hook( self::AUTO_REGEN_HOOK );
		delete_transient( self::SITEMAP_TRANSIENT );
	}

	/**
	 * Auto-regeneration interval in hours (0 = off).
	 */
	private function auto_interval(): int {
		return max( 0, (int) $this->settings->get( 'auto_regenerate_interval', '0' ) );
	}

	/**
	 * Schedule the next auto-regeneration run (single event, re-armed by the
	 * callback - any hour count works without a custom cron schedule).
	 */
	private function schedule_auto_regenerate(): void {
		wp_schedule_single_event( time() + $this->auto_interval() * HOUR_IN_SECONDS, self::AUTO_REGEN_HOOK );
	}

	/**
	 * Cron callback: regenerate the whole site's markdown, then re-arm.
	 */
	public function cron_auto_regenerate(): void {
		if ( $this->auto_interval() <= 0 ) {
			return;
		}
		$this->regenerate_all();
		if ( ! wp_next_scheduled( self::AUTO_REGEN_HOOK ) ) {
			$this->schedule_auto_regenerate();
		}
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
				'key'     => 'taxonomies',
				'type'    => 'multiselect',
				'label'   => __( 'Taxonomies', 'ux-studio' ),
				'help'    => __( 'Terms of these taxonomies are listed in llms.txt and the AI sitemap.', 'ux-studio' ),
				'options' => $this->taxonomy_options(),
				'default' => array( 'category' ),
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
				'key'     => 'enable_ai_sitemap',
				'type'    => 'toggle',
				'label'   => __( 'Serve /ai-sitemap.md', 'ux-studio' ),
				'help'    => __( 'Markdown sitemap for AI bots: every published item with its date and a link to its markdown version, plus the selected taxonomies.', 'ux-studio' ),
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
				'key'     => 'auto_regenerate_interval',
				'type'    => 'select',
				'label'   => __( 'Automatic regeneration', 'ux-studio' ),
				'help'    => __( 'Regenerate the markdown of the whole site at this interval (via WP-Cron).', 'ux-studio' ),
				'options' => array(
					'0'   => __( 'Off', 'ux-studio' ),
					'6'   => __( 'Every 6 hours', 'ux-studio' ),
					'12'  => __( 'Every 12 hours', 'ux-studio' ),
					'24'  => __( 'Once a day', 'ux-studio' ),
					'48'  => __( 'Every 2 days', 'ux-studio' ),
					'168' => __( 'Once a week', 'ux-studio' ),
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
				'help'    => __( 'Record accesses of AI bots (GPTBot, ClaudeBot, PerplexityBot...) to the markdown endpoints. Other visitors are not logged.', 'ux-studio' ),
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
				'show_product_attributes' => array( __( 'Product attributes', 'ux-studio' ), __( 'Include product attributes (size, color...) in the header.', 'ux-studio' ) ),
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
			} elseif ( 'select' === $field['type'] && ! array_key_exists( (string) $values[ $field['key'] ], $field['options'] ) ) {
				// Legacy stored '' for "not set" - show the default instead of an empty select.
				$values[ $field['key'] ] = $field['default'];
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

		$old_interval = $this->auto_interval();
		$values       = parent::save_settings( $input );

		// Post types, taxonomies, exclusions... all change the sitemap.
		$this->flush_sitemap();

		if ( $this->auto_interval() !== $old_interval ) {
			wp_clear_scheduled_hook( self::AUTO_REGEN_HOOK );
			if ( $this->auto_interval() > 0 ) {
				$this->schedule_auto_regenerate();
			}
		}

		return $values;
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
	 * Public taxonomies as select options.
	 *
	 * @return array<string, string>
	 */
	private function taxonomy_options(): array {
		$options = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$options[ $tax->name ] = $tax->label;
		}
		return $options;
	}

	/**
	 * Post types that get a markdown version (public minus `excluded_post_types`).
	 *
	 * @return array<int, string>
	 */
	private function enabled_post_types(): array {
		$excluded = (array) $this->settings->get( 'excluded_post_types', array( 'attachment' ) );
		return array_values( array_diff( array_keys( $this->post_type_options() ), $excluded ) );
	}

	/**
	 * Public taxonomies selected in the `taxonomies` setting.
	 *
	 * @return array<int, string>
	 */
	private function enabled_taxonomies(): array {
		$selected = array_map( 'strval', (array) $this->settings->get( 'taxonomies', array( 'category' ) ) );
		return array_values( array_intersect( array_keys( $this->taxonomy_options() ), $selected ) );
	}

	/**
	 * Title as plain text for a markdown link label (no HTML entities/tags).
	 */
	private static function plain_title( int $post_id ): string {
		return trim( html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Non-empty terms of one taxonomy as markdown list lines.
	 *
	 * @param string $taxonomy   Taxonomy name.
	 * @param int    $limit      Max terms.
	 * @param bool   $with_count Append the item count (sitemap) instead of the description (llms.txt).
	 * @return array<int, string> Lines incl. the "## Label" heading, or empty.
	 */
	private function term_lines( string $taxonomy, int $limit, bool $with_count ): array {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => $limit,
			)
		);
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$lines = array( '## ' . $tax->labels->name, '' );
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$name = html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' );
			$line = '- [' . $name . '](' . $url . ')';
			if ( $with_count ) {
				/* translators: %d: number of items in the term. */
				$line .= ' (' . sprintf( _n( '%d item', '%d items', (int) $term->count, 'ux-studio' ), (int) $term->count ) . ')';
			} elseif ( '' !== trim( (string) $term->description ) ) {
				$line .= ': ' . mb_substr( trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $term->description ) ) ), 0, 150 );
			}
			$lines[] = $line;
		}
		$lines[] = '';
		return $lines;
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
		$this->flush_sitemap();
		$this->warm_sitemap();

		$post_types = $this->enabled_post_types();
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
	 * @return array{cached_posts:int,log_entries_24h:int,llms_txt_url:string,ai_sitemap_url:string}
	 */
	public function get_stats(): array {
		global $wpdb;
		$cached = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_ai_markdown_cache" );
		$since  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$log_24h = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}uxstudio_ai_markdown_log WHERE created_at >= %s", $since )
		);
		return array(
			'cached_posts'    => $cached,
			'log_entries_24h' => $log_24h,
			// Empty = endpoint switched off.
			'llms_txt_url'    => $this->flag( 'enable_llms_txt', true ) ? home_url( '/llms.txt' ) : '',
			'ai_sitemap_url'  => $this->flag( 'enable_ai_sitemap', true ) ? home_url( '/ai-sitemap.md' ) : '',
		);
	}

	/**
	 * One page of the cached posts with title, for the admin table. Cache rows of posts that no longer exist
	 * are not listed.
	 *
	 * @param int $limit  Rows per page (1 to 100).
	 * @param int $offset Rows to skip.
	 * @return array{items:array<int, array{post_id:int,title:string,generated_at:string}>,total:int}
	 */
	public function list_cache( int $limit = 30, int $offset = 0 ): array {
		global $wpdb;
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = $wpdb->prefix . 'uxstudio_ai_markdown_cache';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.post_id, c.generated_at, p.post_title FROM {$table} c INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id ORDER BY c.generated_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$title = (string) $row['post_title'];
			$out[] = array(
				'post_id'      => (int) $row['post_id'],
				'title'        => '' !== $title ? $title : __( '(no title)', 'ux-studio' ),
				'generated_at' => (string) $row['generated_at'],
			);
		}
		return array(
			'items' => $out,
			'total' => $total,
		);
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
		$this->log_access( $post_id, (string) get_post_type( $post_id ), add_query_arg( 'format', 'markdown', (string) get_permalink( $post_id ) ) );

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

		$ids = get_posts(
			array(
				'post_type'      => $this->enabled_post_types(),
				'post_status'    => 'publish',
				'has_password'   => false,
				'post__not_in'   => $this->exclude_ids(),
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		$lines     = array( '# ' . $this->site_name(), '' );
		$site_desc = $this->site_description();
		if ( '' !== $site_desc ) {
			$lines[] = '> ' . $site_desc;
			$lines[] = '';
		}
		foreach ( $ids as $id ) {
			$lines[] = '- [' . self::plain_title( (int) $id ) . '](' . add_query_arg( 'format', 'markdown', get_permalink( $id ) ) . ')';
		}

		$taxonomies = $this->enabled_taxonomies();
		if ( $taxonomies ) {
			$lines[] = '';
		}
		foreach ( $taxonomies as $taxonomy ) {
			$lines = array_merge( $lines, $this->term_lines( $taxonomy, 50, false ) );
		}

		$this->log_access( null, 'llms_txt', home_url( '/llms.txt' ) );

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo rtrim( implode( "\n", $lines ) ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, not HTML.
		exit;
	}

	/**
	 * Serve a virtual /ai-sitemap.md (port of the legacy Generator::generateSitemap()).
	 *
	 * @param \WP $wp Main query object.
	 */
	public function maybe_serve_ai_sitemap( \WP $wp ): void {
		if ( 'ai-sitemap.md' !== trim( (string) $wp->request, '/' ) || ! $this->flag( 'enable_ai_sitemap', true ) ) {
			return;
		}

		$body = $this->warm_sitemap();

		$this->log_access( null, 'sitemap', home_url( '/ai-sitemap.md' ) );

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain markdown, not HTML.
		exit;
	}

	/**
	 * Cached sitemap body, (re)building it when missing. Also a daily-cron
	 * callback, so the cache is complete before the first bot of the day.
	 */
	public function warm_sitemap(): string {
		if ( ! $this->flag( 'enable_ai_sitemap', true ) ) {
			return '';
		}
		$body = get_transient( self::SITEMAP_TRANSIENT );
		if ( is_string( $body ) && '' !== $body ) {
			return $body;
		}
		$body = $this->build_sitemap();
		// Never 0 (= autoloaded, no expiry): the cache TTL setting, else a week. Content changes flush it anyway.
		$ttl = (int) $this->settings->get( 'cache_ttl', 0 );
		set_transient( self::SITEMAP_TRANSIENT, $body, $ttl > 0 ? $ttl * HOUR_IN_SECONDS : WEEK_IN_SECONDS );
		return $body;
	}

	/**
	 * Drop the cached sitemap (content, terms or settings changed).
	 */
	public function flush_sitemap(): void {
		delete_transient( self::SITEMAP_TRANSIENT );
	}

	/**
	 * Markdown sitemap: every enabled post type (published, not password
	 * protected, not excluded - same rules as llms.txt) with dates and links
	 * to the markdown version, then the selected taxonomies' terms.
	 */
	private function build_sitemap(): string {
		$with_md = $this->flag( 'enable_markdown_content', true );
		$lines   = array(
			'# AI Sitemap - ' . $this->site_name(),
			'',
			/* translators: %s: date and time the sitemap was generated. */
			sprintf( __( 'Generated: %s', 'ux-studio' ), current_time( 'Y-m-d H:i:s' ) ),
			'',
		);

		foreach ( $this->enabled_post_types() as $type ) {
			$type_obj = get_post_type_object( $type );
			if ( ! $type_obj ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'post__not_in'   => $this->exclude_ids(),
					'posts_per_page' => 500,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			if ( empty( $ids ) ) {
				continue;
			}
			$lines[] = '## ' . $type_obj->labels->name;
			$lines[] = '';
			foreach ( $ids as $id ) {
				$url  = (string) get_permalink( $id );
				$line = '- [' . self::plain_title( (int) $id ) . '](' . $url . ') (' . get_the_date( 'Y-m-d', $id ) . ')';
				if ( $with_md ) {
					$line .= ' - [' . __( 'Markdown version', 'ux-studio' ) . '](' . add_query_arg( 'format', 'markdown', $url ) . ')';
				}
				$lines[] = $line;
			}
			$lines[] = '';
		}

		foreach ( $this->enabled_taxonomies() as $taxonomy ) {
			$lines = array_merge( $lines, $this->term_lines( $taxonomy, 200, true ) );
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * One-line plain text (a newline would break the markdown heading/blockquote).
	 */
	private static function one_line( string $value ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $value ) ) );
	}

	/**
	 * Site name for llms.txt / the sitemap heading (setting `site_name`, else the site title).
	 */
	private function site_name(): string {
		$name = self::one_line( (string) $this->settings->get( 'site_name', '' ) );
		return '' !== $name ? $name : self::one_line( html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Site description for llms.txt (setting `site_description`, else the tagline).
	 */
	private function site_description(): string {
		$desc = self::one_line( (string) $this->settings->get( 'site_description', '' ) );
		return '' !== $desc ? $desc : self::one_line( html_entity_decode( get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Log an access by a known AI bot - other visitors are ignored, as in the
	 * legacy plugin. Never blocks the response on failure.
	 *
	 * @param int|null $post_id      Post id, or null for llms.txt / the sitemap.
	 * @param string   $content_type llms_txt | sitemap | post type.
	 * @param string   $url          Canonical URL that was served.
	 */
	private function log_access( ?int $post_id, string $content_type, string $url ): void {
		if ( ! $this->flag( 'enable_logging', true ) ) {
			return;
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot = BotDetector::detect( $ua );
		if ( null === $bot ) {
			return;
		}
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}uxstudio_ai_markdown_log",
			array(
				'created_at'   => current_time( 'mysql', true ),
				'post_id'      => $post_id,
				'bot_name'     => mb_substr( $bot, 0, 100 ),
				'url'          => mb_substr( esc_url_raw( $url ), 0, 2048 ),
				'content_type' => substr( sanitize_key( $content_type ), 0, 20 ),
				'ip_address'   => substr( ClientIp::get( 'auto' ), 0, 45 ),
				'user_agent'   => mb_substr( $ua, 0, 255 ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
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
