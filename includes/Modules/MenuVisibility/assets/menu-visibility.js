/**
 * Menu Visibility: rule editor on the Appearance > Menus screen.
 * The rules of an item live in a hidden field as JSON ([{t:'role', r:['editor']}, {t:'in'}]);
 * this script builds the rows from it and writes it back on every change.
 */
( function () {
	'use strict';

	var data = window.uxsMenuVisibility || { roles: {}, types: {}, i18n: {} };
	var counter = 0;

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else {
				node.setAttribute( key, attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			node.appendChild( child );
		} );
		return node;
	}

	function read( box ) {
		try {
			var parsed = JSON.parse( box.querySelector( '.uxs-mv-value' ).value || '[]' );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return [];
		}
	}

	function write( box ) {
		var rules = [];
		box.querySelectorAll( '.uxs-mv-row' ).forEach( function ( row ) {
			var type = row.querySelector( 'select' ).value;
			if ( type === 'in' || type === 'out' ) {
				rules.push( { t: type } );
				return;
			}
			var roles = [];
			row.querySelectorAll( 'input[type=checkbox]:checked' ).forEach( function ( box2 ) {
				roles.push( box2.value );
			} );
			if ( roles.length ) {
				rules.push( { t: type, r: roles } );
			}
		} );
		box.querySelector( '.uxs-mv-value' ).value = JSON.stringify( rules );
		box.querySelector( '.uxs-mv-empty' ).hidden = box.querySelectorAll( '.uxs-mv-row' ).length > 0;
	}

	function addRow( box, rule ) {
		var uid = 'uxs-mv-' + ( ++counter );
		var select = el( 'select', { 'aria-label': data.i18n.condition || 'Condition' } );
		Object.keys( data.types ).forEach( function ( type ) {
			var option = el( 'option', { value: type, text: data.types[ type ] } );
			if ( type === rule.t ) {
				option.selected = true;
			}
			select.appendChild( option );
		} );
		var roles = el( 'span', { 'class': 'uxs-mv-roles', role: 'group', 'aria-label': data.i18n.roles || 'Roles' } );
		Object.keys( data.roles ).forEach( function ( id, index ) {
			var cid = uid + '-' + index;
			var input = el( 'input', { type: 'checkbox', id: cid, value: id } );
			if ( ( rule.r || [] ).indexOf( id ) !== -1 ) {
				input.checked = true;
			}
			roles.appendChild( el( 'label', { 'for': cid }, [ input, document.createTextNode( ' ' + data.roles[ id ] ) ] ) );
		} );
		var remove = el( 'button', { type: 'button', 'class': 'button-link uxs-mv-remove', text: data.i18n.remove || 'Remove' } );
		var row = el( 'div', { 'class': 'uxs-mv-row' }, [ select, roles, remove ] );
		var sync = function () {
			roles.hidden = select.value === 'in' || select.value === 'out';
		};
		sync();
		box.querySelector( '.uxs-mv-rows' ).appendChild( row );
		return sync;
	}

	function init( box ) {
		if ( box.getAttribute( 'data-ready' ) ) {
			return;
		}
		box.setAttribute( 'data-ready', '1' );
		read( box ).forEach( function ( rule ) {
			addRow( box, rule );
		} );
		write( box );
	}

	function initAll( root ) {
		( root || document ).querySelectorAll( '.uxs-mv' ).forEach( init );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var box = target.closest ? target.closest( '.uxs-mv' ) : null;
		if ( ! box ) {
			return;
		}
		if ( target.classList.contains( 'uxs-mv-add' ) ) {
			event.preventDefault();
			addRow( box, { t: 'role', r: [] } );
			write( box );
		} else if ( target.classList.contains( 'uxs-mv-remove' ) ) {
			event.preventDefault();
			target.closest( '.uxs-mv-row' ).remove();
			write( box );
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var box = event.target.closest ? event.target.closest( '.uxs-mv' ) : null;
		if ( ! box ) {
			return;
		}
		var row = event.target.closest( '.uxs-mv-row' );
		if ( row && event.target.tagName === 'SELECT' ) {
			row.querySelector( '.uxs-mv-roles' ).hidden = event.target.value === 'in' || event.target.value === 'out';
		}
		write( box );
	} );

	// Menu items added with the "Add to menu" buttons arrive later (AJAX).
	var list = document.getElementById( 'menu-to-edit' );
	if ( list && window.MutationObserver ) {
		new MutationObserver( function () {
			initAll( list );
		} ).observe( list, { childList: true, subtree: true } );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initAll();
		} );
	} else {
		initAll();
	}
}() );
