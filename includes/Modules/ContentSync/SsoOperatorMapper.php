<?php
/**
 * Maps a central-app operator onto a local WP user (node side).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Resolution order (settings read from the content-sync module, prefix sso_):
 *   1) cache (operator_id_central -> wp_user_id)
 *   2) existing WP user by email
 *   3) auto-provision (only when enabled)
 *
 * Security hardening vs the legacy module: auto-provisioning NEVER creates an
 * administrator. The allowed provisioning roles are editor/author/contributor
 * only; a request asking for 'administrator' can bind to an EXISTING admin user
 * but will never implicitly create one. This enforces "never elevate to admin
 * implicitly".
 */
final class SsoOperatorMapper {

	private const CACHE_OPTION = 'uxstudio_content_sync_sso_operators';

	private const PROVISIONABLE = array( 'editor', 'author', 'contributor' );

	public const REASON_DISABLED  = 'mapping_disabled';
	public const REASON_NOT_FOUND = 'user_not_found';
	public const REASON_NO_EMAIL  = 'no_email';
	public const REASON_PROVISION = 'provision_failed';

	/**
	 * Resolve an operator to a WP user id.
	 *
	 * @param string $operator_email      Operator email.
	 * @param int    $operator_id_central Central operator id.
	 * @param string $target_wp_role      Requested WP role.
	 * @return array{user_id:?int, source:string}
	 */
	public static function resolve( string $operator_email, int $operator_id_central, string $target_wp_role ): array {
		$strategy = (string) Module::setting( 'sso_mapping_strategy', 'auto' );

		if ( 'disabled' === $strategy ) {
			return array(
				'user_id' => null,
				'source'  => self::REASON_DISABLED,
			);
		}

		$cached = self::cache_lookup( $operator_id_central );
		if ( null !== $cached ) {
			if ( get_user_by( 'id', $cached ) instanceof WP_User ) {
				return array(
					'user_id' => $cached,
					'source'  => 'cache',
				);
			}
			self::cache_remove( $operator_id_central );
		}

		if ( 'strict' === $strategy ) {
			return array(
				'user_id' => null,
				'source'  => self::REASON_NOT_FOUND,
			);
		}

		if ( '' === $operator_email ) {
			return array(
				'user_id' => null,
				'source'  => self::REASON_NO_EMAIL,
			);
		}

		$user = get_user_by( 'email', $operator_email );
		if ( $user instanceof WP_User ) {
			self::cache_add( $operator_id_central, (int) $user->ID );
			return array(
				'user_id' => (int) $user->ID,
				'source'  => 'email',
			);
		}

		if ( ! (bool) Module::setting( 'sso_allow_provision', true ) ) {
			return array(
				'user_id' => null,
				'source'  => self::REASON_NOT_FOUND,
			);
		}

		$provisioned = self::provision( $operator_email, $target_wp_role );
		if ( null !== $provisioned ) {
			self::cache_add( $operator_id_central, $provisioned );
			return array(
				'user_id' => $provisioned,
				'source'  => 'provisioned',
			);
		}

		return array(
			'user_id' => null,
			'source'  => self::REASON_PROVISION,
		);
	}

	/**
	 * Create a WP user for an operator, clamped to a non-admin role.
	 *
	 * @param string $email Operator email.
	 * @param string $role  Requested role.
	 * @return int|null New user id or null on failure.
	 */
	private static function provision( string $email, string $role ): ?int {
		// Never implicitly create an administrator, whatever the hub requested.
		if ( ! in_array( $role, self::PROVISIONABLE, true ) ) {
			$role = (string) Module::setting( 'sso_provision_wp_role', 'editor' );
		}
		if ( ! in_array( $role, self::PROVISIONABLE, true ) ) {
			$role = 'editor';
		}

		$local     = strstr( $email, '@', true );
		$local     = false !== $local ? $local : $email;
		$local     = (string) preg_replace( '/[^a-z0-9_-]+/i', '_', $local );
		$username  = mb_substr( 'central_' . $local, 0, 60 );
		$candidate = $username;
		$i         = 1;
		while ( username_exists( $candidate ) ) {
			$candidate = $username . '_' . $i;
			++$i;
			if ( $i > 100 ) {
				return null;
			}
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $candidate,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $role,
				'display_name' => $email,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		update_user_meta( (int) $user_id, 'uxstudio_cs_sso_provisioned_at', current_time( 'mysql', true ) );
		return (int) $user_id;
	}

	/**
	 * Refresh the operator->user cache from a hub-supplied list.
	 *
	 * @param array<int, array{operator_id?:int, email?:string, wp_user_id?:int}> $operators Operators.
	 * @return int Number of cache entries changed.
	 */
	public static function apply_sync( array $operators ): int {
		$cache   = self::cache();
		$changed = 0;
		foreach ( $operators as $op ) {
			$id    = (int) ( $op['operator_id'] ?? 0 );
			$email = (string) ( $op['email'] ?? '' );
			if ( $id <= 0 || '' === $email ) {
				continue;
			}
			$wp_id = (int) ( $op['wp_user_id'] ?? 0 );
			if ( $wp_id <= 0 ) {
				$user = get_user_by( 'email', $email );
				if ( $user instanceof WP_User ) {
					$wp_id = (int) $user->ID;
				}
			}
			if ( $wp_id > 0 && ( $cache[ $id ] ?? null ) !== $wp_id ) {
				$cache[ $id ] = $wp_id;
				++$changed;
			}
		}
		if ( $changed > 0 ) {
			update_option( self::CACHE_OPTION, $cache, false );
		}
		return $changed;
	}

	/**
	 * Cache lookup.
	 *
	 * @param int $operator_id_central Central operator id.
	 */
	private static function cache_lookup( int $operator_id_central ): ?int {
		$cache = self::cache();
		return isset( $cache[ $operator_id_central ] ) ? (int) $cache[ $operator_id_central ] : null;
	}

	/**
	 * Add a cache entry.
	 *
	 * @param int $operator_id_central Central operator id.
	 * @param int $wp_user_id          WP user id.
	 */
	private static function cache_add( int $operator_id_central, int $wp_user_id ): void {
		$cache                         = self::cache();
		$cache[ $operator_id_central ] = $wp_user_id;
		update_option( self::CACHE_OPTION, $cache, false );
	}

	/**
	 * Remove a cache entry.
	 *
	 * @param int $operator_id_central Central operator id.
	 */
	private static function cache_remove( int $operator_id_central ): void {
		$cache = self::cache();
		if ( isset( $cache[ $operator_id_central ] ) ) {
			unset( $cache[ $operator_id_central ] );
			update_option( self::CACHE_OPTION, $cache, false );
		}
	}

	/**
	 * Read the cache option.
	 *
	 * @return array<int, int>
	 */
	private static function cache(): array {
		$raw = get_option( self::CACHE_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}
}
