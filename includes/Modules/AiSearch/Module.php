<?php
/**
 * AI Search module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiSearch;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Live AJAX search with a suggestion dropdown, several search field designs and
 * a results page. It does not keep an index of its own: it reads the shared
 * content/product index of the AI Assistant module (one RAG database), and
 * hands the visitor over to the chat assistant when the search does not
 * satisfy them.
 *
 * Entry points: [uxs_search], [uxs_search_results], the two Gutenberg blocks
 * and, optionally, a replacement of the WordPress search (forms + /?s= redirect).
 */
final class Module extends BaseModule {

	/** Design slug => label. */
	public const DESIGNS = array(
		'classic' => 'Classic (rounded field with button)',
		'minimal' => 'Minimal (underline)',
		'pill'    => 'Pill (large, icon inside)',
		'command' => 'Command palette (shortcut hint)',
		'overlay' => 'Overlay (icon opens full-screen search)',
		'hero'    => 'Hero (large, with popular searches)',
	);

	/** Results layout slug => label. */
	public const LAYOUTS = array(
		'list'    => 'List',
		'cards'   => 'Cards',
		'compact' => 'Compact',
	);

	private const PAGE_CREATED_OPTION = 'uxstudio_ai_search_page_created';

	private bool $enqueued = false;

	public function boot(): void {
		add_shortcode( 'uxs_search', array( $this, 'shortcode_field' ) );
		add_shortcode( 'uxs_search_results', array( $this, 'shortcode_results' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );

		GutenbergBlock::register( $this );

		if ( $this->opt( 'override_wp_search' ) ) {
			add_filter( 'get_search_form', array( $this, 'replace_search_form' ), 20 );
			add_filter( 'render_block_core/search', array( $this, 'replace_search_block' ), 20 );
			add_action( 'template_redirect', array( $this, 'redirect_wp_search' ), 1 );
			add_action( 'admin_init', array( $this, 'ensure_results_page' ) );
		}
	}

	public function rest_controller(): ?string {
		return null; // Public routes are registered by register_rest_routes().
	}

	public function register_rest_routes(): void {
		( new RestController( $this ) )->register_routes();
	}

	// ---------------------------------------------------------------------
	// Settings
	// ---------------------------------------------------------------------

	/**
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return array(
			'default_design'     => 'classic',
			'results_layout'     => 'list',
			'placeholder'        => __( 'Search…', 'ux-studio' ),
			'accent_color'       => '#2271b1',
			'popular_searches'   => '',
			'suggest_limit'      => 6,
			'min_chars'          => 2,
			'per_page'           => 10,
			'show_images'        => true,
			'ai_handoff'         => 'auto',
			'ai_threshold'       => 0,
			'ai_offer_text'      => __( 'Looking for “{query}”? I can help you with your search.', 'ux-studio' ),
			'ai_reply_text'      => __( 'Help me find: {query}', 'ux-studio' ),
			'ai_reply_label'     => __( 'Yes, help me find “{query}”', 'ux-studio' ),
			'override_wp_search' => false,
			'results_page_id'    => 0,
		);
	}

	/**
	 * Setting value with the module default.
	 *
	 * @return mixed
	 */
	public function opt( string $key ) {
		$defaults = $this->defaults();
		return $this->settings->get( $key, $defaults[ $key ] ?? null );
	}

	public function settings_schema(): array {
		$d = $this->defaults();

		$designs = array();
		foreach ( self::DESIGNS as $slug => $label ) {
			$designs[ $slug ] = __( $label, 'ux-studio' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- labels are listed in labels().
		}
		$layouts = array();
		foreach ( self::LAYOUTS as $slug => $label ) {
			$layouts[ $slug ] = __( $label, 'ux-studio' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		$pages = array( 0 => __( 'Created automatically', 'ux-studio' ) );
		foreach ( get_pages( array( 'number' => 200, 'post_status' => 'publish' ) ) as $page ) {
			$pages[ $page->ID ] = $page->post_title;
		}

		$section_look    = __( 'Appearance', 'ux-studio' );
		$section_search  = __( 'Search', 'ux-studio' );
		$section_ai      = __( 'AI Assistant', 'ux-studio' );
		$section_replace = __( 'WordPress search', 'ux-studio' );

		return array(
			array(
				'key'     => 'default_design',
				'section' => $section_look,
				'type'    => 'select',
				'label'   => __( 'Default search field design', 'ux-studio' ),
				'help'    => __( 'Used by [uxs_search] and the block unless a design is set there, e.g. [uxs_search design="pill"].', 'ux-studio' ),
				'options' => $designs,
				'default' => $d['default_design'],
			),
			array(
				'key'     => 'results_layout',
				'section' => $section_look,
				'type'    => 'select',
				'label'   => __( 'Results layout', 'ux-studio' ),
				'options' => $layouts,
				'default' => $d['results_layout'],
			),
			array(
				'key'     => 'accent_color',
				'section' => $section_look,
				'type'    => 'color',
				'label'   => __( 'Accent color', 'ux-studio' ),
				'default' => $d['accent_color'],
			),
			array(
				'key'     => 'placeholder',
				'section' => $section_look,
				'type'    => 'text',
				'label'   => __( 'Placeholder', 'ux-studio' ),
				'default' => $d['placeholder'],
			),
			array(
				'key'     => 'show_images',
				'section' => $section_look,
				'type'    => 'toggle',
				'label'   => __( 'Show thumbnails', 'ux-studio' ),
				'default' => $d['show_images'],
			),
			array(
				'key'     => 'popular_searches',
				'section' => $section_search,
				'type'    => 'text',
				'label'   => __( 'Popular searches', 'ux-studio' ),
				'help'    => __( 'Comma-separated. Shown when the field is focused and empty, and under the Hero design.', 'ux-studio' ),
				'default' => $d['popular_searches'],
			),
			array(
				'key'     => 'min_chars',
				'section' => $section_search,
				'type'    => 'number',
				'label'   => __( 'Start suggesting after (characters)', 'ux-studio' ),
				'default' => $d['min_chars'],
			),
			array(
				'key'     => 'suggest_limit',
				'section' => $section_search,
				'type'    => 'number',
				'label'   => __( 'Suggestions in the dropdown', 'ux-studio' ),
				'default' => $d['suggest_limit'],
			),
			array(
				'key'     => 'per_page',
				'section' => $section_search,
				'type'    => 'number',
				'label'   => __( 'Results per page', 'ux-studio' ),
				'default' => $d['per_page'],
			),
			array(
				'key'     => 'ai_handoff',
				'section' => $section_ai,
				'type'    => 'select',
				'label'   => __( 'Hand-off to the chat assistant', 'ux-studio' ),
				'help'    => __( 'Needs the AI Assistant module with the chat widget enabled. Automatic: the chat bubble opens on the results page and offers help with the search.', 'ux-studio' ),
				'options' => array(
					'off'    => __( 'Off', 'ux-studio' ),
					'button' => __( 'Button on the results page', 'ux-studio' ),
					'auto'   => __( 'Automatically open the chat', 'ux-studio' ),
				),
				'default' => $d['ai_handoff'],
			),
			array(
				'key'     => 'ai_threshold',
				'section' => $section_ai,
				'type'    => 'number',
				'label'   => __( 'Open automatically only with up to N results (0 = always)', 'ux-studio' ),
				'default' => $d['ai_threshold'],
			),
			array(
				'key'     => 'ai_offer_text',
				'section' => $section_ai,
				'type'    => 'textarea',
				'label'   => __( 'Message from the assistant', 'ux-studio' ),
				'help'    => __( 'Use {query} for what the visitor searched for.', 'ux-studio' ),
				'default' => $d['ai_offer_text'],
			),
			array(
				'key'     => 'ai_reply_label',
				'section' => $section_ai,
				'type'    => 'text',
				'label'   => __( 'Quick reply button', 'ux-studio' ),
				'default' => $d['ai_reply_label'],
			),
			array(
				'key'     => 'ai_reply_text',
				'section' => $section_ai,
				'type'    => 'text',
				'label'   => __( 'Message sent to the assistant when it is clicked', 'ux-studio' ),
				'default' => $d['ai_reply_text'],
			),
			array(
				'key'     => 'override_wp_search',
				'section' => $section_replace,
				'type'    => 'toggle',
				'label'   => __( 'Replace the WordPress search', 'ux-studio' ),
				'help'    => __( 'Swaps the theme search forms for the default design and redirects /?s= to the results page below.', 'ux-studio' ),
				'default' => $d['override_wp_search'],
			),
			array(
				'key'     => 'results_page_id',
				'section' => $section_replace,
				'type'    => 'select',
				'label'   => __( 'Results page', 'ux-studio' ),
				'help'    => __( 'A page containing [uxs_search_results]. When left on automatic, a "Search" page is created for you the first time.', 'ux-studio' ),
				'options' => $pages,
				'default' => $d['results_page_id'],
			),
		);
	}

	// ---------------------------------------------------------------------
	// Helpers used by Renderer / RestController
	// ---------------------------------------------------------------------

	public function design( string $requested ): string {
		if ( isset( self::DESIGNS[ $requested ] ) ) {
			return $requested;
		}
		$default = (string) $this->opt( 'default_design' );
		return isset( self::DESIGNS[ $default ] ) ? $default : 'classic';
	}

	public function layout( string $requested ): string {
		if ( isset( self::LAYOUTS[ $requested ] ) ) {
			return $requested;
		}
		$default = (string) $this->opt( 'results_layout' );
		return isset( self::LAYOUTS[ $default ] ) ? $default : 'list';
	}

	public function per_page(): int {
		return max( 3, min( 30, (int) $this->opt( 'per_page' ) ) );
	}

	public function suggest_limit(): int {
		return max( 1, min( 12, (int) $this->opt( 'suggest_limit' ) ) );
	}

	/**
	 * @return array<int, string>
	 */
	public function popular(): array {
		$terms = array_map( 'trim', explode( ',', (string) $this->opt( 'popular_searches' ) ) );
		return array_slice( array_values( array_filter( $terms, 'strlen' ) ), 0, 8 );
	}

	/**
	 * The page that lists results: the configured one, otherwise the home page
	 * (WordPress' own /?s= search).
	 */
	private function results_page(): ?\WP_Post {
		$id = (int) $this->opt( 'results_page_id' );
		if ( $id <= 0 ) {
			return null;
		}
		$page = get_post( $id );
		return ( $page instanceof \WP_Post && 'publish' === $page->post_status ) ? $page : null;
	}

	/** Form action: results page permalink without query string. */
	public function results_base(): string {
		$page = $this->results_page();
		if ( ! $page ) {
			return home_url( '/' );
		}
		$url = (string) get_permalink( $page );
		return (string) strtok( $url, '?' );
	}

	/** Name of the query-string parameter holding the search term. */
	public function query_param(): string {
		return $this->results_page() ? 'q' : 's';
	}

	public function results_url( string $q = '' ): string {
		$page = $this->results_page();
		$url  = $page ? (string) get_permalink( $page ) : home_url( '/' );
		return '' === $q ? $url : add_query_arg( $this->query_param(), rawurlencode( $q ), $url );
	}

	/** Current request's search term (results page and WP search). */
	public function request_query(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only public search.
		$raw = isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : ( isset( $_GET['s'] ) ? wp_unslash( $_GET['s'] ) : '' );
		// phpcs:enable
		return is_string( $raw ) ? SearchService::clean_query( $raw ) : '';
	}

	// ---------------------------------------------------------------------
	// Shortcodes
	// ---------------------------------------------------------------------

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function shortcode_field( $atts ): string {
		$atts = shortcode_atts(
			array(
				'design'      => '',
				'placeholder' => '',
			),
			$atts,
			'uxs_search'
		);
		return ( new Renderer( $this ) )->field( $atts );
	}

	/**
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function shortcode_results( $atts ): string {
		$atts = shortcode_atts(
			array(
				'design' => '',
				'layout' => '',
			),
			$atts,
			'uxs_search_results'
		);
		return ( new Renderer( $this ) )->results_page( $atts );
	}

	// ---------------------------------------------------------------------
	// Assets
	// ---------------------------------------------------------------------

	private static function mtime( string $rel ): string {
		$path = UXSTUDIO_PATH . $rel;
		return file_exists( $path ) ? (string) filemtime( $path ) : (string) UXSTUDIO_VERSION;
	}

	public function register_assets(): void {
		wp_register_style( 'uxstudio-ai-search', UXSTUDIO_URL . 'assets/css/ai-search.css', array(), self::mtime( 'assets/css/ai-search.css' ) );
		wp_register_script(
			'uxstudio-ai-search',
			UXSTUDIO_URL . 'assets/js/ai-search.js',
			array(),
			self::mtime( 'assets/js/ai-search.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Load CSS + JS (once). Called by the renderer, so pages without a search
	 * field load nothing.
	 */
	public function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}
		$this->enqueued = true;

		if ( ! wp_style_is( 'uxstudio-ai-search', 'registered' ) ) {
			$this->register_assets();
		}

		wp_enqueue_style( 'uxstudio-ai-search' );
		wp_add_inline_style( 'uxstudio-ai-search', ':root{--uxs-accent:' . ( sanitize_hex_color( (string) $this->opt( 'accent_color' ) ) ?: '#2271b1' ) . '}' );
		wp_enqueue_script( 'uxstudio-ai-search' );

		wp_localize_script(
			'uxstudio-ai-search',
			'uxstudioAiSearch',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'uxstudio/v1/ai-search' ) ),
				'minChars'   => max( 1, min( 5, (int) $this->opt( 'min_chars' ) ) ),
				'popular'    => $this->popular(),
				'resultsUrl' => $this->results_base(),
				'param'      => $this->query_param(),
				'ai'         => array(
					'mode'       => (string) $this->opt( 'ai_handoff' ),
					'threshold'  => max( 0, (int) $this->opt( 'ai_threshold' ) ),
					'offerText'  => (string) $this->opt( 'ai_offer_text' ),
					'replyText'  => (string) $this->opt( 'ai_reply_text' ),
					'replyLabel' => (string) $this->opt( 'ai_reply_label' ),
				),
				'i18n'       => array(
					'recent'  => __( 'Recent searches', 'ux-studio' ),
					'popular' => __( 'Popular searches', 'ux-studio' ),
					'error'   => __( 'Search is not available right now.', 'ux-studio' ),
				),
			)
		);
	}

	// ---------------------------------------------------------------------
	// WordPress search replacement
	// ---------------------------------------------------------------------

	public function replace_search_form(): string {
		return ( new Renderer( $this ) )->field();
	}

	public function replace_search_block(): string {
		return ( new Renderer( $this ) )->field();
	}

	/**
	 * Send /?s=term to the results page. The results page itself is excluded,
	 * and nothing is redirected when no usable results page exists.
	 */
	public function redirect_wp_search(): void {
		if ( ! is_search() || is_admin() || is_feed() ) {
			return;
		}
		$page = $this->results_page();
		if ( ! $page ) {
			return;
		}
		$q = $this->request_query();
		wp_safe_redirect( $this->results_url( $q ), 302 );
		exit;
	}

	/**
	 * Create the results page once (never recreated if the site owner deletes
	 * it) and select it in the settings.
	 */
	public function ensure_results_page(): void {
		if ( $this->results_page() || get_option( self::PAGE_CREATED_OPTION ) || ! current_user_can( 'publish_pages' ) ) {
			return;
		}
		update_option( self::PAGE_CREATED_OPTION, 1, false );

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Search', 'ux-studio' ),
				'post_name'    => sanitize_title( _x( 'search', 'results page slug', 'ux-studio' ) ),
				'post_content' => '[uxs_search_results]',
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			$current                    = (array) get_option( 'uxstudio_ai_search', array() );
			$current['results_page_id'] = (int) $id;
			update_option( 'uxstudio_ai_search', $current );
		}
	}

	/**
	 * Results pages are thin, endless URL space: keep them out of the index.
	 *
	 * @param array<string, mixed> $robots Robots directives.
	 * @return array<string, mixed>
	 */
	public function robots( array $robots ): array {
		$page = $this->results_page();
		if ( $page && is_page( $page->ID ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
		return $robots;
	}
}
