<?php
/**
 * Single-use SSO token store (node side).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

defined( 'ABSPATH' ) || exit;

/**
 * Tokens are stored only as a SHA-256 hash. The plaintext token is returned
 * exactly once (from issue()) to the hub, which hands it to the operator's
 * browser; it is never persisted. consume() flips consumed_at atomically so a
 * token can be redeemed at most once.
 */
final class SsoTokenStore {

	/** Tokens table (without prefix). */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_central_sso_tokens';
	}

	/**
	 * Issue a new token. Returns the plaintext token (once) + expiry.
	 *
	 * @param array{
	 *   operator_email:string, operator_id:int, central_role:string,
	 *   target_wp_role:string, target_wp_user_id:?int, action:string,
	 *   return_to:string, ua_hash:string, ip:string, ttl_seconds:int
	 * } $args Token attributes.
	 * @return array{token:string, expires_at:string}
	 */
	public static function issue( array $args ): array {
		global $wpdb;
		$plain   = bin2hex( random_bytes( 32 ) );
		$hash    = hash( 'sha256', $plain );
		$ttl     = max( 15, min( 300, (int) $args['ttl_seconds'] ) );
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'          => $now,
				'token_hash'          => $hash,
				'operator_email'      => mb_substr( (string) $args['operator_email'], 0, 190 ),
				'operator_id_central' => (int) $args['operator_id'],
				'central_role'        => mb_substr( (string) $args['central_role'], 0, 40 ),
				'target_wp_role'      => mb_substr( (string) $args['target_wp_role'], 0, 40 ),
				'user_id'             => null !== $args['target_wp_user_id'] ? (int) $args['target_wp_user_id'] : 0,
				'action'              => mb_substr( (string) $args['action'], 0, 40 ),
				'return_to'           => mb_substr( (string) $args['return_to'], 0, 500 ),
				'ua_hash'             => '' !== $args['ua_hash'] ? mb_substr( (string) $args['ua_hash'], 0, 64 ) : null,
				'ip'                  => '' !== $args['ip'] ? mb_substr( (string) $args['ip'], 0, 45 ) : null,
				'issued_at'           => $now,
				'expires_at'          => $expires,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return array(
			'token'      => $plain,
			'expires_at' => $expires,
		);
	}

	/**
	 * Find a token row by plaintext token (hashes internally).
	 *
	 * @param string $token Plaintext token.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_token( string $token ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', $token );
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1', $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? $row : null;
	}

	/**
	 * Atomically mark a token consumed. Returns true only if it flipped exactly
	 * one still-valid row (prevents redemption races).
	 *
	 * @param int $token_id Token row id.
	 */
	public static function consume( int $token_id ): bool {
		global $wpdb;
		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND revoked_at IS NULL',
				current_time( 'mysql', true ),
				$token_id
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Increment the failure counter for a token.
	 *
	 * @param int $token_id Token row id.
	 */
	public static function record_failure( int $token_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET fail_count = fail_count + 1 WHERE id = %d', $token_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Revoke every not-yet-used token. Returns the number revoked.
	 */
	public static function revoke_all(): int {
		global $wpdb;
		$affected = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE consumed_at IS NULL AND revoked_at IS NULL',
				current_time( 'mysql', true )
			)
		);
		return max( 0, (int) $affected );
	}

	/**
	 * Sum of failed redemptions from an IP in the last N minutes.
	 *
	 * @param string $ip      Client IP.
	 * @param int    $minutes Window in minutes.
	 */
	public static function failed_for_ip( string $ip, int $minutes = 60 ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $minutes ) * MINUTE_IN_SECONDS );
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(fail_count),0) FROM ' . self::table() . ' WHERE ip = %s AND issued_at >= %s', $ip, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $count;
	}
}
