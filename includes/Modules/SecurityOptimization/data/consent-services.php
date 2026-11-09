<?php
/**
 * Cookie data of the shared consent catalogue (PLAN.md 42.2, 42.12). Read by CspServices::consent_services().
 *
 * Key = service label, the same label CspServices::consent_catalogue() gives a host (so a script from a known host
 * leads straight to its cookies). Services that are NOT in that host list (WordPress, WooCommerce, our own consent
 * cookie, Cloudflare, reCAPTCHA) carry their own `category` here.
 *
 * Service fields:
 *   category   only for services outside consent_catalogue(): necessary | preferences | statistics | marketing
 *   processor  who runs the service, as the provider names itself; '' = this website itself
 *   country    ISO 3166 alpha-2 code of the processor, `transfer` the way data leaves the EEA:
 *              us (USA, the provider relies on the EU-US Data Privacy Framework or standard contractual clauses),
 *              adequacy (country with an EU adequacy decision), scc (standard contractual clauses, several countries),
 *              eea (stays in the EEA)
 *   policy     privacy policy of the provider, '' when not sure of the address
 *   ocd        Open Cookie Database platform name(s) used for the cookies when `cookies` is empty
 *   note       [ 'en' => ..., 'cs' => ... ], shown when the service has no cookie list of its own
 *   cookies    rows of [ name or pattern, English purpose, Czech purpose, duration code ]; `*` in a name matches
 *              anything; a name starting with `~` is a regular expression (without delimiters)
 *
 * Duration code: `session`, or number + unit: s, min, h, d, mo (months), y (years). Taken from the provider's own
 * cookie documentation where it exists, otherwise from the Open Cookie Database; browsers may cap the real lifetime.
 *
 * The providers, countries and lifetimes are a starting point assembled from public provider information. The site
 * owner is responsible for checking them against what really runs (the dynamic scan of a later phase confirms the cookies).
 *
 * @package UxStudio
 */

defined( 'ABSPATH' ) || exit;

return array(
	// ---------------------------------------------------------------- necessary
	'WordPress'                => array(
		'category'  => 'necessary',
		'processor' => '',
		'country'   => '',
		'transfer'  => '',
		'policy'    => '',
		'cookies'   => array(
			array( 'wordpress_logged_in_*', 'Keeps a logged-in user signed in.', 'Udržuje přihlášení uživatele.', 'session' ),
			array( 'wordpress_sec_*', 'Secure sign-in cookie for the administration.', 'Zabezpečené přihlášení do administrace.', 'session' ),
			array( 'wordpress_test_cookie', 'Checks that the browser accepts cookies when logging in.', 'Ověřuje, že prohlížeč při přihlášení přijímá cookies.', 'session' ),
			array( 'wp-settings-*', 'Remembers the layout of the administration for a logged-in user.', 'Pamatuje si vzhled administrace přihlášeného uživatele.', '1y' ),
			array( 'wp-settings-time-*', 'Time of the last change of those settings.', 'Čas poslední změny těchto nastavení.', '1y' ),
		),
	),
	'WooCommerce'              => array(
		'category'  => 'necessary',
		'processor' => '',
		'country'   => '',
		'transfer'  => '',
		'policy'    => '',
		'cookies'   => array(
			array( 'woocommerce_cart_hash', 'Tells the shop when the contents of the cart change.', 'Eshop pozná, kdy se změnil obsah košíku.', 'session' ),
			array( 'woocommerce_items_in_cart', 'Tells the shop that the cart is not empty.', 'Eshop pozná, že košík není prázdný.', 'session' ),
			array( 'wp_woocommerce_session_*', 'Holds the shopping cart and the customer session.', 'Drží nákupní košík a relaci zákazníka.', '2d' ),
			array( 'woocommerce_recently_viewed', 'Feeds the list of recently viewed products.', 'Dodává seznam naposledy prohlížených produktů.', 'session' ),
			array( 'store_notice*', 'Remembers that a visitor closed the shop notice.', 'Pamatuje si, že návštěvník zavřel oznámení eshopu.', 'session' ),
		),
	),
	'Cookie consent'           => array(
		'category'  => 'necessary',
		'processor' => '',
		'country'   => '',
		'transfer'  => '',
		'policy'    => '',
		'cookies'   => array(
			array( 'uxs_consent', 'Remembers your cookie choice, so the banner does not ask again.', 'Pamatuje si vaši volbu cookies, aby se lišta znovu nezobrazovala.', '1y' ),
		),
	),
	'Cloudflare'               => array(
		'category'  => 'necessary',
		'processor' => 'Cloudflare, Inc.',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.cloudflare.com/privacypolicy/',
		'cookies'   => array(
			array( '__cf_bm', 'Tells people from bots, protects the site against abuse.', 'Rozlišuje lidi od robotů, chrání web před zneužitím.', '30min' ),
			array( '_cfuvid', 'Helps Cloudflare apply rate limits to separate visitors.', 'Pomáhá Cloudflare uplatnit limity pro jednotlivé návštěvníky.', 'session' ),
		),
	),
	'Google reCAPTCHA'         => array(
		'category'  => 'necessary',
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'cookies'   => array(
			array( '_GRECAPTCHA', 'Protects forms against spam and automated abuse.', 'Chrání formuláře před spamem a automatickým zneužitím.', '6mo' ),
		),
	),

	// ---------------------------------------------------------------- Google
	'Google Analytics'         => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'cookies'   => array(
			array( '_ga', 'Tells visitors apart to count visits and visitors.', 'Rozlišuje návštěvníky pro počítání návštěv a návštěvníků.', '2y' ),
			array( '_ga_*', 'Keeps the state of a session in Google Analytics 4.', 'Drží stav relace v Google Analytics 4.', '2y' ),
			array( '_gid', 'Tells visitors apart for the daily statistics.', 'Rozlišuje návštěvníky pro denní statistiky.', '1d' ),
			array( '_gat', 'Limits the number of requests to Google Analytics.', 'Omezuje počet požadavků na Google Analytics.', '1min' ),
			array( '_gat_gtag_*', 'Limits the number of requests to Google Analytics.', 'Omezuje počet požadavků na Google Analytics.', '1min' ),
			array( '_dc_gtm_*', 'Limits the number of requests from Google Tag Manager.', 'Omezuje počet požadavků z Google Tag Manageru.', '1min' ),
			array( '_gac_*', 'Keeps information about the advertising campaign the visitor came from.', 'Drží informace o reklamní kampani, ze které návštěvník přišel.', '3mo' ),
		),
	),
	'Google Tag Manager'       => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'note'      => array(
			'en' => 'The container itself sets no cookies. The tags that run inside it (analytics, advertising) set their own, listed under those services.',
			'cs' => 'Samotný kontejner žádné cookies nenastavuje. Tagy, které v něm běží (analytika, reklama), nastavují vlastní, uvedené u příslušných služeb.',
		),
		'cookies'   => array(),
	),
	'Google Ads'               => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'cookies'   => array(
			array( '_gcl_au', 'Measures how well advertising leads to a purchase or an enquiry.', 'Měří, jak reklama vede k nákupu nebo poptávce.', '3mo' ),
			array( '_gcl_aw', 'Keeps the click on a Google ad until a conversion.', 'Drží kliknutí na reklamu Google do uskutečnění konverze.', '3mo' ),
			array( '_gcl_dc', 'Keeps the click on a Google ad until a conversion.', 'Drží kliknutí na reklamu Google do uskutečnění konverze.', '3mo' ),
			array( '_gcl_gb', 'Keeps the click on a Google ad until a conversion.', 'Drží kliknutí na reklamu Google do uskutečnění konverze.', '3mo' ),
			array( '_gac_gb_*', 'Keeps information about the advertising campaign.', 'Drží informace o reklamní kampani.', '3mo' ),
			array( 'IDE', 'Shows relevant ads and measures their results.', 'Zobrazuje relevantní reklamy a měří jejich výsledky.', '13mo' ),
			array( 'test_cookie', 'Checks whether the browser accepts cookies.', 'Ověřuje, zda prohlížeč přijímá cookies.', '15min' ),
		),
	),
	'Google AdSense'           => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'cookies'   => array(
			array( '__gads', 'Shows ads and limits how often they repeat.', 'Zobrazuje reklamy a omezuje jejich opakování.', '13mo' ),
			array( '__gpi', 'Collects data on how the visitor uses sites with ads.', 'Sbírá údaje o tom, jak návštěvník používá weby s reklamou.', '13mo' ),
			array( '__eoi', 'Fraud protection and ad delivery.', 'Ochrana před podvody a doručování reklam.', '3mo' ),
			array( 'IDE', 'Shows relevant ads and measures their results.', 'Zobrazuje relevantní reklamy a měří jejich výsledky.', '13mo' ),
		),
	),
	'Google Maps'              => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'cookies'   => array(
			array( 'NID', 'Remembers preferences and shows personalised content in Google services.', 'Pamatuje si předvolby a zobrazuje personalizovaný obsah ve službách Google.', '6mo' ),
			array( 'AEC', 'Protects against abuse of the service.', 'Chrání službu před zneužitím.', '6mo' ),
			array( 'SOCS', 'Remembers the visitor\'s choice about Google cookies.', 'Pamatuje si volbu návštěvníka ohledně cookies Google.', '13mo' ),
		),
	),
	'YouTube'                  => array(
		'processor' => 'Google Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policies.google.com/privacy',
		'note'      => array(
			'en' => 'With the privacy-enhanced address (youtube-nocookie.com) YouTube sets these cookies only after the video is played.',
			'cs' => 'U rozšířeného režimu soukromí (youtube-nocookie.com) YouTube tyto cookies nastaví až po spuštění videa.',
		),
		'cookies'   => array(
			array( 'VISITOR_INFO1_LIVE', 'Estimates the connection speed and recognises the visitor.', 'Odhaduje rychlost připojení a rozpozná návštěvníka.', '6mo' ),
			array( 'YSC', 'Keeps statistics of the videos watched in the session.', 'Drží statistiky zhlédnutých videí v rámci relace.', 'session' ),
			array( 'VISITOR_PRIVACY_METADATA', 'Remembers the visitor\'s privacy choices on YouTube.', 'Pamatuje si volby soukromí návštěvníka na YouTube.', '6mo' ),
			array( '__Secure-ROLLOUT_TOKEN', 'Technical cookie for gradual rollout of new features.', 'Technická cookie pro postupné zavádění nových funkcí.', '6mo' ),
		),
	),

	// ---------------------------------------------------------------- Meta and social
	'Facebook'                 => array(
		'processor' => 'Meta Platforms Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.facebook.com/privacy/policy/',
		'cookies'   => array(
			array( '_fbp', 'Recognises the browser to measure and target Facebook ads.', 'Rozpozná prohlížeč pro měření a cílení reklam na Facebooku.', '3mo' ),
			array( '_fbc', 'Keeps the click on a Facebook ad until a conversion.', 'Drží kliknutí na reklamu na Facebooku do uskutečnění konverze.', '3mo' ),
			array( 'fr', 'Shows and measures advertising.', 'Zobrazuje a měří reklamu.', '3mo' ),
			array( 'datr', 'Security: recognises the browser and prevents fraud.', 'Zabezpečení: rozpozná prohlížeč a brání podvodům.', '2y' ),
			array( 'sb', 'Recognises the browser, helps with security and sign-in.', 'Rozpozná prohlížeč, pomáhá s bezpečností a přihlášením.', '2y' ),
		),
	),
	'Instagram'                => array(
		'processor' => 'Meta Platforms Ireland Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://privacycenter.instagram.com/policy',
		'ocd'       => 'Instagram',
		'cookies'   => array(),
	),
	'LinkedIn'                 => array(
		'processor' => 'LinkedIn Ireland Unlimited Company',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.linkedin.com/legal/privacy-policy',
		'cookies'   => array(
			array( 'lidc', 'Routing of requests to LinkedIn servers.', 'Směrování požadavků na servery LinkedInu.', '1d' ),
			array( 'bcookie', 'Recognises the browser, for LinkedIn features and advertising.', 'Rozpozná prohlížeč pro funkce a reklamu LinkedInu.', '1y' ),
			array( 'bscookie', 'Secure browser identification, protection against abuse.', 'Zabezpečená identifikace prohlížeče, ochrana před zneužitím.', '1y' ),
			array( 'UserMatchHistory', 'Matches the visitor with LinkedIn advertising.', 'Propojuje návštěvníka s reklamou LinkedInu.', 'session' ),
		),
	),
	'X (Twitter)'              => array(
		'processor' => 'Twitter International Unlimited Company',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://x.com/en/privacy',
		'cookies'   => array(
			array( 'guest_id', 'Recognises the browser of a visitor who is not signed in.', 'Rozpozná prohlížeč nepřihlášeného návštěvníka.', '2y' ),
			array( 'personalization_id', 'Personalises content and advertising.', 'Personalizuje obsah a reklamu.', '2y' ),
		),
	),
	'TikTok'                   => array(
		'processor' => 'TikTok Technology Limited',
		'country'   => 'US',
		'transfer'  => 'scc',
		'policy'    => 'https://www.tiktok.com/legal/page/eea/privacy-policy/en',
		'cookies'   => array(
			array( '_ttp', 'Measures and targets TikTok advertising.', 'Měří a cílí reklamu na TikToku.', '389d' ),
			array( '_tt_enable_cookie', 'Allows the TikTok pixel to recognise the visitor.', 'Umožňuje pixelu TikToku rozpoznat návštěvníka.', '389d' ),
		),
	),
	'Pinterest'                => array(
		'processor' => 'Pinterest Europe Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://policy.pinterest.com/en/privacy-policy',
		'ocd'       => 'Pinterest',
		'cookies'   => array(),
	),
	'Vimeo'                    => array(
		'processor' => 'Vimeo.com, Inc.',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://vimeo.com/privacy',
		'ocd'       => 'Vimeo',
		'cookies'   => array(),
	),

	// ---------------------------------------------------------------- Microsoft
	'Microsoft Advertising'    => array(
		'processor' => 'Microsoft Ireland Operations Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://privacy.microsoft.com/privacystatement',
		'cookies'   => array(
			array( '_uetsid', 'Session identifier of the Microsoft Advertising tag.', 'Identifikátor relace značky Microsoft Advertising.', '1d' ),
			array( '_uetvid', 'Recognises the visitor across visits for ad measurement.', 'Rozpozná návštěvníka napříč návštěvami pro měření reklamy.', '13mo' ),
			array( 'MUID', 'Identifies the browser for Microsoft advertising and analytics.', 'Identifikuje prohlížeč pro reklamu a analytiku Microsoftu.', '13mo' ),
		),
	),
	'Microsoft Clarity'        => array(
		'processor' => 'Microsoft Ireland Operations Limited',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://privacy.microsoft.com/privacystatement',
		'cookies'   => array(
			array( '_clck', 'Keeps the Clarity user ID and settings.', 'Drží ID uživatele a nastavení Clarity.', '1y' ),
			array( '_clsk', 'Joins page views of one visit into a session recording.', 'Spojuje zobrazení stránek jedné návštěvy do záznamu relace.', '1d' ),
			array( 'CLID', 'Identifies the first visit of the browser to Clarity.', 'Identifikuje první návštěvu prohlížeče v Clarity.', '1y' ),
		),
	),

	// ---------------------------------------------------------------- Czech services
	'Sklik (Seznam)'           => array(
		'processor' => 'Seznam.cz, a.s.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => 'https://o-seznam.cz/ochranaudaju/',
		'note'      => array(
			'en' => 'Sklik retargeting recognises a visitor by cookies of the Seznam.cz domain; their names and lifetimes are set by Seznam.',
			'cs' => 'Retargeting Sklik rozpozná návštěvníka podle cookies domény Seznam.cz; jejich názvy a platnost určuje Seznam.',
		),
		'cookies'   => array(),
	),
	'Heureka'                  => array(
		'processor' => 'Heureka Group a.s.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => '',
		'note'      => array(
			'en' => 'Heureka measures conversions and the Verified by Customers programme with its own cookies; names and lifetimes are set by Heureka.',
			'cs' => 'Heureka měří konverze a program Ověřeno zákazníky vlastními cookies; názvy a platnost určuje Heureka.',
		),
		'cookies'   => array(),
	),
	'Zboží.cz'                 => array(
		'processor' => 'Seznam.cz, a.s.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => 'https://o-seznam.cz/ochranaudaju/',
		'note'      => array(
			'en' => 'Conversion measurement for Zboží.cz uses cookies of the Seznam.cz domain; names and lifetimes are set by Seznam.',
			'cs' => 'Měření konverzí pro Zboží.cz používá cookies domény Seznam.cz; názvy a platnost určuje Seznam.',
		),
		'cookies'   => array(),
	),
	'Mapy.cz'                  => array(
		'processor' => 'Seznam.cz, a.s.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => 'https://o-seznam.cz/ochranaudaju/',
		'note'      => array(
			'en' => 'An embedded Mapy.cz map can store cookies and technical data of the Seznam.cz domain; names and lifetimes are set by Seznam.',
			'cs' => 'Vložená mapa Mapy.cz může ukládat cookies a technické údaje domény Seznam.cz; názvy a platnost určuje Seznam.',
		),
		'cookies'   => array(),
	),
	'Mapy.com'                 => array(
		'processor' => 'Seznam.cz, a.s.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => 'https://o-seznam.cz/ochranaudaju/',
		'note'      => array(
			'en' => 'An embedded Mapy.com map can store cookies and technical data of the Seznam.cz domain; names and lifetimes are set by Seznam.',
			'cs' => 'Vložená mapa Mapy.com může ukládat cookies a technické údaje domény Seznam.cz; názvy a platnost určuje Seznam.',
		),
		'cookies'   => array(),
	),

	// ---------------------------------------------------------------- statistics
	'Hotjar'                   => array(
		'processor' => 'Hotjar Limited',
		'country'   => 'MT',
		'transfer'  => 'eea',
		'policy'    => 'https://www.hotjar.com/privacy/',
		'cookies'   => array(
			array( '_hjSessionUser_*', 'Recognises the visitor on later visits to the same site.', 'Rozpozná návštěvníka při dalších návštěvách téhož webu.', '1y' ),
			array( '_hjSession_*', 'Holds the data of the current visit.', 'Drží údaje aktuální návštěvy.', '30min' ),
			array( '_hjFirstSeen', 'Detects the first visit of a new visitor.', 'Pozná první návštěvu nového návštěvníka.', '30min' ),
			array( '_hjAbsoluteSessionInProgress', 'Detects the beginning of a visit.', 'Pozná začátek návštěvy.', '30min' ),
			array( '_hjIncludedInSessionSample_*', 'Says whether the visit is part of the recorded sample.', 'Říká, zda je návštěva součástí nahrávaného vzorku.', '2min' ),
		),
	),
	'Cloudflare Web Analytics' => array(
		'processor' => 'Cloudflare, Inc.',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.cloudflare.com/privacypolicy/',
		'note'      => array(
			'en' => 'Cloudflare Web Analytics works without cookies.',
			'cs' => 'Cloudflare Web Analytics funguje bez cookies.',
		),
		'cookies'   => array(),
	),
	'Matomo'                   => array(
		'processor' => 'InnoCraft Ltd',
		'country'   => 'NZ',
		'transfer'  => 'adequacy',
		'policy'    => 'https://matomo.org/privacy-policy/',
		'cookies'   => array(
			array( '_pk_id.*', 'Recognises the visitor on later visits.', 'Rozpozná návštěvníka při dalších návštěvách.', '13mo' ),
			array( '_pk_ses.*', 'Holds the data of the current visit.', 'Drží údaje aktuální návštěvy.', '30min' ),
			array( '_pk_ref.*', 'Keeps the source the visitor came from.', 'Drží zdroj, ze kterého návštěvník přišel.', '6mo' ),
		),
	),
	'Smartlook'                => array(
		'processor' => 'Smartlook s.r.o.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => '',
		'note'      => array(
			'en' => 'Smartlook records visits to analyse behaviour; it stores a visitor identifier in the browser, the exact names and lifetimes are set by Smartlook.',
			'cs' => 'Smartlook nahrává návštěvy pro analýzu chování; v prohlížeči ukládá identifikátor návštěvníka, přesné názvy a platnost určuje Smartlook.',
		),
		'cookies'   => array(),
	),

	// ---------------------------------------------------------------- other
	'Mailchimp'                => array(
		'processor' => 'The Rocket Science Group LLC (Intuit Mailchimp)',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.intuit.com/privacy/statement/',
		'ocd'       => 'Mailchimp',
		'cookies'   => array(),
	),

	// ---------------------------------------------------------------- chat widgets
	'Smartsupp'                => array(
		'processor' => 'Smartsupp.com, s.r.o.',
		'country'   => 'CZ',
		'transfer'  => 'eea',
		'policy'    => '',
		'note'      => array(
			'en' => 'The chat remembers the conversation and the visitor in the browser; the exact names and lifetimes are set by Smartsupp.',
			'cs' => 'Chat si v prohlížeči pamatuje konverzaci a návštěvníka; přesné názvy a platnost určuje Smartsupp.',
		),
		'cookies'   => array(),
	),
	'Tawk.to'                  => array(
		'processor' => 'tawk.to inc.',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.tawk.to/privacy-policy/',
		'ocd'       => array( 'Tawk.to', 'Tawk.to Chat' ),
		'cookies'   => array(),
	),
	'Tidio'                    => array(
		'processor' => 'Tidio sp. z o.o.',
		'country'   => 'PL',
		'transfer'  => 'eea',
		'policy'    => 'https://www.tidio.com/privacy-policy/',
		'note'      => array(
			'en' => 'The chat remembers the conversation and the visitor in the browser; the exact names and lifetimes are set by Tidio.',
			'cs' => 'Chat si v prohlížeči pamatuje konverzaci a návštěvníka; přesné názvy a platnost určuje Tidio.',
		),
		'cookies'   => array(),
	),
	'Crisp'                    => array(
		'processor' => 'Crisp IM SAS',
		'country'   => 'FR',
		'transfer'  => 'eea',
		'policy'    => 'https://crisp.chat/en/privacy/',
		'ocd'       => 'Crisp',
		'cookies'   => array(),
	),
	'Intercom'                 => array(
		'processor' => 'Intercom R&D Unlimited Company',
		'country'   => 'US',
		'transfer'  => 'us',
		'policy'    => 'https://www.intercom.com/legal/privacy',
		'ocd'       => 'Intercom',
		'cookies'   => array(),
	),
);
