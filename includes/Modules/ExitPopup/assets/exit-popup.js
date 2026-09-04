/**
 * Exit-intent popup. Plain, dependency-free ES2017, no build step: enqueued
 * directly by Module::maybe_enqueue_assets() and configured via the localized
 * `uxStudioExitPopup` object. Renders headline/body/image/CTA/e-mail form,
 * supports five exit-detection modes plus a time-on-page trigger, and honours
 * session/cookie/always show-frequency.
 */
( function () {
	'use strict';

	var cfg = window.uxStudioExitPopup;
	if ( ! cfg || ! cfg.restUrl ) {
		return;
	}

	var colors = cfg.colors || {};
	var cta = cfg.cta || {};
	var det = cfg.detection || {};

	var SESSION_KEY = 'uxstudioExitPopupShown';
	var COOKIE_NAME = 'uxstudio_exit_popup_dismissed';
	var MOBILE_BREAKPOINT = 768;

	var shown = false;
	var teardown = [];

	// --- Frequency helpers -------------------------------------------------

	function setCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var date = new Date();
			date.setTime( date.getTime() + days * 86400000 );
			expires = '; expires=' + date.toUTCString();
		}
		document.cookie = name + '=' + encodeURIComponent( value ) + expires + '; path=/; SameSite=Lax';
	}

	function getCookie( name ) {
		var match = document.cookie.match( new RegExp( '(^| )' + name + '=([^;]+)' ) );
		return match ? decodeURIComponent( match[ 2 ] ) : null;
	}

	function alreadyShown() {
		if ( cfg.frequency === 'always' ) {
			return false;
		}
		if ( cfg.frequency === 'cookie' ) {
			return getCookie( COOKIE_NAME ) === '1';
		}
		// Default: once per session.
		try {
			return sessionStorage.getItem( SESSION_KEY ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function rememberShown() {
		if ( cfg.frequency === 'always' ) {
			return;
		}
		if ( cfg.frequency === 'cookie' ) {
			setCookie( COOKIE_NAME, '1', parseInt( cfg.cookieDays, 10 ) || 7 );
			return;
		}
		try {
			sessionStorage.setItem( SESSION_KEY, '1' );
		} catch ( e ) {
			// sessionStorage unavailable (private mode etc.) - not fatal.
		}
	}

	// --- Styles ------------------------------------------------------------

	function injectStyles() {
		if ( document.getElementById( 'uxstudio-exit-popup-style' ) ) {
			return;
		}
		var style = document.createElement( 'style' );
		style.id = 'uxstudio-exit-popup-style';
		style.textContent =
			'.uxstudio-exit-popup-overlay{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;}' +
			'.uxstudio-exit-popup{border-radius:8px;max-width:480px;width:100%;position:relative;box-shadow:0 10px 40px rgba(0,0,0,.25);font-family:inherit;overflow:hidden;}' +
			'.uxstudio-exit-popup__image{display:block;width:100%;max-height:220px;object-fit:cover;}' +
			'.uxstudio-exit-popup__inner{padding:32px;}' +
			'.uxstudio-exit-popup__close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.85);border:none;font-size:22px;line-height:1;cursor:pointer;padding:4px 10px;border-radius:4px;color:#333;}' +
			'.uxstudio-exit-popup__headline{margin:0 0 12px;font-size:22px;}' +
			'.uxstudio-exit-popup__body{margin:0 0 16px;font-size:14px;}' +
			'.uxstudio-exit-popup__form{display:flex;gap:8px;flex-wrap:wrap;}' +
			'.uxstudio-exit-popup__email{flex:1 1 200px;padding:10px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;}' +
			'.uxstudio-exit-popup__submit{padding:10px 18px;border:none;border-radius:4px;font-size:14px;cursor:pointer;}' +
			'.uxstudio-exit-popup__submit:disabled{opacity:.6;cursor:default;}' +
			'.uxstudio-exit-popup__cta{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:4px;text-decoration:none;font-size:14px;}' +
			'.uxstudio-exit-popup__message{margin:12px 0 0;font-size:13px;}' +
			'.uxstudio-exit-popup__message.is-error{color:#b32d2e;}' +
			'.uxstudio-exit-popup__message.is-success{color:#1a7a31;}';
		document.head.appendChild( style );
	}

	// --- Popup rendering ---------------------------------------------------

	function buildPopup() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'uxstudio-exit-popup-overlay';
		overlay.style.background = 'rgba(0,0,0,' + ( typeof colors.overlay === 'number' ? colors.overlay : 0.6 ) + ')';

		var modal = document.createElement( 'div' );
		modal.className = 'uxstudio-exit-popup';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		if ( colors.bg ) {
			modal.style.background = colors.bg;
		}

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'uxstudio-exit-popup__close';
		closeBtn.setAttribute( 'aria-label', cfg.closeLabel || 'Close' );
		closeBtn.innerHTML = '&times;';
		modal.appendChild( closeBtn );

		if ( cfg.imageUrl ) {
			var img = document.createElement( 'img' );
			img.className = 'uxstudio-exit-popup__image';
			img.src = cfg.imageUrl;
			img.alt = cfg.imageAlt || '';
			img.loading = 'lazy';
			modal.appendChild( img );
		}

		var inner = document.createElement( 'div' );
		inner.className = 'uxstudio-exit-popup__inner';

		var headline = document.createElement( 'h2' );
		headline.className = 'uxstudio-exit-popup__headline';
		headline.textContent = cfg.headline || '';
		if ( colors.title ) {
			headline.style.color = colors.title;
		}
		inner.appendChild( headline );

		var body = document.createElement( 'div' );
		body.className = 'uxstudio-exit-popup__body';
		// cfg.body is sanitized server-side via wp_kses_post() on save.
		body.innerHTML = cfg.body || '';
		if ( colors.text ) {
			body.style.color = colors.text;
		}
		inner.appendChild( body );

		var message = document.createElement( 'p' );
		message.className = 'uxstudio-exit-popup__message';
		message.style.display = 'none';

		var form = null;
		if ( cfg.emailEnabled ) {
			form = document.createElement( 'form' );
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
			if ( colors.ctaBg ) {
				submit.style.background = colors.ctaBg;
			}
			if ( colors.ctaText ) {
				submit.style.color = colors.ctaText;
			}

			form.appendChild( email );
			form.appendChild( submit );
			inner.appendChild( form );

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
		}

		if ( cta.enabled && cta.text ) {
			var ctaLink = document.createElement( 'a' );
			ctaLink.className = 'uxstudio-exit-popup__cta';
			ctaLink.textContent = cta.text;
			ctaLink.href = cta.url || '#';
			if ( colors.ctaBg ) {
				ctaLink.style.background = colors.ctaBg;
			}
			if ( colors.ctaText ) {
				ctaLink.style.color = colors.ctaText;
			}
			if ( cta.newTab ) {
				ctaLink.target = '_blank';
				ctaLink.rel = 'noopener noreferrer';
			}
			if ( ! cta.url || cta.url === '#' ) {
				ctaLink.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					close();
				} );
			}
			inner.appendChild( ctaLink );
		}

		inner.appendChild( message );
		modal.appendChild( inner );
		overlay.appendChild( modal );

		function close() {
			if ( overlay.parentNode ) {
				overlay.parentNode.removeChild( overlay );
			}
			document.removeEventListener( 'keydown', onKeydown );
			document.body.style.overflow = '';
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

		return overlay;
	}

	function showPopup() {
		if ( shown || alreadyShown() ) {
			return;
		}
		shown = true;
		rememberShown();
		destroyDetection();
		injectStyles();
		document.body.appendChild( buildPopup() );
		document.body.style.overflow = 'hidden';
	}

	// --- Detection ---------------------------------------------------------

	function isMobile() {
		return window.innerWidth <= MOBILE_BREAKPOINT;
	}

	function addListener( el, type, fn ) {
		el.addEventListener( type, fn );
		teardown.push( function () {
			el.removeEventListener( type, fn );
		} );
	}

	function addTimer( id, kind ) {
		teardown.push( function () {
			if ( kind === 'interval' ) {
				clearInterval( id );
			} else {
				clearTimeout( id );
			}
		} );
	}

	function destroyDetection() {
		for ( var i = 0; i < teardown.length; i++ ) {
			teardown[ i ]();
		}
		teardown = [];
	}

	function startDetection() {
		// 1. Mouse leaves the viewport (classic exit-intent), with a delay so a
		// quick return to the window cancels it.
		if ( det.mouseLeave ) {
			var leaveTimer = null;
			var delay = parseInt( det.mouseLeaveDelay, 10 );
			if ( ! ( delay >= 0 ) ) {
				delay = 500;
			}
			addListener( document, 'mouseout', function ( e ) {
				if ( ! e.relatedTarget && ! e.toElement && e.clientY <= 0 ) {
					leaveTimer = window.setTimeout( showPopup, delay );
					addTimer( leaveTimer );
				}
			} );
			addListener( document, 'mouseover', function () {
				if ( leaveTimer ) {
					clearTimeout( leaveTimer );
					leaveTimer = null;
				}
			} );
		}

		// 2. Tab visibility change.
		if ( det.tabChange ) {
			addListener( document, 'visibilitychange', function () {
				if ( document.visibilityState === 'hidden' ) {
					showPopup();
				}
			} );
		}

		// 3. Window blur (ignores focus moving into an on-page iframe).
		if ( det.windowBlur ) {
			addListener( window, 'blur', function () {
				window.setTimeout( function () {
					var active = document.activeElement;
					if ( active && active.tagName === 'IFRAME' && document.contains( active ) ) {
						return;
					}
					showPopup();
				}, 0 );
			} );
		}

		// 4. Idle / inactivity.
		if ( det.idle ) {
			var idleMs = ( parseInt( det.idleTimeout, 10 ) || 30 ) * 1000;
			var idleTimer = null;
			var resetIdle = function () {
				if ( idleTimer ) {
					clearTimeout( idleTimer );
				}
				idleTimer = window.setTimeout( showPopup, idleMs );
			};
			var idleEvents = [ 'mousemove', 'keydown', 'mousedown', 'touchstart', 'touchmove', 'scroll' ];
			for ( var j = 0; j < idleEvents.length; j++ ) {
				addListener( window, idleEvents[ j ], resetIdle );
			}
			resetIdle();
			teardown.push( function () {
				if ( idleTimer ) {
					clearTimeout( idleTimer );
				}
			} );
		}

		// 5. Fast scroll up (mobile exit signal).
		if ( det.scrollUp ) {
			var threshold = isMobile()
				? ( parseInt( det.scrollUpMobile, 10 ) || 0 )
				: ( parseInt( det.scrollUpDesktop, 10 ) || 0 );
			if ( threshold > 0 ) {
				var lastY = window.scrollY;
				var scrollInterval = window.setInterval( function () {
					var y = window.scrollY;
					if ( lastY - y >= threshold ) {
						showPopup();
					}
					lastY = y;
				}, 100 );
				addTimer( scrollInterval, 'interval' );
			}
		}
	}

	// --- Boot --------------------------------------------------------------

	if ( alreadyShown() ) {
		return;
	}

	// Time-on-page trigger is independent of the arming delay.
	var timeOnPage = parseInt( det.timeOnPage, 10 ) || 0;
	if ( timeOnPage > 0 ) {
		var topTimer = window.setTimeout( showPopup, timeOnPage * 1000 );
		addTimer( topTimer );
	}

	var delayMs = Math.max( 0, ( parseInt( cfg.delaySeconds, 10 ) || 0 ) * 1000 );
	var armTimer = window.setTimeout( function () {
		if ( ! shown ) {
			startDetection();
		}
	}, delayMs );
	addTimer( armTimer );
} )();
