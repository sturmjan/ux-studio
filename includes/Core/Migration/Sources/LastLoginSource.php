<?php
/**
 * Source: When Last Login, WP Last Login.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Migration\Sources;

use UxStudio\Core\Migration\AbstractMetaSource;
use UxStudio\Core\Migration\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Target module `user-last-login`: user meta `uxstudio_user_last_login` (Unix time of the last login).
 * When Last Login keeps it in `when_last_login`, WP Last Login in `wp-last-login` (0 = reset at logout, skipped).
 *
 * HARMLESS: showing a time in the users table changes nothing for visitors, so it is written directly
 * even when the module is on. A user who already has a value in UX Studio keeps it (it is never older data).
 */
final class LastLoginSource extends AbstractMetaSource {

	private const META_KEY = 'uxstudio_user_last_login';

	protected function meta_type(): string {
		return 'user';
	}

	protected function changes_behavior(): bool {
		return false;
	}

	protected function data_kind(): string {
		return 'last_login';
	}

	protected function data_label(): string {
		return __( 'Last login times', 'ux-studio' );
	}

	/**
	 * User meta key of the foreign plugin.
	 */
	private function foreign_key(): string {
		return 'when-last-login' === $this->slug ? 'when_last_login' : 'wp-last-login';
	}

	protected function build(): array {
		global $wpdb;
		$out  = array(
			'items'   => array(),
			'skipped' => array(),
		);
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id ASC", $this->foreign_key() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ts = (int) $row['meta_value'];
			if ( $ts <= 0 ) {
				$out['skipped']['reset'] = ( $out['skipped']['reset'] ?? 0 ) + 1;
				continue;
			}
			$id    = (int) $row['user_id'];
			$user  = function_exists( 'get_userdata' ) ? get_userdata( $id ) : null;
			$out['items'][] = array(
				'key'    => $this->slug . ':' . $id,
				'label'  => $user ? (string) $user->user_login : '#' . $id,
				'id'     => $id,
				'meta'   => array( self::META_KEY => (string) $ts ),
				'review' => false,
			);
		}
		return $out;
	}

	protected function map( bool $active ): Mapping {
		$m = new Mapping();
		if ( ! $this->collect()['items'] ) {
			$m->note( __( 'The plugin has no login times stored, there is nothing to transfer.', 'ux-studio' ) );
			return $m;
		}
		$m->lossy( __( 'Only the time of the last login is copied. The login counters and history of the plugin have no equivalent.', 'ux-studio' ) );
		return $m;
	}

	protected function data_plan( array $options ): array {
		return array(
			array(
				'key'   => 'last_login',
				'label' => __( 'Last login times', 'ux-studio' ),
				'count' => count( $this->collect()['items'] ),
			),
		);
	}
}
