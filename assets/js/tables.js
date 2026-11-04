/**
 * UX Studio Tables - optional sorting, search and pagination (PLAN §41).
 * Vanilla JS, loaded only on pages with a table that needs it. Without it the
 * table is a complete static table.
 */
( function () {
	'use strict';

	function text( cell ) {
		return ( cell.textContent || '' ).trim();
	}

	/** Parse "1 234,5" / "1,234.5" / "12 %" as a number, or NaN. */
	function toNumber( value ) {
		var v = value.replace( /[\s ]/g, '' ).replace( /[^\d.,\-+]/g, '' );
		if ( ! /\d/.test( v ) ) {
			return NaN;
		}
		if ( v.indexOf( ',' ) > -1 && v.indexOf( '.' ) > -1 ) {
			v = v.lastIndexOf( ',' ) > v.lastIndexOf( '.' ) ? v.replace( /\./g, '' ).replace( ',', '.' ) : v.replace( /,/g, '' );
		} else {
			v = v.replace( ',', '.' );
		}
		return parseFloat( v );
	}

	function init( root ) {
		var cfg;
		try {
			cfg = JSON.parse( root.getAttribute( 'data-uxs-table' ) );
		} catch ( e ) {
			return;
		}
		var table = root.querySelector( 'table' );
		var tbody = table && table.tBodies[ 0 ];
		if ( ! tbody ) {
			return;
		}

		var i18n = cfg.i18n || {};
		var cols = table.tHead && table.tHead.rows[ 0 ] ? table.tHead.rows[ 0 ].cells.length : ( tbody.rows[ 0 ] ? tbody.rows[ 0 ].cells.length : 0 );
		var rows = Array.prototype.slice.call( tbody.rows ).map( function ( tr, index ) {
			return { el: tr, index: index, text: text( tr ).toLowerCase() };
		} );
		var state = { query: '', sortCol: -1, dir: 1, page: 1 };
		var pager, emptyRow;

		function visibleRows() {
			return rows.filter( function ( r ) {
				return ! state.query || r.text.indexOf( state.query ) > -1;
			} );
		}

		function cellText( r, c ) {
			return r.el.cells[ c ] ? text( r.el.cells[ c ] ) : '';
		}

		function render() {
			var list = visibleRows();
			if ( state.sortCol > -1 ) {
				var c = state.sortCol;
				list.sort( function ( a, b ) {
					var x = cellText( a, c );
					var y = cellText( b, c );
					var nx = toNumber( x );
					var ny = toNumber( y );
					var r = ! isNaN( nx ) && ! isNaN( ny ) ? nx - ny : x.localeCompare( y, undefined, { numeric: true, sensitivity: 'base' } );
					return ( r || a.index - b.index ) * state.dir;
				} );
			}

			var pages = 1;
			var shown = list;
			if ( cfg.paginate ) {
				pages = Math.max( 1, Math.ceil( list.length / cfg.pageSize ) );
				state.page = Math.min( state.page, pages );
				shown = list.slice( ( state.page - 1 ) * cfg.pageSize, state.page * cfg.pageSize );
			}

			rows.forEach( function ( r ) {
				r.el.hidden = true;
			} );
			shown.forEach( function ( r ) {
				r.el.hidden = false;
				tbody.appendChild( r.el ); // Moves the row into the sorted position.
			} );

			if ( emptyRow ) {
				emptyRow.hidden = list.length > 0;
				tbody.appendChild( emptyRow );
			}
			if ( pager ) {
				pager.hidden = pages < 2;
				pager.querySelector( '.uxs-tbl-pager__info' ).textContent = ( i18n.page || '%1$s / %2$s' ).replace( '%1$s', state.page ).replace( '%2$s', pages );
				pager.querySelector( '[data-act="first"]' ).disabled = state.page <= 1;
				pager.querySelector( '[data-act="prev"]' ).disabled = state.page <= 1;
				pager.querySelector( '[data-act="next"]' ).disabled = state.page >= pages;
				pager.querySelector( '[data-act="last"]' ).disabled = state.page >= pages;
			}
		}

		if ( cfg.search ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'uxs-tbl-controls';
			var search = document.createElement( 'input' );
			search.type = 'search';
			search.placeholder = i18n.search || 'Search';
			search.setAttribute( 'aria-label', i18n.search || 'Search' );
			search.addEventListener( 'input', function () {
				state.query = search.value.trim().toLowerCase();
				state.page = 1;
				render();
			} );
			wrap.appendChild( search );
			root.insertBefore( wrap, root.firstChild );

			emptyRow = document.createElement( 'tr' );
			emptyRow.className = 'uxs-tbl-empty';
			emptyRow.hidden = true;
			var td = document.createElement( 'td' );
			td.colSpan = Math.max( 1, cols );
			td.textContent = i18n.empty || '';
			emptyRow.appendChild( td );
		}

		if ( cfg.sort && table.tHead ) {
			Array.prototype.forEach.call( table.tHead.rows[ 0 ].cells, function ( th, col ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'uxs-tbl-sort';
				while ( th.firstChild ) {
					button.appendChild( th.firstChild );
				}
				th.appendChild( button );
				th.setAttribute( 'aria-sort', 'none' );
				button.addEventListener( 'click', function () {
					state.dir = state.sortCol === col ? -state.dir : 1;
					state.sortCol = col;
					Array.prototype.forEach.call( th.parentNode.cells, function ( other ) {
						other.setAttribute( 'aria-sort', other === th ? ( state.dir > 0 ? 'ascending' : 'descending' ) : 'none' );
					} );
					render();
				} );
			} );
		}

		if ( cfg.paginate ) {
			pager = document.createElement( 'nav' );
			pager.className = 'uxs-tbl-pager';
			[ 'first', 'prev', 'info', 'next', 'last' ].forEach( function ( act ) {
				var el = document.createElement( 'info' === act ? 'span' : 'button' );
				if ( 'info' === act ) {
					el.className = 'uxs-tbl-pager__info';
					el.setAttribute( 'aria-live', 'polite' );
				} else {
					el.type = 'button';
					el.setAttribute( 'data-act', act );
					el.textContent = i18n[ act ] || act;
				}
				pager.appendChild( el );
			} );
			pager.addEventListener( 'click', function ( e ) {
				var act = e.target && e.target.getAttribute && e.target.getAttribute( 'data-act' );
				if ( ! act ) {
					return;
				}
				var last = Math.max( 1, Math.ceil( visibleRows().length / cfg.pageSize ) );
				var next = { first: 1, prev: state.page - 1, next: state.page + 1, last: last }[ act ];
				state.page = Math.min( last, Math.max( 1, next ) );
				render();
			} );
			root.appendChild( pager );
		}

		render();
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-uxs-table]' ), init );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
