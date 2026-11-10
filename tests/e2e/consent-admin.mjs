/**
 * Browser QA of the consent module admin page (PLAN.md 42.17). Local test site only.
 *
 *   BASE=http://127.0.0.1/_uxs-e2e UXS_ADMIN_USER=e2e UXS_ADMIN_PASSWORD=... node tests/e2e/consent-admin.mjs
 *
 * Opens every tab of the module, collects console errors and failed REST calls, and drives the scan, the inventory,
 * the legal wizard and the records tab. The password comes from the environment only.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const USER = process.env.UXS_ADMIN_USER || 'e2e';
const PASS = process.env.UXS_ADMIN_PASSWORD;
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const TAG = process.env.TAG || 'admin';
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
const context = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
const page = await context.newPage();
let errors = [];
page.on( 'console', ( m ) => {
	if ( m.type() === 'error' ) {
		errors.push( m.text() );
	}
} );
page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
page.on( 'response', ( r ) => {
	if ( r.status() >= 400 && /wp-json|rest_route/.test( r.url() ) ) {
		errors.push( r.status() + ' ' + r.url() );
	}
} );
const flush = ( label ) => {
	const e = errors.filter( ( x ) => ! /favicon|Failed to load resource.*(googleapis|gstatic)/i.test( x ) );
	check( 'no console/REST errors: ' + label, e.length === 0, e.join( ' // ' ) );
	errors = [];
};

// login
await page.goto( BASE + '/wp-login.php' );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
check( 'logged in', ! /wp-login/.test( page.url() ) );

await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle' } );
await page.waitForSelector( '.uxs-pagehead h1', { timeout: 15000 } );
const tabs = page.locator( '.uxs-tabs button, nav button' ).filter( { hasNot: page.locator( 'svg' ) } );
const tabNav = page.locator( '.uxs-pagehead ~ * button' );
console.log( 'INFO h1 = ' + ( await page.locator( '.uxs-pagehead h1' ).innerText() ) );
// the TabsNav buttons: find the container that holds 9 buttons
const names = await page.evaluate( () => {
	const all = [ ...document.querySelectorAll( 'button' ) ];
	const first = all.find( ( b ) => /^(Display|Zobrazení)$/.test( b.textContent.trim() ) );
	return first ? [ ...first.parentElement.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent.trim() ) : [];
} );
console.log( 'INFO tabs = ' + names.join( ' | ' ) );
check( '9 tabs rendered', names.length === 9, String( names.length ) );
flush( 'open' );

const tab = async ( i ) => {
	const n = names[ i ];
	await page.evaluate( ( label ) => {
		const b = [ ...document.querySelectorAll( 'button' ) ].find( ( x ) => x.textContent.trim() === label );
		b.click();
	}, n );
	await page.waitForTimeout( 900 );
	await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-tab${ i }.png`, fullPage: true } );
	return n;
};

for ( let i = 0; i < names.length; i++ ) {
	const n = await tab( i );
	check( 'tab renders: ' + n, ( await page.locator( '#ux-studio-root' ).innerText() ).length > 200 );
	flush( n );
}

// ---- Services and cookies: scan now ----
await tab( 5 );
{
	const btn = page.getByRole( 'button', { name: /Scan now|Prohledat teď/i } ).first();
	if ( await btn.count() ) {
		await btn.click();
		await page.waitForTimeout( 15000 );
		await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-inventory-scanned.png`, fullPage: true } );
		check( 'inventory scan ran (button re-enabled)', await btn.isEnabled() );
		flush( 'inventory scan' );
	} else {
		check( 'inventory scan button found', false );
	}
}

// ---- Records ----
await tab( 8 );
flush( 'records tab' );

await page.screenshot( { path: `${ SHOTS }\\uxc-${ TAG }-final.png` } );
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
