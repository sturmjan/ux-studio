<?php
/**
 * IP ban data/logic layer (IPv4 + IPv6, single IP / CIDR range / country).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ported from the legacy IpBanStore. Table names/columns are fixed by the
 * migration map (see Migrator.php) - do not rename.
 *
 * `uxstudio_ip_bans` holds logical bans (1 row = 1 IP / CIDR / country).
 * `uxstudio_ip_ban_ranges` holds the expanded binary start/end ranges the
 * firewall hot path compares against (a country ban expands to many ranges).
 *
 * The `origin`/`central_id` columns are kept for schema compatibility with
 * the legacy central-sync feature, but this port only ever writes `local`
 * bans - no central push/pull is implemented here.
 */
final class IpBanStore {

	private const ACTIVE_OPTION = 'uxstudio_ip_bans_active';

	/** @var string */
	private $bans;

	/** @var string */
	private $ranges;

	public function __construct() {
		global $wpdb;
		$this->bans   = $wpdb->prefix . 'uxstudio_ip_bans';
		$this->ranges = $wpdb->prefix . 'uxstudio_ip_ban_ranges';
	}

	/* ═══════════════════════════════════════════════════
	   CRUD
	   ═══════════════════════════════════════════════════ */

	/**
	 * Create/update a ban and (re)expand its ranges.
	 *
	 * @param array $data type, value, label, note, expires_at, created_by, id, ranges (country only).
	 * @return int|WP_Error Ban id or error.
	 */
	public function save_ban( array $data ) {
		global $wpdb;

		$type  = in_array( $data['type'] ?? '', array( 'single', 'cidr', 'country' ), true ) ? $data['type'] : 'single';
		$value = trim( (string) ( $data['value'] ?? '' ) );

		if ( '' === $value ) {
			return new WP_Error( 'uxstudio_empty_value', __( 'Ban value is empty.', 'ux-studio' ) );
		}

		$computed = $this->compute_ranges( $type, $value, $data['ranges'] ?? array() );
		if ( is_wp_error( $computed ) ) {
			return $computed;
		}
		if ( empty( $computed ) ) {
			return new WP_Error( 'uxstudio_no_ranges', __( 'No valid IP range could be derived from this ban.', 'ux-studio' ) );
		}

		// Self-ban guard: never let an admin lock themselves out.
		$my_ip = $this->get_client_ip();
		if ( '' !== $my_ip && $this->ip_matches_ranges( $my_ip, $computed ) ) {
			return new WP_Error(
				'uxstudio_self_ban',
				sprintf(
					/* translators: %s: current IP address */
					__( 'This ban includes your current IP address (%s) - blocked to avoid locking you out. Add it to the allowlist if needed.', 'ux-studio' ),
					$my_ip
				)
			);
		}

		$row = array(
			'type'       => $type,
			'value'      => $value,
			'label'      => isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : null,
			'note'       => isset( $data['note'] ) ? sanitize_textarea_field( (string) $data['note'] ) : null,
			'origin'     => 'local',
			'is_active'  => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
			'expires_at' => $this->normalize_date( $data['expires_at'] ?? null ),
			'created_by' => isset( $data['created_by'] ) ? (int) $data['created_by'] : get_current_user_id(),
		);

		$explicit_id = (int) ( $data['id'] ?? 0 );
		if ( $explicit_id ) {
			$existing_id = $explicit_id;
		} else {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$this->bans} WHERE origin = 'local' AND value = %s LIMIT 1", $value )
			);
		}

		if ( $existing_id ) {
			$wpdb->update( $this->bans, $row, array( 'id' => $existing_id ) );
			$ban_id = $existing_id;
		} else {
			$row['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $this->bans, $row );
			$ban_id = (int) $wpdb->insert_id;
		}

		if ( ! $ban_id ) {
			return new WP_Error( 'uxstudio_db_error', __( 'Could not save the ban.', 'ux-studio' ) );
		}

		$this->replace_ranges( $ban_id, $computed );
		$this->refresh_active_flag();

		return $ban_id;
	}

	/**
	 * @param int $id Ban id.
	 */
	public function get_ban( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->bans} WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Paginated/filtered list.
	 *
	 * @param array $args type, search, page, per_page, active_only.
	 */
	public function get_bans( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}
		if ( ! empty( $args['active_only'] ) ) {
			$where[] = 'is_active = 1';
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(value LIKE %s OR label LIKE %s OR note LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total_sql = "SELECT COUNT(*) FROM {$this->bans} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );

		$list_sql    = "SELECT * FROM {$this->bans} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, array( $per_page, $offset ) );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		return array(
			'items'       => $items ?: array(),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	public function delete_ban( int $id ): bool {
		global $wpdb;
		$wpdb->delete( $this->ranges, array( 'ban_id' => $id ) );
		$deleted = $wpdb->delete( $this->bans, array( 'id' => $id ) );
		$this->refresh_active_flag();
		return (bool) $deleted;
	}

	public function set_active( int $id, bool $active ): void {
		global $wpdb;
		$wpdb->update( $this->bans, array( 'is_active' => $active ? 1 : 0 ), array( 'id' => $id ) );
		$this->refresh_active_flag();
	}

	/* ═══════════════════════════════════════════════════
	   HOT PATH - firewall matching
	   ═══════════════════════════════════════════════════ */

	/**
	 * Fast flag avoiding a DB query when there are no active bans at all.
	 */
	public function has_active_bans(): bool {
		$flag = get_option( self::ACTIVE_OPTION, null );
		if ( null === $flag ) {
			return $this->refresh_active_flag();
		}
		return (bool) $flag;
	}

	public function refresh_active_flag(): bool {
		global $wpdb;
		$exists = (int) $wpdb->get_var(
			"SELECT EXISTS(
				SELECT 1 FROM {$this->bans}
				WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
			)"
		);
		update_option( self::ACTIVE_OPTION, $exists ? 1 : 0, true );
		return (bool) $exists;
	}

	/**
	 * Matching ban for a given IP, or null.
	 */
	public function matched_ban( string $ip ): ?array {
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return null;
		}
		$version = 4 === strlen( $bin ) ? 4 : 6;
		$hex     = bin2hex( $bin );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT b.* FROM {$this->ranges} r
				 INNER JOIN {$this->bans} b ON b.id = r.ban_id
				 WHERE r.ip_version = %d
				   AND r.start_ip <= UNHEX(%s)
				   AND r.end_ip   >= UNHEX(%s)
				   AND b.is_active = 1
				   AND (b.expires_at IS NULL OR b.expires_at > %s)
				 LIMIT 1",
				$version,
				$hex,
				$hex,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Record a hit (throttled to 1/60s per ban+ip so an attack doesn't hammer the DB).
	 */
	public function record_hit( int $ban_id, string $ip ): void {
		$key = 'uxstudio_ipban_hit_' . md5( $ban_id . '|' . $ip );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, MINUTE_IN_SECONDS );

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->bans} SET hit_count = hit_count + 1, last_hit_at = %s WHERE id = %d",
				current_time( 'mysql' ),
				$ban_id
			)
		);
	}

	/* ═══════════════════════════════════════════════════
	   Allowlist + client IP
	   ═══════════════════════════════════════════════════ */

	/**
	 * @param string $ip           IP to check.
	 * @param string $allowlist_raw Raw allowlist textarea value (one entry per line).
	 */
	public function is_allowlisted( string $ip, string $allowlist_raw ): bool {
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		if ( in_array( $ip, array( '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$entries = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $allowlist_raw ) ) );
		foreach ( $entries as $entry ) {
			if ( '' === $entry ) {
				continue;
			}
			if ( false !== strpos( $entry, '/' ) ) {
				$range = $this->cidr_to_range( $entry );
				if ( $range && $this->ip_in_binary_range( $bin, $range[0], $range[1] ) ) {
					return true;
				}
			} elseif ( $entry === $ip ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Client IP detection, trusting forwarded headers only in the configured proxy mode.
	 *
	 * @param string $proxy_mode none|cloudflare|xff.
	 */
	public function get_client_ip( string $proxy_mode = 'none' ): string {
		$candidate = '';
		if ( 'cloudflare' === $proxy_mode && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( 'xff' === $proxy_mode && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$candidate = trim( $parts[0] );
		}

		if ( '' === $candidate ) {
			$candidate = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		}

		return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
	}

	/* ═══════════════════════════════════════════════════
	   Range expansion (single / cidr / country)
	   ═══════════════════════════════════════════════════ */

	/**
	 * @return array|WP_Error Array of [startBin, endBin, version] triples, or error.
	 */
	private function compute_ranges( string $type, string $value, array $country_ranges ) {
		if ( 'single' === $type ) {
			$bin = @inet_pton( $value );
			if ( false === $bin ) {
				return new WP_Error(
					'uxstudio_invalid_ip',
					/* translators: %s: invalid IP address */
					sprintf( __( 'Invalid IP address: %s', 'ux-studio' ), $value )
				);
			}
			return array( array( $bin, $bin, 4 === strlen( $bin ) ? 4 : 6 ) );
		}

		if ( 'cidr' === $type ) {
			$range = $this->cidr_to_range( $value );
			if ( null === $range ) {
				return new WP_Error(
					'uxstudio_invalid_cidr',
					/* translators: %s: invalid CIDR range */
					sprintf( __( 'Invalid CIDR range: %s', 'ux-studio' ), $value )
				);
			}
			return array( $range );
		}

		// country: ranges arrive pre-computed as a list of CIDR strings.
		$out = array();
		foreach ( $country_ranges as $cidr ) {
			$range = $this->cidr_to_range( trim( (string) $cidr ) );
			if ( null !== $range ) {
				$out[] = $range;
			}
		}
		return $out;
	}

	/**
	 * CIDR (v4 or v6) -> [startBin, endBin, version], null on invalid input.
	 */
	public function cidr_to_range( string $cidr ): ?array {
		if ( false === strpos( $cidr, '/' ) ) {
			return null;
		}
		list( $net, $bits_raw ) = explode( '/', $cidr, 2 );
		$bin = @inet_pton( trim( $net ) );
		if ( false === $bin ) {
			return null;
		}

		$len      = strlen( $bin );
		$max_bits = $len * 8;
		$bits     = (int) $bits_raw;
		if ( $bits < 0 || $bits > $max_bits || ! ctype_digit( trim( $bits_raw ) ) ) {
			return null;
		}

		$bytes       = array_values( unpack( 'C*', $bin ) );
		$start_bytes = $bytes;
		$end_bytes   = $bytes;
		$full_bytes  = intdiv( $bits, 8 );
		$rem_bits    = $bits % 8;

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $i < $full_bytes ) {
				continue;
			}
			if ( $i === $full_bytes && $rem_bits > 0 ) {
				$mask             = ( 0xFF << ( 8 - $rem_bits ) ) & 0xFF;
				$start_bytes[ $i ] = $start_bytes[ $i ] & $mask;
				$end_bytes[ $i ]   = $start_bytes[ $i ] | ( ~$mask & 0xFF );
			} else {
				$start_bytes[ $i ] = 0x00;
				$end_bytes[ $i ]   = 0xFF;
			}
		}

		return array(
			pack( 'C*', ...$start_bytes ),
			pack( 'C*', ...$end_bytes ),
			4 === $len ? 4 : 6,
		);
	}

	/**
	 * @param array $ranges [ [startBin, endBin, version], ... ]
	 */
	private function replace_ranges( int $ban_id, array $ranges ): void {
		global $wpdb;
		$wpdb->delete( $this->ranges, array( 'ban_id' => $ban_id ) );

		foreach ( $ranges as $r ) {
			list( $start_bin, $end_bin, $version ) = $r;
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$this->ranges} (ban_id, ip_version, start_ip, end_ip) VALUES (%d, %d, UNHEX(%s), UNHEX(%s))",
					$ban_id,
					$version,
					bin2hex( $start_bin ),
					bin2hex( $end_bin )
				)
			);
		}
	}

	private function ip_matches_ranges( string $ip, array $ranges ): bool {
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		foreach ( $ranges as $r ) {
			if ( $this->ip_in_binary_range( $bin, $r[0], $r[1] ) ) {
				return true;
			}
		}
		return false;
	}

	private function ip_in_binary_range( string $ip_bin, string $start_bin, string $end_bin ): bool {
		if ( strlen( $ip_bin ) !== strlen( $start_bin ) ) {
			return false;
		}
		return strcmp( $ip_bin, $start_bin ) >= 0 && strcmp( $ip_bin, $end_bin ) <= 0;
	}

	private function normalize_date( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}
		$ts = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
		if ( ! $ts ) {
			return null;
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
