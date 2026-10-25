<?php
/**
 * PHP version check: is the PHP this site runs on safe? (PLAN.md 29, phase F2)
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Pure logic, no WordPress calls (tests/php-version-check.php runs it standalone).
 *
 * Three layers of data:
 *  1. BASELINE, bundled with the plugin: per branch the lowest safe version and the end of security support, plus
 *     the advisories we know about (so the check works offline and on a site that never reaches php.net).
 *  2. Remote data from php.net (see PhpVersionMaintenance), validated by normalize_remote() and merged by merge().
 *     It may RAISE the lowest safe version of a branch (a newer security release) and move the end of support
 *     EARLIER, never the other way round, so a wrong or hostile answer cannot make a vulnerable PHP look fine.
 *  3. The decision, evaluate(): current version + table + today -> level ok|warning|critical.
 *
 * Dates are `YYYY-MM-DD`, versions are `x.y.z`, a branch is `x.y`.
 */
final class PhpVersionCheck {

	/** A branch whose security support ends within this many days is a warning. */
	public const EOL_WARNING_DAYS = 90;

	/** Never trust a remote patch number above this (a typo or a hostile answer would flag every site). */
	private const MAX_PATCH = 99;

	/**
	 * Lowest safe version and end of security support per branch. Anything below the lowest branch is unsupported.
	 */
	public const BASELINE = array(
		'8.2' => array(
			'min' => '8.2.35',
			'eol' => '2026-12-31',
		),
		'8.3' => array(
			'min' => '8.3.36',
			'eol' => '2027-12-31',
		),
		'8.4' => array(
			'min' => '8.4.27',
			'eol' => '2028-12-31',
		),
		'8.5' => array(
			'min' => '8.5.12',
			'eol' => '2029-12-31',
		),
	);

	/**
	 * Known advisories. `fixed` is the first safe version per branch.
	 */
	public const ADVISORIES = array(
		array(
			'id'    => 'php-2026-10-21-5',
			'date'  => '2026-10-21',
			'url'   => 'https://www.php.net/archive/2026.php#2026-10-21-5',
			'cves'  => array( 'CVE-2026-109618', 'CVE-2026-109619' ),
			'fixed' => array(
				'8.2' => '8.2.35',
				'8.3' => '8.3.36',
				'8.4' => '8.4.27',
				'8.5' => '8.5.12',
			),
		),
	);

	/**
	 * The bundled table in the shape evaluate() takes.
	 *
	 * @return array{branches:array<string,array{min:string,eol:string}>,advisories:array<int,array<string,mixed>>}
	 */
	public static function baseline(): array {
		return array(
			'branches'   => self::BASELINE,
			'advisories' => self::ADVISORIES,
		);
	}

	/**
	 * Split a PHP_VERSION string such as `8.1.2-1ubuntu2.20` into its numeric part and the rest.
	 *
	 * @param string $version PHP_VERSION.
	 * @return array{version:string,branch:string,distro_build:bool}|null Null when no `x.y.z` prefix exists.
	 */
	public static function parse( string $version ): ?array {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)(.*)$/s', trim( $version ), $m ) ) {
			return null;
		}
		return array(
			'version'      => $m[1] . '.' . $m[2] . '.' . $m[3],
			'branch'       => $m[1] . '.' . $m[2],
			'distro_build' => '' !== $m[4],
		);
	}

	/**
	 * The decision.
	 *
	 * Rules, in this order:
	 *  - unparsable version -> ok (reason `unknown`), we do not guess;
	 *  - branch older than the lowest known one, or past its end of security support -> critical `unsupported`;
	 *  - version below the lowest safe one of its branch -> critical `outdated` (CVEs when a known advisory covers it);
	 *  - end of security support within EOL_WARNING_DAYS -> warning `eol_soon`;
	 *  - branch newer than the table (8.6 RC and later) or otherwise fine -> ok.
	 *
	 * @param string $php_version PHP_VERSION.
	 * @param array  $table       Table from baseline() / merge().
	 * @param string $today       `YYYY-MM-DD`, UTC.
	 * @return array{level:string,reason:string,branch:string,current:string,minimum:string,eol:string,days_to_eol:?int,cves:array<int,string>,advisory_url:string,distro_build:bool,recommended:string}
	 */
	public static function evaluate( string $php_version, array $table, string $today ): array {
		$result = array(
			'level'        => 'ok',
			'reason'       => '',
			'branch'       => '',
			'current'      => $php_version,
			'minimum'      => '',
			'eol'          => '',
			'days_to_eol'  => null,
			'cves'         => array(),
			'advisory_url' => '',
			'distro_build' => false,
			'recommended'  => '',
		);

		$parsed = self::parse( $php_version );
		if ( null === $parsed ) {
			$result['reason'] = 'unknown';
			return $result;
		}

		$branches = (array) ( $table['branches'] ?? array() );
		if ( array() === $branches ) {
			$result['reason'] = 'unknown';
			return $result;
		}

		$result['current']      = $parsed['version'];
		$result['branch']       = $parsed['branch'];
		$result['distro_build'] = $parsed['distro_build'];
		$result['recommended']  = self::recommended( $branches, $today );

		uksort( $branches, 'version_compare' );
		$keys   = array_keys( $branches );
		$lowest = (string) reset( $keys );
		$newest = (string) end( $keys );

		if ( version_compare( $parsed['branch'], $lowest, '<' ) ) {
			$result['level']  = 'critical';
			$result['reason'] = 'unsupported';
			return $result;
		}
		if ( version_compare( $parsed['branch'], $newest, '>' ) || ! isset( $branches[ $parsed['branch'] ] ) ) {
			// A branch newer than the table (an RC or a release we do not know yet): we have nothing to compare with.
			return $result;
		}

		$row                = $branches[ $parsed['branch'] ];
		$result['minimum']  = (string) $row['min'];
		$result['eol']      = (string) $row['eol'];
		$days               = self::days_between( $today, (string) $row['eol'] );
		$result['days_to_eol'] = $days;

		if ( null !== $days && $days < 0 ) {
			$result['level']  = 'critical';
			$result['reason'] = 'unsupported';
			return $result;
		}

		if ( version_compare( $parsed['version'], (string) $row['min'], '<' ) ) {
			$result['level']  = 'critical';
			$result['reason'] = 'outdated';
			foreach ( (array) ( $table['advisories'] ?? array() ) as $advisory ) {
				$fixed = $advisory['fixed'][ $parsed['branch'] ] ?? null;
				if ( is_string( $fixed ) && version_compare( $parsed['version'], $fixed, '<' ) ) {
					$result['cves']         = array_values( array_unique( array_merge( $result['cves'], (array) $advisory['cves'] ) ) );
					$result['advisory_url'] = '' === $result['advisory_url'] ? (string) $advisory['url'] : $result['advisory_url'];
				}
			}
			$result['recommended'] = (string) $row['min'];
			return $result;
		}

		if ( null !== $days && $days <= self::EOL_WARNING_DAYS ) {
			$result['level']  = 'warning';
			$result['reason'] = 'eol_soon';
		}
		return $result;
	}

	/**
	 * What to tell the owner to switch to when the branch is dead: the lowest branch that stays supported for at
	 * least another year (so the advice does not expire in a few months).
	 *
	 * @param array<string,array{min:string,eol:string}> $branches Branches of the table.
	 * @param string                                     $today    `YYYY-MM-DD`.
	 * @return string Version `x.y.z`, or an empty string when the table is empty.
	 */
	public static function recommended( array $branches, string $today ): string {
		uksort( $branches, 'version_compare' );
		$last = '';
		foreach ( $branches as $row ) {
			$last = (string) $row['min'];
			$days = self::days_between( $today, (string) $row['eol'] );
			if ( null !== $days && $days >= 365 ) {
				return $last;
			}
		}
		return $last;
	}

	/**
	 * Whole days from `$from` to `$to` (negative when `$to` is earlier).
	 *
	 * @param string $from `YYYY-MM-DD`.
	 * @param string $to   `YYYY-MM-DD`.
	 */
	public static function days_between( string $from, string $to ): ?int {
		$a = self::date_to_ts( $from );
		$b = self::date_to_ts( $to );
		return ( null === $a || null === $b ) ? null : (int) round( ( $b - $a ) / 86400 );
	}

	/**
	 * @param string $date `YYYY-MM-DD`.
	 */
	private static function date_to_ts( string $date ): ?int {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		return gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Is `$version` a clean `x.y.z` that belongs to `$branch` with a believable patch number?
	 *
	 * @param mixed  $version Candidate.
	 * @param string $branch  `x.y`.
	 */
	public static function valid_version( $version, string $branch ): bool {
		if ( ! is_string( $version ) || ! preg_match( '/^(\d+)\.(\d+)\.(\d+)\z/', $version, $m ) ) {
			return false;
		}
		return $m[1] . '.' . $m[2] === $branch && (int) $m[3] <= self::MAX_PATCH;
	}

	/**
	 * Turn the php.net answers into our own small shape, dropping everything that does not validate.
	 *
	 * @param mixed                $states   Decoded `releases/states` (major => branch => state, security_support_end ...).
	 * @param array<string,mixed>  $releases Per branch the decoded `releases/?json&version=<branch>&max=N` (version => info with `tags`).
	 * @return array<string,array{min:?string,eol:string}> Branch => data.
	 */
	public static function normalize_remote( $states, array $releases ): array {
		$out = array();
		if ( ! is_array( $states ) ) {
			return $out;
		}
		foreach ( $states as $by_branch ) {
			if ( ! is_array( $by_branch ) ) {
				continue;
			}
			foreach ( $by_branch as $branch => $info ) {
				$branch = (string) $branch;
				if ( ! preg_match( '/^\d+\.\d+\z/', $branch ) || ! is_array( $info ) ) {
					continue;
				}
				if ( ! in_array( $info['state'] ?? '', array( 'stable', 'security', 'bugfix' ), true ) ) {
					continue; // EOL, upcoming or unknown: the bundled table (or the "unsupported" rule) decides.
				}
				$eol = substr( (string) ( $info['security_support_end'] ?? '' ), 0, 10 );
				if ( null === self::date_to_ts( $eol ) ) {
					continue;
				}
				$min = null;
				foreach ( (array) ( $releases[ $branch ] ?? array() ) as $version => $release ) {
					$version = (string) $version;
					if ( ! self::valid_version( $version, $branch ) || ! is_array( $release ) ) {
						continue;
					}
					if ( ! in_array( 'security', (array) ( $release['tags'] ?? array() ), true ) ) {
						continue;
					}
					if ( null === $min || version_compare( $version, $min, '>' ) ) {
						$min = $version;
					}
				}
				$out[ $branch ] = array(
					'min' => $min,
					'eol' => $eol,
				);
			}
		}
		return $out;
	}

	/**
	 * Bundled table + validated remote data. The remote side can only make the result stricter.
	 *
	 * @param array                                         $baseline Table from baseline().
	 * @param array<string,array{min:?string,eol:string}>   $remote   Result of normalize_remote() (or the stored copy).
	 * @return array The merged table.
	 */
	public static function merge( array $baseline, array $remote ): array {
		$branches = (array) $baseline['branches'];
		foreach ( $remote as $branch => $row ) {
			$branch = (string) $branch;
			if ( ! preg_match( '/^\d+\.\d+\z/', $branch ) || ! is_array( $row ) ) {
				continue;
			}
			$min = $row['min'] ?? null;
			$eol = (string) ( $row['eol'] ?? '' );
			$min = self::valid_version( $min, $branch ) ? $min : null;
			$eol = null !== self::date_to_ts( $eol ) ? $eol : null;

			if ( isset( $branches[ $branch ] ) ) {
				if ( null !== $min && version_compare( $min, $branches[ $branch ]['min'], '>' ) ) {
					$branches[ $branch ]['min'] = $min;
				}
				if ( null !== $eol && $eol < $branches[ $branch ]['eol'] ) {
					$branches[ $branch ]['eol'] = $eol;
				}
				continue;
			}
			// A branch we have never heard of: only with a valid end of support, never "older than the table".
			if ( null !== $eol && version_compare( $branch, (string) array_key_last( $branches ), '>' ) ) {
				$branches[ $branch ] = array(
					'min' => $min ?? $branch . '.0',
					'eol' => $eol,
				);
			}
		}
		uksort( $branches, 'version_compare' );
		return array(
			'branches'   => $branches,
			'advisories' => (array) $baseline['advisories'],
		);
	}

	/**
	 * Is the notice hidden by the "verified with hosting" button?
	 *
	 * A new PHP version brings the notice back, so a hosting upgrade never stays silenced.
	 *
	 * @param mixed  $snooze  Stored option: array{until:int,version:string}.
	 * @param string $version Current PHP_VERSION.
	 * @param int    $now     Unix time.
	 */
	public static function snoozed( $snooze, string $version, int $now ): bool {
		return is_array( $snooze )
			&& (int) ( $snooze['until'] ?? 0 ) > $now
			&& (string) ( $snooze['version'] ?? '' ) === $version;
	}

	/**
	 * Is the e-mail due? Once when the site first turns critical, then at most weekly while it stays critical.
	 * (A little under 7 days, so a daily cron that fires an hour late does not skip a whole day.)
	 *
	 * @param mixed  $state Stored option: array{last:int,version:string}.
	 * @param string $level Current level.
	 * @param int    $now   Unix time.
	 */
	public static function mail_due( $state, string $level, int $now ): bool {
		if ( 'critical' !== $level ) {
			return false;
		}
		if ( ! is_array( $state ) || (int) ( $state['last'] ?? 0 ) <= 0 ) {
			return true;
		}
		return $now - (int) $state['last'] >= 7 * 86400 - 3600;
	}
}
