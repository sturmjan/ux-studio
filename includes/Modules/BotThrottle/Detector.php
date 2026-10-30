<?php
/**
 * Bot detection by User-Agent, IP whitelist/blacklist and optional rDNS.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless detector: given a UA + IP, decide whether the request is a known
 * bot and which category it belongs to.
 */
final class Detector {

	/** @var array<string,array> Category definitions. */
	private array $categories;

	/** @var string[] */
	private array $whitelist_ua;

	/** @var string[] */
	private array $whitelist_ip;

	/** @var string[] */
	private array $blacklist_ua;

	private bool $verify_rdns;

	/** @var callable(string):string|false Reverse lookup (IP => host name); replaceable in tests. */
	private $reverse_lookup;

	/** @var callable(string,bool):string[] Forward lookup (host name, IPv6? => IPs); replaceable in tests. */
	private $forward_lookup;

	/**
	 * @param array $config whitelist_ua/whitelist_ip/blacklist_ua/verify_rdns/categories.
	 */
	public function __construct( array $config = array() ) {
		$this->categories   = $config['categories'] ?? Signatures::categories();
		$this->whitelist_ua = (array) ( $config['whitelist_ua'] ?? array() );
		$this->whitelist_ip = (array) ( $config['whitelist_ip'] ?? array() );
		$this->blacklist_ua = (array) ( $config['blacklist_ua'] ?? array() );
		$this->verify_rdns  = (bool) ( $config['verify_rdns'] ?? false );

		$this->reverse_lookup = isset( $config['reverse_lookup'] ) && is_callable( $config['reverse_lookup'] )
			? $config['reverse_lookup']
			: static fn ( string $ip ) => @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->forward_lookup = isset( $config['forward_lookup'] ) && is_callable( $config['forward_lookup'] )
			? $config['forward_lookup']
			: array( self::class, 'forward_ips' );
	}

	/**
	 * Detect a bot from a UA + IP. Returns null for non-bots, otherwise
	 * [ category, name, verified ].
	 *
	 * With reverse-DNS verification on, a search engine whose address is proven NOT to belong to that engine
	 * (it claims to be Googlebot, but the address resolves to another company) is not trusted: it is filed under
	 * the generic crawlers, which are throttled hard, and `verified` is false. A lookup that gives no answer at all
	 * never counts against a real engine (see verify_reverse_dns()).
	 *
	 * @param string $user_agent Request User-Agent.
	 * @param string $ip         Client IP.
	 * @return array{category:string,name:string,verified:bool}|null
	 */
	public function detect( string $user_agent, string $ip ): ?array {
		if ( '' === $user_agent ) {
			return null;
		}

		// Whitelist - treated as not-a-bot for throttling purposes.
		foreach ( $this->whitelist_ua as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern && false !== stripos( $user_agent, $pattern ) ) {
				return null;
			}
		}
		if ( ! empty( $this->whitelist_ip ) && self::ip_in_list( $ip, $this->whitelist_ip ) ) {
			return null;
		}

		// Blacklist - always a bot, generic category.
		foreach ( $this->blacklist_ua as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern && false !== stripos( $user_agent, $pattern ) ) {
				return array(
					'category' => 'blacklist',
					'name'     => 'blacklisted-ua',
					'verified' => false,
				);
			}
		}

		// Match against category signatures.
		foreach ( $this->categories as $cat_id => $cat ) {
			foreach ( (array) ( $cat['bots'] ?? array() ) as $bot ) {
				$pattern = (string) ( $bot['pattern'] ?? '' );
				if ( '' === $pattern ) {
					continue;
				}
				if ( preg_match( '#' . $pattern . '#i', $user_agent ) ) {
					$verified = $this->verify_rdns ? $this->verify_reverse_dns( $ip, (string) $bot['name'] ) : true;
					if ( ! $verified && 'search_engines' === $cat_id ) {
						return array(
							'category' => 'generic_crawlers',
							'name'     => (string) $bot['name'] . ' (unverified)',
							'verified' => false,
						);
					}
					return array(
						'category' => (string) $cat_id,
						'name'     => (string) $bot['name'],
						'verified' => $verified,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Reverse-DNS verification (slow). Only the major search engines are verifiable - unknown bots pass through
	 * as verified.
	 *
	 * Returns false only when the address is PROVEN to be someone else: it has a host name, but the name is not in
	 * the engine's domains or does not resolve back to the same address. When the lookup gives no answer at all
	 * (no PTR record, a slow or broken DNS server of the host) the bot is given the benefit of the doubt, because
	 * throttling a real Googlebot by mistake would harm the search ranking. Proven results are remembered for a
	 * day, "no answer" for ten minutes.
	 *
	 * @param string $ip       Client IP.
	 * @param string $bot_name Detected bot name.
	 */
	private function verify_reverse_dns( string $ip, string $bot_name ): bool {
		$verifiable = Signatures::verifiable_rdns();
		if ( ! isset( $verifiable[ $bot_name ] ) ) {
			return true;
		}

		$cache_key = 'uxstudio_bt_rdns_' . md5( $ip . '|' . $bot_name );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '0' !== $cached;
		}

		// '1' = proven, '0' = proven to be someone else, 'u' = no answer.
		$status   = 'u';
		$hostname = ( $this->reverse_lookup )( $ip );
		if ( is_string( $hostname ) && '' !== $hostname && $hostname !== $ip ) {
			$hostname = strtolower( rtrim( $hostname, '.' ) );
			$status   = '0';
			foreach ( $verifiable[ $bot_name ] as $suffix ) {
				if ( substr( $hostname, -strlen( $suffix ) ) !== $suffix ) {
					continue;
				}
				$forward = ( $this->forward_lookup )( $hostname, false !== strpos( $ip, ':' ) );
				if ( array() === $forward ) {
					$status = 'u';
					break;
				}
				if ( self::same_ip( $ip, $forward ) ) {
					$status = '1';
					break;
				}
			}
		}

		set_transient( $cache_key, $status, 'u' === $status ? 10 * MINUTE_IN_SECONDS : DAY_IN_SECONDS );
		return '0' !== $status;
	}

	/**
	 * IP addresses a host name resolves to (A records, or AAAA for an IPv6 client).
	 *
	 * @param string $host Host name.
	 * @param bool   $v6   Look for IPv6 addresses.
	 * @return string[] Empty when the lookup fails.
	 */
	public static function forward_ips( string $host, bool $v6 ): array {
		$out     = array();
		$records = @dns_get_record( $host, $v6 ? DNS_AAAA : DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( is_array( $records ) ? $records : array() as $record ) {
			$value = $v6 ? ( $record['ipv6'] ?? '' ) : ( $record['ip'] ?? '' );
			if ( is_string( $value ) && '' !== $value ) {
				$out[] = $value;
			}
		}
		if ( ! $v6 && array() === $out ) {
			$single = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $single !== $host ) {
				$out[] = $single;
			}
		}
		return $out;
	}

	/**
	 * True when the address is among the candidates (IPv6 compared in packed form, so notation does not matter).
	 *
	 * @param string   $ip         Client IP.
	 * @param string[] $candidates Resolved addresses.
	 */
	public static function same_ip( string $ip, array $candidates ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( $candidates as $candidate ) {
			$other = @inet_pton( (string) $candidate ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $packed && $packed === $other ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match an IP against a list of exact IPs and IPv4 CIDR ranges.
	 *
	 * @param string   $ip   Client IP.
	 * @param string[] $list List of IPs / CIDR ranges.
	 */
	public static function ip_in_list( string $ip, array $list ): bool {
		foreach ( $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( false === strpos( $entry, '/' ) ) {
				if ( $ip === $entry ) {
					return true;
				}
				continue;
			}
			list( $subnet, $bits ) = explode( '/', $entry, 2 );
			$bits                  = (int) $bits;
			if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				$ip_long     = ip2long( $ip );
				$subnet_long = ip2long( $subnet );
				if ( false === $ip_long || false === $subnet_long ) {
					continue;
				}
				$mask = -1 << ( 32 - $bits );
				if ( ( $ip_long & $mask ) === ( $subnet_long & $mask ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
