<?php
/**
 * Markup for the search field, the suggestion dropdown and the results page.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Single place that produces HTML, used by the shortcodes, the Gutenberg
 * blocks and the REST endpoints alike, so server render and AJAX updates can
 * never drift apart. Everything user- or content-derived is escaped here.
 */
final class Renderer {

	private static int $instance = 0;

	private Module $module;

	public function __construct( Module $module ) {
		$this->module = $module;
	}

	// ---------------------------------------------------------------------
	// Search field
	// ---------------------------------------------------------------------

	/**
	 * @param array{design?:string,placeholder?:string,live?:bool,suggest?:bool,value?:string} $atts Field options.
	 */
	public function field( array $atts = array() ): string {
		$this->module->enqueue();
		++self::$instance;
		$n = self::$instance;

		$design      = $this->module->design( (string) ( $atts['design'] ?? '' ) );
		$placeholder = (string) ( $atts['placeholder'] ?? '' );
		if ( '' === $placeholder ) {
			$placeholder = (string) $this->module->opt( 'placeholder' );
		}
		$suggest = ! isset( $atts['suggest'] ) || $atts['suggest'];
		$live    = ! empty( $atts['live'] );
		$value   = (string) ( $atts['value'] ?? '' );
		$input   = 'uxs-input-' . $n;
		$list    = 'uxs-list-' . $n;
		$label   = __( 'Search', 'ux-studio' );

		$hidden = '';
		$query  = wp_parse_url( $this->module->results_url(), PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $args );
			foreach ( $args as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$hidden .= '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">';
				}
			}
		}

		$form = '<form class="uxs-search__form" role="search" method="get" action="' . esc_url( $this->module->results_base() ) . '">'
			. $hidden
			. '<label class="uxs-sr" for="' . esc_attr( $input ) . '">' . esc_html( $label ) . '</label>'
			. '<div class="uxs-search__box">'
			. '<span class="uxs-search__icon" aria-hidden="true">' . ( 'hero' === $design ? self::icon( 'sparkle' ) : self::icon( 'search' ) ) . '</span>'
			. '<input id="' . esc_attr( $input ) . '" class="uxs-search__input" type="search" name="' . esc_attr( $this->module->query_param() ) . '"'
			. ' value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" maxlength="' . (int) SearchService::MAX_QUERY . '"'
			. ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $list ) . '">'
			. '<span class="uxs-search__spinner" aria-hidden="true"></span>'
			. ( 'command' === $design ? '<kbd class="uxs-search__kbd" aria-hidden="true">Ctrl K</kbd>' : '' )
			. '<button class="uxs-search__submit" type="submit"><span class="uxs-search__submit-label">' . esc_html( $label ) . '</span><span class="uxs-search__submit-icon" aria-hidden="true">' . self::icon( 'search' ) . '</span></button>'
			. '</div>'
			. '<div class="uxs-suggest" id="' . esc_attr( $list ) . '" role="listbox" aria-label="' . esc_attr__( 'Suggestions', 'ux-studio' ) . '" hidden></div>'
			. '</form>';

		if ( 'hero' === $design ) {
			$form .= $this->popular_chips();
		}

		$attrs = 'class="uxs-search uxs-search--' . esc_attr( $design ) . '" data-uxs-search data-suggest="' . ( $suggest ? '1' : '0' ) . '" data-live="' . ( $live ? '1' : '0' ) . '"';

		if ( 'overlay' === $design ) {
			return '<div ' . $attrs . '>'
				. '<button class="uxs-search__trigger" type="button" aria-haspopup="dialog" aria-expanded="false" data-uxs-open><span class="uxs-sr">' . esc_html( $label ) . '</span>' . self::icon( 'search' ) . '</button>'
				. '<div class="uxs-overlay" role="dialog" aria-modal="true" aria-label="' . esc_attr( $label ) . '" hidden>'
				. '<button class="uxs-overlay__close" type="button" data-uxs-close aria-label="' . esc_attr__( 'Close', 'ux-studio' ) . '">' . self::icon( 'close' ) . '</button>'
				. '<div class="uxs-overlay__inner">' . $form . '</div></div></div>';
		}

		return '<div ' . $attrs . '>' . $form . '</div>';
	}

	private function popular_chips(): string {
		$terms = $this->module->popular();
		if ( ! $terms ) {
			return '';
		}
		$out = '<p class="uxs-popular"><span class="uxs-popular__label">' . esc_html__( 'Popular:', 'ux-studio' ) . '</span>';
		foreach ( $terms as $term ) {
			$out .= '<a class="uxs-chip" href="' . esc_url( $this->module->results_url( $term ) ) . '">' . esc_html( $term ) . '</a>';
		}
		return $out . '</p>';
	}

	// ---------------------------------------------------------------------
	// Suggestions dropdown
	// ---------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $items Items from SearchService.
	 */
	public function suggestions( array $items, string $q ): string {
		if ( ! $items ) {
			return '<div class="uxs-suggest__empty">' . esc_html(
				/* translators: %s: search query */
				sprintf( __( 'Nothing found for “%s”.', 'ux-studio' ), $q )
			) . '</div>'
			. '<a class="uxs-suggest__all" role="option" href="' . esc_url( $this->module->results_url( $q ) ) . '">' . esc_html__( 'Search everywhere', 'ux-studio' ) . '</a>';
		}

		$show_images = (bool) $this->module->opt( 'show_images' );
		$out         = '';
		foreach ( $items as $item ) {
			$out .= '<a class="uxs-suggest__item" role="option" href="' . esc_url( (string) $item['url'] ) . '">';
			if ( $show_images ) {
				$out .= ! empty( $item['image'] )
					? '<img class="uxs-suggest__thumb" src="' . esc_url( (string) $item['image'] ) . '" alt="" loading="lazy" width="40" height="40">'
					: '<span class="uxs-suggest__thumb uxs-suggest__thumb--empty" aria-hidden="true"></span>';
			}
			$out .= '<span class="uxs-suggest__text"><span class="uxs-suggest__title">' . SearchService::highlight( (string) $item['title'], $q ) . '</span>'
				. '<span class="uxs-suggest__meta">' . esc_html( (string) $item['type_label'] ) . $this->props_inline( (array) $item['props'], $q, 1 ) . '</span></span>'
				. ( '' !== (string) $item['price'] ? '<span class="uxs-suggest__price">' . wp_kses_post( (string) $item['price'] ) . '</span>' : '' )
				. '</a>';
		}

		return $out . '<a class="uxs-suggest__all" role="option" href="' . esc_url( $this->module->results_url( $q ) ) . '">'
			. esc_html(
				/* translators: %s: search query */
				sprintf( __( 'Show all results for “%s”', 'ux-studio' ), $q )
			) . self::icon( 'arrow' ) . '</a>';
	}

	// ---------------------------------------------------------------------
	// Results
	// ---------------------------------------------------------------------

	/**
	 * Full results block: field + body. Used by [uxs_search_results], the block
	 * and the page created for the "replace WordPress search" option.
	 *
	 * @param array{design?:string,layout?:string,q?:string} $atts Options.
	 */
	public function results_page( array $atts = array() ): string {
		$this->module->enqueue();
		$q = SearchService::clean_query( (string) ( $atts['q'] ?? $this->module->request_query() ) );

		$layout = $this->module->layout( (string) ( $atts['layout'] ?? '' ) );
		$field  = $this->field(
			array(
				'design'  => $atts['design'] ?? '',
				'live'    => true,
				'suggest' => false,
				'value'   => $q,
			)
		);

		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public search.

		return '<div class="uxs-results uxs-results--' . esc_attr( $layout ) . '" data-uxs-results data-layout="' . esc_attr( $layout ) . '">'
			. '<div class="uxs-results__field">' . $field . '</div>'
			. '<div class="uxs-results__body" aria-live="polite">' . $this->results_body( $q, 1, $type, $layout ) . '</div>'
			. '</div>';
	}

	/**
	 * Summary, type chips, items, "load more" and the assistant offer.
	 */
	public function results_body( string $q, int $page, string $type, string $layout ): string {
		$q = SearchService::clean_query( $q );
		if ( mb_strlen( $q ) < 2 ) {
			return '<p class="uxs-results__hint">' . esc_html__( 'Type what you are looking for.', 'ux-studio' ) . '</p>' . $this->assistant_offer( $q, 0 );
		}

		$all   = SearchService::search( $q );
		$types = array();
		foreach ( $all as $item ) {
			$slug = (string) $item['type'];
			if ( ! isset( $types[ $slug ] ) ) {
				$types[ $slug ] = array( 'label' => (string) $item['type_label'], 'count' => 0 );
			}
			++$types[ $slug ]['count'];
		}

		$shown = $all;
		if ( '' !== $type && isset( $types[ $type ] ) ) {
			$shown = array_values( array_filter( $all, static fn( array $i ): bool => $i['type'] === $type ) );
		} else {
			$type = '';
		}

		$per   = $this->module->per_page();
		$slice = array_slice( $shown, 0, $per * max( 1, $page ) );
		$total = count( $all );

		$out = '<p class="uxs-results__summary" data-count="' . (int) $total . '" data-q="' . esc_attr( $q ) . '">'
			. esc_html(
				sprintf(
					/* translators: 1: number of results, 2: search query */
					_n( '%1$d result for “%2$s”', '%1$d results for “%2$s”', $total, 'ux-studio' ),
					$total,
					$q
				)
			) . '</p>';

		if ( count( $types ) > 1 ) {
			$out .= '<div class="uxs-results__types" role="group" aria-label="' . esc_attr__( 'Filter by type', 'ux-studio' ) . '">'
				. '<button type="button" class="uxs-chip' . ( '' === $type ? ' is-active' : '' ) . '" data-type="">' . esc_html__( 'All', 'ux-studio' ) . ' <span>' . (int) $total . '</span></button>';
			foreach ( $types as $slug => $meta ) {
				$out .= '<button type="button" class="uxs-chip' . ( $type === $slug ? ' is-active' : '' ) . '" data-type="' . esc_attr( $slug ) . '">' . esc_html( $meta['label'] ) . ' <span>' . (int) $meta['count'] . '</span></button>';
			}
			$out .= '</div>';
		}

		if ( $slice ) {
			$out .= '<div class="uxs-items" data-page="' . (int) $page . '">' . $this->items( $slice, $q ) . '</div>';
			if ( count( $shown ) > count( $slice ) ) {
				$out .= '<button type="button" class="uxs-more" data-uxs-more>' . esc_html__( 'Show more', 'ux-studio' ) . '</button>';
			}
		} else {
			$out .= '<p class="uxs-results__empty">' . esc_html__( 'We did not find anything matching your search.', 'ux-studio' ) . '</p>';
		}

		return $out . $this->assistant_offer( $q, $total );
	}

	/**
	 * Result cards/rows only (also returned by the "load more" request).
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 */
	public function items( array $items, string $q ): string {
		$show_images = (bool) $this->module->opt( 'show_images' );
		$out         = '';
		foreach ( $items as $item ) {
			$out .= '<article class="uxs-item">';
			if ( $show_images && ! empty( $item['image'] ) ) {
				$out .= '<a class="uxs-item__media" href="' . esc_url( (string) $item['url'] ) . '" tabindex="-1" aria-hidden="true"><img src="' . esc_url( (string) $item['image'] ) . '" alt="" loading="lazy"></a>';
			}
			$out .= '<div class="uxs-item__body">'
				. '<span class="uxs-item__type">' . esc_html( (string) $item['type_label'] ) . '</span>'
				. '<h3 class="uxs-item__title"><a href="' . esc_url( (string) $item['url'] ) . '">' . SearchService::highlight( (string) $item['title'], $q ) . '</a></h3>';
			if ( ! empty( $item['props'] ) ) {
				$out .= '<ul class="uxs-item__props">';
				foreach ( $item['props'] as $prop ) {
					$out .= '<li><span>' . esc_html( (string) $prop['label'] ) . ':</span> ' . SearchService::highlight( (string) $prop['value'], $q ) . '</li>';
				}
				$out .= '</ul>';
			}
			if ( '' !== (string) $item['snippet'] ) {
				$out .= '<p class="uxs-item__snippet">' . SearchService::highlight( (string) $item['snippet'], $q ) . '</p>';
			}
			if ( '' !== (string) $item['price'] ) {
				$out .= '<span class="uxs-item__price">' . wp_kses_post( (string) $item['price'] ) . '</span>';
			}
			$out .= '</div></article>';
		}
		return $out;
	}

	/**
	 * "Can the assistant help?" box. Hidden until the script confirms that the
	 * chat widget is actually on the page.
	 */
	private function assistant_offer( string $q, int $count ): string {
		if ( 'off' === $this->module->opt( 'ai_handoff' ) ) {
			return '';
		}
		return '<div class="uxs-ai" data-uxs-ai data-count="' . (int) $count . '" hidden>'
			. '<span class="uxs-ai__icon" aria-hidden="true">' . self::icon( 'sparkle' ) . '</span>'
			. '<div class="uxs-ai__text"><strong>' . esc_html__( 'Did not find what you need?', 'ux-studio' ) . '</strong>'
			. '<span>' . esc_html__( 'Our assistant can help you with the search.', 'ux-studio' ) . '</span></div>'
			. '<button type="button" class="uxs-ai__button" data-uxs-ai-open>' . esc_html__( 'Ask the assistant', 'ux-studio' ) . '</button>'
			. '</div>';
	}

	/**
	 * " · Label: value" fragments for the dropdown's meta line.
	 *
	 * @param array<int, array{label:string,value:string}> $props Matched properties.
	 */
	private function props_inline( array $props, string $q, int $max ): string {
		$out = '';
		foreach ( array_slice( $props, 0, $max ) as $prop ) {
			$out .= ' · ' . esc_html( (string) $prop['label'] ) . ': ' . SearchService::highlight( (string) $prop['value'], $q );
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Icons
	// ---------------------------------------------------------------------

	public static function icon( string $name ): string {
		$paths = array(
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
			'close'   => '<path d="M18 6 6 18M6 6l12 12"/>',
			'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
			'sparkle' => '<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.7 1.8L21.5 17.5l-1.8.7L19 20l-.7-1.8-1.8-.7 1.8-.7z"/>',
		);
		return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}
}
