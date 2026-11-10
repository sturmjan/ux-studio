<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the legal pages (PLAN.md 42, F4): the template engine, the validation of the wizard
 * data, the template files (balanced, in both languages) and the checklist of required contents (GDPR Art. 13, cookie
 * policy, consumer terms) run against the real templates.
 *
 *   php tests/consent-legal.php
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function esc_url_raw( $s, $protocols = null ) { return preg_match( '#^https?://[^\s<>"\']+$#i', (string) $s ) ? (string) $s : ''; }

$root = dirname( __DIR__ ) . '/includes/Modules/Consent/';
require_once $root . 'LegalTemplate.php';
require_once $root . 'LegalProfile.php';

use UxStudio\Modules\Consent\LegalProfile;
use UxStudio\Modules\Consent\LegalTemplate;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

// ---------------------------------------------------------------- template engine
$assert( 'variable is escaped', 'a &lt;b&gt; &amp; &quot;c&quot;' === LegalTemplate::render( 'a {{x}}', array( 'x' => '<b> & "c"' ) ) );
$assert( 'triple braces print the HTML as it is', '<i>ok</i>' === LegalTemplate::render( '{{{x}}}', array( 'x' => '<i>ok</i>' ) ) );
$assert( 'unknown variable is empty', 'a|b' === LegalTemplate::render( 'a|{{nothing}}b', array() ) );
$assert( 'if: kept when truthy', 'A' === LegalTemplate::render( '{% if f %}A{% endif %}', array( 'f' => 'x' ) ) );
$assert( 'if: dropped when empty, "0", false, null, empty list', '' === LegalTemplate::render( '{% if a %}1{% endif %}{% if b %}2{% endif %}{% if c %}3{% endif %}{% if d %}4{% endif %}{% if e %}5{% endif %}', array( 'a' => '', 'b' => '0', 'c' => false, 'd' => null, 'e' => array() ) ) );
$assert( 'else branch', 'no' === LegalTemplate::render( '{% if f %}yes{% else %}no{% endif %}', array( 'f' => false ) ) );
$assert( 'not', 'N' === LegalTemplate::render( '{% if not f %}N{% endif %}', array() ) );
$assert( 'nested conditions', 'xz' === LegalTemplate::render( '{% if a %}x{% if b %}y{% endif %}{% if c %}z{% endif %}{% endif %}', array( 'a' => true, 'c' => true ) ) );
$assert( 'nested else', 'in2' === LegalTemplate::render( '{% if a %}{% if b %}in1{% else %}in2{% endif %}{% else %}out{% endif %}', array( 'a' => true ) ) );
$assert( 'a value cannot inject a tag', 'x{% if a %}y{% endif %}' !== LegalTemplate::render( '{{v}}', array( 'v' => 'x' ) ) && '{% if a %}Y{% endif %}' === html_entity_decode( LegalTemplate::render( '{{v}}', array( 'v' => '{% if a %}Y{% endif %}', 'a' => true ) ) ) );
$assert( 'balanced: ok and broken', LegalTemplate::balanced( '{% if a %}{% else %}{% endif %}' ) && ! LegalTemplate::balanced( '{% if a %}' ) && ! LegalTemplate::balanced( '{% endif %}' ) && ! LegalTemplate::balanced( '{% if a %}{% else %}{% else %}{% endif %}' ) );
$assert( 'names lists variables and flags', array( 'a', 'b', 'c' ) === LegalTemplate::names( '{{a}} {{{b}}} {% if not c %}{% endif %}' ) );

// ---------------------------------------------------------------- profile validation
$base = LegalProfile::defaults( true );
$ok   = LegalProfile::sanitize(
	array(
		'name'       => '  Firma <b>s.r.o.</b> ',
		'ico'        => '123 456 78',
		'dic'        => 'cz12345678',
		'address'    => "Ulice 1\nPraha 1",
		'email'      => 'info@example.cz',
		'phone'      => '+420 123 456 789',
		'dpo'        => 'true',
		'dpo_contact' => 'dpo@example.cz',
		'purposes'   => array( 'contact', 'nonsense', 'orders', 'contact' ),
		'retention'  => array( 'orders' => '10 let', 'nonsense' => 'x', 'contact' => '' ),
		'recipients' => array( array( 'kind' => 'carrier', 'name' => 'Zásilkovna', 'third_country' => '0' ), array( 'kind' => 'bogus', 'name' => 'Banka', 'third_country' => 'true' ), array( 'kind' => 'other', 'name' => '' ), 'text' ),
		'return_costs' => 'seller',
		'language'   => 'fr',
		'authority_url' => 'https://www.uoou.gov.cz',
		'unknown_key' => 'dropped',
	),
	$base
);
$v = $ok['values'];
$assert( 'valid input: no errors', array() === $ok['errors'], json_encode( $ok['errors'] ) );
$assert( 'name has tags stripped and is trimmed', 'Firma s.r.o.' === $v['name'] );
$assert( 'ico loses spaces, dic is upper case', '12345678' === $v['ico'] && 'CZ12345678' === $v['dic'] );
$assert( 'address on several lines becomes one line', 'Ulice 1, Praha 1' === $v['address'] );
$assert( 'booleans from strings', true === $v['dpo'] );
$assert( 'purposes: unknown dropped, duplicates dropped, catalogue order kept', array( 'orders', 'contact' ) === $v['purposes'], json_encode( $v['purposes'] ) );
$assert( 'retention: unknown key and empty value dropped', array( 'orders' => '10 let' ) === $v['retention'] );
$assert( 'recipients: empty name and non-arrays dropped, unknown kind becomes other', 2 === count( $v['recipients'] ) && 'other' === $v['recipients'][1]['kind'] && true === $v['recipients'][1]['third_country'] && false === $v['recipients'][0]['third_country'] );
$assert( 'language outside cs, en becomes auto', 'auto' === $v['language'] );
$assert( 'unknown keys never reach the profile', ! array_key_exists( 'unknown_key', $v ) );
$assert( 'return costs by the seller', 'seller' === $v['return_costs'] );

$bad = LegalProfile::sanitize(
	array(
		'ico' => '12ab', 'dic' => 'X', 'email' => 'nope', 'phone' => 'call me', 'authority_url' => 'javascript:alert(1)',
		'name' => str_repeat( 'a', 151 ), 'dpo' => true, 'dpo_contact' => '',
		'recipients' => array_fill( 0, 31, array( 'kind' => 'other', 'name' => 'X' ) ),
	),
	$base
);
foreach ( array( 'ico', 'dic', 'email', 'phone', 'authority_url', 'name', 'dpo_contact', 'recipients' ) as $field ) {
	$assert( "invalid $field is reported", isset( $bad['errors'][ $field ] ) );
}
$assert( 'an invalid value is not stored', '' === $bad['values']['ico'] && '' === $bad['values']['email'] && $base['authority_url'] === $bad['values']['authority_url'] );
$partial = LegalProfile::sanitize( array( 'phone' => '123' ), array_merge( $base, array( 'name' => 'Kept' ) ) );
$assert( 'values missing in the input stay as they were', 'Kept' === $partial['values']['name'] && '123' === $partial['values']['phone'] );

$assert( 'missing: empty profile lacks name, address, e-mail', array( 'name', 'address', 'email' ) === array_keys( LegalProfile::missing( 'cookies', $base ) ) );
$assert( 'missing: privacy needs a purpose, terms need ico and a shop', isset( LegalProfile::missing( 'privacy', array_merge( $base, array( 'purposes' => array() ) ) )['purposes'] ) && isset( LegalProfile::missing( 'terms', array_merge( $base, array( 'has_eshop' => false ) ) )['has_eshop'] ) && isset( LegalProfile::missing( 'terms', $base )['ico'] ) );
$full = array_merge( $base, array( 'name' => 'A', 'address' => 'B', 'email' => 'c@d.cz', 'ico' => '12345678' ) );
$assert( 'missing: a complete profile has nothing missing for any document', array() === LegalProfile::missing( 'cookies', $full ) && array() === LegalProfile::missing( 'privacy', $full ) && array() === LegalProfile::missing( 'terms', $full ) );

// ---------------------------------------------------------------- purposes file
$purposes = LegalProfile::purposes();
$assert( 'purposes: eight known keys', array( 'orders', 'accounts', 'contact', 'newsletter', 'reviews', 'analytics', 'marketing', 'security' ) === array_keys( $purposes ) );
$shape = true;
foreach ( $purposes as $key => $def ) {
	foreach ( array( 'cs', 'en' ) as $lang ) {
		foreach ( array( 'title', 'data', 'why', 'interest', 'retention' ) as $field ) {
			$shape = $shape && isset( $def[ $lang ][ $field ] );
		}
	}
	$shape = $shape && array() !== $def['basis'] && array() === array_diff( $def['basis'], array( 'contract', 'legal', 'consent', 'interest' ) );
	if ( in_array( 'interest', $def['basis'], true ) ) {
		$shape = $shape && '' !== $def['cs']['interest'] && '' !== $def['en']['interest'];
	}
}
$assert( 'purposes: texts in both languages, valid bases, a stated interest wherever the basis is a legitimate interest', $shape );

// ---------------------------------------------------------------- the templates
$templates = array();
foreach ( array( 'cs', 'en' ) as $lang ) {
	foreach ( LegalProfile::DOCS as $doc ) {
		$file = $root . "legal/$lang/$doc.php";
		$text = is_readable( $file ) ? (string) require $file : '';
		$templates[ $lang ][ $doc ] = $text;
		$assert( "template $lang/$doc exists, is balanced, has no long dash", '' !== $text && LegalTemplate::balanced( $text ) && false === strpos( $text, "\u{2014}" ) && false === strpos( $text, "\u{2013}" ) );
		$assert( "template $lang/$doc: only h2 to h4, no scripts, no inline styles", 1 !== preg_match( '/<(script|style|iframe|h1)\b|style=|on[a-z]+=/i', $text ) );
	}
}
// Both languages use the same names, so a variable cannot be forgotten in one of them.
foreach ( LegalProfile::DOCS as $doc ) {
	$cs = LegalTemplate::names( $templates['cs'][ $doc ] );
	$en = LegalTemplate::names( $templates['en'][ $doc ] );
	sort( $cs );
	sort( $en );
	$assert( "template $doc: Czech and English use the same variables", $cs === $en, 'cs-en: ' . implode( ',', array_diff( $cs, $en ) ) . ' en-cs: ' . implode( ',', array_diff( $en, $cs ) ) );
}

// Render with every flag on and every variable set to a marker, then run the checklist against the visible text.
$render = static function ( string $lang, string $doc, array $override = array() ) use ( $templates ): string {
	$vars = array();
	foreach ( LegalTemplate::names( $templates[ $lang ][ $doc ] ) as $name ) {
		$vars[ $name ] = 0 === strpos( $name, 'has_' ) || 0 === strpos( $name, 'uses_' ) || 0 === strpos( $name, 'p_' ) || 0 === strpos( $name, 'return_by_' ) || 0 === strpos( $name, 'prices_' ) || 'log_on' === $name ? true : '[' . $name . ']';
	}
	$vars['return_by_seller'] = false;
	$vars['prices_without_tax'] = false;
	return LegalTemplate::render( $templates[ $lang ][ $doc ], array_merge( $vars, $override ) );
};
$text = static fn( string $html ): string => mb_strtolower( html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' ), 'UTF-8' );

/*
 * Checklist (PLAN 42.14). Each row: label => list of substrings of which every one must be in the visible text of the
 * rendered document. Czech and English are checked separately because the wording differs.
 */
$checklist = array(
	'privacy' => array(
		'cs' => array(
			'čl. 13: controller identity and contact' => array( '[controller]', '[address]', '[email]' ),
			'čl. 13: data protection officer (or the statement that there is none)' => array( '[dpo_contact]' ),
			'čl. 13: purposes with legal basis (art. 6)' => array( '[purposes_html]', 'na jakém základě' ),
			'čl. 13: recipients' => array( '[recipients_html]', 'komu údaje předáváme' ),
			'čl. 13: transfers to third countries' => array( '[transfers_html]', 'kapitoly v gdpr' ),
			'čl. 13: retention period' => array( 'jak dlouho údaje uchováváme', 'promlčecí lhůty' ),
			'čl. 13: right of access' => array( 'čl. 15' ),
			'čl. 13: rectification' => array( 'čl. 16' ),
			'čl. 13: erasure' => array( 'čl. 17' ),
			'čl. 13: restriction' => array( 'čl. 18' ),
			'čl. 13: portability' => array( 'čl. 20' ),
			'čl. 13: objection incl. direct marketing' => array( 'čl. 21', 'přímý marketing' ),
			'čl. 13: withdrawal of consent' => array( 'čl. 7 odst. 3', 'odvolat' ),
			'čl. 13: right to complain to the authority' => array( '[authority]', 'stížnost' ),
			'čl. 13: whether providing data is obligatory' => array( 'musíte nám údaje poskytnout', 'dobrovolné' ),
			'čl. 13: automated decision-making and profiling' => array( 'automatizované rozhodování' ),
			'čl. 12: deadline of one month' => array( 'jednoho měsíce' ),
			'link to the cookie policy' => array( 'zásadách používání cookies' ),
		),
		'en' => array(
			'Art. 13: controller identity and contact' => array( '[controller]', '[address]', '[email]' ),
			'Art. 13: data protection officer' => array( '[dpo_contact]' ),
			'Art. 13: purposes with legal basis' => array( '[purposes_html]', 'legal basis' ),
			'Art. 13: recipients' => array( '[recipients_html]', 'who receives the data' ),
			'Art. 13: transfers to third countries' => array( '[transfers_html]', 'chapter v of the gdpr' ),
			'Art. 13: retention period' => array( 'how long we keep the data', 'limitation period' ),
			'Art. 13: access, rectification, erasure, restriction, portability' => array( 'art. 15', 'art. 16', 'art. 17', 'art. 18', 'art. 20' ),
			'Art. 13: objection incl. direct marketing' => array( 'art. 21', 'direct marketing' ),
			'Art. 13: withdrawal of consent' => array( 'art. 7(3)', 'withdraw' ),
			'Art. 13: right to complain' => array( '[authority]', 'complaint' ),
			'Art. 13: whether providing data is obligatory' => array( 'do you have to give us the data', 'voluntary' ),
			'Art. 13: automated decision-making' => array( 'automated decision-making' ),
			'Art. 12: one month' => array( 'one month' ),
			'link to the cookie policy' => array( 'cookie policy' ),
		),
	),
	'cookies' => array(
		'cs' => array(
			'categories: necessary, preferences, statistics, marketing (from the report)' => array( '[cookie_tables]' ),
			'legal basis: §89(3) ZEK, consent and the exception' => array( '§ 89 odst. 3', 'bez souhlasu', 'nezbytné' ),
			'who is responsible' => array( '[controller]', '[address]' ),
			'how to change or withdraw consent (button)' => array( '[cookie_button]', 'odvolat souhlas' ),
			'duration of the consent' => array( '[consent_days]' ),
			'proof of consent record and its retention' => array( '[log_years]' ),
			'third parties and providers' => array( 'poskytovatel', 'třetích stran' ),
			'transfers outside the EEA' => array( '[transfers_html]' ),
			'how to block cookies in the browser' => array( 'v prohlížeči' ),
			'versions of the policy' => array( 'verze' ),
		),
		'en' => array(
			'categories from the report' => array( '[cookie_tables]' ),
			'legal basis: section 89(3), consent and the exception' => array( 'section 89(3)', 'without consent', 'necessary' ),
			'who is responsible' => array( '[controller]', '[address]' ),
			'how to change or withdraw consent (button)' => array( '[cookie_button]', 'withdrawing consent' ),
			'duration of the consent' => array( '[consent_days]' ),
			'proof of consent record and its retention' => array( '[log_years]' ),
			'third parties and providers' => array( 'provider', 'third-party' ),
			'transfers outside the EEA' => array( '[transfers_html]' ),
			'how to block cookies in the browser' => array( 'in the browser' ),
			'versions of the policy' => array( 'version' ),
		),
	),
	'terms' => array(
		'cs' => array(
			'seller identity (§1820)' => array( '[controller]', '[ico]', '[address]', '[email]' ),
			'order and conclusion of the contract' => array( 'potvrdíme přijetí objednávky', 'závazná objednávka' ),
			'price incl. taxes and delivery cost (§1820)' => array( '[currency]', 'daně z přidané hodnoty', 'cena dopravy' ),
			'payment methods from WooCommerce' => array( '[payment_html]' ),
			'delivery methods from WooCommerce' => array( '[shipping_html]' ),
			'withdrawal within 14 days (§1829)' => array( '14 dnů', '§ 1829' ),
			'withdrawal: exceptions (§1837)' => array( '§ 1837', 'nelze odstoupit' ),
			'withdrawal: model form' => array( 'vzorový formulář' ),
			'withdrawal: refund within 14 days and return of goods' => array( 'vrátíme nejpozději do 14 dnů', 'zboží nám pošlete' ),
			'who bears the cost of return' => array( 'náklady na vrácení zboží' ),
			'defects: warranty 24 months and rights' => array( '24 měsíců', 'opravu', 'slevu' ),
			'complaint handling: confirmation and 30 days (§19 ZOS)' => array( '30 dnů', 'potvrdíme vám' ),
			'out-of-court dispute resolution: ČOI' => array( 'česká obchodní inspekce', 'adr.coi.cz' ),
			'personal data link' => array( 'zásadách ochrany osobních údajů' ),
			'version applicable to the order' => array( 've znění, které bylo účinné' ),
		),
		'en' => array(
			'seller identity' => array( '[controller]', '[ico]', '[address]', '[email]' ),
			'order and conclusion of the contract' => array( 'confirm receipt of the order', 'binding order' ),
			'price incl. taxes and delivery cost' => array( '[currency]', 'value added tax', 'cost of delivery' ),
			'payment methods' => array( '[payment_html]' ),
			'delivery methods' => array( '[shipping_html]' ),
			'withdrawal within 14 days' => array( '14 days', 'section 1829' ),
			'withdrawal: exceptions' => array( 'section 1837', 'cannot withdraw' ),
			'withdrawal: model form' => array( 'model withdrawal form' ),
			'withdrawal: refund and return' => array( 'within 14 days of the withdrawal', 'send or hand the goods back' ),
			'who bears the cost of return' => array( 'cost of returning the goods' ),
			'defects: warranty 24 months and rights' => array( '24 months', 'repair', 'discount' ),
			'complaint handling: confirmation and 30 days' => array( '30 days', 'we will confirm' ),
			'out-of-court dispute resolution' => array( 'czech trade inspection authority', 'adr.coi.cz' ),
			'personal data link' => array( 'privacy policy' ),
			'version applicable to the order' => array( 'as they stood on the day' ),
		),
	),
);
foreach ( $checklist as $doc => $by_lang ) {
	foreach ( $by_lang as $lang => $rows ) {
		$visible = $text( $render( $lang, $doc ) );
		foreach ( $rows as $label => $needles ) {
			$missing = array_filter( $needles, static fn( string $n ): bool => false === strpos( $visible, mb_strtolower( $n, 'UTF-8' ) ) );
			$assert( "checklist $doc/$lang: $label", array() === $missing, 'missing: ' . implode( ' | ', $missing ) );
		}
	}
}

// Optional parts really are optional.
$none = $text( $render( 'cs', 'privacy', array( 'has_dpo' => false, 'has_transfers' => false, 'has_recipients' => false, 'uses_consent' => false ) ) );
$assert( 'privacy cs: without DPO it says so, without transfers it says none, without recipients it names no list', false !== strpos( $none, 'pověřence pro ochranu osobních údajů nemáme' ) && false !== strpos( $none, 'mimo evropský hospodářský prostor nepředáváme' ) && false === strpos( $none, '[recipients_html]' ) );
$seller = $text( $render( 'cs', 'terms', array( 'return_by_customer' => false, 'return_by_seller' => true ) ) );
$assert( 'terms cs: return costs follow the wizard', false !== strpos( $seller, 'hradíme my' ) && false === strpos( $seller, 'nesete vy' ) );

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "ALL PASSED\n";
