/**
 * "Switch to classic editor" in the options menu of the block editor (Classic Editor module, users choose their editor).
 */
( function ( wp, data ) {
	if ( ! wp || ! wp.plugins || ! wp.editPost || ! data ) {
		return;
	}

	wp.plugins.registerPlugin( 'uxstudio-editor-switch', {
		render: function () {
			var PluginMoreMenuItem = wp.editPost.PluginMoreMenuItem;

			return wp.element.createElement(
				PluginMoreMenuItem,
				{
					icon: 'editor-kitchensink',
					href: data.url,
				},
				data.text
			);
		},
	} );
} )( window.wp, window.uxStudioEditorSwitch );
