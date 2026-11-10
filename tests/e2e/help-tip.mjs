/**
 * Browser QA of the help "?" button and its floating window (PLAN.md 43). Local test site only.
 *
 *   BASE=http://127.0.0.1/_uxs-e2e UXS_COOKIES=<json file with the auth cookies> node tests/e2e/help-tip.mjs
 *
 * The cookie file is {auth_name, auth, li_name, li, site, admin, plugins} (made with wp_generate_auth_cookie for a
 * temporary administrator). Opens the settings of a module, drives the button with the mouse, the keyboard and a
 * touch screen, checks the placement, runs axe-core with the window open, in the light and dark theme, desktop and
 * phone width. Screenshots go to SHOTS (the scratch folder).
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const AXE = readFileSync( resolve( here, '../../node_modules/axe-core/axe.min.js' ), 'utf8' );
const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const MODULE = process.env.MODULE || 'admin-customiser';
const ck = JSON.parse( readFileSync( process.env.UXS_COOKIES, 'utf8' ) );
mkdirSync( SHOTS, { recursive: true } );

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

const browser = await chromium.launch();

async function open( viewport, theme, touch = false ) {
	const context = await browser.newContext( { viewport, hasTouch: touch, isMobile: touch, deviceScaleFactor: 1 } );
	const host = new URL( BASE ).hostname;
	await context.addCookies( [
		{ name: ck.auth_name, value: ck.auth, domain: host, path: ck.admin, httpOnly: true },
		{ name: ck.auth_name, value: ck.auth, domain: host, path: ck.plugins, httpOnly: true },
		{ name: ck.li_name, value: ck.li, domain: host, path: ck.cookie, httpOnly: true },
	] );
	await context.addInitScript( ( t ) => window.localStorage.setItem( 'uxstudio-theme', t ), theme );
	const page = await context.newPage();
	const errors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && errors.push( m.text() ) );
	page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
	await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=' + MODULE, { waitUntil: 'load' } );
	await page.waitForSelector( '.uxs-tipbtn', { timeout: 20000 } );
	return { context, page, errors };
}

const axeRun = async ( page, label ) => {
	await page.addScriptTag( { content: AXE } );
	const r = await page.evaluate( async () => {
		const res = await window.axe.run( { include: [ [ '#ux-studio-root' ], [ '.uxs-tip' ] ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] } } );
		return res.violations.map( ( v ) => ( { id: v.id, impact: v.impact, nodes: v.nodes.slice( 0, 3 ).map( ( n ) => n.target.join( ' ' ) ) } ) );
	} );
	const bad = r.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	check( 'axe (' + label + '): no serious or critical violation', bad.length === 0, JSON.stringify( bad ) );
	const minor = r.filter( ( v ) => ! bad.includes( v ) );
	if ( minor.length ) {
		console.log( 'INFO axe (' + label + ') other: ' + JSON.stringify( minor ) );
	}
};

const rect = ( page, sel ) => page.locator( sel ).first().evaluate( ( el ) => { const r = el.getBoundingClientRect(); return { l: r.left, t: r.top, r: r.right, b: r.bottom, w: r.width, h: r.height }; } );
const inside = async ( page, sel ) => {
	const r = await rect( page, sel );
	const vw = await page.evaluate( () => document.documentElement.clientWidth );
	const vh = await page.evaluate( () => window.innerHeight );
	return r.l >= 0 && r.t >= 0 && r.r <= vw + 0.5 && r.b <= vh + 0.5;
};

// ---- Desktop, light ----
{
	const { context, page, errors } = await open( { width: 1440, height: 900 }, 'light' );
	check( 'the old "Details" expander is gone', ( await page.locator( 'details.uxs-explain, .uxs-explain' ).count() ) === 0 );
	const count = await page.locator( '.uxs-tipbtn' ).count();
	check( 'help buttons are drawn', count > 3, String( count ) );

	// Visible sub-tab or group: open the login section so that a button is in view.
	const btn = page.locator( '.uxs-tipbtn' ).first();
	await btn.scrollIntoViewIfNeeded();
	const label = await btn.getAttribute( 'aria-label' );
	check( 'aria-label "Help: <field>"', /^Help: .+/.test( label || '' ), label || '' );
	check( 'closed: aria-expanded false, no window', ( await btn.getAttribute( 'aria-expanded' ) ) === 'false' && ( await page.locator( '.uxs-tip' ).count() ) === 0 );

	// Same line as the control: the button's vertical centre is inside the row control's box.
	const sameLine = await btn.evaluate( ( b ) => {
		const ctl = b.closest( '.uxs-form__control' );
		const main = ctl.querySelector( '.uxs-form__control-main' ).getBoundingClientRect();
		const r = b.getBoundingClientRect();
		const cy = r.top + r.height / 2;
		return cy >= main.top && cy <= main.bottom && r.left >= main.right - 1;
	} );
	check( 'button sits to the right of the control, in the same line', sameLine );

	// Hover opens, leaving closes after a delay, pointer can travel into the window.
	await btn.hover();
	await page.waitForSelector( '.uxs-tip', { state: 'visible' } );
	check( 'hover opens the window', true );
	check( 'aria-expanded true, controls and describedby point at the window', await btn.evaluate( ( b ) => { const id = b.getAttribute( 'aria-controls' ); return b.getAttribute( 'aria-expanded' ) === 'true' && id && b.getAttribute( 'aria-describedby' ) === id && !! document.getElementById( id ); } ) );
	check( 'window is inside the screen', await inside( page, '.uxs-tip' ) );
	check( 'window is at most 460px wide', ( await rect( page, '.uxs-tip' ) ).w <= 461 );
	await page.screenshot( { path: SHOTS + '\\uxs-help-desktop-light-hover.png' } );
	const tipBox = await rect( page, '.uxs-tip' );
	await page.mouse.move( tipBox.l + 20, tipBox.t + 20, { steps: 8 } );
	await sleep( 500 );
	check( 'moving the pointer into the window keeps it open', ( await page.locator( '.uxs-tip' ).count() ) === 1 );
	await page.mouse.move( 5, 5, { steps: 4 } );
	await sleep( 600 );
	check( 'leaving closes it', ( await page.locator( '.uxs-tip' ).count() ) === 0 );

	// Click pins; leaving does not close; a click on the button closes; Escape closes.
	await btn.hover();
	await btn.click();
	await page.mouse.move( 5, 5, { steps: 4 } );
	await sleep( 600 );
	check( 'a click pins it (stays after the pointer left)', ( await page.locator( '.uxs-tip[data-pinned="true"]' ).count() ) === 1 );
	await axeRun( page, 'desktop light, pinned' );
	await page.screenshot( { path: SHOTS + '\\uxs-help-desktop-light-pinned.png' } );
	await page.keyboard.press( 'Escape' );
	check( 'Escape closes it', ( await page.locator( '.uxs-tip' ).count() ) === 0 );
	await btn.click();
	await page.mouse.move( 5, 5 );
	await btn.click();
	check( 'a second click closes it', ( await page.locator( '.uxs-tip' ).count() ) === 0 );

	// Outside click.
	await btn.click();
	await page.mouse.click( 1436, 450 );
	check( 'a click outside closes it', ( await page.locator( '.uxs-tip' ).count() ) === 0 );

	// Keyboard: focus peeks, Enter pins and moves the focus in, Escape returns the focus.
	await page.mouse.move( 5, 5 );
	await btn.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	await page.waitForSelector( '.uxs-tip', { timeout: 3000 } ).catch( () => null );
	check( 'keyboard focus opens the window', ( await page.locator( '.uxs-tip' ).count() ) === 1 );
	await page.keyboard.press( 'Enter' );
	check( 'Enter pins it and moves the focus into it', await page.evaluate( () => !! document.activeElement?.closest( '.uxs-tip' ) && document.querySelector( '.uxs-tip' ).dataset.pinned === 'true' ) );
	await page.keyboard.press( 'Escape' );
	await sleep( 200 );
	check( 'Escape closes it and the focus is back on the button', ( await page.locator( '.uxs-tip' ).count() ) === 0 && ( await btn.evaluate( ( b ) => document.activeElement === b ) ), String( await page.locator( '.uxs-tip' ).count() ) + ' / ' + ( await page.evaluate( () => document.activeElement.outerHTML.slice( 0, 90 ) ) ) );

	// Only one at a time.
	const second = page.locator( '.uxs-tipbtn' ).nth( 1 );
	await second.scrollIntoViewIfNeeded();
	await btn.click();
	await second.click();
	check( 'only one window at a time', ( await page.locator( '.uxs-tip' ).count() ) === 1 );
	await page.keyboard.press( 'Escape' );

	// Every button of the page opens a window with text.
	const n = await page.locator( '.uxs-tipbtn' ).count();
	let empty = 0;
	for ( let i = 0; i < n; i++ ) {
		const b = page.locator( '.uxs-tipbtn' ).nth( i );
		if ( ! ( await b.isVisible() ) ) {
			continue;
		}
		await b.scrollIntoViewIfNeeded();
		await b.click();
		const t = ( await page.locator( '.uxs-tip' ).first().innerText() ).trim();
		if ( ! t || ! ( await inside( page, '.uxs-tip' ) ) ) {
			empty++;
		}
		await page.keyboard.press( 'Escape' );
	}
	check( 'every visible button opens a non-empty window inside the screen', empty === 0, String( empty ) );
	check( 'no console errors', errors.length === 0, errors.slice( 0, 3 ).join( ' | ' ) );
	await context.close();
}

// ---- Desktop, dark ----
{
	const { context, page } = await open( { width: 1440, height: 900 }, 'dark' );
	const btn = page.locator( '.uxs-tipbtn' ).first();
	await btn.scrollIntoViewIfNeeded();
	await btn.click();
	await page.mouse.move( 5, 5 );
	const bg = await page.locator( '.uxs-tip' ).evaluate( ( el ) => getComputedStyle( el ).backgroundColor );
	check( 'dark theme: the window uses the dark surface', bg !== 'rgb(255, 255, 255)', bg );
	await axeRun( page, 'desktop dark, pinned' );
	await page.screenshot( { path: SHOTS + '\\uxs-help-desktop-dark-pinned.png' } );
	await context.close();
}

// ---- Phone, light and dark, by touch ----
for ( const theme of [ 'light', 'dark' ] ) {
	const { context, page } = await open( { width: 390, height: 780 }, theme, true );
	const btn = page.locator( '.uxs-tipbtn' ).first();
	await btn.scrollIntoViewIfNeeded();
	await btn.tap();
	await page.waitForSelector( '.uxs-tip', { state: 'visible' } );
	check( 'phone ' + theme + ': a tap opens it (pinned)', ( await page.locator( '.uxs-tip[data-pinned="true"]' ).count() ) === 1 );
	check( 'phone ' + theme + ': inside the screen', await inside( page, '.uxs-tip' ) );
	const r = await rect( page, '.uxs-tip' );
	check( 'phone ' + theme + ': spans the width with margins', r.l >= 11 && r.l <= 13 && r.w >= 360, JSON.stringify( r ) );
	await axeRun( page, 'phone ' + theme );
	await page.screenshot( { path: SHOTS + '\\uxs-help-phone-' + theme + '.png' } );
	await page.touchscreen.tap( 20, 60 );
	check( 'phone ' + theme + ': a tap outside closes it', ( await page.locator( '.uxs-tip' ).count() ) === 0 );
	await context.close();
}

await browser.close();
console.log( fails ? fails + ' FAILED' : 'ALL PASSED' );
process.exit( fails ? 1 : 0 );
