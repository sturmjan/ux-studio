<?php
/**
 * REST route of the PHP version check.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * GET uxstudio/v1/security/php-version   (manage_options)
 *
 * Registered with the core routes, NOT with the Security module, so the onboarding wizard can ask on a site where
 * Security is still off. Works from the bundled table plus whatever remote data was fetched earlier; it makes no
 * HTTP request itself.
 *
 * data: level (ok|warning|critical), reason (''|outdated|unsupported|eol_soon|unknown), branch, current (x.y.z),
 * php_full (PHP_VERSION as is), minimum, recommended, eol, days_to_eol, cves[], advisory_url, distro_build,
 * checked, source (bundled|php.net), fetched_at, snoozed.
 */
final class PhpVersionRestController extends Controller {

	public function register_routes(): void {
		$this->route( '/security/php-version', 'GET', array( $this, 'get' ) );
	}

	public function get() {
		return $this->ok( PhpVersionMaintenance::payload() );
	}
}
