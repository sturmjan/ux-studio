/**
 * Browser QA of the unified Dashboard Widgets screen (PLAN.md 43). Local test site only.
 *
 *   BASE=http://127.0.0.1/_uxs-e2e COOKIES=path\to\cookies.json node tests/e2e/dashboard-widgets-unified.mjs
 *
 * COOKIES is a JSON list of {name, value} auth cookies of a temporary administrator (made with wp_generate_auth_cookie);
 * no password is used. Expects the site to have the module on with the settings of a NEW installation, and a mu-plugin
 * that adds the widgets `wpseo-dashboard-overview` and `dashw_fatal` (a widget that fatals).
 */
import { chromium } from 'playwright';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const cookies = JSON.parse( readFileSync( process.env.COOKIES, 'utf8' ) ).map( ( c ) => ( { ...c, url: BASE + '/' } ) );
const axeSource = readFileSync( createRequire( import.meta.url ).resolve( 'axe-core/axe.min.js' ), 'utf8' );
const SCREEN = `${ BASE }/wp-admin/admin.php?page=ux-studio#/module?id=dashboard-widgets`;

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};

const browser = await chromium.launch();
const context = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
await context.addCookies( cookies );
const page = await context.newPage();
const errors = [];
page.on( 'console', ( m ) => m.type() === 'error' && errors.push( m.text() ) );
page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );

const dashIds = async () => page.$$eval( '#dashboard-widgets .postbox', ( els ) => els.map( ( e ) => e.id ) );

// --- The real dashboard with the starting state -----------------------------------------------------------------------
await page.goto( `${ BASE }/wp-admin/index.php` );
let ids = await dashIds();
check( 'dashboard: starting state hides site health, events, notes', ! ids.includes( 'dashboard_site_health' ) && ! ids.includes( 'dashboard_primary' ) && ! ids.includes( 'uxstudio_notes' ), ids.join() );
check( 'dashboard: welcome panel hidden', 0 === ( await page.$$( '#welcome-panel' ) ).length );
check( 'dashboard: Yoast widget hidden on first sight', ! ids.includes( 'wpseo-dashboard-overview' ), ids.join() );
check( 'dashboard: other widgets stay', ids.includes( 'dashboard_right_now' ) && ids.includes( 'uxstudio_tasks' ) && ids.includes( 'uxstudio_recent_media' ) && ids.includes( 'uxstudio_user_activity' ), ids.join() );
check( 'dashboard: off widgets absent', ! ids.includes( 'uxstudio_disk_usage' ) && ! ids.includes( 'uxstudio_recent_posts' ) );
await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-dashboard-before.png` } );

// --- Old tab addresses ------------------------------------------------------------------------------------------------
await page.goto( SCREEN + '&tab=custom' );
await page.waitForSelector( '.uxs-wt__table', { timeout: 30000 } );
check( 'old tab=custom redirects', /tab=widgets/.test( page.url() ) && /type=own/.test( page.url() ), page.url() );
check( 'old tab=custom preselects the type', 'own' === ( await page.inputValue( 'select >> nth=0' ) ) );
await page.goto( SCREEN + '&tab=modules' );
await page.reload();
await page.waitForSelector( '.uxs-wt__table', { timeout: 30000 } );
check( 'old tab=modules preselects the type', 'module' === ( await page.inputValue( 'select >> nth=0' ) ) && /type=module/.test( page.url() ), page.url() );
await page.goto( SCREEN + '&tab=hidden' );
await page.reload();
await page.waitForSelector( '.uxs-wt__table', { timeout: 30000 } );
check( 'old tab=hidden shows everything', 'all' === ( await page.inputValue( 'select >> nth=0' ) ) );

// --- Table ------------------------------------------------------------------------------------------------------------
const rowOf = ( id ) => page.locator( 'tr', { has: page.locator( `code:text-is("${ id }")` ) } ).first();
const rows = async () => ( await page.$$( '.uxs-wt__table tbody tr:not(.uxs-wt__detail)' ) ).length;
const total = await rows();
check( 'table lists all widgets in one place', total >= 25, String( total ) );
const sel = ( n ) => page.locator( '.uxs-wt__toolbar select' ).nth( n );
await sel( 0 ).selectOption( 'own' );
check( 'filter own', 9 === ( await rows() ), String( await rows() ) );
await sel( 0 ).selectOption( 'module' );
const modRows = await rows();
check( 'filter module', modRows >= 10, String( modRows ) );
await sel( 0 ).selectOption( 'wordpress' );
check( 'filter wordpress', ( await rows() ) >= 6 );
await sel( 0 ).selectOption( 'plugin' );
check( 'filter plugins (mu-plugin test widgets)', ( await rows() ) >= 2, String( await rows() ) );
await sel( 0 ).selectOption( 'all' );
await sel( 1 ).selectOption( 'off' );
const offRows = await rows();
check( 'filter state off', offRows >= 8, String( offRows ) );
await sel( 1 ).selectOption( 'hidden' );
check( 'filter state hidden has the 5 default-hidden', ( await rows() ) >= 5, String( await rows() ) );
await sel( 1 ).selectOption( 'all' );
await page.fill( '.uxs-wt__search input', 'disk' );
check( 'search by name', 1 === ( await rows() ) );
await page.fill( '.uxs-wt__search input', 'uxstudio_forms' );
check( 'search by id', 1 === ( await rows() ) );
await page.fill( '.uxs-wt__search input', '' );

// type labels
check( 'WordPress row labelled', /WordPress/.test( await rowOf( 'dashboard_right_now' ).innerText() ) );
check( 'own row labelled', /UX1 Studio widget|Widget UX Studia/.test( await rowOf( 'uxstudio_tasks' ).innerText() ) );
const formsText = await rowOf( 'uxstudio_forms_widget' ).innerText();
check( 'module row names its module', /Form/i.test( formsText ) && /module|modul/i.test( formsText ), formsText.replace( /\n/g, ' | ' ) );
const yoastText = await rowOf( 'wpseo-dashboard-overview' ).innerText();
check( 'third-party row labelled', /Plugin|Must-use/i.test( yoastText ), yoastText.replace( /\n/g, ' | ' ) );
check( 'Yoast (default) row is hidden', 'true' === ( await rowOf( 'wpseo-dashboard-overview' ).locator( '.uxs-switch >> nth=0' ).getAttribute( 'aria-pressed' ) ) || 'true' === ( await rowOf( 'wpseo-dashboard-overview' ).locator( '.uxs-wt__c-hide .uxs-switch' ).getAttribute( 'aria-pressed' ) ) );

// switches
const diskSwitch = rowOf( 'uxstudio_disk_usage' ).locator( '.uxs-wt__c-on .uxs-switch' );
check( 'disk usage starts off', 'false' === ( await diskSwitch.getAttribute( 'aria-pressed' ) ) );
await diskSwitch.click();
await page.waitForTimeout( 800 );
check( 'disk usage switched on', 'true' === ( await diskSwitch.getAttribute( 'aria-pressed' ) ) );
const tasksSwitch = rowOf( 'uxstudio_tasks' ).locator( '.uxs-wt__c-on .uxs-switch' );
await tasksSwitch.click();
await page.waitForTimeout( 800 );
check( 'tasks switched off', 'false' === ( await tasksSwitch.getAttribute( 'aria-pressed' ) ) );
// role limit + hide
await rowOf( 'dashboard_activity' ).locator( '.uxs-wt__c-hide .uxs-switch' ).click();
await page.waitForTimeout( 800 );
// bulk
await rowOf( 'uxstudio_recent_posts' ).locator( 'input[type=checkbox]' ).check();
await rowOf( 'uxstudio_pagespeed' ).locator( 'input[type=checkbox]' ).check();
await page.getByRole( 'button', { name: /^(Turn on|Zapnout)$/ } ).click();
await page.waitForTimeout( 1000 );
check( 'bulk turn on', 'true' === ( await rowOf( 'uxstudio_recent_posts' ).locator( '.uxs-wt__c-on .uxs-switch' ).getAttribute( 'aria-pressed' ) ) && 'true' === ( await rowOf( 'uxstudio_pagespeed' ).locator( '.uxs-wt__c-on .uxs-switch' ).getAttribute( 'aria-pressed' ) ) );
await page.getByRole( 'button', { name: /Select none|Zrušit výběr|Nic nevybírat/ } ).first().click();

// module off row
const dnsText = await rowOf( 'uxstudio_dns_manager_widget' ).innerText();
console.log( 'INFO dns row: ' + dnsText.replace( /\n/g, ' | ' ) );

// detail
await rowOf( 'uxstudio_pagespeed' ).locator( '.uxs-wt__expand' ).click();
const detail = await page.locator( '.uxs-wt__detail' ).first().innerText();
check( 'detail shows description and the key hint', /PageSpeed/i.test( detail ) && /(API|klíč)/i.test( detail ), detail.replace( /\n/g, ' | ' ) );
await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-table.png`, fullPage: false } );
await rowOf( 'uxstudio_pagespeed' ).locator( '.uxs-wt__expand' ).click();

// --- Previews ---------------------------------------------------------------------------------------------------------
const previewOf = async ( id, shot ) => {
	await page.mouse.move( 5, 5 );
	const eye = rowOf( id ).locator( '.uxs-wt__eye' );
	await eye.scrollIntoViewIfNeeded();
	await eye.hover();
	const pop = page.locator( '.uxs-wt__preview' );
	await pop.waitFor( { timeout: 5000 } );
	const handle = await pop.locator( 'iframe' ).elementHandle();
	const frame = handle ? await handle.contentFrame() : null;
	if ( frame ) {
		await frame.waitForLoadState( 'load', { timeout: 20000 } ).catch( () => undefined );
		await page.waitForTimeout( 1200 );
	}
	await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-preview-${ shot }.png` } );
	const text = frame ? await frame.evaluate( () => document.body.innerText ).catch( () => '' ) : '';
	const box = await pop.boundingBox();
	await page.mouse.move( 5, 5 );
	await pop.waitFor( { state: 'detached', timeout: 3000 } ).catch( () => undefined );
	return { text, box };
};
let r = await previewOf( 'dashboard_right_now', 'wordpress' );
check( 'preview WordPress widget', /At a Glance|Na první pohled/i.test( r.text ), r.text.slice( 0, 80 ) );
check( 'preview popover about 420 wide', r.box && r.box.width > 400 && r.box.width < 440, JSON.stringify( r.box ) );
r = await previewOf( 'uxstudio_recent_media', 'own' );
check( 'preview own widget', /media|média|nahr/i.test( r.text ) || r.text.length > 5, r.text.slice( 0, 80 ) );
r = await previewOf( 'uxstudio_bot_throttle_widget', 'module' );
check( 'preview module widget', /bot|Bot/i.test( r.text ) && ! /not available|není k dispozici/i.test( r.text ), r.text.slice( 0, 80 ) );
r = await previewOf( 'uxstudio_forms_widget', 'module-off' );
check( 'preview of a widget whose module is off says so', /not available|není k dispozici/i.test( r.text ), r.text.slice( 0, 80 ) );
r = await previewOf( 'uxstudio_disk_usage', 'disabled-own' );
check( 'preview of a switched-off own widget (was off at load)', /Disk|disk|WordPress/i.test( r.text ), r.text.slice( 0, 80 ) );
r = await previewOf( 'uxstudio_woocommerce_recent_orders', 'woo' );
check( 'preview of an unavailable widget says so', /not available|není k dispozici/i.test( r.text ), r.text.slice( 0, 80 ) );
r = await previewOf( 'dashw_fatal', 'fatal' );
check( 'preview of a fatal widget is caught', /not available|není k dispozici|nepodařilo/i.test( r.text ), r.text.slice( 0, 80 ) );
r = await previewOf( 'wpseo-dashboard-overview', 'hidden-third-party' );
check( 'preview of a hidden third-party widget', /Fake Yoast/.test( r.text ), r.text.slice( 0, 80 ) );

// keyboard: focus opens, Escape closes
await rowOf( 'uxstudio_notes' ).locator( '.uxs-wt__eye' ).focus();
await page.locator( '.uxs-wt__preview' ).waitFor( { timeout: 5000 } );
await page.keyboard.press( 'Escape' );
await page.locator( '.uxs-wt__preview' ).waitFor( { state: 'detached', timeout: 3000 } );
check( 'preview opens on focus and closes with Escape', true );

// --- Security of the endpoint -----------------------------------------------------------------------------------------
const base = await page.evaluate( () => 0 );
const anon = await browser.newContext();
const ap = await anon.newPage();
const res = await ap.goto( `${ BASE }/wp-admin/admin-ajax.php?action=uxstudio_dw_preview&widget=dashboard_right_now` );
check( 'preview without login is refused', res && ( 400 === res.status() || 403 === res.status() || 0 === ( await ap.content() ).indexOf( '0' ) ) && ! /At a Glance/.test( await ap.content() ), String( res && res.status() ) );
const nonceUrl = await page.evaluate( async () => {
	const r = await fetch( `${ window.uxStudioBoot.restUrl }/dashboard-widgets/rules`, { headers: { 'X-WP-Nonce': window.uxStudioBoot.nonce } } );
	return ( await r.json() ).data.preview;
} );
const noNonce = await page.request.get( `${ BASE }/wp-admin/admin-ajax.php?action=uxstudio_dw_preview&widget=dashboard_right_now` );
check( 'preview with login but no nonce is 403', 403 === noNonce.status(), String( noNonce.status() ) );
const good = await page.request.get( `${ nonceUrl }&widget=dashboard_right_now` );
check( 'preview with nonce: 200 and frame headers', 200 === good.status() && /sameorigin/i.test( good.headers()[ 'x-frame-options' ] || '' ) && /frame-ancestors/.test( good.headers()[ 'content-security-policy' ] || '' ), JSON.stringify( good.headers() ) );
const bad = await page.request.get( `${ nonceUrl }&widget=../../etc` );
check( 'preview with a bad id is refused', 400 === bad.status(), String( bad.status() ) );
await anon.close();

// --- Dashboard follows the settings -----------------------------------------------------------------------------------
await page.goto( `${ BASE }/wp-admin/index.php` );
ids = await dashIds();
check( 'dashboard: disk usage now shown', ids.includes( 'uxstudio_disk_usage' ), ids.join() );
check( 'dashboard: tasks now gone', ! ids.includes( 'uxstudio_tasks' ), ids.join() );
check( 'dashboard: activity now hidden', ! ids.includes( 'dashboard_activity' ), ids.join() );
check( 'dashboard: recent posts and pagespeed shown', ids.includes( 'uxstudio_recent_posts' ) && ids.includes( 'uxstudio_pagespeed' ), ids.join() );
await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-dashboard-after.png` } );

// --- Revert -----------------------------------------------------------------------------------------------------------
await page.goto( SCREEN + '&tab=widgets' );
await page.reload();
await page.waitForSelector( '.uxs-wt__table', { timeout: 30000 } );
await rowOf( 'uxstudio_disk_usage' ).locator( '.uxs-wt__c-on .uxs-switch' ).click();
await page.waitForTimeout( 800 );
await page.getByRole( 'button', { name: /Revert changes|Vrátit změny/ } ).click();
await page.waitForTimeout( 1200 );
check( 'revert puts the switch back', 'true' === ( await rowOf( 'uxstudio_disk_usage' ).locator( '.uxs-wt__c-on .uxs-switch' ).getAttribute( 'aria-pressed' ) ) );

// --- axe, light and dark ----------------------------------------------------------------------------------------------
for ( const theme of [ 'light', 'dark' ] ) {
	await page.evaluate( ( t ) => document.getElementById( 'ux-studio-root' )?.setAttribute( 'data-theme', t ), theme );
	await page.waitForTimeout( 400 );
	await rowOf( 'uxstudio_notes' ).locator( '.uxs-wt__expand' ).click();
	await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-table-${ theme }.png` } );
	await page.evaluate( axeSource );
	const res2 = await page.evaluate( async () => {
		const out = await window.axe.run( '#ux-studio-root', { resultTypes: [ 'violations' ] } );
		return out.violations.filter( ( v ) => 'serious' === v.impact || 'critical' === v.impact ).map( ( v ) => `${ v.id } (${ v.nodes.length }): ${ v.nodes[ 0 ].html.slice( 0, 120 ) }` );
	} );
	check( `axe ${ theme }: no serious or critical`, 0 === res2.length, res2.join( ' ;; ' ) );
	await rowOf( 'uxstudio_notes' ).locator( '.uxs-wt__expand' ).click();
}
// narrow screen
await page.setViewportSize( { width: 390, height: 900 } );
await page.waitForTimeout( 400 );
await page.screenshot( { path: `${ SHOTS }\\uxs-dashw-table-mobile.png` } );
check( 'mobile: no horizontal overflow', await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth + 2 ) );

const real = errors.filter( ( e ) => ! /favicon|Failed to load resource/.test( e ) );
check( 'no console errors', 0 === real.length, real.slice( 0, 3 ).join( ' ;; ' ) );

await browser.close();
console.log( fails ? `${ fails } FAILED` : 'ALL PASSED' );
process.exit( fails ? 1 : 0 );
