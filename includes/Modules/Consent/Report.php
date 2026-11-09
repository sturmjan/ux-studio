<?php
/**
 * The complete list of cookies and services of the site, by category. The one public read API of the module for the
 * cookie policy generator (F4) and the scanner (F3).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * Call `\UxStudio\Modules\Consent\Report::site()` (see PLAN.md 42.12 for the shape). It reads the same lists the banner
 * shows, so the cookie policy and the banner cannot disagree:
 *
 *  - the necessary services of the site itself (WordPress, the consent cookie, WooCommerce when active);
 *  - the services the inventory found and the owner classified;
 *  - the services named by hand in the module settings, the Pixel and Tag Manager IDs and GTM Kit.
 *
 * Every service carries the cookies of the shared catalogue (own texts, or the Open Cookie Database for services without
 * a list of their own). It is a description of what the catalogue and the inventory KNOW, not proof of what runs on the
 * site: the dynamic scan of F3 confirms the cookies. `unclassified` lists the hosts that still wait for a decision.
 */
final class Report {

	/**
	 * Services and cookies of this site.
	 *
	 * @param string|null $lang `cs` or `en`; null = language of the current locale.
	 * @return array{generated:int, language:string, scanned_at:int, categories:array<string, list<array<string, mixed>>>, cookies:list<array<string, string>>, unclassified:list<string>, open_cookie_database:array<string, mixed>}
	 */
	public static function site( ?string $lang = null ): array {
		$lang   = null === $lang ? Cookies::lang( determine_locale() ) : ( 'cs' === $lang ? 'cs' : 'en' );
		$labels = Inventory::banner_services();
		$module = Inventory::module();
		if ( null !== $module ) {
			// Hand-written lists, Pixel and Tag Manager, GTM Kit: exactly what the banner lists.
			foreach ( (array) ( $module->frontend_config()['c'] ?? array() ) as $category ) {
				foreach ( (array) ( $category['s'] ?? array() ) as $label ) {
					$labels[ (string) $category['id'] ][] = (string) $label;
				}
			}
		}
		$categories = Cookies::services_by_category( $labels, $lang );
		$cookies    = array();
		foreach ( $categories as $category => $services ) {
			foreach ( $services as $service ) {
				foreach ( $service['cookies'] as $cookie ) {
					$cookies[] = array(
						'name'      => $cookie['name'],
						'purpose'   => $cookie['purpose'],
						'duration'  => $cookie['duration'],
						'category'  => $category,
						'service'   => $service['label'],
						'processor' => $service['processor'],
						'source'    => $cookie['source'],
					);
				}
			}
		}
		$state = Inventory::state();
		return array(
			'generated'            => time(),
			'language'             => $lang,
			'scanned_at'           => $state['scanned_at'],
			'categories'           => $categories,
			'cookies'              => $cookies,
			'unclassified'         => array_values( array_map( static fn( array $q ): string => (string) $q['host'], $state['queue'] ) ),
			'open_cookie_database' => OpenCookieDb::meta(),
		);
	}
}
