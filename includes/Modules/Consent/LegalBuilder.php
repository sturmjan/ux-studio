<?php
/**
 * Builds the legal documents: collects the values for a template and renders it.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.5 / 42.14. The static text of a document lives in `legal/<lang>/<doc>.php` (one template per language and
 * document, written for this plugin, see PLAN 42.14 for why they are not gettext strings). The parts that change with the
 * site are built here and passed to the template as ready, escaped HTML:
 *
 *  - the list of cookies and services comes from `Report::site()` and nowhere else, so the policy cannot disagree with
 *    the banner;
 *  - recipients and transfers outside the EEA come from the same report plus the recipients typed in the wizard;
 *  - shipping and payment methods come from WooCommerce when it is active.
 *
 * `html()` returns the document with the cookie button left as a token (see COOKIE_BUTTON); the shortcode swaps it for the
 * real button when the page is shown. The token keeps the stored archive and the hash free of markup that depends on
 * the plugin version.
 */
final class LegalBuilder {

	/** Replaced by the "Cookie settings" button when the page is shown. */
	public const COOKIE_BUTTON = '%%UXS_COOKIE_BUTTON%%';

	public const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing' );

	/**
	 * Titles of the pages.
	 *
	 * @param string $doc  cookies | privacy | terms.
	 * @param string $lang cs | en.
	 */
	public static function title( string $doc, string $lang ): string {
		$titles = array(
			'cs' => array(
				'cookies' => 'Zásady používání cookies',
				'privacy' => 'Zásady ochrany osobních údajů',
				'terms'   => 'Obchodní podmínky',
			),
			'en' => array(
				'cookies' => 'Cookie policy',
				'privacy' => 'Privacy policy',
				'terms'   => 'Terms and conditions',
			),
		);
		return $titles['cs' === $lang ? 'cs' : 'en'][ $doc ] ?? $doc;
	}

	/**
	 * Slug proposed for a new page.
	 *
	 * @param string $doc  cookies | privacy | terms.
	 * @param string $lang cs | en.
	 */
	public static function slug( string $doc, string $lang ): string {
		$slugs = array(
			'cs' => array(
				'cookies' => 'zasady-cookies',
				'privacy' => 'zasady-ochrany-osobnich-udaju',
				'terms'   => 'obchodni-podminky',
			),
			'en' => array(
				'cookies' => 'cookie-policy',
				'privacy' => 'privacy-policy',
				'terms'   => 'terms-and-conditions',
			),
		);
		return $slugs['cs' === $lang ? 'cs' : 'en'][ $doc ] ?? $doc;
	}

	/**
	 * The template text of a document.
	 *
	 * @param string $doc  cookies | privacy | terms.
	 * @param string $lang cs | en.
	 */
	public static function template( string $doc, string $lang ): string {
		if ( ! in_array( $doc, LegalProfile::DOCS, true ) ) {
			return '';
		}
		$file = dirname( __FILE__ ) . '/legal/' . ( 'cs' === $lang ? 'cs' : 'en' ) . '/' . $doc . '.php';
		return is_readable( $file ) ? (string) require $file : '';
	}

	/**
	 * The document as safe HTML, cookie button still a token.
	 *
	 * @param string                    $doc     cookies | privacy | terms.
	 * @param string                    $lang    cs | en.
	 * @param array<string, mixed>      $profile Clean profile.
	 * @param array<string, mixed>|null $report  Result of Report::site() for $lang (null = read it now).
	 */
	public static function html( string $doc, string $lang, array $profile, ?array $report = null ): string {
		$report = $report ?? Report::site( $lang );
		$html   = LegalTemplate::render( self::template( $doc, $lang ), self::vars( $doc, $lang, $profile, $report ) );
		return wp_kses_post( $html );
	}

	/**
	 * Hash of a document: its text without the whitespace the template layout adds. A change in the wizard, in the
	 * list of cookies or in a template changes it.
	 *
	 * @param string $html Result of html().
	 */
	public static function hash( string $html ): string {
		return hash( 'sha256', trim( (string) preg_replace( '/\s+/', ' ', $html ) ) );
	}

	/**
	 * Every value a template can use.
	 *
	 * @param string               $doc     Document.
	 * @param string               $lang    cs | en.
	 * @param array<string, mixed> $profile Clean profile.
	 * @param array<string, mixed> $report  Report::site() result.
	 * @return array<string, mixed>
	 */
	public static function vars( string $doc, string $lang, array $profile, array $report ): array {
		$cs      = 'cs' === $lang;
		$purpose = (array) $profile['purposes'];
		$pages   = Legal::page_urls();
		$vars    = array(
			'controller'    => (string) $profile['name'],
			'ico'           => (string) $profile['ico'],
			'dic'           => (string) $profile['dic'],
			'address'       => (string) $profile['address'],
			'email'         => (string) $profile['email'],
			'phone'         => (string) $profile['phone'],
			'register_note' => (string) $profile['register_note'],
			'site'          => self::site_name(),
			'site_url'      => home_url( '/' ),
			'dpo_contact'   => (string) $profile['dpo_contact'],
			'authority'     => (string) $profile['authority_name'],
			'authority_url' => (string) $profile['authority_url'],
			'has_ico'       => '' !== $profile['ico'],
			'has_dic'       => '' !== $profile['dic'],
			'has_phone'     => '' !== $profile['phone'],
			'has_register'  => '' !== $profile['register_note'],
			'has_dpo'       => ! empty( $profile['dpo'] ),
			'has_eshop'     => ! empty( $profile['has_eshop'] ),
			'privacy_url'   => (string) ( $pages['privacy'] ?? '' ),
			'cookies_url'   => (string) ( $pages['cookies'] ?? '' ),
			'terms_url'     => (string) ( $pages['terms'] ?? '' ),
			'has_privacy_url' => '' !== (string) ( $pages['privacy'] ?? '' ),
			'has_cookies_url' => '' !== (string) ( $pages['cookies'] ?? '' ),
			'has_terms_url'   => '' !== (string) ( $pages['terms'] ?? '' ),
			'cookie_button' => self::COOKIE_BUTTON,
		);

		// Cookies and services: the one source is the site report.
		$vars['cookie_tables'] = self::cookie_tables( $report, $lang );
		foreach ( array( 'preferences', 'statistics', 'marketing' ) as $category ) {
			$vars[ 'has_' . $category ] = ! empty( $report['categories'][ $category ] );
		}
		$vars['has_optional_cookies'] = $vars['has_preferences'] || $vars['has_statistics'] || $vars['has_marketing'];
		$vars['consent_days']         = (string) max( 1, min( 365, (int) self::setting( 'expiry_days', 365 ) ) );
		$vars['log_on']               = (bool) self::setting( 'log_enabled', true );
		$vars['log_years']            = self::years( (int) self::setting( 'log_retention_days', Records::DEFAULT_DAYS ), $lang );

		// Purposes, recipients, transfers.
		$vars['purposes_html'] = self::purposes_html( $profile, $lang );
		foreach ( LegalProfile::purpose_keys() as $key ) {
			$vars[ 'p_' . $key ] = in_array( $key, $purpose, true );
		}
		$bases = array();
		foreach ( $purpose as $key ) {
			foreach ( (array) ( LegalProfile::purposes()[ $key ]['basis'] ?? array() ) as $basis ) {
				$bases[ $basis ] = true;
			}
		}
		$vars['uses_consent']  = isset( $bases['consent'] );
		$vars['uses_interest'] = isset( $bases['interest'] );
		$recipients            = self::recipients( $profile, $report, $lang );
		$vars['recipients_html'] = $recipients['html'];
		$vars['has_recipients']  = '' !== $recipients['html'];
		$vars['transfers_html']  = $recipients['transfers'];
		$vars['has_transfers']   = '' !== $recipients['transfers'];

		// Terms and conditions.
		$shop                       = self::shop( $lang );
		$vars['shipping_html']      = $shop['shipping'];
		$vars['payment_html']       = $shop['payment'];
		$vars['has_shipping']       = '' !== $shop['shipping'];
		$vars['has_payment']        = '' !== $shop['payment'];
		$vars['currency']           = $shop['currency'];
		$vars['prices_with_tax']    = $shop['with_tax'];
		$vars['prices_without_tax'] = ! $shop['with_tax'];
		$vars['delivery_note']      = (string) $profile['delivery_note'];
		$vars['payment_note']       = (string) $profile['payment_note'];
		$vars['return_by_customer'] = 'seller' !== $profile['return_costs'];
		$vars['return_by_seller']   = 'seller' === $profile['return_costs'];
		$vars['complaints_address'] = '' !== $profile['complaints_address'] ? (string) $profile['complaints_address'] : (string) $profile['address'];
		$vars['complaints_email']   = '' !== $profile['complaints_email'] ? (string) $profile['complaints_email'] : (string) $profile['email'];
		$vars['withdrawal_address'] = '' !== $profile['withdrawal_address'] ? (string) $profile['withdrawal_address'] : (string) $profile['address'];
		$vars['has_delivery_note']  = '' !== $profile['delivery_note'];
		$vars['has_payment_note']   = '' !== $profile['payment_note'];
		$vars['is_cs']              = $cs;
		return $vars;
	}

	/**
	 * Name of the site for the text ("this website").
	 */
	private static function site_name(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : (string) get_bloginfo( 'name' );
	}

	/**
	 * A module setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private static function setting( string $key, $default ) {
		$module = Inventory::module();
		return null === $module ? $default : $module->settings_value( $key, $default );
	}

	/**
	 * Days as "3 years" / "180 days" in words.
	 *
	 * @param int    $days Days.
	 * @param string $lang cs | en.
	 */
	public static function years( int $days, string $lang ): string {
		$days = $days > 0 ? $days : 1095;
		if ( 0 === $days % 365 ) {
			$years = intdiv( $days, 365 );
			if ( 'cs' === $lang ) {
				return $years . ( 1 === $years ? ' rok' : ( $years < 5 ? ' roky' : ' let' ) );
			}
			return $years . ( 1 === $years ? ' year' : ' years' );
		}
		if ( 'cs' === $lang ) {
			return $days . ( 1 === $days ? ' den' : ( $days < 5 ? ' dny' : ' dní' ) );
		}
		return $days . ( 1 === $days ? ' day' : ' days' );
	}

	/**
	 * Tables of the services and cookies by category (always the four categories, so the reader sees what is NOT used).
	 *
	 * @param array<string, mixed> $report Report::site().
	 * @param string               $lang   cs | en.
	 */
	public static function cookie_tables( array $report, string $lang ): string {
		$cs   = 'cs' === $lang;
		$head = array(
			'necessary'   => array(
				$cs ? 'Nezbytné cookies' : 'Necessary cookies',
				$cs ? 'Bez nich web nefunguje, například si nepamatuje vaši volbu cookies, nezabezpečí formuláře a přihlášení nebo neudrží obsah košíku. Používáme je bez vašeho souhlasu.' : 'The site cannot work without them, for example it would not remember your cookie choice, secure forms and sign-in or keep the contents of a basket. We use them without asking for consent.',
			),
			'preferences' => array(
				$cs ? 'Cookies pro předvolby' : 'Preference cookies',
				$cs ? 'Pamatují si vaše volby, například nastavení nebo rozepsanou konverzaci, aby web fungoval tak, jak jste zvyklí. Používáme je jen s vaším souhlasem.' : 'They remember your choices, for example settings or a conversation in progress, so the site works the way you are used to. We use them only with your consent.',
			),
			'statistics'  => array(
				$cs ? 'Statistické cookies' : 'Statistics cookies',
				$cs ? 'Ukazují nám, jaké stránky lidé navštěvují a jak web používají. Používáme je jen s vaším souhlasem.' : 'They show us which pages people visit and how the site is used. We use them only with your consent.',
			),
			'marketing'   => array(
				$cs ? 'Marketingové cookies' : 'Marketing cookies',
				$cs ? 'Měří účinnost reklamy a umožňují zobrazit nabídky, které by vás mohly zajímat, i mimo náš web. Patří sem také vložená videa, mapy a obsah sociálních sítí. Používáme je jen s vaším souhlasem.' : 'They measure how well advertising works and let us show offers that may interest you, also outside our site. Embedded videos, maps and social content belong here too. We use them only with your consent.',
			),
		);
		$none  = $cs ? 'V této kategorii teď nepoužíváme žádnou službu.' : 'We do not use any service in this category at the moment.';
		$words = array(
			'provider' => $cs ? 'Poskytovatel' : 'Provider',
			'policy'   => $cs ? 'zásady ochrany osobních údajů poskytovatele' : 'the provider\'s privacy policy',
			'cookie'   => 'Cookie',
			'purpose'  => $cs ? 'Účel' : 'Purpose',
			'duration' => $cs ? 'Doba platnosti' : 'Lifetime',
			'nocookie' => $cs ? 'Tato služba nenastavuje vlastní cookies nebo jejich seznam nemáme k dispozici.' : 'This service sets no cookies of its own, or we do not have a list of them.',
		);
		$out   = '';
		foreach ( self::CATEGORIES as $category ) {
			$out     .= '<h3>' . esc_html( $head[ $category ][0] ) . '</h3><p>' . esc_html( $head[ $category ][1] ) . '</p>';
			$services = (array) ( $report['categories'][ $category ] ?? array() );
			if ( array() === $services ) {
				$out .= '<p><em>' . esc_html( $none ) . '</em></p>';
				continue;
			}
			foreach ( $services as $service ) {
				$out .= '<h4>' . esc_html( (string) $service['label'] ) . '</h4>';
				$info = array();
				if ( '' !== (string) $service['processor'] ) {
					$info[] = esc_html( $words['provider'] . ': ' . $service['processor'] . ( '' !== (string) $service['country_name'] ? ' (' . $service['country_name'] . ')' : '' ) );
				}
				if ( '' !== (string) $service['transfer_text'] ) {
					$info[] = esc_html( (string) $service['transfer_text'] );
				}
				if ( '' !== (string) $service['note'] ) {
					$info[] = esc_html( (string) $service['note'] );
				}
				$link = '';
				if ( '' !== (string) $service['policy'] ) {
					$link = ' <a href="' . esc_url( (string) $service['policy'] ) . '" rel="noopener noreferrer" target="_blank">' . esc_html( $words['policy'] ) . '</a>';
				}
				if ( array() !== $info || '' !== $link ) {
					$out .= '<p>' . implode( ' ', $info ) . $link . '</p>';
				}
				$cookies = (array) $service['cookies'];
				if ( array() === $cookies ) {
					$out .= '<p><em>' . esc_html( $words['nocookie'] ) . '</em></p>';
					continue;
				}
				$out .= '<table class="uxs-legal-table"><thead><tr><th>' . esc_html( $words['cookie'] ) . '</th><th>' . esc_html( $words['purpose'] ) . '</th><th>' . esc_html( $words['duration'] ) . '</th></tr></thead><tbody>';
				foreach ( $cookies as $cookie ) {
					$out .= '<tr><td>' . esc_html( (string) $cookie['name'] ) . '</td><td>' . esc_html( (string) $cookie['purpose'] ) . '</td><td>' . esc_html( (string) $cookie['duration'] ) . '</td></tr>';
				}
				$out .= '</tbody></table>';
			}
		}
		return $out;
	}

	/**
	 * Purposes chosen in the wizard, each with data, reason, legal basis and retention.
	 *
	 * @param array<string, mixed> $profile Clean profile.
	 * @param string               $lang    cs | en.
	 */
	public static function purposes_html( array $profile, string $lang ): string {
		$cs    = 'cs' === $lang;
		$lang  = $cs ? 'cs' : 'en';
		$names = array(
			'contract' => $cs ? 'plnění smlouvy nebo jednání o jejím uzavření (čl. 6 odst. 1 písm. b) GDPR)' : 'performance of a contract or steps before concluding one (Article 6(1)(b) GDPR)',
			'legal'    => $cs ? 'splnění právní povinnosti (čl. 6 odst. 1 písm. c) GDPR)' : 'compliance with a legal obligation (Article 6(1)(c) GDPR)',
			'consent'  => $cs ? 'váš souhlas (čl. 6 odst. 1 písm. a) GDPR), který můžete kdykoli odvolat' : 'your consent (Article 6(1)(a) GDPR), which you can withdraw at any time',
			'interest' => $cs ? 'oprávněný zájem (čl. 6 odst. 1 písm. f) GDPR)' : 'legitimate interest (Article 6(1)(f) GDPR)',
		);
		$label = array(
			'data'      => $cs ? 'Jaké údaje' : 'Data',
			'why'       => $cs ? 'Proč' : 'Why',
			'basis'     => $cs ? 'Právní základ' : 'Legal basis',
			'interest'  => $cs ? 'Oprávněný zájem spočívá v tom, že chceme' : 'Our legitimate interest is',
			'retention' => $cs ? 'Jak dlouho' : 'How long',
		);
		$out   = '';
		foreach ( (array) $profile['purposes'] as $key ) {
			$def = LegalProfile::purposes()[ $key ] ?? null;
			if ( null === $def ) {
				continue;
			}
			$text      = $def[ $lang ];
			$retention = (string) ( $profile['retention'][ $key ] ?? '' );
			$retention = '' !== $retention ? $retention : (string) $text['retention'];
			$basis     = array();
			foreach ( (array) $def['basis'] as $b ) {
				$basis[] = $names[ $b ];
			}
			$out .= '<h3>' . esc_html( (string) $text['title'] ) . '</h3><ul>';
			$out .= '<li><strong>' . esc_html( $label['data'] ) . ':</strong> ' . esc_html( (string) $text['data'] ) . '.</li>';
			$out .= '<li><strong>' . esc_html( $label['why'] ) . ':</strong> ' . esc_html( (string) $text['why'] ) . '</li>';
			$out .= '<li><strong>' . esc_html( $label['basis'] ) . ':</strong> ' . esc_html( implode( $cs ? '; ' : '; ', $basis ) ) . '.';
			if ( '' !== (string) $text['interest'] && in_array( 'interest', (array) $def['basis'], true ) ) {
				$out .= ' ' . esc_html( $label['interest'] . ' ' . $text['interest'] ) . '.';
			}
			$out .= '</li>';
			$out .= '<li><strong>' . esc_html( $label['retention'] ) . ':</strong> ' . esc_html( rtrim( $retention, '. ' ) ) . '.</li></ul>';
		}
		return $out;
	}

	/**
	 * Recipients (processors found by the report plus the ones typed in the wizard) and the transfers outside the EEA.
	 *
	 * @param array<string, mixed> $profile Clean profile.
	 * @param array<string, mixed> $report  Report::site().
	 * @param string               $lang    cs | en.
	 * @return array{html: string, transfers: string}
	 */
	public static function recipients( array $profile, array $report, string $lang ): array {
		$cs    = 'cs' === $lang;
		$kinds = array(
			'carrier'    => $cs ? 'dopravce' : 'carrier',
			'payment'    => $cs ? 'platební služba' : 'payment service',
			'accounting' => $cs ? 'účetní a daňové služby' : 'accounting and tax services',
			'hosting'    => $cs ? 'hosting a správa webu' : 'hosting and site administration',
			'email'      => $cs ? 'rozesílání e-mailů' : 'e-mail delivery',
			'other'      => $cs ? 'další poskytovatel' : 'other provider',
		);
		$items     = array();
		$transfers = array();
		$seen      = array();
		foreach ( (array) ( $report['categories'] ?? array() ) as $services ) {
			foreach ( (array) $services as $service ) {
				$provider = trim( (string) $service['processor'] );
				// The own necessary cookies (WordPress, the consent cookie) have no outside provider.
				if ( '' === $provider || '' === (string) $service['country'] ) {
					continue;
				}
				$seen[ $provider ]['services'][] = (string) $service['label'];
				$seen[ $provider ]['country']    = (string) $service['country_name'];
				$seen[ $provider ]['transfer']   = (string) $service['transfer'];
				$seen[ $provider ]['text']       = (string) $service['transfer_text'];
			}
		}
		ksort( $seen );
		foreach ( $seen as $provider => $data ) {
			$services = implode( ', ', array_unique( $data['services'] ) );
			$items[]  = esc_html( $provider . ' (' . $services . ( '' !== $data['country'] ? '; ' . $data['country'] : '' ) . ')' );
			if ( 'eea' !== $data['transfer'] && '' !== $data['transfer'] ) {
				$transfers[] = esc_html( $provider . ' (' . $data['country'] . '): ' . $data['text'] );
			}
		}
		foreach ( (array) $profile['recipients'] as $row ) {
			$kind    = $kinds[ $row['kind'] ] ?? $kinds['other'];
			$items[] = esc_html( $row['name'] . ' (' . $kind . ')' );
			if ( ! empty( $row['third_country'] ) ) {
				$transfers[] = esc_html( $row['name'] . ': ' . ( $cs ? 'údaje mohou být předány mimo Evropský hospodářský prostor.' : 'data may be transferred outside the European Economic Area.' ) );
			}
		}
		$li = static fn( string $item ): string => '<li>' . $item . '</li>';
		return array(
			'html'      => array() === $items ? '' : '<ul>' . implode( '', array_map( $li, $items ) ) . '</ul>',
			'transfers' => array() === $transfers ? '' : '<ul>' . implode( '', array_map( $li, $transfers ) ) . '</ul>',
		);
	}

	/**
	 * Shipping and payment methods and price display from WooCommerce (empty when it is not active).
	 *
	 * @param string $lang cs | en.
	 * @return array{shipping: string, payment: string, currency: string, with_tax: bool}
	 */
	public static function shop( string $lang ): array {
		$out = array(
			'shipping' => '',
			'payment'  => '',
			'currency' => '',
			'with_tax' => true,
		);
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $out;
		}
		$cs                = 'cs' === $lang;
		$out['currency']   = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		$out['with_tax']   = function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;
		$symbol            = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : $out['currency'];
		$money             = static fn( $n ): string => number_format_i18n( (float) $n, 0 === ( (float) $n ) - floor( (float) $n ) ? 0 : 2 ) . ' ' . $symbol;
		$zones             = (array) \WC_Shipping_Zones::get_zones();
		$zones[]           = array( 'zone_id' => 0 );
		$lines             = array();
		foreach ( $zones as $zone_data ) {
			$zone = new \WC_Shipping_Zone( (int) $zone_data['zone_id'] );
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'yes' !== $method->enabled ) {
					continue;
				}
				$cost = '';
				if ( 'flat_rate' === $method->id ) {
					$value = (string) $method->get_option( 'cost' );
					$cost  = is_numeric( $value ) ? $money( $value ) : '';
				} elseif ( 'free_shipping' === $method->id ) {
					$min  = (string) $method->get_option( 'min_amount' );
					$cost = is_numeric( $min ) && (float) $min > 0
						? ( $cs ? 'zdarma od ' : 'free from ' ) . $money( $min )
						: ( $cs ? 'zdarma' : 'free' );
				}
				$zone_name = 0 === (int) $zone_data['zone_id'] ? ( $cs ? 'ostatní oblasti' : 'other areas' ) : (string) $zone->get_zone_name();
				$lines[]   = esc_html( (string) $method->get_title() . ( '' !== $cost ? ': ' . $cost : '' ) . ' (' . $zone_name . ')' );
			}
		}
		$out['shipping'] = array() === $lines ? '' : '<ul><li>' . implode( '</li><li>', array_values( array_unique( $lines ) ) ) . '</li></ul>';

		$titles = array();
		$wc     = \WC();
		$registry = is_object( $wc ) && method_exists( $wc, 'payment_gateways' ) ? $wc->payment_gateways() : null;
		if ( is_object( $registry ) ) {
			foreach ( (array) $registry->payment_gateways() as $gateway ) {
				if ( 'yes' === $gateway->enabled ) {
					$titles[] = esc_html( wp_strip_all_tags( (string) $gateway->get_title() ) );
				}
			}
		}
		$out['payment'] = array() === $titles ? '' : '<ul><li>' . implode( '</li><li>', array_values( array_unique( $titles ) ) ) . '</li></ul>';
		return $out;
	}
}
