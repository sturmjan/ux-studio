/**
 * Browser QA of the live banner preview in the consent module admin (PLAN.md 42.18). Local test site only.
 *
 *   BASE=http://localhost/hydro-izol UXS_ADMIN_USER=... UXS_ADMIN_PASSWORD=... node tests/e2e/consent-preview.mjs
 *
 * Checks that the preview follows UNSAVED values, that its views and widths work, and that the sandbox holds: no cookie
 * in the admin domain, no request to the consent endpoints, the saved settings stay as they were. The password comes
 * from the environment only (UXS_ADMIN_PASSWORD).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = ( process.env.BASE || 'http://localhost/hydro-izol' ).replace( /\/$/, '' );
const USER = process.env.UXS_ADMIN_USER || 'admin';
const PASS = process.env.UXS_ADMIN_PASSWORD;
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const TAG = process.env.TAG || 'preview';
if ( ! PASS ) {
	console.error( 'Set UXS_ADMIN_PASSWORD' );
	process.exit( 2 );
}
mkdirSync( SHOTS, { recursive: true } );

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};

const browser = await chromium.launch();
const context = await browser.newContext( { viewport: { width: 1600, height: 1000 } } );
const page = await context.newPage();
const errors = [];
const forbidden = [];
page.on( 'console', ( m ) => m.type() === 'error' && errors.push( m.text() ) );
page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
page.on( 'request', ( r ) => {
	if ( /consent\/(record|region|cookies)/.test( r.url() ) ) {
		forbidden.push( r.url() );
	}
} );
const previewCalls = [];
page.on( 'request', ( r ) => /consent\/preview-config/.test( r.url() ) && previewCalls.push( r.method() ) );

await page.goto( BASE + ( process.env.LOGIN_PATH || '/wp-login.php' ) );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
check( 'logged in', ! /wp-login/.test( page.url() ) );

const stored = () =>
	page.evaluate( async () => {
		const b = window.uxStudioBoot;
		const r = await fetch( `${ b.restUrl }/settings/consent`, { headers: { 'X-WP-Nonce': b.nonce } } );
		return JSON.stringify( ( await r.json() ).data.values );
	} );

await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle', timeout: 90000 } );
await page.waitForSelector( '.uxs-uxcp iframe', { timeout: 20000 } );
const before = await stored();
const frameOf = async () => {
	const handle = await page.waitForSelector( '.uxs-uxcp iframe' );
	return handle.contentFrame();
};
let frame = await frameOf();
await frame.waitForSelector( '.uxc:not([hidden]) .uxc-dlg', { timeout: 10000 } );
check( 'preview shows the first layer of the real banner', ( await frame.locator( '.uxc-main' ).isVisible() ) && ( await frame.locator( '.uxc-main .uxc-actions button' ).count() ) === 3 );
check( 'iframe is sandboxed with allow-scripts only', ( await page.getAttribute( '.uxs-uxcp iframe', 'sandbox' ) ) === 'allow-scripts' );
check( 'the real script and style are used', ( await frame.evaluate( () => [ ...document.scripts ].map( ( s ) => s.src ).concat( [ ...document.styleSheets ].map( ( s ) => s.href ) ).join( ' ' ) ) ).match( /consent(\.min)?\.js.*consent(\.min)?\.css/ ) !== null );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-display.png` } );

// ---------- unsaved texts follow ----------
const tab = async ( names ) => {
	await page.evaluate( ( list ) => {
		const b = [ ...document.querySelectorAll( 'button' ) ].find( ( x ) => list.includes( x.textContent.trim() ) );
		b.click();
	}, names );
	await page.waitForTimeout( 400 );
};
await tab( [ 'Texts', 'Texty' ] );
const title = page.locator( '.uxs-topic-settings__form input[type=text]' ).first();
const original = await title.inputValue();
await title.fill( 'Preview title ' + Date.now() );
const typed = await title.inputValue();
await page.waitForTimeout( 1800 );
frame = await frameOf();
await frame.waitForSelector( '.uxc-h' );
check( 'an unsaved title shows in the preview', ( await frame.locator( '#uxc-t' ).innerText() ) === typed, await frame.locator( '#uxc-t' ).innerText() );
const accept = page.locator( '.uxs-topic-settings__form input[type=text]' ).nth( 3 );
await accept.fill( 'Souhlasim se vsim' );
await page.waitForTimeout( 1800 );
frame = await frameOf();
check( 'an unsaved button text shows in the preview', ( await frame.locator( '.uxc-actions button', { hasText: 'Souhlasim se vsim' } ).count() ) >= 1 );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-texts.png` } );

// ---------- appearance ----------
await tab( [ 'Appearance', 'Vzhled' ] );
await page.waitForTimeout( 600 );
const select = page.locator( '.uxs-topic-settings__form select' ).first();
await select.selectOption( 'bar' );
await page.waitForTimeout( 1800 );
frame = await frameOf();
check( 'bar layout in the preview', ( await frame.locator( '.uxc.uxc-bar' ).count() ) === 1 );
await select.selectOption( 'box' );
const side = page.locator( '.uxs-topic-settings__form select' ).nth( 1 );
await side.selectOption( 'right' );
const radius = page.locator( '.uxs-topic-settings__form input[type=number]' ).first();
await radius.fill( '22' );
const colours = page.locator( '.uxs-topic-settings__form input[type=color]' );
await colours.nth( 0 ).fill( '#fff3cd' );
await colours.nth( 2 ).fill( '#b91c1c' );
await page.waitForTimeout( 2000 );
frame = await frameOf();
const css = await frame.evaluate( () => {
	const d = document.querySelector( '.uxc-dlg' );
	const b = document.querySelector( '.uxc-actions .uxc-btn' );
	const s = getComputedStyle( d );
	return { r: s.borderTopLeftRadius, bg: s.backgroundColor, btn: getComputedStyle( b ).backgroundColor, right: document.querySelector( '.uxc' ).className };
} );
check( 'unsaved radius applies', css.r === '22px', css.r );
check( 'unsaved background colour applies', css.bg === 'rgb(255, 243, 205)', css.bg );
check( 'unsaved button colour applies', css.btn === 'rgb(185, 28, 28)', css.btn );
check( 'unsaved side applies', /uxc-right/.test( css.right ), css.right );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-appearance.png` } );

// ---------- views and widths ----------
const view = ( re ) => page.locator( '.uxs-uxcp__group button' ).filter( { hasText: re } ).first();
await view( /Category settings|Nastavení kategorií/ ).click();
await page.waitForTimeout( 1800 );
frame = await frameOf();
await frame.waitForSelector( '.uxc.is-detail' );
check( 'detail view shows the category switches', ( await frame.locator( '.uxc-sw' ).count() ) === 4 );
check( 'opening the detail did not steal focus from the admin form', await page.evaluate( () => document.activeElement?.tagName !== 'IFRAME' ) );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-detail.png` } );
await view( /Floating button|Plovoucí tlačítko/ ).click();
await page.waitForTimeout( 1800 );
frame = await frameOf();
const fabOn = ( await frame.locator( '.uxc-fab' ).count() ) === 1;
check( 'floating button view (button, or the notice that it is switched off)', fabOn || ( await page.locator( '.uxs-uxcp [role=status]' ).count() ) === 1 );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-fab.png` } );
await view( /Embedded video|Vložené video/ ).click();
await page.waitForTimeout( 1800 );
frame = await frameOf();
await frame.waitForSelector( '.uxc-ph' );
check( 'embedded video view shows the placeholder', ( await frame.locator( '.uxc-ph .uxc-btn' ).count() ) === 1 );
await frame.locator( '.uxc-ph .uxc-btn' ).click();
check( 'the placeholder button does not load or remove anything', ( await frame.locator( '.uxc-ph' ).count() ) === 1 );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-video.png` } );
await view( /First layer|První vrstva/ ).click();
await page.locator( '.uxs-uxcp__group button' ).filter( { hasText: /Mobile|Mobil/ } ).click();
await page.waitForTimeout( 1800 );
frame = await frameOf();
await frame.waitForSelector( '.uxc-dlg' );
check( 'mobile width is 390 px wide inside', ( await frame.evaluate( () => window.innerWidth ) ) === 390 );
await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-mobile.png` } );

// ---------- isolation ----------
const probe = await frame.evaluate( () => {
	const out = {};
	try {
		out.cookie = document.cookie;
	} catch ( e ) {
		out.cookie = 'throws ' + e.name;
	}
	try {
		out.storage = String( window.sessionStorage.length );
	} catch ( e ) {
		out.storage = 'throws ' + e.name;
	}
	try {
		out.parent = String( window.parent.document.title );
	} catch ( e ) {
		out.parent = 'throws ' + e.name;
	}
	out.origin = window.origin;
	return out;
} );
check( 'in the frame cookies, storage and the parent document are out of reach', /throws/.test( probe.cookie ) && /throws/.test( probe.storage ) && /throws/.test( probe.parent ), JSON.stringify( probe ) );
check( 'the frame has an opaque origin', probe.origin === 'null', probe.origin );
await frame.locator( '.uxc-main .uxc-actions button' ).first().click();
await frame.locator( '.uxc-main .uxc-actions button' ).nth( 1 ).click();
await page.waitForTimeout( 800 );
check( 'buttons do nothing: the banner stays', ( await frame.locator( '.uxc:not([hidden])' ).count() ) === 1 );
const jar = await context.cookies();
check( 'no uxs_consent cookie in the admin domain', ! jar.some( ( c ) => c.name === 'uxs_consent' ), JSON.stringify( jar.map( ( c ) => c.name ) ) );
check( 'no request to record, region or cookies', forbidden.length === 0, forbidden.join( ' ' ) );
check( 'the preview endpoint was called with POST only', previewCalls.length > 3 && previewCalls.every( ( m ) => m === 'POST' ), previewCalls.join( ',' ) );

// ---------- nothing was saved ----------
check( 'the saved settings are unchanged', ( await stored() ) === before );
check( 'no console errors', errors.filter( ( e ) => ! /favicon/.test( e ) ).length === 0, errors.join( ' // ' ) );

await browser.close();
console.log( fails === 0 ? 'ALL OK' : fails + ' FAILED' );
process.exit( fails === 0 ? 0 : 1 );
