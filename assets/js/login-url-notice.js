( function () {
	'use strict';

	var notice = document.getElementById( 'uxstudio-login-url-notice' );
	if ( ! notice ) {
		return;
	}
	var button = notice.querySelector( 'button[data-copy]' );
	if ( ! button ) {
		return;
	}

	function copied() {
		var label = button.textContent;
		button.textContent = button.getAttribute( 'data-done' ) || label;
		window.setTimeout( function () {
			button.textContent = label;
		}, 2000 );
	}

	// Old browsers / insecure contexts: select the address so Ctrl+C works.
	function fallback() {
		var code = notice.querySelector( '.uxstudio-login-url-notice__url' );
		if ( ! code || ! window.getSelection ) {
			return;
		}
		var range = document.createRange();
		range.selectNodeContents( code );
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	button.addEventListener( 'click', function () {
		var text = button.getAttribute( 'data-copy' ) || '';
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( copied, fallback );
		} else {
			fallback();
		}
	} );
}() );
