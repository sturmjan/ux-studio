/**
 * Shield of UX Studio: the browser side of the key-less proof-of-work CAPTCHA (PLAN.md 31).
 *
 * One file, three jobs: the widget on the page, the solver inside a Web Worker (the page starts this same file as the
 * worker) and a main-thread solver when a worker is not allowed. tests/captcha-shield.mjs loads the very same file in
 * Node. ES5 syntax, no dependencies, no cookies, no requests except the one challenge of this site.
 *
 * Why not crypto.subtle: it answers asynchronously for every single hash and measured about 40 times slower here
 * (24 thousand against one million hashes a second), and it does not exist on a plain http page. The hot loop hashes
 * one 64 byte block in plain JS instead; the same code runs everywhere.
 */
( function ( root ) {
	'use strict';

	/* ── SHA-256 ── */

	var K = [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da, 0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	];
	var IV = [ 0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19 ];
	var W = new Array( 64 );

	/** One block: w = 16 words, h = 8 words in and out. */
	function block( w, h ) {
		var i, a, b, c, d, e, f, g, k, t1, t2, x, y;
		for ( i = 0; i < 16; i++ ) { W[ i ] = w[ i ]; }
		for ( i = 16; i < 64; i++ ) {
			x = W[ i - 15 ];
			y = W[ i - 2 ];
			W[ i ] = ( W[ i - 16 ] + ( ( ( x >>> 7 ) | ( x << 25 ) ) ^ ( ( x >>> 18 ) | ( x << 14 ) ) ^ ( x >>> 3 ) ) + W[ i - 7 ] + ( ( ( y >>> 17 ) | ( y << 15 ) ) ^ ( ( y >>> 19 ) | ( y << 13 ) ) ^ ( y >>> 10 ) ) ) | 0;
		}
		a = h[ 0 ]; b = h[ 1 ]; c = h[ 2 ]; d = h[ 3 ]; e = h[ 4 ]; f = h[ 5 ]; g = h[ 6 ]; k = h[ 7 ];
		for ( i = 0; i < 64; i++ ) {
			t1 = ( k + ( ( ( e >>> 6 ) | ( e << 26 ) ) ^ ( ( e >>> 11 ) | ( e << 21 ) ) ^ ( ( e >>> 25 ) | ( e << 7 ) ) ) + ( ( e & f ) ^ ( ~e & g ) ) + K[ i ] + W[ i ] ) | 0;
			t2 = ( ( ( ( a >>> 2 ) | ( a << 30 ) ) ^ ( ( a >>> 13 ) | ( a << 19 ) ) ^ ( ( a >>> 22 ) | ( a << 10 ) ) ) + ( ( a & b ) ^ ( a & c ) ^ ( b & c ) ) ) | 0;
			k = g; g = f; f = e; e = ( d + t1 ) | 0; d = c; c = b; b = a; a = ( t1 + t2 ) | 0;
		}
		h[ 0 ] = ( h[ 0 ] + a ) | 0; h[ 1 ] = ( h[ 1 ] + b ) | 0; h[ 2 ] = ( h[ 2 ] + c ) | 0; h[ 3 ] = ( h[ 3 ] + d ) | 0;
		h[ 4 ] = ( h[ 4 ] + e ) | 0; h[ 5 ] = ( h[ 5 ] + f ) | 0; h[ 6 ] = ( h[ 6 ] + g ) | 0; h[ 7 ] = ( h[ 7 ] + k ) | 0;
	}

	/** Words of the SHA-256 of an ASCII string (any length). */
	function digest( text ) {
		var h = IV.slice(), n = text.length, total = ( ( n + 9 + 63 ) >> 6 ) << 4, w = new Array( total ), i, j;
		for ( i = 0; i < total; i++ ) { w[ i ] = 0; }
		for ( i = 0; i < n; i++ ) { w[ i >> 2 ] |= ( text.charCodeAt( i ) & 255 ) << ( 24 - 8 * ( i & 3 ) ); }
		w[ n >> 2 ] |= 0x80 << ( 24 - 8 * ( n & 3 ) );
		w[ total - 1 ] = n * 8;
		for ( j = 0; j < total; j += 16 ) { block( w.slice( j, j + 16 ), h ); }
		return h;
	}

	function hex( words ) {
		var out = '', i, s;
		for ( i = 0; i < words.length; i++ ) {
			s = ( words[ i ] >>> 0 ).toString( 16 );
			out += ( '00000000' + s ).slice( -8 );
		}
		return out;
	}

	function sha256Hex( text ) { return hex( digest( text ) ); }

	/**
	 * Find the number n in [from, to) for which sha256( salt + n ) equals the challenge, or -1. The hot loop: one block
	 * per try (salt of 32 characters plus at most 7 digits fits into 55 bytes), no allocation.
	 */
	function solveRange( salt, challenge, from, to ) {
		var target = [], i, n, len, text, w = new Array( 16 ), h = new Array( 8 ), c, base = salt.length;
		for ( i = 0; i < 8; i++ ) { target.push( parseInt( challenge.substr( i * 8, 8 ), 16 ) | 0 ); }
		if ( base + 8 > 55 ) { return -2; }
		for ( n = from; n < to; n++ ) {
			text = salt + n;
			len = text.length;
			for ( i = 0; i < 16; i++ ) { w[ i ] = 0; }
			for ( i = 0; i < len; i++ ) { w[ i >> 2 ] |= text.charCodeAt( i ) << ( 24 - 8 * ( i & 3 ) ); }
			w[ len >> 2 ] |= 0x80 << ( 24 - 8 * ( len & 3 ) );
			w[ 15 ] = len * 8;
			for ( i = 0; i < 8; i++ ) { h[ i ] = IV[ i ]; }
			block( w, h );
			c = h[ 0 ] === target[ 0 ] && h[ 1 ] === target[ 1 ] && h[ 2 ] === target[ 2 ] && h[ 3 ] === target[ 3 ] && h[ 4 ] === target[ 4 ] && h[ 5 ] === target[ 5 ] && h[ 6 ] === target[ 6 ] && h[ 7 ] === target[ 7 ];
			if ( c ) { return n; }
		}
		return -1;
	}

	/** Solve in slices on the main thread, so the page stays alive. cb( number | -1 ). */
	function solveChunked( salt, challenge, max, cb ) {
		var at = 0, step = 20000;
		( function slice() {
			var found = solveRange( salt, challenge, at, Math.min( at + step, max + 1 ) );
			if ( found !== -1 || at + step > max ) { cb( found ); return; }
			at += step;
			setTimeout( slice, 0 );
		}() );
	}

	var api = { sha256Hex: sha256Hex, solveRange: solveRange, solveChunked: solveChunked };

	/* ── Worker side: the page starts this file as a worker ── */

	if ( typeof document === 'undefined' && typeof importScripts === 'function' ) {
		root.onmessage = function ( e ) {
			var d = e.data;
			root.postMessage( { number: solveRange( d.salt, d.challenge, 0, d.max + 1 ) } );
		};
		return;
	}

	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	if ( typeof document === 'undefined' ) { return; }

	/* ── Page side ── */

	var widgets = [];

	function solve( src, c, cb ) {
		var done = false, worker = null, timer;
		function finish( number ) {
			if ( done ) { return; }
			done = true;
			clearTimeout( timer );
			if ( worker ) { worker.terminate(); }
			cb( number );
		}
		timer = setTimeout( function () { finish( -1 ); }, 60000 );
		try {
			if ( root.Worker && src ) {
				worker = new root.Worker( src );
				worker.onmessage = function ( e ) { finish( e.data.number ); };
				worker.onerror = function () {
					// A worker that cannot start (CSP, odd browser): keep going on the main thread.
					if ( worker ) { worker.terminate(); worker = null; }
					if ( ! done ) { solveChunked( c.salt, c.challenge, c.number_max, finish ); }
				};
				worker.postMessage( { salt: c.salt, challenge: c.challenge, max: c.number_max } );
				return;
			}
		} catch ( err ) { worker = null; }
		solveChunked( c.salt, c.challenge, c.number_max, finish );
	}

	function Widget( el ) {
		var self = this;
		this.el = el;
		this.state = 'idle';
		this.check = el.querySelector( '.uxs-shield__check' );
		this.status = el.querySelector( '.uxs-shield__status' );
		this.input = el.querySelector( '[data-uxs-shield-payload]' );
		this.fallback = el.querySelector( '.uxs-shield__fallback' );
		this.waiters = [];
		this.readyAt = 0;
		this.timer = 0;
		if ( this.fallback ) { this.fallback.parentNode.removeChild( this.fallback ); }
		this.say( 'idle' );
		if ( this.check ) {
			this.check.addEventListener( 'click', function ( e ) {
				if ( self.state === 'done' ) { e.preventDefault(); return; }
				self.start();
			} );
		}
		if ( el.getAttribute( 'data-autostart' ) === '1' ) { this.start(); }
	}

	Widget.prototype.say = function ( key ) {
		var label = this.el.getAttribute( 'data-l-' + key ) || '';
		this.el.setAttribute( 'data-state', key === 'idle' ? 'idle' : this.state );
		if ( this.status ) { this.status.textContent = key === 'idle' ? '' : label; }
		if ( this.check ) {
			this.check.checked = this.state === 'done';
			this.check.setAttribute( 'aria-busy', this.state === 'working' ? 'true' : 'false' );
		}
	};

	/** True when a submit may go: solved and old enough, or failed with a typed question to fall back on. */
	Widget.prototype.ready = function () {
		return ( this.state === 'failed' && !! this.fallback ) || ( this.state === 'done' && Date.now() >= this.readyAt );
	};

	Widget.prototype.whenReady = function ( cb ) {
		var self = this;
		if ( this.ready() ) { cb( this.state === 'done' ); return; }
		this.waiters.push( cb );
		if ( this.state === 'idle' || this.state === 'failed' ) { this.start(); }
		if ( this.state === 'done' ) {
			setTimeout( function () { self.flush(); }, Math.max( 0, this.readyAt - Date.now() ) + 5 );
		}
	};

	Widget.prototype.flush = function () {
		var list, ok, i;
		if ( this.state === 'failed' ) { ok = false; } else if ( this.ready() ) { ok = true; } else { return; }
		list = this.waiters;
		this.waiters = [];
		for ( i = 0; i < list.length; i++ ) { list[ i ]( ok ); }
	};

	Widget.prototype.fail = function () {
		this.state = 'failed';
		this.say( 'error' );
		if ( this.fallback ) {
			this.el.appendChild( this.fallback );
			this.el.setAttribute( 'data-fallback', '1' );
		}
		this.flush();
	};

	Widget.prototype.reset = function () {
		clearTimeout( this.timer );
		this.state = 'idle';
		if ( this.input ) { this.input.value = ''; }
		this.el.removeAttribute( 'data-fallback' );
		if ( this.fallback && this.fallback.parentNode ) { this.fallback.parentNode.removeChild( this.fallback ); }
		this.say( 'idle' );
	};

	Widget.prototype.start = function () {
		var self = this, url = this.el.getAttribute( 'data-challenge' ), min = parseInt( this.el.getAttribute( 'data-min' ), 10 ) || 0;
		if ( this.state === 'working' || this.state === 'done' ) { return; }
		if ( this.fallback && this.fallback.parentNode ) { this.fallback.parentNode.removeChild( this.fallback ); }
		this.state = 'working';
		this.say( 'working' );
		if ( ! root.fetch || ! url ) { this.fail(); return; }
		root.fetch( url, { credentials: 'omit', cache: 'no-store' } )
			.then( function ( res ) { if ( ! res.ok ) { throw new Error( 'http' ); } return res.json(); } )
			.then( function ( c ) {
				var arrived = Date.now();
				if ( ! c || typeof c.salt !== 'string' || typeof c.challenge !== 'string' || typeof c.number_max !== 'number' ) { throw new Error( 'shape' ); }
				solve( self.el.getAttribute( 'data-worker' ), c, function ( number ) {
					if ( number < 0 ) { self.fail(); return; }
					var data = { algorithm: c.algorithm, challenge: c.challenge, number: number, salt: c.salt, signature: c.signature, issued: c.issued, expires: c.expires, number_max: c.number_max };
					if ( self.input ) { self.input.value = btoa( JSON.stringify( data ) ); }
					self.state = 'done';
					// The server refuses an answer that comes sooner than the fill time after it issued the challenge;
					// the clock starts when the challenge arrived, which is never earlier than when it was issued.
					self.readyAt = arrived + min + 150;
					self.say( 'done' );
					// A little before the challenge dies, the next interaction asks for a new one.
					clearTimeout( self.timer );
					self.timer = setTimeout( function () { if ( self.state === 'done' ) { self.reset(); } }, Math.max( 10000, c.expires * 1000 - c.issued - 25000 ) );
					self.flush();
					if ( self.waiters.length ) { setTimeout( function () { self.flush(); }, Math.max( 0, self.readyAt - Date.now() ) + 5 ); }
				} );
			} )
			.catch( function () { self.fail(); } );
	};

	function widgetsIn( node ) {
		var out = [], i;
		for ( i = 0; i < widgets.length; i++ ) {
			if ( node === widgets[ i ].el || ( node.contains && node.contains( widgets[ i ].el ) ) ) { out.push( widgets[ i ] ); }
		}
		return out;
	}

	function scan() {
		var list = document.querySelectorAll( '[data-uxs-shield]' ), i;
		for ( i = 0; i < list.length; i++ ) {
			if ( ! list[ i ].getAttribute( 'data-uxs-bound' ) ) {
				list[ i ].setAttribute( 'data-uxs-bound', '1' );
				widgets.push( new Widget( list[ i ] ) );
			}
		}
	}

	function formOf( target ) {
		var node = target;
		while ( node && node.nodeType === 1 ) {
			if ( node.tagName === 'FORM' ) { return node; }
			node = node.parentNode;
		}
		return null;
	}

	// First contact with a form that has a widget: start working while the visitor still fills it in.
	function touch( e ) {
		var form = formOf( e.target ), list, i;
		if ( ! form ) { return; }
		list = widgetsIn( form );
		for ( i = 0; i < list.length; i++ ) { if ( list[ i ].state === 'idle' ) { list[ i ].start(); } }
	}
	[ 'focusin', 'pointerdown', 'keydown', 'input', 'touchstart' ].forEach( function ( name ) { document.addEventListener( name, touch, true ); } );

	// A submit before the answer is ready waits for it ("Ověřuji…"), then goes on by itself.
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target, list = widgetsIn( form ), pending = [], i, submitter = e.submitter || null;
		for ( i = 0; i < list.length; i++ ) { if ( ! list[ i ].ready() ) { pending.push( list[ i ] ); } }
		if ( ! pending.length ) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		var left = pending.length, ok = true, buttons = form.querySelectorAll( 'button[type=submit],input[type=submit]' ), j;
		for ( j = 0; j < buttons.length; j++ ) { buttons[ j ].setAttribute( 'data-uxs-was', buttons[ j ].disabled ? '1' : '0' ); buttons[ j ].disabled = true; }
		pending.forEach( function ( w ) {
			w.whenReady( function ( good ) {
				ok = ok && good;
				left--;
				if ( left > 0 ) { return; }
				for ( j = 0; j < buttons.length; j++ ) { buttons[ j ].disabled = buttons[ j ].getAttribute( 'data-uxs-was' ) === '1'; }
				// A failed solve shows the typed question instead of sending a form the server would refuse.
				if ( ! ok ) { return; }
				if ( form.requestSubmit ) { form.requestSubmit( submitter && submitter.form === form ? submitter : undefined ); } else { form.submit(); }
			} );
		} );
	}, true );

	api.scan = scan;
	api.reset = function ( node ) {
		var list = widgetsIn( node || document ), i;
		for ( i = 0; i < list.length; i++ ) { list[ i ].reset(); }
	};
	root.UxStudioShield = api;

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', scan ); } else { scan(); }
	if ( root.MutationObserver ) {
		var queued = false;
		new root.MutationObserver( function () {
			if ( queued ) { return; }
			queued = true;
			setTimeout( function () { queued = false; scan(); }, 50 );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
}( typeof self !== 'undefined' ? self : this ) );
