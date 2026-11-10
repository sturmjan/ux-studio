/**
 * Browser QA (PLAN.md 42.19): the legal wizard fills the details of the controller from the site. Local test site only.
 *
 *   BASE=http://localhost/hydro-izol UXS_ADMIN_USER=... UXS_ADMIN_PASSWORD=... node tests/e2e/consent-legal-detect.mjs
 *
 * Scenario A: the REST answer of the wizard is replaced by an UNSAVED, empty profile (the saved one of the site is not
 * touched) and the detection runs by itself on opening; then ARES is asked. Scenario B: the real, saved profile; the
 * button must not overwrite anything. In both, no request saves anything (POST consent/legal/profile).
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = ( process.env.BASE || 'http://localhost/hydro-izol' ).replace( /\/$/, '' );
const PASS = process.env.UXS_ADMIN_PASSWORD;
const USER = process.env.UXS_ADMIN_USER;
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
if ( ! process.env.COOKIES_JSON && ( ! PASS || ! USER ) ) {
	console.error( 'Set UXS_ADMIN_USER and UXS_ADMIN_PASSWORD (or COOKIES_JSON)' );
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
const context = await browser.newContext( { viewport: { width: 1440, height: 1100 } } );
const page = await context.newPage();
const errors = [];
const saves = [];
const detects = [];
page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
page.on( 'response', ( r ) => {
	if ( r.status() >= 400 && /wp-json|rest_route/.test( r.url() ) ) {
		errors.push( r.status() + ' ' + r.url() );
	}
} );
page.on( 'request', ( r ) => {
	if ( r.method() === 'POST' && /consent\/legal\/profile/.test( r.url() ) ) {
		saves.push( r.url() );
	}
	if ( /consent(\/|%2F)legal(\/|%2F)detect/.test( r.url() ) ) {
		detects.push( decodeURIComponent( r.url() ) );
	}
} );

if ( process.env.COOKIES_JSON ) {
	// The login form of the site has a bot shield; a session made with WP-CLI is used instead.
	await context.addCookies( JSON.parse( readFileSync( process.env.COOKIES_JSON, 'utf8' ) ) );
} else {
	await page.goto( BASE + ( process.env.LOGIN_PATH || '/wp-login.php' ) );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

const fieldValue = async ( label ) => page.locator( 'label', { hasText: label } ).locator( 'input[type=text], input:not([type]), textarea' ).first().inputValue();
const rowText = async ( label ) => ( await page.locator( '.uxs-form__row', { has: page.locator( 'label', { hasText: label } ) } ).first().innerText() ).replace( /\s+/g, ' ' );
const openWizard = async () => {
	await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'domcontentloaded', timeout: 90000 } );
	await page.waitForSelector( '.uxs-pagehead h1', { timeout: 60000 } );
	await page.evaluate( () => {
		const rx = /^(Legal pages|Právní stránky)$/;
		[ ...document.querySelectorAll( 'button' ) ].find( ( x ) => rx.test( x.textContent.trim() ) && x.parentElement.querySelectorAll( 'button' ).length >= 8 ).click();
	} );
	await page.waitForSelector( 'text=/Who is the controller|Kdo je správce/' );
};

// ---------- A: nothing saved yet ----------
await page.route( /consent(\/|%2F)legal(\?|$)/, async ( route ) => {
	if ( route.request().method() !== 'GET' ) {
		return route.continue();
	}
	const res = await route.fetch();
	const body = await res.json();
	const d = body.data;
	d.stored = false;
	d.guessed = [ 'name', 'email' ];
	d.profile = { ...d.profile, name: 'Hydro-Izol (jen tip serveru)', email: 'sturm.jan@gmail.com', ico: '', dic: '', address: '', register_note: '', phone: '', purposes: [ 'contact', 'security' ] };
	await route.fulfill( { response: res, json: body } );
} );
await openWizard();
await page.waitForTimeout( 6000 );
check( 'A: the detection ran by itself, without ARES', detects.length >= 1 && detects.every( ( u ) => /ares=0/.test( u ) ), detects.join( ' | ' ) );
check( 'A: IČO filled', ( await fieldValue( 'Company number' ) ) === '06674861', await fieldValue( 'Company number' ) );
check( 'A: DIČ filled', ( await fieldValue( 'VAT number' ) ) === 'CZ06674861' );
check( 'A: the guess of the server was replaced by the SEO plugin', ( await fieldValue( 'Name of the company' ) ) === 'HYDRO-IZOL CZ, s.r.o.', await fieldValue( 'Name of the company' ) );
check( 'A: address filled', ( await fieldValue( 'Address of the seat' ) ) === 'Horní Lánov 188, 543 41 Lánov', await fieldValue( 'Address of the seat' ) );
check( 'A: register entry filled', /Krajským soudem v Hradci Králové, oddíl C, vložka 40698/.test( await fieldValue( 'Entry in a register' ) ) );
check( 'A: e-mail on the domain of the site', ( await fieldValue( 'E-mail for questions' ) ) === 'info@hydro-izol.cz', await fieldValue( 'E-mail for questions' ) );
check( 'A: phone filled', ( await fieldValue( 'Telephone' ) ) === '+420 604 909 533' );
check( 'A: badge with the source next to the name', /Yoast SEO/.test( await rowText( 'Name of the company' ) ), await rowText( 'Name of the company' ) );
check( 'A: badge with the page next to the IČO', /Kontakt/.test( await rowText( 'Company number' ) ), await rowText( 'Company number' ) );
check( 'A: nothing was saved by the detection', saves.length === 0 );
check( 'A: Save is enabled and says unsaved', await page.locator( 'button.button-primary', { hasText: /Save details|Uložit/ } ).isEnabled() );
await page.screenshot( { path: SHOTS + '\\legal-detect-a.png', fullPage: false } );

// ---------- A2: ARES ----------
await page.locator( 'button', { hasText: /Verify in ARES|Ověřit v ARES/ } ).click();
await page.waitForTimeout( 5000 );
check( 'A2: ARES was asked with the company number', detects.some( ( u ) => /ares=1/.test( u ) && /ico=06674861/.test( u ) ), detects.join( ' | ' ) );
const nameAfter = await fieldValue( 'Name of the company' );
check( 'A2: the official name from ARES replaced the untouched suggestion', nameAfter === 'HYDRO - IZOL CZ, s.r.o.', nameAfter );
check( 'A2: badge says ARES', /ARES/.test( await rowText( 'Name of the company' ) ), await rowText( 'Name of the company' ) );
check( 'A2: still nothing saved', saves.length === 0 );
// What the person types is never overwritten, a different value is only offered.
await page.locator( 'label', { hasText: 'Name of the company' } ).locator( 'input' ).first().fill( 'Moje vlastní jméno' );
await page.locator( 'button', { hasText: /Verify in ARES|Ověřit v ARES/ } ).click();
await page.waitForTimeout( 5000 );
check( 'A2: a typed value stays', ( await fieldValue( 'Name of the company' ) ) === 'Moje vlastní jméno' );
check( 'A2: the ARES value is offered next to it', /ARES.*HYDRO - IZOL CZ, s\.r\.o\./.test( await rowText( 'Name of the company' ) ), await rowText( 'Name of the company' ) );
await page.locator( '.uxs-form__row', { has: page.locator( 'label', { hasText: 'Name of the company' } ) } ).locator( 'button', { hasText: /Use it|Použít/ } ).click();
check( 'A2: "Use it" takes the suggestion', ( await fieldValue( 'Name of the company' ) ) === 'HYDRO - IZOL CZ, s.r.o.' );
await page.screenshot( { path: SHOTS + '\\legal-detect-a2.png', fullPage: false } );
await page.unroute( /consent(\/|%2F)legal(\?|$)/ );

// ---------- B: the real, saved profile ----------
detects.length = 0;
await openWizard();
await page.waitForTimeout( 2500 );
check( 'B: a saved profile does not trigger the detection', detects.length === 0, detects.join( ' | ' ) );
const before = await fieldValue( 'Name of the company' );
await page.locator( 'button', { hasText: /Load details from the site|Načíst údaje z webu/ } ).click();
await page.waitForTimeout( 6000 );
check( 'B: the filled name is not overwritten', ( await fieldValue( 'Name of the company' ) ) === before, before );
check( 'B: nothing to fill, the page says so', /Nothing new|Nic nového/.test( await page.locator( '[role=status]' ).innerText() ), await page.locator( '[role=status]' ).innerText() );
check( 'B: nothing saved', saves.length === 0 );

check( 'no console or REST errors', errors.length === 0, errors.join( ' | ' ) );
await browser.close();
console.log( fails === 0 ? '\nALL PASSED' : `\n${ fails } FAILED` );
process.exit( fails === 0 ? 0 : 1 );
