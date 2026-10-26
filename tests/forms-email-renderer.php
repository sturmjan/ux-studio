<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Test of the e-mail notification renderer (PLAN.md 20.12): logo position, colours, contrast warnings,
 * Outlook-safe markup, sanitizing of the WYSIWYG message (images, links, scripts) and merge-tag escaping.
 *
 * Run inside a WordPress install that has UX Studio active, loading THIS checkout:
 *
 *   wp eval-file tests/forms-email-renderer.php --skip-plugins=wp-mail-smtp
 *
 * Exits non-zero when any case fails. Nothing is written to the database.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

$dir = dirname( __DIR__ ) . '/includes/Modules/Forms/';
require_once $dir . 'EmailTemplateRenderer.php';
require_once $dir . 'Fields.php';
require_once $dir . 'Module.php';

use UxStudio\Modules\Forms\EmailTemplateRenderer as R;
use UxStudio\Modules\Forms\Module;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};
$has = static fn( string $haystack, string $needle ): bool => false !== strpos( $haystack, $needle );

/* ── colours ── */

$assert( 'hex: #rgb expands, upper case is lowered', '#aabbcc' === R::sanitize_hex( '#ABC' ) && '#0a1b2c' === R::sanitize_hex( ' #0A1B2C ' ) );
$assert( 'hex: names, rgb(), missing hash and junk are rejected', '' === R::sanitize_hex( 'red' ) && '' === R::sanitize_hex( 'rgb(1,2,3)' ) && '' === R::sanitize_hex( '123456' ) && '' === R::sanitize_hex( '#12345' ) && '' === R::sanitize_hex( '#gggggg' ) && '' === R::sanitize_hex( '#fff;background:url(x)' ) );
$assert( 'contrast: black on white is 21', abs( R::contrast_ratio( '#000000', '#ffffff' ) - 21.0 ) < 0.01 );
$assert( 'contrast: same colour is 1', abs( R::contrast_ratio( '#336699', '#336699' ) - 1.0 ) < 0.001 );
$assert( 'readable text: white on dark, dark on light', '#ffffff' === R::readable_text_color( '#1f2937' ) && '#111827' === R::readable_text_color( '#fde047' ) );
$assert( 'warning: a fine blue link and header give none', array() === R::contrast_warnings( '#1d4ed8', '#1d4ed8' ) );
$w = array_column( R::contrast_warnings( '#1d4ed8', '#facc15' ), 'code' );
$assert( 'warning: a yellow link on the white body is reported', array( 'link_contrast' ) === $w, implode( ',', $w ) );
$w = array_column( R::contrast_warnings( '#777777', '#1d4ed8' ), 'code' );
$assert( 'warning: a mid-grey header (neither white nor dark text reaches 4.5) is reported', array( 'header_contrast' ) === $w, implode( ',', $w ) );

/* ── layout options ── */

$o = R::layout_options( array( 'template' => 'card', 'logo_position' => 'diagonal', 'logo_width' => 99999, 'header_bg' => 'nonsense', 'link_color' => '#ABCDEF' ) );
$assert( 'layout: bad position -> left, width clamped to 400, bad colour -> template accent, valid colour kept', 'left' === $o['logo_position'] && 400 === $o['logo_width'] && '#2563eb' === $o['header_bg'] && '#abcdef' === $o['link_color'] );
$assert( 'layout: width clamps from below too', 40 === R::clamp_logo_width( 3 ) && 160 === R::clamp_logo_width( 'x' ) );

/* ── rendering: logo position, colours, Outlook-safe markup ── */

$base = array(
	'heading'         => 'Contact form',
	'body_html'       => '<p>Hello <a href="https://example.com/x">link</a></p><p><img src="https://example.com/a.png" width="120" height="60" alt="A"></p>',
	'include_table'   => true,
	'fields_snapshot' => array( 'name' => array( 'label' => 'Name', 'type' => 'text' ) ),
	'values'          => array( 'name' => 'Jana' ),
	'cta_text'        => 'Open',
	'cta_url'         => 'https://example.com/open',
	'site_name'       => 'Site',
	'logo_url'        => 'https://example.com/logo.png',
	'logo_width'      => 180,
	'logo_height'     => 60,
);

foreach ( array( 'left', 'center', 'right' ) as $pos ) {
	$html = R::render( 'card', $base + array( 'logo_position' => $pos, 'header_bg' => '#0f172a', 'link_color' => '#0f766e' ) );
	$assert( "logo $pos: header cell is aligned $pos (attribute and CSS), logo is an inline image above the title", $has( $html, 'align="' . $pos . '" bgcolor="#0f172a"' ) && $has( $html, 'text-align:' . $pos . ';' ) && 1 === preg_match( '/<img src="https:\/\/example\.com\/logo\.png"[^>]*display:inline-block[^>]*><br>/', $html ) );
	$assert( "logo $pos: logo image has width, height and border attributes", $has( $html, 'width="180"' ) && $has( $html, 'height="60"' ) && $has( $html, 'border="0"' ) && $has( $html, 'src="https://example.com/logo.png"' ) );
}

$html = R::render( 'card', $base + array( 'logo_position' => 'center', 'header_bg' => '#0f172a', 'link_color' => '#0f766e' ) );
$assert( 'colours: header background as bgcolor attribute AND inline style', $has( $html, 'bgcolor="#0f172a" style="background-color:#0f172a;' ) );
$assert( 'colours: header text is white on a dark header', $has( $html, 'color:#ffffff;font-size:20px' ) );
$assert( 'colours: link in the message gets the link colour inline', 1 === preg_match( '/<a [^>]*href="https:\/\/example\.com\/x"[^>]*>link<\/a>/', $html ) && 1 === preg_match( '/<a [^>]*style="color:#0f766e;"[^>]*href="https:\/\/example\.com\/x"|<a [^>]*href="https:\/\/example\.com\/x"[^>]*style="color:#0f766e;"/', $html ) );
$assert( 'colours: button cell uses the link colour as bgcolor', $has( $html, 'bgcolor="#0f766e"' ) && $has( $html, 'href="https://example.com/open"' ) );
$light = R::render( 'card', $base + array( 'header_bg' => '#fde047' ) );
$assert( 'colours: header text turns dark on a light header', $has( $light, 'color:#111827;font-size:20px' ) );
$default = R::render( 'branded', array( 'heading' => 'X', 'body_html' => '<p>b</p>', 'logo_url' => '' ) );
$assert( 'defaults: header and button use the template accent, no logo block without a logo', $has( $default, 'bgcolor="#7c3aed"' ) && ! $has( $default, '<img' ) );

$assert( 'outlook: layout is tables with role, width, cellpadding, cellspacing', $has( $html, '<table role="presentation" width="600"' ) && $has( $html, 'cellpadding="0"' ) && $has( $html, 'cellspacing="0"' ) );
$assert( 'outlook: page and card backgrounds are also bgcolor attributes', $has( $html, 'bgcolor="#f3f4f6"' ) && $has( $html, 'bgcolor="#ffffff"' ) );
$assert( 'outlook: no flex, grid, position, float, rgba or CSS variables in the markup', 0 === preg_match( '/display:\s*(flex|grid)|position:|float:|rgba\(|var\(--/i', $html ) );
$assert( 'outlook: only the mso conditional carries a style block', 1 === substr_count( $html, '<style' ) && $has( $html, '<!--[if mso]><style' ) );
$assert( 'outlook: the button is a padded table cell with a link inside (bulletproof button)', 1 === preg_match( '/<td align="center" bgcolor="#0f766e"[^>]*>\s*<a href="[^"]+" style="display:inline-block;padding:11px 22px;/', $html ) );
$assert( 'outlook: every image carries a width attribute', 0 === preg_match( '/<img(?![^>]*\swidth=)/i', $html ) );
$assert( 'outlook: the submission table uses valign and inline fonts', $has( $html, 'valign="top"' ) && $has( $html, 'font-family:Arial' ) );

/* ── the WYSIWYG message ── */

$dirty = '<p onclick="x()">Hi <strong>there</strong></p>'
	. '<script>alert(1)</script><iframe src="https://evil.example"></iframe>'
	. '<img src="javascript:alert(1)" onerror="alert(2)" alt="bad">'
	. '<img src="data:image/png;base64,AAAA" alt="data">'
	. '<img src="https://example.com/ok.png" alt="ok" width="300" height="100" style="position:fixed;max-width:100%;border-radius:4px" onload="x()">'
	. '<a href="javascript:alert(3)">js link</a> <a href="https://example.com/ok" onmouseover="x()" title="t">fine</a> <a href="mailto:a@example.com">mail</a>'
	. '<form action="/x"><input name="a"></form><object data="x"></object><style>p{color:red}</style>';
$clean = R::sanitize_message( $dirty );
$assert( 'message: script, iframe, form, object and style block are removed', ! preg_match( '/<(script|iframe|form|input|object|style)/i', $clean ) && ! $has( $clean, 'alert(1)</' ) );
$assert( 'message: event handler attributes are stripped everywhere', ! preg_match( '/\son[a-z]+=/i', $clean ), $clean );
$assert( 'message: javascript: is gone from src and href', ! $has( strtolower( $clean ), 'javascript:' ), $clean );
$assert( 'message: data: image is gone', ! $has( $clean, 'data:image' ) );
$assert( 'message: a normal http(s) image survives with alt, width and height', $has( $clean, 'src="https://example.com/ok.png"' ) && $has( $clean, 'alt="ok"' ) && $has( $clean, 'width="300"' ) && $has( $clean, 'height="100"' ) );
$assert( 'message: style is limited (position dropped, max-width kept)', ! $has( $clean, 'position' ) && $has( $clean, 'max-width:100%' ), $clean );
$assert( 'message: the text inside a removed script or style block is removed too', ! $has( $clean, 'alert(1)</' ) && ! $has( $clean, 'p{color:red}' ) && ! $has( $clean, '>alert(1)' ), $clean );
$sent = R::build_html( array( 'template' => 'card', 'message' => $dirty ), 'F', array( 'values' => array(), 'fields_snapshot' => array() ) );
$assert( 'the sent mail has no trace of the attack strings', ! $has( $sent, 'alert(' ) && ! $has( strtolower( $sent ), 'javascript' ) && ! preg_match( '/\son[a-z]+=/i', $sent ) );
$assert( 'message: https and mailto links and bold text survive', $has( $clean, 'href="https://example.com/ok"' ) && $has( $clean, 'href="mailto:a@example.com"' ) && $has( $clean, '<strong>there</strong>' ) );

$fin = R::finalize_html( '<img src="/wp-content/uploads/logo.png" alt="rel"><img src="//cdn.example.com/x.png" alt="proto"><img src="ftp://x/y.png" alt="ftp"><a href="javascript:alert(1)">x</a><a href="https://example.com">y</a>', '#112233' );
$assert( 'finalize: a relative image becomes an absolute URL on this site', $has( $fin, 'src="' . home_url( '/wp-content/uploads/logo.png' ) . '"' ) );
$assert( 'finalize: a protocol-relative image gets https', $has( $fin, 'src="https://cdn.example.com/x.png"' ) );
$assert( 'finalize: an ftp image loses its src', ! $has( $fin, 'ftp://' ) );
$assert( 'finalize: images get border, width-safe style', $has( $fin, 'border="0"' ) && $has( $fin, 'max-width:100%' ) );
$assert( 'finalize: javascript: link loses its href, the good link is coloured', ! $has( $fin, 'javascript:' ) && 1 === preg_match( '/<a [^>]*style="color:#112233;"[^>]*href="https:\/\/example\.com"|<a [^>]*href="https:\/\/example\.com"[^>]*style="color:#112233;"/', $fin ) && $has( $fin, '<a >x</a>' ), $fin );

/* ── merge tags ── */

$ctx = array(
	'form_title'      => 'F',
	'submission_date' => '2026-10-26',
	'fields_snapshot' => array(),
	'values'          => array( 'name' => '<img src=x onerror=alert(1)>', 'site' => "evil\r\nBcc: a@b.cz", 'web' => 'javascript:alert(1)' ),
);
$assert( 'merge (html): a submitted value is HTML-escaped', $has( R::merge_tags( '<p>{name}</p>', $ctx, true ), '&lt;img src=x onerror=alert(1)&gt;' ) );
$assert( 'merge (plain, subject / recipient): the value stays as typed', $has( R::merge_tags( '{name}', $ctx ), '<img' ) );
$assert( 'merge: CR/LF are stripped so a value can not add mail headers', ! preg_match( '/[\r\n]/', R::merge_tags( '{site}', $ctx ) ) );
$built = R::build_html( array( 'template' => 'card', 'message' => '<p><a href="{web}">go</a> {name}</p>' ), 'F', $ctx );
$assert( 'build: a merged javascript: href never reaches the mail', ! $has( strtolower( $built ), 'javascript:' ) && ! $has( $built, '<img src=x' ) );
$table = R::build_html( array( 'template' => 'minimal', 'message' => '<p>{submission_table}</p>', 'include_table' => false ), 'F', array( 'fields_snapshot' => array( 'name' => array( 'label' => 'Name', 'type' => 'text' ) ), 'values' => array( 'name' => 'Jana' ) ) + $ctx );
$assert( 'build: {submission_table} survives the sanitizing (merged after it)', $has( $table, '<td valign="top"' ) && $has( $table, 'Jana' ) );
$custom = R::build_html( array( 'template' => 'custom', 'custom_html' => '<html><body><h1>{form_title}</h1><script>x()</script></body></html>' ), 'F', $ctx );
$assert( 'build: custom template is the admin document, scripts removed, tags merged', $has( $custom, '<h1>F</h1>' ) && ! $has( $custom, '<script' ) );

/* ── saving an action ── */

$module = ( new ReflectionClass( Module::class ) )->newInstanceWithoutConstructor();
$saved  = $module->sanitize_settings(
	array(
		'actions' => array(
			array(
				'type'          => 'email',
				'message'       => '<p>Hi</p><script>x()</script><img src="javascript:alert(1)">',
				'logo_id'       => 999999,
				'logo_position' => 'right',
				'logo_width'    => 5,
				'header_bg'     => '#F00',
				'link_color'    => 'blue',
				'cta_text'      => 'Go',
				'cta_url'       => 'javascript:alert(1)',
			),
		),
	)
);
$a = $saved['actions'][0];
$assert( 'save: message is sanitized', ! $has( $a['message'], '<script' ) && ! $has( strtolower( $a['message'] ), 'javascript:' ) && $has( $a['message'], '<p>Hi</p>' ) );
$assert( 'save: an attachment id that is not an image is dropped', 0 === $a['logo_id'] && '' === $a['logo_url'] );
$assert( 'save: position kept, width clamped, #F00 expanded, a colour name rejected', 'right' === $a['logo_position'] && 40 === $a['logo_width'] && '#ff0000' === $a['header_bg'] && '' === $a['link_color'] );
$assert( 'save: a javascript: button URL is dropped', '' === $a['cta_url'] );
$again = $module->sanitize_settings( $saved );
$assert( 'save: sanitizing twice changes nothing', $again['actions'] === $saved['actions'] );

echo PHP_EOL . ( 0 === $failures ? 'All passed.' : "$failures FAILED." ) . PHP_EOL;
if ( $failures ) {
	exit( 1 );
}
