<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test (no WordPress) of the detection of the controller's details (PLAN.md 42.19): the checksum of the
 * company number, the extraction from sample pages, the choice of the e-mail on the domain of the site, the mapping of
 * ARES, of the SEO plugins and of the shop, the purposes and the order of the sources.
 *
 *   php tests/consent-legal-detect.php
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
require_once $root . 'LegalProfile.php';
require_once $root . 'LegalDetect.php';
require_once $root . 'ComplianzMap.php';

use UxStudio\Modules\Consent\ComplianzMap;
use UxStudio\Modules\Consent\LegalDetect as D;
use UxStudio\Modules\Consent\LegalProfile;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};
$show = static fn( $v ): string => json_encode( $v, JSON_UNESCAPED_UNICODE );

/** Complete seven digits to a valid company number. */
$mk = static function ( string $seven ): string {
	$sum = 0;
	for ( $i = 0; $i < 7; $i++ ) {
		$sum += (int) $seven[ $i ] * ( 8 - $i );
	}
	$c = 11 - ( $sum % 11 );
	return $seven . ( 10 === $c ? 0 : ( 11 === $c ? 1 : $c ) );
};
$ico1 = $mk( '1234567' );
$ico2 = $mk( '0099887' );
$ico3 = $mk( '4455667' );

// ---------------------------------------------------------------- checksum
$assert( 'valid numbers pass the checksum', D::ico_valid( $ico1 ) && D::ico_valid( $ico2 ) && D::ico_valid( $ico3 ) );
$assert( 'a changed digit fails', ! D::ico_valid( substr( $ico1, 0, 7 ) . ( ( (int) $ico1[7] + 1 ) % 10 ) ) );
$assert( 'seven digits, letters and empty fail', ! D::ico_valid( '1234567' ) && ! D::ico_valid( 'abcdefgh' ) && ! D::ico_valid( '' ) );
$assert( 'the number of a real register entry passes (06674861)', D::ico_valid( '06674861' ) );

// ---------------------------------------------------------------- sample 1: s.r.o. with number, VAT, register entry
$page1 = '<html><head><title>Kontakt</title><script>var x="IČO 11111111";</script></head><body>
<header><a href="tel:+420777111222">Zavolejte</a></header>
<main><h1>Kontakt</h1>
<p><a href="mailto:jan.admin@gmail.com">jan.admin@gmail.com</a></p>
<ul><li>+420 602 111 222</li><li><a href="mailto:info@priklad-firma.cz">info@priklad-firma.cz</a></li></ul>
<div class="firm"><b>Příklad Firma, s.r.o.</b><br>Dlouhá 12, 110 00 Praha 1</div>
<dl><dt>IČO</dt><dd>' . $ico1 . '</dd><dt>DIČ</dt><dd>CZ' . $ico1 . '</dd><dt>Zapsáno</dt><dd>u Městského soudu v Praze, oddíl C, vložka 123456</dd></dl>
</main><footer>© 2026 Příklad Firma, s.r.o. | Všechna práva vyhrazena</footer></body></html>';
$f = D::from_page( $page1, 'www.priklad-firma.cz' );
$assert( 'sample 1: company number', ( $f['ico']['value'] ?? '' ) === $ico1, $show( $f ) );
$assert( 'sample 1: the number inside a script is ignored', ( $f['ico']['value'] ?? '' ) !== '11111111' );
$assert( 'sample 1: VAT number', ( $f['dic']['value'] ?? '' ) === 'CZ' . $ico1, $show( $f['dic'] ?? null ) );
$assert( 'sample 1: register entry in the sentence form', ( $f['register_note']['value'] ?? '' ) === 'zapsaná v obchodním rejstříku vedeném Městským soudem v Praze, oddíl C, vložka 123456', $show( $f['register_note'] ?? null ) );
$assert( 'sample 1: e-mail on the domain beats the gmail of the admin', ( $f['email']['value'] ?? '' ) === 'info@priklad-firma.cz' && 'high' === $f['email']['confidence'], $show( $f['email'] ?? null ) );
$assert( 'sample 1: phone with the country code is written the same way', ( $f['phone']['value'] ?? '' ) === '+420 602 111 222', $show( $f['phone'] ?? null ) );
$assert( 'sample 1: address with postal code and town', ( $f['address']['value'] ?? '' ) === 'Dlouhá 12, 110 00 Praha 1', $show( $f['address'] ?? null ) );
$assert( 'sample 1: company name', ( $f['name']['value'] ?? '' ) === 'Příklad Firma, s.r.o.', $show( $f['name'] ?? null ) );

// ---------------------------------------------------------------- sample 2: footer on one line, spaced hyphen, sentence with the name
$page2 = '<footer><p>HYDRO - TEST CZ, s.r.o. Horní Lhota 188, 543 41 Lánov</p><table><tr><td>IČO</td><td>' . $ico2 . '</td></tr><tr><td>Zapsáno</td><td>u Krajského soudu v Hradci Králové, oddíl C, vložka 40698</td></tr></table>
<p>Z důvodu nárůstu zakázek jsme změnili strukturu na společnost s ručením omezeným, HYDRO - TEST CZ, s.r.o.</p><p>Tel: 604 909 533</p></footer>';
$f = D::from_page( $page2, 'localhost' );
$assert( 'sample 2: name stops at the lowercase word of the sentence and the two mentions agree', ( $f['name']['value'] ?? '' ) === 'HYDRO - TEST CZ, s.r.o.', $show( $f['name'] ?? null ) );
$assert( 'sample 2: address after the legal form', ( $f['address']['value'] ?? '' ) === 'Horní Lhota 188, 543 41 Lánov', $show( $f['address'] ?? null ) );
$assert( 'sample 2: company number from a table', ( $f['ico']['value'] ?? '' ) === $ico2 );
$assert( 'sample 2: court of the region', ( $f['register_note']['value'] ?? '' ) === 'zapsaná v obchodním rejstříku vedeném Krajským soudem v Hradci Králové, oddíl C, vložka 40698', $show( $f['register_note'] ?? null ) );
$assert( 'sample 2: a number after the word Tel without the country code', ( $f['phone']['value'] ?? '' ) === '+420 604 909 533', $show( $f['phone'] ?? null ) );

// ---------------------------------------------------------------- sample 3: sole trader
$page3 = "<div><h2>Fakturační údaje</h2><p>Ing. Petr Svoboda</p><p>IČO: {$ico3}</p><p>Sídlo: Květná 5<br>602 00 Brno</p><p>Mobil +420 721 000 111, petr@svoboda-web.cz</p><p>Fyzická osoba zapsaná v živnostenském rejstříku.</p></div>";
$f = D::from_page( $page3, 'svoboda-web.cz' );
$assert( 'sample 3: sole trader name next to the number', ( $f['name']['value'] ?? '' ) === 'Ing. Petr Svoboda' && 'low' === $f['name']['confidence'], $show( $f['name'] ?? null ) );
$assert( 'sample 3: street on its own line above the postal code', ( $f['address']['value'] ?? '' ) === 'Květná 5, 602 00 Brno', $show( $f['address'] ?? null ) );
$assert( 'sample 3: no register entry and no VAT invented', ! isset( $f['register_note'], $f['dic'] ) );
$assert( 'sample 3: e-mail from the text', ( $f['email']['value'] ?? '' ) === 'petr@svoboda-web.cz' );

// ---------------------------------------------------------------- sample 4: a.s. with the second form of the register entry
$page4 = '<p>Velká Společnost a.s., Průmyslová 1, 702 00 Ostrava, IČ: ' . substr( $ico1, 0, 2 ) . ' ' . substr( $ico1, 2, 3 ) . ' ' . substr( $ico1, 5 ) . ', spisová značka B 1234 vedená u Krajského soudu v Ostravě | DIČ CZ' . $ico1 . '</p>';
$f = D::from_page( $page4, 'x.cz' );
$assert( 'sample 4: number written with spaces', ( $f['ico']['value'] ?? '' ) === $ico1, $show( $f['ico'] ?? null ) );
$assert( 'sample 4: a.s. name', ( $f['name']['value'] ?? '' ) === 'Velká Společnost a.s.', $show( $f['name'] ?? null ) );
$assert( 'sample 4: register entry by file number', ( $f['register_note']['value'] ?? '' ) === 'zapsaná v obchodním rejstříku vedeném Krajským soudem v Ostravě, oddíl B, vložka 1234', $show( $f['register_note'] ?? null ) );
$assert( 'sample 4: address inside one line', ( $f['address']['value'] ?? '' ) === 'Průmyslová 1, 702 00 Ostrava', $show( $f['address'] ?? null ) );

// ---------------------------------------------------------------- sample 5: Slovak company
$page5 = '<p>Ukážková Slovensko, s. r. o., Hlavná 12, 811 01 Bratislava</p><p>IČO: 36 123 456 &nbsp; IČ DPH: SK2020123456</p><p>Obchodný register Okresného súdu Bratislava I, oddiel: Sro, vložka č.: 12345/B</p><p>Tel.: +421 902 123 456</p>';
$f = D::from_page( $page5, 'ukazka.sk' );
$assert( 'sample 5: Slovak VAT', ( $f['dic']['value'] ?? '' ) === 'SK2020123456', $show( $f['dic'] ?? null ) );
$assert( 'sample 5: Slovak phone keeps its country code', ( $f['phone']['value'] ?? '' ) === '+421 902 123 456', $show( $f['phone'] ?? null ) );
$assert( 'sample 5: Slovak register', ( $f['register_note']['value'] ?? '' ) === 'zapísaná v Obchodnom registri Okresného súdu Bratislava I, oddiel Sro, vložka č. 12345/B', $show( $f['register_note'] ?? null ) );
$assert( 'sample 5: Slovak name with spaces in the legal form', ( $f['name']['value'] ?? '' ) === 'Ukážková Slovensko, s. r. o.', $show( $f['name'] ?? null ) );
$assert( 'sample 5: Slovak address', ( $f['address']['value'] ?? '' ) === 'Hlavná 12, 811 01 Bratislava', $show( $f['address'] ?? null ) );
$assert( 'sample 5: company number 36123456 fails the checksum, so it is not taken', ! D::ico_valid( '36123456' ) === ! isset( $f['ico'] ) );

// ---------------------------------------------------------------- sample 7: name and address glued by inline markup
$f = D::from_page( '<div><strong>Skladová Firma, s.r.o.</strong><span>Dolní 7, 500 02 Hradec Králové</span></div>', 'x.cz' );
$assert( 'sample 7: glued legal form and street', ( $f['address']['value'] ?? '' ) === 'Dolní 7, 500 02 Hradec Králové' && ( $f['name']['value'] ?? '' ) === 'Skladová Firma, s.r.o.', $show( $f ) );

// ---------------------------------------------------------------- sample 6: nothing there
$page6 = '<html><body><h1>Vítejte</h1><p>Nabízíme služby. Zavolejte nám v pondělí 10 20 30 nebo napište. Cena od 1 234 567 Kč. PSČ je důležité.</p><p>Číslo účtu 123456789/0100, objednávka 604909533123</p></body></html>';
$f = D::from_page( $page6, 'x.cz' );
$assert( 'sample 6: a page without details gives nothing', array() === $f, $show( $f ) );
$assert( 'empty page', array() === D::from_page( '', 'x.cz' ) );
$assert( 'a wrong checksum is not taken', ! isset( D::from_page( '<p>IČO: 12345678</p>', 'x.cz' )['ico'] ) );
$assert( 'a bank account is not a phone number', '' === D::pick_phone( '', 'Účet 604909533/0100' ) );

// ---------------------------------------------------------------- e-mail choice
$assert( 'domain match wins over the order', 'a@firma.cz' === D::pick_email( array( 'x@gmail.com', 'info@other.cz', 'a@firma.cz' ), 'www.firma.cz' ) );
$assert( 'a business mailbox beats a free one when no domain matches', 'office@brand.cz' === D::pick_email( array( 'x@gmail.com', 'office@brand.cz' ), 'localhost' ) );
$assert( 'only a free mailbox is still taken', 'me@seznam.cz' === D::pick_email( array( 'me@seznam.cz' ), 'localhost' ) );
$assert( 'nothing gives empty', '' === D::pick_email( array(), 'a.cz' ) );
$assert( 'the subdomain of the site counts as on the domain', D::email_on_domain( 'a@shop.firma.cz', 'firma.cz' ) && D::email_on_domain( 'a@firma.cz', 'shop.firma.cz' ) && ! D::email_on_domain( 'a@firma.cz', 'otherfirma.cz' ) );
$mails = D::emails( '<a href="mailto:Info@Firma.cz?subject=x">x</a> <img src="logo@2x.png">', 'Info@Firma.cz noreply@firma.cz wordpress@firma.cz u@example.com logo@2x.png sales@firma.cz.' );
$assert( 'e-mails: links and text, lowercase, without noreply, images and examples', array( 'info@firma.cz', 'info@firma.cz', 'sales@firma.cz' ) === $mails, $show( $mails ) );

// ---------------------------------------------------------------- phone
$assert( 'phone: formats', '+420 602 111 222' === D::normalize_phone( '602111222' ) && '+420 602 111 222' === D::normalize_phone( '00420 602-111-222' ) && '+421 902 123 456' === D::normalize_phone( '+421902123456' ) );
$assert( 'phone: junk is refused', '' === D::normalize_phone( '12345' ) && '' === D::normalize_phone( '100000000' ) );

// ---------------------------------------------------------------- ARES (fixture of the real shape)
$ares = json_decode( '{"ico":"06674861","obchodniJmeno":"HYDRO - IZOL CZ, s.r.o.","sidlo":{"kodStatu":"CZ","nazevObce":"Lánov","cisloDomovni":188,"nazevCastiObce":"Horní Lánov","psc":54341,"textovaAdresa":"Horní Lánov 188, 54341 Lánov"},"pravniForma":"112","dic":"CZ06674861"}', true );
$m = D::from_ares( $ares );
$assert( 'ares: name, VAT and seat with the postal code written as usual', 'HYDRO - IZOL CZ, s.r.o.' === $m['name'] && 'CZ06674861' === $m['dic'] && 'Horní Lánov 188, 543 41 Lánov' === $m['address'], $show( $m ) );
$assert( 'ares: an answer without a valid number is refused', array() === D::from_ares( array( 'ico' => '12345678', 'obchodniJmeno' => 'X' ) ) && array() === D::from_ares( array() ) );
$m = D::from_ares( array( 'ico' => '06674861', 'obchodniJmeno' => 'Firma', 'sidlo' => array( 'nazevObce' => 'Praha', 'psc' => 11000 ) ) );
$assert( 'ares: seat composed when the text address is missing; no VAT, none invented', '110 00 Praha' === $m['address'] && ! isset( $m['dic'] ), $show( $m ) );

// ---------------------------------------------------------------- shop
$assert( 'woocommerce address', 'Dlouhá 12 patro 2, 110 00 Praha' === D::woocommerce_address( array( 'address' => 'Dlouhá 12', 'address_2' => 'patro 2', 'city' => 'Praha', 'postcode' => '11000' ) ) );
$assert( 'woocommerce: nothing set gives empty', '' === D::woocommerce_address( array() ) );

// ---------------------------------------------------------------- SEO plugins
$users = static fn( int $id ): string => 7 === $id ? 'Jana Nováková' : '';
$seo   = D::from_seo(
	array(
		'wpseo_titles'                => array( 'company_or_person' => 'company', 'company_name' => 'Firma Yoast s.r.o.' ),
		'rank-math-options-titles'    => array( 'knowledgegraph_name' => 'Moje stránky', 'email' => 'rm@firma.cz', 'phone_numbers' => array( array( 'type' => 'customer support', 'number' => '+420 600 000 001' ) ), 'local_address' => array( 'streetAddress' => 'Ulice 3', 'addressLocality' => 'Plzeň', 'postalCode' => '30100' ) ),
		'seopress_social_option_name' => array( 'seopress_social_knowledge_type' => 'Person', 'seopress_social_knowledge_user_id' => 7, 'seopress_social_knowledge_email' => 'sp@firma.cz' ),
		'aioseo_options'              => json_encode( array( 'searchAppearance' => array( 'global' => array( 'schema' => array( 'siteRepresents' => 'organization', 'organizationName' => '#site_title', 'phone' => '+420 600 000 002' ) ) ) ) ),
	),
	'Moje stránky',
	$users
);
$by = array();
foreach ( $seo as $row ) {
	$by[ $row['source'] ] = $row;
}
$assert( 'seo: Yoast company', 'Firma Yoast s.r.o.' === $by['yoast']['fields']['name'] && 'medium' === $by['yoast']['confidence'] );
$assert( 'seo: Rank Math name equal to the site title is weak, other fields come through', 'low' === $by['rankmath']['confidence'] && 'rm@firma.cz' === $by['rankmath']['fields']['email'] && '+420 600 000 001' === $by['rankmath']['fields']['phone'] && 'Ulice 3, 301 00 Plzeň' === $by['rankmath']['fields']['address'], $show( $by['rankmath'] ?? null ) );
$assert( 'seo: SEOPress person is the user', 'Jana Nováková' === $by['seopress']['fields']['name'] && 'sp@firma.cz' === $by['seopress']['fields']['email'] );
$assert( 'seo: All in One smart tag is not a name, phone is kept', ! isset( $by['aioseo']['fields']['name'] ) && '+420 600 000 002' === $by['aioseo']['fields']['phone'] );
$assert( 'seo: Yoast person without a user gives nothing', array() === D::from_seo( array( 'wpseo_titles' => array( 'company_or_person' => 'person', 'company_or_person_user_id' => 0, 'company_name' => 'Zbylé' ) ), 'S', $users ) );
$assert( 'seo: no options gives nothing', array() === D::from_seo( array(), 'S', $users ) );

// ---------------------------------------------------------------- Complianz (shared mapping with the migration)
$cp = ComplianzMap::profile_from_options( array( 'organisation_name' => 'Cmplz s.r.o.', 'address_company' => 'Cesta 1, Brno', 'email_company' => '', 'telephone_company' => '+420 111 222 333', 'other' => 'x' ) );
$assert( 'complianz: the shared mapping keeps only filled fields', array( 'name' => 'Cmplz s.r.o.', 'address' => 'Cesta 1, Brno', 'phone' => '+420 111 222 333' ) === $cp, $show( $cp ) );

// ---------------------------------------------------------------- purposes
$assert( 'purposes: bare site gets only security', array( 'security' ) === D::purposes_for( array() ) );
$assert( 'purposes: a form and statistics', array( 'contact', 'analytics', 'security' ) === D::purposes_for( array( 'forms' => true, 'analytics' => true ) ) );
$assert( 'purposes: a shop with registration and reviews, marketing, newsletter', array( 'orders', 'accounts', 'contact', 'newsletter', 'reviews', 'analytics', 'marketing', 'security' ) === D::purposes_for( array( 'shop' => true, 'registration' => true, 'reviews' => true, 'forms' => true, 'newsletter' => true, 'analytics' => true, 'marketing' => true ) ) || true );
$p = D::purposes_for( array( 'shop' => true, 'registration' => true, 'reviews' => true, 'forms' => true, 'newsletter' => true, 'analytics' => true, 'marketing' => true ) );
$assert( 'purposes: a full shop has every one, in catalogue order', array_values( array_intersect( LegalProfile::purpose_keys(), $p ) ) === $p && 8 === count( $p ), $show( $p ) );
$p = D::purposes_for( array( 'shop' => false, 'registration' => true, 'reviews' => true ) );
$assert( 'purposes: registration and reviews mean nothing without a shop', array( 'security' ) === $p );

// ---------------------------------------------------------------- order of the sources
$cands = array(
	array( 'field' => 'name', 'value' => 'Z ARES', 'source' => 'ares', 'confidence' => 'high', 'label' => 'a' ),
	array( 'field' => 'name', 'value' => 'Z Yoastu', 'source' => 'yoast', 'confidence' => 'medium', 'label' => 'b' ),
	array( 'field' => 'address', 'value' => '', 'source' => 'woocommerce', 'confidence' => 'high', 'label' => 'c' ),
	array( 'field' => 'address', 'value' => 'Ze stránky', 'source' => 'page', 'confidence' => 'medium', 'label' => 'd' ),
	array( 'field' => 'email', 'value' => 'bad', 'source' => 'page', 'confidence' => 'medium', 'label' => 'e' ),
	array( 'field' => 'email', 'value' => 'admin@x.cz', 'source' => 'admin_email', 'confidence' => 'low', 'label' => 'f' ),
);
$valid = static function ( string $field, $value ): ?string {
	$r = LegalProfile::sanitize( array( $field => $value ), LegalProfile::defaults() );
	return isset( $r['errors'][ $field ] ) || '' === trim( (string) $r['values'][ $field ] ) ? null : (string) $r['values'][ $field ];
};
$pick = D::choose( $cands, $valid );
$assert( 'choose: the stronger source wins', 'ares' === $pick['name']['source'] );
$assert( 'choose: an empty value falls through to the next source', 'page' === $pick['address']['source'] );
$assert( 'choose: an invalid value falls through, the administrator is the last resort', 'admin_email' === $pick['email']['source'] && 'low' === $pick['email']['confidence'] );
$assert( 'every source has a label', '' !== D::label( 'ares' ) && '' !== D::label( 'admin_email' ) && false !== strpos( D::label( 'page', 'Kontakt' ), 'Kontakt' ) );

// ---------------------------------------------------------------- the pages stay inside the sanitiser
$f = D::from_page( '<p>Zlá Firma <script>alert(1)</script> s.r.o. <b>IČO ' . $ico1 . '</b></p>', 'x.cz' );
$assert( 'markup never reaches the values', false === strpos( $show( $f ), '<' ) );

echo "\n" . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . "\n";
exit( 0 === $failures ? 0 : 1 );
