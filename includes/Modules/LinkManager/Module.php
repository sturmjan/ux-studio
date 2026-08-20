<?php
/**
 * Link Manager module - external link handling + speculative loading.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\LinkManager;

use UxStudio\Modules\BaseModule;

defined( 'ABSPATH' ) || exit;

/**
 * Processes the_content to make external links safer (rel, target, aria-label,
 * arrow icon) and configures WordPress 6.8+ speculative loading. Ported from
 * the legacy module (free + pro merged, no licence gates).
 */
final class Module extends BaseModule {

	/**
	 * Exclusion class map (per behaviour type).
	 *
	 * @var array<string, string[]>
	 */
	private array $exclude_classes = array();

	/**
	 * Whether any arrow was added on the current request.
	 */
	private bool $arrows_added = false;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		$this->exclude_classes = apply_filters(
			'ux_studio/link_manager/exclude_classes',
			$this->build_exclude_classes()
		);

		add_filter( 'the_content', array( $this, 'process_content' ) );
		add_action( 'wp_footer', array( $this, 'maybe_render_arrow' ) );

		$this->init_speculative_loading();
	}

	/**
	 * Build the exclusion class map, merging custom classes from settings.
	 *
	 * @return array<string, string[]>
	 */
	private function build_exclude_classes(): array {
		$classes = array(
			'arrow'      => array( 'wpe-exclude--arrow', 'wpe-no-arrow' ),
			'rel'        => array( 'wpe-exclude--rel', 'wpe-no-rel' ),
			'new-tab'    => array( 'wpe-exclude--new-tab', 'wpe-no-new-tab' ),
			'aria-label' => array( 'wpe-exclude--aria-label', 'wpe-no-aria-label' ),
			'all'        => array( 'wpe-exclude--link', 'wpe-no-external-link' ),
		);

		$custom = (string) $this->settings->get( 'exclude_classes', '' );
		if ( '' !== trim( $custom ) ) {
			$custom_classes  = array_map( 'trim', explode( ',', $custom ) );
			$classes['all']  = array_merge( $classes['all'], array_filter( $custom_classes ) );
		}

		return $classes;
	}

	/**
	 * Process HTML content, modifying external links.
	 *
	 * @param string $content Post content.
	 */
	public function process_content( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		$dom                     = new \DOMDocument();
		$dom->substituteEntities = false;

		libxml_use_internal_errors( true );

		$content = '<?xml encoding="UTF-8">' . $content;
		$dom->loadHTML( $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();

		$this->process_links( $dom );

		return (string) $dom->saveHTML();
	}

	/**
	 * Process every anchor in the document.
	 *
	 * @param \DOMDocument $dom Document.
	 */
	private function process_links( \DOMDocument $dom ): void {
		$links = $dom->getElementsByTagName( 'a' );
		if ( 0 === $links->length ) {
			return;
		}

		$links_array = array();
		foreach ( $links as $link ) {
			$links_array[] = $link;
		}

		foreach ( $links_array as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( '' === $href ) {
				continue;
			}

			if ( $this->should_skip_link( $link, $href ) ) {
				continue;
			}

			$has_window_open = $this->has_window_open_onclick( $link );
			if (
				'_blank' === $link->getAttribute( 'target' ) ||
				! $this->is_internal_url( $href ) ||
				$has_window_open
			) {
				$this->modify_link( $link, $href, $has_window_open );
			}
		}
	}

	/**
	 * Whether the link has a window.open() onclick.
	 *
	 * @param \DOMElement $link Link.
	 */
	private function has_window_open_onclick( \DOMElement $link ): bool {
		if ( ! $link->hasAttribute( 'onclick' ) ) {
			return false;
		}
		return false !== strpos( $link->getAttribute( 'onclick' ), 'window.open' );
	}

	/**
	 * Whether the link (or an ancestor) has an exclusion class of a given type.
	 *
	 * @param \DOMElement $link Link.
	 * @param string      $type Exclusion type key.
	 */
	private function has_exclusion_class( \DOMElement $link, string $type ): bool {
		if ( $link->hasAttribute( 'class' ) ) {
			$link_classes = $link->getAttribute( 'class' );
			foreach ( $this->exclude_classes[ $type ] as $class ) {
				if ( false !== strpos( $link_classes, $class ) ) {
					return true;
				}
			}
		}

		$current = $link->parentNode;
		while ( $current && XML_ELEMENT_NODE === $current->nodeType ) {
			if ( $current instanceof \DOMElement && $current->hasAttribute( 'class' ) ) {
				$parent_classes = $current->getAttribute( 'class' );
				foreach ( $this->exclude_classes[ $type ] as $class ) {
					if ( false !== strpos( $parent_classes, $class ) ) {
						return true;
					}
				}
			}
			$current = $current->parentNode;
		}

		return false;
	}

	/**
	 * Whether a link should be skipped entirely.
	 *
	 * @param \DOMElement $link Link.
	 * @param string      $href Href.
	 */
	private function should_skip_link( \DOMElement $link, string $href ): bool {
		if ( 0 === strpos( $href, 'mailto:' ) ) {
			return true;
		}
		if ( 0 === strpos( $href, 'tel:' ) ) {
			return true;
		}
		if ( $this->is_internal_url( $href ) && '_blank' !== $link->getAttribute( 'target' ) ) {
			return true;
		}
		return $this->has_exclusion_class( $link, 'all' );
	}

	/**
	 * Apply attributes/elements to an external link.
	 *
	 * @param \DOMElement $link            Link.
	 * @param string      $href            Href.
	 * @param bool        $has_window_open Whether it has window.open onclick.
	 */
	private function modify_link( \DOMElement $link, string $href, bool $has_window_open = false ): void {
		if ( ! $this->has_exclusion_class( $link, 'rel' ) && ! $this->is_internal_url( $href ) ) {
			$this->add_rel_attributes( $link );
		}

		if (
			! $this->has_exclusion_class( $link, 'new-tab' ) &&
			( $has_window_open || ! $this->is_internal_url( $href ) ) &&
			'_blank' !== $link->getAttribute( 'target' )
		) {
			$link->setAttribute( 'target', '_blank' );
		}

		$this->add_aria_label( $link );

		if ( ! $this->settings->get( 'disable_arrow', false ) && ! $this->has_exclusion_class( $link, 'arrow' ) ) {
			$this->add_svg_arrow( $link );
		}
	}

	/**
	 * Add rel="noopener noreferrer nofollow".
	 *
	 * @param \DOMElement $link Link.
	 */
	private function add_rel_attributes( \DOMElement $link ): void {
		if ( $this->has_exclusion_class( $link, 'rel' ) ) {
			return;
		}

		$rel_attributes = array( 'noopener', 'noreferrer', 'nofollow' );
		$current_rel    = $link->getAttribute( 'rel' );
		$current_array  = '' === $current_rel ? array() : explode( ' ', $current_rel );

		foreach ( $rel_attributes as $attr ) {
			if ( ! in_array( $attr, $current_array, true ) ) {
				$current_array[] = $attr;
			}
		}

		$link->setAttribute( 'rel', implode( ' ', $current_array ) );
	}

	/**
	 * Add or extend the aria-label to note the new tab behaviour.
	 *
	 * @param \DOMElement $link Link.
	 */
	private function add_aria_label( \DOMElement $link ): void {
		if ( $this->has_exclusion_class( $link, 'aria-label' ) || $this->has_exclusion_class( $link, 'new-tab' ) ) {
			return;
		}

		/* translators: appended to the aria-label of external links opening in a new tab. */
		$new_tab_text = __( ' opens in a new tab', 'ux-studio' );

		if ( $link->hasAttribute( 'aria-label' ) ) {
			$aria_label = $link->getAttribute( 'aria-label' );
			if ( false === strpos( $aria_label, $new_tab_text ) ) {
				$link->setAttribute( 'aria-label', sprintf( '%s,%s', $aria_label, $new_tab_text ) );
			}
		} else {
			$link_text = $link->textContent;
			if ( '' !== $link_text ) {
				$link->setAttribute( 'aria-label', sprintf( '%s,%s', $link_text, $new_tab_text ) );
			}
		}
	}

	/**
	 * Append the SVG arrow icon inside the link.
	 *
	 * @param \DOMElement $link Link.
	 */
	private function add_svg_arrow( \DOMElement $link ): void {
		$xpath           = new \DOMXPath( $link->ownerDocument );
		$existing_arrows = $xpath->query( './/svg[contains(@class, "uxstudio-external-link")]', $link );

		if ( $existing_arrows && $existing_arrows->length > 0 ) {
			return;
		}

		$svg = $link->ownerDocument->createElement( 'svg' );
		$svg->setAttribute( 'class', 'uxstudio-external-link' );
		$svg->setAttribute( 'aria-hidden', 'true' );

		$use = $link->ownerDocument->createElement( 'use' );
		$use->setAttribute( 'xlink:href', '#uxstudio-external-link' );

		$svg->appendChild( $use );
		$link->appendChild( $svg );

		$this->arrows_added = true;
	}

	/**
	 * Whether a URL points to this site.
	 *
	 * @param string $url URL.
	 */
	private function is_internal_url( string $url ): bool {
		$url = trim( $url );

		if ( 0 === strpos( $url, '#' ) || 0 === strpos( $url, '/' ) || 0 === strpos( $url, '?' ) ) {
			return true;
		}

		$parsed_url      = wp_parse_url( $url );
		$home_url        = home_url();
		$parsed_home_url = wp_parse_url( $home_url );

		if ( ! isset( $parsed_url['scheme'], $parsed_url['host'] ) ) {
			return false;
		}

		if (
			isset( $parsed_home_url['host'], $parsed_home_url['scheme'] ) &&
			$parsed_url['host'] === $parsed_home_url['host'] &&
			$parsed_url['scheme'] === $parsed_home_url['scheme']
		) {
			return true;
		}

		return 0 === strpos( $url, $home_url );
	}

	/**
	 * Output the shared SVG arrow symbol in the footer (only if used).
	 */
	public function maybe_render_arrow(): void {
		if ( ! $this->arrows_added || $this->settings->get( 'disable_arrow', false ) ) {
			return;
		}
		?>
		<style>
			.uxstudio-external-link {
				width: 1em;
				height: 1em;
			}
		</style>
		<svg class="uxstudio-external-link" aria-hidden="true" style="position: absolute; width: 0; height: 0; overflow: hidden;">
			<symbol viewBox="0 0 256 256" id="uxstudio-external-link">
				<path fill="currentColor" d="M200 64v104a8 8 0 0 1-16 0V83.31L69.66 197.66a8 8 0 0 1-11.32-11.32L172.69 72H88a8 8 0 0 1 0-16h104a8 8 0 0 1 8 8"></path>
			</symbol>
		</svg>
		<?php
	}

	/**
	 * Configure WordPress 6.8+ speculative loading based on settings.
	 */
	private function init_speculative_loading(): void {
		if ( ! version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ) {
			return;
		}

		if ( ! $this->settings->get( 'disable_speculative_loading', false ) ) {
			add_filter( 'wp_speculation_rules_configuration', array( $this, 'configure_speculative_loading' ) );
			add_filter( 'wp_speculation_rules_href_exclude_paths', array( $this, 'get_excluded_paths' ), 10, 2 );
			return;
		}

		add_filter( 'wp_speculation_rules_configuration', '__return_null', 10 );
	}

	/**
	 * Apply mode/eagerness overrides to the speculation config.
	 *
	 * @param mixed $config Configuration array.
	 * @return mixed
	 */
	public function configure_speculative_loading( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}

		$mode      = (string) $this->settings->get( 'speculative_loading_mode', 'auto' );
		$eagerness = (string) $this->settings->get( 'speculative_loading_eagerness', 'auto' );

		if ( 'auto' !== $mode ) {
			$config['mode'] = $mode;
		}
		if ( 'auto' !== $eagerness ) {
			$config['eagerness'] = $eagerness;
		}

		return $config;
	}

	/**
	 * Merge configured exclude paths into the speculation exclusions.
	 *
	 * @param mixed  $href_exclude_paths Current exclude paths.
	 * @param string $mode               Speculation mode.
	 * @return array
	 */
	public function get_excluded_paths( $href_exclude_paths, string $mode ): array {
		$exclude_paths           = (string) $this->settings->get( 'exclude_paths', '' );
		$exclude_prerender_paths = (string) $this->settings->get( 'exclude_prerender_paths', '' );

		if ( ! is_array( $href_exclude_paths ) ) {
			$href_exclude_paths = array();
		}

		if ( '' !== $exclude_paths ) {
			$paths = array_filter( array_map( 'trim', explode( "\n", $exclude_paths ) ) );
			if ( ! empty( $paths ) ) {
				$href_exclude_paths = array_merge( $href_exclude_paths, $paths );
			}
		}

		if ( 'prerender' === $mode && '' !== $exclude_prerender_paths ) {
			$paths = array_filter( array_map( 'trim', explode( "\n", $exclude_prerender_paths ) ) );
			if ( ! empty( $paths ) ) {
				$href_exclude_paths = array_merge( $href_exclude_paths, $paths );
			}
		}

		return $href_exclude_paths;
	}

	/**
	 * Settings schema for the generic renderer.
	 */
	public function settings_schema(): array {
		$schema = array(
			array(
				'key'     => 'exclude_classes',
				'type'    => 'text',
				'label'   => __( 'Exclude classes', 'ux-studio' ),
				'help'    => __( 'Comma-separated list of CSS classes to exclude from external link processing. Built-in per-link classes: wpe-exclude--link, wpe-exclude--arrow, wpe-exclude--rel, wpe-exclude--new-tab, wpe-exclude--aria-label.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'disable_arrow',
				'type'    => 'toggle',
				'label'   => __( 'Disable external link arrow', 'ux-studio' ),
				'help'    => __( 'Not recommended as it may cause accessibility issues.', 'ux-studio' ),
				'default' => false,
			),
		);

		if ( version_compare( get_bloginfo( 'version' ), '6.8', '>=' ) ) {
			$schema[] = array(
				'key'     => 'disable_speculative_loading',
				'type'    => 'toggle',
				'label'   => __( 'Disable speculative loading', 'ux-studio' ),
				'help'    => __( 'Completely disable the WordPress 6.8+ speculative loading feature.', 'ux-studio' ),
				'default' => false,
			);
			$schema[] = array(
				'key'     => 'speculative_loading_mode',
				'type'    => 'select',
				'label'   => __( 'Loading mode', 'ux-studio' ),
				'help'    => __( 'Choose how aggressively to preload links.', 'ux-studio' ),
				'options' => array(
					'auto'      => __( 'Auto (WordPress default)', 'ux-studio' ),
					'prefetch'  => __( 'Prefetch', 'ux-studio' ),
					'prerender' => __( 'Prerender', 'ux-studio' ),
				),
				'default' => 'auto',
			);
			$schema[] = array(
				'key'     => 'speculative_loading_eagerness',
				'type'    => 'select',
				'label'   => __( 'Loading eagerness', 'ux-studio' ),
				'help'    => __( 'Choose how early to start preloading links.', 'ux-studio' ),
				'options' => array(
					'auto'         => __( 'Auto (WordPress default)', 'ux-studio' ),
					'conservative' => __( 'Conservative', 'ux-studio' ),
					'moderate'     => __( 'Moderate', 'ux-studio' ),
					'eager'        => __( 'Eager', 'ux-studio' ),
				),
				'default' => 'auto',
			);
			$schema[] = array(
				'key'     => 'exclude_paths',
				'type'    => 'textarea',
				'label'   => __( 'Exclude paths', 'ux-studio' ),
				'help'    => __( 'URL patterns to exclude from speculative loading, one per line (e.g. /cart/*, /checkout/*).', 'ux-studio' ),
				'default' => '',
			);
			$schema[] = array(
				'key'     => 'exclude_prerender_paths',
				'type'    => 'textarea',
				'label'   => __( 'Exclude from prerender', 'ux-studio' ),
				'help'    => __( 'URL patterns to exclude only from prerendering, one per line (e.g. /personalized-area/*).', 'ux-studio' ),
				'default' => '',
			);
		}

		return $schema;
	}
}
