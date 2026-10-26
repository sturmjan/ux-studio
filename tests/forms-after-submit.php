<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Test of the "after submit" step of the Forms module (PLAN.md 20.13): sanitizing of the thank-you message,
 * escaped merge tags, migration of the old success_text and redirect action, the redirect target, who may save the
 * custom script, escaping of that script and sanitizing of the conversion tracking settings.
 *
 * Run inside a WordPress install that has UX Studio active, loading THIS checkout:
 *
 *   wp eval-file tests/forms-after-submit.php --skip-plugins=wp-mail-smtp
 *
 * Exits non-zero when any case fails. Nothing is written to the database.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$dir = dirname( __DIR__ ) . '/includes/Modules/Forms/';
require_once $dir . 'EmailTemplateRenderer.php';
require_once $dir . 'AfterSubmit.php';
require_once $dir . 'Fields.php';
require_once $dir . 'Templates.php';
require_once $dir . 'Module.php';

use UxStudio\Modules\Forms\AfterSubmit as A;
use UxStudio\Modules\Forms\Module;
use UxStudio\Modules\Forms\Templates;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$has = static fn( string $haystack, string $needle ): bool => false !== strpos( $haystack, $needle );

/* ── thank-you message: allowlist ── */

$dirty = '<p onclick="x()">Hi <a href="javascript:alert(1)">bad</a> <a href="JaVaScRiPt:alert(2)">bad2</a> <a href="https://example.com/ok" target="_blank" onmouseover="y()">ok</a></p>'
	. '<script>alert(3)</script><iframe src="https://evil.example"></iframe><style>body{display:none}</style>'
	. '<img src="https://example.com/a.png" onerror="alert(4)" alt="A"><img src="javascript:alert(5)"><img src="data:image/svg+xml;base64,AAAA"><img src="mailto:x@y.z">'
	. '<form action="https://evil.example"><input name="a"></form><p style="color:red;position:fixed">styled</p><svg onload="alert(6)"><circle/></svg>';
$clean = A::sanitize_message( $dirty );
$assert( 'message: javascript: links are dropped (any case)', ! $has( strtolower( $clean ), 'javascript:' ), $clean );
$assert( 'message: event-handler attributes are dropped', ! $has( $clean, 'onclick' ) && ! $has( $clean, 'onerror' ) && ! $has( $clean, 'onmouseover' ) && ! $has( $clean, 'onload' ), $clean );
$assert( 'message: script, iframe, style, form and svg blocks are removed with their content', ! $has( $clean, '<script' ) && ! $has( $clean, 'alert(3)' ) && ! $has( $clean, '<iframe' ) && ! $has( $clean, 'display:none' ) && ! $has( $clean, '<form' ) && ! $has( $clean, '<svg' ), $clean );
$assert( 'message: a normal https link survives and gets rel=noopener for target=_blank', $has( $clean, 'href="https://example.com/ok"' ) && $has( $clean, 'noopener' ), $clean );
$assert( 'message: an https image survives', $has( $clean, 'src="https://example.com/a.png"' ), $clean );
$assert( 'message: data: and mailto: images are dropped', ! $has( $clean, 'data:image' ) && ! $has( $clean, 'src="mailto' ), $clean );
$assert( 'message: unsafe css (position) is dropped, color kept', ! $has( $clean, 'position' ) && $has( $clean, 'color:red' ), $clean );
$assert( 'message: plain formatting and an empty input survive', '<p><strong>x</strong></p>' === A::sanitize_message( '<p><strong>x</strong></p>' ) && '' === A::sanitize_message( '   ' ) );

/* ── merge tags: escaped on the server ── */

$values = array(
	'name'  => '<script>alert(1)</script> & "Bob"',
	'email' => 'a@b.cz',
	'tags'  => array( 'x', 'y<b>' ),
	'file'  => array( 'stored_name' => 'a.pdf', 'original_name' => 'a.pdf' ),
	'tricky' => '{email}',
);
$out = A::merge( '<p>Hello {name}, we write to {email} about {tags}. {file}{nope} {tricky} {form_title}</p>', $values, 'Contact <b>form</b>', true );
$assert( 'merge: a submitted tag is escaped, never live markup', ! $has( $out, '<script' ) && $has( $out, '&lt;script&gt;' ) && $has( $out, '&amp;' ), $out );
$assert( 'merge: quotes are escaped', $has( $out, '&quot;Bob&quot;' ), $out );
$assert( 'merge: a list is joined and escaped', $has( $out, 'x, y&lt;b&gt;' ), $out );
$assert( 'merge: a file value and an unknown tag vanish', ! $has( $out, '{file}' ) && ! $has( $out, '{nope}' ) && ! $has( $out, 'stored_name' ), $out );
$assert( 'merge: a value that looks like a tag is not expanded a second time', $has( $out, '{email}' ) === true && substr_count( $out, 'a@b.cz' ) === 1, $out );
$assert( 'merge: the form title is escaped', $has( $out, 'Contact &lt;b&gt;form&lt;/b&gt;' ), $out );
$assert( 'merge: plain mode (the title) keeps the raw text for textContent', 'Hi <b> Bob' === A::merge( 'Hi {n}', array( 'n' => '<b>' ), 'F', false ) . ' Bob' );

/* ── migration of success_text and the redirect action ── */

$module = ( new ReflectionClass( Module::class ) )->newInstanceWithoutConstructor();

$old = $module->sanitize_settings( array( 'success_text' => "Thanks!\nSee you soon." ) );
$assert( 'migration: old success_text becomes the message, mode replace, no title', 'replace' === $old['after_submit']['mode'] && $has( $old['after_submit']['message'], 'Thanks!<br />' ) && '' === $old['after_submit']['title'], wp_json_encode( $old['after_submit'] ) );
$assert( 'migration: success_text stays readable as plain text', $has( $old['success_text'], 'Thanks!' ) && $has( $old['success_text'], 'See you soon.' ) && ! $has( $old['success_text'], '<' ), $old['success_text'] );

$old2 = $module->sanitize_settings( array( 'success_text' => 'x', 'actions' => array( array( 'type' => 'redirect', 'url' => 'https://other.example/thanks' ), array( 'type' => 'webhook', 'url' => 'https://hook.example/h' ) ) ) );
$assert( 'migration: the redirect action becomes the redirect mode and leaves the action list', 'redirect' === $old2['after_submit']['mode'] && 'url' === $old2['after_submit']['redirect_type'] && 'https://other.example/thanks' === $old2['after_submit']['redirect_url'], wp_json_encode( $old2['after_submit'] ) );
$assert( 'migration: an off-site legacy redirect is allowed (it worked before)', true === $old2['after_submit']['redirect_external'] );
$assert( 'migration: only the webhook action is left in the list', 1 === count( $old2['actions'] ) && 'webhook' === $old2['actions'][0]['type'] );

$new = $module->sanitize_settings( array( 'success_text' => 'old', 'after_submit' => array( 'mode' => 'message', 'title' => 'Done', 'message' => '<p>New</p>' ), 'actions' => array( array( 'type' => 'redirect', 'url' => 'https://x.example/' ) ) ) );
$assert( 'migration: a form that already has after_submit wins over success_text', 'message' === $new['after_submit']['mode'] && '<p>New</p>' === $new['after_submit']['message'] && 'New' === trim( $new['success_text'] ) );
$assert( 'redirect actions never survive in the list (no two redirects)', array() === $new['actions'] );

/* ── redirect target ── */

$home = home_url( '/thanks/' );
$r    = A::sanitize( array( 'mode' => 'redirect', 'redirect_type' => 'url', 'redirect_url' => 'https://evil.example/x' ) );
$assert( 'redirect: another website is dropped unless explicitly allowed', '' === $r['redirect_url'] );
$r = A::sanitize( array( 'mode' => 'redirect', 'redirect_type' => 'url', 'redirect_url' => 'https://evil.example/x', 'redirect_external' => true ) );
$assert( 'redirect: another website is kept when allowed', 'https://evil.example/x' === $r['redirect_url'] );
$r = A::sanitize( array( 'mode' => 'redirect', 'redirect_type' => 'url', 'redirect_url' => 'javascript:alert(1)', 'redirect_external' => true ) );
$assert( 'redirect: javascript: is never a target', '' === $r['redirect_url'] );
$r = A::sanitize( array( 'mode' => 'redirect', 'redirect_type' => 'url', 'redirect_url' => $home ) );
$assert( 'redirect: an address on this site is kept', $home === $r['redirect_url'] );
$form = array( 'settings' => array( 'after_submit' => $r ) );
$assert( 'redirect: redirect_url() returns it', $home === A::redirect_url( $form ), A::redirect_url( $form ) );
$form['settings']['after_submit']['mode'] = 'replace';
$assert( 'redirect: other modes give no target', '' === A::redirect_url( $form ) );
$page_id = (int) get_option( 'page_on_front' ) ?: (int) ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
if ( $page_id > 0 ) {
	$form = array( 'settings' => array( 'after_submit' => array( 'mode' => 'redirect', 'redirect_type' => 'page', 'redirect_page_id' => $page_id ) ) );
	$assert( 'redirect: a published page resolves to its permalink', get_permalink( $page_id ) === A::redirect_url( $form ) );
}
$form = array( 'settings' => array( 'after_submit' => array( 'mode' => 'redirect', 'redirect_type' => 'page', 'redirect_page_id' => 999999999 ) ) );
$assert( 'redirect: a missing page gives no target (the thank-you is shown instead)', '' === A::redirect_url( $form ) );

/* ── sanitizing of the remaining after_submit fields ── */

$s = A::sanitize( array( 'mode' => 'weird', 'icon' => 'x', 'accent' => 'red', 'title' => '<b>T</b>' . str_repeat( 'a', 300 ) ) );
$assert( 'after_submit: bad mode/icon/accent fall back, title is plain text and capped', 'replace' === $s['mode'] && 'check' === $s['icon'] && '' === $s['accent'] && strlen( $s['title'] ) <= 120 && ! $has( $s['title'], '<' ) );
$s = A::sanitize( array( 'accent' => '#ABC', 'again' => 1, 'scroll' => 0 ) );
$assert( 'after_submit: accent is a normalised hex, toggles are booleans', '#aabbcc' === $s['accent'] && true === $s['again'] && false === $s['scroll'] );
$s = A::sanitize( array() );
$assert( 'after_submit: defaults - replace, check, no "again", scroll on', 'replace' === $s['mode'] && 'check' === $s['icon'] && false === $s['again'] && true === $s['scroll'] );

/* ── server response ── */

$resp = A::response(
	array( 'title' => 'Contact', 'settings' => array( 'after_submit' => A::sanitize( array( 'title' => 'Thanks {name}', 'message' => '<p>We call {name}.</p>' ) ) ) ),
	array( 'id' => 7, 'values' => array( 'name' => '<i>Eva</i>' ) )
);
$assert( 'response: title is merged as plain text, message as escaped HTML', 'Thanks <i>Eva</i>' === $resp['title'] && $has( $resp['html'], 'We call &lt;i&gt;Eva&lt;/i&gt;.' ), wp_json_encode( $resp ) );
$assert( 'response: carries the submission id and form title', 7 === $resp['submission_id'] && 'Contact' === $resp['form_title'] );
$resp = A::response( array( 'title' => 'C', 'settings' => array( 'after_submit' => A::sanitize( array() ) ) ), array( 'id' => 1, 'values' => array() ) );
$assert( 'response: an empty message and title use the defaults', '' !== $resp['title'] && $has( $resp['html'], A::default_message() ) );

/* ── who may save the custom script ── */

$assert( 'script guard: a user who may edit takes the new value', 'new();' === A::resolve_custom_js( 'new();', 'old();', true ) );
$assert( 'script guard: a user who may not keeps the stored value', 'old();' === A::resolve_custom_js( 'evil();', 'old();', false ) );
$g = A::guard_tracking( array( 'datalayer' => false, 'custom_js' => 'evil();' ), array( 'custom_js' => 'old();' ), false );
$assert( 'script guard: guard_tracking keeps the existing script and the other settings of the sender', 'old();' === $g['custom_js'] && false === $g['datalayer'] );
$g = A::guard_tracking( array( 'custom_js' => 'evil();' ), null, false );
$assert( 'script guard: a new form from a user who may not gets no script', '' === $g['custom_js'] );
$g = A::guard_tracking( null, array( 'custom_js' => 'old();' ), false );
$assert( 'script guard: a payload without tracking at all cannot wipe the script either', 'old();' === $g['custom_js'] );

$previous = get_current_user_id();
$admin_id = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
wp_set_current_user( 0 );
$assert( 'script guard: a visitor may not edit the script', false === A::user_can_edit_script() );
$existing = array( 'tracking' => array( 'custom_js' => 'old();' ) );
$saved    = $module->guard_settings( array( 'tracking' => array( 'custom_js' => 'evil();' ) ), $existing );
$assert( 'script guard: Module::guard_settings() preserves the stored script for them', 'old();' === $saved['tracking']['custom_js'] );
if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
	$can = ! is_multisite() ? current_user_can( 'unfiltered_html' ) : is_super_admin();
	$assert( 'script guard: an administrator with unfiltered_html may edit it (single site)', A::user_can_edit_script() === $can );
	if ( $can ) {
		$saved = $module->guard_settings( array( 'tracking' => array( 'custom_js' => 'new();' ) ), $existing );
		$assert( 'script guard: and their value is taken', 'new();' === $saved['tracking']['custom_js'] );
	}
}
wp_set_current_user( $previous );

/* ── script escaping ── */

$hostile = "var a = '</script><script>alert(1)</script>'; // </SCRIPT >\nvar b = '<!-- <script>';";
$html    = A::hook_script( 12, $hostile );
$assert( 'script: only our own closing tag remains - the code cannot break out', 1 === substr_count( strtolower( $html ), '</script' ), $html );
$assert( 'script: an HTML comment opener is neutralised', ! $has( $html, '<!--' ) );
$assert( 'script: registers a function under the form id, no eval or Function', $has( $html, 'uxstudioFormHooks' ) && $has( $html, '[12]=function(detail){' ) && ! $has( $html, 'eval(' ) && ! $has( $html, 'new Function' ) );
$assert( 'script: a trailing line comment cannot swallow the closing brace', $has( $html, "\n};" ) );
$assert( 'script: empty code prints nothing', '' === A::hook_script( 1, "  \n" ) );
$assert( 'script: the original text still reads the same inside a JS string', $has( $html, "'<\\/script>" ) );

/* ── tracking settings ── */

$t = A::sanitize_tracking( null );
$assert( 'tracking: defaults - dataLayer on, every preset off', true === $t['datalayer'] && false === $t['ga4']['enabled'] && false === $t['ads']['enabled'] && false === $t['meta']['enabled'] && false === $t['sklik']['enabled'] && 'generate_lead' === $t['ga4']['event'] );
$t = A::sanitize_tracking( array( 'datalayer' => 0 ) );
$assert( 'tracking: dataLayer can be switched off', false === $t['datalayer'] );

foreach ( array( 'generate_lead', 'form_submit', 'Lead2' ) as $ok ) {
	$assert( "ga4 event '$ok' is accepted", $ok === A::sanitize_tracking( array( 'ga4' => array( 'event' => $ok ) ) )['ga4']['event'] );
}
foreach ( array( '1abc', 'has space', 'x-y', 'a;b', str_repeat( 'a', 41 ), '' ) as $bad ) {
	$assert( "ga4 event '" . substr( $bad, 0, 12 ) . "' falls back", 'generate_lead' === A::sanitize_tracking( array( 'ga4' => array( 'event' => $bad ) ) )['ga4']['event'] );
}
foreach ( array( 'AW-123456789/AbC-dEf_1' => true, 'AW-1/x' => true, 'AW-123' => false, 'AW-abc/x' => false, 'G-123/x' => false, 'AW-123/' => false, 'AW-123/a b' => false, "AW-1/x'};alert(1)//" => false ) as $v => $valid ) {
	$assert( "ads send_to '$v' " . ( $valid ? 'accepted' : 'rejected' ), ( $valid ? $v : '' ) === A::sanitize_tracking( array( 'ads' => array( 'send_to' => $v ) ) )['ads']['send_to'] );
}
$t = A::sanitize_tracking( array( 'ads' => array( 'value' => '-5', 'currency' => 'eur' ) ) );
$assert( 'ads: a negative value is dropped, the currency is upper-cased', null === $t['ads']['value'] && 'EUR' === $t['ads']['currency'] );
$t = A::sanitize_tracking( array( 'ads' => array( 'value' => '1299.5', 'currency' => 'euro' ) ) );
$assert( 'ads: a value is kept, a bad currency falls back to CZK', 1299.5 === $t['ads']['value'] && 'CZK' === $t['ads']['currency'] );

$t = A::sanitize_tracking( array( 'meta' => array( 'enabled' => true, 'custom' => false, 'event' => 'Contact' ) ) );
$assert( 'meta: a standard event is kept', 'Contact' === $t['meta']['event'] && false === $t['meta']['custom'] );
$t = A::sanitize_tracking( array( 'meta' => array( 'custom' => false, 'event' => 'NotAnEvent' ) ) );
$assert( 'meta: an unknown "standard" event becomes Lead', 'Lead' === $t['meta']['event'] );
$t = A::sanitize_tracking( array( 'meta' => array( 'custom' => true, 'event' => 'My_Form_1' ) ) );
$assert( 'meta: a valid custom name is kept with trackCustom', 'My_Form_1' === $t['meta']['event'] && true === $t['meta']['custom'] );
$t = A::sanitize_tracking( array( 'meta' => array( 'custom' => true, 'event' => "x');alert(1);//" ) ) );
$assert( 'meta: a hostile custom name falls back to Lead (standard)', 'Lead' === $t['meta']['event'] && false === $t['meta']['custom'] );

$t = A::sanitize_tracking( array( 'sklik' => array( 'enabled' => true, 'id' => '1234567', 'consent' => 0 ) ) );
$assert( 'sklik: a numeric id and consent 0 are kept', '1234567' === $t['sklik']['id'] && 0 === $t['sklik']['consent'] );
$t = A::sanitize_tracking( array( 'sklik' => array( 'enabled' => true, 'id' => '12ab', 'consent' => 5 ) ) );
$assert( 'sklik: a non-numeric id is dropped, consent defaults to 1', '' === $t['sklik']['id'] && 1 === $t['sklik']['consent'] );

$c = A::client_tracking( A::sanitize_tracking( array( 'ga4' => array( 'enabled' => true ), 'ads' => array( 'enabled' => true, 'send_to' => '' ), 'meta' => array( 'enabled' => true, 'custom' => true, 'event' => 'Foo' ), 'custom_js' => 'secret();' ) ) );
$assert( 'client config: enabled presets only, an Ads preset without a target is left out', is_array( $c['ga4'] ) && null === $c['ads'] && 'trackCustom' === $c['meta']['method'] && null === $c['sklik'] );
$assert( 'client config: the custom script never reaches it', ! $has( wp_json_encode( $c ), 'secret' ) );
$t = A::sanitize_tracking( array( 'custom_js' => str_repeat( 'a', 50000 ) . "\0" ) );
$assert( 'tracking: the script is capped and has no NUL byte', strlen( $t['custom_js'] ) <= A::CUSTOM_JS_MAX && ! $has( $t['custom_js'], "\0" ) );

/* ── markup and templates ── */

$box = A::box_html( null );
$assert( 'markup: the empty box is hidden, role=status, aria-live, title focusable', $has( $box, 'role="status"' ) && $has( $box, 'aria-live="polite"' ) && $has( $box, ' hidden>' ) && $has( $box, 'data-uxs-done-title tabindex="-1"' ) );
$box = A::box_html( array( 'mode' => 'replace', 'title' => 'T<', 'html' => '<p>x</p>', 'icon' => 'check', 'accent' => '#112233', 'again' => false ) );
$assert( 'markup: a filled box shows the icon, escapes the title and carries the accent', $has( $box, '<svg' ) && $has( $box, 'T&lt;' ) && $has( $box, '--uxs-done:#112233' ) && ! $has( substr( $box, 0, (int) strpos( $box, '>' ) ), 'hidden' ) );
$doc = A::preview_document( array( 'mode' => 'replace', 'title' => 'T', 'html' => '', 'icon' => 'none', 'accent' => '', 'again' => false ), 'dark' );
$assert( 'markup: the preview document is dark on request and has no script', $has( $doc, '#111827' ) && ! $has( $doc, '<script' ) && $has( $doc, 'uxs-fp-done--no-icon' ) );
$css = A::done_css();
$assert( 'css: the ring (151) then the tick (40) draw in, reduced motion switches animation off', $has( $css, 'stroke-dasharray:151' ) && $has( $css, 'stroke-dasharray:40' ) && $has( $css, 'prefers-reduced-motion' ) );

$payload = Templates::payload( 'booking' );
$assert( 'template: booking has its own thank-you title and message', is_array( $payload ) && $has( (string) $payload['settings']['after_submit']['title'], 'Reservation received' ) && $has( (string) $payload['settings']['after_submit']['message'], '<p>' ) && ! isset( $payload['settings']['thanks_title'] ) );
foreach ( array( 'order' => 'Order sent', 'newsletter' => 'You are subscribed' ) as $id => $title ) {
	$p = Templates::payload( $id );
	$assert( "template: $id title is '$title'", is_array( $p ) && $title === $p['settings']['after_submit']['title'] );
}
$ids = array_column( Templates::catalog(), 'id' );
$all = true;
foreach ( $ids as $id ) {
	$p    = Templates::payload( (string) $id );
	$all  = $all && is_array( $p ) && '' !== (string) $p['settings']['after_submit']['title'];
}
$assert( 'template: every template has a thank-you title', $all );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
