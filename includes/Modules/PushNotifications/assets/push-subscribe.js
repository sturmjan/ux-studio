/**
 * UX Studio - Push Notifications: visitor opt-in.
 *
 * Registers the push service worker, shows the opt-in prompt (configurable
 * delay / scroll trigger / position / texts / colours) and subscribes the
 * browser through PushManager with the site's VAPID public key, then POSTs the
 * subscription to the REST /subscribe endpoint. Config comes from
 * window.uxsPush (see Module::enqueue_frontend()).
 */
( function () {
	'use strict';

	if ( ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) || ! ( 'Notification' in window ) ) {
		return;
	}

	var config = window.uxsPush;
	if ( ! config || ! config.vapidPublicKey || ! config.swUrl ) {
		return;
	}

	var DISMISS_KEY = 'uxs_push_dismissed';
	var SENT_KEY = 'uxs_push_sent';
	var DAY_MS = 86400000;

	function init() {
		navigator.serviceWorker
			.register( config.swUrl, { scope: config.swScope || '/' } )
			.then( function ( registration ) {
				if ( Notification.permission === 'granted' ) {
					ensureSubscription( registration );
					return;
				}
				if ( Notification.permission === 'denied' ) {
					return;
				}
				try {
					if ( sessionStorage.getItem( DISMISS_KEY ) ) {
						return;
					}
				} catch ( e ) {
					// Storage blocked: just show the prompt.
				}
				scheduleOptIn( registration );
			} )
			.catch( function ( err ) {
				if ( window.console ) {
					window.console.warn( 'UX Studio Push: service worker registration failed', err );
				}
			} );
	}

	// ---- Opt-in prompt ---------------------------------------------------

	function scheduleOptIn( registration ) {
		var prompt = config.prompt || {};
		var delay = Math.max( 0, Number( prompt.delay ) || 0 ) * 1000;
		var scrollPct = Math.max( 0, Number( prompt.scroll ) || 0 );
		var shown = false;

		function show() {
			if ( shown ) {
				return;
			}
			shown = true;
			renderPrompt( registration );
		}

		if ( scrollPct > 0 ) {
			var onScroll = function () {
				var top = window.pageYOffset || document.documentElement.scrollTop;
				var height = document.documentElement.scrollHeight - window.innerHeight;
				var pct = height > 0 ? ( top / height ) * 100 : 100;
				if ( pct >= scrollPct ) {
					window.removeEventListener( 'scroll', onScroll );
					show();
				}
			};
			window.addEventListener( 'scroll', onScroll, { passive: true } );
		}

		if ( delay > 0 ) {
			window.setTimeout( show, delay );
		} else if ( scrollPct <= 0 ) {
			show();
		}
	}

	function isHexColor( value ) {
		return typeof value === 'string' && /^#[0-9a-fA-F]{3,6}$/.test( value );
	}

	function el( tag, css, text ) {
		var node = document.createElement( tag );
		if ( css ) {
			node.style.cssText = css;
		}
		if ( text ) {
			node.textContent = text;
		}
		return node;
	}

	function renderPrompt( registration ) {
		if ( document.getElementById( 'uxs-push-prompt' ) ) {
			return;
		}
		var prompt = config.prompt || {};
		var position = prompt.position || 'top-left';
		var bg = isHexColor( prompt.bgColor ) ? prompt.bgColor : '#ffffff';
		var accent = isHexColor( prompt.btnColor ) ? prompt.btnColor : '#2271b1';

		var positions = {
			'top-left': 'top:20px;left:20px;',
			'top-right': 'top:20px;right:20px;',
			'bottom-right': 'bottom:20px;right:20px;',
			'bottom-left': 'bottom:20px;left:20px;',
			center: 'top:50%;left:50%;transform:translate(-50%,-50%);'
		};

		var box = el(
			'div',
			'position:fixed;z-index:999999;' +
				( positions[ position ] || positions[ 'top-left' ] ) +
				'max-width:420px;width:calc(100% - 40px);box-sizing:border-box;background:' +
				bg +
				';border-radius:4px;padding:16px 20px;box-shadow:0 4px 24px rgba(0,0,0,.18);' +
				'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
		);
		box.id = 'uxs-push-prompt';
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-label', prompt.title || '' );

		var row = el( 'div', 'display:flex;align-items:flex-start;gap:14px;' );
		var icon = el(
			'div',
			'flex-shrink:0;width:52px;height:52px;border-radius:6px;display:flex;align-items:center;justify-content:center;background:' +
				accent +
				';'
		);
		icon.innerHTML =
			'<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
		var texts = el( 'div', 'flex:1;min-width:0;' );
		texts.appendChild( el( 'div', 'font-weight:700;font-size:14px;line-height:1.4;color:#1a1a1a;margin-bottom:4px;', prompt.title || '' ) );
		texts.appendChild( el( 'div', 'font-size:12px;color:#666;line-height:1.5;', prompt.text || '' ) );
		row.appendChild( icon );
		row.appendChild( texts );

		var actions = el( 'div', 'display:flex;justify-content:flex-end;align-items:center;gap:16px;margin-top:14px;' );
		var later = el( 'button', 'padding:0;border:none;background:transparent;cursor:pointer;font-size:13px;color:#666;font-family:inherit;', prompt.btnLater || '' );
		later.type = 'button';
		var allow = el(
			'button',
			'padding:8px 20px;border:none;border-radius:4px;cursor:pointer;font-size:13px;font-weight:600;color:#fff;font-family:inherit;background:' +
				accent +
				';',
			prompt.btnAllow || ''
		);
		allow.type = 'button';
		actions.appendChild( later );
		actions.appendChild( allow );

		box.appendChild( row );
		box.appendChild( actions );
		document.body.appendChild( box );

		allow.addEventListener( 'click', function () {
			box.remove();
			Notification.requestPermission().then( function ( permission ) {
				if ( permission === 'granted' ) {
					subscribeUser( registration );
				}
			} );
		} );

		later.addEventListener( 'click', function () {
			box.remove();
			try {
				sessionStorage.setItem( DISMISS_KEY, '1' );
			} catch ( e ) {
				// Ignore.
			}
		} );
	}

	// ---- Subscription ----------------------------------------------------

	function subscribeUser( registration ) {
		return registration.pushManager
			.subscribe( {
				userVisibleOnly: true,
				applicationServerKey: urlBase64ToUint8Array( config.vapidPublicKey )
			} )
			.then( function ( subscription ) {
				return sendSubscription( subscription, true );
			} )
			.catch( function ( err ) {
				if ( window.console ) {
					window.console.warn( 'UX Studio Push: subscribe failed', err );
				}
			} );
	}

	/**
	 * Permission already granted: make sure a subscription exists, belongs to the
	 * CURRENT VAPID key (it does not after the key was regenerated) and is known
	 * to the server.
	 */
	function ensureSubscription( registration ) {
		registration.pushManager.getSubscription().then( function ( subscription ) {
			if ( ! subscription ) {
				return subscribeUser( registration );
			}
			if ( ! sameKey( subscription, config.vapidPublicKey ) ) {
				return subscription.unsubscribe().then( function () {
					return subscribeUser( registration );
				} );
			}
			return sendSubscription( subscription, false );
		} );
	}

	function sameKey( subscription, publicKey ) {
		var current = subscription.options && subscription.options.applicationServerKey;
		if ( ! current ) {
			return true; // Cannot tell - keep it.
		}
		return arrayBufferToBase64Url( current ) === publicKey;
	}

	function sendSubscription( subscription, force ) {
		var stamp = subscription.endpoint + '|' + ( config.userId || 0 );
		if ( ! force ) {
			try {
				var last = JSON.parse( localStorage.getItem( SENT_KEY ) || 'null' );
				if ( last && last.s === stamp && Date.now() - last.t < DAY_MS ) {
					return Promise.resolve();
				}
			} catch ( e ) {
				// Ignore broken storage value.
			}
		}

		var body = JSON.stringify( {
			endpoint: subscription.endpoint,
			p256dh: arrayBufferToBase64Url( subscription.getKey( 'p256dh' ) ),
			auth: arrayBufferToBase64Url( subscription.getKey( 'auth' ) )
		} );

		function post( withNonce ) {
			var headers = { 'Content-Type': 'application/json' };
			if ( withNonce && config.nonce ) {
				headers[ 'X-WP-Nonce' ] = config.nonce;
			}
			return fetch( config.subscribeUrl, { method: 'POST', headers: headers, body: body, credentials: 'same-origin' } );
		}

		// The nonce lets the server link the subscription to the logged-in user
		// (used by "send test"). A stale nonce on a cached page is rejected for
		// logged-in users, so retry without it.
		return post( true )
			.then( function ( response ) {
				return response.ok ? response : post( false );
			} )
			.then( function ( response ) {
				if ( response && response.ok ) {
					try {
						localStorage.setItem( SENT_KEY, JSON.stringify( { s: stamp, t: Date.now() } ) );
					} catch ( e ) {
						// Ignore.
					}
				}
			} )
			.catch( function () {
				// Offline etc. - retried on the next page view.
			} );
	}

	// ---- Helpers ---------------------------------------------------------

	function urlBase64ToUint8Array( base64String ) {
		var padding = '='.repeat( ( 4 - ( base64String.length % 4 ) ) % 4 );
		var base64 = ( base64String + padding ).replace( /-/g, '+' ).replace( /_/g, '/' );
		var raw = atob( base64 );
		var out = new Uint8Array( raw.length );
		for ( var i = 0; i < raw.length; i++ ) {
			out[ i ] = raw.charCodeAt( i );
		}
		return out;
	}

	function arrayBufferToBase64Url( buffer ) {
		var bytes = new Uint8Array( buffer );
		var str = '';
		for ( var i = 0; i < bytes.length; i++ ) {
			str += String.fromCharCode( bytes[ i ] );
		}
		return btoa( str ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
