<?php
/**
 * Node-side package distribution (install plugins/themes pushed by the hub).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\ContentSync;

use UxStudio\Core\ActivityLog;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Ported 1:1 from the legacy NodeController `packages/*` endpoints used by the
 * Central App to distribute plugins and themes (including this plugin).
 *
 * HIGH RISK: `install` runs WP_Upgrader with a package fetched from a
 * hub-supplied URL, and `configure_fs_method` edits wp-config.php. Both are
 * reachable ONLY through the HMAC permission callback (shared node API key,
 * timestamp window, single-use nonce) registered in NodeController. Guards kept
 * from legacy: mandatory SHA-256 match of the downloaded ZIP, FS_METHOD=direct
 * requirement, backup + atomic write of wp-config.php, idempotency. Guards
 * added: URL must pass wp_http_validate_url() (download_url() additionally
 * rejects private/loopback hosts via the "safe" HTTP API), slug charset check,
 * and an ActivityLog entry for each state change.
 */
final class NodePackages {

	/**
	 * Capability probe used by the hub before an install.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ping( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'             => true,
				'module_version' => '1.0',
				'fs_method'      => defined( 'FS_METHOD' ) ? FS_METHOD : null,
				'wp_version'     => get_bloginfo( 'version' ),
			),
			200
		);
	}

	/**
	 * Installed version / active flag of a plugin or theme by slug.
	 *
	 * @param WP_REST_Request $request Request (`slug`, `type` = plugin|theme).
	 */
	public function status( WP_REST_Request $request ): WP_REST_Response {
		$slug = sanitize_text_field( (string) $request->get_param( 'slug' ) );
		$type = sanitize_text_field( (string) $request->get_param( 'type' ) );

		if ( ! $this->valid_slug( $slug ) || ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Missing slug or invalid type.', 'ux-studio' ) ), 400 );
		}

		if ( 'plugin' === $type ) {
			$info = $this->find_plugin_by_slug( $slug );
			if ( ! $info ) {
				return new WP_REST_Response(
					array(
						'installed' => false,
						'version'   => null,
						'active'    => false,
					),
					200
				);
			}
			return new WP_REST_Response(
				array(
					'installed' => true,
					'version'   => (string) ( $info['data']['Version'] ?? '' ),
					'active'    => is_plugin_active( $info['file'] ) || ( is_multisite() && is_plugin_active_for_network( $info['file'] ) ),
				),
				200
			);
		}

		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new WP_REST_Response(
				array(
					'installed' => false,
					'version'   => null,
					'active'    => false,
				),
				200
			);
		}
		$current = wp_get_theme();
		return new WP_REST_Response(
			array(
				'installed' => true,
				'version'   => (string) $theme->get( 'Version' ),
				'active'    => $current->get_stylesheet() === $slug || $current->get_template() === $slug,
			),
			200
		);
	}

	/**
	 * Download, verify (SHA-256) and install a plugin/theme ZIP; optionally activate.
	 *
	 * @param WP_REST_Request $request Request (JSON: type, slug, version, download_url, sha256, activate, network_wide).
	 */
	public function install( WP_REST_Request $request ): WP_REST_Response {
		$data = (array) $request->get_json_params();

		$type         = sanitize_text_field( (string) ( $data['type'] ?? '' ) );
		$slug         = sanitize_text_field( (string) ( $data['slug'] ?? '' ) );
		$version      = sanitize_text_field( (string) ( $data['version'] ?? '' ) );
		$download_url = isset( $data['download_url'] ) ? esc_url_raw( (string) $data['download_url'] ) : '';
		$expected_sha = strtolower( (string) preg_replace( '/[^a-f0-9]/i', '', (string) ( $data['sha256'] ?? '' ) ) );
		$activate     = ! empty( $data['activate'] );
		$network_wide = ! empty( $data['network_wide'] );

		if (
			! in_array( $type, array( 'plugin', 'theme' ), true )
			|| ! $this->valid_slug( $slug )
			|| '' === $download_url
			|| 64 !== strlen( $expected_sha )
			|| ! wp_http_validate_url( $download_url )
		) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'invalid_payload',
					'message' => __( 'Invalid payload.', 'ux-studio' ),
				),
				400
			);
		}

		// FS_METHOD=direct is a hard requirement - without it WP_Upgrader falls back to an FTP prompt.
		if ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'fs_method_unsupported',
					'message' => sprintf(
						/* translators: %s: current FS_METHOD value */
						__( 'Requires FS_METHOD=direct (currently %s).', 'ux-studio' ),
						FS_METHOD
					),
				),
				500
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Download the ZIP to a temp file (uses the "safe" HTTP API).
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$tmp_file = download_url( $download_url, 300 );
		if ( is_wp_error( $tmp_file ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'download_failed',
					'message' => $tmp_file->get_error_message(),
				),
				502
			);
		}

		// Verify the checksum before anything is extracted or executed.
		$actual_sha = (string) hash_file( 'sha256', $tmp_file );
		if ( ! hash_equals( $expected_sha, $actual_sha ) ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_REST_Response(
				array(
					'ok'       => false,
					'code'     => 'sha256_mismatch',
					'message'  => __( 'SHA-256 of the downloaded package does not match the expected hash.', 'ux-studio' ),
					'expected' => $expected_sha,
					'actual'   => $actual_sha,
				),
				400
			);
		}

		if ( ! WP_Filesystem() ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'fs_init_failed',
					'message' => __( 'Could not initialise WP_Filesystem.', 'ux-studio' ),
				),
				500
			);
		}

		if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		}
		$skin = new \Automatic_Upgrader_Skin();

		$install_args = array(
			'overwrite_package' => true,
			'clear_destination' => true,
		);

		if ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			$upgrader = new \Plugin_Upgrader( $skin );
		} else {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			$upgrader = new \Theme_Upgrader( $skin );
		}
		$result = $upgrader->install( $tmp_file, $install_args );

		// The upgrader normally removes the temp file itself; make sure.
		if ( file_exists( $tmp_file ) ) {
			@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'upgrader_error',
					'message' => $result->get_error_message(),
					'log'     => $skin->get_upgrade_messages(),
				),
				500
			);
		}
		if ( false === $result || null === $result ) {
			$errors = $skin->result;
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'install_failed',
					'message' => is_wp_error( $errors ) ? $errors->get_error_message() : __( 'Installation failed.', 'ux-studio' ),
					'log'     => $skin->get_upgrade_messages(),
				),
				500
			);
		}

		// Resolve the real installed version and optionally activate.
		$installed_version = null;
		$active            = false;

		if ( 'plugin' === $type ) {
			$plugin_info = $upgrader->plugin_info(); // Relative path such as "woodmart/woodmart.php".
			if ( ! $plugin_info ) {
				$found       = $this->find_plugin_by_slug( $slug );
				$plugin_info = $found ? $found['file'] : null;
			}

			if ( $plugin_info ) {
				$all = get_plugins();
				if ( isset( $all[ $plugin_info ]['Version'] ) ) {
					$installed_version = $all[ $plugin_info ]['Version'];
				}

				if ( $activate ) {
					$activation = activate_plugin( $plugin_info, '', $network_wide && is_multisite(), true );
					if ( is_wp_error( $activation ) ) {
						ActivityLog::log( 'content-sync', 'node_package_install', $type, 0, array( 'slug' => $slug, 'version' => $installed_version, 'activation_error' => true ) );
						return new WP_REST_Response(
							array(
								'ok'                => true,
								'installed_version' => $installed_version,
								'active'            => false,
								'activation_error'  => $activation->get_error_message(),
							),
							200
						);
					}
					$active = true;
				} else {
					$active = is_plugin_active( $plugin_info );
				}
			}
		} else {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				$installed_version = (string) $theme->get( 'Version' );
				$current           = wp_get_theme();
				$active            = $current->get_stylesheet() === $slug || $current->get_template() === $slug;
			}
		}

		ActivityLog::log( 'content-sync', 'node_package_install', $type, 0, array( 'slug' => $slug, 'version' => $installed_version, 'active' => $active ) );

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'type'              => $type,
				'slug'              => $slug,
				'requested_version' => $version,
				'installed_version' => $installed_version,
				'active'            => $active,
			),
			200
		);
	}

	/**
	 * Idempotently add define('FS_METHOD', 'direct') to wp-config.php. Backs the
	 * file up first (wp-config.php.bak-{timestamp}), writes atomically and leaves
	 * the file untouched if FS_METHOD is already defined (with any value).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function configure_fs_method( WP_REST_Request $request ): WP_REST_Response {
		$config_path = ABSPATH . 'wp-config.php';
		if ( ! file_exists( $config_path ) ) {
			$config_path = dirname( ABSPATH ) . '/wp-config.php';
		}
		if ( ! file_exists( $config_path ) ) {
			return $this->fail( 'config_not_found', __( 'wp-config.php not found.', 'ux-studio' ), 404 );
		}
		if ( ! is_writable( $config_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return $this->fail( 'config_not_writable', __( 'wp-config.php is not writable (check the file permissions).', 'ux-studio' ), 403 );
		}

		$content = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return $this->fail( 'read_failed', __( 'Could not read wp-config.php.', 'ux-studio' ), 500 );
		}

		// Idempotency: leave an existing definition alone, whatever its value.
		if ( preg_match( '/define\s*\(\s*[\'"]FS_METHOD[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)\s*;/i', $content, $m ) ) {
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'changed'   => false,
					'fs_method' => $m[1],
					'message'   => sprintf(
						/* translators: %s: existing FS_METHOD value */
						__( 'FS_METHOD is already defined (%s) - not changing it.', 'ux-studio' ),
						$m[1]
					),
				),
				200
			);
		}

		// Backup before any write.
		$backup_path = $config_path . '.bak-' . gmdate( 'Ymd-His' );
		if ( ! @copy( $config_path, $backup_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return $this->fail( 'backup_failed', __( 'Could not create a backup of wp-config.php.', 'ux-studio' ), 500 );
		}

		// Insertion point: before "That's all, stop editing!" (localised, varying apostrophes).
		$line_to_add = "define('FS_METHOD', 'direct');\n";
		$new_content = null;
		if ( preg_match( '/^([ \t]*)\/\*\s*That.s\s+all/mi', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$offset      = (int) $m[0][1];
			$new_content = substr( $content, 0, $offset ) . $line_to_add . "\n" . substr( $content, $offset );
		} elseif ( preg_match( '/^[ \t]*require_once[^\n]*wp-settings\.php/mi', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			// Fallback: before the require_once of wp-settings.php.
			$offset      = (int) $m[0][1];
			$new_content = substr( $content, 0, $offset ) . $line_to_add . "\n" . substr( $content, $offset );
		}

		if ( null === $new_content ) {
			@unlink( $backup_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return $this->fail( 'no_insertion_point', __( 'Could not find an insertion point in wp-config.php (neither the "That\'s all" comment nor the wp-settings.php line). Edit the file manually.', 'ux-studio' ), 500 );
		}

		// Atomic write: temp file in the same directory, then rename.
		$tmp   = $config_path . '.tmp-' . bin2hex( random_bytes( 4 ) );
		$bytes = @file_put_contents( $tmp, $new_content, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes || strlen( $new_content ) !== $bytes ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'write_failed',
					'message' => sprintf(
						/* translators: %s: backup file name */
						__( 'Writing the temporary file failed - backup kept: %s', 'ux-studio' ),
						basename( $backup_path )
					),
					'backup'  => basename( $backup_path ),
				),
				500
			);
		}

		// Keep the original permissions on the replaced file.
		$perms = @fileperms( $config_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $perms ) {
			@chmod( $tmp, $perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.chmod_chmod
		}

		if ( ! @rename( $tmp, $config_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'code'    => 'rename_failed',
					'message' => sprintf(
						/* translators: %s: backup file name */
						__( 'Atomic rename failed - wp-config.php unchanged, backup in %s', 'ux-studio' ),
						basename( $backup_path )
					),
					'backup'  => basename( $backup_path ),
				),
				500
			);
		}

		ActivityLog::log( 'content-sync', 'node_configure_fs_method', 'config', 0, array( 'backup' => basename( $backup_path ) ) );

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'changed'   => true,
				'fs_method' => 'direct',
				'backup'    => basename( $backup_path ),
				'message'   => sprintf(
					/* translators: %s: backup file name */
					__( 'FS_METHOD=direct added to wp-config.php. Backup: %s', 'ux-studio' ),
					basename( $backup_path )
				),
			),
			200
		);
	}

	/**
	 * Find a plugin by slug (folder name or single-file name) in get_plugins().
	 *
	 * @param string $slug Slug.
	 * @return array{file:string,data:array<string,mixed>}|null
	 */
	private function find_plugin_by_slug( string $slug ): ?array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $file => $info ) {
			if ( dirname( $file ) === $slug || $file === $slug . '.php' ) {
				return array(
					'file' => $file,
					'data' => $info,
				);
			}
		}
		return null;
	}

	/**
	 * Slugs are folder names: letters, digits, dot, dash, underscore only.
	 *
	 * @param string $slug Slug.
	 */
	private function valid_slug( string $slug ): bool {
		return '' !== $slug && 1 === preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) && false === strpos( $slug, '..' );
	}

	/**
	 * Uniform error response.
	 *
	 * @param string $code    Machine code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function fail( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'ok'      => false,
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
