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

async function runUi( cookie, cfg, { beacon = true, steps, sandbox = false } = {} ) {
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
		createTextNode: ( t ) => Object.assign( new FakeNode( '#text' ), { textContent: t } ),
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
	if ( sandbox ) {
		// A sandboxed iframe without allow-same-origin: touching cookies or storage throws.
		const deny = () => { throw new Error( 'SecurityError' ); };
		Object.defineProperty( doc, 'cookie', { get: deny, set: deny } );
		win.sessionStorage = { getItem: deny, setItem: deny };
		win.location = { get protocol() { return deny(); }, get pathname() { return deny(); } };
	}
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

r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, { ...withText, rv: 'main' }, { steps: ( { win } ) => win.uxConsent.open() } );
check( 'reopen view main: reopening shows the first screen, not the category settings', ! / is-detail/.test( r.body.querySelectorAll( '.uxc' )[ 0 ].className ) );
r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, withText, { steps: ( { win } ) => win.uxConsent.open() } );
check( 'reopen view default: reopening shows the category settings', / is-detail/.test( r.body.querySelectorAll( '.uxc' )[ 0 ].className ) );
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


// ---------- public API for automation (F6): same behaviour as the buttons ----------
r = await runUi( '', withText, { steps: ( { win } ) => { win.apiResult = win.uxConsent.acceptAll(); } } );
b = r.calls.beacon;
check( 'api: acceptAll records an accept_all decision with all bits', b.length === 1 && b[ 0 ].b.a === 'accept_all' && b[ 0 ].b.c === '7', JSON.stringify( b ) );
check( 'api: acceptAll writes the cookie and returns the new state', /uxs_consent=1\.3\.7\./.test( r.doc.cookie ) && r.win.apiResult.marketing === true && r.win.apiResult.necessary === true );
check( 'api: acceptAll pushes uxc_consent with source user', uxc( r.win.dataLayer || [] ).some( ( x ) => x.uxc_source === 'user' && x.uxc_marketing === true ) );

r = await runUi( '', withText, { steps: ( { win } ) => win.uxConsent.rejectAll() } );
b = r.calls.beacon;
check( 'api: rejectAll records reject_all with bits 0', b.length === 1 && b[ 0 ].b.a === 'reject_all' && b[ 0 ].b.c === '0' && /uxs_consent=1\.3\.0\./.test( r.doc.cookie ), JSON.stringify( b ) );

r = await runUi( '', withText, { steps: ( { win } ) => win.uxConsent.set( { statistics: true, marketing: 0, junk: true } ) } );
b = r.calls.beacon;
check( 'api: set records a custom decision with only the named categories', b.length === 1 && b[ 0 ].b.a === 'custom' && b[ 0 ].b.c === '2' && /uxs_consent=1\.3\.2\./.test( r.doc.cookie ), JSON.stringify( b ) );

r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, withText, { steps: ( { win } ) => win.uxConsent.rejectAll() } );
b = r.calls.beacon;
check( 'api: rejectAll after accepting is a withdraw with the same id', b.length === 1 && b[ 0 ].b.a === 'withdraw' && b[ 0 ].b.id === stored, JSON.stringify( b ) );

// ---------- choices made in the old banner (Complianz cookies) ----------
const leg = { leg: { p: 'cmplz_', v: 7 }, ...withText };
const old = ( extra = '' ) => 'cmplz_banner-status=dismissed; cmplz_policy_id=7; cmplz_functional=allow; cmplz_preferences=allow; cmplz_statistics=allow; cmplz_marketing=deny' + extra;
r = await runUi( old(), leg );
e = uxc( r.win.dataLayer || [] );
check( 'legacy: an old Complianz choice becomes our cookie (preferences + statistics)', /uxs_consent=1\.3\.3\.\d+\.[a-f0-9]{32}/.test( r.doc.cookie ), r.doc.cookie );
check( 'legacy: the banner is not shown and the event has source import', e.length === 1 && e[ 0 ].uxc_source === 'import' && e[ 0 ].uxc_statistics === true && e[ 0 ].uxc_marketing === false && r.body.querySelectorAll( 'button' ).filter( ( x ) => x.textContent === TX.accept ).length === 0, JSON.stringify( e ) );
check( 'legacy: taking over is not reported as a visitor decision', r.calls.beacon.length === 0 && r.calls.fetch.length === 0 );

r = await runUi( old().replace( 'policy_id=7', 'policy_id=6' ), leg );
check( 'legacy: another policy number is ignored, the banner asks', ! /uxs_consent=/.test( r.doc.cookie ) && r.body.querySelectorAll( 'button' ).some( ( x ) => x.textContent === TX.accept ) );

r = await runUi( 'cmplz_policy_id=7; cmplz_marketing=allow', leg );
check( 'legacy: a banner that was never dismissed is ignored', ! /uxs_consent=/.test( r.doc.cookie ) );

r = await runUi( 'cmplz_banner-status=dismissed; cmplz_policy_id=7', leg );
check( 'legacy: no category cookie means no decision to take over', ! /uxs_consent=/.test( r.doc.cookie ) );

r = await runUi( old( '; uxs_consent=1.3.4.1731000000' ), leg );
check( 'legacy: an existing choice of ours wins over the old cookies', /uxs_consent=1\.3\.4\.1731000000/.test( r.doc.cookie ) && uxc( r.win.dataLayer || [] )[ 0 ].uxc_source === 'stored' );

r = await runUi( old(), withText );
check( 'legacy: without the setting the old cookies are not read', ! /uxs_consent=/.test( r.doc.cookie ) );

r = await runUi( old(), { ...withText, leg: { p: 'x;y', v: 7 } } );
check( 'legacy: a prefix with odd characters is refused', ! /uxs_consent=/.test( r.doc.cookie ) );

// ---------- preview in the admin (PLAN 42.18): sandboxed iframe, nothing stored, reported or reloaded ----------
const pv = { ...withText, preview: 'main', geo: 'https://x.test/region', leg: { p: 'cmplz_', v: 7 }, t: { ...withText.t, ph: 'From %s', load: 'Load' } };
let shown = ( x ) => x.body.querySelectorAll( '.uxc' ).filter( ( n ) => ! n.hidden ).length;
r = await runUi( '', pv, { sandbox: true, steps: ( { button } ) => button( TX.accept ).click() } );
check( 'preview: the banner is shown although cookies and storage throw', shown( r ) === 1 );
check( 'preview: Accept does not hide the banner, record, set a cookie or push to the dataLayer', shown( r ) === 1 && r.calls.beacon.length === 0 && r.calls.fetch.length === 0 && ! ( r.win.dataLayer || [] ).length );
r = await runUi( '', pv, { sandbox: true, steps: ( { win } ) => { win.uxConsent.rejectAll(); win.uxConsent.set( { marketing: 1 } ); } } );
check( 'preview: the automation API does not store or report either', shown( r ) === 1 && r.calls.beacon.length === 0 && r.calls.fetch.length === 0 );
r = await runUi( '', { ...pv, preview: 'detail' }, { sandbox: true } );
check( 'preview: detail view opens the second layer', shown( r ) === 1 && / is-detail/.test( r.body.querySelectorAll( '.uxc' )[ 0 ].className ) );
r = await runUi( '', { ...pv, preview: 'fab' }, { sandbox: true } );
check( 'preview: floating button view shows the button and no banner', r.body.querySelectorAll( '.uxc-fab' ).length === 1 && r.body.querySelectorAll( '.uxc' ).length === 0 );
r = await runUi( '', { ...pv, preview: 'fab', fab: false }, { sandbox: true } );
check( 'preview: the button stays hidden when the setting is off', r.body.querySelectorAll( '.uxc-fab' ).length === 0 );
r = await runUi( '', { ...pv, preview: 'ph' }, { sandbox: true } );
check( 'preview: placeholder view builds no banner', r.body.querySelectorAll( '.uxc' ).length === 0 && r.body.querySelectorAll( '.uxc-fab' ).length === 0 );

// ---------- banner templates (PLAN 42.20): same buttons, same functions, only the structure differs ----------
for ( const tpl of [ 'classic', 'cookiebot', 'cookie', 'slim', 'nonsense' ] ) {
	const tcfg = { ...withText, tpl, t: { ...withText.t, tab_c: 'Consent', tab_d: 'Details', tab_a: 'About cookies', tabs: 'Sections', about: 'About text' } };
	const cls = ( x ) => x.body.querySelectorAll( '.uxc' )[ 0 ].className;
	const name = tpl === 'nonsense' ? 'unknown template (falls back to classic)' : tpl;
	const root = tpl === 'nonsense' ? 'classic' : tpl;
	r = await runUi( '', tcfg );
	check( 'tpl ' + name + ': root carries the template class', cls( r ).split( ' ' ).includes( 'uxc-t-' + root ), cls( r ) );
	const dlgButtons = r.body.querySelectorAll( '.uxc-dlg button' ).filter( ( x ) => x.className.includes( 'uxc-btn' ) );
	const rej = dlgButtons.find( ( x ) => x.textContent === TX.reject );
	const acc = dlgButtons.find( ( x ) => x.textContent === TX.accept );
	check( 'tpl ' + name + ': reject and accept are in the first layer with the same weight', !! rej && !! acc && ! rej.className.includes( 'uxc-alt' ) && ! acc.className.includes( 'uxc-alt' ), dlgButtons.map( ( x ) => x.className ).join( '|' ) );
	if ( root !== 'cookiebot' ) {
		check( 'tpl ' + name + ': there is a customize button that is not the quiet-less accept', dlgButtons.some( ( x ) => x.textContent === 'Customize' ) );
	} else {
		const tabs = r.body.querySelectorAll( 'button' ).filter( ( x ) => x.attrs.role === 'tab' );
		check( 'tpl cookiebot: three tabs, the first selected', tabs.length === 3 && tabs[ 0 ].attrs[ 'aria-selected' ] === true && tabs[ 1 ].attrs[ 'aria-selected' ] === false, JSON.stringify( tabs.map( ( x ) => x.attrs[ 'aria-selected' ] ) ) );
		check( 'tpl cookiebot: switches of the three categories are on the first layer (necessary locked)', r.body.querySelectorAll( '.uxc-swr input' ).length === 4 && r.body.querySelectorAll( '.uxc-swr input' ).filter( ( x ) => x.disabled ).length === 1 );
		tabs[ 1 ].click();
		check( 'tpl cookiebot: the Details tab shows the details panel only', r.body.querySelectorAll( '.uxc-detail' )[ 0 ].hidden === false && r.body.querySelectorAll( '.uxc-main' )[ 0 ].hidden === true && r.body.querySelectorAll( '.uxc-about' )[ 0 ].hidden === true );
	}
	r = await runUi( '', tcfg, { steps: ( { button } ) => button( TX.accept ).click() } );
	b = r.calls.beacon;
	check( 'tpl ' + name + ': accept all records accept_all with all bits and writes the cookie', b.length === 1 && b[ 0 ].b.a === 'accept_all' && b[ 0 ].b.c === '7' && /uxs_consent=1\.3\.7\./.test( r.doc.cookie ), JSON.stringify( b ) );
	r = await runUi( '', tcfg, { steps: ( { button } ) => button( TX.reject ).click() } );
	b = r.calls.beacon;
	check( 'tpl ' + name + ': reject all records reject_all with bits 0', b.length === 1 && b[ 0 ].b.a === 'reject_all' && b[ 0 ].b.c === '0' && /uxs_consent=1\.3\.0\./.test( r.doc.cookie ), JSON.stringify( b ) );
	r = await runUi( '', tcfg, {
		steps: ( { body, button } ) => {
			if ( root !== 'cookiebot' ) { button( 'Customize' ).click(); }
			body.querySelectorAll( 'input' ).filter( ( i ) => ! i.disabled )[ 1 ].checked = true;
			button( TX.save ).click();
		},
	} );
	b = r.calls.beacon;
	check( 'tpl ' + name + ': a selection records custom with the bits of the ticked switches', b.length === 1 && b[ 0 ].b.a === 'custom' && b[ 0 ].b.c === '2', JSON.stringify( b ) );
	r = await runUi( 'uxs_consent=1.3.7.1731000000.' + stored, tcfg, { steps: ( { win, button } ) => { win.uxConsent.open(); button( TX.reject ).click(); } } );
	b = r.calls.beacon;
	check( 'tpl ' + name + ': reopening and rejecting is a withdraw', b.length === 1 && b[ 0 ].b.a === 'withdraw' && b[ 0 ].b.id === stored, JSON.stringify( b ) );
	r = await runUi( '', { ...tcfg, preview: 'detail' }, { sandbox: true } );
	check( 'tpl ' + name + ': preview detail view works and nothing is stored', r.body.querySelectorAll( '.uxc-detail' )[ 0 ].hidden === false && r.calls.beacon.length === 0 );
}

// ---------- options of one template (PLAN 42.22): cfg.o ----------
{
	const base = { ...withText, t: { ...withText.t, tab_c: 'Consent', tab_d: 'Details', tab_a: 'About cookies', tabs: 'Sections', about: 'About text' } };
	const classes = ( x ) => x.body.querySelectorAll( '.uxc' )[ 0 ].className.split( ' ' );
	const tabsOf = ( x ) => x.body.querySelectorAll( 'button' ).filter( ( b ) => b.attrs.role === 'tab' );
	r = await runUi( '', { ...base, tpl: 'cookiebot', o: { place: 'full', about: false } } );
	check( 'opt cookiebot: full width adds the class and the About tab and panel are gone', classes( r ).includes( 'uxc-o-full' ) && tabsOf( r ).length === 2 && r.body.querySelectorAll( '.uxc-about' ).length === 0, classes( r ).join( ' ' ) + ' ' + tabsOf( r ).length );
	r = await runUi( '', { ...base, tpl: 'cookiebot', o: { place: 'center', about: true } } );
	check( 'opt cookiebot: centre with three tabs has no extra class', ! classes( r ).includes( 'uxc-o-full' ) && tabsOf( r ).length === 3 );
	r = await runUi( '', { ...base, tpl: 'cookiebot' } );
	check( 'opt cookiebot: without cfg.o (older config) it is the same as before', tabsOf( r ).length === 3 && ! classes( r ).includes( 'uxc-o-full' ) );
	r = await runUi( '', { ...base, tpl: 'cookiebot', o: { about: false } } );
	const t2 = tabsOf( r );
	check( 'opt cookiebot: with two tabs the first is selected and the Details tab opens its panel', t2.length === 2 && t2[ 0 ].attrs[ 'aria-selected' ] === true && r.body.querySelectorAll( '.uxc-detail' )[ 0 ].hidden === true );
	r = await runUi( '', { ...base, tpl: 'cookie', o: { icon: false, shape: 'rect' } } );
	check( 'opt cookie: no icon and rectangular buttons', r.body.querySelectorAll( '.uxc-ic' ).length === 0 && r.body.querySelectorAll( '.uxc-hd' ).length === 0 && classes( r ).includes( 'uxc-o-rect' ) && r.body.querySelectorAll( '.uxc-h' ).length >= 1 );
	r = await runUi( '', { ...base, tpl: 'cookie', o: { icon: true, shape: 'round' } } );
	check( 'opt cookie: icon and round buttons are the default look', r.body.querySelectorAll( '.uxc-ic' ).length === 1 && ! classes( r ).includes( 'uxc-o-rect' ) );
	r = await runUi( '', { ...base, tpl: 'cookie', o: { icon: false, shape: 'rect' } } );
	const rb = r.body.querySelectorAll( '.uxc-dlg button' ).filter( ( x ) => x.className.includes( 'uxc-btn' ) );
	check( 'opt cookie rect: reject and accept are both solid (no quiet class)', ! rb.find( ( x ) => x.textContent === TX.reject ).className.includes( 'uxc-alt' ) && ! rb.find( ( x ) => x.textContent === TX.accept ).className.includes( 'uxc-alt' ) );
	const withPolicy = { ...base, policy: '/privacy/' };
	r = await runUi( '', { ...withPolicy, tpl: 'slim', o: { place: 'top', link: false } } );
	check( 'opt slim: top adds the class and the policy link is left out', classes( r ).includes( 'uxc-o-top' ) && r.body.querySelectorAll( '.uxc-a' ).length === 0 );
	r = await runUi( '', { ...withPolicy, tpl: 'slim', o: { place: 'bottom', link: true } } );
	check( 'opt slim: bottom with the link is the default look', ! classes( r ).includes( 'uxc-o-top' ) && r.body.querySelectorAll( '.uxc-a' ).length === 1 );
	r = await runUi( '', { ...withPolicy, tpl: 'classic', o: { place: 'top', link: false, icon: false, shape: 'rect', about: false } } );
	check( 'opt: another template ignores the options of a different one', ! classes( r ).some( ( c ) => c.startsWith( 'uxc-o-' ) ) && r.body.querySelectorAll( '.uxc-a' ).length === 1 );
	r = await runUi( '', { ...withPolicy, tpl: 'cookie', o: { place: 'top', link: false } } );
	check( 'opt cookie: keeps its policy link whatever the slim option says', r.body.querySelectorAll( '.uxc-a' ).length === 1 );
}

// UX1 signature link (PLAN 42.23): in every template, once, a link to a new window with an accessible name; none without cfg.by.
{
	const byBase = { t: { by: 'Cookie banner by UX1 (opens in a new window)' } };
	for ( const tpl of [ 'classic', 'cookiebot', 'cookie', 'slim' ] ) {
		r = await runUi( '', { ...byBase, tpl, by: 'https://ux1.cz/' } );
		const links = r.body.querySelectorAll( '.uxc-by' );
		const a = links[ 0 ];
		check( `by ${ tpl }: one link to https://ux1.cz/ in a new window with noopener`, links.length === 1 && a.href === 'https://ux1.cz/' && a.target === '_blank' && a.rel === 'noopener', JSON.stringify( links.length ) );
		check( `by ${ tpl }: aria-label and title are the translated text`, a.attrs[ 'aria-label' ] === byBase.t.by && a.title === byBase.t.by );
		check( `by ${ tpl }: no markup or text inside, the word comes from CSS (no svg, no external request)`, ! a.innerHTML && ! ( a.children && a.children.length ) );
		r = await runUi( '', { ...byBase, tpl } );
		check( `by ${ tpl }: no link when the server sends no "by" (filter off)`, r.body.querySelectorAll( '.uxc-by' ).length === 0 );
		r = await runUi( '', { ...byBase, tpl, by: 'javascript:alert(1)' } );
		check( `by ${ tpl }: only an https address is accepted`, r.body.querySelectorAll( '.uxc-by' ).length === 0 );
	}
	r = await runUi( '', { ...byBase, tpl: 'cookiebot', by: 'https://ux1.cz/' } );
	const actionsBox = r.body.querySelectorAll( '.uxc-actions' )[ 0 ];
	check( 'by cookiebot: the link is the last item of the button row (after the buttons in the tab order)', actionsBox.children[ actionsBox.children.length - 1 ].className === 'uxc-by' );
	r = await runUi( '', { ...byBase, tpl: 'classic', by: 'https://ux1.cz/' } );
	const dlgBox = r.body.querySelectorAll( '.uxc-dlg' )[ 0 ];
	check( 'by classic: the link is the last child of the dialog (after the buttons in the tab order)', dlgBox.children[ dlgBox.children.length - 1 ].className === 'uxc-by' );
}

console.log( failures === 0 ? 'ALL OK' : failures + ' FAILED' );
process.exit( failures === 0 ? 0 : 1 );
