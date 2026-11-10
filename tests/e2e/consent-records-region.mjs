/**
 * Browser QA (PLAN.md 42.17): consent records end to end (banner -> sendBeacon -> admin lookup -> CSV export), the
 * dashboard widget. Local test site only. The EU-only mode is in consent-region.mjs.
 *
 *   BASE=... UXS_ADMIN_USER=e2e UXS_ADMIN_PASSWORD=... node tests/e2e/consent-records-region.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const PASS = process.env.UXS_ADMIN_PASSWORD;
const USER = process.env.UXS_ADMIN_USER || 'e2e';
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
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
const stub = ( c ) => c.route( /googletagmanager|youtube|google\.com\/maps/, ( r ) => r.fulfill( { body: '' } ) );

// 1. visitor decides
const v = await browser.newContext();
await stub( v );
const vp = await v.newPage();
await vp.goto( BASE + '/qa-embeds/', { waitUntil: 'networkidle' } );
await vp.waitForSelector( '.uxc-main .uxc-actions button' );
await vp.locator( '.uxc-main .uxc-actions button' ).nth( 1 ).click();
await vp.waitForTimeout( 1200 );
const ck = ( await v.cookies() ).find( ( c ) => c.name === 'uxs_consent' );
const cid = ck.value.split( '.' )[ 4 ];
console.log( 'INFO consent id ' + cid );
check( 'cookie carries a 32 hex consent id', /^[0-9a-f]{32}$/.test( cid || '' ) );
await v.close();

// 2. admin
const ctx = await browser.newContext( { viewport: { width: 1440, height: 900 }, acceptDownloads: true } );
const page = await ctx.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( e.message ) );
page.on( 'response', ( r ) => {
	if ( r.status() >= 400 && /wp-json|rest_route/.test( r.url() ) ) {
		errors.push( r.status() + ' ' + r.url() );
	}
} );
await page.goto( BASE + '/wp-login.php' );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

// dashboard widget
await page.goto( BASE + '/wp-admin/index.php', { waitUntil: 'networkidle' } );
const widget = page.locator( '#uxstudio_consent_widget' );
check( 'dashboard widget present', await widget.count() === 1 );
if ( await widget.count() ) {
	const wt = await widget.innerText();
	console.log( 'INFO widget: ' + wt.replace( /\s+/g, ' ' ).slice( 0, 200 ) );
	check( 'dashboard widget shows a count', /[0-9]/.test( wt ) );
	await widget.screenshot( { path: SHOTS + '\\uxc-dashboard-widget.png' } );
}

await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle' } );
await page.waitForSelector( '.uxs-pagehead h1' );
await page.evaluate( () => {
	[ ...document.querySelectorAll( 'button' ) ].find( ( x ) => /^(Consent records|Záznamy souhlasů)$/.test( x.textContent.trim() ) ).click();
} );
await page.waitForTimeout( 1500 );
const body = await page.locator( '#ux-studio-root' ).innerText();
check( 'records tab shows a total', /Accepted all|Přijato vše|Total|Celkem/.test( body ) );
await page.fill( 'input[placeholder="Consent ID"], input[placeholder="ID souhlasu"]', cid );
await page.getByRole( 'button', { name: /^(Find|Najít|Hledat)/ } ).click();
await page.waitForTimeout( 1500 );
const found = await page.locator( '#ux-studio-root table' ).last().innerText().catch( () => '' );
check( 'lookup by id finds the record', /accept|přijat|Accepted/i.test( found ) || /yes|ano/i.test( found ), found.slice( 0, 200 ) );
await page.screenshot( { path: SHOTS + '\\uxc-records-lookup.png', fullPage: true } );
const [ dl ] = await Promise.all( [
	page.waitForEvent( 'download', { timeout: 15000 } ).catch( () => null ),
	page.getByRole( 'button', { name: /Export CSV|Exportovat CSV/ } ).click(),
] );
if ( dl ) {
	const path = await dl.path();
	const csv = readFileSync( path, 'utf8' );
	check( 'CSV export downloaded with the record', csv.includes( cid ), csv.slice( 0, 200 ) );
	console.log( 'INFO csv head: ' + csv.split( '\n' ).slice( 0, 2 ).join( ' / ' ) );
} else {
	check( 'CSV export download started', false );
}
check( 'no REST errors in records tab', errors.length === 0, errors.join( ' // ' ) );

await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
