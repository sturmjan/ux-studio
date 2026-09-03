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

	/**
	 * @param array $config whitelist_ua/whitelist_ip/blacklist_ua/verify_rdns/categories.
	 */
	public function __construct( array $config = array() ) {
		$this->categories   = $config['categories'] ?? Signatures::categories();
		$this->whitelist_ua = (array) ( $config['whitelist_ua'] ?? array() );
		$this->whitelist_ip = (array) ( $config['whitelist_ip'] ?? array() );
		$this->blacklist_ua = (array) ( $config['blacklist_ua'] ?? array() );
		$this->verify_rdns  = (bool) ( $config['verify_rdns'] ?? false );
	}

	/**
	 * Detect a bot from a UA + IP. Returns null for non-bots, otherwise
	 * [ category, name, verified ].
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
	 * Reverse-DNS verification (slow; cached 24h). Only the major search engines
	 * are verifiable - unknown bots pass through as verified.
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
			return '1' === $cached;
		}

		$hostname = @gethostbyaddr( $ip );
		$ok       = false;
		if ( $hostname && $hostname !== $ip ) {
			foreach ( $verifiable[ $bot_name ] as $suffix ) {
				if ( substr( $hostname, -strlen( $suffix ) ) === $suffix ) {
					$forward = @gethostbyname( $hostname );
					if ( $forward === $ip ) {
						$ok = true;
						break;
					}
				}
			}
		}

		set_transient( $cache_key, $ok ? '1' : '0', DAY_IN_SECONDS );
		return $ok;
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
