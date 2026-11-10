/**
 * Browser QA (PLAN.md 42.17): the legal pages wizard, the pages on the frontend and the WooCommerce checkout that stores
 * the terms version on the order. Local test site with WooCommerce (COD + BACS, a virtual product "QA produkt") only.
 *
 *   BASE=http://127.0.0.1/_uxs-e2e UXS_ADMIN_USER=e2e UXS_ADMIN_PASSWORD=... node tests/e2e/consent-legal-shop.mjs
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

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
const context = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
const page = await context.newPage();
const errors = [];
page.on( 'pageerror', ( e ) => errors.push( 'pageerror: ' + e.message ) );
page.on( 'response', ( r ) => {
	if ( r.status() >= 400 && /wp-json|rest_route/.test( r.url() ) ) {
		errors.push( r.status() + ' ' + r.url() );
	}
} );

await page.goto( BASE + '/wp-login.php' );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

// ---------- legal wizard ----------
await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle' } );
await page.waitForSelector( '.uxs-pagehead h1' );
const openTab = async ( re ) => {
	await page.evaluate( ( src ) => {
		const rx = new RegExp( src );
		[ ...document.querySelectorAll( 'button' ) ].find( ( x ) => rx.test( x.textContent.trim() ) && x.parentElement.querySelectorAll( 'button' ).length === 9 ).click();
	}, re );
	await page.waitForTimeout( 900 );
};
await openTab( '^(Legal pages|Právní stránky)$' );
const fill = async ( label, value ) => {
	const f = page.locator( 'label', { hasText: label } ).locator( 'input[type=text], input:not([type]), textarea' ).first();
	await f.fill( value );
};
await fill( 'Name of the company or person', 'QA Testovací s.r.o.' );
await fill( 'Company number', '12345678' );
await fill( 'Address of the seat', 'Testovací 1, 110 00 Praha 1' );
await fill( 'E-mail for questions about personal data', 'gdpr@example.com' );
await fill( 'Supervisory authority', 'Úřad pro ochranu osobních údajů' );
await fill( 'Address of its website', 'https://uoou.gov.cz' );
await page.locator( 'label', { hasText: 'We run an online shop' } ).locator( 'input' ).check();
await page.waitForTimeout( 400 );
// tick the first purposes so the "purposes" validation passes
const purposeBoxes = page.locator( '.uxs-form__row label input[type=checkbox]' );
console.log( 'INFO checkboxes in form: ' + await purposeBoxes.count() );
await page.screenshot( { path: SHOTS + '\\uxc-legal-form.png', fullPage: true } );
const saveBtn = page.getByRole( 'button', { name: /Save details|Uložit údaje/i } );
if ( await saveBtn.isEnabled() ) {
	await saveBtn.click();
}
await page.waitForTimeout( 1500 );
const body1 = await page.locator( '#ux-studio-root' ).innerText();
const stillDirty = /Unsaved changes|Neuložené/i.test( body1 );
const inv = /missing or not valid|chybí|nejsou platné/i.test( body1 );
console.log( 'INFO after save: dirty=' + stillDirty + ' invalid=' + inv );
if ( inv ) {
	const errs = await page.locator( '.uxs-badge.is-danger, .uxs-form__error' ).allInnerTexts();
	console.log( 'INFO errors: ' + JSON.stringify( errs ) );
}
await page.screenshot( { path: SHOTS + '\\uxc-legal-saved.png', fullPage: true } );
check( 'legal details saved', ! stillDirty && ! inv );

// preview
const previewBtn = page.getByRole( 'button', { name: /Preview|Náhled/i } ).first();
await previewBtn.click();
await page.waitForTimeout( 1500 );
check( 'preview renders text', ( await page.locator( '#ux-studio-root' ).innerText() ).length > 1500 );
await page.screenshot( { path: SHOTS + '\\uxc-legal-preview.png', fullPage: true } );
const closePrev = page.getByRole( 'button', { name: /Close preview|Zavřít náhled/i } ).first();
if ( await closePrev.count() ) {
	await closePrev.click();
}
await page.waitForTimeout( 400 );

// make pages (select all available, publish)
for ( const cb of await page.locator( 'table.uxs-table input[type=checkbox]:not([disabled])' ).all() ) {
	await cb.check();
}
await page.locator( 'label', { hasText: /Publish the pages|Zveřejnit/ } ).locator( 'input' ).check();
await page.getByRole( 'button', { name: /Make or update the selected pages|Vytvořit nebo/i } ).click();
await page.waitForTimeout( 3000 );
await page.screenshot( { path: SHOTS + '\\uxc-legal-published.png', fullPage: true } );
const links = await page.locator( 'table.uxs-table a[target=_blank]' ).evaluateAll( ( a ) => a.map( ( x ) => x.href ) );
console.log( 'INFO page links: ' + links.join( ' ' ) );
check( 'three legal pages published', links.length >= 3, String( links.length ) );
check( 'no REST errors in wizard', errors.length === 0, errors.join( ' // ' ) );

// frontend of the pages
for ( const href of links ) {
	const p2 = await context.newPage();
	const resp = await p2.goto( href, { waitUntil: 'networkidle' } );
	const text = await p2.locator( 'main, body' ).first().innerText();
	check( 'legal page 200 and has content: ' + href, resp.status() === 200 && text.length > 1500, String( text.length ) );
	if ( /cookie/.test( href ) ) {
		check( 'cookie policy shows cookies table', await p2.locator( 'table' ).count() >= 1 );
		check( 'cookie policy mentions _ga', /_ga/.test( text ) );
		check( 'cookie policy has settings link/button', await p2.locator( '[data-uxc-open], a[href="#uxc-open"]' ).count() >= 1 );
	}
	await p2.screenshot( { path: SHOTS + '\\uxc-legal-front-' + href.split( '/' ).filter( Boolean ).pop() + '.png', fullPage: true } );
	await p2.close();
}

// history
const histBtn = page.getByRole( 'button', { name: /History|Historie/i } ).first();
if ( await histBtn.isEnabled() ) {
	await histBtn.click();
	await page.waitForTimeout( 800 );
	check( 'history shows a version', /(Version|Verze) [0-9]/.test( await page.locator( '#ux-studio-root' ).innerText() ) );
}

// ---------- WooCommerce checkout (classic and block) ----------
const order = async ( checkoutPath, label ) => {
	const c2 = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
	const p = await c2.newPage();
	await p.route( /googletagmanager|youtube|google\.com\/maps/, ( r ) => r.fulfill( { body: '' } ) );
	await p.goto( BASE + '/?post_type=product&add-to-cart=16', { waitUntil: 'networkidle' } );
	await p.goto( BASE + checkoutPath, { waitUntil: 'networkidle' } );
	await p.waitForTimeout( 1500 );
	// accept banner so it does not cover the button
	const acc = p.locator( '.uxc-main .uxc-actions button' ).nth( 1 );
	if ( await acc.isVisible().catch( () => false ) ) {
		await acc.click();
	}
	const set = async ( sel, v ) => {
		const f = p.locator( sel ).first();
		if ( await f.count() ) {
			await f.fill( v );
		}
	};
	await set( '#billing_first_name, #billing-first_name', 'Jan' );
	await set( '#billing_last_name, #billing-last_name', 'Test' );
	await set( '#billing_address_1, #billing-address_1', 'Testovaci 1' );
	await set( '#billing_city, #billing-city', 'Praha' );
	await set( '#billing_postcode, #billing-postcode', '11000' );
	await set( '#billing_phone, #billing-phone', '777123456' );
	await set( '#billing_email, #email', 'qa@example.com' );
	await p.screenshot( { path: `${ SHOTS }\\uxc-shop-${ label }-form.png`, fullPage: true } );
	const terms = p.locator( '#terms, input[name=terms]' );
	console.log( `INFO ${ label }: terms checkbox count ${ await terms.count() }` );
	if ( await terms.count() ) {
		const href = await p.locator( '.woocommerce-terms-and-conditions-link' ).first().getAttribute( 'href' ).catch( () => null );
		console.log( `INFO ${ label }: terms link ${ href }` );
		await terms.first().check();
	}
	// choose COD
	const cod = p.locator( '#payment_method_cod, input[value=cod]' ).first();
	if ( await cod.count() ) {
		await cod.check().catch( () => {} );
	}
	const btn = p.locator( '#place_order, .wc-block-components-checkout-place-order-button' ).first();
	await btn.click();
	await p.waitForURL( /order-received/, { timeout: 60000 } ).catch( () => {} );
	const url = p.url();
	await p.screenshot( { path: `${ SHOTS }\\uxc-shop-${ label }-after.png`, fullPage: true } );
	console.log( `INFO ${ label }: after place order url ${ url }` );
	const ok = /order-received/.test( url );
	check( `${ label }: order placed`, ok, ( await p.locator( '.woocommerce-error, .wc-block-components-notice-banner' ).allInnerTexts().catch( () => [] ) ).join( ' | ' ) );
	await c2.close();
	return ok;
};
await order( '/pokladna-klasicka/', 'classic' );
await order( '/checkout/', 'block' );

// order detail in the admin shows the terms version
for ( const id of [ 30, 31 ] ) {
	await page.goto( `${ BASE }/wp-admin/post.php?post=${ id }&action=edit`, { waitUntil: 'networkidle' } );
	const t = await page.locator( '#order_data' ).innerText().catch( () => '' );
	check( `order ${ id } admin detail shows terms version`, /terms|podmín/i.test( t ) && /(version|verze) [0-9]+/i.test( t ), t.slice( 0, 300 ).replace( /\s+/g, ' ' ) );
	await page.locator( '#order_data' ).screenshot( { path: `${ SHOTS }/uxc-shop-order-${ id }.png` } ).catch( () => {} );
}
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
