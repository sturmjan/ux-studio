<?php
/**
 * Dynamic scan of cookies and scripts (PLAN.md 42.4, phase F3): receives what the Central App's browser saw on this site and
 * works out what it means.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

use UxStudio\Core\ActivityLog;
use UxStudio\Core\CentralApp\Gateway;
use UxStudio\Modules\SecurityOptimization\CspServices;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Division of work (PLAN.md 42.15): the Central App (a real browser on the NAS) only RECORDS what happens on the pages in three
 * runs (no consent, after "Accept all", after "Reject all") and sends the raw facts here. This class SANITIZES them, and the
 * classification happens here, because the catalogue of cookies and services lives in this plugin (`Cookies::match()`,
 * `Services::classify()`, `CspServices`), so a newer catalogue or a rule of the owner applies at once and the Central App needs
 * no copy of the catalogue.
 *
 * A violation is a cookie or a request to a tracking host that appears
 *  - in the run WITHOUT consent (only judged when the banner was visible there), or
 *  - after "Reject all" (only what appeared after the click; what was set before the click is the first run's business),
 * and that the catalogue says needs consent (category other than necessary). An unknown cookie or host is never a violation,
 * it is queued to be classified (an unknown cookie in the Scan tab, an unknown host in the inventory queue).
 *
 * The functions marked "pure" do not call WordPress and are covered by tests/consent-scan.php.
 */
final class Scan {

	public const OPTION = 'uxstudio_consent_scan';
	public const SCHEMA = 1;

	/** Order matters: it is the order of the runs and of the tables. */
	public const SCENARIOS = array( 'none', 'accept', 'reject' );

	/** Caps per scenario. The Central App keeps to the same ones; anything above is cut here as well. */
	public const MAX_COOKIES  = 150;
	public const MAX_STORAGE  = 150;
	public const MAX_REQUESTS = 400;
	public const MAX_PAGES    = 6;

	/** Cap on the violations and queues kept and shown. */
	private const MAX_ITEMS = 100;

	private const BANNERS = array( 'uxstudio', 'complianz', 'cookiebot', 'cookieyes', 'onetrust', 'borlabs', 'cookieconsent' );

	/** Phases of a cookie or a request: before any choice, right after the click, on the pages visited afterwards. */
	private const PHASES = array( 'load', 'choice', 'nav' );

	private const REQUEST_TYPES = array( 'script', 'xhr', 'fetch', 'image', 'iframe', 'font', 'stylesheet', 'media', 'websocket', 'ping', 'beacon', 'other' );

	// ================================================================== pure

	/**
	 * Plain text of a bounded length: control characters out, trimmed, cut.
	 *
	 * @param mixed $value Anything.
	 * @param int   $max   Most characters kept.
	 */
	public static function clean_text( $value, int $max = 200 ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value );
		$text = trim( $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Host name as the scan reports it: lower case, a plausible public name (an IP address or garbage becomes '').
	 *
	 * @param mixed $value Host.
	 */
	public static function clean_host( $value ): string {
		$host = strtolower( trim( self::clean_text( $value, 253 ), '. ' ) );
		return Inventory::valid_host( $host ) ? $host : '';
	}

	/**
	 * Cookie domain without the leading dot, lower case. Empty when it is no plausible host.
	 *
	 * @param mixed $value Domain attribute of the cookie.
	 */
	public static function clean_domain( $value ): string {
		return self::clean_host( ltrim( self::clean_text( $value, 253 ), '.' ) );
	}

	/**
	 * Address without query and fragment, https only, at most 200 characters (a query can carry tokens and identifiers).
	 *
	 * @param mixed $value Address or path.
	 */
	public static function clean_path( $value ): string {
		$path = self::clean_text( $value, 400 );
		$path = (string) strtok( $path, '?#' );
		return substr( $path, 0, 200 );
	}

	/**
	 * One phase name.
	 *
	 * @param mixed $value Phase.
	 */
	private static function phase( $value ): string {
		return in_array( $value, self::PHASES, true ) ? (string) $value : 'load';
	}

	/**
	 * Take what the Central App sent and keep only the known fields, with known types and bounded sizes. The result is the only
	 * form that is ever stored, evaluated or shown.
	 *
	 * @param mixed $raw Decoded body of the push.
	 * @return array<string, mixed>|null Null when it is no scan result at all.
	 */
	public static function sanitize( $raw ): ?array {
		if ( ! is_array( $raw ) || ! isset( $raw['scenarios'] ) || ! is_array( $raw['scenarios'] ) ) {
			return null;
		}
		$banner_kind = self::clean_text( $raw['banner']['kind'] ?? '', 20 );
		$out         = array(
			'schema'     => self::SCHEMA,
			'scanned_at' => self::clean_text( $raw['scanned_at'] ?? '', 40 ),
			'site_url'   => self::clean_path( $raw['site_url'] ?? '' ),
			'pages'      => array(),
			'banner'     => array(
				'kind'  => in_array( $banner_kind, self::BANNERS, true ) ? $banner_kind : '',
				'found' => ! empty( $raw['banner']['found'] ),
			),
			'scenarios'  => array(),
		);
		foreach ( array_slice( (array) ( $raw['pages'] ?? array() ), 0, self::MAX_PAGES ) as $page ) {
			$url = self::clean_path( $page );
			if ( '' !== $url ) {
				$out['pages'][] = $url;
			}
		}
		foreach ( self::SCENARIOS as $name ) {
			$s = $raw['scenarios'][ $name ] ?? null;
			if ( ! is_array( $s ) ) {
				continue;
			}
			$status            = self::clean_text( $s['status'] ?? '', 20 );
			$out['scenarios'][ $name ] = array(
				'status'         => in_array( $status, array( 'ok', 'banner_not_found', 'error' ), true ) ? $status : 'error',
				'error'          => self::clean_text( $s['error'] ?? '', 200 ),
				'banner_visible' => ! empty( $s['banner_visible'] ),
				'clicked'        => ! empty( $s['clicked'] ),
				'truncated'      => ! empty( $s['truncated'] ),
				'cookies'        => self::sanitize_cookies( (array) ( $s['cookies'] ?? array() ) ),
				'storage'        => self::sanitize_storage( (array) ( $s['storage'] ?? array() ) ),
				'requests'       => self::sanitize_requests( (array) ( $s['requests'] ?? array() ) ),
			);
		}
		return array() === $out['scenarios'] ? null : $out;
	}

	/**
	 * Cookies of one run. The scan never sends values and this never keeps any.
	 *
	 * @param array<int, mixed> $rows Cookies.
	 * @return list<array<string, mixed>>
	 */
	private static function sanitize_cookies( array $rows ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, self::MAX_COOKIES ) as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$name = self::clean_text( $c['name'] ?? '', 120 );
			if ( '' === $name ) {
				continue;
			}
			$same = self::clean_text( $c['same_site'] ?? '', 10 );
			$via  = self::clean_text( $c['via'] ?? '', 10 );
			$out[] = array(
				'name'        => $name,
				'domain'      => self::clean_domain( $c['domain'] ?? '' ),
				'path'        => self::clean_path( $c['path'] ?? '/' ),
				'expires'     => isset( $c['expires'] ) && is_numeric( $c['expires'] ) && (float) $c['expires'] > 0 ? (int) $c['expires'] : 0,
				'http_only'   => ! empty( $c['http_only'] ),
				'secure'      => ! empty( $c['secure'] ),
				'same_site'   => in_array( $same, array( 'Strict', 'Lax', 'None' ), true ) ? $same : '',
				'third_party' => ! empty( $c['third_party'] ),
				'phase'       => self::phase( $c['phase'] ?? '' ),
				'page'        => self::clean_path( $c['page'] ?? '' ),
				'via'         => in_array( $via, array( 'http', 'script' ), true ) ? $via : '',
				'by_host'     => self::clean_host( $c['by_host'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Keys of localStorage and sessionStorage (never values).
	 *
	 * @param array<int, mixed> $rows Storage rows.
	 * @return list<array<string, string>>
	 */
	private static function sanitize_storage( array $rows ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, self::MAX_STORAGE ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$key = self::clean_text( $r['key'] ?? '', 120 );
			if ( '' === $key ) {
				continue;
			}
			$out[] = array(
				'area'   => 'session' === ( $r['area'] ?? '' ) ? 'session' : 'local',
				'key'    => $key,
				'origin' => self::clean_host( $r['origin'] ?? '' ),
				'phase'  => self::phase( $r['phase'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Requests to other hosts, grouped by host, type and path.
	 *
	 * @param array<int, mixed> $rows Requests.
	 * @return list<array<string, mixed>>
	 */
	private static function sanitize_requests( array $rows ): array {
		$out = array();
		foreach ( array_slice( $rows, 0, self::MAX_REQUESTS ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$host = self::clean_host( $r['host'] ?? '' );
			if ( '' === $host ) {
				continue;
			}
			$type  = self::clean_text( $r['type'] ?? '', 12 );
			$out[] = array(
				'host'        => $host,
				'type'        => in_array( $type, self::REQUEST_TYPES, true ) ? $type : 'other',
				'path'        => self::clean_path( $r['path'] ?? '/' ),
				'third_party' => ! empty( $r['third_party'] ),
				'n_pre'       => max( 0, min( 100000, (int) ( $r['n_pre'] ?? 0 ) ) ),
				'n_post'      => max( 0, min( 100000, (int) ( $r['n_post'] ?? 0 ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Work out what a sanitized scan means.
	 *
	 * @param array<string, mixed>                                    $scan          Result of sanitize().
	 * @param callable(string): ?array{category:string, service:string, purpose:string, duration:string} $cookie_class Catalogue lookup of a cookie name (null = unknown).
	 * @param callable(string, string): array{kind:string, category:string, label:string}               $host_class   Classification of a host (and an example path): kind necessary | known | category | unknown.
	 * @param array<string, mixed>|null                               $previous      Keys of the previous evaluation (`keys`), null on the first scan.
	 * @param bool                                                    $advanced_gcm  Google Consent Mode "advanced" sends pings before consent by design: Google requests are then no violation.
	 * @return array<string, mixed>
	 */
	public static function evaluate( array $scan, callable $cookie_class, callable $host_class, ?array $previous = null, bool $advanced_gcm = false ): array {
		$scenarios  = (array) ( $scan['scenarios'] ?? array() );
		$violations = array();
		$unknown    = array();
		$cookies    = array();
		$hosts      = array();
		$state      = array();

		foreach ( self::SCENARIOS as $name ) {
			if ( ! isset( $scenarios[ $name ] ) ) {
				continue;
			}
			$s = $scenarios[ $name ];
			// Which runs can be judged at all: a run without a banner, or one that failed, proves nothing.
			$judged = false;
			$note   = '';
			if ( 'error' === $s['status'] ) {
				$note = 'error';
			} elseif ( 'accept' === $name ) {
				$note = '';
			} elseif ( 'none' === $name ) {
				$judged = ! empty( $s['banner_visible'] );
				$note   = $judged ? '' : 'no_banner';
			} else {
				$judged = ! empty( $s['clicked'] );
				$note   = $judged ? '' : 'banner_not_found';
			}
			$state[ $name ] = array(
				'status'   => $s['status'],
				'judged'   => $judged,
				'note'     => $note,
				'cookies'  => count( $s['cookies'] ),
				'requests' => count( $s['requests'] ),
				'storage'  => count( $s['storage'] ),
			);

			foreach ( $s['cookies'] as $c ) {
				$domain = '' !== $c['domain'] ? $c['domain'] : '*';
				$id     = strtolower( $c['name'] ) . '|' . $domain;
				$known  = $cookie_class( $c['name'] );
				if ( ! isset( $cookies[ $id ] ) ) {
					$cookies[ $id ] = array(
						'name'        => $c['name'],
						'domain'      => $c['domain'],
						'category'    => null === $known ? '' : $known['category'],
						'service'     => null === $known ? '' : $known['service'],
						'purpose'     => null === $known ? '' : $known['purpose'],
						'duration'    => null === $known ? '' : $known['duration'],
						'third_party' => $c['third_party'],
						'expires'     => $c['expires'],
						'http_only'   => $c['http_only'],
						'secure'      => $c['secure'],
						'same_site'   => $c['same_site'],
						'via'         => $c['via'],
						'by_host'     => $c['by_host'],
						'scenarios'   => array(),
						'first_seen'  => array( 'scenario' => $name, 'phase' => $c['phase'], 'page' => $c['page'] ),
					);
				}
				if ( ! in_array( $name, $cookies[ $id ]['scenarios'], true ) ) {
					$cookies[ $id ]['scenarios'][] = $name;
				}
				if ( null === $known ) {
					if ( ! isset( $unknown[ $id ] ) ) {
						$unknown[ $id ] = array( 'name' => $c['name'], 'domain' => $c['domain'], 'third_party' => $c['third_party'], 'scenarios' => array() );
					}
					if ( ! in_array( $name, $unknown[ $id ]['scenarios'], true ) ) {
						$unknown[ $id ]['scenarios'][] = $name;
					}
					continue;
				}
				if ( ! $judged || 'necessary' === $known['category'] ) {
					continue;
				}
				// After "Reject all" only what appeared after the click counts.
				if ( 'reject' === $name && 'load' === $c['phase'] ) {
					continue;
				}
				$key = $name . '|cookie|' . $id;
				if ( ! isset( $violations[ $key ] ) && count( $violations ) < self::MAX_ITEMS ) {
					$violations[ $key ] = array(
						'key'      => $key,
						'scenario' => $name,
						'kind'     => 'cookie',
						'name'     => $c['name'],
						'host'     => $c['domain'],
						'category' => $known['category'],
						'service'  => $known['service'],
						'phase'    => $c['phase'],
						'page'     => $c['page'],
					);
				}
			}

			foreach ( $s['requests'] as $r ) {
				if ( ! $r['third_party'] ) {
					continue;
				}
				$class = $host_class( $r['host'], $r['path'] );
				if ( ! isset( $hosts[ $r['host'] ] ) ) {
					$hosts[ $r['host'] ] = array( 'host' => $r['host'], 'kind' => $class['kind'], 'category' => $class['category'], 'label' => $class['label'], 'types' => array(), 'path' => $r['path'] );
				}
				$hosts[ $r['host'] ]['types'][ $r['type'] ] = ( $hosts[ $r['host'] ]['types'][ $r['type'] ] ?? 0 ) + 1;
				if ( ! $judged || 'category' !== $class['kind'] ) {
					continue;
				}
				// After "Reject all" a request seen only before the click is not the rejection's fault.
				$count = 'reject' === $name ? $r['n_post'] : $r['n_pre'] + $r['n_post'];
				if ( $count < 1 ) {
					continue;
				}
				if ( $advanced_gcm && 0 === strpos( $class['label'], 'Google' ) ) {
					continue;
				}
				$key = $name . '|request|' . $r['host'];
				if ( ! isset( $violations[ $key ] ) && count( $violations ) < self::MAX_ITEMS ) {
					$violations[ $key ] = array(
						'key'      => $key,
						'scenario' => $name,
						'kind'     => 'request',
						'name'     => $r['host'] . $r['path'],
						'host'     => $r['host'],
						'category' => $class['category'],
						'service'  => $class['label'],
						'phase'    => 'reject' === $name ? 'choice' : 'load',
						'page'     => '',
					);
				}
			}
		}

		$unknown_hosts = array();
		foreach ( $hosts as $h ) {
			if ( 'unknown' === $h['kind'] ) {
				$unknown_hosts[] = $h['host'];
			}
		}

		$violation_keys = array_keys( $violations );
		$unknown_keys   = array_keys( $unknown );
		$new_violations = null === $previous ? $violation_keys : array_values( array_diff( $violation_keys, (array) ( $previous['violations'] ?? array() ) ) );
		$new_unknown    = null === $previous ? array() : array_values( array_diff( $unknown_keys, (array) ( $previous['unknown'] ?? array() ) ) );

		foreach ( $violations as $key => $v ) {
			$violations[ $key ]['new'] = in_array( $key, $new_violations, true );
		}

		return array(
			'banner'          => (array) ( $scan['banner'] ?? array() ),
			'scenarios'       => $state,
			'violations'      => array_values( $violations ),
			'unknown_cookies' => array_slice( array_values( $unknown ), 0, self::MAX_ITEMS ),
			'unknown_hosts'   => array_slice( $unknown_hosts, 0, self::MAX_ITEMS ),
			'hosts'           => array_slice( array_values( $hosts ), 0, self::MAX_REQUESTS ),
			'cookies'         => array_slice( array_values( $cookies ), 0, 2 * self::MAX_COOKIES ),
			'keys'            => array( 'violations' => $violation_keys, 'unknown' => $unknown_keys ),
			'summary'         => array(
				'cookies'         => count( $cookies ),
				'unknown_cookies' => count( $unknown ),
				'violations'      => count( $violations ),
				'new_unknown'     => count( $new_unknown ),
				'new_violations'  => count( $new_violations ),
				'banner'          => (string) ( $scan['banner']['kind'] ?? '' ),
				'judged'          => array_map( static fn( array $x ): bool => $x['judged'], $state ),
			),
		);
	}

	/**
	 * Hosts of a scan as facts for the inventory queue (same shape as `Inventory::add_page()` keeps).
	 *
	 * @param list<array<string, mixed>> $hosts Hosts of evaluate().
	 * @return array<string, array<string, mixed>>
	 */
	public static function hosts_as_facts( array $hosts ): array {
		$out = array();
		foreach ( $hosts as $h ) {
			$out[ (string) $h['host'] ] = array(
				'k' => (array) $h['types'],
				'p' => 0,
				's' => array( 'scan' ),
				'h' => 0,
				'u' => array( Inventory::example_url( 'https://' . $h['host'] . $h['path'] ) ),
			);
		}
		return $out;
	}

	// ================================================================== WordPress

	/**
	 * Hook the scan in. Called once from Module::boot().
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( ScanRest::class, 'register' ) );
	}

	/**
	 * What the Central App needs before it scans: the pages to open.
	 *
	 * @return array{ok:bool, consent:bool, urls:list<string>, banner:string, mode:string}
	 */
	public static function plan(): array {
		$urls = array_slice( Inventory::sample_urls(), 0, self::MAX_PAGES - 2 );
		$mod  = Inventory::module();
		return array(
			'ok'      => true,
			'consent' => true,
			'urls'    => $urls,
			'banner'  => 'uxstudio',
			'mode'    => null === $mod ? '' : $mod->consent_mode(),
		);
	}

	/**
	 * Stored state (last scan, evaluation, and the keys of the previous one).
	 *
	 * @return array<string, mixed>
	 */
	public static function stored(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Take a pushed scan result: sanitize, evaluate, store, report back.
	 *
	 * @param mixed $body Decoded JSON body (`scan_id`, `result`).
	 * @return array<string, mixed>|WP_Error The reply for the Central App: `ok`, `summary`, a short `violations` list, `unknown_cookies` names.
	 */
	public static function receive( $body ) {
		$body = is_array( $body ) ? $body : array();
		$scan = self::sanitize( $body['result'] ?? null );
		if ( null === $scan ) {
			return new WP_Error( 'uxstudio_consent_scan_bad', __( 'The scan result is not valid.', 'ux-studio' ), array( 'status' => 400 ) );
		}
		$stored   = self::stored();
		$previous = isset( $stored['evaluation']['keys'] ) ? (array) $stored['evaluation']['keys'] : null;
		$lang     = Cookies::lang( determine_locale() );
		$mod      = Inventory::module();
		$eval     = self::evaluate(
			$scan,
			static function ( string $name ) use ( $lang ): ?array {
				$m = Cookies::match( $name, $lang );
				return null === $m ? null : array(
					'category' => $m['category'],
					'service'  => $m['service'],
					'purpose'  => $m['purpose'],
					'duration' => $m['duration'],
				);
			},
			array( self::class, 'classify_host' ),
			$previous,
			null !== $mod && 'advanced' === $mod->consent_mode()
		);

		update_option(
			self::OPTION,
			array(
				'received_at' => time(),
				'scan_id'     => max( 0, (int) ( $body['scan_id'] ?? 0 ) ),
				'scan'        => $scan,
				'evaluation'  => $eval,
				'requested'   => (int) ( $stored['requested'] ?? 0 ),
			),
			false
		);
		ActivityLog::log( 'consent', 'scan_received', 'setting', 0, $eval['summary'] );

		// The Central App keeps the counts and a short list for its fleet overview and the notification; the full
		// evaluation stays in this plugin.
		return array(
			'ok'              => true,
			'summary'         => $eval['summary'],
			'violations'      => array_map(
				static fn( array $v ): array => array(
					'scenario' => $v['scenario'],
					'kind'     => $v['kind'],
					'name'     => $v['name'],
					'service'  => $v['service'],
					'category' => $v['category'],
					'new'      => (bool) $v['new'],
				),
				array_slice( $eval['violations'], 0, 20 )
			),
			'unknown_cookies' => array_slice( array_map( static fn( array $c ): string => (string) $c['name'], $eval['unknown_cookies'] ), 0, 20 ),
		);
	}

	/**
	 * Classification of a host for the evaluation: an own rule, the catalogue, a host known to need no consent, or unknown.
	 * The same order as `Inventory::evaluate()`.
	 *
	 * @param string $host Host.
	 * @param string $path Example path.
	 * @return array{kind:string, category:string, label:string}
	 */
	public static function classify_host( string $host, string $path = '/' ): array {
		$site = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$rule = Services::rule_for_host( $host, Inventory::rules() );
		if ( null !== $rule ) {
			$entry = CspServices::consent_for_host( $host );
			$label = null !== $entry ? $entry['label'] : $host;
			return 'necessary' === $rule['category']
				? array( 'kind' => 'necessary', 'category' => 'necessary', 'label' => $label )
				: array( 'kind' => 'category', 'category' => $rule['category'], 'label' => $label );
		}
		$found = Services::classify( 'https://' . $host . ( '' === $path ? '/' : $path ), $site, array() );
		if ( null !== $found ) {
			return array( 'kind' => 'category', 'category' => explode( ' ', $found['category'] )[0], 'label' => $found['label'] );
		}
		$label = CspServices::label_for_host( $host );
		if ( '' !== $label ) {
			return array( 'kind' => 'known', 'category' => '', 'label' => $label );
		}
		return array( 'kind' => 'unknown', 'category' => '', 'label' => '' );
	}

	/**
	 * Hosts the last scan saw, as inventory facts (merged by Inventory::facts() so they land in the queue to be classified).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function inventory_hosts(): array {
		$stored = self::stored();
		return self::hosts_as_facts( (array) ( $stored['evaluation']['hosts'] ?? array() ) );
	}

	/**
	 * Cookies found by the last scan for the public report (the cookie policy of phase F4): bounded, no values.
	 *
	 * @return array{scanned_at:int, cookies:list<array<string, mixed>>}
	 */
	public static function report_cookies(): array {
		$stored = self::stored();
		$rows   = array();
		foreach ( (array) ( $stored['evaluation']['cookies'] ?? array() ) as $c ) {
			$rows[] = array(
				'name'      => (string) $c['name'],
				'domain'    => (string) $c['domain'],
				'category'  => (string) $c['category'],
				'service'   => (string) $c['service'],
				'purpose'   => (string) $c['purpose'],
				'duration'  => (string) $c['duration'],
				'party'     => ! empty( $c['third_party'] ) ? 'third' : 'first',
				'scenarios' => array_values( (array) $c['scenarios'] ),
			);
		}
		return array(
			'scanned_at' => (int) ( $stored['received_at'] ?? 0 ),
			'cookies'    => array_slice( $rows, 0, 2 * self::MAX_COOKIES ),
		);
	}

	/**
	 * What the admin tab shows.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		$stored = self::stored();
		$eval   = (array) ( $stored['evaluation'] ?? array() );
		return array(
			'paired'          => Gateway::is_paired(),
			'active'          => Gateway::is_active(),
			'received_at'     => (int) ( $stored['received_at'] ?? 0 ),
			'requested_at'    => (int) ( $stored['requested'] ?? 0 ),
			'scanned_at'      => (string) ( $stored['scan']['scanned_at'] ?? '' ),
			'pages'           => array_values( (array) ( $stored['scan']['pages'] ?? array() ) ),
			'banner'          => (array) ( $eval['banner'] ?? array() ),
			'scenarios'       => (array) ( $eval['scenarios'] ?? array() ),
			'summary'         => (array) ( $eval['summary'] ?? array() ),
			'violations'      => array_values( (array) ( $eval['violations'] ?? array() ) ),
			'unknown_cookies' => array_values( (array) ( $eval['unknown_cookies'] ?? array() ) ),
			'unknown_hosts'   => array_values( (array) ( $eval['unknown_hosts'] ?? array() ) ),
			'cookies'         => array_values( (array) ( $eval['cookies'] ?? array() ) ),
		);
	}

	/**
	 * Ask the Central App to scan this site. The Central App queues it and the cron there runs it within minutes (a scan takes
	 * three runs of a browser, too long to wait for in a request), then pushes the result to `receive()`.
	 *
	 * @return array{queued:bool, message:string}|WP_Error
	 */
	public static function request_scan() {
		if ( ! Gateway::is_paired() ) {
			return new WP_Error( 'uxstudio_consent_scan_unpaired', __( 'This site is not connected to the central app.', 'ux-studio' ), array( 'status' => 424 ) );
		}
		$result = Gateway::post( 'consent_scan_api', array( 'site_url' => home_url( '/' ), 'reason' => 'manual' ), array( 'action' => 'request' ) );
		if ( $result instanceof WP_Error ) {
			return $result;
		}
		$code = (int) $result['code'];
		$body = (array) $result['body'];
		if ( 429 === $code ) {
			return new WP_Error( 'uxstudio_consent_scan_wait', __( 'The central app scanned this site a short while ago. Try again later.', 'ux-studio' ), array( 'status' => 429 ) );
		}
		if ( 409 === $code || 410 === $code || 423 === $code ) {
			return new WP_Error( 'uxstudio_consent_scan_link', __( 'The central app does not accept the request now (the site address or the connection needs attention).', 'ux-studio' ), array( 'status' => 409 ) );
		}
		if ( ( 200 !== $code && 202 !== $code ) || empty( $body['queued'] ) ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'uxstudio_consent_scan_failed', sprintf( __( 'The central app answered with HTTP %d.', 'ux-studio' ), $code ), array( 'status' => 502 ) );
		}
		$stored              = self::stored();
		$stored['requested'] = time();
		update_option( self::OPTION, $stored, false );
		return array(
			'queued'  => true,
			'message' => __( 'The scan is queued in the central app. The result appears here within a few minutes.', 'ux-studio' ),
		);
	}
}
