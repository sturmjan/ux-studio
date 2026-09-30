<?php
/**
 * Auto-updates from GitHub Releases via plugin-update-checker.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Wires YahnisElsts/plugin-update-checker (bundled in vendor/) to the public
 * GitHub repo. Releases carry a pre-built zip (build/ included) so client
 * sites never need npm. Integrates with native WP auto-updates.
 *
 * Optional release signing: CI signs the zip with an Ed25519 key (GitHub
 * secret UXSTUDIO_SIGNING_KEY) and attaches `ux-studio.zip.sig`. When a public
 * key is configured (RELEASE_PUBLIC_KEY below, or the UXSTUDIO_RELEASE_PUBLIC_KEY
 * wp-config constant), every UX Studio package is verified before install and
 * refused on a missing/invalid signature. With no key configured, updates
 * behave exactly as before.
 */
final class GithubUpdater {

	private const REPO_OWNER = 'sturmjan';
	private const REPO_NAME  = 'ux-studio';
	private const REPO_URL   = 'https://github.com/sturmjan/ux-studio/';

	/** Release asset installed by the updater (the .sig sits next to it). */
	private const ASSET_NAME = 'ux-studio.zip';

	/**
	 * Base64 Ed25519 public key (32 bytes) matching the CI signing secret.
	 * Empty = signature verification off.
	 */
	private const RELEASE_PUBLIC_KEY = '';

	/**
	 * Whether this request may check for or install updates: wp-admin (incl.
	 * admin-ajax, update-core.php, Plugins screen), WP-Cron (background checks
	 * + automatic updates), WP-CLI, and the REST plugins endpoint. Everything
	 * else (front end, other REST/AJAX traffic) skips loading the library.
	 */
	public static function is_update_context(): bool {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		// REST_REQUEST is not defined yet on plugins_loaded - match the route.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only substring-matched.
		return false !== strpos( $uri, 'wp/v2/plugins' ) || false !== strpos( rawurldecode( $uri ), 'wp/v2/plugins' );
	}

	/**
	 * Register the update checker (no-op until the library is bundled).
	 *
	 * Works for a PUBLIC repo out of the box. For a PRIVATE repo, define a
	 * fine-grained GitHub token with read access to this repo on each client
	 * site in wp-config.php:
	 *
	 *     define( 'UXSTUDIO_GITHUB_TOKEN', 'github_pat_...' );
	 *
	 * The token is read from that constant only - never hard-coded here or
	 * committed. Without it a private repo returns 404 and no update is offered.
	 */
	public static function register(): void {
		$puc = UXSTUDIO_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $puc ) ) {
			return;
		}
		require_once $puc;

		if ( ! class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			UXSTUDIO_FILE,
			'ux-studio'
		);

		// Private-repo access token, supplied per-site via a wp-config constant.
		if ( '' !== self::token() ) {
			$checker->setAuthentication( self::token() );
		}

		// Update from GitHub Releases: prefer the CI-built distribution zip
		// (contains build/), falling back to the source archive. The name filter
		// matters: without it PUC takes the FIRST asset, which could be the .sig.
		$api = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( '/^' . preg_quote( self::ASSET_NAME, '/' ) . '$/i' );
		}

		if ( '' !== self::public_key() ) {
			add_filter( 'upgrader_pre_download', array( self::class, 'verify_package' ), 10, 4 );
		}
	}

	/**
	 * upgrader_pre_download: for a UX Studio package, download it ourselves,
	 * fetch its detached signature and verify it before WordPress unpacks it.
	 * Returning the local file path makes the upgrader use it as-is; a WP_Error
	 * aborts the update (the installed version stays untouched).
	 *
	 * @param false|string|WP_Error $reply      Short-circuit value from earlier filters.
	 * @param string                $package    Package URL.
	 * @param \WP_Upgrader|null     $upgrader   Upgrader instance.
	 * @param array                 $hook_extra Extra args (plugin basename on updates).
	 * @return false|string|WP_Error
	 */
	public static function verify_package( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::is_own_package( $package, (array) $hook_extra ) ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( $upgrader && isset( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$signature = self::fetch_signature( $package );
		if ( is_wp_error( $signature ) ) {
			wp_delete_file( $file );
			return $signature;
		}

		$valid = false;
		try {
			$valid = sodium_crypto_sign_verify_detached(
				$signature,
				(string) hash_file( 'sha384', $file, true ),
				(string) base64_decode( self::public_key(), true )
			);
		} catch ( \Throwable $e ) {
			$valid = false;
		}

		if ( ! $valid ) {
			wp_delete_file( $file );
			return new WP_Error(
				'uxstudio_bad_signature',
				__( 'UX Studio update refused: the package signature is invalid. The installed version was kept.', 'ux-studio' )
			);
		}
		return $file;
	}

	/**
	 * Whether an upgrader package is a UX Studio release (by the plugin being
	 * updated, or by its GitHub URL for installs without hook_extra).
	 *
	 * @param string $package    Package URL.
	 * @param array  $hook_extra Upgrader extra args.
	 */
	private static function is_own_package( string $package, array $hook_extra ): bool {
		if ( isset( $hook_extra['plugin'] ) && plugin_basename( UXSTUDIO_FILE ) === $hook_extra['plugin'] ) {
			return true;
		}
		$repo = '/' . self::REPO_OWNER . '/' . self::REPO_NAME . '/';
		return false !== strpos( $package, 'github.com' . $repo )
			|| false !== strpos( $package, 'api.github.com/repos' . $repo );
	}

	/**
	 * Download the raw 64-byte signature for a package. Public repos: the .sig
	 * asset next to the zip's browser_download_url. Private repos (API asset
	 * URL): look the release up via the API and take its .sig asset.
	 *
	 * @param string $package Package URL.
	 * @return string|WP_Error
	 */
	private static function fetch_signature( string $package ) {
		$missing = new WP_Error(
			'uxstudio_missing_signature',
			__( 'UX Studio update refused: the release has no valid signature file (ux-studio.zip.sig). The installed version was kept.', 'ux-studio' )
		);

		if ( false !== strpos( $package, '/releases/assets/' ) ) {
			$sig_url = self::sig_asset_api_url( $package );
			if ( '' === $sig_url ) {
				return $missing;
			}
			$response = wp_safe_remote_get( $sig_url, self::request_args( 'application/octet-stream' ) );
		} else {
			$url = (string) strtok( $package, '?#' );
			if ( '/' . self::ASSET_NAME !== substr( $url, -strlen( '/' . self::ASSET_NAME ) ) ) {
				return $missing;
			}
			$response = wp_safe_remote_get( $url . '.sig', self::request_args( '' ) );
		}

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $missing;
		}
		$raw = base64_decode( trim( (string) wp_remote_retrieve_body( $response ) ), true );
		if ( false === $raw || 64 !== strlen( $raw ) ) {
			return $missing;
		}
		return $raw;
	}

	/**
	 * API URL of the .sig asset that belongs to the same release as the given
	 * zip asset API URL, or '' if none.
	 *
	 * @param string $zip_asset_url https://api.github.com/repos/o/r/releases/assets/{id}.
	 */
	private static function sig_asset_api_url( string $zip_asset_url ): string {
		$response = wp_safe_remote_get(
			sprintf( 'https://api.github.com/repos/%s/%s/releases?per_page=20', self::REPO_OWNER, self::REPO_NAME ),
			self::request_args( 'application/vnd.github+json' )
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}
		$releases = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		foreach ( is_array( $releases ) ? $releases : array() as $release ) {
			$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
			if ( ! in_array( $zip_asset_url, array_column( $assets, 'url' ), true ) ) {
				continue;
			}
			foreach ( $assets as $asset ) {
				if ( self::ASSET_NAME . '.sig' === ( $asset['name'] ?? '' ) ) {
					return (string) ( $asset['url'] ?? '' );
				}
			}
			return '';
		}
		return '';
	}

	/**
	 * HTTP args for GitHub requests (token only when configured).
	 *
	 * @param string $accept Accept header ('' = default).
	 * @return array<string, mixed>
	 */
	private static function request_args( string $accept ): array {
		$headers = array();
		if ( '' !== $accept ) {
			$headers['Accept'] = $accept;
		}
		if ( '' !== self::token() ) {
			$headers['Authorization'] = 'token ' . self::token();
		}
		return array(
			'timeout' => 30,
			'headers' => $headers,
		);
	}

	/**
	 * Configured Ed25519 public key (base64) or '' when verification is off.
	 * The embedded constant wins; the wp-config constant lets a site opt in
	 * before a release that embeds the key.
	 */
	private static function public_key(): string {
		$key = self::RELEASE_PUBLIC_KEY;
		if ( '' === $key && defined( 'UXSTUDIO_RELEASE_PUBLIC_KEY' ) ) {
			$key = (string) UXSTUDIO_RELEASE_PUBLIC_KEY;
		}
		$raw = base64_decode( $key, true );
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $key : '';
	}

	/**
	 * Private-repo token from wp-config, or ''.
	 */
	private static function token(): string {
		return defined( 'UXSTUDIO_GITHUB_TOKEN' ) ? (string) UXSTUDIO_GITHUB_TOKEN : '';
	}
}
