<?php
/**
 * URL tester + rule audit. Both answer the question an SEO check asks: does
 * the old URL reach a live page in exactly one permanent hop?
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Redirects;

defined( 'ABSPATH' ) || exit;

final class Tester {

	/** Max hops followed by test(). */
	private const MAX_HOPS = 10;

	/** Max rules scanned by one audit run. */
	private const AUDIT_MAX = 5000;

	/**
	 * Resolve a URL through the rules (no HTTP), optionally also follow it
	 * over HTTP and report every hop's real status code.
	 *
	 * @param string $url  Old URL (any host) or path.
	 * @param bool   $live Also request it over HTTP.
	 * @return array<string, mixed>
	 */
	public static function test( string $url, bool $live = false ): array {
		$parsed  = Url::parse_source( $url );
		$path    = $parsed['path'];
		$query   = $parsed['query'];
		$steps   = array();
		$visited = array();
		$loop    = false;

		for ( $hop = 0; $hop < self::MAX_HOPS && '' !== $path; $hop++ ) {
			$key = Url::key( $path, $query );
			if ( isset( $visited[ $key ] ) ) {
				$loop = true;
				break;
			}
			$visited[ $key ] = true;

			$match = Repository::match( $path, $query );
			if ( ! $match ) {
				break;
			}
			$steps[] = array(
				'from'    => home_url( $key ),
				'code'    => (int) $match['rule']['code'],
				'to'      => $match['target'],
				'rule_id' => (int) $match['rule']['id'],
			);
			if ( '' === $match['target'] || ! Url::is_internal( $match['target'] ) ) {
				break;
			}
			$next  = explode( '?', Url::target_key( $match['target'] ), 2 );
			$path  = $next[0];
			$query = $next[1] ?? '';
		}

		$last   = $steps ? $steps[ count( $steps ) - 1 ] : null;
		$issues = array();
		if ( $loop ) {
			$issues[] = 'loop';
		}
		if ( count( $steps ) > 1 ) {
			$issues[] = 'chain';
		}
		if ( $last && in_array( $last['code'], array( 302, 307 ), true ) ) {
			$issues[] = 'temporary';
		}

		$result = array(
			'input'      => $url,
			'tested_url' => home_url( Url::key( $parsed['path'], $parsed['query'] ) ),
			'matched'    => (bool) $steps,
			'hops'       => count( $steps ),
			'final_url'  => $last ? $last['to'] : null,
			'final_code' => $last ? $last['code'] : null,
			'loop'       => $loop,
			'steps'      => $steps,
			'issues'     => $issues,
		);
		if ( $live ) {
			$result['live'] = self::follow( $result['tested_url'] );
			if ( ! $result['live']['ok'] ) {
				$result['issues'][] = 'live_check_failed';
			}
		}
		return $result;
	}

	/**
	 * Follow a URL over HTTP hop by hop (no automatic redirects).
	 *
	 * @param string $url Absolute URL.
	 * @return array{ok:bool, final_status:int, hops:int, chain:array<int, array{url:string, status:int, location:string}>, error?:string}
	 */
	public static function follow( string $url ): array {
		$chain = array();
		$seen  = array();
		for ( $i = 0; $i <= self::MAX_HOPS; $i++ ) {
			if ( isset( $seen[ $url ] ) ) {
				return array( 'ok' => false, 'final_status' => 0, 'hops' => count( $chain ), 'chain' => $chain, 'error' => 'loop' );
			}
			$seen[ $url ] = true;
			$args         = array(
				'redirection'         => 0,
				'timeout'             => 10,
				'limit_response_size' => 4096,
				'user-agent'          => 'UX Studio Redirect Checker',
			);
			if ( Url::is_internal( $url ) ) {
				// Same loopback rule WP core uses (local dev often has self-signed certs).
				$args['sslverify'] = apply_filters( 'https_local_ssl_verify', false );
				$response          = wp_remote_get( $url, $args );
			} else {
				$response = wp_safe_remote_get( $url, $args );
			}
			if ( is_wp_error( $response ) ) {
				return array( 'ok' => false, 'final_status' => 0, 'hops' => count( $chain ), 'chain' => $chain, 'error' => $response->get_error_message() );
			}
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$chain[]  = array(
				'url'      => $url,
				'status'   => $status,
				'location' => $location,
			);
			if ( $status < 300 || $status >= 400 || '' === $location ) {
				$hops = count( $chain ) - 1;
				return array(
					'ok'           => ( 200 === $status && $hops <= 1 ) || ( 410 === $status && 0 === $hops ),
					'final_status' => $status,
					'hops'         => $hops,
					'chain'        => $chain,
				);
			}
			$url = \WP_Http::make_absolute_url( $location, $url );
		}
		return array( 'ok' => false, 'final_status' => 0, 'hops' => count( $chain ), 'chain' => $chain, 'error' => 'too_many_redirects' );
	}

	/**
	 * Audit every enabled rule.
	 *
	 * @param bool $live       HTTP-check targets (slow; capped by $live_limit).
	 * @param int  $live_limit Max targets checked over HTTP.
	 * @param bool $fix        Re-save chained rules so they point straight at the final target.
	 * @return array{summary:array<string, int>, issues:array<int, array<string, mixed>>}
	 */
	public static function audit( bool $live = false, int $live_limit = 50, bool $fix = false ): array {
		global $wpdb;
		$table = Repository::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY hits DESC, id ASC LIMIT %d", self::AUDIT_MAX ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$issues  = array();
		$to_home = 0;
		$checked = 0;
		$fixed   = 0;
		$month   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		foreach ( $rows as $row ) {
			$id     = (int) $row['id'];
			$code   = (int) $row['code'];
			$source = (string) $row['source'];
			$add    = static function ( string $severity, string $issue, string $message ) use ( &$issues, $id, $source ): void {
				$issues[] = array(
					'severity' => $severity,
					'code'     => $issue,
					'rule_id'  => $id,
					'source'   => $source,
					'message'  => $message,
				);
			};

			if ( 'regex' === $row['match_type'] ) {
				if ( false === @preg_match( Repository::delimit( (string) $row['source_key'] ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$add( 'error', 'invalid_regex', __( 'Invalid regular expression - the rule never matches.', 'ux-studio' ) );
				}
				continue;
			}

			$post_id = (int) $row['target_post_id'];
			if ( $post_id > 0 && 'publish' !== get_post_status( $post_id ) ) {
				$add( 'error', 'target_post_gone', __( 'The target post is no longer published - pick a new target or switch to 410.', 'ux-studio' ) );
			}

			$test = self::test( (string) $row['source_key'] );
			if ( $test['loop'] ) {
				$add( 'error', 'loop', __( 'Redirect loop.', 'ux-studio' ) );
			} elseif ( $test['hops'] > 1 ) {
				if ( $fix ) {
					$result = Repository::save( array( 'id' => $id ) );
					if ( in_array( $result['status'], array( 'updated', 'unchanged' ), true ) && self::test( (string) $row['source_key'] )['hops'] <= 1 ) {
						++$fixed;
						continue;
					}
				}
				/* translators: %d: hops */
				$add( 'warning', 'chain', sprintf( __( 'Redirect chain with %d hops - each hop loses crawl budget; point straight at the final URL.', 'ux-studio' ), $test['hops'] ) );
			}

			if ( 410 !== $code && '/' === Url::target_key( (string) $row['target'] ) && 0 === $post_id ) {
				++$to_home;
			}
			if ( in_array( $code, array( 302, 307 ), true ) && (string) $row['created_at'] < $month ) {
				$add( 'warning', 'temporary_long', __( 'Temporary redirect older than 30 days - if the move is permanent, switch to 301 so the ranking is passed.', 'ux-studio' ) );
			}
			$path = explode( '?', (string) $row['source_key'], 2 )[0];
			if ( url_to_postid( home_url( $path ) ) > 0 ) {
				$add( 'warning', 'source_has_content', __( 'The source URL has live content again - the redirect hides it. Delete the rule if the page should be reachable.', 'ux-studio' ) );
			}

			if ( $live && 410 !== $code && $checked < $live_limit ) {
				++$checked;
				$target = Repository::resolve_target( $row );
				$check  = self::follow( $target );
				if ( 200 !== $check['final_status'] || $check['hops'] > 0 ) {
					/* translators: 1: status, 2: hops */
					$add( 'error', 'target_broken', sprintf( __( 'Target answers HTTP %1$d after %2$d extra hop(s) - it must answer 200 directly.', 'ux-studio' ), $check['final_status'], $check['hops'] ) );
				}
			}
		}

		$total = count( $rows );
		if ( $to_home >= 5 && $to_home > $total * 0.1 ) {
			$issues[] = array(
				'severity' => 'warning',
				'code'     => 'homepage_mass',
				'rule_id'  => null,
				'source'   => '',
				/* translators: %d: number of rules */
				'message'  => sprintf( __( '%d rules send visitors to the homepage. Google treats mass homepage redirects as soft 404s - map each URL to its closest page or use 410.', 'ux-studio' ), $to_home ),
			);
		}

		$summary = array(
			'rules'    => $total,
			'errors'   => count( array_filter( $issues, static fn ( array $i ): bool => 'error' === $i['severity'] ) ),
			'warnings' => count( array_filter( $issues, static fn ( array $i ): bool => 'warning' === $i['severity'] ) ),
			'live_checked' => $checked,
			'fixed'    => $fixed,
		);
		return array(
			'summary' => $summary,
			'issues'  => $issues,
		);
	}
}
