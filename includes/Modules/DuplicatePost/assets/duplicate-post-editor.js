/**
 * Duplicate Post - "Duplicate" button in the block editor header toolbar.
 * Opens the shared element picker modal (duplicate-post.js).
 */
( function ( wp ) {
	'use strict';

	var cfg = window.uxStudioDuplicatePost;
	if ( ! cfg || ! wp || ! wp.data ) {
		return;
	}

	var SELECTORS = [ '.editor-document-tools', '.edit-post-header-toolbar' ];

	function findToolbar() {
		for ( var i = 0; i < SELECTORS.length; i++ ) {
			var node = document.querySelector( SELECTORS[ i ] );
			if ( node ) {
				return node;
			}
		}
		return null;
	}

	function insertButton( toolbar ) {
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'components-button is-secondary uxstudio-duplicate-post';
		button.textContent = cfg.i18n.duplicate;
		button.style.marginLeft = '8px';
		button.addEventListener( 'click', function () {
			var editor = wp.data.select( 'core/editor' );
			var postId = ( editor && editor.getCurrentPostId() ) || cfg.postId;
			if ( postId && window.uxStudioDuplicatePostApi ) {
				window.uxStudioDuplicatePostApi.open( postId, button );
			}
		} );

		var left = toolbar.querySelector( '.editor-document-tools__left' );
		if ( left && left.nextSibling ) {
			toolbar.insertBefore( button, left.nextSibling );
		} else {
			toolbar.appendChild( button );
		}
	}

	// The toolbar mounts after the editor boots and can be re-rendered; keep
	// the button in place (cheap DOM check on each store change).
	wp.data.subscribe( function () {
		var editor = wp.data.select( 'core/editor' );
		if ( ! editor || editor.isCleanNewPost() ) {
			return;
		}
		var toolbar = findToolbar();
		if ( toolbar && ! toolbar.querySelector( '.uxstudio-duplicate-post' ) ) {
			insertButton( toolbar );
		}
	} );
} )( window.wp );
