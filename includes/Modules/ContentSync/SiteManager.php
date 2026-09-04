<?php
/**
 * Registry of connected node sites (hub side).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD over the sites table. Each site's shared HMAC key is a secret: it is
 * NEVER stored in the table nor returned to the client. It lives in an
 * encrypted option (Security::store_secret) keyed by the site id, and only a
 * boolean has_api_key flag is ever exposed.
 */
final class SiteManager {

	/** Per-site secret option prefix. */
	private const SECRET_PREFIX = 'uxstudio_secret_content_sync_site_';

	/** Sites table (without prefix). */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'uxstudio_content_sync_sites';
	}

	/**
	 * All sites, newest first, with a has_api_key flag (never the key itself).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, created_at, name, url, status, acf_active, last_ping, last_sync FROM ' . $this->table() . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( array( $this, 'shape' ), $rows );
	}

	/**
	 * One site row (internal shape, no secret) or null.
	 *
	 * @param int $id Site id.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, created_at, name, url, status, acf_active, last_ping, last_sync FROM ' . $this->table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return $row ? $this->shape( $row ) : null;
	}

	/**
	 * Register a new node.
	 *
	 * @param string $name    Label.
	 * @param string $url     Base URL.
	 * @param string $api_key Shared HMAC key (stored encrypted, out of the table).
	 * @return array<string, mixed> The created row (no secret).
	 */
	public function create( string $name, string $url, string $api_key ): array {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'created_at' => current_time( 'mysql' ),
				'name'       => mb_substr( $name, 0, 255 ),
				'url'        => esc_url_raw( $url ),
				'status'     => 'unchecked',
				'acf_active' => 0,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);
		$id = (int) $wpdb->insert_id;

		if ( '' !== $api_key ) {
			Security::store_secret( self::SECRET_PREFIX . $id, $api_key );
		}

		return $this->get( $id ) ?? array();
	}

	/**
	 * Update mutable, non-secret site columns.
	 *
	 * @param int   $id      Site id.
	 * @param array $changes Allowed: name, url, status, acf_active, last_ping, last_sync.
	 */
	public function update( int $id, array $changes ): bool {
		global $wpdb;
		$data    = array();
		$formats = array();
		if ( isset( $changes['name'] ) ) {
			$data['name']  = mb_substr( (string) $changes['name'], 0, 255 );
			$formats[]     = '%s';
		}
		if ( isset( $changes['url'] ) ) {
			$data['url'] = esc_url_raw( (string) $changes['url'] );
			$formats[]   = '%s';
		}
		if ( isset( $changes['status'] ) ) {
			$data['status'] = sanitize_key( (string) $changes['status'] );
			$formats[]      = '%s';
		}
		if ( isset( $changes['acf_active'] ) ) {
			$data['acf_active'] = (int) (bool) $changes['acf_active'];
			$formats[]          = '%d';
		}
		if ( array_key_exists( 'last_ping', $changes ) ) {
			$data['last_ping'] = $changes['last_ping'] ? (string) $changes['last_ping'] : null;
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'last_sync', $changes ) ) {
			$data['last_sync'] = $changes['last_sync'] ? (string) $changes['last_sync'] : null;
			$formats[]         = '%s';
		}
		if ( empty( $data ) ) {
			return false;
		}
		return (bool) $wpdb->update( $this->table(), $data, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete a site and its secret.
	 *
	 * @param int $id Site id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		delete_option( self::SECRET_PREFIX . $id );
		return (bool) $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a site has a stored shared key.
	 *
	 * @param int $id Site id.
	 */
	public function has_secret( int $id ): bool {
		return '' !== Security::get_secret( self::SECRET_PREFIX . $id );
	}

	/**
	 * Build a signed client for a site, or null when the site/key is missing.
	 *
	 * @param int $id Site id.
	 */
	public function client( int $id ): ?SyncClient {
		$site = $this->get( $id );
		if ( ! $site ) {
			return null;
		}
		$secret = Security::get_secret( self::SECRET_PREFIX . $id );
		if ( '' === $secret ) {
			return null;
		}
		return new SyncClient( (string) $site['url'], $secret );
	}

	/**
	 * Ping a site and persist the resulting status.
	 *
	 * @param int $id Site id.
	 * @return array<string, mixed>
	 */
	public function test_connection( int $id ): array {
		$client = $this->client( $id );
		if ( ! $client ) {
			return array(
				'success' => false,
				'error'   => __( 'Site not found or missing API key.', 'ux-studio' ),
			);
		}
		$result = $client->ping();
		$this->update(
			$id,
			array(
				'status'     => $result['success'] ? 'connected' : 'error',
				'acf_active' => ( $result['success'] && ! empty( $result['data']['acf_active'] ) ) ? 1 : 0,
				'last_ping'  => current_time( 'mysql' ),
			)
		);
		return $result;
	}

	/**
	 * Add a has_api_key flag and cast ids/flags on a raw row.
	 *
	 * @param array $row Raw DB row.
	 * @return array<string, mixed>
	 */
	private function shape( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['acf_active']  = (int) ( $row['acf_active'] ?? 0 );
		$row['has_api_key'] = $this->has_secret( (int) $row['id'] );
		return $row;
	}
}
