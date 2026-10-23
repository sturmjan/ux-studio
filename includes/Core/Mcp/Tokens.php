<?php
/**
 * Access tokens of the MCP server: scopes, expiry, IP allowlist, revoking, rate limit.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Mcp;

use UxStudio\Core\ClientIp;

defined( 'ABSPATH' ) || exit;

/**
 * Token format `uxsmcp_<12 hex id>_<48 hex secret>`. Only SHA-256 of the secret is stored
 * (the secret has 192 bits of entropy, so a fast hash is enough) - the full token is shown
 * once, when it is created.
 */
final class Tokens {

	public const OPTION          = 'uxstudio_mcp_tokens';
	public const PREFIX          = 'uxsmcp_';
	public const MAX_DAYS        = 90;
	public const RATE_PER_MINUTE = 120;
	public const TOUCH_EVERY     = 60;

	/** @var callable|null Test seam: fn( int $user_id ): ?array{pwh:string,admin:bool}. */
	private static $user_resolver = null;

	/**
	 * Replace the user lookup (tests).
	 *
	 * @param callable|null $resolver Resolver.
	 */
	public static function set_user_resolver( ?callable $resolver ): void {
		self::$user_resolver = $resolver;
	}

	/**
	 * Create a token. The returned `token` is the only time the secret is available.
	 *
	 * @param array<string, mixed> $args label, user_id, scopes[], days, ips[].
	 * @param int|null             $now  Test seam.
	 * @return array{token:string,record:array<string, mixed>}
	 * @throws McpError On invalid input.
	 */
	public static function create( array $args, ?int $now = null ): array {
		$now    = $now ?? time();
		$label  = trim( (string) ( $args['label'] ?? '' ) );
		$scopes = Scopes::normalize( $args['scopes'] ?? array() );
		$days   = (int) ( $args['days'] ?? 0 );
		$ips    = self::clean_ips( (array) ( $args['ips'] ?? array() ) );

		if ( '' === $label || strlen( $label ) > 60 ) {
			throw new McpError( 'invalid_label', __( 'Give the token a name of up to 60 characters.', 'ux-studio' ) );
		}
		if ( ! $scopes ) {
			throw new McpError( 'invalid_scopes', __( 'Choose at least one scope.', 'ux-studio' ) );
		}
		if ( $days < 1 || $days > self::MAX_DAYS ) {
			throw new McpError( 'invalid_expiry', __( 'The token must expire in 1 to 90 days.', 'ux-studio' ) );
		}
		if ( $ips['invalid'] ) {
			throw new McpError( 'invalid_ip', __( 'Some IP addresses are not valid.', 'ux-studio' ), array( 'invalid' => $ips['invalid'] ) );
		}

		$id     = bin2hex( random_bytes( 6 ) );
		$secret = bin2hex( random_bytes( 24 ) );
		$user   = (int) ( $args['user_id'] ?? 0 );
		$info   = self::user( $user );

		$record = array(
			'id'         => $id,
			'label'      => $label,
			'user_id'    => $user,
			'scopes'     => $scopes,
			'created_at' => $now,
			'expires_at' => $now + $days * 86400,
			'ips'        => $ips['valid'],
			'last_used'  => 0,
			'last_ip'    => '',
			'pwh'        => $info['pwh'] ?? '',
			'hash'       => hash( 'sha256', $secret ),
		);

		$all        = self::stored();
		$all[ $id ] = $record;
		update_option( self::OPTION, $all, false );

		return array(
			'token'  => self::PREFIX . $id . '_' . $secret,
			'record' => self::public_record( $record ),
		);
	}

	/**
	 * Check a bearer token.
	 *
	 * @param string   $raw Raw bearer value.
	 * @param string   $ip  Client IP.
	 * @param int|null $now Test seam.
	 * @return array{ok:bool,code:string,token?:array<string, mixed>}
	 */
	public static function verify( string $raw, string $ip, ?int $now = null ): array {
		$now = $now ?? time();
		if ( 1 !== preg_match( '/^' . self::PREFIX . '([a-f0-9]{12})_([a-f0-9]{48})$/', $raw, $m ) ) {
			return array( 'ok' => false, 'code' => 'malformed' );
		}
		$record = self::stored()[ $m[1] ] ?? null;
		if ( ! $record || ! hash_equals( (string) $record['hash'], hash( 'sha256', $m[2] ) ) ) {
			return array( 'ok' => false, 'code' => 'unknown' );
		}
		if ( (int) $record['expires_at'] <= $now ) {
			return array( 'ok' => false, 'code' => 'expired' );
		}
		$user = self::user( (int) $record['user_id'] );
		if ( ! $user || empty( $user['admin'] ) ) {
			return array( 'ok' => false, 'code' => 'user_invalid' );
		}
		if ( '' !== (string) $record['pwh'] && ! hash_equals( (string) $record['pwh'], (string) $user['pwh'] ) ) {
			return array( 'ok' => false, 'code' => 'user_invalid' );
		}
		if ( ! empty( $record['ips'] ) && ( '' === $ip || ! ClientIp::in_ranges( $ip, (array) $record['ips'] ) ) ) {
			return array( 'ok' => false, 'code' => 'ip_denied' );
		}
		return array( 'ok' => true, 'code' => 'ok', 'token' => $record );
	}

	/**
	 * Remember when a token was used (at most once a minute, to spare the options table).
	 *
	 * @param string   $id  Token id.
	 * @param string   $ip  Client IP.
	 * @param int|null $now Test seam.
	 */
	public static function touch( string $id, string $ip, ?int $now = null ): void {
		$now = $now ?? time();
		$all = self::stored();
		if ( ! isset( $all[ $id ] ) || (int) $all[ $id ]['last_used'] > $now - self::TOUCH_EVERY ) {
			return;
		}
		$all[ $id ]['last_used'] = $now;
		$all[ $id ]['last_ip']   = $ip;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Count one call against the token's per-minute budget.
	 *
	 * @param string   $id  Token id.
	 * @param int|null $now Test seam.
	 * @return array{ok:bool,retry_after:int,limit:int}
	 */
	public static function hit( string $id, ?int $now = null ): array {
		$now    = $now ?? time();
		$limit  = (int) apply_filters( 'uxstudio_mcp_rate_limit', self::RATE_PER_MINUTE );
		$bucket = (int) floor( $now / 60 );
		$key    = 'uxstudio_mcprl_' . substr( md5( $id ), 0, 16 ) . '_' . $bucket;
		$count  = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return array( 'ok' => false, 'retry_after' => max( 1, ( $bucket + 1 ) * 60 - $now ), 'limit' => $limit );
		}
		set_transient( $key, $count + 1, 120 );
		return array( 'ok' => true, 'retry_after' => 0, 'limit' => $limit );
	}

	/**
	 * Revoke a token (it stops working immediately).
	 *
	 * @param string $id Token id.
	 */
	public static function revoke( string $id ): bool {
		$all = self::stored();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		return true;
	}

	/**
	 * One token without its hash.
	 *
	 * @param string $id Token id.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $id ): ?array {
		$record = self::stored()[ $id ] ?? null;
		return $record ? self::public_record( $record ) : null;
	}

	/**
	 * All tokens without the hash, newest first. Tokens expired for more than a week are dropped.
	 *
	 * @param int|null $now Test seam.
	 * @return array<int, array<string, mixed>>
	 */
	public static function all( ?int $now = null ): array {
		$now  = $now ?? time();
		$all  = self::stored();
		$keep = array_filter( $all, static fn( array $r ): bool => (int) $r['expires_at'] > $now - 7 * 86400 );
		if ( count( $keep ) !== count( $all ) ) {
			update_option( self::OPTION, $keep, false );
		}
		$out = array_map( static fn( array $r ): array => self::public_record( $r, $now ), array_values( $keep ) );
		usort( $out, static fn( array $a, array $b ): int => strcmp( (string) $b['created_at'], (string) $a['created_at'] ) );
		return $out;
	}

	/**
	 * Public shape of a record (UTC datetimes, no hash).
	 *
	 * @param array<string, mixed> $r   Stored record.
	 * @param int|null             $now Test seam.
	 * @return array<string, mixed>
	 */
	public static function public_record( array $r, ?int $now = null ): array {
		$now  = $now ?? time();
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', (int) $r['user_id'] ) : false;
		return array(
			'id'         => (string) $r['id'],
			'label'      => (string) $r['label'],
			'user_id'    => (int) $r['user_id'],
			'user'       => $user ? (string) $user->display_name : '',
			'scopes'     => array_values( (array) $r['scopes'] ),
			'created_at' => gmdate( 'Y-m-d H:i:s', (int) $r['created_at'] ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', (int) $r['expires_at'] ),
			'expired'    => (int) $r['expires_at'] <= $now,
			'last_used'  => (int) $r['last_used'] > 0 ? gmdate( 'Y-m-d H:i:s', (int) $r['last_used'] ) : null,
			'last_ip'    => (string) ( $r['last_ip'] ?? '' ),
			'ips'        => array_values( (array) ( $r['ips'] ?? array() ) ),
			'legacy'     => false,
		);
	}

	/**
	 * Split an IP list into valid entries (IP or CIDR) and invalid ones.
	 *
	 * @param array<int, mixed> $list Raw list.
	 * @return array{valid:string[],invalid:string[]}
	 */
	public static function clean_ips( array $list ): array {
		$valid   = array();
		$invalid = array();
		foreach ( $list as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$parts = explode( '/', $item );
			$ip    = $parts[0];
			$ok    = false !== filter_var( $ip, FILTER_VALIDATE_IP ) && count( $parts ) <= 2;
			if ( $ok && isset( $parts[1] ) ) {
				$max = false !== strpos( $ip, ':' ) ? 128 : 32;
				$ok  = ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
			}
			if ( $ok ) {
				$valid[] = $item;
			} else {
				$invalid[] = $item;
			}
		}
		return array( 'valid' => array_values( array_unique( $valid ) ), 'invalid' => $invalid );
	}

	/**
	 * Stored records keyed by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function stored(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * User facts needed to accept a token.
	 *
	 * @param int $user_id User id.
	 * @return array{pwh:string,admin:bool}|null
	 */
	private static function user( int $user_id ): ?array {
		if ( self::$user_resolver ) {
			return ( self::$user_resolver )( $user_id );
		}
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user ) {
			return null;
		}
		return array(
			'pwh'   => substr( hash_hmac( 'sha256', 'mcp-pwh|' . $user->ID . '|' . $user->user_pass, wp_salt( 'secure_auth' ) ), 0, 32 ),
			'admin' => user_can( $user, 'manage_options' ),
		);
	}
}
