<?php
/**
 * Client IP resolution for rate limiting and visitor hashing.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper kept for existing callers. Resolution lives in Core\ClientIp,
 * which trusts forwarding headers only from configured proxies or Cloudflare
 * edges (`uxstudio_trusted_proxies` filter / UXSTUDIO_TRUSTED_PROXIES).
 */
final class ClientIp {

	/**
	 * Resolve the client IP. Empty string if it cannot be determined.
	 */
	public static function get(): string {
		return \UxStudio\Core\ClientIp::get( 'auto' );
	}
}
