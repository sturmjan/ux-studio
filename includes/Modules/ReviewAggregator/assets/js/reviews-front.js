( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.ux1-reviews__more' ) : null;
		if ( ! btn ) {
			return;
		}
		var card = btn.closest( '.ux1-reviews__card' );
		if ( ! card ) {
			return;
		}
		var excerpt = card.querySelector( '.ux1-reviews__excerpt' );
		var full = card.querySelector( '.ux1-reviews__full' );
		if ( ! excerpt || ! full ) {
			return;
		}
		var expanded = full.style.display !== 'none';
		excerpt.style.display = expanded ? '' : 'none';
		full.style.display = expanded ? 'none' : '';
		btn.textContent = expanded ? btn.getAttribute( 'data-more' ) : btn.getAttribute( 'data-less' );
	} );
}() );
