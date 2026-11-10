/**
 * Browser QA (PLAN.md 42.17): "show the banner only in the EU" with a mocked CF-IPCountry header. Local test site only.
 * Set the module option first:  wp eval 'update_option("uxstudio_consent", array_merge(get_option("uxstudio_consent",[]), ["display_to"=>"eu"]));'
 *
 *   BASE=http://127.0.0.1/_uxs-e2e node tests/e2e/consent-region.mjs
 */
import { chromium } from 'playwright';

const BASE = ( process.env.BASE || 'http://127.0.0.1/_uxs-e2e' ).replace( /\/$/, '' );
let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};
const browser = await chromium.launch();
for ( const [ country, banner ] of [ [ 'CZ', true ], [ 'DE', true ], [ 'US', false ], [ 'XX', true ], [ '', true ] ] ) {
	const c = await browser.newContext( country ? { extraHTTPHeaders: { 'CF-IPCountry': country } } : {} );
	const gtm = [];
	await c.route( /googletagmanager/, ( r ) => {
		gtm.push( r.request().url() );
		return r.fulfill( { contentType: 'text/javascript', body: '' } );
	} );
	const p = await c.newPage();
	await p.goto( BASE + '/qa-embeds/', { waitUntil: 'networkidle' } );
	await p.waitForTimeout( 1200 );
	const shown = await p.locator( '.uxc .uxc-dlg' ).isVisible().catch( () => false );
	check( `country ${ country || '(none)' }: banner ${ banner ? 'shown' : 'hidden' }`, shown === banner );
	if ( ! banner ) {
		check( 'outside the region: statistics script runs without a choice', gtm.length >= 1, String( gtm.length ) );
		check( 'outside the region: no consent cookie written', ! ( await c.cookies() ).some( ( k ) => k.name === 'uxs_consent' ) );
	} else {
		check( `country ${ country || '(none)' }: nothing loaded before a choice`, gtm.length === 0 );
	}
	await c.close();
}
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
