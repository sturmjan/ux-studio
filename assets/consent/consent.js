/**
 * UX Studio Cookie Consent: the banner, the stored choice and the release of held-back scripts and iframes.
 *
 * No dependencies, no jQuery. Reads the JSON block #uxc-config (printed by includes/Modules/Consent). Minified to
 * consent.min.js by `npm run build:widget`.
 *
 * Choice cookie `uxs_consent` = 1.<policy version>.<bits>.<unix time>[.<consent id>], bits 1 preferences, 2 statistics, 4 marketing.
 * The consent id is 32 random hex characters made here; every decision is reported to cfg.rec (consent records, F5) with it.
 * Blocked script: <script type="text/plain" data-uxc="statistics marketing"> (any of the listed categories releases it).
 * Blocked iframe: <iframe data-uxc="marketing" data-uxc-src="..." data-uxc-service="YouTube">.
 *
 * Public API: window.uxConsent.has( category ), .get(), .open(), and for automation the same as the buttons (consent records and
 * the `uxc_consent` event included): .acceptAll(), .rejectAll(), .set( { preferences, statistics, marketing } ); event `uxstudio:consent` on document;
 * window.uxstudioConsent = { preferences, statistics, marketing } (read by the Forms conversion tracking).
 *
 * Templates (cfg.tpl, PLAN 42.20): classic (default), cookiebot (tabs + switches), cookie (light card, round buttons), slim (thin bar).
 * One script, one behaviour: a template only changes which elements build() makes and the class it puts on the root; its style is
 * consent-<tpl>.css, loaded on top of consent.css by the page (classic lives in consent.css itself).
 *
 * Preview (admin, PLAN 42.18): cfg.preview = main | detail | fab | ph. The page is a sandboxed iframe without allow-same-origin, so
 * there is no cookie, no storage and no request: nothing is stored or reported, the buttons do nothing, the view stays as asked.
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
	var PV = !! cfg.preview;
	var TPL = [ 'cookiebot', 'cookie', 'slim' ].indexOf( cfg.tpl ) < 0 ? 'classic' : cfg.tpl;
	var CB = TPL === 'cookiebot';
	var quiet = PV; // no focus change while the admin is typing in the form next to the preview

	var state = null; // { preferences, statistics, marketing } booleans once the visitor decided
	var implicit = false; // outside the region the banner is meant for: everything counts as allowed
	var root = null;
	var fab = null;
	var opener = null;
	var cid = ''; // anonymous id of this visitor's consent records (also the 5th part of the cookie)

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

	function bitsOf( st ) {
		var bits = 0;
		CATS.forEach( function ( c ) {
			if ( st[ c ] ) {
				bits |= BIT[ c ];
			}
		} );
		return bits;
	}

	function cookieId() {
		var m = new RegExp( '(?:^|; )' + NAME + '=([^;]*)' ).exec( doc.cookie );
		var id = m ? decodeURIComponent( m[ 1 ] ).split( '.' )[ 4 ] : '';
		return /^[a-f0-9]{32}$/.test( id || '' ) ? id : '';
	}

	function newId() {
		var a = [];
		var i;
		try {
			a = Array.prototype.slice.call( win.crypto.getRandomValues( new Uint8Array( 16 ) ) );
		} catch ( e ) {
			for ( i = 0; i < 16; i++ ) {
				a.push( Math.floor( Math.random() * 256 ) );
			}
		}
		return a
			.map( function ( b ) {
				return ( b < 16 ? '0' : '' ) + b.toString( 16 );
			} )
			.join( '' );
	}

	// Report a decision for the consent records. Fire and forget: never waits, never throws, never blocks the banner.
	function record( action, st ) {
		if ( ! cfg.rec ) {
			return;
		}
		try {
			var body = new win.URLSearchParams( { id: cid, v: cfg.v, c: bitsOf( st ), a: action, p: win.location.pathname } );
			if ( ! ( win.navigator.sendBeacon && win.navigator.sendBeacon( cfg.rec, body ) ) && win.fetch ) {
				win.fetch( cfg.rec, { method: 'POST', body: body, keepalive: true, credentials: 'omit' } ).catch( function () {} );
			}
		} catch ( e ) {}
	}

	function writeCookie( st ) {
		var value = [ 1, cfg.v, bitsOf( st ), Math.floor( Date.now() / 1000 ) ].concat( cid ? [ cid ] : [] ).join( '.' );
		doc.cookie = NAME + '=' + value + ';max-age=' + cfg.days * 86400 + ';path=/;SameSite=Lax' + ( win.location.protocol === 'https:' ? ';Secure' : '' );
	}

	// Migration from another banner (cfg.leg = { p: cookie prefix, v: policy number }): the visitor's choice in the old
	// cookies is taken over once, so nobody is asked again. Only a dismissed banner and the same policy number count.
	function legacy() {
		var l = cfg.leg;
		if ( ! l || ! /^[a-z0-9_]{1,20}$/.test( l.p || '' ) ) {
			return null;
		}
		var get = function ( n ) {
			var m = new RegExp( '(?:^|; )' + l.p + n + '=([^;]*)' ).exec( doc.cookie );
			return m ? decodeURIComponent( m[ 1 ] ) : '';
		};
		if ( get( 'banner-status' ) !== 'dismissed' || ( l.v && +get( 'policy_id' ) !== l.v ) ) {
			return null;
		}
		var st = {};
		var seen = false;
		CATS.forEach( function ( c ) {
			var v = get( c );
			seen = seen || !! v;
			st[ c ] = v === 'allow';
		} );
		return seen ? st : null;
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
		if ( cfg.gcm && ( source === 'user' || source === 'region' || source === 'import' ) ) {
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
		if ( PV ) {
			return;
		}
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

	/* ---------- cookies of the services (loaded when the detail is opened, not carried in every page) ---------- */

	var cookiePromise = null;

	function loadCookies() {
		if ( cookiePromise ) {
			return cookiePromise;
		}
		var labels = [];
		cfg.c.forEach( function ( c ) {
			( c.s || [] ).forEach( function ( s ) {
				if ( labels.indexOf( s ) < 0 ) {
					labels.push( s );
				}
			} );
		} );
		if ( ! cfg.ck || ! win.fetch || ! labels.length ) {
			cookiePromise = Promise.resolve( {} );
		} else {
			cookiePromise = win
				.fetch( cfg.ck + ( cfg.ck.indexOf( '?' ) < 0 ? '?' : '&' ) + 's=' + encodeURIComponent( labels.join( '|' ) ), { credentials: 'omit' } )
				.then( function ( r ) {
					return r.ok ? r.json() : {};
				} )
				.catch( function () {
					return {};
				} );
		}
		return cookiePromise;
	}

	function fillService( li, info ) {
		var meta = [];
		if ( info.p ) {
			meta.push( T.prov + ': ' + info.p );
		}
		if ( info.c ) {
			meta.push( T.ctry + ': ' + info.c );
		}
		if ( meta.length ) {
			li.appendChild( el( 'span', 'uxc-meta', meta.join( ', ' ) ) );
		}
		if ( info.u && /^https:\/\//i.test( info.u ) ) {
			var a = el( 'a', 'uxc-a uxc-meta', T.pol );
			a.href = info.u;
			a.target = '_blank';
			a.rel = 'noopener noreferrer';
			li.appendChild( a );
		}
		if ( info.k && info.k.length ) {
			var ul = el( 'ul', 'uxc-ck' );
			info.k.forEach( function ( k ) {
				var item = el( 'li' );
				item.appendChild( el( 'code', '', k[ 0 ] ) );
				item.appendChild( doc.createTextNode( ' ' + k[ 1 ] ) );
				if ( k[ 2 ] ) {
					item.appendChild( el( 'span', 'uxc-meta', T.keep + ': ' + k[ 2 ] ) );
				}
				ul.appendChild( item );
			} );
			li.appendChild( ul );
		} else {
			li.appendChild( el( 'span', 'uxc-meta', info.n || T.nock ) );
		}
	}

	/* ---------- banner ---------- */

	function focusables() {
		return Array.prototype.filter.call( root.querySelectorAll( 'button, a[href], input, summary' ), function ( n ) {
			return ! n.disabled && n.offsetParent !== null;
		} );
	}

	function commit( choice, kind ) {
		if ( PV ) {
			return;
		}
		var before = state || ( implicit ? { preferences: 1, statistics: 1, marketing: 1 } : null );
		implicit = false;
		state = {};
		CATS.forEach( function ( c ) {
			state[ c ] = !! choice[ c ];
		} );
		cid = cid || cookieId() || newId();
		writeCookie( state );
		var revoked = before && CATS.some( function ( c ) {
			return before[ c ] && ! state[ c ];
		} );
		record( revoked ? 'withdraw' : kind, state );
		hide();
		signal( 'user' );
		scan();
		if ( revoked ) {
			// Scripts that already ran cannot be taken back: start the page again without them.
			win.setTimeout( function () {
				win.location.reload();
			}, 0 );
		}
	}

	// Tabs of the cookiebot template: main (consent), detail, about.
	function setTab( name ) {
		root._tabs.forEach( function ( t ) {
			var on = t._p === name;
			t.setAttribute( 'aria-selected', on );
			t.tabIndex = on ? 0 : -1;
			root._panels[ t._p ].hidden = ! on;
		} );
	}

	function setView( detail ) {
		if ( CB ) {
			setTab( detail ? 'detail' : 'main' );
			return;
		}
		root.className = root.className.replace( / is-detail/, '' ) + ( detail ? ' is-detail' : '' );
		var dlg = root.querySelector( '.uxc-dlg' );
		dlg.setAttribute( 'aria-modal', detail ? 'true' : 'false' );
		dlg.setAttribute( 'aria-labelledby', detail ? 'uxc-t2' : 'uxc-t' );
		root.querySelector( '.uxc-main' ).hidden = detail;
		root.querySelector( '.uxc-detail' ).hidden = ! detail;
		if ( detail && ! quiet ) {
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

	// A small cookie with a bite and crumbs (own drawing); follows the text colour.
	function icon( size ) {
		return '<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01M16 15.5v.01M12 12v.01M11 17v.01M7 14v.01"/></svg>';
	}

	// The word UX1 in the system font (no web font, no request): a quiet signature link, off only through the PHP filter.
	function brand() {
		if ( ! cfg.by || ! /^https:\/\//i.test( cfg.by ) ) {
			return null;
		}
		var a = el( 'a', 'uxc-by' );
		a.href = cfg.by;
		a.target = '_blank';
		a.rel = 'noopener';
		a.setAttribute( 'aria-label', T.by || 'UX1' );
		a.title = T.by || 'UX1';
		// The visible word comes from CSS (::after, system font): it is a logotype, so it is not text a contrast check
		// should judge, and the link is named by aria-label.
		return a;
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
		fab.innerHTML = icon( 22 );
		fab.addEventListener( 'click', function () {
			open( fab );
		} );
		doc.body.appendChild( fab );
	}

	function build() {
		// Options of the chosen template (PLAN 42.22): cfg.o only holds the ones of that template.
		var O = cfg.o || {};
		root = el(
			'div',
			'uxc uxc-t-' + TPL + ( TPL === 'classic' ? ' uxc-' + cfg.layout : '' ) + ' uxc-' + cfg.pos +
				( TPL === 'cookiebot' && O.place === 'full' ? ' uxc-o-full' : '' ) +
				( TPL === 'cookie' && O.shape === 'rect' ? ' uxc-o-rect' : '' ) +
				( TPL === 'slim' && O.place === 'top' ? ' uxc-o-top' : '' )
		);
		root.hidden = true;
		root.appendChild( el( 'div', 'uxc-ov' ) );
		var dlg = el( 'div', 'uxc-dlg' );
		dlg.setAttribute( 'role', 'dialog' );
		dlg.setAttribute( 'aria-modal', 'false' );
		dlg.setAttribute( 'aria-labelledby', 'uxc-t' );
		dlg.setAttribute( 'aria-describedby', 'uxc-m' );

		var boxes = {};
		var all = function () {
			commit( { preferences: 1, statistics: 1, marketing: 1 }, 'accept_all' );
		};
		var none = function () {
			commit( {}, 'reject_all' );
		};
		var saveSel = function () {
			var choice = {};
			CATS.forEach( function ( c ) {
				choice[ c ] = boxes[ c ] && boxes[ c ].checked;
			} );
			commit( choice, 'custom' );
		};
		var button = function ( label, cls, fn ) {
			var b = el( 'button', 'uxc-btn' + ( cls ? ' ' + cls : '' ), label );
			b.type = 'button';
			b.addEventListener( 'click', fn );
			return b;
		};
		// A category with its switch (only the name when `plain`).
		var sw = function ( c, plain ) {
			var label = el( plain ? 'div' : 'label', 'uxc-sw' );
			if ( ! plain ) {
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
			}
			label.appendChild( el( 'span', 'uxc-n', c.n ) );
			if ( c.id === 'necessary' ) {
				label.appendChild( el( 'span', 'uxc-on', T.always ) );
			}
			return label;
		};

		// First layer: accept and reject look the same, customize is the quieter option.
		var main = el( 'div', 'uxc-main' );
		var text = el( 'div', 'uxc-text' );
		var title = el( 'h2', 'uxc-h', T.title );
		title.id = 'uxc-t';
		title.tabIndex = -1;
		var msg = el( 'p', 'uxc-p', T.msg );
		msg.id = 'uxc-m';
		if ( TPL === 'cookie' && O.icon !== false ) {
			var head = el( 'div', 'uxc-hd' );
			var ic = el( 'span', 'uxc-ic' );
			ic.innerHTML = icon( 40 );
			head.appendChild( ic );
			head.appendChild( title );
			text.appendChild( head );
		} else {
			text.appendChild( title );
		}
		text.appendChild( msg );
		if ( cfg.policy && /^(https?:\/\/|\/)/i.test( cfg.policy ) && ! ( TPL === 'slim' && O.link === false ) ) {
			var link = el( 'a', 'uxc-a', T.policy );
			link.href = cfg.policy;
			msg.appendChild( doc.createTextNode( ' ' ) );
			msg.appendChild( link );
		}
		// The buttons and their order depend on the template: r reject, a accept, s customize (opens the detail),
		// v allow the selection made with the switches. Reject and accept always get the same size and weight.
		var defs = {
			r: [ T.reject, 'uxc-no', none ],
			a: [ T.accept, 'uxc-yes', all ],
			s: [
				T.settings,
				'uxc-alt',
				function () {
					setView( true );
				},
			],
			v: [ T.save, 'uxc-alt', saveSel ],
		};
		var actions = el( 'div', 'uxc-actions' );
		( { cookiebot: 'rva', slim: 'rsa', cookie: 'sra' }[ TPL ] || 'ras' ).split( '' ).forEach( function ( k ) {
			actions.appendChild( button( defs[ k ][ 0 ], defs[ k ][ 1 ], defs[ k ][ 2 ] ) );
		} );
		main.appendChild( text );
		if ( CB ) {
			var row = el( 'div', 'uxc-swr' );
			cfg.c.forEach( function ( c ) {
				row.appendChild( sw( c ) );
			} );
			main.appendChild( row );
		} else {
			main.appendChild( actions );
		}

		// Second layer: the categories with the services in use (and a switch each, except in the tabs template).
		var detail = el( 'div', 'uxc-detail' );
		detail.hidden = true;
		if ( ! CB ) {
			var h = el( 'h2', 'uxc-h', T.settings );
			h.id = 'uxc-t2';
			h.tabIndex = -1;
			detail.appendChild( h );
		}
		cfg.c.forEach( function ( c ) {
			var row2 = el( 'div', 'uxc-cat' );
			row2.appendChild( sw( c, CB ) );
			row2.appendChild( el( 'p', 'uxc-d', c.d ) );
			var list = c.s || [];
			if ( c.id !== 'necessary' || list.length ) {
				var det = el( 'details', 'uxc-svc' );
				det.appendChild( el( 'summary', '', T.services + ' (' + list.length + ')' ) );
				if ( list.length ) {
					var ul = el( 'ul' );
					var items = {};
					list.forEach( function ( s ) {
						items[ s ] = el( 'li', '', s );
						ul.appendChild( items[ s ] );
					} );
					det.appendChild( ul );
					// The cookies of the services come on first opening, so the page itself stays small.
					det.addEventListener( 'toggle', function () {
						if ( ! det.open || det._ck ) {
							return;
						}
						det._ck = 1;
						loadCookies().then( function ( data ) {
							list.forEach( function ( s ) {
								if ( data[ s ] ) {
									fillService( items[ s ], data[ s ] );
								}
							} );
						} );
					} );
				} else {
					det.appendChild( el( 'p', 'uxc-d', T.none ) );
				}
				row2.appendChild( det );
			}
			detail.appendChild( row2 );
		} );
		root._id = el( 'p', 'uxc-d' );
		root._id.hidden = true;
		detail.appendChild( root._id );
		if ( ! CB ) {
			var dActions = el( 'div', 'uxc-actions' );
			dActions.appendChild( button( T.reject, 'uxc-no', none ) );
			dActions.appendChild( button( T.accept, 'uxc-yes', all ) );
			dActions.appendChild( button( T.save, 'uxc-alt', saveSel ) );
			detail.appendChild( dActions );
			dlg.appendChild( main );
			dlg.appendChild( detail );
		} else {
			// Tabs: Consent (the first layer), Details, About cookies. The three buttons stay under all of them.
			var tabs = [];
			var tabList = el( 'div', 'uxc-tabs' );
			tabList.setAttribute( 'role', 'tablist' );
			tabList.setAttribute( 'aria-label', T.tabs );
			var about = el( 'div', 'uxc-about' );
			about.appendChild( el( 'p', 'uxc-p', T.about ) );
			root._panels = { main: main, detail: detail, about: about };
			[ [ 'main', T.tab_c ], [ 'detail', T.tab_d ], [ 'about', T.tab_a ] ].slice( 0, O.about === false ? 2 : 3 ).forEach( function ( d ) {
				var tab = el( 'button', 'uxc-tab', d[ 1 ] );
				tab.type = 'button';
				tab.id = 'uxc-tab-' + d[ 0 ];
				tab._p = d[ 0 ];
				tab.setAttribute( 'role', 'tab' );
				tab.setAttribute( 'aria-controls', 'uxc-pn-' + d[ 0 ] );
				root._panels[ d[ 0 ] ].id = 'uxc-pn-' + d[ 0 ];
				root._panels[ d[ 0 ] ].setAttribute( 'role', 'tabpanel' );
				root._panels[ d[ 0 ] ].setAttribute( 'aria-labelledby', tab.id );
				tab.addEventListener( 'click', function () {
					setTab( d[ 0 ] );
				} );
				tab.addEventListener( 'keydown', function ( e ) {
					if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
						e.preventDefault();
						var next = tabs[ ( tabs.indexOf( tab ) + ( e.key === 'ArrowRight' ? 1 : tabs.length - 1 ) ) % tabs.length ];
						setTab( next._p );
						next.focus();
					}
				} );
				tabs.push( tab );
				tabList.appendChild( tab );
			} );
			root._tabs = tabs;
			dlg.appendChild( tabList );
			dlg.appendChild( main );
			dlg.appendChild( detail );
			if ( O.about !== false ) {
				dlg.appendChild( about );
			}
			// Tabs: the signature sits at the start of the button row, after the buttons in the tab order.
			var by1 = brand();
			if ( by1 ) {
				actions.appendChild( by1 );
			}
			dlg.appendChild( actions );
			setTab( 'main' );
		}
		if ( ! CB ) {
			var by2 = brand();
			if ( by2 ) {
				dlg.appendChild( by2 );
			}
		}
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
		root._id.textContent = cid && T.cid ? T.cid + ' ' + cid : '';
		root._id.hidden = ! root._id.textContent;
		if ( fab ) {
			fab.hidden = true;
		}
		root.hidden = false;
		if ( CB ) {
			setTab( 'main' ); // the switches are on this tab
		} else {
			setView( true );
		}
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
		if ( PV ) {
			scan();
			if ( cfg.preview === 'fab' ) {
				if ( cfg.fab ) {
					showFab();
				}
			} else if ( cfg.preview === 'detail' ) {
				open( null );
				if ( CB ) {
					setTab( 'detail' );
				}
			} else if ( cfg.preview === 'main' ) {
				show();
			}
			quiet = false;
			return;
		}
		state = readCookie();
		cid = cookieId();
		var old = state ? null : legacy();
		if ( old ) {
			// Not reported as a decision of the visitor (nothing new was decided); the old banner holds that record.
			state = old;
			cid = newId();
			writeCookie( state );
			signal( 'import' );
			scan();
			if ( cfg.fab ) {
				showFab();
			}
			return;
		}
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

	function choose( choice, kind ) {
		commit( choice, kind );
		return snapshot();
	}

	win.uxConsent = {
		has: has,
		get: snapshot,
		open: function () {
			open( doc.activeElement );
		},
		acceptAll: function () {
			return choose( { preferences: 1, statistics: 1, marketing: 1 }, 'accept_all' );
		},
		rejectAll: function () {
			return choose( {}, 'reject_all' );
		},
		set: function ( c ) {
			var choice = {};
			CATS.forEach( function ( k ) {
				choice[ k ] = !! ( c && c[ k ] );
			} );
			return choose( choice, 'custom' );
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
