/* Download Files - post editor meta box (media picker). */
( function () {
	'use strict';

	var list = document.getElementById( 'uxstudio-df-list' );
	var addBtn = document.getElementById( 'uxstudio-df-add' );
	var idsInput = document.getElementById( 'uxstudio-df-ids' );
	if ( ! list || ! addBtn || ! idsInput || ! window.wp || ! wp.media ) {
		return;
	}

	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( s ) { return s; };
	var frame = null;

	function updateIds() {
		var ids = [];
		list.querySelectorAll( '.uxstudio-df-item' ).forEach( function ( el ) {
			ids.push( el.getAttribute( 'data-id' ) );
		} );
		idsInput.value = ids.join( ',' );
	}

	addBtn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		if ( frame ) {
			frame.open();
			return;
		}
		frame = wp.media( {
			title: __( 'Select files', 'ux-studio' ),
			button: { text: __( 'Attach to post', 'ux-studio' ) },
			multiple: true
		} );
		frame.on( 'select', function () {
			frame.state().get( 'selection' ).each( function ( attachment ) {
				var data = attachment.toJSON();
				if ( list.querySelector( '[data-id="' + data.id + '"]' ) ) {
					return;
				}
				var li = document.createElement( 'li' );
				li.className = 'uxstudio-df-item';
				li.setAttribute( 'data-id', String( data.id ) );
				var name = document.createElement( 'span' );
				name.className = 'uxstudio-df-item__name';
				name.textContent = data.title || data.filename || String( data.id );
				var rm = document.createElement( 'button' );
				rm.type = 'button';
				rm.className = 'uxstudio-df-item__remove';
				rm.title = __( 'Remove', 'ux-studio' );
				rm.textContent = '\u00d7';
				li.appendChild( name );
				li.appendChild( rm );
				list.appendChild( li );
			} );
			updateIds();
		} );
		frame.open();
	} );

	list.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.uxstudio-df-item__remove' ) : null;
		if ( ! btn ) {
			return;
		}
		var item = btn.closest( '.uxstudio-df-item' );
		if ( item ) {
			item.remove();
			updateIds();
		}
	} );
}() );
