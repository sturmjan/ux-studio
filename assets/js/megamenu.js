/**
 * UX Studio megamenu controller: intercepts the top-level admin menu click
 * (on any wp-admin page) and opens the quick-nav overlay instead of
 * navigating away. Plain JS, no build step - see Core\Megamenu for markup.
 */
( function () {
	'use strict';

	var VIEW_KEY = 'uxstudio-launcher-view';

	/** Only 'grid' | 'list' are valid; anything else (stale/foreign value) means the default grid. */
	function normalizeView( value ) {
		return value === 'list' ? 'list' : 'grid';
	}

	// Pure helper exported for tests/launcher-view.mjs (Node); no effect in the browser.
	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { normalizeView: normalizeView, VIEW_KEY: VIEW_KEY };
	}

	var megamenu = typeof document !== 'undefined' ? document.getElementById( 'uxstudio-megamenu' ) : null;
	if ( ! megamenu ) {
		return;
	}

	// Match the SPA's own dark-mode choice (src/app/prefs.ts) so the overlay
	// looks like the same app whether opened from inside or outside the SPA.
	try {
		var theme = window.localStorage.getItem( 'uxstudio-theme' );
		if ( theme !== 'dark' && theme !== 'light' ) {
			theme = window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
		}
		megamenu.setAttribute( 'data-theme', theme );
	} catch ( e ) {
		// localStorage unavailable (privacy mode etc.) - default light styling is fine.
	}

	// Remembered card/row display (per browser profile, like the theme above).
	var viewButtons = megamenu.querySelectorAll( '.uxstudio-megamenu__view' );

	function applyView( view ) {
		view = normalizeView( view );
		megamenu.setAttribute( 'data-view', view );
		Array.prototype.forEach.call( viewButtons, function ( btn ) {
			var on = btn.getAttribute( 'data-view' ) === view;
			btn.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			btn.tabIndex = on ? 0 : -1;
		} );
	}

	try {
		applyView( window.localStorage.getItem( VIEW_KEY ) );
	} catch ( e ) {
		applyView( 'grid' );
	}

	function chooseView( view ) {
		applyView( view );
		try {
			window.localStorage.setItem( VIEW_KEY, normalizeView( view ) );
		} catch ( e ) {
			// Not persisted (privacy mode) - still applies for this page view.
		}
	}

	Array.prototype.forEach.call( viewButtons, function ( btn, i ) {
		btn.addEventListener( 'click', function () {
			chooseView( btn.getAttribute( 'data-view' ) );
		} );
		// Radiogroup keyboard model: arrows move + select, focus follows.
		btn.addEventListener( 'keydown', function ( e ) {
			var step = 0;
			if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
				step = 1;
			} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
				step = -1;
			}
			if ( ! step ) {
				return;
			}
			e.preventDefault();
			var next = viewButtons[ ( i + step + viewButtons.length ) % viewButtons.length ];
			chooseView( next.getAttribute( 'data-view' ) );
			next.focus();
		} );
	} );

	var backdrop = megamenu.querySelector( '.uxstudio-megamenu__backdrop' );
	var closeBtn = megamenu.querySelector( '.uxstudio-megamenu__close' );
	var panel = megamenu.querySelector( '.uxstudio-megamenu__panel' );
	var menuItem = document.querySelector( '#adminmenu #toplevel_page_ux-studio > a' );

	if ( ! menuItem ) {
		return;
	}

	function isOpen() {
		return megamenu.getAttribute( 'aria-hidden' ) === 'false';
	}

	function open() {
		megamenu.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'uxstudio-megamenu-open' );
		closeBtn.focus();
		var parentLi = menuItem.closest( 'li' );
		if ( parentLi ) {
			parentLi.classList.add( 'uxstudio-megamenu-trigger--active' );
		}
	}

	function close() {
		megamenu.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'uxstudio-megamenu-open' );
		var parentLi = menuItem.closest( 'li' );
		if ( parentLi ) {
			parentLi.classList.remove( 'uxstudio-megamenu-trigger--active' );
		}
		menuItem.focus();
	}

	function toggle() {
		if ( isOpen() ) {
			close();
		} else {
			open();
		}
	}

	// Click on the UX Studio top-level admin menu item opens the overlay
	// instead of navigating to the SPA.
	menuItem.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		toggle();
	} );

	closeBtn.addEventListener( 'click', function ( e ) {
		e.preventDefault();
		close();
	} );

	backdrop.addEventListener( 'click', function () {
		close();
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && isOpen() ) {
			e.preventDefault();
			close();
		}
	} );

	// Clicking inside the panel must not bubble to the backdrop's close handler.
	panel.addEventListener( 'click', function ( e ) {
		e.stopPropagation();
	} );

	var collapseButton = document.getElementById( 'collapse-button' );
	if ( collapseButton ) {
		collapseButton.addEventListener( 'click', function () {
			if ( isOpen() ) {
				close();
			}
		} );
	}

	// Prevent WP's native hover flyout submenu from also appearing.
	var parentLi = menuItem.closest( 'li' );
	if ( parentLi ) {
		parentLi.addEventListener( 'mouseenter', function () {
			this.classList.remove( 'opensub' );
		} );
	}
} )();
