<?php
/**
 * Gutenberg block wrapper around the shortcode render (PLAN.md 20.11/F2).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * "UX Form" block - a thin, dynamic block that renders the exact same markup
 * as `[uxstudio_form id="…"]` (PublicRenderer::render()), so there is never a
 * second implementation to keep in sync (same rule as the Elementor widget
 * planned for F3, PLAN.md 20.7). The editor's own live preview reuses core's
 * generic `/wp/v2/block-renderer/uxstudio/form` endpoint (ServerSideRender),
 * which calls the very same render_callback below - not a bespoke route.
 *
 * No @wordpress/scripts build step for the editor UI on purpose: it is a
 * small, self-contained script registered inline (same pattern as
 * PublicRenderer::print_runtime()), not part of the admin SPA bundle.
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
			'uxstudio/form',
			array(
				'api_version'     => 2,
				'title'           => __( 'UX Form', 'ux-studio' ),
				'description'     => __( 'Embed a form built with the UX Studio Form Builder.', 'ux-studio' ),
				'category'        => 'widgets',
				'icon'            => 'feedback',
				'attributes'      => array(
					'formId' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
				'render_callback' => array( __CLASS__, 'render' ),
				'editor_script'   => 'uxstudio-forms-block-editor',
			)
		);
	}

	/**
	 * @param array $attributes Block attributes ({ formId }).
	 */
	public static function render( array $attributes ): string {
		if ( null === self::$module ) {
			return '';
		}
		$form_id = (int) ( $attributes['formId'] ?? 0 );
		if ( $form_id <= 0 ) {
			return '';
		}
		// Exactly the shortcode's own render path - no second renderer.
		return self::$module->render_shortcode( array( 'id' => $form_id ) );
	}

	public static function enqueue_editor_assets(): void {
		wp_register_script(
			'uxstudio-forms-block-editor',
			false,
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render' ),
			UXSTUDIO_VERSION,
			true
		);
		wp_enqueue_script( 'uxstudio-forms-block-editor' );
		wp_add_inline_script( 'uxstudio-forms-block-editor', self::editor_script() );
	}

	/**
	 * Vanilla JS block registration (no JSX/build - see class docblock).
	 * Fetches the form list from the lightweight `edit_posts`-gated
	 * `/forms/options` route (Module::list_form_options()) so non-admin
	 * content editors can still pick a form, then renders a live preview via
	 * WordPress core's generic block-renderer (ServerSideRender).
	 */
	private static function editor_script(): string {
		return <<<'JS'
( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.blockEditor ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var Notice = wp.components.Notice;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'uxstudio/form', {
		apiVersion: 2,
		title: __( 'UX Form', 'ux-studio' ),
		description: __( 'Embed a form built with the UX Studio Form Builder.', 'ux-studio' ),
		category: 'widgets',
		icon: 'feedback',
		attributes: {
			formId: { type: 'integer', default: 0 },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();
			var state = useState( [] );
			var forms = state[ 0 ];
			var setForms = state[ 1 ];
			var loadingState = useState( true );
			var loading = loadingState[ 0 ];
			var setLoading = loadingState[ 1 ];

			useEffect( function () {
				wp.apiFetch( { path: '/uxstudio/v1/forms/options' } )
					.then( function ( res ) {
						setForms( ( res && res.data ) || [] );
						setLoading( false );
					} )
					.catch( function () {
						setLoading( false );
					} );
			}, [] );

			var options = [ { label: __( 'Select a form…', 'ux-studio' ), value: 0 } ].concat(
				forms.map( function ( f ) {
					return { label: f.title || ( '#' + f.id ), value: f.id };
				} )
			);

			var selected = null;
			for ( var i = 0; i < forms.length; i++ ) {
				if ( forms[ i ].id === attributes.formId ) {
					selected = forms[ i ];
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
						{ title: __( 'Form', 'ux-studio' ) },
						el( SelectControl, {
							label: __( 'Form', 'ux-studio' ),
							value: attributes.formId,
							options: options,
							disabled: loading,
							onChange: function ( value ) {
								setAttributes( { formId: parseInt( value, 10 ) || 0 } );
							},
						} ),
						selected && 'active' !== selected.status
							? el(
									Notice,
									{ status: 'warning', isDismissible: false },
									__( 'This form is not published (draft/archived) and will render empty on the live site.', 'ux-studio' )
							  )
							: null
					)
				),
				attributes.formId
					? el( ServerSideRender, { block: 'uxstudio/form', attributes: attributes } )
					: el(
							Placeholder,
							{ icon: 'feedback', label: __( 'UX Form', 'ux-studio' ) },
							__( 'Select a form in the block settings on the right.', 'ux-studio' )
					  )
			);
		},
		save: function () {
			return null; // Server-rendered (render_callback) - see PLAN.md 20.11.
		},
	} );
} )( window.wp );
JS;
	}
}
