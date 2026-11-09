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

console.log( failures === 0 ? 'ALL OK' : failures + ' FAILED' );
process.exit( failures === 0 ? 0 : 1 );
