/**
 * Media Replace - open the WP media modal to pick/upload a replacement file,
 * then POST it to the module REST endpoint. Ported from the legacy module.
 */
( function ( $ ) {
	'use strict';

	if ( typeof window.uxStudioMediaReplace === 'undefined' ) {
		return;
	}

	var cfg = window.uxStudioMediaReplace;
	var frame = null;

	function openFrame( attachmentId ) {
		if ( ! attachmentId ) {
			return;
		}

		frame = wp.media( {
			title: cfg.i18n.title,
			button: { text: cfg.i18n.button },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' ).first();
			if ( ! selection ) {
				return;
			}
			replace( attachmentId, selection.get( 'id' ) );
		} );

		frame.open();
	}

	function replace( attachmentId, newAttachmentId ) {
		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( {
				attachment_id: attachmentId,
				new_attachment_id: newAttachmentId,
			} ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					if ( ! res.ok ) {
						throw new Error( ( data && data.message ) || cfg.i18n.error );
					}
					return data;
				} );
			} )
			.then( function () {
				var url = new URL( window.location.href );
				url.searchParams.set( 'uxstudio_replace', 'updated' );
				window.location.href = url.toString();
			} )
			.catch( function ( err ) {
				window.alert( err.message || cfg.i18n.error );
			} );
	}

	$( document ).on( 'click', '.uxstudio-media-replace-trigger', function ( e ) {
		e.preventDefault();
		openFrame( $( this ).data( 'attachment-id' ) );
	} );

	$( function () {
		if ( ! cfg.autoOpen ) {
			return;
		}
		var trigger = document.querySelector( '.uxstudio-media-replace-trigger' );
		if ( trigger ) {
			openFrame( $( trigger ).data( 'attachment-id' ) );
		}
	} );
} )( jQuery );
