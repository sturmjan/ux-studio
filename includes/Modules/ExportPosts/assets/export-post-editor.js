/**
 * Export Posts - block editor "Export" row in the post status panel.
 */
( function ( wp ) {
	'use strict';
	var cfg = window.uxStudioExportPost;
	if ( ! cfg || ! wp || ! wp.plugins || ! wp.element || ! wp.components ) {
		return;
	}
	var Panel = ( wp.editor && wp.editor.PluginPostStatusInfo ) || ( wp.editPost && wp.editPost.PluginPostStatusInfo );
	if ( ! Panel ) {
		return;
	}
	var el = wp.element.createElement;
	var SelectControl = wp.components.SelectControl;

	function download( type ) {
		if ( ! type || ! window.confirm( cfg.i18n.confirmExport ) ) {
			return;
		}
		var postId = wp.data.select( 'core/editor' ).getCurrentPostId() || cfg.postId;
		var params = new URLSearchParams( {
			action: cfg.action,
			export_type: type,
			ids: String( postId ),
			_wpnonce: cfg.nonce,
		} );
		window.location.href = cfg.adminPostUrl + '?' + params.toString();
	}

	function ExportControl() {
		var options = [ { value: '', label: cfg.i18n.selectType } ].concat(
			( cfg.types || [] ).map( function ( t ) {
				return { value: t, label: t.toUpperCase() };
			} )
		);
		return el(
			Panel,
			{ className: 'uxstudio-export-posts' },
			el(
				'div',
				{ className: 'editor-post-panel__row', style: { display: 'flex', alignItems: 'center', gap: '8px', width: '100%' } },
				el( 'span', { className: 'editor-post-panel__row-label' }, cfg.i18n.export ),
				el(
					'div',
					{ className: 'editor-post-panel__row-control', style: { flex: 1 } },
					el( SelectControl, {
						value: '',
						options: options,
						onChange: download,
						'aria-label': cfg.i18n.ariaLabel,
						__nextHasNoMarginBottom: true,
					} )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'uxstudio-export-posts', { render: ExportControl, icon: 'download' } );
} )( window.wp );
