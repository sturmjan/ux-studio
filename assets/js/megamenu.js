/**
 * UX Studio megamenu controller: intercepts the top-level admin menu click
 * (on any wp-admin page) and opens the quick-nav overlay instead of
 * navigating away. Plain JS, no build step - see Core\Megamenu for markup.
 */
( function () {
	'use strict';

	var megamenu = document.getElementById( 'uxstudio-megamenu' );
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
