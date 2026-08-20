/**
 * Quick Image - open the media modal from the list-table thumbnail column and
 * update the post's featured image via REST. Ported from the legacy module.
 */
( function () {
	'use strict';

	if ( typeof window.uxStudioQuickImage === 'undefined' ) {
		return;
	}

	var cfg = window.uxStudioQuickImage;
	var frame = null;
	var activePostId = null;

	function openFrame( button ) {
		activePostId = button.getAttribute( 'data-post-id' );
		var thumbId = button.getAttribute( 'data-thumbnail-id' );

		frame = wp.media( {
			title: cfg.i18n.title,
			button: { text: cfg.i18n.add },
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'open', function () {
			var selection = frame.state().get( 'selection' );
			selection.reset();
			if ( thumbId && '0' !== thumbId ) {
				var attachment = wp.media.attachment( thumbId );
				selection.add( attachment );
				attachment.fetch();
			}
			frame.toolbar.get().set( 'remove', {
				text: cfg.i18n.remove,
				style: 'secondary',
				priority: 10,
				click: function () {
					removeImage( activePostId );
				},
			} );
		} );

		frame.on( 'select', selectImage );
		frame.open();
	}

	function selectImage() {
		var attachment = frame.state().get( 'selection' ).first().toJSON();
		var button = document.querySelector( '.uxstudio-quick-image__button[data-post-id="' + activePostId + '"]' );
		if ( button ) {
			button.querySelector( 'img' ).setAttribute( 'src', attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url );
			button.setAttribute( 'data-thumbnail-id', String( attachment.id ) );
		}
		update( activePostId, attachment.id );
	}

	function removeImage( postId ) {
		var button = document.querySelector( '.uxstudio-quick-image__button[data-post-id="' + postId + '"]' );
		if ( button ) {
			button.querySelector( 'img' ).setAttribute( 'src', cfg.placeholder );
			button.setAttribute( 'data-thumbnail-id', '0' );
		}
		update( postId, 0 );
		if ( frame ) {
			frame.close();
		}
	}

	function update( postId, attachmentId ) {
		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce,
			},
			body: JSON.stringify( { post_id: postId, attachment_id: attachmentId } ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					if ( ! res.ok ) {
						throw new Error( ( data && data.message ) || cfg.i18n.error );
					}
					return data;
				} );
			} )
			.catch( function ( err ) {
				window.alert( err.message || cfg.i18n.error );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.uxstudio-quick-image__button' );
		if ( ! button ) {
			return;
		}
		e.preventDefault();
		openFrame( button );
	} );
} )();
