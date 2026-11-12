/**
 * Browser QA (PLAN.md 42.17): the "Switch" step of the migration wizard when the plan has must_confirm > 0 (Complianz
 * scripts with no counterpart). Without a real Complianz run the REST answers of the history and the plan are mocked in
 * the browser, so this checks the UI only (render, the switch button blocked until the loss is confirmed), not the PHP.
 * The PHP side is covered by tests/migration-*.php and tests/consent-complianz.php. Local test site only.
 *
 *   BASE=... UXS_ADMIN_USER=e2e UXS_ADMIN_PASSWORD=... node tests/e2e/consent-switch-mock.mjs
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
const p = await c.newPage();
const errors = [];
p.on( 'pageerror', ( e ) => errors.push( e.message ) );
const json = ( body ) => ( { status: 200, contentType: 'application/json', body: JSON.stringify( { data: body, meta: {} } ) } );
const counts = { ok: 0, warn: 0, fail: 0, info: 0 };
await c.route( /migration\/runs/, ( r ) => r.fulfill( json( { runs: [ {
	id: 'mockrun1', started_at: '2026-11-10 08:00:00', finished: 1, sources: [ { id: 'complianz-gdpr', label: 'Complianz', status: 'done', written: 12, staged: 12 } ],
	errors: 0, snapshot: true, rolled_back: 0, rollback_until: '2026-12-10', can_rollback: true, staging: { settings: 12, secrets: 0, activations: 1, modules: [ 'consent' ] },
	preview: null, switched: 0, switch_active: false, can_revert_switch: false,
} ] } ) ) );
await c.route( /migration\/switch-plan/, ( r ) => r.fulfill( json( {
	run_id: 'mockrun1', switched: false, plugins: [ { basename: 'complianz-gdpr/complianz-gpdr.php', name: 'Complianz', source: 'complianz-gdpr' } ],
	modules: [ { id: 'consent', name: 'Cookie Consent', enabled: false } ], staging: { settings: 12, secrets: 0, activations: 1, modules: [ 'consent' ] },
	login: { changes: false, url: '', slug: '', now_url: '' }, errors: 0,
	preview: { available: { ok: false, reason: 'Mocked: no safe preview on this server.' }, tested: false, fresh: false, complete: false, counts, total: 4 },
	must_confirm: 2, kill_switch: 'UXSTUDIO_DISABLE_LOGIN_LOCK', revert_days: 30,
} ) ) );

await p.goto( BASE + '/wp-login.php' );
await p.fill( '#user_login', USER );
await p.fill( '#user_pass', PASS );
await Promise.all( [ p.waitForNavigation(), p.click( '#wp-submit' ) ] );
await p.goto( BASE + '/wp-admin/admin.php?page=ux-studio#/wizard', { waitUntil: 'networkidle' } );
await p.waitForTimeout( 2500 );
await p.screenshot( { path: SHOTS + '/uxc-switch-mock-0.png' } );
await p.getByRole( 'button', { name: /Preview and switch/ } ).click( { timeout: 8000 } );
await p.waitForTimeout( 800 );
// walk forward to the Switch step
for ( let i = 0; i < 4; i++ ) {
	if ( await p.getByRole( 'heading', { name: /Switch to UX1 Studio/ } ).count() ) {
		break;
	}
	const next = p.getByRole( 'button', { name: /^(Next|Continue|Skip|Go on|Switch)/ } ).first();
	if ( ! await next.count() ) {
		break;
	}
	await next.click().catch( () => {} );
	await p.waitForTimeout( 600 );
}
check( 'Switch step reached', await p.getByRole( 'heading', { name: /Switch to UX1 Studio/ } ).count() > 0 );
await p.waitForTimeout( 800 );
await p.screenshot( { path: SHOTS + '/uxc-switch-mock.png', fullPage: true } );
check( 'must_confirm callout rendered', await p.getByText( 'Some things stop working after the switch' ).count() === 1 );
const box = p.locator( 'label.uxs-wiz-checkrow', { hasText: 'I accept the loss' } ).locator( 'input' );
check( 'loss checkbox present and unchecked', await box.count() === 1 && ! ( await box.isChecked() ) );
const sw = p.getByRole( 'button', { name: /^Switch now$/ } ).first();
check( 'Switch now blocked before confirming', await sw.isDisabled() );
check( 'blocker text mentions the loss', await p.getByText( /has no counterpart and stops working/ ).count() >= 1 );
const noPrev = p.locator( 'label.uxs-wiz-checkrow', { hasText: 'without a preview' } ).locator( 'input' );
if ( await noPrev.count() ) {
	await noPrev.check();
}
check( 'still blocked with only the no-preview box ticked', await sw.isDisabled() );
await box.check();
await p.waitForTimeout( 300 );
check( 'Switch now enabled after both confirmations', await sw.isEnabled() );
check( 'no page errors', errors.length === 0, errors.join( ' // ' ) );
await browser.close();
console.log( fails ? `\n${ fails } FAILED` : '\nALL PASSED' );
process.exit( fails ? 1 : 0 );
