/**
 * Image Optimizer - Optimize / Restore buttons in the Media Library list
 * column and in the attachment details (media modal, grid view, edit screen).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.uxsImageOptimizer;
	if ( ! cfg ) {
		return;
	}
	var i18n = cfg.i18n || {};

	function inModal( $el ) {
		return $el.closest( '.media-modal, .media-frame, .attachment-details' ).length > 0;
	}

	function refreshAttachment( id ) {
		if ( window.wp && window.wp.media && window.wp.media.attachment ) {
			window.wp.media.attachment( id ).fetch();
		}
	}

	function request( action, $el, onDone, onFail ) {
		$.post( cfg.ajaxUrl, {
			action: action,
			attachment_id: $el.data( 'id' ),
			nonce: $el.data( 'nonce' ),
		} ).done( function ( response ) {
			if ( response && response.success ) {
				onDone( response.data || {} );
			} else {
				onFail( response && response.data && response.data.message ? response.data.message : i18n.connection );
			}
		} ).fail( function () {
			onFail( i18n.connection );
		} );
	}

	/** Swap the status block for the freshly rendered one. */
	function applyResult( $el, data ) {
		var $box = $el.closest( '.uxs-io' );
		if ( data.html && $box.length ) {
			var $parent = $box.parent();
			$box.replaceWith( data.html );
			$parent.find( '.uxs-io-status' ).first().addClass( 'is-success' ).text( data.message || '' );
		}
	}

	$( document ).on( 'click', '.uxs-io-optimize', function ( event ) {
		event.preventDefault();
		var $btn = $( this );
		var $status = $btn.closest( '.uxs-io-actions' ).find( '.uxs-io-status' );
		var label = $btn.text();
		var id = $btn.data( 'id' );

		$btn.prop( 'disabled', true ).text( i18n.processing );
		$status.removeClass( 'is-error is-success' ).text( '' );

		request( 'uxstudio_io_optimize', $btn, function ( data ) {
			applyResult( $btn, data );
			if ( inModal( $btn ) ) {
				refreshAttachment( id );
			}
		}, function ( message ) {
			$btn.prop( 'disabled', false ).text( label );
			$status.addClass( 'is-error' ).text( message );
		} );
	} );

	$( document ).on( 'click', '.uxs-io-restore', function ( event ) {
		event.preventDefault();
		if ( ! window.confirm( i18n.confirmRestore ) ) {
			return;
		}
		var $link = $( this );
		var $status = $link.closest( '.uxs-io-actions' ).find( '.uxs-io-status' );
		var label = $link.text();
		var id = $link.data( 'id' );

		$link.text( i18n.restoring );
		$status.removeClass( 'is-error is-success' ).text( '' );

		request( 'uxstudio_io_restore', $link, function ( data ) {
			applyResult( $link, data );
			if ( inModal( $link ) ) {
				refreshAttachment( id );
			}
		}, function ( message ) {
			$link.text( label );
			$status.addClass( 'is-error' ).text( message );
		} );
	} );
}( jQuery ) );
