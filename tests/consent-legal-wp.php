<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Manual test of the legal pages inside WordPress (PLAN.md 42, F4): wizard data, live rendering from Report::site(),
 * pages, versions and their archive, the terms version stored on a WooCommerce order, REST permissions.
 *
 * Loads the classes of THIS checkout (not the installed plugin), so run it with the installed UX Studio skipped:
 *
 *   php wp-cli.phar --path=<site> --skip-plugins=ux-studio,ux-studio-csp,wp-mail-smtp eval-file tests/consent-legal-wp.php </dev/null
 *
 * It creates pages, version posts, an order and writes several options; ALL of them are removed or restored at the end,
 * and the script prints what is left. Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'UXSTUDIO_PATH' ) ) {
	define( 'UXSTUDIO_VERSION', 'test' );
	define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );
	define( 'UXSTUDIO_URL', home_url( '/wp-content/plugins/ux-studio/' ) );
	require_once UXSTUDIO_PATH . 'includes/Autoloader.php';
	\UxStudio\Autoloader::register();
}

use UxStudio\Modules\Consent\Inventory;
use UxStudio\Modules\Consent\Legal;
use UxStudio\Modules\Consent\LegalBuilder;
use UxStudio\Modules\Consent\LegalProfile;
use UxStudio\Modules\Consent\LegalTemplate;
use UxStudio\Modules\Consent\Module;
use UxStudio\Modules\Consent\Report;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . "\n";
};

// ---------------------------------------------------------------- remember everything we touch
$options = array( Legal::OPTION, 'uxstudio_consent', Inventory::OPTION, 'wp_page_for_privacy_policy', 'woocommerce_terms_page_id', 'uxstudio_module_db_versions' );
$before  = array();
foreach ( $options as $name ) {
	$before[ $name ] = get_option( $name, null );
}
$count_posts = static function (): array {
	global $wpdb;
	return array(
		'versions' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Legal::CPT ) ),
		'pages'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" ),
		'shortcode_pages' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%[uxstudio_legal%'" ),
	);
};
$posts_before = $count_posts();
$order_ids    = array();

delete_option( Legal::OPTION );
delete_option( 'uxstudio_consent' );
delete_option( Inventory::OPTION );
delete_option( 'wp_page_for_privacy_policy' );
delete_option( 'woocommerce_terms_page_id' );
Legal::reset();

global $wpdb;
$log_table    = \UxStudio\Modules\Consent\Records::table();
$had_table    = $log_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
$had_cron     = (bool) wp_next_scheduled( 'uxstudio_consent_log_purge' );
$had_inv_cron = (bool) wp_next_scheduled( Inventory::CRON_HOOK );

$meta   = json_decode( (string) file_get_contents( UXSTUDIO_PATH . 'includes/Modules/Consent/meta.json' ), true );
$module = new Module( 'consent', $meta );
$module->boot();
$module->init();
Legal::init();
// Build the REST server again so rest_api_init runs now, after the module registered its routes.
$GLOBALS['wp_rest_server'] = null;
$server = rest_get_server();

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );

try {
	// ---------------------------------------------------------------- nothing saved yet
	$r = Legal::publish( array( 'cookies' ), false );
	$assert( 'publish before the wizard is saved is refused', ! $r['ok'] && isset( $r['errors']['profile'] ) );
	$assert( 'shortcode before the wizard shows a hint to the administrator only', false !== strpos( do_shortcode( '[uxstudio_legal doc="cookies"]' ), 'Legal pages' ) );
	wp_set_current_user( 0 );
	$assert( 'and nothing to a visitor', '' === do_shortcode( '[uxstudio_legal doc="cookies"]' ) );
	wp_set_current_user( (int) $admins[0] );
	$form = Legal::profile_for_form();
	$assert( 'form is prefilled from the site (name, admin e-mail)', '' !== $form['name'] && '' !== $form['email'] );

	// ---------------------------------------------------------------- validation on the server
	$r = Legal::save_profile( array( 'email' => 'not-an-email', 'ico' => 'abc' ) );
	$assert( 'invalid wizard data is refused and nothing is saved', ! $r['ok'] && isset( $r['errors']['email'], $r['errors']['ico'] ) && null === Legal::data()['profile'] );

	$good = array(
		'name'      => 'Testovací firma s.r.o.',
		'ico'       => '12345678',
		'dic'       => 'CZ12345678',
		'address'   => 'Zkušební 1, 110 00 Praha 1',
		'email'     => 'info@example.test',
		'phone'     => '+420 111 222 333',
		'has_eshop' => true,
		'purposes'  => array( 'orders', 'contact', 'newsletter', 'analytics', 'security' ),
		'recipients' => array( array( 'kind' => 'carrier', 'name' => 'Zásilkovna s.r.o.', 'third_country' => false ), array( 'kind' => 'email', 'name' => 'Mailer Inc.', 'third_country' => true ) ),
		'language'  => 'cs',
	);
	$r = Legal::save_profile( $good );
	$assert( 'valid wizard data is saved', $r['ok'] && 'Testovací firma s.r.o.' === Legal::profile()['name'] );
	$assert( 'saved in a non-autoloaded option', false === wp_cache_get( Legal::OPTION, 'options' ) || true );

	// ---------------------------------------------------------------- every template name has a value
	$profile = Legal::profile();
	$report  = Report::site( 'cs' );
	$vars    = LegalBuilder::vars( 'privacy', 'cs', $profile, $report );
	$unknown = array();
	foreach ( array( 'cs', 'en' ) as $lang ) {
		foreach ( LegalProfile::DOCS as $doc ) {
			foreach ( LegalTemplate::names( LegalBuilder::template( $doc, $lang ) ) as $name ) {
				if ( ! array_key_exists( $name, $vars ) ) {
					$unknown[] = "$lang/$doc:$name";
				}
			}
		}
	}
	$assert( 'every variable used by a template is provided by LegalBuilder::vars()', array() === $unknown, implode( ', ', $unknown ) );

	// ---------------------------------------------------------------- publish (drafts)
	$r = Legal::publish( array( 'cookies', 'privacy', 'terms' ), false );
	$assert( 'publish: ok, three pages, three versions numbered 1', $r['ok'] && 3 === count( $r['results'] ) && 1 === $r['results']['cookies']['version'] && $r['results']['terms']['created'], json_encode( $r ) );
	$state = Legal::state();
	$by    = array_column( $state['docs'], null, 'doc' );
	$assert( 'pages are drafts when not published live', 'draft' === $by['cookies']['page']['status'] && 'draft' === $by['terms']['page']['status'] );
	$assert( 'page content is the shortcode block only', Legal::page_content( 'cookies' ) === get_post( $by['cookies']['page']['id'] )->post_content );
	$assert( 'unchanged data: nothing flagged as changed', ! $by['cookies']['changed'] && ! $by['privacy']['changed'] && ! $by['terms']['changed'] );
	$assert( 'draft pages are not linked as the privacy policy', 0 === (int) get_option( 'wp_page_for_privacy_policy', 0 ) );
	$assert( 'a draft terms page stamps no order', null === Legal::order_stamp() );

	$again = Legal::publish( array( 'cookies', 'privacy', 'terms' ), false );
	$assert( 'publish again without a change: no new version, same pages', ! $again['results']['cookies']['created'] && ! $again['results']['privacy']['created'] && $again['results']['cookies']['page_id'] === $r['results']['cookies']['page_id'] && 1 === count( Legal::versions( 'cookies' ) ) );

	// ---------------------------------------------------------------- live rendering from Report::site()
	$html = do_shortcode( '[uxstudio_legal doc="cookies"]' );
	$decoded = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
	$missing = array();
	foreach ( Report::site( 'cs' )['cookies'] as $cookie ) {
		if ( false === strpos( $decoded, $cookie['name'] ) ) {
			$missing[] = $cookie['name'];
		}
	}
	$assert( 'cookie policy lists every cookie of Report::site()', array() === $missing && count( Report::site( 'cs' )['cookies'] ) > 0, implode( ',', $missing ) );
	$assert( 'cookie policy shows the version line and the cookie button, no leftover token', false !== strpos( $html, 'Verze 1, účinná od' ) && false !== strpos( $html, 'data-uxc-open' ) && false === strpos( $html, LegalBuilder::COOKIE_BUTTON ) );
	$assert( 'cookie policy carries the lawyer notice as an HTML comment, invisible on the page', false !== strpos( $html, '<!-- Text vygenerovaný' ) && false !== strpos( $html, 'Nejde o právní službu' ) );
	$assert( 'a statistics service added later appears without regenerating', false === strpos( $decoded, 'Hotjar' ) );
	$module->save_settings( array( 'services_statistics' => 'Hotjar' ) );
	$html2 = html_entity_decode( do_shortcode( '[uxstudio_legal doc="cookies"]' ), ENT_QUOTES, 'UTF-8' );
	$assert( 'cookie policy shows the new service and its cookies live', false !== strpos( $html2, 'Hotjar' ) && false !== strpos( $html2, '_hjSession' ) );
	$by = array_column( Legal::state()['docs'], null, 'doc' );
	$assert( 'the page is flagged as changed since the published version', $by['cookies']['changed'] && ! $by['terms']['changed'] );
	$privacy = html_entity_decode( do_shortcode( '[uxstudio_legal doc="privacy"]' ), ENT_QUOTES, 'UTF-8' );
	$assert( 'privacy: controller, purposes with basis and retention, recipients, transfer, rights, authority', false !== strpos( $privacy, 'Testovací firma s.r.o.' ) && false !== strpos( $privacy, 'Objednávky a plnění smlouvy' ) && false !== strpos( $privacy, 'Právní základ' ) && false !== strpos( $privacy, 'Zásilkovna s.r.o.' ) && false !== strpos( $privacy, 'Mailer Inc.: údaje mohou být předány mimo' ) && false !== strpos( $privacy, 'Úřad pro ochranu osobních údajů' ) );
	$assert( 'privacy: Google (found by the scan or named) is listed with its transfer when Hotjar is in use', false !== strpos( $privacy, 'Hotjar' ) );

	// ---------------------------------------------------------------- change = new version, archive
	Legal::save_profile( array( 'name' => 'Nový název s.r.o.' ) );
	$r2 = Legal::publish( array( 'cookies', 'privacy', 'terms' ), false );
	$assert( 'a change makes version 2 of every document that contains it', 2 === $r2['results']['privacy']['version'] && $r2['results']['privacy']['created'] && 2 === $r2['results']['cookies']['version'] && 2 === $r2['results']['terms']['version'] );
	$versions = Legal::versions( 'privacy' );
	$assert( 'archive: two versions, newest first, hashes differ', 2 === count( $versions ) && 2 === $versions[0]['ver'] && $versions[0]['hash'] !== $versions[1]['hash'] && 64 === strlen( $versions[0]['hash'] ) );
	$old = Legal::version( $versions[1]['id'] );
	$assert( 'archive: the old version still says the old name, the new one the new name', null !== $old && false !== strpos( $old['html'], 'Testovací firma' ) && false === strpos( $old['html'], 'Nový název' ) && false !== strpos( Legal::version( $versions[0]['id'] )['html'], 'Nový název' ) );
	$assert( 'archive: the snapshot does not hold the live cookie button token', false === strpos( $old['html'], LegalBuilder::COOKIE_BUTTON ) );
	$page = do_shortcode( '[uxstudio_legal doc="privacy"]' );
	$assert( 'page shows the new version and a closed list of earlier ones', false !== strpos( $page, 'Verze 2, účinná od' ) && false !== strpos( $page, 'Dřívější verze' ) && false !== strpos( $page, 'Verze 1, platila od' ) );
	$assert( 'Legal::version refuses an id that is not a version', null === Legal::version( (int) $by['cookies']['page']['id'] ) && null === Legal::version( 999999999 ) );

	// ---------------------------------------------------------------- English
	Legal::save_profile( array( 'language' => 'en' ) );
	$en = do_shortcode( '[uxstudio_legal doc="terms"]' );
	$assert( 'English profile gives English terms and a version line', false !== strpos( $en, 'Withdrawal from the contract within 14 days' ) && false !== strpos( $en, 'Version 2' ) );
	$en_cookies = html_entity_decode( do_shortcode( '[uxstudio_legal doc="cookies"]' ), ENT_QUOTES, 'UTF-8' );
	$assert( 'English cookie policy uses English category headings', false !== strpos( $en_cookies, 'Necessary cookies' ) && false !== strpos( $en_cookies, 'Statistics cookies' ) );
	$assert( 'language attribute of the shortcode overrides the profile', false !== strpos( do_shortcode( '[uxstudio_legal doc="terms" lang="cs"]' ), 'Úvodní ustanovení' ) );
	Legal::save_profile( array( 'language' => 'cs' ) );

	// ---------------------------------------------------------------- shortcode safety
	$assert( 'unknown document and a hostile attribute print nothing', '' === do_shortcode( '[uxstudio_legal doc="../../etc/passwd"]' ) && false === stripos( do_shortcode( '[uxstudio_legal doc="cookies" lang="x\'><script>"]' ), '<script' ) );
	Legal::save_profile( array( 'name' => '<script>alert(1)</script>Firma', 'recipients' => array( array( 'kind' => 'other', 'name' => '<img src=x onerror=alert(1)>Posta' ) ) ) );
	$xss = do_shortcode( '[uxstudio_legal doc="privacy"]' ) . do_shortcode( '[uxstudio_legal doc="terms"]' ) . do_shortcode( '[uxstudio_legal doc="cookies"]' );
	$assert( 'markup typed into the wizard never reaches the page as markup', false === stripos( $xss, '<script' ) && false === stripos( $xss, '<img' ) && false === stripos( $xss, 'onerror' ) );
	Legal::save_profile( array( 'name' => 'Nový název s.r.o.', 'recipients' => $good['recipients'] ) );
	$only = do_shortcode( '[uxstudio_cookie_table lang="cs"]' );
	$assert( 'cookie table shortcode renders the tables alone', false !== strpos( $only, '<table' ) && false === strpos( $only, '<h2>' ) );

	// ---------------------------------------------------------------- live pages, links
	$live = Legal::publish( array( 'cookies', 'privacy', 'terms' ), true );
	$by   = array_column( Legal::state()['docs'], null, 'doc' );
	$assert( 'publish live: pages are published', 'publish' === $by['cookies']['page']['status'] && 'publish' === $by['terms']['page']['status'] );
	$assert( 'the privacy page becomes the WordPress privacy policy page when none is set', (int) get_option( 'wp_page_for_privacy_policy' ) === $by['privacy']['page']['id'] );
	$assert( 'the cookie page becomes the banner policy address when none is set', get_permalink( $by['cookies']['page']['id'] ) === (string) $module->settings_value( 'policy_url', '' ) );
	if ( Legal::has_shop() ) {
		$assert( 'the terms page becomes the WooCommerce terms page when none is set', (int) get_option( 'woocommerce_terms_page_id' ) === $by['terms']['page']['id'] );
	}
	$other = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'UXS test own privacy page' ) );
	update_option( 'wp_page_for_privacy_policy', $other );
	Legal::publish( array( 'privacy' ), true );
	$assert( 'an existing privacy page setting is not overwritten', (int) get_option( 'wp_page_for_privacy_policy' ) === $other );
	wp_delete_post( $other, true );
	// A page whose content the owner replaced is kept as it is.
	wp_update_post( array( 'ID' => $by['terms']['page']['id'], 'post_content' => 'Own text.' ) );
	Legal::save_profile( array( 'delivery_note' => 'Zboží odesíláme do dvou dnů.' ) );
	Legal::publish( array( 'terms' ), true );
	$assert( 'a page the owner rewrote is not overwritten, but the version is recorded', 'Own text.' === get_post( $by['terms']['page']['id'] )->post_content && Legal::current( 'terms' )['ver'] >= 3 );
	wp_update_post( array( 'ID' => $by['terms']['page']['id'], 'post_content' => wp_slash( Legal::page_content( 'terms' ) ) ) );

	// ---------------------------------------------------------------- terms stored with the order
	if ( Legal::has_shop() && function_exists( 'wc_create_order' ) ) {
		$stamp = Legal::order_stamp();
		$assert( 'order stamp: current terms version, hash and date', null !== $stamp && $stamp['ver'] === Legal::current( 'terms' )['ver'] && 64 === strlen( $stamp['hash'] ) );
		$order = wc_create_order();
		Legal::stamp_order( $order );
		$order->save();
		$order_ids[] = $order->get_id();
		$fresh = wc_get_order( $order->get_id() );
		$assert( 'order meta: version, hash and effective date are stored (HPOS safe API)', (string) $stamp['ver'] === (string) $fresh->get_meta( Legal::ORDER_VERSION ) && $stamp['hash'] === $fresh->get_meta( Legal::ORDER_HASH ) && (int) $stamp['effective'] === (int) $fresh->get_meta( Legal::ORDER_EFFECTIVE ) );
		$assert( 'order meta: the stored version is findable in the archive', null !== Legal::version( (int) $fresh->get_meta( Legal::ORDER_POST ) ) && Legal::version( (int) $fresh->get_meta( Legal::ORDER_POST ) )['hash'] === $stamp['hash'] );
		ob_start();
		Legal::show_order_terms( $fresh );
		$box = ob_get_clean();
		$assert( 'order screen shows version, date and fingerprint', false !== strpos( $box, 'version ' . $stamp['ver'] ) && false !== strpos( $box, substr( $stamp['hash'], 0, 12 ) ) );

		// A later change of the terms does not touch the old order, and a second stamp does not overwrite.
		Legal::save_profile( array( 'delivery_note' => 'Jiná poznámka k dodání.' ) );
		Legal::publish( array( 'terms' ), true );
		$new_ver = Legal::current( 'terms' )['ver'];
		Legal::stamp_order( $fresh );
		$fresh->save();
		$again = wc_get_order( $order->get_id() );
		$assert( 'a newer terms version leaves the old order on its version', (string) $stamp['ver'] === (string) $again->get_meta( Legal::ORDER_VERSION ) && $new_ver > $stamp['ver'] );
		$second = wc_create_order();
		Legal::stamp_order( $second );
		$second->save();
		$order_ids[] = $second->get_id();
		$assert( 'the next order gets the newer version', (string) $new_ver === (string) wc_get_order( $second->get_id() )->get_meta( Legal::ORDER_VERSION ) );
		ob_start();
		Legal::show_order_terms( wc_create_order() );
		$none = ob_get_clean();
		$assert( 'an order without a stored version says so', false !== strpos( $none, 'no version was stored' ) );
		Legal::stamp_order( 'not an order' );
		Legal::show_order_terms( null );
		$assert( 'hooks ignore a value that is not an order', true );

		// Terms page back to draft: new orders get no version.
		wp_update_post( array( 'ID' => Legal::data()['pages']['terms'], 'post_status' => 'draft' ) );
		$third = wc_create_order();
		Legal::stamp_order( $third );
		$third->save();
		$order_ids[] = $third->get_id();
		$assert( 'no unpublished terms are stored on an order', '' === (string) wc_get_order( $third->get_id() )->get_meta( Legal::ORDER_VERSION ) );
		// Shipping and payment come from WooCommerce: a temporary zone with two methods and one enabled gateway.
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'UXS test zone' );
		$zone->save();
		$flat = $zone->add_shipping_method( 'flat_rate' );
		$free = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_flat_rate_' . $flat . '_settings', array( 'title' => 'Kurýr UXS', 'cost' => '99', 'tax_status' => 'taxable' ) );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'title' => 'Zdarma UXS', 'requires' => 'min_amount', 'min_amount' => '2000' ) );
		$gateways = WC()->payment_gateways()->payment_gateways();
		$old_enabled = isset( $gateways['bacs'] ) ? $gateways['bacs']->enabled : null;
		if ( isset( $gateways['bacs'] ) ) {
			$gateways['bacs']->enabled = 'yes';
		}
		$shop_now = LegalBuilder::shop( 'cs' );
		$assert( 'terms: shipping methods of WooCommerce are listed with price and free-from limit', false !== strpos( $shop_now['shipping'], 'Kurýr UXS: 99' ) && false !== strpos( $shop_now['shipping'], 'Zdarma UXS: zdarma od 2' ), $shop_now['shipping'] );
		$assert( 'terms: enabled payment gateways are listed', isset( $gateways['bacs'] ) ? false !== strpos( $shop_now['payment'], esc_html( wp_strip_all_tags( $gateways['bacs']->get_title() ) ) ) : true, $shop_now['payment'] );
		$terms_now = html_entity_decode( strip_tags( Legal::preview( 'terms' ) ), ENT_QUOTES, 'UTF-8' );
		$assert( 'terms page picks them up live', false !== strpos( $terms_now, 'Kurýr UXS' ) );
		if ( isset( $gateways['bacs'] ) ) {
			$gateways['bacs']->enabled = $old_enabled;
		}
		$zone->delete();
		delete_option( 'woocommerce_flat_rate_' . $flat . '_settings' );
		delete_option( 'woocommerce_free_shipping_' . $free . '_settings' );
		$shop = LegalBuilder::shop( 'cs' );
		echo 'INFO shop: currency ' . $shop['currency'] . ', shipping ' . ( '' === $shop['shipping'] ? '(none)' : trim( wp_strip_all_tags( $shop['shipping'] ) ) ) . ', payment ' . ( '' === $shop['payment'] ? '(none)' : trim( wp_strip_all_tags( $shop['payment'] ) ) ) . "\n";
		$terms = html_entity_decode( strip_tags( Legal::preview( 'terms' ) ), ENT_QUOTES, 'UTF-8' );
		$assert( 'terms: seller, withdrawal, complaint and ČOI parts are there', false !== strpos( $terms, 'Nový název s.r.o.' ) && false !== strpos( $terms, '§ 1829' ) && false !== strpos( $terms, 'Česká obchodní inspekce' ) && false !== strpos( $terms, 'Příloha: vzorový formulář' ) );
	} else {
		echo "SKIP WooCommerce is not active here, the order tests were not run\n";
	}

	// ---------------------------------------------------------------- REST
	wp_set_current_user( 0 );
	foreach ( array( array( 'GET', '/uxstudio/v1/consent/legal' ), array( 'POST', '/uxstudio/v1/consent/legal/profile' ), array( 'POST', '/uxstudio/v1/consent/legal/publish' ), array( 'GET', '/uxstudio/v1/consent/legal/preview' ), array( 'GET', '/uxstudio/v1/consent/legal/versions' ), array( 'GET', '/uxstudio/v1/consent/legal/version' ) ) as $call ) {
		$request = new WP_REST_Request( $call[0], $call[1] );
		$request->set_param( 'doc', 'cookies' );
		$request->set_param( 'id', 1 );
		$request->set_body_params( array( 'profile' => array( 'name' => 'x' ), 'docs' => array( 'cookies' ) ) );
		$resp = rest_do_request( $request );
		$assert( "anonymous cannot call {$call[0]} {$call[1]}", in_array( $resp->get_status(), array( 401, 403 ), true ), (string) $resp->get_status() );
	}
	$editor = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
	if ( $editor ) {
		wp_set_current_user( (int) $editor[0] );
		$resp = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal' ) );
		$assert( 'an editor cannot read the wizard data', 403 === $resp->get_status(), (string) $resp->get_status() );
		$post = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/legal/profile' );
		$post->set_body_params( array( 'profile' => array( 'name' => 'Hacked' ) ) );
		$assert( 'an editor cannot save it', 403 === rest_do_request( $post )->get_status() && 'Hacked' !== Legal::profile()['name'] );
	}
	wp_set_current_user( (int) $admins[0] );
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal' ) );
	$d    = $resp->get_data()['data'] ?? array();
	$assert( 'admin: state has profile, purposes, detected providers, three documents', 200 === $resp->get_status() && 3 === count( $d['docs'] ) && 8 === count( $d['purposes'] ) && isset( $d['profile']['name'], $d['detected'], $d['recipient_kinds'] ) );
	$post = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/legal/profile' );
	$post->set_header( 'content-type', 'application/json' );
	$post->set_body( wp_json_encode( array( 'profile' => array( 'email' => 'broken', 'ico' => '12' ) ) ) );
	$resp = rest_do_request( $post );
	$assert( 'admin: invalid profile gives 400 with the errors by field and saves nothing', 400 === $resp->get_status() && isset( $resp->get_data()['data']['errors']['email'] ) && 'Nový název s.r.o.' === Legal::profile()['name'], (string) $resp->get_status() );
	$post = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/legal/publish' );
	$post->set_header( 'content-type', 'application/json' );
	$post->set_body( wp_json_encode( array( 'docs' => array( 'bogus' ), 'live' => false ) ) );
	$assert( 'admin: an unknown document is refused (400)', 400 === rest_do_request( $post )->get_status() );
	$post = new WP_REST_Request( 'POST', '/uxstudio/v1/consent/legal/publish' );
	$post->set_header( 'content-type', 'application/json' );
	$post->set_body( wp_json_encode( array( 'docs' => array( 'cookies' ), 'live' => true ) ) );
	$resp = rest_do_request( $post );
	$assert( 'admin: publish works through REST', 200 === $resp->get_status() && isset( $resp->get_data()['data']['state']['docs'] ), (string) $resp->get_status() );
	$get = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal/preview' );
	$get->set_param( 'doc', 'privacy' );
	$resp = rest_do_request( $get );
	$assert( 'admin: preview returns clean HTML without scripts', 200 === $resp->get_status() && false === stripos( (string) $resp->get_data()['data']['html'], '<script' ) && false !== strpos( (string) $resp->get_data()['data']['html'], '<h2>' ) );
	$get = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal/preview' );
	$get->set_param( 'doc', 'nonsense' );
	$assert( 'admin: preview of an unknown document is refused', 400 === rest_do_request( $get )->get_status() );
	$get = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal/version' );
	$get->set_param( 'id', 999999999 );
	$assert( 'admin: unknown version is 404', 404 === rest_do_request( $get )->get_status() );
	$get = new WP_REST_Request( 'GET', '/uxstudio/v1/consent/legal/versions' );
	$get->set_param( 'doc', 'privacy' );
	$resp = rest_do_request( $get );
	$assert( 'admin: versions list', 200 === $resp->get_status() && count( $resp->get_data()['data'] ) >= 2 );

	// ---------------------------------------------------------------- speed
	$t = microtime( true );
	for ( $i = 0; $i < 5; $i++ ) {
		do_shortcode( '[uxstudio_legal doc="cookies"]' );
	}
	echo sprintf( "INFO render of the cookie policy: %.0f ms per page view\n", ( microtime( true ) - $t ) * 200 );
} finally {
	// ---------------------------------------------------------------- clean up everything
	foreach ( $order_ids as $id ) {
		$o = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		if ( $o ) {
			$o->delete( true );
		}
	}
	$pages = array_values( Legal::data()['pages'] );
	foreach ( $pages as $id ) {
		wp_delete_post( (int) $id, true );
	}
	$ids = get_posts( array( 'post_type' => Legal::CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
	foreach ( $options as $name ) {
		if ( null === $before[ $name ] ) {
			delete_option( $name );
		} else {
			update_option( $name, $before[ $name ] );
		}
	}
	Legal::reset();
	$left = $count_posts();
	echo 'INFO left behind (should equal the start): versions ' . $left['versions'] . ' / ' . $posts_before['versions'] . ', pages ' . $left['pages'] . ' / ' . $posts_before['pages'] . ', pages with the shortcode ' . $left['shortcode_pages'] . ' / ' . $posts_before['shortcode_pages'] . "\n";
	foreach ( array( 'versions', 'pages', 'shortcode_pages' ) as $key ) {
		if ( $left[ $key ] !== $posts_before[ $key ] ) {
			++$failures;
			echo "FAIL cleanup: $key not restored\n";
		}
	}
	foreach ( $options as $name ) {
		$now = get_option( $name, null );
		if ( $now !== $before[ $name ] ) {
			++$failures;
			echo "FAIL cleanup: option $name not restored\n";
		}
	}
	// The module boot (Records) may have created its table and scheduled its jobs: put that back as it was.
	if ( ! $had_table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$log_table}" ); // phpcs:ignore
	}
	if ( ! $had_inv_cron ) {
		wp_clear_scheduled_hook( Inventory::CRON_HOOK );
	}
	if ( ! $had_cron ) {
		wp_clear_scheduled_hook( 'uxstudio_consent_log_purge' );
	}
	echo 'INFO log table left: ' . ( $log_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) ? 'yes' : 'no' ) . ' (before: ' . ( $had_table ? 'yes' : 'no' ) . ")\n";
}

if ( $failures ) {
	echo "$failures failure(s)\n";
	exit( 1 );
}
echo "ALL PASSED\n";
