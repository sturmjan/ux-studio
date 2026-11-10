/**
 * Browser QA (PLAN.md 42.17): the inventory queue. A page of the test site loads a script from an unknown host
 * (cdn.unknownvendor-qa.example); the scan queues it, the admin classifies it as marketing, and the frontend then holds
 * the script back until marketing consent. Local test site only.
 */
import { chromium } from 'playwright';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const PASS = process.env.UXS_ADMIN_PASSWORD;
const USER = process.env.UXS_ADMIN_USER || 'e2e';
const SHOTS = process.env.SHOTS || 'C:/Users/sturm/.claude/screenshots';
if ( ! PASS ) {
	console.error( 'Set UXS_ADMIN_PASSWORD' );
	process.exit( 2 );
}
let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};
const HOST = 'cdn.unknownvendor-qa.example';
const browser = await chromium.launch();
const c = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
const p = await c.newPage();
const errors = [];
p.on( 'pageerror', ( e ) => errors.push( e.message ) );
p.on( 'response', ( r ) => {
	if ( r.status() >= 400 && /wp-json|rest_route/.test( r.url() ) ) {
		errors.push( r.status() + ' ' + r.url() );
	}
} );
await p.goto( BASE + '/wp-login.php' );
await p.fill( '#user_login', USER );
await p.fill( '#user_pass', PASS );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
await p.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle' } );
await p.waitForSelector( '.uxs-pagehead h1' );
await p.evaluate( () => [ ...document.querySelectorAll( 'button' ) ].find( ( x ) => /^(Services and cookies|Služby a cookies)$/.test( x.textContent.trim() ) ).click() );
await p.waitForTimeout( 800 );
await p.getByRole( 'button', { name: /Scan now|Prohledat teď/ } ).first().click();
await p.waitForSelector( `text=${ HOST }`, { timeout: 60000 } ).catch( () => {} );
check( 'unknown host queued after the scan', await p.getByText( HOST ).count() > 0 );
await p.screenshot( { path: SHOTS + '/uxc-inventory-queue.png', fullPage: true } );
const row = p.locator( 'tr', { hasText: HOST } );
await row.locator( 'select' ).selectOption( 'marketing' );
await row.getByRole( 'button', { name: /Classify|Zařadit/ } ).click();
await p.waitForTimeout( 1500 );
check( 'host leaves the queue after classifying', await p.locator( 'tr', { hasText: HOST } ).filter( { has: p.locator( 'select' ) } ).count() === 0 );
check( 'no REST errors', errors.length === 0, errors.join( ' // ' ) );

// frontend: a snippet pasted into a page is held back only with the page buffer on (Blocking tab)
await p.evaluate( async () => {
	const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.uxStudioBoot.nonce };
	const url = window.uxStudioBoot.restUrl + '/settings/consent';
	const cur = ( await ( await fetch( url, { headers: h } ) ).json() ).data;
	await fetch( url, { method: 'POST', headers: h, body: JSON.stringify( { ...cur.values, buffer_guard: true } ) } );
} );
await p.waitForTimeout( 500 );
const f = await browser.newContext();
const hits = [];
await f.route( /unknownvendor-qa/, ( r ) => {
	hits.push( r.request().url() );
	return r.fulfill( { contentType: 'text/javascript', body: '' } );
} );
await f.route( /googletagmanager|youtube|google\.com\/maps/, ( r ) => r.fulfill( { body: '' } ) );
const fp = await f.newPage();
await fp.goto( BASE + '/qa-embeds/', { waitUntil: 'networkidle' } );
check( 'classified script held back (text/plain, marketing)', await fp.locator( 'script[type="text/plain"][data-uxc~=marketing][src*="unknownvendor-qa"]' ).count() === 1 );
check( 'classified script not requested before consent', hits.length === 0, hits.join() );
await fp.evaluate( () => window.uxConsent.set( { preferences: false, statistics: false, marketing: true } ) );
await fp.waitForTimeout( 800 );
check( 'classified script requested after marketing consent', hits.length >= 1 );
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
