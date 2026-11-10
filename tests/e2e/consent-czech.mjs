/**
 * Browser QA (PLAN.md 42.17): with the site and the user set to cs_CZ the banner and the module pages are Czech.
 * Prints every visible string that still looks English. Local test site only.
 *
 *   BASE=... UXS_ADMIN_USER=e2e UXS_ADMIN_PASSWORD=... node tests/e2e/consent-czech.mjs
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
const browser = await chromium.launch();
const c = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
await c.route( /googletagmanager|youtube|google\.com\/maps/, ( r ) => r.fulfill( { body: '' } ) );
const fp = await c.newPage();
await fp.goto( BASE + '/qa-embeds/', { waitUntil: 'networkidle' } );
await fp.waitForSelector( '.uxc-dlg' );
const banner = await fp.locator( '.uxc' ).innerText();
console.log( 'INFO banner: ' + banner.replace( /\s+/g, ' ' ).slice( 0, 300 ) );
check( 'banner is Czech', /cookies|souhlas|Přijmout|Odmítnout/i.test( banner ) && /Přijmout|Odmítnout/.test( banner ) );
await fp.screenshot( { path: SHOTS + '/uxc-cs-banner.png' } );
await fp.getByRole( 'button', { name: /Nastavení/i } ).first().click();
const detail = await fp.locator( '.uxc-detail' ).innerText();
check( 'detail is Czech', /Nezbytné|Statistické|Marketingové|Statistiky/i.test( detail ) && ! /Necessary/.test( detail ), detail.slice( 0, 200 ) );
check( 'placeholder is Czech', /Načíst obsah/.test( await fp.locator( '.uxc-ph' ).first().innerText() ) );

const p = await c.newPage();
const errors = [];
p.on( 'pageerror', ( e ) => errors.push( e.message ) );
await p.goto( BASE + '/wp-login.php' );
await p.fill( '#user_login', USER );
await p.fill( '#user_pass', PASS );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
await p.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'networkidle' } );
await p.waitForSelector( '.uxs-pagehead h1' );
const names = await p.evaluate( () => {
	const first = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) => /^Zobrazení$/.test( b.textContent.trim() ) );
	return first ? [ ...first.parentElement.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent.trim() ) : [];
} );
console.log( 'INFO tabs: ' + names.join( ' | ' ) );
check( '9 Czech tabs', names.length === 9 );
// a crude English detector on the visible text of every tab
const ENGLISH = /\b(the|and|with|your|you|this|that|which|have|for)\b/g;
for ( let i = 0; i < names.length; i++ ) {
	await p.evaluate( ( label ) => [ ...document.querySelectorAll( 'button' ) ].find( ( x ) => x.textContent.trim() === label ).click(), names[ i ] );
	await p.waitForTimeout( 1200 );
	await p.screenshot( { path: `${ SHOTS }/uxc-cs-tab${ i }.png`, fullPage: true } );
	const lines = ( await p.locator( '.uxs-pagehead ~ *' ).allInnerTexts() ).join( '\n' ).split( '\n' ).map( ( l ) => l.trim() ).filter( Boolean );
	const eng = lines.filter( ( l ) => ( l.match( ENGLISH ) || [] ).length >= 2 && ! /[ěščřžýáíéúůďťňĚŠČŘŽÝÁÍÉÚŮĎŤŇ]/.test( l ) );
	check( 'tab ' + names[ i ] + ': no English-looking lines', eng.length === 0, eng.slice( 0, 4 ).join( ' // ' ) );
}
check( 'no page errors', errors.length === 0, errors.join( ' // ' ) );
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
