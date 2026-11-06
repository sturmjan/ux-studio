<?php
/**
 * Gutenberg blocks wrapping the AI Search shortcodes.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\AiSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Two thin dynamic blocks (search field, results) that render through exactly
 * the same Renderer as the shortcodes. Like the Forms block, the editor UI is a
 * small inline script instead of part of the admin SPA bundle.
 */
final class GutenbergBlock {

	private static ?Module $module = null;

	public static function register( Module $module ): void {
		self::$module = $module;
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
	}

	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		register_block_type(
			'uxstudio/ai-search',
			array(
				'api_version'     => 2,
				'title'           => __( 'AI Search field', 'ux-studio' ),
				'description'     => __( 'Live search field with suggestions.', 'ux-studio' ),
				'category'        => 'widgets',
				'icon'            => 'search',
				'attributes'      => array(
					'design'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'placeholder' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_field' ),
				'editor_script'   => 'uxstudio-ai-search-block-editor',
			)
		);

		register_block_type(
			'uxstudio/ai-search-results',
			array(
				'api_version'     => 2,
				'title'           => __( 'AI Search results', 'ux-studio' ),
				'description'     => __( 'Search field with the live list of results. Place it on the results page.', 'ux-studio' ),
				'category'        => 'widgets',
				'icon'            => 'list-view',
				'attributes'      => array(
					'design' => array(
						'type'    => 'string',
						'default' => '',
					),
					'layout' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => array( __CLASS__, 'render_results' ),
				'editor_script'   => 'uxstudio-ai-search-block-editor',
			)
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_field( array $attributes ): string {
		return self::$module ? ( new Renderer( self::$module ) )->field( $attributes ) : '';
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_results( array $attributes ): string {
		return self::$module ? ( new Renderer( self::$module ) )->results_page( $attributes ) : '';
	}

	public static function enqueue_editor_assets(): void {
		wp_register_script(
			'uxstudio-ai-search-block-editor',
			false,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			UXSTUDIO_VERSION,
			true
		);
		wp_enqueue_script( 'uxstudio-ai-search-block-editor' );
		wp_enqueue_style( 'uxstudio-ai-search', UXSTUDIO_URL . 'assets/css/ai-search.css', array(), UXSTUDIO_VERSION );
		wp_add_inline_script( 'uxstudio-ai-search-block-editor', self::editor_script() );
	}

	private static function editor_script(): string {
		$designs = array( array( 'label' => __( 'Default design', 'ux-studio' ), 'value' => '' ) );
		foreach ( Module::DESIGNS as $slug => $label ) {
			$designs[] = array( 'label' => __( $label, 'ux-studio' ), 'value' => $slug ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		$layouts = array( array( 'label' => __( 'Default layout', 'ux-studio' ), 'value' => '' ) );
		foreach ( Module::LAYOUTS as $slug => $label ) {
			$layouts[] = array( 'label' => __( $label, 'ux-studio' ), 'value' => $slug ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}

		$data = wp_json_encode(
			array(
				'designs' => $designs,
				'layouts' => $layouts,
				'text'    => array(
					'field'       => __( 'AI Search field', 'ux-studio' ),
					'results'     => __( 'AI Search results', 'ux-studio' ),
					'design'      => __( 'Search field design', 'ux-studio' ),
					'placeholder' => __( 'Placeholder', 'ux-studio' ),
					'layout'      => __( 'Results layout', 'ux-studio' ),
				),
			)
		);

		return 'window.uxstudioAiSearchBlock=' . $data . ';' . <<<'JS'
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}
	var el = wp.element.createElement;
	var cfg = window.uxstudioAiSearchBlock;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;

	function register( name, title, icon, withPlaceholder ) {
		wp.blocks.registerBlockType( name, {
			apiVersion: 2,
			title: title,
			category: 'widgets',
			icon: icon,
			attributes: withPlaceholder
				? { design: { type: 'string', default: '' }, placeholder: { type: 'string', default: '' } }
				: { design: { type: 'string', default: '' }, layout: { type: 'string', default: '' } },
			edit: function ( props ) {
				var a = props.attributes;
				var controls = [
					el( SelectControl, {
						key: 'design',
						label: cfg.text.design,
						value: a.design,
						options: cfg.designs,
						onChange: function ( v ) { props.setAttributes( { design: v } ); },
					} ),
				];
				if ( withPlaceholder ) {
					controls.push( el( TextControl, {
						key: 'placeholder',
						label: cfg.text.placeholder,
						value: a.placeholder,
						onChange: function ( v ) { props.setAttributes( { placeholder: v } ); },
					} ) );
				} else {
					controls.push( el( SelectControl, {
						key: 'layout',
						label: cfg.text.layout,
						value: a.layout,
						options: cfg.layouts,
						onChange: function ( v ) { props.setAttributes( { layout: v } ); },
					} ) );
				}
				return el(
					'div',
					useBlockProps(),
					el( InspectorControls, {}, el( PanelBody, { title: title }, controls ) ),
					el( ServerSideRender, { block: name, attributes: a } )
				);
			},
			save: function () { return null; },
		} );
	}

	register( 'uxstudio/ai-search', cfg.text.field, 'search', true );
	register( 'uxstudio/ai-search-results', cfg.text.results, 'list-view', false );
} )( window.wp );
JS;
	}
}
