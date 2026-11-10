/**
 * Browser QA of the consent module frontend (PLAN.md 42.17). Runs against a local test site, never a live one.
 *
 *   BASE=http://127.0.0.1/_uxs-e2e SHOTS=C:\Users\...\screenshots node tests/e2e/consent-frontend.mjs
 *
 * Needs on the site: module "consent" on, "pixel-tag-manager" with google_analytics = G-QATEST1234, and a page
 * /qa-embeds/ with a YouTube iframe, a Google Maps iframe, [uxstudio_cookie_settings] and a link to #uxc-open.
 * Network calls to Google and YouTube are stubbed, nothing leaves the machine. No credentials.
 */
import { chromium } from 'playwright';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const here = dirname( fileURLToPath( import.meta.url ) );
const AXE = readFileSync( resolve( here, '../../node_modules/axe-core/axe.min.js' ), 'utf8' );
mkdirSync( SHOTS, { recursive: true } );

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};

const browser = await chromium.launch();

async function ctx( opts = {} ) {
	const context = await browser.newContext( { viewport: { width: 1280, height: 800 }, ...opts } );
	const seen = { gtm: [], yt: [], maps: [], record: [], console: [] };
	await context.route( /googletagmanager\.com|google-analytics\.com|youtube(-nocookie)?\.com|google\.com\/maps/, ( route ) => {
		const u = route.request().url();
		if ( /googletagmanager|google-analytics/.test( u ) ) {
			seen.gtm.push( u );
			return route.fulfill( { contentType: 'text/javascript', body: 'window.__gtmLoaded=1;' } );
		}
		( /maps/.test( u ) ? seen.maps : seen.yt ).push( u );
		return route.fulfill( { contentType: 'text/html', body: '<html><body>stub</body></html>' } );
	} );
	const page = await context.newPage();
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			seen.console.push( m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => seen.console.push( 'pageerror: ' + e.message ) );
	page.on( 'request', ( r ) => {
		if ( /consent\/record/.test( r.url() ) ) {
			seen.record.push( r.postData() );
		}
	} );
	return { context, page, seen };
}

const axeRun = async ( page, label ) => {
	await page.evaluate( AXE );
	const res = await page.evaluate( async () => {
		const r = await window.axe.run( { include: [ [ '.uxc' ], [ '.uxc-fab' ] ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] } } );
		return r.violations.map( ( v ) => ( { id: v.id, impact: v.impact, n: v.nodes.length, target: v.nodes.slice( 0, 2 ).map( ( n ) => n.target.join( ' ' ) ) } ) );
	} );
	const bad = res.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	check( 'axe ' + label + ' (serious/critical)', bad.length === 0, JSON.stringify( bad ) );
	const minor = res.filter( ( v ) => v.impact !== 'serious' && v.impact !== 'critical' );
	if ( minor.length ) {
		console.log( 'INFO axe ' + label + ' minor/moderate: ' + JSON.stringify( minor ) );
	}
};

const cookieNames = async ( context ) => ( await context.cookies() ).map( ( c ) => c.name );
const URL_ = BASE + '/qa-embeds/';

// ---------- 1. Banner, desktop ----------
{
	const { context, page, seen } = await ctx();
	await page.goto( URL_, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.uxc .uxc-dlg', { state: 'visible' } );
	check( 'banner visible on first visit', true );
	check( 'banner is a dialog with label', await page.locator( '.uxc-dlg[role=dialog][aria-labelledby=uxc-t]' ).count() === 1 );
	await page.screenshot( { path: SHOTS + '\\uxc-desktop-banner.png' } );
	await axeRun( page, 'banner open (desktop)' );

	// Blocking before consent
	check( 'GA not requested before consent', seen.gtm.length === 0, seen.gtm.join() );
	check( 'no _ga cookie before consent', ! ( await cookieNames( context ) ).some( ( n ) => /^_ga/.test( n ) ) );
	check( 'GA script held back as text/plain', await page.locator( 'script[type="text/plain"][data-uxc=statistics]' ).count() >= 1 );
	check( 'iframes replaced by placeholders', await page.locator( '.uxc-ph' ).count() === 2, String( await page.locator( '.uxc-ph' ).count() ) );
	check( 'no iframe requests before consent', seen.yt.length === 0 && seen.maps.length === 0 );
	const ytSrc = await page.locator( 'iframe[data-uxc-service]' ).first().getAttribute( 'data-uxc-src' ).catch( () => null );
	console.log( 'INFO first held iframe data-uxc-src = ' + ytSrc );
	check( 'YouTube goes through youtube-nocookie', /youtube-nocookie\.com/.test( ytSrc || '' ), String( ytSrc ) );
	check( 'before choice: only a "none" uxc_consent event, nothing granted', await page.evaluate( () => ( window.dataLayer || [] ).filter( ( e ) => e && e.event === 'uxc_consent' ).every( ( e ) => e.uxc_source === 'none' && ! e.uxc_statistics && ! e.uxc_marketing ) ) );

	// Detail view, focus trap, Escape
	await page.getByRole( 'button', { name: /nastav|settings|customi/i } ).first().click();
	await page.waitForSelector( '.uxc-detail', { state: 'visible' } );
	check( 'detail: aria-modal true', await page.locator( '.uxc-dlg' ).getAttribute( 'aria-modal' ) === 'true' );
	check( 'detail: heading focused', await page.evaluate( () => document.activeElement && document.activeElement.id === 'uxc-t2' ) );
	// Tab wraps inside the dialog
	const inside = [];
	for ( let i = 0; i < 14; i++ ) {
		await page.keyboard.press( 'Tab' );
		inside.push( await page.evaluate( () => !! ( document.activeElement && document.activeElement.closest( '.uxc' ) ) ) );
	}
	check( 'focus trap: Tab stays in dialog (14 presses)', inside.every( Boolean ), inside.join() );
	await page.keyboard.press( 'Shift+Tab' );
	check( 'focus trap: Shift+Tab stays in dialog', await page.evaluate( () => !! document.activeElement.closest( '.uxc' ) ) );
	// services + cookies
	const det = page.locator( '.uxc-svc:has(ul)' ).first();
	const cookiesReq = page.waitForResponse( ( r ) => /consent\/cookies/.test( r.url() ), { timeout: 8000 } ).catch( () => null );
	await det.locator( 'summary' ).click();
	const cr = await cookiesReq;
	check( 'services expand loads consent/cookies', !! cr && cr.status() === 200, cr ? String( cr.status() ) : 'no request' );
	await page.waitForTimeout( 400 );
	check( 'service cookies rendered (.uxc-ck)', await page.locator( '.uxc-ck' ).count() > 0 );
	await page.locator( '.uxc-detail' ).screenshot( { path: SHOTS + '\\uxc-desktop-detail.png' } );
	await axeRun( page, 'banner detail (desktop)' );
	await page.keyboard.press( 'Escape' );
	check( 'Escape in detail returns to first layer', await page.locator( '.uxc-main' ).isVisible() );

	// Only statistics via detail
	await page.getByRole( 'button', { name: /nastav|settings|customi/i } ).first().click();
	await page.waitForSelector( '.uxc-detail', { state: 'visible' } );
	await page.locator( '.uxc-cat input[type=checkbox]:not([disabled])' ).nth( 1 ).check();
	await page.locator( '.uxc-detail .uxc-actions .uxc-alt' ).click();
	await page.waitForTimeout( 800 );
	check( 'banner hidden after save', ! ( await page.locator( '.uxc' ).isVisible() ) );
	check( 'consent cookie uxs_consent set', ( await cookieNames( context ) ).includes( 'uxs_consent' ) );
	const ck = ( await context.cookies() ).find( ( c ) => c.name === 'uxs_consent' );
	console.log( 'INFO uxs_consent = ' + ( ck && ck.value ) );
	check( 'GA requested after statistics consent', seen.gtm.length >= 1, String( seen.gtm.length ) );
	check( 'GA stub ran', await page.evaluate( () => window.__gtmLoaded === 1 ) );
	check( 'iframes still held (no marketing)', seen.yt.length === 0 && seen.maps.length === 0 );
	const dl = await page.evaluate( () => ( window.dataLayer || [] ).map( ( e ) => ( e && e[ 0 ] === 'consent' ? 'consent:' + e[ 1 ] : e && e.event ? 'ev:' + e.event : 'other' ) ) );
	console.log( 'INFO dataLayer order = ' + dl.join( ' | ' ) );
	const iUpd = dl.indexOf( 'consent:update' );
	const iEv = dl.indexOf( 'ev:uxc_consent', iUpd );
	check( 'consent update precedes uxc_consent event', iUpd >= 0 && iEv > iUpd, dl.join() );
	const upd = await page.evaluate( () => ( window.dataLayer || [] ).find( ( e ) => e && e[ 0 ] === 'consent' && e[ 1 ] === 'update' ) );
	check( 'update: analytics granted, ad denied', upd && upd[ 2 ].analytics_storage === 'granted' && upd[ 2 ].ad_storage === 'denied', JSON.stringify( upd ) );
	check( 'API: uxConsent.has', await page.evaluate( () => window.uxConsent.has( 'statistics' ) === true && window.uxConsent.has( 'marketing' ) === false ) );
	check( 'consent record sent (sendBeacon)', seen.record.length >= 1, String( seen.record.length ) );
	console.log( 'INFO record payload = ' + ( seen.record[ 0 ] || '' ).slice( 0, 200 ) );

	// FAB, reopen
	check( 'FAB visible after decision', await page.locator( '.uxc-fab' ).isVisible() );
	await page.screenshot( { path: SHOTS + '\\uxc-desktop-fab.png' } );
	await axeRun( page, 'banner closed + fab (desktop)' );
	await page.locator( '.uxc-fab' ).click();
	check( 'FAB reopens detail with id row', await page.locator( '.uxc-detail' ).isVisible() );
	const idTxt = await page.locator( '.uxc-detail .uxc-d' ).last().textContent();
	// last .uxc-d in detail is the id paragraph? (root._id appended after categories)
	const idRow = await page.evaluate( () => { const p = [ ...document.querySelectorAll( '.uxc-detail > p.uxc-d' ) ]; return p.map( ( x ) => x.textContent ); } );
	check( 'consent id row shown', idRow.some( ( t ) => /[0-9a-f]{32}/.test( t ) ), JSON.stringify( idRow ) + idTxt );
	check( 'statistics preselected on reopen', await page.locator( '.uxc-cat input[type=checkbox]:not([disabled])' ).nth( 1 ).isChecked() );
	await page.keyboard.press( 'Escape' );
	check( 'Escape closes after decision', ! ( await page.locator( '.uxc' ).isVisible() ) );
	// shortcode + #uxc-open link
	const sc = page.locator( '[data-uxc-open]' );
	console.log( 'INFO shortcode elements [data-uxc-open]: ' + await sc.count() );
	await page.locator( '#open-link' ).click();
	check( '#uxc-open link opens settings', await page.locator( '.uxc-detail' ).isVisible() );
	await page.keyboard.press( 'Escape' );
	if ( await sc.count() ) {
		await sc.first().click();
		check( 'shortcode button opens settings', await page.locator( '.uxc-detail' ).isVisible() );
		await page.keyboard.press( 'Escape' );
	} else {
		check( 'shortcode [uxstudio_cookie_settings] rendered', false );
	}

	// Marketing via API: iframes load only after "Load content"
	await page.evaluate( () => window.uxConsent.set( { preferences: false, statistics: true, marketing: true } ) );
	await page.waitForTimeout( 800 );
	check( 'marketing consent loads iframes (YouTube nocookie)', seen.yt.some( ( u ) => /youtube-nocookie/.test( u ) ), seen.yt.join() );
	check( 'marketing consent loads Google Maps', seen.maps.length >= 1, seen.maps.join() );

	// Revocation: reload and scripts do not run
	const gtmBefore = seen.gtm.length;
	await page.evaluate( () => window.uxConsent.rejectAll() );
	await page.waitForLoadState( 'load' );
	await page.waitForTimeout( 800 );
	check( 'revocation reloads page; GA not re-requested', seen.gtm.length === gtmBefore, gtmBefore + ' -> ' + seen.gtm.length );
	check( 'after revocation GA stub did not run', await page.evaluate( () => window.__gtmLoaded !== 1 ) );
	await page.waitForSelector( '.uxc-ph', { timeout: 5000 } ).catch( () => {} );
	check( 'after revocation iframes are placeholders again', await page.locator( '.uxc-ph' ).count() === 2, String( await page.locator( '.uxc-ph' ).count() ) );
	check( 'no console errors (desktop flow)', seen.console.length === 0, seen.console.join( ' // ' ) );
	await context.close();
}

// ---------- 2. Accept all / reject all, placeholder button ----------
{
	const { context, page, seen } = await ctx();
	await page.goto( URL_, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.uxc-main .uxc-actions', { state: 'visible' } );
	await page.locator( '.uxc-main .uxc-actions button' ).nth( 0 ).click(); // reject
	await page.waitForTimeout( 600 );
	check( 'reject all: no GA, no iframes', seen.gtm.length === 0 && seen.yt.length === 0 && seen.maps.length === 0 );
	check( 'reject all: no _ga cookie', ! ( await cookieNames( context ) ).some( ( n ) => /^_ga/.test( n ) ) );
	const upd = await page.evaluate( () => ( window.dataLayer || [] ).find( ( e ) => e && e[ 0 ] === 'consent' && e[ 1 ] === 'update' ) );
	check( 'reject all: consent update all denied', upd && Object.values( upd[ 2 ] ).every( ( v ) => v === 'denied' ), JSON.stringify( upd ) );
	// placeholder button loads one iframe only after marketing consent
	await page.locator( '.uxc-ph .uxc-btn' ).first().click();
	await page.waitForTimeout( 500 );
	console.log( 'INFO placeholder button without marketing consent: iframe requests yt=' + seen.yt.length + ' maps=' + seen.maps.length + ' banner visible=' + ( await page.locator( '.uxc' ).isVisible() ) );
	await context.close();

	const b = await ctx();
	await b.page.goto( URL_, { waitUntil: 'networkidle' } );
	await b.page.waitForSelector( '.uxc-main .uxc-actions', { state: 'visible' } );
	await b.page.locator( '.uxc-main .uxc-actions button' ).nth( 1 ).click(); // accept
	await b.page.waitForTimeout( 800 );
	check( 'accept all: GA loaded', b.seen.gtm.length >= 1 );
	check( 'accept all: iframes load', b.seen.yt.length >= 1 && b.seen.maps.length >= 1, `yt=${ b.seen.yt.length } maps=${ b.seen.maps.length }` );
	const upd2 = await b.page.evaluate( () => ( window.dataLayer || [] ).find( ( e ) => e && e[ 0 ] === 'consent' && e[ 1 ] === 'update' ) );
	check( 'accept all: update granted', upd2 && upd2[ 2 ].ad_storage === 'granted' && upd2[ 2 ].analytics_storage === 'granted', JSON.stringify( upd2 ) );
	await b.context.close();

	// API on a fresh visit
	const c = await ctx();
	await c.page.goto( URL_, { waitUntil: 'networkidle' } );
	await c.page.evaluate( () => window.uxConsent.acceptAll() );
	await c.page.waitForTimeout( 600 );
	check( 'API acceptAll sets cookie + GA', ( await cookieNames( c.context ) ).includes( 'uxs_consent' ) && c.seen.gtm.length >= 1 );
	await c.context.close();
}

// ---------- 3. Mobile 390 px ----------
{
	const { context, page, seen } = await ctx( { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } );
	await page.goto( URL_, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.uxc .uxc-dlg', { state: 'visible' } );
	await page.screenshot( { path: SHOTS + '\\uxc-mobile-banner.png' } );
	const box = await page.locator( '.uxc-dlg' ).boundingBox();
	check( 'mobile: dialog within viewport width', box && box.x >= 0 && box.x + box.width <= 391, JSON.stringify( box ) );
	const noOverflow = await page.evaluate( () => document.documentElement.scrollWidth <= 391 );
	check( 'mobile: no horizontal overflow', noOverflow );
	const btns = await page.locator( '.uxc-main .uxc-actions button' ).evaluateAll( ( bs ) => bs.map( ( b ) => { const r = b.getBoundingClientRect(); return [ Math.round( r.width ), Math.round( r.height ) ]; } ) );
	check( 'mobile: tap targets >= 24px high', btns.every( ( s ) => s[ 1 ] >= 24 ), JSON.stringify( btns ) );
	await axeRun( page, 'banner open (mobile)' );
	await page.getByRole( 'button', { name: /nastav|settings|customi/i } ).first().click();
	await page.waitForSelector( '.uxc-detail', { state: 'visible' } );
	await page.screenshot( { path: SHOTS + '\\uxc-mobile-detail.png' } );
	const dbox = await page.locator( '.uxc-dlg' ).boundingBox();
	check( 'mobile: detail fits the viewport height', dbox && dbox.y >= 0 && dbox.y + dbox.height <= 845, JSON.stringify( dbox ) );
	const saveVisible = await page.locator( '.uxc-detail .uxc-actions button' ).last().isVisible();
	check( 'mobile: detail action buttons reachable', saveVisible );
	await axeRun( page, 'banner detail (mobile)' );
	await page.locator( '.uxc-detail .uxc-actions button' ).first().click();
	await page.waitForTimeout( 500 );
	check( 'mobile: no console errors', seen.console.length === 0, seen.console.join( ' // ' ) );
	await context.close();
}

// ---------- 4. Region endpoint (CF-IPCountry) ----------
{
	const region = async ( code ) => {
		const { context, page } = await ctx( code ? { extraHTTPHeaders: { 'CF-IPCountry': code } } : {} );
		const r = await context.request.get( BASE + '/wp-json/uxstudio/v1/consent/region' ).catch( () => null );
		let body = null;
		if ( r ) {
			body = await r.text();
		}
		await context.close();
		return { status: r && r.status(), body };
	};
	for ( const [ code, expect ] of [ [ 'CZ', true ], [ 'US', true ], [ '', true ] ] ) {
		const r = await region( code );
		console.log( 'INFO region header=' + ( code || '-' ) + ' (mode all) -> ' + r.status + ' ' + r.body );
	}
}

await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
