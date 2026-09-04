<?php
/**
 * Auto-updates from GitHub Releases via plugin-update-checker.
 *
 * @package UxStudio
 */

namespace UxStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wires YahnisElsts/plugin-update-checker (bundled in vendor/) to the public
 * GitHub repo. Releases carry a pre-built zip (build/ included) so client
 * sites never need npm. Integrates with native WP auto-updates.
 */
final class GithubUpdater {

	private const REPO_URL = 'https://github.com/sturmjan/ux-studio/';

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
		if ( defined( 'UXSTUDIO_GITHUB_TOKEN' ) && '' !== (string) UXSTUDIO_GITHUB_TOKEN ) {
			$checker->setAuthentication( (string) UXSTUDIO_GITHUB_TOKEN );
		}

		// Update from GitHub Releases: prefer the CI-built distribution zip
		// (contains build/), falling back to the source archive.
		$api = $checker->getVcsApi();
		if ( method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets();
		}
	}
}
