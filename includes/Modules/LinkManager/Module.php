<?php
/**
 * Link Manager module - external link handling + speculative loading.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\LinkManager;

use UxStudio\Modules\BaseModule;
use UxStudio\Modules\MenuIcons\IconLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Processes the_content to make external links safer (rel, target, aria-label,
 * icon) and configures WordPress 6.8+ speculative loading. Ported from
 * the legacy module (free + pro merged, no licence gates).
 *
 * Rules (B3): default target / rel / icon, rules per domain (first matching line wins), a list of addresses that
 * are never external. The content is parsed ONCE (one DOMDocument per the_content call, and none at all for content
 * without a link); the decision for an address is cached for the request.
 */
final class Module extends BaseModule {

	/**
	 * Exclusion class map (per behaviour type).
	 *
	 * @var array<string, string[]>
	 */
	private array $exclude_classes = array();

	/**
	 * Whether any SVG arrow was added on the current request.
	 */
	private bool $arrows_added = false;

	/**
	 * Whether any Dashicon was added on the current request.
	 */
	private bool $dashicons_added = false;

	/**
	 * Configuration read once per request: null until first use.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $config = null;

	/**
	 * Decision cache per href.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $decisions = array();

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
	 * The effective configuration (settings parsed once per request).
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		if ( null !== $this->config ) {
			return $this->config;
		}

		$icon = (string) $this->settings->get( 'icon_type', '' );
		if ( ! in_array( $icon, array( 'arrow', 'none', 'dashicon' ), true ) ) {
			// Sites saved before the icon choice existed only had the "disable arrow" switch.
			$icon = $this->settings->get( 'disable_arrow', false ) ? 'none' : 'arrow';
		}
		$dashicon = (string) $this->settings->get( 'icon_dashicon', 'dashicons-external' );
		if ( ! IconLibrary::dashicon_exists( $dashicon ) ) {
			$dashicon = 'dashicons-external';
		}

		$stored_rel = $this->settings->get( 'default_rel', null );
		$rel        = null === $stored_rel ? LinkRules::DEFAULT_REL : LinkRules::parse_rel( (string) $stored_rel );

		$home = LinkRules::parts( home_url() );

		$this->config = array(
			'icon'          => $icon,
			'dashicon'      => $dashicon,
			'icon_position' => 'left' === $this->settings->get( 'icon_position', 'right' ) ? 'left' : 'right',
			'skip_images'   => (bool) $this->settings->get( 'icon_skip_images', false ),
			'new_tab'       => 'keep' !== $this->settings->get( 'default_target', 'new_tab' ),
			'rel'           => $rel,
			'rules'         => LinkRules::parse_rules( (string) $this->settings->get( 'link_rules', '' ) )['rules'],
			'internal'      => LinkRules::parse_patterns( (string) $this->settings->get( 'internal_domains', '' ) ),
			'subdomains'    => (bool) $this->settings->get( 'subdomains_internal', false ),
			'home_host'     => $home ? $home['host'] : '',
		);

		return $this->config;
	}

	/**
	 * Settings values; a site saved before the icon choice existed shows its old "disable arrow" as icon "none".
	 *
	 * @return array<string, mixed>
	 */
	public function settings_values(): array {
		$values = parent::settings_values();
		if ( '' === (string) $this->settings->get( 'icon_type', '' ) && $this->settings->get( 'disable_arrow', false ) ) {
			$values['icon_type'] = 'none';
		}
		return $values;
	}

	/**
	 * Process HTML content, modifying external links.
	 *
	 * @param string $content Post content.
	 */
	public function process_content( string $content ): string {
		// No link, nothing to do: skip the DOM parser altogether.
		if ( '' === $content || 1 !== preg_match( '/<a[\s>]/i', $content ) ) {
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

			$decision = $this->decide( $href );
			if ( $this->should_skip_link( $link, $decision ) ) {
				continue;
			}

			$has_window_open = $this->has_window_open_onclick( $link );
			if (
				'_blank' === $link->getAttribute( 'target' ) ||
				'external' === $decision['kind'] ||
				$has_window_open
			) {
				$this->modify_link( $link, $decision, $has_window_open );
			}
		}
	}

	/**
	 * What to do with an address: its kind and the matching rule. Cached per href for the request.
	 *
	 * @param string $href Href.
	 * @return array{kind:string,rule:?array<string,mixed>}
	 */
	public function decide( string $href ): array {
		if ( isset( $this->decisions[ $href ] ) ) {
			return $this->decisions[ $href ];
		}

		$config = $this->config();
		$info   = LinkRules::classify( $href, (string) $config['home_host'], (bool) $config['subdomains'], (array) $config['internal'] );
		$rule   = 'external' === $info['kind'] ? LinkRules::find_rule( (array) $config['rules'], $info['host'], $info['host_path'] ) : null;

		// Bound the cache: a page with thousands of different addresses must not grow it without limit.
		if ( count( $this->decisions ) > 2000 ) {
			$this->decisions = array();
		}
		$this->decisions[ $href ] = array(
			'kind' => $info['kind'],
			'rule' => $rule,
		);

		return $this->decisions[ $href ];
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
	 * @param \DOMElement                              $link     Link.
	 * @param array{kind:string,rule:?array<string,mixed>} $decision What the address is.
	 */
	private function should_skip_link( \DOMElement $link, array $decision ): bool {
		// mailto:, tel:, javascript:, anchors and every other non-web address.
		if ( in_array( $decision['kind'], array( 'skip', 'anchor' ), true ) ) {
			return true;
		}
		if ( 'internal' === $decision['kind'] && '_blank' !== $link->getAttribute( 'target' ) ) {
			return true;
		}
		return $this->has_exclusion_class( $link, 'all' );
	}

	/**
	 * Apply attributes/elements to an external link.
	 *
	 * @param \DOMElement                              $link            Link.
	 * @param array{kind:string,rule:?array<string,mixed>} $decision        What the address is.
	 * @param bool                                     $has_window_open Whether it has window.open onclick.
	 */
	private function modify_link( \DOMElement $link, array $decision, bool $has_window_open = false ): void {
		$config   = $this->config();
		$external = 'external' === $decision['kind'];
		$rule     = $decision['rule'];

		if ( $external && ! $this->has_exclusion_class( $link, 'rel' ) ) {
			$tokens = null !== $rule && null !== $rule['rel'] ? (array) $rule['rel'] : (array) $config['rel'];
			$this->add_rel_attributes( $link, $tokens );
		}

		$new_tab = null !== $rule && null !== $rule['tab'] ? (bool) $rule['tab'] : (bool) $config['new_tab'];
		if (
			! $this->has_exclusion_class( $link, 'new-tab' ) &&
			( $has_window_open || $external ) &&
			$new_tab &&
			'_blank' !== $link->getAttribute( 'target' )
		) {
			$link->setAttribute( 'target', '_blank' );
		}

		// The note "opens in a new tab" only where the link really does.
		if ( '_blank' === $link->getAttribute( 'target' ) ) {
			$this->add_aria_label( $link );
		}

		$icon_on = null !== $rule && null !== $rule['icon'] ? (bool) $rule['icon'] : 'none' !== $config['icon'];
		if ( $icon_on && ! $this->has_exclusion_class( $link, 'arrow' ) && ! ( $config['skip_images'] && $link->getElementsByTagName( 'img' )->length > 0 ) ) {
			$this->add_icon( $link );
		}
	}

	/**
	 * Add the rel tokens (existing tokens stay).
	 *
	 * @param \DOMElement $link   Link.
	 * @param string[]    $tokens Tokens to add.
	 */
	private function add_rel_attributes( \DOMElement $link, array $tokens ): void {
		if ( array() === $tokens ) {
			return;
		}
		$link->setAttribute( 'rel', LinkRules::merge_rel( $link->getAttribute( 'rel' ), $tokens ) );
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
	 * Add the icon (arrow or Dashicon) inside the link, once.
	 *
	 * @param \DOMElement $link Link.
	 */
	private function add_icon( \DOMElement $link ): void {
		$config = $this->config();
		$doc    = $link->ownerDocument;

		foreach ( $link->childNodes as $child ) {
			// Already has one of ours (content filtered twice, saved by hand).
			if ( $child instanceof \DOMElement && false !== strpos( $child->getAttribute( 'class' ), 'uxstudio-external-link' ) ) {
				return;
			}
		}

		if ( 'dashicon' === $config['icon'] ) {
			$icon = $doc->createElement( 'span' );
			$icon->setAttribute( 'class', 'uxstudio-external-link uxstudio-external-dashicon dashicons ' . $config['dashicon'] );
			$icon->setAttribute( 'aria-hidden', 'true' );
			$this->dashicons_added = true;
			if ( ! wp_style_is( 'dashicons', 'enqueued' ) ) {
				wp_enqueue_style( 'dashicons' );
			}
		} else {
			$icon = $doc->createElement( 'svg' );
			$icon->setAttribute( 'class', 'uxstudio-external-link' );
			$icon->setAttribute( 'aria-hidden', 'true' );
			$use = $doc->createElement( 'use' );
			$use->setAttribute( 'xlink:href', '#uxstudio-external-link' );
			$icon->appendChild( $use );
			$this->arrows_added = true;
		}

		if ( 'left' === $config['icon_position'] && $link->firstChild ) {
			$link->insertBefore( $icon, $link->firstChild );
		} else {
			$link->appendChild( $icon );
		}
	}

	/**
	 * Output the shared SVG arrow symbol in the footer (only if used).
	 */
	public function maybe_render_arrow(): void {
		if ( ! $this->arrows_added && ! $this->dashicons_added ) {
			return;
		}
		?>
		<style>
			.uxstudio-external-link {
				width: 1em;
				height: 1em;
			}
			.uxstudio-external-dashicon {
				font-size: 1em;
				line-height: 1;
				vertical-align: -0.1em;
				text-decoration: none;
			}
		</style>
		<?php if ( $this->arrows_added ) : ?>
		<svg class="uxstudio-external-link" aria-hidden="true" style="position: absolute; width: 0; height: 0; overflow: hidden;">
			<symbol viewBox="0 0 256 256" id="uxstudio-external-link">
				<path fill="currentColor" d="M200 64v104a8 8 0 0 1-16 0V83.31L69.66 197.66a8 8 0 0 1-11.32-11.32L172.69 72H88a8 8 0 0 1 0-16h104a8 8 0 0 1 8 8"></path>
			</symbol>
		</svg>
		<?php endif; ?>
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
		$general = __( 'Links', 'ux-studio' );
		$rules   = __( 'Rules', 'ux-studio' );
		$spec    = __( 'Speculative loading', 'ux-studio' );

		$schema = array(
			array(
				'key'     => 'default_target',
				'section' => $general,
				'type'    => 'select',
				'label'   => __( 'External links open', 'ux-studio' ),
				'help'    => __( 'Where external links open. "Keep as is" leaves the target the author set.', 'ux-studio' ),
				'options' => array(
					'new_tab' => __( 'In a new tab', 'ux-studio' ),
					'keep'    => __( 'Keep as is', 'ux-studio' ),
				),
				'default' => 'new_tab',
			),
			array(
				'key'     => 'default_rel',
				'section' => $general,
				'type'    => 'text',
				'label'   => __( 'rel attribute of external links', 'ux-studio' ),
				'help'    => __( 'Tokens added to external links, separated by spaces: noopener, noreferrer, nofollow, sponsored, ugc, external. Leave empty to add nothing.', 'ux-studio' ),
				'default' => implode( ' ', LinkRules::DEFAULT_REL ),
			),
			array(
				'key'     => 'icon_type',
				'section' => $general,
				'type'    => 'select',
				'label'   => __( 'Icon of external links', 'ux-studio' ),
				'help'    => __( 'The arrow of the module, a Dashicon of your choice, or no icon. Not recommended to switch it off: it may cause accessibility issues.', 'ux-studio' ),
				'options' => array(
					'arrow'    => __( 'Arrow', 'ux-studio' ),
					'dashicon' => __( 'Dashicon', 'ux-studio' ),
					'none'     => __( 'No icon', 'ux-studio' ),
				),
				'default' => 'arrow',
			),
			array(
				'key'     => 'icon_dashicon',
				'section' => $general,
				'type'    => 'select',
				'label'   => __( 'Dashicon', 'ux-studio' ),
				'help'    => __( 'Used when the icon is a Dashicon.', 'ux-studio' ),
				'options' => IconLibrary::dashicon_choices(),
				'default' => 'dashicons-external',
			),
			array(
				'key'     => 'icon_position',
				'section' => $general,
				'type'    => 'select',
				'label'   => __( 'Icon position', 'ux-studio' ),
				'options' => array(
					'right' => __( 'After the link text', 'ux-studio' ),
					'left'  => __( 'Before the link text', 'ux-studio' ),
				),
				'default' => 'right',
			),
			array(
				'key'     => 'icon_skip_images',
				'section' => $general,
				'type'    => 'toggle',
				'label'   => __( 'No icon on links that contain an image', 'ux-studio' ),
				'help'    => __( 'Image links (logos, banners) do not get the icon.', 'ux-studio' ),
				'default' => false,
			),
			array(
				'key'     => 'exclude_classes',
				'section' => $general,
				'type'    => 'text',
				'label'   => __( 'Exclude classes', 'ux-studio' ),
				'help'    => __( 'Comma-separated list of CSS classes to exclude from external link processing. Built-in per-link classes: wpe-exclude--link, wpe-exclude--arrow, wpe-exclude--rel, wpe-exclude--new-tab, wpe-exclude--aria-label.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'link_rules',
				'section' => $rules,
				'type'    => 'textarea',
				'label'   => __( 'Rules per domain', 'ux-studio' ),
				'help'    => __( 'One rule per line: an address, then any of tab=yes|no (open in a new tab), rel=tokens separated by commas (exactly these are added, none = nothing) and icon=yes|no. The first matching line wins. Examples: partner.example tab=no rel=none / *.shop.example rel=sponsored,nofollow / example.org/affiliate/* rel=sponsored icon=no. An address without a star is that host (www is ignored), *.example.com covers its subdomains, a slash makes it a path. Lines starting with # are comments.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'internal_domains',
				'section' => $rules,
				'type'    => 'textarea',
				'label'   => __( 'Never treat as external', 'ux-studio' ),
				'help'    => __( 'Addresses that are not processed as external links, for example your own other domains and subdomains. One per line or separated by commas, * stands for any characters: shop.example.com, *.example.com, example.org/blog/*.', 'ux-studio' ),
				'default' => '',
			),
			array(
				'key'     => 'subdomains_internal',
				'section' => $rules,
				'type'    => 'toggle',
				'label'   => __( 'Subdomains of this site are internal', 'ux-studio' ),
				'help'    => __( 'Links to any subdomain of the domain of this site are not treated as external.', 'ux-studio' ),
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

		foreach ( $schema as $i => $field ) {
			if ( ! isset( $field['section'] ) ) {
				$schema[ $i ]['section'] = $spec;
			}
		}

		return $schema;
	}
}
