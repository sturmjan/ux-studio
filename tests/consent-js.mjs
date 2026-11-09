// Test only (Node): `node tests/consent-js.mjs`
// Runs assets/consent/consent.js against a minimal stubbed browser and checks what it pushes to the dataLayer
// (the `uxc_consent` event that GTM tags without Consent Mode support use as trigger).
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const src = readFileSync( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'assets', 'consent', 'consent.js' ), 'utf8' );
let failures = 0;
function check( name, ok, detail = '' ) {
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => ' + detail ) );
}

async function run( cookie, cfg, fetchJson ) {
	const events = [];
	const doc = {
		cookie,
		getElementById: () => ( { textContent: JSON.stringify( { v: 1, days: 365, gcm: true, layout: 'box', pos: 'left', fab: false, c: [], t: {}, ...cfg } ) } ),
		querySelectorAll: () => [],
		addEventListener: () => {},
		dispatchEvent: ( e ) => events.push( e ),
	};
	const win = { location: { protocol: 'https:' }, setTimeout, sessionStorage: { getItem: () => null, setItem: () => {} } };
	if ( fetchJson ) { win.fetch = () => Promise.resolve( { ok: true, json: () => Promise.resolve( fetchJson ) } ); }
	const ctx = vm.createContext( { document: doc, window: win, CustomEvent: class { constructor( n, o ) { this.type = n; this.detail = o.detail; } }, JSON, Array, Math, Date, RegExp, decodeURIComponent } );
	win.document = doc;
	vm.runInContext( src.replace( /\bwin\.fetch\b/g, 'window.fetch' ), ctx );
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	return { layer: win.dataLayer || [], events, win };
}

const uxc = ( layer ) => layer.filter( ( x ) => x && x.event === 'uxc_consent' );

let r = await run( 'a=b; uxs_consent=1.1.6.1731000000' );
let e = uxc( r.layer );
check( 'stored choice (statistics + marketing): one uxc_consent push', e.length === 1 && e[ 0 ].uxc_statistics === true && e[ 0 ].uxc_marketing === true && e[ 0 ].uxc_preferences === false && e[ 0 ].uxc_source === 'stored', JSON.stringify( e ) );
check( 'stored choice: no extra gtag update (the inline defaults script already sent it)', r.layer.filter( ( x ) => x && x[ 0 ] === 'consent' ).length === 0 );
check( 'stored choice: window.uxstudioConsent filled', r.win.uxstudioConsent && r.win.uxstudioConsent.marketing === true );

r = await run( 'uxs_consent=1.1.0.1731000000' );
e = uxc( r.layer );
check( 'stored refusal: marketing false', e.length === 1 && e[ 0 ].uxc_marketing === false && e[ 0 ].uxc_statistics === false, JSON.stringify( e ) );

r = await run( '', { geo: 'https://x.test/region' }, { eu: false } );
e = uxc( r.layer );
check( 'outside the region: push with everything true and source region', e.some( ( x ) => x.uxc_marketing === true && x.uxc_source === 'region' ), JSON.stringify( e ) );
check( 'outside the region: Consent Mode update granted as well', r.layer.some( ( x ) => x && x[ 0 ] === 'consent' && x[ 1 ] === 'update' && x[ 2 ].ad_storage === 'granted' ) );
check( 'event carries stable key names', e.every( ( x ) => [ 'event', 'uxc_preferences', 'uxc_statistics', 'uxc_marketing', 'uxc_source' ].every( ( k ) => k in x ) ) );

// ---------- consent records (F5): what the banner reports after a decision ----------
import { webcrypto } from 'node:crypto';

/** A tiny fake DOM, just enough for the banner: elements with children, attributes, listeners and class/tag lookup. */
class FakeNode {
	constructor( tag ) { this.tag = tag.toLowerCase(); this.children = []; this.attrs = {}; this.listeners = {}; this.className = ''; this.hidden = false; this.checked = false; this.disabled = false; this.textContent = ''; this.parentNode = null; }
	appendChild( c ) { c.parentNode = this; this.children.push( c ); return c; }
	insertBefore( c, ref ) { c.parentNode = this; const i = ref ? this.children.indexOf( ref ) : -1; this.children.splice( i < 0 ? this.children.length : i, 0, c ); return c; }
	setAttribute( k, v ) { this.attrs[ k ] = v; }
	getAttribute( k ) { return k in this.attrs ? this.attrs[ k ] : null; }
	addEventListener( t, fn ) { ( this.listeners[ t ] = this.listeners[ t ] || [] ).push( fn ); }
	focus() {}
	get offsetParent() { return {}; }
	click() { ( this.listeners.click || [] ).forEach( ( fn ) => fn( { target: this, preventDefault() {} } ) ); }
	all() { return this.children.flatMap( ( c ) => [ c, ...c.all() ] ); }
	matches( part ) { return part.startsWith( '.' ) ? this.className.split( ' ' ).includes( part.slice( 1 ) ) : this.tag === part; }
	querySelectorAll( sel ) {
		const parts = sel.split( ',' )[ 0 ].trim().split( ' ' );
		let pool = [ this ];
		for ( const part of parts ) { pool = pool.flatMap( ( n ) => n.all().filter( ( x ) => x.matches( part ) ) ); }
		return pool;
	}
	querySelector( sel ) { return this.querySelectorAll( sel )[ 0 ] || null; }
}

async function runUi( cookie, cfg, { beacon = true, steps } = {} ) {
	const calls = { beacon: [], fetch: [] };
	const body = new FakeNode( 'body' );
	const doc = {
		cookie,
		body,
		getElementById: () => ( { textContent: JSON.stringify( { v: 3, days: 365, gcm: false, layout: 'box', pos: 'left', fab: true, c: [ { id: 'necessary', n: 'N', d: 'd' }, { id: 'preferences', n: 'P', d: 'd', s: [] }, { id: 'statistics', n: 'S', d: 'd', s: [] }, { id: 'marketing', n: 'M', d: 'd', s: [] } ], rec: 'https://x.test/wp-json/uxstudio/v1/consent/record', ...cfg } ) } ),
		querySelectorAll: () => [],
		addEventListener: () => {},
		dispatchEvent: () => {},
		createElement: ( t ) => new FakeNode( t ),
		createTextNode: ( t ) => ( { textContent: t } ),
	};
	const win = {
		location: { protocol: 'https:', pathname: '/kontakt/', search: '?utm=secret' },
		setTimeout: () => 0,
		sessionStorage: { getItem: () => null, setItem: () => {} },
		crypto: webcrypto,
		URLSearchParams,
		navigator: beacon ? { sendBeacon: ( url, b ) => { calls.beacon.push( { url, b: Object.fromEntries( b ) } ); return true; } } : {},
		fetch: ( url, o ) => { calls.fetch.push( { url, o, b: Object.fromEntries( o.body ) } ); return Promise.resolve( { ok: true } ); },
	};
	const ctx = vm.createContext( { document: doc, window: win, CustomEvent: class { constructor( n, o ) { this.type = n; this.detail = o.detail; } }, JSON, Array, Math, Date, RegExp, decodeURIComponent, Uint8Array, Object, URLSearchParams } );
	win.document = doc;
	vm.runInContext( src.replace( /\bwin\.fetch\b/g, 'window.fetch' ), ctx );
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	const button = ( label ) => body.querySelectorAll( 'button' ).find( ( b ) => b.textContent === label );
	if ( steps ) { steps( { body, button, win, doc } ); }
	await new Promise( ( r ) => setTimeout( r, 20 ) );
	return { calls, doc, win, body };
}

const ID = /^[a-f0-9]{32}$/;
const TX = { reject: 'Reject all', accept: 'Accept all', save: 'Save choices' };
const withText = { t: { cid: 'Consent ID:', reject: TX.reject, accept: TX.accept, save: TX.save, settings: 'Customize' } };

r = await runUi( '', withText, { steps: ( { button } ) => button( TX.accept ).click() } );
let b = r.calls.beacon;
check( 'records: accept all is sent once with sendBeacon', b.length === 1 && b[ 0 ].url.endsWith( '/consent/record' ), JSON.stringify( b ) );
check( 'records: payload is id, version, bits, action and the path only', b[ 0 ] && ID.test( b[ 0 ].b.id ) && b[ 0 ].b.v === '3' && b[ 0 ].b.c === '7' && b[ 0 ].b.a === 'accept_all' && b[ 0 ].b.p === '/kontakt/' && Object.keys( b[ 0 ].b ).length === 5, JSON.stringify( b[ 0 ] ) );
check( 'records: the query string never leaves the page', ! JSON.stringify( b ).includes( 'utm' ) && ! JSON.stringify( b ).includes( 'secret' ) );
check( 'records: the cookie carries the same id as 5th part', b[ 0 ] && r.doc.cookie.split( ';' )[ 0 ].split( '=' )[ 1 ].split( '.' )[ 4 ] === b[ 0 ].b.id, r.doc.cookie );
check( 'records: no fetch when sendBeacon accepted the request', r.calls.fetch.length === 0 );

r = await runUi( '', withText, { steps: ( { button } ) => button( TX.reject ).click() } );
b = r.calls.beacon;
check( 'records: reject all is sent with bits 0', b.length === 1 && b[ 0 ].b.a === 'reject_all' && b[ 0 ].b.c === '0', JSON.stringify( b ) );

r = await runUi( '', withText, {
	steps: ( { body, button } ) => {
		button( 'Customize' ).click();
		const boxes = body.querySelectorAll( 'input' ).filter( ( i ) => ! i.disabled );
		boxes[ 1 ].checked = true;
		button( TX.save ).click();
	},
} );
b = r.calls.beacon;
check( 'records: own choice carries the bits of the ticked categories', b.length === 1 && b[ 0 ].b.a === 'custom' && b[ 0 ].b.c === '2', JSON.stringify( b ) );

const stored = '0123456789abcdef0123456789abcdef';
r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, withText );
check( 'records: a stored choice is not reported on page load', r.calls.beacon.length === 0 && r.calls.fetch.length === 0 );

r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, withText, { steps: ( { win, button } ) => { win.uxConsent.open(); button( TX.reject ).click(); } } );
b = r.calls.beacon;
check( 'records: withdrawing a granted category is reported as withdraw with the same id', b.length === 1 && b[ 0 ].b.a === 'withdraw' && b[ 0 ].b.id === stored && b[ 0 ].b.c === '0', JSON.stringify( b ) );

r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, withText, { steps: ( { win } ) => win.uxConsent.open() } );
const idLine = r.body.all().find( ( n ) => n.textContent === 'Consent ID: ' + stored );
check( 'records: the consent id is shown in the cookie settings', !! idLine && idLine.hidden === false );

r = await runUi( 'uxs_consent=1.3.2.1731000000', withText, { steps: ( { win, button } ) => { win.uxConsent.open(); button( TX.accept ).click(); } } );
b = r.calls.beacon;
check( 'records: a cookie from before F5 gets a new id on the next decision', b.length === 1 && ID.test( b[ 0 ].b.id ) && b[ 0 ].b.a === 'accept_all', JSON.stringify( b ) );

r = await runUi( '', withText, { beacon: false, steps: ( { button } ) => button( TX.accept ).click() } );
check( 'records: fetch with keepalive is the fallback', r.calls.fetch.length === 1 && r.calls.fetch[ 0 ].o.keepalive === true && r.calls.fetch[ 0 ].o.credentials === 'omit' && r.calls.fetch[ 0 ].b.a === 'accept_all', JSON.stringify( r.calls.fetch ) );

r = await runUi( '', { ...withText, rec: undefined }, { steps: ( { button } ) => button( TX.accept ).click() } );
check( 'records: nothing is sent when records are off, the choice still works', r.calls.beacon.length === 0 && r.calls.fetch.length === 0 && /uxs_consent=1\.3\.7\./.test( r.doc.cookie ) );

r = await runUi( '', withText, { steps: ( { win, button } ) => { win.navigator.sendBeacon = () => { throw new Error( 'x' ); }; button( TX.accept ).click(); } } );
check( 'records: a throwing sendBeacon does not stop the choice', /uxs_consent=1\.3\.7\./.test( r.doc.cookie ) );

console.log( failures === 0 ? 'ALL OK' : failures + ' FAILED' );
process.exit( failures === 0 ? 0 : 1 );
