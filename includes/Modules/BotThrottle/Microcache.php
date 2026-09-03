<?php
/**
 * Filesystem microcache of rendered HTML for throttled bot requests.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\BotThrottle;

defined( 'ABSPATH' ) || exit;

/**
 * Stores full HTML responses keyed by host + URI + bot category under
 * uploads/uxstudio-bot-cache (locked down with a deny-all .htaccess). Used for
 * ORANGE+ tiers so repeat bot hits are served from cache instead of a full
 * WordPress render.
 */
final class Microcache {

	private string $dir;
	private int $default_ttl;

	/**
	 * @param string|null $dir         Cache directory (defaults to uploads).
	 * @param int         $default_ttl Default TTL in seconds.
	 */
	public function __construct( ?string $dir = null, int $default_ttl = 3600 ) {
		$upload            = wp_upload_dir();
		$this->dir         = $dir ?: trailingslashit( $upload['basedir'] ) . 'uxstudio-bot-cache';
		$this->default_ttl = $default_ttl;

		if ( ! file_exists( $this->dir ) ) {
			wp_mkdir_p( $this->dir );
			@file_put_contents( $this->dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			@file_put_contents( $this->dir . '/index.html', '' );
		}
	}

	/**
	 * Cache key for a request.
	 *
	 * @param string $host     Host header.
	 * @param string $uri      Request URI.
	 * @param string $category Bot category.
	 */
	public function key( string $host, string $uri, string $category ): string {
		return md5( $host . '|' . $uri . '|' . $category );
	}

	/**
	 * Fetch cached HTML for a key, or null on miss/expiry.
	 *
	 * @param string $key Cache key.
	 */
	public function get( string $key ): ?string {
		$path = $this->path( $key );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$data = @file_get_contents( $path );
		if ( false === $data ) {
			return null;
		}
		$parts = explode( "\n---END-META---\n", $data, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$meta = json_decode( $parts[0], true );
		if ( ! is_array( $meta ) ) {
			return null;
		}
		if ( ( $meta['expires'] ?? 0 ) < time() ) {
			@unlink( $path );
			return null;
		}
		return $parts[1];
	}

	/**
	 * Store HTML under a key.
	 *
	 * @param string   $key  Cache key.
	 * @param string   $html Response body.
	 * @param int|null $ttl  TTL override in seconds.
	 */
	public function set( string $key, string $html, ?int $ttl = null ): void {
		$ttl  = $ttl ?? $this->default_ttl;
		$meta = wp_json_encode(
			array(
				'created' => time(),
				'expires' => time() + max( 60, $ttl ),
			)
		);
		@file_put_contents( $this->path( $key ), $meta . "\n---END-META---\n" . $html, LOCK_EX );
	}

	/**
	 * Delete all cache files. Returns how many were removed.
	 */
	public function clear(): int {
		$count = 0;
		if ( ! is_dir( $this->dir ) ) {
			return 0;
		}
		foreach ( glob( $this->dir . '/*.cache' ) ?: array() as $file ) {
			if ( @unlink( $file ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * File count + total bytes.
	 *
	 * @return array{count:int,bytes:int}
	 */
	public function size(): array {
		$files = glob( $this->dir . '/*.cache' ) ?: array();
		$bytes = 0;
		foreach ( $files as $f ) {
			$bytes += @filesize( $f ) ?: 0;
		}
		return array(
			'count' => count( $files ),
			'bytes' => $bytes,
		);
	}

	/**
	 * @param string $key Cache key.
	 */
	private function path( string $key ): string {
		return $this->dir . '/' . $key . '.cache';
	}
}
