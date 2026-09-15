/**
 * Menu Icons admin helper for wp-admin/nav-menus.php.
 *
 * Toggles which icon-source sub-field is shown per item, and wires the
 * Media Library picker button. Uses event delegation because WordPress
 * injects new menu item rows via AJAX after this script has loaded.
 */
( function ( $ ) {
	'use strict';

	function syncIconFields( $fieldset ) {
		var type = $fieldset.find( '.uxs-icon-type' ).val();

		$fieldset.find( '.uxs-icon-field' ).removeClass( 'uxs-visible' );
		$fieldset.find( '.uxs-icon-field-' + type ).addClass( 'uxs-visible' );
	}

	$( document ).on( 'change', '.field-uxstudio-menu-icons .uxs-icon-type', function () {
		syncIconFields( $( this ).closest( '.field-uxstudio-menu-icons' ) );
	} );

	$( document ).on( 'click', '.field-uxstudio-menu-icons .uxs-media-pick', function ( e ) {
		e.preventDefault();

		var $button   = $( this );
		var $fieldset = $button.closest( '.field-uxstudio-menu-icons' );
		var $input    = $fieldset.find( '.uxs-media-id' );
		var $preview  = $fieldset.find( '.uxs-media-preview' );

		var frame = wp.media( {
			title: $button.text(),
			multiple: false,
			library: { type: [ 'image' ] },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			$input.val( attachment.id );

			var thumb = ( attachment.sizes && attachment.sizes.thumbnail ) ? attachment.sizes.thumbnail.url : attachment.url;
			$preview.html( '<img src="' + thumb + '" alt="" />' );
		} );

		frame.open();
	} );

	$( function () {
		$( '.field-uxstudio-menu-icons' ).each( function () {
			syncIconFields( $( this ) );
		} );
	} );
} )( jQuery );
