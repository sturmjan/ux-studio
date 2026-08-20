<?php
/**
 * IP ban management (REST-facing business logic on top of IpBanStore).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\ActivityLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin orchestration layer used by RestController: validates input, resolves
 * country -> CIDR ranges via CountryBlocklist, and writes an ActivityLog
 * entry for every create/delete so ban changes are auditable.
 */
final class IpBanManager {

	private IpBanStore $store;

	public function __construct( IpBanStore $store ) {
		$this->store = $store;
	}

	public function store(): IpBanStore {
		return $this->store;
	}

	/**
	 * @param array $args type, search, page, per_page, active_only.
	 */
	public function list_bans( array $args = array() ): array {
		return $this->store->get_bans( $args );
	}

	/**
	 * Create or update a local ban.
	 *
	 * @param array $data id?, type, value|country, note, expires_at.
	 * @return int|WP_Error Ban id or error.
	 */
	public function save_ban( array $data ) {
		$type = in_array( $data['type'] ?? '', array( 'single', 'cidr', 'country' ), true ) ? $data['type'] : 'single';

		$payload = array(
			'id'         => ! empty( $data['id'] ) ? (int) $data['id'] : null,
			'type'       => $type,
			'note'       => (string) ( $data['note'] ?? '' ),
			'expires_at' => $data['expires_at'] ?? null,
			'created_by' => get_current_user_id(),
		);

		if ( 'country' === $type ) {
			$code       = strtoupper( sanitize_text_field( (string) ( $data['value'] ?? $data['country'] ?? '' ) ) );
			$blocklist  = new CountryBlocklist();
			$ranges     = $blocklist->fetch_ranges( $code );
			if ( is_wp_error( $ranges ) ) {
				return $ranges;
			}
			$payload['value']  = $code;
			$payload['label']  = $blocklist->get_country_name( $code );
			$payload['ranges'] = $ranges;
		} else {
			$payload['value'] = sanitize_text_field( (string) ( $data['value'] ?? '' ) );
			$payload['label'] = isset( $data['label'] ) ? sanitize_text_field( (string) $data['label'] ) : null;
		}

		$result = $this->store->save_ban( $payload );

		if ( ! is_wp_error( $result ) ) {
			ActivityLog::log(
				'security-optimization',
				empty( $data['id'] ) ? 'ip_ban_created' : 'ip_ban_updated',
				'ip_ban',
				(int) $result,
				array(
					'type'  => $type,
					'value' => $payload['value'],
				)
			);
		}

		return $result;
	}

	public function delete_ban( int $id ): bool {
		$ban = $this->store->get_ban( $id );
		if ( ! $ban || 'local' !== $ban['origin'] ) {
			return false;
		}

		$deleted = $this->store->delete_ban( $id );
		if ( $deleted ) {
			ActivityLog::log( 'security-optimization', 'ip_ban_deleted', 'ip_ban', $id, array( 'value' => $ban['value'] ) );
		}
		return $deleted;
	}

	/**
	 * @return array|WP_Error Updated ban row, or error if not found.
	 */
	public function toggle_ban( int $id ) {
		$ban = $this->store->get_ban( $id );
		if ( ! $ban || 'local' !== $ban['origin'] ) {
			return new WP_Error( 'uxstudio_not_found', __( 'Ban not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$new_active = empty( $ban['is_active'] );
		$this->store->set_active( $id, $new_active );
		ActivityLog::log(
			'security-optimization',
			$new_active ? 'ip_ban_enabled' : 'ip_ban_disabled',
			'ip_ban',
			$id,
			array( 'value' => $ban['value'] )
		);

		return $this->store->get_ban( $id );
	}
}
