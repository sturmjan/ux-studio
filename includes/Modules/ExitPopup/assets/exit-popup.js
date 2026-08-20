/**
 * Exit-intent email capture popup. Plain, dependency-free ES2017, no build
 * step: enqueued directly by Module::maybe_enqueue_assets() and configured
 * via the localized `uxStudioExitPopup` object.
 */
( function () {
	'use strict';

	var cfg = window.uxStudioExitPopup;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var SESSION_KEY = 'uxstudioExitPopupShown';
	var armed = false;
	var shown = false;

	function alreadyShownThisSession() {
		if ( ! cfg.showOncePerSession ) {
			return false;
		}
		try {
			return sessionStorage.getItem( SESSION_KEY ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function rememberShown() {
		if ( ! cfg.showOncePerSession ) {
			return;
		}
		try {
			sessionStorage.setItem( SESSION_KEY, '1' );
		} catch ( e ) {
			// sessionStorage unavailable (private mode etc.) - not fatal, the
			// popup simply may reappear within the same session.
		}
	}

	function injectStyles() {
		if ( document.getElementById( 'uxstudio-exit-popup-style' ) ) {
			return;
		}
		var style = document.createElement( 'style' );
		style.id = 'uxstudio-exit-popup-style';
		style.textContent =
			'.uxstudio-exit-popup-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;}' +
			'.uxstudio-exit-popup{background:#fff;border-radius:8px;max-width:480px;width:100%;padding:32px;position:relative;box-shadow:0 10px 40px rgba(0,0,0,.25);font-family:inherit;}' +
			'.uxstudio-exit-popup__close{position:absolute;top:8px;right:8px;background:none;border:none;font-size:22px;line-height:1;cursor:pointer;padding:8px;color:#333;}' +
			'.uxstudio-exit-popup__headline{margin:0 0 12px;font-size:22px;}' +
			'.uxstudio-exit-popup__body{margin:0 0 16px;font-size:14px;color:#333;}' +
			'.uxstudio-exit-popup__form{display:flex;gap:8px;flex-wrap:wrap;}' +
			'.uxstudio-exit-popup__email{flex:1 1 200px;padding:10px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;}' +
			'.uxstudio-exit-popup__submit{padding:10px 18px;border:none;border-radius:4px;background:#1d3998;color:#fff;font-size:14px;cursor:pointer;}' +
			'.uxstudio-exit-popup__submit:disabled{opacity:.6;cursor:default;}' +
			'.uxstudio-exit-popup__message{margin:12px 0 0;font-size:13px;}' +
			'.uxstudio-exit-popup__message.is-error{color:#b32d2e;}' +
			'.uxstudio-exit-popup__message.is-success{color:#1a7a31;}';
		document.head.appendChild( style );
	}

	function buildPopup() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'uxstudio-exit-popup-overlay';

		var modal = document.createElement( 'div' );
		modal.className = 'uxstudio-exit-popup';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'uxstudio-exit-popup__close';
		closeBtn.setAttribute( 'aria-label', cfg.closeLabel || 'Close' );
		closeBtn.innerHTML = '&times;';

		var headline = document.createElement( 'h2' );
		headline.className = 'uxstudio-exit-popup__headline';
		headline.textContent = cfg.headline || '';

		var body = document.createElement( 'div' );
		body.className = 'uxstudio-exit-popup__body';
		// cfg.body is already sanitized server-side via wp_kses_post() when the
		// setting was saved (see Settings::sanitize_field 'richtext' branch).
		body.innerHTML = cfg.body || '';

		var form = document.createElement( 'form' );
		form.className = 'uxstudio-exit-popup__form';
		form.noValidate = true;

		var email = document.createElement( 'input' );
		email.type = 'email';
		email.required = true;
		email.className = 'uxstudio-exit-popup__email';
		email.placeholder = cfg.emailLabel || 'Email address';
		email.setAttribute( 'aria-label', cfg.emailLabel || 'Email address' );

		var submit = document.createElement( 'button' );
		submit.type = 'submit';
		submit.className = 'uxstudio-exit-popup__submit';
		submit.textContent = cfg.submitLabel || 'Subscribe';

		var message = document.createElement( 'p' );
		message.className = 'uxstudio-exit-popup__message';
		message.style.display = 'none';

		form.appendChild( email );
		form.appendChild( submit );

		modal.appendChild( closeBtn );
		modal.appendChild( headline );
		modal.appendChild( body );
		modal.appendChild( form );
		modal.appendChild( message );
		overlay.appendChild( modal );

		function close() {
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			document.removeEventListener( 'keydown', onKeydown );
		}

		function onKeydown( event ) {
			if ( event.key === 'Escape' ) {
				close();
			}
		}

		closeBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', function ( event ) {
			if ( event.target === overlay ) {
				close();
			}
		} );
		document.addEventListener( 'keydown', onKeydown );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			submit.disabled = true;
			message.style.display = 'none';

			fetch( cfg.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.restNonce || '',
				},
				body: JSON.stringify( {
					email: email.value,
					page_url: window.location.href,
				} ),
			} )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'request-failed' );
					}
					return response.json();
				} )
				.then( function () {
					message.textContent = cfg.successMessage || 'Thanks!';
					message.className = 'uxstudio-exit-popup__message is-success';
					message.style.display = 'block';
					form.style.display = 'none';
					window.setTimeout( close, 2500 );
				} )
				.catch( function () {
					message.textContent = cfg.errorMessage || 'Something went wrong.';
					message.className = 'uxstudio-exit-popup__message is-error';
					message.style.display = 'block';
					submit.disabled = false;
				} );
		} );

		return overlay;
	}

	function showPopup() {
		if ( shown || alreadyShownThisSession() ) {
			return;
		}
		shown = true;
		rememberShown();
		injectStyles();
		document.body.appendChild( buildPopup() );
	}

	function onMouseLeave( event ) {
		if ( ! armed || shown ) {
			return;
		}
		if ( event.clientY <= 0 ) {
			showPopup();
		}
	}

	if ( alreadyShownThisSession() ) {
		return;
	}

	var delayMs = Math.max( 0, ( parseInt( cfg.delaySeconds, 10 ) || 0 ) * 1000 );
	window.setTimeout( function () {
		armed = true;
		document.addEventListener( 'mouseleave', onMouseLeave );
	}, delayMs );
} )();
