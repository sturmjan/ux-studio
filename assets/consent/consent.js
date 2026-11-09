/**
 * UX Studio Cookie Consent: the banner, the stored choice and the release of held-back scripts and iframes.
 *
 * No dependencies, no jQuery. Reads the JSON block #uxc-config (printed by includes/Modules/Consent). Minified to
 * consent.min.js by `npm run build:widget`.
 *
 * Choice cookie `uxs_consent` = 1.<policy version>.<bits>.<unix time>, bits 1 preferences, 2 statistics, 4 marketing.
 * Blocked script: <script type="text/plain" data-uxc="statistics marketing"> (any of the listed categories releases it).
 * Blocked iframe: <iframe data-uxc="marketing" data-uxc-src="..." data-uxc-service="YouTube">.
 *
 * Public API: window.uxConsent.has( category ), .get(), .open(); event `uxstudio:consent` on document;
 * window.uxstudioConsent = { preferences, statistics, marketing } (read by the Forms conversion tracking).
 */
( function () {
	'use strict';

	var doc = document;
	var win = window;
	var cfgNode = doc.getElementById( 'uxc-config' );
	if ( ! cfgNode ) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse( cfgNode.textContent );
	} catch ( e ) {
		return;
	}

	var NAME = 'uxs_consent';
	var CATS = [ 'preferences', 'statistics', 'marketing' ];
	var BIT = { preferences: 1, statistics: 2, marketing: 4 };
	var T = cfg.t || {};

	var state = null; // { preferences, statistics, marketing } booleans once the visitor decided
	var implicit = false; // outside the region the banner is meant for: everything counts as allowed
	var root = null;
	var fab = null;
	var opener = null;

	function el( tag, cls, text ) {
		var n = doc.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	/* ---------- stored choice ---------- */

	function readCookie() {
		var m = new RegExp( '(?:^|; )' + NAME + '=([^;]*)' ).exec( doc.cookie );
		if ( ! m ) {
			return null;
		}
		var p = decodeURIComponent( m[ 1 ] ).split( '.' );
		if ( p[ 0 ] !== '1' || +p[ 1 ] !== cfg.v ) {
			return null;
		}
		var bits = +p[ 2 ] || 0;
		var st = {};
		CATS.forEach( function ( c ) {
			st[ c ] = !! ( bits & BIT[ c ] );
		} );
		return st;
	}

	function writeCookie( st ) {
		var bits = 0;
		CATS.forEach( function ( c ) {
			if ( st[ c ] ) {
				bits |= BIT[ c ];
			}
		} );
		var value = [ 1, cfg.v, bits, Math.floor( Date.now() / 1000 ) ].join( '.' );
		doc.cookie = NAME + '=' + value + ';max-age=' + cfg.days * 86400 + ';path=/;SameSite=Lax' + ( win.location.protocol === 'https:' ? ';Secure' : '' );
	}

	function has( category ) {
		if ( category === 'necessary' ) {
			return true;
		}
		return implicit || !! ( state && state[ category ] );
	}

	function snapshot() {
		return { necessary: true, preferences: has( 'preferences' ), statistics: has( 'statistics' ), marketing: has( 'marketing' ) };
	}

	/* ---------- Google Consent Mode ---------- */

	function gtag() {
		( win.dataLayer = win.dataLayer || [] ).push( arguments );
	}

	function signal( source ) {
		var s = snapshot();
		win.uxstudioConsent = s;
		// A stored choice was already sent by the inline defaults script, so only a new decision needs an update.
		// It goes before the event below, so a Google tag fired by that event already sees the new state.
		if ( cfg.gcm && ( source === 'user' || source === 'region' ) ) {
			var g = function ( on ) {
				return on ? 'granted' : 'denied';
			};
			gtag( 'consent', 'update', {
				functionality_storage: g( s.preferences ),
				personalization_storage: g( s.preferences ),
				analytics_storage: g( s.statistics ),
				ad_storage: g( s.marketing ),
				ad_user_data: g( s.marketing ),
				ad_personalization: g( s.marketing ),
			} );
		}
		// Trigger for Google Tag Manager, pushed on EVERY page view and every decision (also for a choice read from the
		// cookie), so a tag that does not read Consent Mode (Meta Pixel, Sklik, TikTok, Hotjar...) can be fired by a
		// custom event trigger `uxc_consent` plus a condition on the variable it needs. Names are stable:
		//   event: 'uxc_consent', uxc_preferences / uxc_statistics / uxc_marketing: true | false, uxc_source: 'stored' | 'user' | 'region' | 'none'
		( win.dataLayer = win.dataLayer || [] ).push( {
			event: 'uxc_consent',
			uxc_preferences: s.preferences,
			uxc_statistics: s.statistics,
			uxc_marketing: s.marketing,
			uxc_source: source,
		} );
		try {
			doc.dispatchEvent( new CustomEvent( 'uxstudio:consent', { detail: { categories: s, source: source } } ) );
		} catch ( e ) {}
	}

	/* ---------- release of held-back content ---------- */

	var queue = [];
	var running = false;

	function allowed( list ) {
		return ( list || '' ).split( ' ' ).some( function ( c ) {
			return c && has( c );
		} );
	}

	function runQueue() {
		if ( running ) {
			return;
		}
		running = true;
		( function next() {
			var old = queue.shift();
			if ( ! old ) {
				running = false;
				return;
			}
			var fresh = doc.createElement( 'script' );
			Array.prototype.forEach.call( old.attributes, function ( a ) {
				if ( a.name !== 'type' && a.name.indexOf( 'data-uxc' ) !== 0 ) {
					fresh.setAttribute( a.name, a.value );
				}
			} );
			var type = old.getAttribute( 'data-uxc-type' );
			if ( type ) {
				fresh.type = type;
			}
			if ( old.getAttribute( 'src' ) ) {
				// Wait for each external script, so a later inline script can rely on it.
				fresh.onload = fresh.onerror = next;
				old.parentNode.replaceChild( fresh, old );
			} else {
				fresh.text = old.text;
				old.parentNode.replaceChild( fresh, old );
				next();
			}
		} )();
	}

	function loadFrame( f ) {
		f.src = f.getAttribute( 'data-uxc-src' );
		f.hidden = false;
		if ( f._uxcPh && f._uxcPh.parentNode ) {
			f._uxcPh.parentNode.removeChild( f._uxcPh );
		}
	}

	function holdFrame( f ) {
		f._uxc = 1;
		f.hidden = true;
		var ph = el( 'div', 'uxc-ph' );
		var w = parseInt( f.getAttribute( 'width' ), 10 );
		var h = parseInt( f.getAttribute( 'height' ), 10 );
		if ( w > 0 && h > 0 && ! /%/.test( f.getAttribute( 'width' ) ) ) {
			ph.style.aspectRatio = w + ' / ' + h;
		}
		ph.appendChild( el( 'p', '', ( T.ph || '%s' ).replace( '%s', f.getAttribute( 'data-uxc-service' ) || '' ) ) );
		var b = el( 'button', 'uxc-btn', T.load );
		b.type = 'button';
		b.addEventListener( 'click', function () {
			loadFrame( f );
		} );
		ph.appendChild( b );
		f._uxcPh = ph;
		f.parentNode.insertBefore( ph, f );
	}

	function scan() {
		Array.prototype.forEach.call( doc.querySelectorAll( 'script[type="text/plain"][data-uxc]' ), function ( s ) {
			if ( ! s._uxc && allowed( s.getAttribute( 'data-uxc' ) ) ) {
				s._uxc = 1;
				queue.push( s );
			}
		} );
		Array.prototype.forEach.call( doc.querySelectorAll( 'iframe[data-uxc-src]' ), function ( f ) {
			if ( ! f._uxc ) {
				holdFrame( f );
			}
			if ( f.hidden && allowed( f.getAttribute( 'data-uxc' ) ) ) {
				loadFrame( f );
			}
		} );
		runQueue();
	}

	/* ---------- banner ---------- */

	function focusables() {
		return Array.prototype.filter.call( root.querySelectorAll( 'button, a[href], input, summary' ), function ( n ) {
			return ! n.disabled && n.offsetParent !== null;
		} );
	}

	function commit( choice ) {
		var before = state || ( implicit ? { preferences: 1, statistics: 1, marketing: 1 } : null );
		implicit = false;
		state = {};
		CATS.forEach( function ( c ) {
			state[ c ] = !! choice[ c ];
		} );
		writeCookie( state );
		hide();
		signal( 'user' );
		scan();
		var revoked = before && CATS.some( function ( c ) {
			return before[ c ] && ! state[ c ];
		} );
		if ( revoked ) {
			// Scripts that already ran cannot be taken back: start the page again without them.
			win.setTimeout( function () {
				win.location.reload();
			}, 0 );
		}
	}

	function setView( detail ) {
		root.className = root.className.replace( / is-detail/, '' ) + ( detail ? ' is-detail' : '' );
		var dlg = root.querySelector( '.uxc-dlg' );
		dlg.setAttribute( 'aria-modal', detail ? 'true' : 'false' );
		dlg.setAttribute( 'aria-labelledby', detail ? 'uxc-t2' : 'uxc-t' );
		root.querySelector( '.uxc-main' ).hidden = detail;
		root.querySelector( '.uxc-detail' ).hidden = ! detail;
		if ( detail ) {
			root.querySelector( '.uxc-detail h2' ).focus();
		}
	}

	function hide() {
		if ( root ) {
			root.hidden = true;
		}
		if ( cfg.fab && ! implicit ) {
			showFab();
		}
		if ( opener && opener.focus ) {
			opener.focus();
		}
		opener = null;
	}

	function showFab() {
		if ( fab ) {
			fab.hidden = false;
			return;
		}
		fab = el( 'button', 'uxc-fab' + ( cfg.fabpos === 'right' ? ' uxc-r' : '' ) );
		fab.type = 'button';
		fab.setAttribute( 'aria-label', T.fab );
		fab.title = T.fab;
		fab.innerHTML = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01M16 15.5v.01M12 12v.01M11 17v.01M7 14v.01"/></svg>';
		fab.addEventListener( 'click', function () {
			open( fab );
		} );
		doc.body.appendChild( fab );
	}

	function build() {
		root = el( 'div', 'uxc uxc-' + cfg.layout + ' uxc-' + cfg.pos );
		root.hidden = true;
		root.appendChild( el( 'div', 'uxc-ov' ) );
		var dlg = el( 'div', 'uxc-dlg' );
		dlg.setAttribute( 'role', 'dialog' );
		dlg.setAttribute( 'aria-modal', 'false' );
		dlg.setAttribute( 'aria-labelledby', 'uxc-t' );
		dlg.setAttribute( 'aria-describedby', 'uxc-m' );

		var all = function () {
			commit( { preferences: 1, statistics: 1, marketing: 1 } );
		};
		var none = function () {
			commit( {} );
		};
		var button = function ( label, cls, fn ) {
			var b = el( 'button', 'uxc-btn' + ( cls ? ' ' + cls : '' ), label );
			b.type = 'button';
			b.addEventListener( 'click', fn );
			return b;
		};

		// First layer: accept and reject look the same, customize is the quieter third option.
		var main = el( 'div', 'uxc-main' );
		var text = el( 'div', 'uxc-text' );
		var title = el( 'h2', 'uxc-h', T.title );
		title.id = 'uxc-t';
		title.tabIndex = -1;
		var msg = el( 'p', 'uxc-p', T.msg );
		msg.id = 'uxc-m';
		text.appendChild( title );
		text.appendChild( msg );
		if ( cfg.policy && /^(https?:\/\/|\/)/i.test( cfg.policy ) ) {
			var link = el( 'a', 'uxc-a', T.policy );
			link.href = cfg.policy;
			msg.appendChild( doc.createTextNode( ' ' ) );
			msg.appendChild( link );
		}
		var actions = el( 'div', 'uxc-actions' );
		actions.appendChild( button( T.reject, '', none ) );
		actions.appendChild( button( T.accept, '', all ) );
		actions.appendChild( button( T.settings, 'uxc-alt', function () {
			setView( true );
		} ) );
		main.appendChild( text );
		main.appendChild( actions );

		// Second layer: one switch per category, with the services in use.
		var detail = el( 'div', 'uxc-detail' );
		detail.hidden = true;
		var h = el( 'h2', 'uxc-h', T.settings );
		h.id = 'uxc-t2';
		h.tabIndex = -1;
		detail.appendChild( h );
		var boxes = {};
		cfg.c.forEach( function ( c ) {
			var row = el( 'div', 'uxc-cat' );
			var label = el( 'label', 'uxc-sw' );
			var input = el( 'input' );
			input.type = 'checkbox';
			if ( c.id === 'necessary' ) {
				input.checked = true;
				input.disabled = true;
			} else {
				input.checked = !! ( state && state[ c.id ] );
				boxes[ c.id ] = input;
			}
			label.appendChild( input );
			label.appendChild( el( 'span', 'uxc-n', c.n ) );
			if ( c.id === 'necessary' ) {
				label.appendChild( el( 'span', 'uxc-on', T.always ) );
			}
			row.appendChild( label );
			row.appendChild( el( 'p', 'uxc-d', c.d ) );
			var list = c.s || [];
			if ( c.id !== 'necessary' ) {
				var det = el( 'details', 'uxc-svc' );
				det.appendChild( el( 'summary', '', T.services + ' (' + list.length + ')' ) );
				if ( list.length ) {
					var ul = el( 'ul' );
					list.forEach( function ( s ) {
						ul.appendChild( el( 'li', '', s ) );
					} );
					det.appendChild( ul );
				} else {
					det.appendChild( el( 'p', 'uxc-d', T.none ) );
				}
				row.appendChild( det );
			}
			detail.appendChild( row );
		} );
		var dActions = el( 'div', 'uxc-actions' );
		dActions.appendChild( button( T.reject, '', none ) );
		dActions.appendChild( button( T.accept, '', all ) );
		dActions.appendChild( button( T.save, 'uxc-alt', function () {
			var choice = {};
			CATS.forEach( function ( c ) {
				choice[ c ] = boxes[ c ] && boxes[ c ].checked;
			} );
			commit( choice );
		} ) );
		detail.appendChild( dActions );

		dlg.appendChild( main );
		dlg.appendChild( detail );
		root.appendChild( dlg );
		root.addEventListener( 'keydown', onKey );
		doc.body.insertBefore( root, doc.body.firstChild );
		root._boxes = boxes;
	}

	function onKey( e ) {
		var detail = / is-detail/.test( root.className );
		if ( e.key === 'Escape' && ( detail || state || implicit ) ) {
			e.preventDefault();
			if ( state || implicit ) {
				hide();
			} else {
				setView( false );
			}
			return;
		}
		if ( e.key === 'Tab' && detail ) {
			var f = focusables();
			if ( ! f.length ) {
				return;
			}
			var first = f[ 0 ];
			var last = f[ f.length - 1 ];
			if ( e.shiftKey && ( doc.activeElement === first || doc.activeElement.tabIndex === -1 ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && doc.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	}

	function open( from ) {
		opener = from || null;
		if ( ! root ) {
			build();
		}
		// Preselect what is stored.
		CATS.forEach( function ( c ) {
			if ( root._boxes[ c ] ) {
				root._boxes[ c ].checked = has( c );
			}
		} );
		if ( fab ) {
			fab.hidden = true;
		}
		root.hidden = false;
		setView( true );
	}

	/* ---------- start ---------- */

	function show() {
		build();
		root.hidden = false;
		setView( false );
	}

	function decideRegion( inRegion ) {
		if ( inRegion ) {
			show();
			return;
		}
		implicit = true;
		signal( 'region' );
		scan();
	}

	function start() {
		state = readCookie();
		if ( state ) {
			signal( 'stored' );
			scan();
			if ( cfg.fab ) {
				showFab();
			}
			return;
		}
		signal( 'none' );
		scan(); // Frames get their placeholders even before a choice.
		if ( ! cfg.geo || ! win.fetch ) {
			show();
			return;
		}
		var known = null;
		try {
			known = win.sessionStorage.getItem( 'uxc_eu' );
		} catch ( e ) {}
		if ( known !== null ) {
			decideRegion( known === '1' );
			return;
		}
		win.fetch( cfg.geo, { credentials: 'omit', cache: 'no-store' } )
			.then( function ( r ) {
				return r.ok ? r.json() : { eu: true };
			} )
			.then( function ( j ) {
				var eu = ! j || j.eu !== false;
				try {
					win.sessionStorage.setItem( 'uxc_eu', eu ? '1' : '0' );
				} catch ( e ) {}
				decideRegion( eu );
			} )
			.catch( function () {
				decideRegion( true );
			} );
	}

	win.uxConsent = {
		has: has,
		get: snapshot,
		open: function () {
			open( doc.activeElement );
		},
	};

	doc.addEventListener( 'click', function ( e ) {
		var t = e.target && e.target.closest ? e.target.closest( '[data-uxc-open], a[href="#uxc-open"]' ) : null;
		if ( t ) {
			e.preventDefault();
			open( t );
		}
	} );

	start();
} )();
