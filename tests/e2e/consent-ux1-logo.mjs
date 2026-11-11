/**
 * Browser QA of the UX1 signature in the cookie banner (PLAN.md 42.23). Needs no WordPress site.
 *
 *   node tests/e2e/consent-ux1-logo.mjs
 *
 * The REAL assets/consent/consent.min.js and the minified CSS of the working copy are served by request interception on a
 * fake origin, with a config block shaped like Module::frontend_config() output and a deliberately hostile "theme" (greys
 * every label, colours every link, resets svg and buttons). For every template and option variant, on a 1280 and a 390 px
 * wide screen, in the first layer and in the settings (all tabs for the tabs template): the logo exists once, is a link that
 * opens in a new window, sits inside the screen and the dialog, overlaps no button, text, switch or tab, comes after the
 * buttons in the tab order, has a visible focus ring, and axe-core finds nothing serious or critical.
 * Screenshots go to SHOTS (uxc-ux1-<variant>-<view>-<desktop|mobile>.png).
 */
import { chromium } from 'playwright';
import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const ASSETS = resolve( here, '../../assets/consent' );
const SHOTS = process.env.SHOTS || 'C:\\Users\\sturm\\.claude\\screenshots';
const AXE = readFileSync( resolve( here, '../../node_modules/axe-core/axe.min.js' ), 'utf8' );
mkdirSync( SHOTS, { recursive: true } );
const ORIGIN = 'http://uxc-qa.test';

let fails = 0;
const check = ( name, ok, detail = '' ) => {
	if ( ! ok ) {
		fails++;
	}
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok || ! detail ? '' : ' => ' + detail ) );
};

const TEXT = {
	title: 'Your privacy choices',
	msg: 'We use cookies so this site works and, if you agree, to measure visits and show relevant offers. Choose what you allow. You can change your decision at any time.',
	accept: 'Accept all', reject: 'Reject all', settings: 'Customize', save: 'Save choices', close: 'Close', always: 'Always on',
	services: 'Services', none: 'None in use', fab: 'Cookie settings', policy: 'Cookie policy', cid: 'Consent ID:',
	by: 'Cookie banner by UX1 (opens in a new window)',
	tab_c: 'Consent', tab_d: 'Details', tab_a: 'About cookies', tabs: 'Cookie banner sections',
	about: 'Cookies are small files that a website stores in your browser.',
};
const CATS = [
	{ id: 'necessary', n: 'Necessary', d: 'Needed for the site to work.', s: [ 'WordPress' ] },
	{ id: 'preferences', n: 'Preferences', d: 'Remember your settings.', s: [] },
	{ id: 'statistics', n: 'Statistics', d: 'Count visits anonymously.', s: [ 'Google Analytics', 'Matomo' ] },
	{ id: 'marketing', n: 'Marketing', d: 'Measure advertising and show offers.', s: [ 'YouTube' ] },
];

// name: screenshot label; cfg: what the server would send; css: template style file; vars: inline CSS variables of the option.
const variants = [
	{ name: 'classic', cfg: { tpl: 'classic', layout: 'box', pos: 'left' } },
	{ name: 'classic-right', cfg: { tpl: 'classic', layout: 'box', pos: 'right' } },
	{ name: 'classic-bar', cfg: { tpl: 'classic', layout: 'bar', pos: 'left' } },
	{ name: 'cookiebot', cfg: { tpl: 'cookiebot', layout: 'box', pos: 'left' }, css: 'cookiebot' },
	{ name: 'cookiebot-full', cfg: { tpl: 'cookiebot', layout: 'box', pos: 'left', o: { place: 'full', about: true } }, css: 'cookiebot', vars: '--uxc-w:1000px' },
	{ name: 'cookie', cfg: { tpl: 'cookie', layout: 'box', pos: 'left' }, css: 'cookie' },
	{ name: 'cookie-right-rect', cfg: { tpl: 'cookie', layout: 'box', pos: 'right', o: { shape: 'rect', icon: false } }, css: 'cookie', vars: '--uxc-cr:8px' },
	{ name: 'cookie-radius40', cfg: { tpl: 'cookie', layout: 'box', pos: 'left' }, css: 'cookie', vars: '--uxc-cr:40px' },
	{ name: 'slim', cfg: { tpl: 'slim', layout: 'box', pos: 'left', policy: '/privacy/' }, css: 'slim' },
	{ name: 'slim-top', cfg: { tpl: 'slim', layout: 'box', pos: 'left', policy: '/privacy/', o: { place: 'top' } }, css: 'slim' },
	// The live preview in the admin runs the same files with cfg.preview (nothing is stored, buttons do nothing).
	{ name: 'preview-slim', cfg: { tpl: 'slim', layout: 'box', pos: 'left', preview: 'main', policy: '/privacy/' }, css: 'slim' },
	{ name: 'preview-cookie', cfg: { tpl: 'cookie', layout: 'box', pos: 'left', preview: 'main' }, css: 'cookie' },
	{ name: 'classic-nobrand', cfg: { tpl: 'classic', layout: 'box', pos: 'left' }, nobrand: true },
];

function pageHtml( v ) {
	const cfg = { v: 1, days: 365, gcm: true, fab: true, c: CATS, t: { ...TEXT }, ...v.cfg };
	if ( v.nobrand ) {
		delete cfg.t.by;
	} else {
		cfg.by = 'https://ux1.cz/';
	}
	const json = JSON.stringify( cfg ).replace( /</g, '\\u003c' );
	return (
		'<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>QA</title>' +
		`<link rel="stylesheet" href="${ ORIGIN }/consent.min.css">` +
		( v.css ? `<link rel="stylesheet" href="${ ORIGIN }/consent-${ v.css }.min.css">` : '' ) +
		// A hostile theme: greys labels, colours and underlines links, restyles buttons and svg, bigger headings.
		'<style>body{margin:0;font:16px/1.5 Georgia,serif;color:#222}label{color:#9e9e9e}a{color:#c00;text-decoration:none}svg{fill:currentColor;max-width:none}' +
		'button{letter-spacing:.05em;text-transform:uppercase}h2{font-size:2em;margin:1em 0}p{margin:1em 0}.pg{padding:24px;max-width:760px;margin:0 auto}' +
		`:root{${ v.vars || '' }}.uxc,.uxc-fab{${ v.vars || '' }}</style></head><body>` +
		'<main class="pg"><h1>Page</h1>' + '<p>Some page text that sits behind the banner.</p>'.repeat( 12 ) + '</main>' +
		`<script type="application/json" id="uxc-config">${ json }</script><script src="${ ORIGIN }/consent.min.js"></script></body></html>`
	);
}

const rectsOverlap = ( a, b, tol = 0.5 ) => a.x < b.x + b.width - tol && b.x < a.x + a.width - tol && a.y < b.y + b.height - tol && b.y < a.y + a.height - tol;

async function inspect( page, label, vp ) {
	const data = await page.evaluate( () => {
		const r = ( n ) => {
			const b = n.getBoundingClientRect();
			return { x: b.x, y: b.y, width: b.width, height: b.height };
		};
		// Headings and paragraphs keep room for the logo in their padding: compare with the text itself, not the padded box.
		const textBox = ( n ) => {
			const rg = document.createRange();
			rg.selectNodeContents( n );
			const b = rg.getBoundingClientRect();
			return { x: b.x, y: b.y, width: b.width, height: b.height };
		};
		const vis = ( n ) => n.offsetParent !== null || getComputedStyle( n ).position === 'fixed';
		const logos = [ ...document.querySelectorAll( '.uxc .uxc-by' ) ].filter( vis );
		const logo = logos[ 0 ];
		if ( ! logo ) {
			return { count: 0 };
		}
		const dlg = document.querySelector( '.uxc-dlg' );
		const sel = 'button, a:not(.uxc-by), input, summary, h2, p, label, .uxc-sw, .uxc-tab, .uxc-d, li';
		const others = [ ...dlg.querySelectorAll( sel ) ]
			.filter( ( n ) => vis( n ) && ! logo.contains( n ) )
			.filter( ( n ) => {
				// Elements clipped away (screen-reader-only title of the slim bar) have no visible box.
				const b = n.getBoundingClientRect();
				return b.width > 2 && b.height > 2;
			} )
			.map( ( n ) => ( { tag: n.tagName.toLowerCase(), cls: n.className, text: ( n.textContent || '' ).trim().slice( 0, 20 ), box: /^(H2|P)$/.test( n.tagName ) ? textBox( n ) : r( n ) } ) );
		const buttons = [ ...dlg.querySelectorAll( 'button' ) ].filter( vis );
		const order = buttons.every( ( b ) => !! ( b.compareDocumentPosition( logo ) & Node.DOCUMENT_POSITION_FOLLOWING ) );
		const ws = getComputedStyle( logo );
		const cs = getComputedStyle( logo );
		return {
			count: logos.length, box: r( logo ), dlg: r( dlg ), others, order,
			href: logo.getAttribute( 'href' ), target: logo.getAttribute( 'target' ), rel: logo.getAttribute( 'rel' ),
			label: logo.getAttribute( 'aria-label' ), title: logo.getAttribute( 'title' ),
			wordText: getComputedStyle( logo, '::after' ).content.replace( /"/g, '' ), textNodes: logo.textContent.trim(), fontStyle: ws.fontStyle, fontFamily: ws.fontFamily, hasSvg: !! logo.querySelector( 'svg' ),
			opacity: cs.opacity, color: cs.color,
		};
	} );
	if ( ! data.count ) {
		check( `${ label }: the logo is shown`, false, 'missing' );
		return;
	}
	check( `${ label }: exactly one logo`, data.count === 1, String( data.count ) );
	check( `${ label }: link to https://ux1.cz/ in a new window with noopener`, data.href === 'https://ux1.cz/' && data.target === '_blank' && data.rel === 'noopener', JSON.stringify( [ data.href, data.target, data.rel ] ) );
	check( `${ label }: accessible name and title in Czech/English text of the config, the visible word UX1 from CSS (no text node), italic system font, no svg`, data.label === TEXT.by && data.title === TEXT.by && data.wordText === 'UX1' && data.textNodes === '' && data.fontStyle === 'italic' && /system-ui/.test( data.fontFamily ) && ! data.hasSvg, JSON.stringify( data ) );
	const b = data.box;
	check( `${ label }: inside the screen`, b.x >= 0 && b.y >= 0 && b.x + b.width <= vp.width && b.y + b.height <= vp.height, JSON.stringify( b ) );
	check( `${ label }: inside the dialog`, b.x >= data.dlg.x - 0.5 && b.y >= data.dlg.y - 0.5 && b.x + b.width <= data.dlg.x + data.dlg.width + 0.5 && b.y + b.height <= data.dlg.y + data.dlg.height + 0.5, JSON.stringify( { b, dlg: data.dlg } ) );
	const hit = data.others.filter( ( o ) => rectsOverlap( b, o.box ) );
	check( `${ label }: overlaps no button, text, switch or tab`, hit.length === 0, JSON.stringify( hit.map( ( h ) => [ h.tag, h.cls, h.text, h.box ] ) ) );
	check( `${ label }: after the buttons in the tab order`, data.order );
	check( `${ label }: light and in the background (height 22 to 34 px, opacity .1 to .2)`, b.height >= 22 && b.height <= 34 && +data.opacity >= 0.1 && +data.opacity <= 0.2, JSON.stringify( { b, o: data.opacity } ) );
	console.log( `INFO ${ label }: logo ${ b.width.toFixed( 1 ) } x ${ b.height.toFixed( 1 ) } at (${ Math.round( b.x ) }, ${ Math.round( b.y ) }), font ${ data.fontFamily.slice( 0, 20 ) }, colour ${ data.color }` );
}

async function axeCheck( page, label ) {
	await page.evaluate( AXE );
	const res = await page.evaluate( async () => {
		const r = await window.axe.run( { include: [ [ '.uxc' ] ] }, { runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice' ] } } );
		return r.violations.map( ( x ) => ( { id: x.id, impact: x.impact, html: x.nodes[ 0 ].html.slice( 0, 100 ) } ) );
	} );
	const bad = res.filter( ( x ) => x.impact === 'serious' || x.impact === 'critical' );
	check( `${ label }: axe (serious/critical)`, bad.length === 0, JSON.stringify( bad ) );
	if ( res.length > bad.length ) {
		console.log( `INFO ${ label }: axe minor/moderate ${ JSON.stringify( res.filter( ( x ) => ! bad.includes( x ) ).map( ( x ) => x.id ) ) }` );
	}
}

const browser = await chromium.launch();
const errors = [];
const files = {
	'/consent.min.css': [ readFileSync( resolve( ASSETS, 'consent.min.css' ) ), 'text/css' ],
	'/consent.min.js': [ readFileSync( resolve( ASSETS, 'consent.min.js' ) ), 'application/javascript' ],
};
for ( const t of [ 'cookiebot', 'cookie', 'slim' ] ) {
	files[ `/consent-${ t }.min.css` ] = [ readFileSync( resolve( ASSETS, `consent-${ t }.min.css` ) ), 'text/css' ];
}

try {
	for ( const v of variants ) {
		for ( const [ size, viewport ] of [ [ 'desktop', { width: 1280, height: 800 } ], [ 'mobile', { width: 390, height: 844 } ] ] ) {
			const tag = `${ v.name } ${ size }`;
			const context = await browser.newContext( { viewport, isMobile: size === 'mobile', hasTouch: size === 'mobile' } );
			await context.route( '**/*', ( route ) => {
				const u = new URL( route.request().url() );
				if ( u.origin === ORIGIN && u.pathname === '/' ) {
					return route.fulfill( { status: 200, contentType: 'text/html', body: pageHtml( v ) } );
				}
				if ( u.origin === ORIGIN && files[ u.pathname ] ) {
					return route.fulfill( { status: 200, contentType: files[ u.pathname ][ 1 ], body: files[ u.pathname ][ 0 ] } );
				}
				if ( u.hostname === 'ux1.cz' ) {
					return route.fulfill( { status: 200, contentType: 'text/html', body: '<title>UX1</title>' } );
				}
				return route.fulfill( { status: 404, body: '' } );
			} );
			const page = await context.newPage();
			page.on( 'pageerror', ( e ) => errors.push( tag + ': ' + e.message ) );
			page.on( 'console', ( m ) => m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) && errors.push( tag + ': ' + m.text() ) );
			await page.goto( ORIGIN + '/', { waitUntil: 'load' } );
			await page.waitForSelector( '.uxc:not([hidden]) .uxc-dlg', { timeout: 10000 } );
			await page.waitForTimeout( 250 );
			const shot = ( view ) => `${ SHOTS }\\uxc-ux1-${ v.name }-${ view }-${ size }.png`;

			if ( v.nobrand ) {
				check( `${ tag }: no logo when the server sends no "by" (filter off)`, ( await page.locator( '.uxc-by' ).count() ) === 0 );
				await page.screenshot( { path: shot( 'main' ) } );
				await context.close();
				continue;
			}

			await inspect( page, `${ tag } first layer`, viewport );
			await page.screenshot( { path: shot( 'main' ) } );
			await axeCheck( page, `${ tag } first layer` );

			// Focus ring and the tab order (the logo is reached after the last button).
			const lastBtn = page.locator( '.uxc-dlg button:visible' ).last();
			await lastBtn.focus();
			await page.keyboard.press( 'Tab' );
			const focused = await page.evaluate( () => {
				const a = document.activeElement;
				const cs = getComputedStyle( a );
				return { cls: a.className, outline: cs.outlineStyle, w: parseFloat( cs.outlineWidth ), op: cs.opacity };
			} );
			check( `${ tag }: Tab after the last button reaches the logo, with a visible focus ring and stronger`, /uxc-by/.test( focused.cls ) && focused.outline !== 'none' && focused.w >= 2 && +focused.op >= 0.5, JSON.stringify( focused ) );

			if ( v.name === 'classic' && size === 'desktop' ) {
				const [ popup ] = await Promise.all( [ context.waitForEvent( 'page' ), page.locator( '.uxc-by' ).click() ] );
				check( 'classic desktop: click opens ux1.cz in a new window', popup.url().startsWith( 'https://ux1.cz' ), popup.url() );
				await popup.close();
				check( 'classic desktop: the banner stays open after the click', await page.locator( '.uxc-dlg' ).isVisible() );
			}

			// Settings layer.
			if ( v.cfg.tpl === 'cookiebot' ) {
				for ( const tab of [ 'detail', 'about', 'main' ] ) {
					await page.click( `#uxc-tab-${ tab }` );
					await page.waitForTimeout( 150 );
					await inspect( page, `${ tag } tab ${ tab }`, viewport );
					if ( tab !== 'main' ) {
						await page.screenshot( { path: shot( tab ) } );
						await axeCheck( page, `${ tag } tab ${ tab }` );
					}
				}
			} else {
				await page.click( '.uxc-dlg .uxc-alt' );
				await page.waitForSelector( '.uxc.is-detail .uxc-detail:not([hidden])' );
				await page.waitForTimeout( 200 );
				await inspect( page, `${ tag } settings`, viewport );
				await page.screenshot( { path: shot( 'detail' ) } );
				await axeCheck( page, `${ tag } settings` );
				// The focus trap still works with the logo inside: 14 Tabs and Shift+Tabs never leave the dialog, and the logo is reached.
				let inside = true;
				let reached = false;
				for ( let i = 0; i < 14; i++ ) {
					await page.keyboard.press( 'Tab' );
					const st = await page.evaluate( () => ( { inside: !! document.activeElement.closest( '.uxc' ), by: document.activeElement.classList.contains( 'uxc-by' ) } ) );
					inside = inside && st.inside;
					reached = reached || st.by;
				}
				for ( let i = 0; i < 14; i++ ) {
					await page.keyboard.press( 'Shift+Tab' );
					inside = inside && ( await page.evaluate( () => !! document.activeElement.closest( '.uxc' ) ) );
				}
				check( `${ tag }: focus trap keeps working and reaches the logo`, inside && reached, JSON.stringify( { inside, reached } ) );
			}
			await context.close();
		}
	}
	check( 'no JavaScript or console errors', errors.length === 0, errors.join( ' | ' ) );
} finally {
	await browser.close();
}
console.log( fails === 0 ? 'ALL OK' : fails + ' FAILED' );
process.exit( fails === 0 ? 0 : 1 );
