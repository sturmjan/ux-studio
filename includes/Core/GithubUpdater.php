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

	// Repo je privátní - plugin-update-checker bez access tokenu update nestáhne.
	// Token doplnit přes buildUpdateChecker()/setAuthentication() při nasazení.
	private const REPO_URL = 'https://github.com/sturmjan/ux-studio/';

	/**
	 * Register the update checker (no-op until the library exists).
	 */
	public static function register(): void {
		$puc = UXSTUDIO_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! is_readable( $puc ) ) {
			return;
		}
		require_once $puc;

		$builder = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			UXSTUDIO_FILE,
			'ux-studio'
		);
		// Use release assets (the CI-built distribution zip), not source archives.
		$builder->getVcsApi()->enableReleaseAssets();
	}
}
