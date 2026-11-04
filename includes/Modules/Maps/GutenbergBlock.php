<?php
/**
 * Gutenberg block wrapper around the map shortcode render.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Maps;

defined( 'ABSPATH' ) || exit;

/**
 * "UX Studio Map" block - a thin dynamic block rendering the exact same markup
 * as `[uxstudio_map id="…"]` (Module::render_map()). The editor shows a
 * placeholder with the map name (no live map in the editor); the map list is
 * passed inline, so editors without access to the admin REST routes can pick.
 *
 * Like the Forms block, the editor UI is a small vanilla script registered
 * inline - no build step.
 */
final class GutenbergBlock {

	private static ?Module $module = null;

	public static function register( Module $module ): void {
		self::$module = $module;
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
	}

	public static function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // Block editor not available (very old WP) - shortcode/Elementor still work.
		}

		register_block_type(
			'uxstudio/map',
			array(
				'api_version'     => 2,
				'title'           => __( 'UX Studio Map', 'ux-studio' ),
				'description'     => __( 'Embed a map built with the UX Studio Maps module.', 'ux-studio' ),
				'category'        => 'widgets',
				'icon'            => 'location-alt',
				'attributes'      => array(
					'mapId'  => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'height' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render_callback' => array( __CLASS__, 'render' ),
				'editor_script'   => 'uxstudio-maps-block-editor',
			)
		);
	}

	/**
	 * @param array $attributes Block attributes ({ mapId, height }).
	 */
	public static function render( array $attributes ): string {
		if ( null === self::$module ) {
			return '';
		}
		$map_id = (int) ( $attributes['mapId'] ?? 0 );
		if ( $map_id <= 0 ) {
			return '';
		}
		// Exactly the shortcode's own render path - no second renderer.
		return self::$module->render_map( $map_id, (int) ( $attributes['height'] ?? 0 ) );
	}

	public static function enqueue_editor_assets(): void {
		if ( null === self::$module ) {
			return;
		}
		$maps = array();
		foreach ( self::$module->list_maps() as $map ) {
			$maps[] = array(
				'id'    => (int) $map['id'],
				'title' => (string) $map['title'],
			);
		}
		wp_register_script(
			'uxstudio-maps-block-editor',
			false,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			UXSTUDIO_VERSION,
			true
		);
		wp_enqueue_script( 'uxstudio-maps-block-editor' );
		wp_add_inline_script( 'uxstudio-maps-block-editor', 'window.uxsMapsBlock = ' . wp_json_encode( $maps ) . ';', 'before' );
		wp_add_inline_script( 'uxstudio-maps-block-editor', self::editor_script() );
	}

	/**
	 * Vanilla JS block registration (no JSX/build - see class docblock).
	 */
	private static function editor_script(): string {
		return <<<'JS'
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;
	var maps = window.uxsMapsBlock || [];

	wp.blocks.registerBlockType( 'uxstudio/map', {
		apiVersion: 2,
		title: __( 'UX Studio Map', 'ux-studio' ),
		description: __( 'Embed a map built with the UX Studio Maps module.', 'ux-studio' ),
		category: 'widgets',
		icon: 'location-alt',
		attributes: {
			mapId: { type: 'integer', default: 0 },
			height: { type: 'integer', default: 0 },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var options = [ { label: __( 'Select a map…', 'ux-studio' ), value: 0 } ].concat(
				maps.map( function ( m ) {
					return { label: m.title || ( '#' + m.id ), value: m.id };
				} )
			);

			var selected = null;
			for ( var i = 0; i < maps.length; i++ ) {
				if ( maps[ i ].id === attributes.mapId ) {
					selected = maps[ i ];
					break;
				}
			}

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Map', 'ux-studio' ) },
						el( SelectControl, {
							label: __( 'Map', 'ux-studio' ),
							value: attributes.mapId,
							options: options,
							onChange: function ( value ) {
								setAttributes( { mapId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						el( TextControl, {
							label: __( 'Height (px)', 'ux-studio' ),
							help: __( 'Leave empty to use the height set in the map.', 'ux-studio' ),
							type: 'number',
							value: attributes.height || '',
							onChange: function ( value ) {
								setAttributes( { height: parseInt( value, 10 ) || 0 } );
							},
						} )
					)
				),
				el(
					Placeholder,
					{ icon: 'location-alt', label: __( 'UX Studio Map', 'ux-studio' ) },
					selected
						? selected.title || '#' + selected.id
						: __( 'Select a map in the block settings on the right.', 'ux-studio' )
				)
			);
		},
		save: function () {
			return null; // Server-rendered (render_callback).
		},
	} );
} )( window.wp );
JS;
	}
}
