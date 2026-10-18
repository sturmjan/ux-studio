/**
 * Export Posts - classic editor metabox. Navigates to the signed admin-post
 * download URL (the server re-checks nonce and edit_post capability).
 */
( function () {
	'use strict';
	var cfg = window.uxStudioExportPost;
	if ( ! cfg ) {
		return;
	}
	var box = document.querySelector( '.uxstudio-export-posts' );
	if ( ! box ) {
		return;
	}
	var select = box.querySelector( '.uxstudio-export-posts__select' );
	var button = box.querySelector( '.uxstudio-export-posts__button' );
	if ( ! select || ! button ) {
		return;
	}

	button.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		var type = select.value;
		if ( ! type ) {
			select.focus();
			window.alert( cfg.i18n.selectType );
			return;
		}
		if ( ! window.confirm( cfg.i18n.confirmExport ) ) {
			return;
		}
		var params = new URLSearchParams( {
			action: cfg.action,
			export_type: type,
			ids: String( cfg.postId ),
			_wpnonce: cfg.nonce,
		} );
		button.disabled = true;
		button.textContent = cfg.i18n.exporting;
		window.location.href = cfg.adminPostUrl + '?' + params.toString();
		window.setTimeout( function () {
			select.value = '';
			button.disabled = false;
			button.textContent = cfg.i18n.export;
		}, 1000 );
	} );
} )();
