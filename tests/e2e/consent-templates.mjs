/**
 * Browser QA of the four banner templates (PLAN.md 42.20). Local test site only.
 *
 *   BASE=http://localhost/hydro-izol WP_PATH=D:/xampp/htdocs/hydro-izol WPCLI=D:/xampp/htdocs/pobyty/wp-cli.phar \
 *   PHP=D:/xampp/php/php.exe node tests/e2e/consent-templates.mjs
 *
 * For each template (set with WP-CLI in the `template` setting of the module, the page cache purged) and for a desktop and a
 * 390 px wide screen: the banner opens, accept and reject are the same size, no horizontal overflow, the cookie of the choice is
 * written, axe-core finds nothing serious or critical (first layer, settings, floating button), keyboard (Tab stays inside the
 * settings, Escape). Screenshots go to SHOTS (uxc-tpl-<template>-<view>-<desktop|mobile>.png).
 * At the end the setting is removed again (the test site had no saved settings of the module); set KEEP=1 to leave the last one.
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
const SETTER = process.env.SETTER || resolve( here, 'consent-templates-set.php' );
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

function setTemplate( tpl ) {
	execFileSync( PHP, [ WPCLI, '--path=' + WP_PATH, 'eval-file', SETTER ], { env: { ...process.env, UXC_TPL: tpl }, stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 180000 } );
}

const browser = await chromium.launch();
const errors = [];

async function axeRun( page, label ) {
	await page.evaluate( AXE );
	const result = await page.evaluate( async () => {
		const r = await window.axe.run( { include: [ [ '.uxc' ], [ '.uxc-fab' ] ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] } } );
		return r.violations.map( ( v ) => ( { id: v.id, impact: v.impact, n: v.nodes.length, html: v.nodes[ 0 ].html.slice( 0, 120 ) } ) );
	} );
	const bad = result.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
	check( 'axe ' + label + ' (serious/critical)', bad.length === 0, JSON.stringify( bad ) );
	const rest = result.filter( ( v ) => ! bad.includes( v ) );
	if ( rest.length ) {
		console.log( 'INFO axe ' + label + ' minor/moderate: ' + JSON.stringify( rest ) );
	}
}

const box = async ( page, sel ) => page.locator( sel ).first().boundingBox();

for ( const tpl of [ 'classic', 'cookiebot', 'cookie', 'slim' ] ) {
	setTemplate( tpl );
	for ( const [ size, viewport ] of [ [ 'desktop', { width: 1280, height: 800 } ], [ 'mobile', { width: 390, height: 844 } ] ] ) {
		const tag = `${ tpl } ${ size }`;
		const shot = ( view ) => `${ SHOTS }\\uxc-tpl-${ tpl }-${ view }-${ size }.png`;
		const context = await browser.newContext( { viewport, isMobile: size === 'mobile', hasTouch: size === 'mobile' } );
		const page = await context.newPage();
		page.on( 'console', ( m ) => m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) && errors.push( tag + ': ' + m.text() ) );
		page.on( 'pageerror', ( e ) => errors.push( tag + ' pageerror: ' + e.message ) );
		await page.goto( BASE + '/', { waitUntil: 'load' } );
		await page.waitForSelector( '.uxc:not([hidden]) .uxc-dlg', { timeout: 10000 } );
		const cls = await page.getAttribute( '.uxc', 'class' );
		check( `${ tag }: root class has the template`, cls.includes( 'uxc-t-' + tpl ), cls );
		const css = await page.evaluate( () => Array.from( document.querySelectorAll( 'link[rel=stylesheet]' ) ).map( ( l ) => l.href ).filter( ( h ) => /consent/.test( h ) ).map( ( h ) => h.split( '/' ).pop().split( '?' )[ 0 ] ) );
		check( `${ tag }: only the style of this template is loaded`, JSON.stringify( css ) === JSON.stringify( tpl === 'classic' ? [ 'consent.min.css' ] : [ 'consent.min.css', `consent-${ tpl }.min.css` ] ), JSON.stringify( css ) );
		await page.waitForTimeout( 200 );
		await page.screenshot( { path: shot( 'main' ) } );

		const dlg = await box( page, '.uxc-dlg' );
		const overflow = await page.evaluate( () => document.documentElement.scrollWidth - window.innerWidth );
		check( `${ tag }: no horizontal overflow`, overflow <= 0, String( overflow ) );
		check( `${ tag }: the banner stays inside the screen`, dlg.x >= -0.5 && dlg.x + dlg.width <= viewport.width + 0.5 && dlg.y >= -0.5 && dlg.y + dlg.height <= viewport.height + 0.5, JSON.stringify( dlg ) );
		console.log( `INFO ${ tag }: banner ${ Math.round( dlg.width ) } x ${ Math.round( dlg.height ) } px` );
		if ( tpl === 'slim' ) {
			check( `${ tag }: the slim bar is low and spans the browser`, dlg.width >= viewport.width - 1 && dlg.height <= ( size === 'desktop' ? 72 : 150 ), JSON.stringify( dlg ) );
		}
		if ( tpl === 'cookiebot' && size === 'desktop' ) {
			check( `${ tag }: panel is wide and centred`, dlg.width >= 700 && Math.abs( dlg.x + dlg.width / 2 - viewport.width / 2 ) < 2, JSON.stringify( dlg ) );
		}
		if ( tpl === 'cookie' ) {
			check( `${ tag }: card sits at the bottom ${ size === 'desktop' ? 'left' : 'full width' }`, size === 'desktop' ? dlg.x < 40 && dlg.width < 420 : dlg.width >= viewport.width - 1 && dlg.y + dlg.height >= viewport.height - 1, JSON.stringify( dlg ) );
		}

		// Reject and accept are the same size and weight in every template.
		const no = await box( page, '.uxc-dlg .uxc-no' );
		const yes = await box( page, '.uxc-dlg .uxc-yes' );
		// Same height always; the width follows the label (a longer word is a wider button), except for the round buttons.
		check( `${ tag }: reject and accept have the same height and a similar width`, Math.abs( no.height - yes.height ) < 2 && Math.max( no.width, yes.width ) / Math.min( no.width, yes.width ) < 1.35, JSON.stringify( { no, yes } ) );
		const looks = await page.evaluate( () => {
			const s = ( q ) => getComputedStyle( document.querySelector( q ) );
			const no = s( '.uxc-dlg .uxc-no' );
			const yes = s( '.uxc-dlg .uxc-yes' );
			return { noBg: no.backgroundColor, yesBg: yes.backgroundColor, noWeight: no.fontWeight, yesWeight: yes.fontWeight, noSize: no.fontSize, yesSize: yes.fontSize, noBorder: no.borderTopWidth, yesBorder: yes.borderTopWidth };
		} );
		check( `${ tag }: reject is a solid button like accept (font, weight, border)`, looks.noWeight === looks.yesWeight && looks.noSize === looks.yesSize && looks.noBorder === looks.yesBorder && looks.noBg !== 'rgba(0, 0, 0, 0)' && looks.yesBg !== 'rgba(0, 0, 0, 0)', JSON.stringify( looks ) );

		await axeRun( page, tag + ' first layer' );

		// Keyboard: the first Tab reaches a control of the banner or the page; walk to the banner and check focus rings exist.
		const focusSel = tpl === 'cookiebot' ? '.uxc-tab[aria-selected=true]' : '.uxc-no';
		await page.focus( focusSel );
		const ring = await page.evaluate( () => {
			const cs = getComputedStyle( document.activeElement );
			return { outline: cs.outlineStyle, w: cs.outlineWidth };
		} );
		check( `${ tag }: focused control has a visible focus ring`, ring.outline !== 'none' && parseFloat( ring.w ) >= 2, JSON.stringify( ring ) );

		// Settings.
		if ( tpl === 'cookiebot' ) {
			await page.click( '#uxc-tab-detail' );
			await page.waitForTimeout( 100 );
			check( `${ tag }: Details tab shows the categories`, await page.locator( '.uxc-detail' ).isVisible() && ! ( await page.locator( '.uxc-main' ).isVisible() ) );
			await page.screenshot( { path: shot( 'detail' ) } );
			await axeRun( page, tag + ' details tab' );
			await page.focus( '#uxc-tab-detail' );
			await page.keyboard.press( 'ArrowRight' );
			check( `${ tag }: arrow key moves to the About tab`, await page.locator( '.uxc-about' ).isVisible() && ( await page.evaluate( () => document.activeElement.id ) ) === 'uxc-tab-about' );
			await page.screenshot( { path: shot( 'about' ) } );
			await axeRun( page, tag + ' about tab' );
			await page.click( '#uxc-tab-main' );
			const sw = page.locator( '.uxc-swr input:not([disabled])' );
			check( `${ tag }: three optional switches, all off by default`, ( await sw.count() ) === 3 && ( await sw.evaluateAll( ( l ) => l.every( ( i ) => ! i.checked ) ) ) );
			await sw.nth( 1 ).check();
			await page.screenshot( { path: shot( 'switches' ) } );
		} else {
			await page.click( '.uxc-dlg .uxc-alt' );
			await page.waitForSelector( '.uxc.is-detail .uxc-detail:not([hidden])' );
			await page.waitForTimeout( 150 );
			await page.screenshot( { path: shot( 'detail' ) } );
			const d = await box( page, '.uxc-dlg' );
			check( `${ tag }: settings dialog stays inside the screen`, d.x >= -0.5 && d.y >= -0.5 && d.x + d.width <= viewport.width + 0.5 && d.y + d.height <= viewport.height + 0.5, JSON.stringify( d ) );
			await axeRun( page, tag + ' settings' );
			// Focus trap: 16 Tabs and Shift+Tabs stay inside the dialog.
			let inside = true;
			for ( let i = 0; i < 16; i++ ) {
				await page.keyboard.press( 'Tab' );
				inside = inside && ( await page.evaluate( () => !! document.activeElement.closest( '.uxc' ) ) );
			}
			for ( let i = 0; i < 16; i++ ) {
				await page.keyboard.press( 'Shift+Tab' );
				inside = inside && ( await page.evaluate( () => !! document.activeElement.closest( '.uxc' ) ) );
			}
			check( `${ tag }: focus stays in the settings dialog`, inside );
			await page.keyboard.press( 'Escape' );
			check( `${ tag }: Escape returns to the first layer`, await page.locator( '.uxc-main' ).isVisible() && ! ( await page.locator( '.uxc.is-detail' ).count() ) );
			await page.locator( '.uxc-detail input:not([disabled])' ).count();
		}

		// Reject: cookie written with no categories, the banner goes, the floating button comes.
		await page.locator( '.uxc-dlg .uxc-no:visible' ).first().click();
		await page.waitForSelector( '.uxc-fab:not([hidden])' );
		const cookie = ( await context.cookies() ).find( ( c ) => c.name === 'uxs_consent' );
		check( `${ tag }: reject writes the choice cookie with bits 0`, !! cookie && cookie.value.split( '.' )[ 2 ] === '0', cookie ? cookie.value : 'none' );
		check( `${ tag }: banner is hidden after the decision`, await page.locator( '.uxc' ).first().isHidden() );
		await page.waitForTimeout( 150 );
		await page.screenshot( { path: shot( 'fab' ) } );
		await axeRun( page, tag + ' floating button' );
		const fab = await box( page, '.uxc-fab' );
		check( `${ tag }: floating button is at least 44 px`, fab.width >= 44 && fab.height >= 44, JSON.stringify( fab ) );

		// Reopen from the floating button, accept all.
		await page.click( '.uxc-fab' );
		await page.waitForSelector( '.uxc:not([hidden])' );
		await page.screenshot( { path: shot( 'reopen' ) } );
		await page.locator( '.uxc-dlg .uxc-yes:visible' ).first().click();
		await page.waitForTimeout( 200 );
		const c2 = ( await context.cookies() ).find( ( c ) => c.name === 'uxs_consent' );
		check( `${ tag }: accept all after reopening writes bits 7`, !! c2 && c2.value.split( '.' )[ 2 ] === '7', c2 ? c2.value : 'none' );
		await context.close();
	}
}

// ---------- admin: the template select and the live preview (needs a session: COOKIES_JSON, a temporary administrator) ----------
if ( process.env.COOKIES_JSON ) {
	const context = await browser.newContext( { viewport: { width: 1600, height: 1000 } } );
	await context.addCookies( JSON.parse( readFileSync( process.env.COOKIES_JSON, 'utf8' ) ) );
	const page = await context.newPage();
	page.on( 'pageerror', ( e ) => errors.push( 'admin pageerror: ' + e.message ) );
	const stored = () =>
		page.evaluate( async () => {
			const b = window.uxStudioBoot;
			const r = await fetch( `${ b.restUrl }/settings/consent`, { headers: { 'X-WP-Nonce': b.nonce } } );
			return JSON.stringify( ( await r.json() ).data.values );
		} );
	await page.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/module?id=consent', { waitUntil: 'load', timeout: 90000 } );
	await page.waitForSelector( '.uxs-uxcp iframe', { timeout: 30000 } );
	const before = await stored();
	await page.evaluate( () => [ ...document.querySelectorAll( 'button' ) ].find( ( x ) => [ 'Appearance', 'Vzhled' ].includes( x.textContent.trim() ) ).click() );
	await page.waitForTimeout( 600 );
	const tiles = page.locator( '.uxs-topic-settings__form .uxs-tiles' ).first();
	const options = await tiles.locator( 'input[type=radio]' ).evaluateAll( ( l ) => l.map( ( o ) => o.value ) );
	check( 'admin: the first field of Appearance is the template as tiles with the four values', JSON.stringify( options ) === JSON.stringify( [ 'classic', 'cookiebot', 'cookie', 'slim' ] ), JSON.stringify( options ) );
	for ( const tpl of [ 'cookiebot', 'cookie', 'slim', 'classic' ] ) {
		await tiles.locator( `.uxs-tile:has(input[value="${ tpl }"])` ).click();
		await page.waitForTimeout( 1800 );
		const frame = await ( await page.waitForSelector( '.uxs-uxcp iframe' ) ).contentFrame();
		await frame.waitForSelector( `.uxc.uxc-t-${ tpl }:not([hidden]) .uxc-dlg`, { timeout: 10000 } );
		const links = await frame.evaluate( () => [ ...document.querySelectorAll( 'link[rel=stylesheet]' ) ].map( ( l ) => l.href.split( '/' ).pop().split( '?' )[ 0 ] ) );
		check( `admin preview ${ tpl }: shows the template with its style`, JSON.stringify( links ) === JSON.stringify( tpl === 'classic' ? [ 'consent.min.css' ] : [ 'consent.min.css', `consent-${ tpl }.min.css` ] ), JSON.stringify( links ) );
		const layoutShown = await page.locator( '.uxs-topic-settings__form select' ).count();
		console.log( `INFO admin ${ tpl }: ${ layoutShown } selects in the Appearance form` );
		await page.screenshot( { path: resolve( SHOTS, `uxc-tpl-admin-${ tpl }.png` ) } );
		for ( const view of [ 'Category settings', 'Nastavení kategorií' ] ) {
			const btn = page.locator( '.uxs-uxcp__bar button', { hasText: view } );
			if ( await btn.count() ) {
				await btn.click();
				await page.waitForTimeout( 1800 );
				const f2 = await ( await page.waitForSelector( '.uxs-uxcp iframe' ) ).contentFrame();
				await f2.waitForSelector( '.uxc:not([hidden]) .uxc-detail:not([hidden])', { timeout: 10000 } );
				check( `admin preview ${ tpl }: the category settings view works`, true );
				await page.screenshot( { path: resolve( SHOTS, `uxc-tpl-admin-${ tpl }-detail.png` ) } );
				await page.locator( '.uxs-uxcp__bar button' ).first().click();
				await page.waitForTimeout( 1500 );
			}
		}
	}
	check( 'admin: choosing templates in the form saved nothing', ( await stored() ) === before );
	await context.close();
}

check( 'no console errors on the page', errors.length === 0, errors.join( ' | ' ) );
if ( ! process.env.KEEP ) {
	setTemplate( 'DELETE' );
}
await browser.close();
console.log( fails === 0 ? 'ALL OK' : fails + ' FAILED' );
process.exit( fails === 0 ? 0 : 1 );
