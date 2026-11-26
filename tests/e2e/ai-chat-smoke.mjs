// Browser smoke test of the AI Assistant chat (PLAN.md §45, F0). Never part of the release zip (tests/ is excluded).
//
// Covers what broke or was risky before: the widget answers, product cards carry name/price/link (the { data, meta }
// envelope bug), and the operator handoff round trip (visitor -> admin takes over -> reply reaches the visitor ->
// return to AI). It runs against a real local site with the AI Assistant module on and any working provider
// (for example the Claude Code Bridge provider), so answers take tens of seconds.
//
//   node tests/e2e/ai-chat-smoke.mjs
//
// Environment (nothing secret is stored here):
//   UXS_BASE_URL          site URL, default https://127.0.0.1/pobyty/
//   UXS_QUESTION          product question, default a Luhacovice stay question (pobyty data)
//   UXS_ADMIN_COOKIES     path to a JSON array of Playwright cookies of a logged-in admin; without it the handoff
//                         part is skipped
//   UXS_BROWSER_CHANNEL   msedge (default) or chrome
//   PLAYWRIGHT_CORE       folder of a playwright-core install, when it is not resolvable from here
//   UXS_HEADLESS          1 to run headless
//
// Exit code 0 = all checks passed (skipped parts do not fail).

import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';

const require = createRequire( import.meta.url );
let chromium;
try {
	( { chromium } = require( process.env.PLAYWRIGHT_CORE ? path.join( process.env.PLAYWRIGHT_CORE, 'index.js' ) : 'playwright-core' ) );
} catch {
	console.error( 'playwright-core not found: npm i -D playwright-core, or set PLAYWRIGHT_CORE to its folder.' );
	process.exit( 2 );
}

const BASE = ( process.env.UXS_BASE_URL || 'https://127.0.0.1/pobyty/' ).replace( /\/?$/, '/' );
const QUESTION = process.env.UXS_QUESTION || 'Jaké máte lázeňské pobyty pro seniory v Luhačovicích?';
const COOKIES = process.env.UXS_ADMIN_COOKIES || '';
const ROOT = '#uxstudio-ai-assistant-widget-root';

let failed = 0;
const check = ( name, ok, detail = '' ) => {
	console.log( `${ ok ? 'ok  ' : 'FAIL' } ${ name }${ ! ok && detail ? ' - ' + detail : '' }` );
	if ( ! ok ) failed++;
};

async function openWidget( page ) {
	await page.goto( BASE, { waitUntil: 'domcontentloaded', timeout: 180000 } );
	await page.getByRole( 'button', { name: 'Otevřít chat' } ).waitFor( { state: 'attached', timeout: 90000 } );
	await page.waitForTimeout( 8000 ); // auto-open delay
	const box = page.getByRole( 'textbox', { name: 'Zpráva' } );
	if ( ! ( await box.isVisible() ) ) await page.getByRole( 'button', { name: 'Otevřít chat' } ).click();
	await box.waitFor( { timeout: 60000 } );
	return box;
}

const browser = await chromium.launch( { channel: process.env.UXS_BROWSER_CHANNEL || 'msedge', headless: process.env.UXS_HEADLESS === '1' } );
try {
	// 1) Widget answer + product cards.
	const visitor = await ( await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 } } ) ).newPage();
	const jsErrors = [];
	visitor.on( 'pageerror', ( e ) => { if ( /uxstudio|ai-assistant/i.test( e.stack || '' ) ) jsErrors.push( e.message ); } );
	const box = await openWidget( visitor );
	await box.fill( QUESTION );
	await box.press( 'Enter' );
	const card = visitor.locator( `${ ROOT } .uxstudio-ais-product-card` ).first();
	const gotCards = await card.waitFor( { timeout: 180000 } ).then( () => true, () => false );
	check( 'widget: answer with product cards', gotCards, 'no product card within 180 s (no products on this site?)' );
	if ( gotCards ) {
		await visitor.waitForTimeout( 3000 );
		const cards = await visitor.$$eval( `${ ROOT } .uxstudio-ais-product-card`, ( els ) => els.map( ( a ) => ( {
			href: a.getAttribute( 'href' ),
			name: a.querySelector( '.uxstudio-ais-product-card-name' )?.textContent?.trim() || '',
			price: a.querySelector( '.uxstudio-ais-product-card-price' )?.textContent?.trim() || '',
		} ) ) );
		check( 'cards: every card has a name', cards.every( ( c ) => c.name ), JSON.stringify( cards[ 0 ] ) );
		check( 'cards: every card links to a page (not "#")', cards.every( ( c ) => c.href && c.href !== '#' ) );
		check( 'cards: prices are grouped, not raw decimals', cards.every( ( c ) => ! c.price || ! /\d{4,}\.\d{2}/.test( c.price ) ), cards.map( ( c ) => c.price ).join( ', ' ) );
	}
	check( 'widget: no JS errors from the widget', jsErrors.length === 0, jsErrors.join( ' | ' ) );

	// 2) Operator handoff round trip (needs an admin session).
	if ( ! COOKIES || ! fs.existsSync( COOKIES ) ) {
		console.log( 'skip handoff: set UXS_ADMIN_COOKIES to a cookie JSON of a logged-in admin' );
	} else {
		const v = await ( await browser.newContext( { ignoreHTTPSErrors: true } ) ).newPage();
		const vb = await openWidget( v );
		await vb.fill( 'Potřebuji mluvit s člověkem (smoke test).' );
		await vb.press( 'Enter' );
		await v.waitForTimeout( 30000 );
		await v.getByRole( 'button', { name: 'Přivolat operátora' } ).click();
		const banner = await v.locator( `${ ROOT } .uxstudio-ais-handoff-banner` ).waitFor( { timeout: 90000 } ).then( () => true, () => false );
		check( 'handoff: visitor sees the waiting banner', banner );

		const actx = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1500, height: 2000 } } );
		await actx.addCookies( JSON.parse( fs.readFileSync( COOKIES, 'utf8' ) ) );
		const a = await actx.newPage();
		a.setDefaultTimeout( 120000 );
		await a.goto( BASE + 'wp-admin/admin.php?page=ux-studio#/module?id=ai-assistant', { waitUntil: 'domcontentloaded', timeout: 180000 } );
		await a.getByText( 'Předání', { exact: true } ).first().waitFor( { state: 'visible' } );
		await a.getByRole( 'button', { name: 'Mám to' } ).click( { timeout: 3000 } ).catch( () => {} );
		await a.getByText( 'Předání', { exact: true } ).first().click();
		const row = a.getByText( 'Potřebuji mluvit s člověkem (smoke test).' ).first();
		check( 'handoff: request shows in the admin queue', await row.waitFor( { timeout: 90000 } ).then( () => true, () => false ) );
		await row.click();
		await a.getByRole( 'button', { name: 'Převzít' } ).click();
		await a.waitForTimeout( 6000 );
		const reply = 'Dobrý den, tady operátor (smoke test).';
		await a.getByRole( 'button', { name: 'Odeslat' } ).last().locator( 'xpath=preceding::*[self::textarea or self::input[@type="text" or not(@type)]][1]' ).fill( reply );
		await a.getByRole( 'button', { name: 'Odeslat' } ).last().click();
		check( 'handoff: operator reply reaches the visitor', await v.getByText( reply ).first().waitFor( { timeout: 60000 } ).then( () => true, () => false ) );
		await a.getByRole( 'button', { name: 'Vrátit AI' } ).click();
		await v.waitForTimeout( 15000 );
		check( 'handoff: banner disappears after "return to AI"', ( await v.locator( `${ ROOT } .uxstudio-ais-handoff-banner` ).count() ) === 0 );
	}
} finally {
	await browser.close();
}

console.log( failed ? `\n${ failed } failed` : '\nall passed' );
process.exit( failed ? 1 : 0 );
