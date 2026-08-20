<?php
/**
 * Rollback Manager REST controller.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\RollbackManager;

use UxStudio\Core\ActivityLog;
use UxStudio\Rest\Controller;
use WP_Error;
use WP_REST_Request;
use WP_Upgrader_Skin;

defined( 'ABSPATH' ) || exit;

/**
 * GET  uxstudio/v1/rollback-manager/items                       - installed plugins/themes originating from wordpress.org
 * GET  uxstudio/v1/rollback-manager/versions/{type}/{slug}       - available versions for one item
 * POST uxstudio/v1/rollback-manager/rollback                     - roll an item back to an older version
 *
 * Only Plugin_Upgrader/Theme_Upgrader (WP core) are used to perform the
 * actual file replacement, and only ever with a download URL resolved
 * server-side via WordPressApi (which enforces the downloads.wordpress.org
 * host allow-list) - the client only ever supplies type/slug/version.
 */
final class RestController extends Controller {

	/**
	 * @param \UxStudio\Modules\RollbackManager\Module $module Owning module instance (unused, kept for parity with other controllers).
	 */
	public function __construct( $module ) {
		unset( $module );
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$this->route( '/rollback-manager/items', 'GET', array( $this, 'items' ) );

		$this->route(
			'/rollback-manager/versions/(?P<type>plugin|theme)/(?P<slug>[a-zA-Z0-9_.-]+)',
			'GET',
			array( $this, 'versions' )
		);

		$this->route(
			'/rollback-manager/rollback',
			'POST',
			array( $this, 'rollback' ),
			array(
				'type'    => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'plugin', 'theme' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'slug'    => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'version' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			)
		);
	}

	/**
	 * List installed plugins/themes that come from wordpress.org.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function items( WP_REST_Request $request ) {
		return $this->ok(
			array(
				'plugins' => $this->plugin_items(),
				'themes'  => $this->theme_items(),
			)
		);
	}

	/**
	 * Available versions for one plugin/theme.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function versions( WP_REST_Request $request ) {
		$type = (string) $request['type'];
		$slug = (string) $request['slug'];

		$current = 'plugin' === $type ? $this->current_plugin_version( $slug ) : $this->current_theme_version( $slug );
		$versions = WordPressApi::get_versions( $type, $slug );

		foreach ( $versions as &$entry ) {
			$entry['is_current'] = '' !== $current && version_compare( $entry['version'], $current, '===' );
			unset( $entry['download_url'] ); // Never leak raw package URLs to the client.
		}
		unset( $entry );

		return $this->ok( $versions, array( 'current_version' => $current ) );
	}

	/**
	 * Roll a plugin or theme back to an older wordpress.org version.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function rollback( WP_REST_Request $request ) {
		$type    = (string) $request->get_param( 'type' );
		$slug    = sanitize_text_field( (string) $request->get_param( 'slug' ) );
		$version = sanitize_text_field( (string) $request->get_param( 'version' ) );

		$download_url = WordPressApi::resolve_download_url( $type, $slug, $version );
		if ( null === $download_url ) {
			ActivityLog::log( 'rollback-manager', 'rollback_failed', $type, 0, array( 'slug' => $slug, 'version' => $version, 'reason' => 'version_not_available' ) );
			return new WP_Error( 'uxstudio_version_unavailable', __( 'The requested version is not available from WordPress.org.', 'ux-studio' ), array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'plugin' === $type ) {
			$result = $this->rollback_plugin( $slug, $download_url );
		} else {
			$result = $this->rollback_theme( $slug, $download_url );
		}

		if ( is_wp_error( $result ) ) {
			ActivityLog::log( 'rollback-manager', 'rollback_failed', $type, 0, array( 'slug' => $slug, 'version' => $version, 'error' => $result->get_error_message() ) );
			return $result;
		}

		ActivityLog::log( 'rollback-manager', 'rollback', $type, 0, array( 'slug' => $slug, 'version' => $version ) );

		return $this->ok(
			array(
				'type'    => $type,
				'slug'    => $slug,
				'version' => $version,
			)
		);
	}

	/**
	 * Roll a plugin back using WP core's Plugin_Upgrader.
	 *
	 * @param string $slug         Plugin slug.
	 * @param string $download_url Pre-validated downloads.wordpress.org URL.
	 * @return true|WP_Error
	 */
	private function rollback_plugin( string $slug, string $download_url ) {
		$plugin_file = $this->find_plugin_file( $slug );
		if ( null === $plugin_file ) {
			return new WP_Error( 'uxstudio_plugin_not_found', __( 'Plugin not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$was_active = is_plugin_active( $plugin_file );

		$skin     = new WP_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		add_filter( 'upgrader_pre_download', '__return_false', 20 ); // Ensure our own URL is used, not a cached one.

		$result = $upgrader->install( $download_url, array( 'overwrite_package' => true ) );

		remove_filter( 'upgrader_pre_download', '__return_false', 20 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'uxstudio_rollback_failed', __( 'The rollback could not be completed.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
			activate_plugin( $plugin_file, '', is_network_admin(), true );
		}

		return true;
	}

	/**
	 * Roll a theme back using WP core's Theme_Upgrader.
	 *
	 * @param string $slug         Theme slug (stylesheet).
	 * @param string $download_url Pre-validated downloads.wordpress.org URL.
	 * @return true|WP_Error
	 */
	private function rollback_theme( string $slug, string $download_url ) {
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'uxstudio_theme_not_found', __( 'Theme not found.', 'ux-studio' ), array( 'status' => 404 ) );
		}

		$skin     = new WP_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );

		add_filter( 'upgrader_pre_download', '__return_false', 20 );

		$result = $upgrader->install( $download_url, array( 'overwrite_package' => true ) );

		remove_filter( 'upgrader_pre_download', '__return_false', 20 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'uxstudio_rollback_failed', __( 'The rollback could not be completed.', 'ux-studio' ), array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Installed plugins whose current package resolves to wordpress.org.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plugin_items(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();
		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$slug = $this->plugin_slug( $plugin_file );
			if ( ! WordPressApi::is_from_wordpress_org( 'plugin', $slug ) ) {
				continue;
			}
			$items[] = array(
				'slug'    => $slug,
				'name'    => $plugin_data['Name'],
				'version' => $plugin_data['Version'],
				'active'  => is_plugin_active( $plugin_file ),
			);
		}
		return $items;
	}

	/**
	 * Installed themes whose current package resolves to wordpress.org.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function theme_items(): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$items = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( ! WordPressApi::is_from_wordpress_org( 'theme', $slug ) ) {
				continue;
			}
			$items[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $slug,
			);
		}
		return $items;
	}

	/**
	 * Resolve a plugin slug (folder name, or file basename for single-file
	 * plugins) to its plugin_basename() (e.g. akismet/akismet.php).
	 *
	 * @param string $slug Plugin slug.
	 */
	private function find_plugin_file( string $slug ): ?string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( $this->plugin_slug( $plugin_file ) === $slug ) {
				return $plugin_file;
			}
		}
		return null;
	}

	/**
	 * @param string $plugin_file Relative plugin file path.
	 */
	private function plugin_slug( string $plugin_file ): string {
		$folder = dirname( $plugin_file );
		return '.' === $folder ? basename( $plugin_file, '.php' ) : $folder;
	}

	/**
	 * @param string $slug Plugin slug.
	 */
	private function current_plugin_version( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			if ( $this->plugin_slug( $plugin_file ) === $slug ) {
				return (string) $plugin_data['Version'];
			}
		}
		return '';
	}

	/**
	 * @param string $slug Theme slug.
	 */
	private function current_theme_version( string $slug ): string {
		$theme = wp_get_theme( $slug );
		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}
}
