<?php
/**
 * Static inventory of the third-party services a site loads, and the queue of hosts nobody classified yet.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Modules\SecurityOptimization\CspLogger;
use UxStudio\Modules\SecurityOptimization\CspServices;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.4, static layer. Sources of external hosts:
 *
 *  - the HTML of a sample of the site's own pages (home, a few posts and pages, a product, the cart and checkout of
 *    WooCommerce), fetched like a visitor would and read for script, stylesheet, iframe and image addresses plus known
 *    inline snippets. Reading the delivered HTML instead of `wp_scripts()` also catches code pasted straight into a
 *    template or a page builder, and shows what a cache really serves;
 *  - the CSP violation table of the Security Optimization module (hosts that browsers reported).
 *
 * Each host is then mapped to a service and a category through the shared catalogue (CspServices). A host the
 * catalogue does not know is put in the queue "to be classified"; the owner puts it in a category or marks it necessary,
 * which is stored as an own rule (setting `custom_rules`) that Blocker already honours. Nothing is decided automatically.
 *
 * What is stored is only the raw facts (hosts, a few example addresses, counts) in an option that is not autoloaded.
 * The classification is worked out on every read, so a new rule or a newer catalogue applies at once.
 *
 * The functions marked "pure" do not call WordPress and are covered by tests/consent.php.
 */
final class Inventory {

	public const OPTION    = 'uxstudio_consent_inventory';
	public const CRON_HOOK = 'uxstudio_consent_inventory_run';

	/** At most this many own pages are fetched in one run. */
	private const MAX_PAGES = 10;

	/** Seconds for a whole run, so an admin request does not hang. */
	private const BUDGET = 30;

	/** Example addresses kept per host. */
	private const MAX_URLS = 5;

	private static ?Module $module = null;

	/**
	 * Hook the inventory in. Called once from Module::boot().
	 *
	 * @param Module $module The consent module.
	 */
	public static function boot( Module $module ): void {
		self::$module = $module;
		add_action( self::CRON_HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( 'rest_api_init', array( InventoryRest::class, 'register' ) );
		add_filter( 'uxstudio_rest_public_routes', array( self::class, 'public_routes' ) );
	}

	/**
	 * The two public routes of the banner must stay reachable when Security Optimization limits REST to logged-in users.
	 *
	 * @param mixed $routes Route prefixes already registered as public.
	 * @return array<int, string>
	 */
	public static function public_routes( $routes ): array {
		$routes   = is_array( $routes ) ? $routes : array();
		$routes[] = '/uxstudio/v1/consent/region';
		$routes[] = '/uxstudio/v1/consent/cookies';
		return $routes;
	}

	/** Weekly scan by WP-Cron (the module uses WP-Cron like the others; no Action Scheduler needed for one job). */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK );
		}
	}

	/** Module switched off: stop the weekly job. The stored inventory stays. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The consent module, when booted (needed to read and write its settings).
	 */
	public static function module(): ?Module {
		return self::$module;
	}

	// ================================================================== pure

	/**
	 * Is it a plausible public host name?
	 *
	 * @param string $host Host.
	 */
	public static function valid_host( string $host ): bool {
		return 1 === preg_match( '/^(?=.{4,253}$)[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $host ) && 1 !== preg_match( '/^\d+(\.\d+){3}$/', $host );
	}

	/**
	 * Address kept as an example: scheme, host and path, plus the `id` of a Google tag (it tells analytics from
	 * advertising). Any other query and the fragment are dropped (they can carry tokens).
	 *
	 * @param string $url Absolute or protocol-relative address.
	 */
	public static function example_url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$out = 'https://' . strtolower( (string) $parts['host'] ) . (string) ( $parts['path'] ?? '/' );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $args );
			if ( isset( $args['id'] ) && is_string( $args['id'] ) && 1 === preg_match( '/^[A-Za-z0-9\-]{3,40}$/', $args['id'] ) ) {
				$out .= '?id=' . $args['id'];
			}
		}
		return substr( $out, 0, 200 );
	}

	/**
	 * References to other hosts in one page of HTML.
	 *
	 * @param string $html      Page HTML.
	 * @param string $site_host Host of the site itself (its own addresses are not listed).
	 * @return array{refs:list<array{host:string, url:string, kind:string}>, inline:array<string,string>}
	 */
	public static function scan_html( string $html, string $site_host = '' ): array {
		$refs   = array();
		$inline = array();
		$add    = static function ( string $raw, string $kind ) use ( &$refs, $site_host ): void {
			$raw = trim( html_entity_decode( $raw, ENT_QUOTES ) );
			if ( 0 === strpos( $raw, '//' ) ) {
				$raw = 'https:' . $raw;
			}
			if ( 1 !== preg_match( '#^https?://#i', $raw ) ) {
				return;
			}
			$host = strtolower( (string) parse_url( $raw, PHP_URL_HOST ) );
			if ( ! self::valid_host( $host ) ) {
				return;
			}
			if ( '' !== $site_host && ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) ) {
				return;
			}
			$refs[] = array(
				'host' => $host,
				'url'  => self::example_url( $raw ),
				'kind' => $kind,
			);
		};
		$attr = static function ( string $attrs, string $name ): string {
			return 1 === preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', ' ' . $attrs, $m ) ? ( $m[1] ?? '' ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' ) : '';
		};

		if ( preg_match_all( '~<script\b([^>]*)>(.*?)</script>~is', $html, $scripts, PREG_SET_ORDER ) ) {
			foreach ( $scripts as $s ) {
				$src = $attr( $s[1], 'src' );
				if ( '' !== $src ) {
					$add( $src, 'script' );
				} elseif ( '' !== trim( $s[2] ) && strlen( $s[2] ) < 200000 && 1 === preg_match( '/^(?:|text\/javascript|application\/javascript|text\/plain)$/i', $attr( $s[1], 'type' ) ) ) {
					$found = Services::classify_inline( $s[2] );
					if ( null !== $found ) {
						$inline[ $found['label'] ] = explode( ' ', $found['category'] )[0];
					}
				}
			}
		}
		if ( preg_match_all( '~<link\b([^>]*)>~i', $html, $links, PREG_SET_ORDER ) ) {
			foreach ( $links as $l ) {
				if ( 1 === preg_match( '/\b(stylesheet|preload|modulepreload)\b/i', $attr( $l[1], 'rel' ) ) ) {
					$add( $attr( $l[1], 'href' ), 'style' );
				}
			}
		}
		if ( preg_match_all( '~<iframe\b([^>]*)>~i', $html, $frames, PREG_SET_ORDER ) ) {
			foreach ( $frames as $f ) {
				$add( '' !== $attr( $f[1], 'data-uxc-src' ) ? $attr( $f[1], 'data-uxc-src' ) : $attr( $f[1], 'src' ), 'frame' );
			}
		}
		if ( preg_match_all( '~<img\b([^>]*)>~i', $html, $images, PREG_SET_ORDER ) ) {
			foreach ( $images as $i ) {
				$add( $attr( $i[1], 'src' ), 'img' );
			}
		}
		return array(
			'refs'   => $refs,
			'inline' => $inline,
		);
	}

	/**
	 * Add the references of one page to the stored facts.
	 *
	 * @param array<string, mixed>                                                              $facts  Stored facts (see OPTION).
	 * @param array{refs:list<array{host:string, url:string, kind:string}>, inline:array<string,string>} $found Result of scan_html().
	 * @return array<string, mixed>
	 */
	public static function add_page( array $facts, array $found ): array {
		$facts['hosts']  = (array) ( $facts['hosts'] ?? array() );
		$facts['inline'] = (array) ( $facts['inline'] ?? array() );
		$seen            = array();
		foreach ( $found['refs'] as $ref ) {
			$host = $ref['host'];
			$row  = (array) ( $facts['hosts'][ $host ] ?? array() ) + array(
				'k' => array(),
				'p' => 0,
				's' => array(),
				'h' => 0,
				'u' => array(),
			);
			$row['k'][ $ref['kind'] ] = (int) ( $row['k'][ $ref['kind'] ] ?? 0 ) + 1;
			if ( ! isset( $seen[ $host ] ) ) {
				$seen[ $host ] = true;
				++$row['p'];
			}
			if ( ! in_array( 'html', $row['s'], true ) ) {
				$row['s'][] = 'html';
			}
			if ( '' !== $ref['url'] && count( $row['u'] ) < self::MAX_URLS && ! in_array( $ref['url'], $row['u'], true ) ) {
				$row['u'][] = $ref['url'];
			}
			$facts['hosts'][ $host ] = $row;
		}
		foreach ( $found['inline'] as $label => $category ) {
			$facts['inline'][ $label ] = $category;
		}
		return $facts;
	}

	/**
	 * Add hosts the CSP reports saw.
	 *
	 * @param array<string, mixed>                                      $facts Stored facts.
	 * @param list<array{host:string, hits:int, url:string}>            $rows  Hosts from the violation table.
	 * @param string                                                    $site_host Own host.
	 * @return array<string, mixed>
	 */
	public static function add_csp( array $facts, array $rows, string $site_host = '' ): array {
		$facts['hosts'] = (array) ( $facts['hosts'] ?? array() );
		foreach ( $rows as $r ) {
			$host = strtolower( $r['host'] );
			if ( ! self::valid_host( $host ) || ( '' !== $site_host && ( $host === $site_host || str_ends_with( $host, '.' . $site_host ) ) ) ) {
				continue;
			}
			$row = (array) ( $facts['hosts'][ $host ] ?? array() ) + array(
				'k' => array(),
				'p' => 0,
				's' => array(),
				'h' => 0,
				'u' => array(),
			);
			if ( ! in_array( 'csp', $row['s'], true ) ) {
				$row['s'][] = 'csp';
			}
			$row['h'] = (int) $r['hits'];
			$url      = self::example_url( $r['url'] );
			if ( '' !== $url && count( $row['u'] ) < self::MAX_URLS && ! in_array( $url, $row['u'], true ) ) {
				$row['u'][] = $url;
			}
			$facts['hosts'][ $host ] = $row;
		}
		return $facts;
	}

	/**
	 * Work out what the stored facts mean under the current rules and catalogue.
	 *
	 * @param array<string, mixed>                          $facts     Stored facts.
	 * @param list<array{pattern:string, category:string}> $rules     Parsed own rules.
	 * @param string                                        $site_host Own host.
	 * @return array{services:array<string, array{label:string, category:string, hosts:list<string>}>, queue:list<array<string,mixed>>, decided:list<array{host:string, category:string, label:string}>, known:list<array{host:string, label:string}>}
	 */
	public static function evaluate( array $facts, array $rules, string $site_host = '' ): array {
		$services = array();
		$queue    = array();
		$decided  = array();
		$known    = array();

		$put = static function ( string $label, string $category, string $host ) use ( &$services ): void {
			if ( ! isset( $services[ $label ] ) ) {
				$services[ $label ] = array(
					'label'    => $label,
					'category' => $category,
					'hosts'    => array(),
				);
			}
			if ( '' !== $host && ! in_array( $host, $services[ $label ]['hosts'], true ) ) {
				$services[ $label ]['hosts'][] = $host;
			}
		};

		foreach ( (array) ( $facts['hosts'] ?? array() ) as $host => $row ) {
			$host = (string) $host;
			$rule = Services::rule_for_host( $host, $rules );
			if ( null !== $rule ) {
				$entry     = CspServices::consent_for_host( $host );
				$label     = null !== $entry ? $entry['label'] : $host;
				$decided[] = array(
					'host'     => $host,
					'category' => $rule['category'],
					'label'    => $label,
				);
				if ( 'necessary' !== $rule['category'] ) {
					$put( $label, $rule['category'], $host );
				}
				continue;
			}
			$hit  = false;
			$urls = (array) ( $row['u'] ?? array() );
			foreach ( array() === $urls ? array( 'https://' . $host . '/' ) : $urls as $url ) {
				$found = Services::classify( (string) $url, $site_host, array() );
				if ( null !== $found ) {
					$hit = true;
					$put( $found['label'], explode( ' ', $found['category'] )[0], $host );
				}
			}
			if ( $hit ) {
				continue;
			}
			$label = CspServices::label_for_host( $host );
			if ( '' !== $label ) {
				$known[] = array(
					'host'  => $host,
					'label' => $label,
				);
				continue;
			}
			$queue[] = array(
				'host'     => $host,
				'kinds'    => (array) ( $row['k'] ?? array() ),
				'pages'    => (int) ( $row['p'] ?? 0 ),
				'sources'  => array_values( (array) ( $row['s'] ?? array() ) ),
				'hits'     => (int) ( $row['h'] ?? 0 ),
				'examples' => array_slice( $urls, 0, 2 ),
			);
		}
		foreach ( (array) ( $facts['inline'] ?? array() ) as $label => $category ) {
			$put( (string) $label, (string) $category, '' );
		}

		usort( $queue, static fn( array $a, array $b ): int => array( $b['pages'], $b['hits'] ) <=> array( $a['pages'], $a['hits'] ) );
		return array(
			'services' => $services,
			'queue'    => $queue,
			'decided'  => $decided,
			'known'    => $known,
		);
	}

	/**
	 * The own-rules text after deciding a host. Lines for other hosts and comments stay as written.
	 *
	 * @param string $text     Current rules textarea.
	 * @param string $host     Host to decide.
	 * @param string $category preferences | statistics | marketing | necessary, or `remove` to forget the rule.
	 */
	public static function rules_with( string $text, string $host, string $category ): string {
		$lines = array();
		foreach ( preg_split( '/\R/', $text ) ?: array() as $line ) {
			$first = strtolower( (string) strtok( trim( $line ), " \t" ) );
			if ( $first === $host ) {
				continue; // The old decision for this host is replaced.
			}
			$lines[] = rtrim( $line );
		}
		if ( 'remove' !== $category ) {
			$lines[] = $host . ' ' . $category;
		}
		return trim( implode( "\n", $lines ) );
	}

	// ================================================================== WordPress

	/**
	 * Own pages worth looking at: the home page, a few posts and pages, and the shop pages of WooCommerce.
	 *
	 * @return list<string>
	 */
	public static function sample_urls(): array {
		$urls = array( home_url( '/' ) );
		foreach ( array( 'post', 'page' ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'has_password'   => false,
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = (string) get_permalink( (int) $id );
			}
		}
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'limit'  => 1,
					'status' => 'publish',
					'return' => 'ids',
				)
			);
			foreach ( $products as $id ) {
				$urls[] = (string) get_permalink( (int) $id );
			}
			foreach ( array( 'wc_get_cart_url', 'wc_get_checkout_url' ) as $fn ) {
				if ( function_exists( $fn ) ) {
					$urls[] = (string) $fn();
				}
			}
		}
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out  = array();
		foreach ( $urls as $url ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $url && $host === $home && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
		}
		/**
		 * Pages the inventory reads. Only addresses of this site are used.
		 *
		 * @param string[] $out Page addresses.
		 */
		$out = (array) apply_filters( 'uxstudio_consent_inventory_pages', $out );
		return array_slice( array_values( array_filter( array_map( 'strval', $out ) ) ), 0, self::MAX_PAGES );
	}

	/**
	 * Hosts reported by the CSP violation table (empty when the table does not exist).
	 *
	 * @return list<array{host:string, hits:int, url:string}>
	 */
	public static function csp_hosts(): array {
		global $wpdb;
		$table = CspLogger::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- the table name comes from CspLogger, there is no user input.
		$rows = $wpdb->get_results( "SELECT blocked_host, MAX(blocked_uri) AS uri, SUM(hit_count) AS hits FROM {$table} WHERE blocked_host <> '' AND directive IN ('script-src','script-src-elem','frame-src','child-src','connect-src','img-src') GROUP BY blocked_host ORDER BY hits DESC LIMIT 200", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'host' => (string) $r['blocked_host'],
				'hits' => (int) $r['hits'],
				'url'  => CspLogger::strip_query( (string) $r['uri'] ),
			);
		}
		return $out;
	}

	/**
	 * Fetch the sample pages, read them, add the CSP hosts and store the facts.
	 *
	 * @return array<string, mixed> The stored facts.
	 */
	public static function run(): array {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- best effort for a slow site.
		}
		$site  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$facts = array(
			'at'     => time(),
			'pages'  => array(),
			'hosts'  => array(),
			'inline' => array(),
		);
		$until = microtime( true ) + self::BUDGET;
		foreach ( self::sample_urls() as $url ) {
			if ( microtime( true ) > $until ) {
				break;
			}
			// A request to the site's own address, taken from its own permalinks; not user input.
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 12,
					'redirection'         => 2,
					'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook for loopback requests.
					'user-agent'          => 'UXStudio-Consent-Inventory/1',
					'limit_response_size' => 3 * MB_IN_BYTES,
					'headers'             => array( 'Accept' => 'text/html' ),
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			if ( false === stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'html' ) ) {
				continue;
			}
			$facts            = self::add_page( $facts, self::scan_html( (string) wp_remote_retrieve_body( $response ), $site ) );
			$facts['pages'][] = $url;
		}
		$facts = self::add_csp( $facts, self::csp_hosts(), $site );
		update_option( self::OPTION, $facts, false );
		return $facts;
	}

	/**
	 * Stored facts, or an empty structure before the first run.
	 *
	 * @return array<string, mixed>
	 */
	public static function facts(): array {
		$facts = get_option( self::OPTION, array() );
		return is_array( $facts ) ? $facts : array();
	}

	/**
	 * The own rules as parsed lists.
	 *
	 * @return list<array{pattern:string, category:string}>
	 */
	public static function rules(): array {
		return null === self::$module ? array() : Services::parse_rules( (string) self::$module->settings_value( 'custom_rules', '' ) );
	}

	/**
	 * The inventory as the admin page and the report need it.
	 *
	 * @return array{scanned_at:int, pages:list<string>, services:array<string, array{label:string, category:string, hosts:list<string>}>, queue:list<array<string,mixed>>, decided:list<array{host:string, category:string, label:string}>, known:list<array{host:string, label:string}>}
	 */
	public static function state(): array {
		$facts = self::facts();
		$site  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return array(
			'scanned_at' => (int) ( $facts['at'] ?? 0 ),
			'pages'      => array_values( array_map( 'strval', (array) ( $facts['pages'] ?? array() ) ) ),
		) + self::evaluate( $facts, self::rules(), $site );
	}

	/**
	 * Services the banner lists on every page, by category: the site's own necessary cookies plus what the inventory
	 * found and the owner classified. (What Blocker meets on the current page and the hand-written lists are added by
	 * Module::frontend_config().)
	 *
	 * @return array<string, list<string>>
	 */
	public static function banner_services(): array {
		$out = array( 'necessary' => array( 'WordPress', 'Cookie consent' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$out['necessary'][] = 'WooCommerce';
		}
		foreach ( self::state()['services'] as $service ) {
			$out[ $service['category'] ][] = $service['label'];
		}
		return $out;
	}

	/**
	 * Decide a host: put it in a category, mark it necessary, or forget the decision.
	 *
	 * @param string $host     Host from the inventory.
	 * @param string $category preferences | statistics | marketing | necessary | remove.
	 * @return true|WP_Error
	 */
	public static function assign( string $host, string $category ) {
		$host = strtolower( trim( $host ) );
		if ( null === self::$module ) {
			return new WP_Error( 'uxstudio_consent_off', __( 'The Cookie Consent module is not active.', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ! self::valid_host( $host ) || ! in_array( $category, array_merge( Services::CATEGORIES, array( 'necessary', 'remove' ) ), true ) ) {
			return new WP_Error( 'uxstudio_consent_bad_rule', __( 'This host or category is not valid.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$facts = self::facts();
		$known = isset( $facts['hosts'][ $host ] ) || null !== Services::rule_for_host( $host, self::rules() );
		if ( ! $known ) {
			return new WP_Error( 'uxstudio_consent_unknown_host', __( 'This host is not in the inventory.', 'ux-studio' ), array( 'status' => 404 ) );
		}
		$old  = (string) self::$module->settings_value( 'custom_rules', '' );
		$text = self::rules_with( $old, $host, $category );
		if ( 'remove' !== $category && count( Services::parse_rules( $old ) ) >= 100 && null === Services::rule_for_host( $host, self::rules() ) ) {
			return new WP_Error( 'uxstudio_consent_too_many_rules', __( 'Too many own rules (the limit is 100).', 'ux-studio' ), array( 'status' => 409 ) );
		}
		self::$module->save_settings( array( 'custom_rules' => $text ) );
		return true;
	}
}
