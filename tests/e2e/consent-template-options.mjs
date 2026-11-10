/**
 * Browser QA of the options of one banner template (PLAN.md 42.22). Local test site only.
 *
 *   PHP=D:/xampp/php/php.exe node tests/e2e/consent-template-options.mjs
 *
 * Each variant is written with WP-CLI as the saved settings of the module (the page cache purged), then the front page is
 * opened on a desktop and a 390 px wide screen: geometry of the banner, the option shows, no horizontal overflow, reject and
 * accept equally big, axe-core finds nothing serious or critical. Screenshots go to SHOTS (uxc-tplopt-<variant>-<size>.png).
 * At the end the saved settings are removed again (the test site had none); set KEEP=1 to leave the last variant.
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const BASE = ( process.env.BASE || 'http://localhost/hydro-izol' ).replace( /\/$/, '' );
const WP_PATH = process.env.WP_PATH || 'D:/xampp/htdocs/hydro-izol';
const WPCLI = process.env.WPCLI || 'D:/xampp/htdocs/pobyty/wp-cli.phar';
const PHP = process.env.PHP || 'D:/xampp/php/php.exe';
const SETTER = resolve( here, 'consent-template-options-set.php' );
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const AXE = readFileSync( resolve( here, '../../node_modules/axe-core/axe.min.js' ), 'utf8' );
mkdirSync( SHOTS, { recursive: true } );

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};
const save = ( opts ) =>
	execFileSync( PHP, [ WPCLI, '--path=' + WP_PATH, 'eval-file', SETTER ], {
		env: { ...process.env, UXC_OPTS: opts === 'DELETE' ? opts : JSON.stringify( opts ) },
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		timeout: 180000,
	} );

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ];
const axeBad = ( page ) =>
	page.evaluate( async ( tags ) => {
		const r = await window.axe.run( { include: [ [ '.uxc' ] ] }, { runOnly: { type: 'tag', values: tags } } );
		return r.violations.filter( ( x ) => x.impact === 'serious' || x.impact === 'critical' ).map( ( x ) => ( { id: x.id, impact: x.impact, n: x.nodes.length } ) );
	}, TAGS );

const variants = [
	{ name: 'tabs-full', o: { template: 'cookiebot', tabs_place: 'full', tabs_width: 1000 }, test: async ( p, d, s ) => {
		if ( s === 'desktop' ) {
			check( 'tabs-full: spans the whole width and sits on the bottom edge', d.width >= 1279 && Math.abs( d.y + d.height - 800 ) < 2, JSON.stringify( d ) );
			const inner = await p.evaluate( () => {
				const c = getComputedStyle( document.querySelector( '.uxc-main' ) );
				return document.querySelector( '.uxc-main' ).clientWidth - parseFloat( c.paddingLeft ) - parseFloat( c.paddingRight );
			} );
			check( 'tabs-full: the content keeps the chosen width (1000 px)', Math.abs( inner - 1000 ) < 2, String( inner ) );
		}
		check( 'tabs-full: three tabs', ( await p.locator( '.uxc-tab' ).count() ) === 3 );
	} },
	{ name: 'tabs-narrow-noabout', o: { template: 'cookiebot', tabs_width: 600, tabs_about: false }, test: async ( p, d, s ) => {
		if ( s === 'desktop' ) {
			check( 'tabs-narrow: panel is 600 px wide and centred', Math.abs( d.width - 600 ) < 2 && Math.abs( d.x + d.width / 2 - 640 ) < 2, JSON.stringify( d ) );
		}
		check( 'tabs-narrow: two tabs, no About panel', ( await p.locator( '.uxc-tab' ).count() ) === 2 && ( await p.locator( '.uxc-about' ).count() ) === 0 );
		await p.locator( '.uxc-tab[aria-selected=true]' ).focus();
		await p.keyboard.press( 'ArrowRight' );
		check( 'tabs-narrow: the arrow key moves between the two tabs', ( await p.locator( '#uxc-tab-detail' ).getAttribute( 'aria-selected' ) ) === 'true' );
		await p.keyboard.press( 'ArrowLeft' );
	} },
	{ name: 'card-right-rect', o: { template: 'cookie', position: 'right', card_shape: 'rect', card_icon: false, card_radius: 8 }, test: async ( p, d, s ) => {
		if ( s === 'desktop' ) {
			check( 'card-right: sits at the bottom right', d.x + d.width > 1280 - 40 && d.x > 700, JSON.stringify( d ) );
		}
		check( 'card-right: no cookie icon in the banner', ( await p.locator( '.uxc-dlg .uxc-ic' ).count() ) === 0 );
		const r = await p.evaluate( () => getComputedStyle( document.querySelector( '.uxc-dlg' ) ).borderTopLeftRadius );
		check( 'card-right: the corner radius is 8 px', r === '8px', r );
		const no = await p.locator( '.uxc-dlg .uxc-no' ).first().boundingBox();
		const yes = await p.locator( '.uxc-dlg .uxc-yes' ).first().boundingBox();
		const br = await p.evaluate( () => getComputedStyle( document.querySelector( '.uxc-dlg .uxc-no' ) ).borderTopLeftRadius );
		check( 'card-right: rectangular buttons, reject and accept the same height and a similar width', br === '10px' && Math.abs( no.height - yes.height ) < 2 && Math.max( no.width, yes.width ) / Math.min( no.width, yes.width ) < 1.4, JSON.stringify( { br, no, yes } ) );
		const st = await p.evaluate( () => {
			const g = ( q ) => getComputedStyle( document.querySelector( q ) );
			return [ g( '.uxc-dlg .uxc-no' ).backgroundColor, g( '.uxc-dlg .uxc-yes' ).backgroundColor ];
		} );
		check( 'card-right: both buttons are solid', st.every( ( c ) => ! /rgba\(0, 0, 0, 0\)|transparent/.test( c ) ), JSON.stringify( st ) );
	} },
	{ name: 'card-round-radius40', o: { template: 'cookie', card_radius: 40 }, test: async ( p ) => {
		const r = await p.evaluate( () => getComputedStyle( document.querySelector( '.uxc-dlg' ) ).borderTopLeftRadius );
		check( 'card-round: the corner radius is 40 px', r === '40px', r );
		check( 'card-round: the icon is there', ( await p.locator( '.uxc-dlg .uxc-ic' ).count() ) === 1 );
		const no = await p.locator( '.uxc-dlg .uxc-no' ).first().boundingBox();
		check( 'card-round: the buttons are round (68 px high)', Math.abs( no.height - 68 ) < 2, JSON.stringify( no ) );
	} },
	{ name: 'slim-top-nolink', o: { template: 'slim', slim_place: 'top', slim_link: false, policy_url: '/privacy-policy/' }, test: async ( p, d ) => {
		check( 'slim-top: the bar is on the top edge and spans the width', d.y < 2 && d.width >= 389, JSON.stringify( d ) );
		check( 'slim-top: no policy link', ( await p.locator( '.uxc-dlg .uxc-a' ).count() ) === 0 );
	} },
	{ name: 'slim-bottom-link', o: { template: 'slim', policy_url: '/privacy-policy/' }, test: async ( p, d ) => {
		check( 'slim-bottom: the bar is on the bottom edge', d.y > 300, JSON.stringify( d ) );
		check( 'slim-bottom: the policy link is shown', ( await p.locator( '.uxc-dlg .uxc-a' ).count() ) === 1 );
	} },
	{ name: 'classic-unchanged', o: { template: 'classic', layout: 'bar', radius: 16 }, test: async ( p, d, s ) => {
		check( 'classic: a bar across the bottom (unchanged)', d.width >= ( s === 'desktop' ? 1279 : 389 ) && d.y > 300, JSON.stringify( d ) );
	} },
];

const browser = await chromium.launch();
const errors = [];
try {
	for ( const v of variants ) {
		save( v.o );
		for ( const [ size, viewport ] of [ [ 'desktop', { width: 1280, height: 800 } ], [ 'mobile', { width: 390, height: 844 } ] ] ) {
			const tag = `${ v.name } ${ size }`;
			const context = await browser.newContext( { viewport, isMobile: size === 'mobile', hasTouch: size === 'mobile' } );
			const page = await context.newPage();
			page.on( 'pageerror', ( e ) => errors.push( tag + ': ' + e.message ) );
			await page.goto( BASE + '/', { waitUntil: 'load' } );
			await page.waitForSelector( '.uxc:not([hidden]) .uxc-dlg', { timeout: 10000 } );
			await page.waitForTimeout( 250 );
			await page.screenshot( { path: `${ SHOTS }\\uxc-tplopt-${ v.name }-${ size }.png` } );
			const dlg = await page.locator( '.uxc-dlg' ).boundingBox();
			const overflow = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
			check( `${ tag }: no horizontal overflow, banner inside the screen`, overflow <= 0 && dlg.x >= -0.5 && dlg.x + dlg.width <= viewport.width + 0.5 && dlg.y >= -0.5 && dlg.y + dlg.height <= viewport.height + 0.5, JSON.stringify( { overflow, dlg } ) );
			await v.test( page, dlg, size );
			await page.evaluate( AXE );
			const bad = await axeBad( page );
			check( `${ tag }: axe first layer (serious/critical)`, bad.length === 0, JSON.stringify( bad ) );
			await page.locator( '.uxc-dlg .uxc-alt, .uxc-tab#uxc-tab-detail' ).first().click().catch( () => {} );
			await page.waitForTimeout( 250 );
			const bad2 = await axeBad( page );
			check( `${ tag }: axe settings layer (serious/critical)`, bad2.length === 0, JSON.stringify( bad2 ) );
			await context.close();
		}
	}
	check( 'no JavaScript errors', errors.length === 0, errors.join( ' | ' ) );
} finally {
	if ( ! process.env.KEEP ) {
		save( 'DELETE' );
	}
	await browser.close();
}
console.log( fails === 0 ? 'ALL OK' : fails + ' FAILED' );
process.exit( fails === 0 ? 0 : 1 );
