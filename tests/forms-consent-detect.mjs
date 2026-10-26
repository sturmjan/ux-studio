// Test only (Node): `node tests/forms-consent-detect.mjs`
// Loads the very file PublicRenderer inlines into the public runtime and feeds it stubbed globals.
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );
const detect = require( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'assets', 'js', 'forms-consent-detect.js' ) );

let failures = 0;
function check( name, actual, expected ) {
	const ok = actual === expected;
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + String( actual ) + ', expected ' + String( expected ) ) );
}

// gtag() pushes an Arguments object, so the stubs do the same.
function gtagArgs() { return arguments; }
const gtag = ( ...a ) => gtagArgs( ...a );
const win = ( extra = {}, cookie = '' ) => ( { document: { cookie }, ...extra } );

check( 'no consent tool: null', detect( win() ), null );
check( 'a window without document does not throw', detect( {} ), null );

check( 'Complianz function true: 1', detect( win( { cmplz_has_consent: ( c ) => c === 'marketing' } ) ), 1 );
check( 'Complianz function false: 0', detect( win( { cmplz_has_consent: () => false } ) ), 0 );
check( 'Complianz cookie allow: 1', detect( win( {}, 'a=b; cmplz_marketing=allow; x=y' ) ), 1 );
check( 'Complianz cookie deny: 0', detect( win( {}, 'cmplz_marketing=deny' ) ), 0 );
check( 'Complianz cookie empty: null', detect( win( {}, 'cmplz_marketing=' ) ), null );

check( 'CookieYes advertisement true: 1', detect( win( { getCkyConsent: () => ( { categories: { advertisement: true } } ) } ) ), 1 );
check( 'CookieYes advertisement false: 0', detect( win( { getCkyConsent: () => ( { categories: { advertisement: false } } ) } ) ), 0 );
check( 'CookieYes throws before the banner loaded: null', detect( win( { getCkyConsent: () => { throw new Error( 'not ready' ); } } ) ), null );

check( 'Cookiebot marketing true: 1', detect( win( { Cookiebot: { consent: { marketing: true } } } ) ), 1 );
check( 'Cookiebot marketing false: 0', detect( win( { Cookiebot: { consent: { marketing: false } } } ) ), 0 );
check( 'Cookiebot without consent object: null', detect( win( { Cookiebot: {} } ) ), null );

check( 'Consent Mode default denied: 0', detect( win( { dataLayer: [ gtag( 'consent', 'default', { ad_storage: 'denied' } ) ] } ) ), 0 );
check( 'Consent Mode default denied then update granted: 1', detect( win( { dataLayer: [ gtag( 'consent', 'default', { ad_storage: 'denied' } ), { event: 'x' }, gtag( 'consent', 'update', { ad_storage: 'granted' } ) ] } ) ), 1 );
check( 'Consent Mode update granted then revoked: 0', detect( win( { dataLayer: [ gtag( 'consent', 'update', { ad_storage: 'granted' } ), gtag( 'consent', 'update', { ad_storage: 'denied' } ) ] } ) ), 0 );
check( 'Consent Mode without ad_storage: null', detect( win( { dataLayer: [ gtag( 'consent', 'default', { analytics_storage: 'granted' } ) ] } ) ), null );
check( 'Consent Mode with plain GTM objects only: null', detect( win( { dataLayer: [ { event: 'gtm.js' } ] } ) ), null );

check( 'uxstudioConsent.marketing true: 1', detect( win( { uxstudioConsent: { marketing: true } } ) ), 1 );
check( 'uxstudioConsent.marketing false: 0', detect( win( { uxstudioConsent: { marketing: false } } ) ), 0 );
check( 'uxstudioConsent with a non-boolean: null', detect( win( { uxstudioConsent: { marketing: 'yes' } } ) ), null );

check( 'order: Complianz wins over CookieYes', detect( win( { cmplz_has_consent: () => false, getCkyConsent: () => ( { categories: { advertisement: true } } ) } ) ), 0 );
check( 'order: a broken first probe falls through to the next', detect( win( { cmplz_has_consent: () => { throw new Error( 'x' ); }, Cookiebot: { consent: { marketing: true } } } ) ), 1 );
check( 'order: Cookiebot wins over Consent Mode', detect( win( { Cookiebot: { consent: { marketing: false } }, dataLayer: [ gtag( 'consent', 'update', { ad_storage: 'granted' } ) ] } ) ), 0 );
check( 'order: Consent Mode wins over uxstudioConsent', detect( win( { dataLayer: [ gtag( 'consent', 'update', { ad_storage: 'granted' } ) ], uxstudioConsent: { marketing: false } } ) ), 1 );

console.log( failures === 0 ? 'ALL OK' : failures + ' FAILED' );
process.exit( failures === 0 ? 0 : 1 );
