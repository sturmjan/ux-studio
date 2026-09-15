<?php
/**
 * Curated Lucide icon allowlist for the Menu Icons module.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\MenuIcons;

defined( 'ABSPATH' ) || exit;

/**
 * Reads inner `<svg>` markup for a fixed, curated set of Lucide icon names
 * from local files under `assets/lucide/`. Icons are never fetched at
 * runtime (no CDN, no network call) - the files are synced once at build
 * time from the `lucide-static` npm package via `npm run icons:sync`
 * (see bin/sync-menu-icons.mjs), matching the ALLOWLIST below.
 *
 * Only names in ALLOWLIST are ever looked up, so this doubles as the
 * server-side whitelist that save_fields() validates against - an id that
 * is not in this list is rejected even if a same-named file existed on disk.
 */
final class IconLibrary {

	/**
	 * Curated icon names (kebab-case, matching Lucide's own naming).
	 * Extend this list + re-run `npm run icons:sync` to add more.
	 *
	 * @var string[]
	 */
	private const ALLOWLIST = array(
		'house', 'mail', 'phone', 'map-pin', 'star', 'heart', 'user', 'users',
		'search', 'menu', 'x', 'check', 'chevron-right', 'chevron-down',
		'arrow-right', 'external-link', 'calendar', 'clock', 'globe',
		'facebook', 'instagram', 'linkedin', 'youtube', 'twitter',
		'download', 'upload', 'file-text', 'settings', 'info', 'bell',
		'lock', 'shopping-cart', 'tag', 'circle-help', 'message-circle',
	);

	/**
	 * In-request cache of loaded svg inner markup, name => markup.
	 *
	 * @var array<string, string>
	 */
	private static array $cache = array();

	/**
	 * Allowed icon names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return self::ALLOWLIST;
	}

	/**
	 * name => label list for a <select>.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$choices = array();
		foreach ( self::ALLOWLIST as $name ) {
			$choices[ $name ] = ucwords( str_replace( '-', ' ', $name ) );
		}
		return $choices;
	}

	/** Whether a name is on the allowlist. */
	public static function exists( string $name ): bool {
		return in_array( $name, self::ALLOWLIST, true );
	}

	/**
	 * Sanitized inner SVG content (viewBox 0 0 24 24, stroke-based) for one
	 * icon, ready to wrap in our own `<svg>` element with runtime attributes.
	 *
	 * @param string $name Icon name.
	 * @return string Inner markup, or '' if not allowlisted / file missing.
	 */
	public static function svg_inner( string $name ): string {
		if ( ! self::exists( $name ) ) {
			return '';
		}

		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}

		$file = __DIR__ . '/assets/lucide/' . $name . '.svg';
		if ( ! is_readable( $file ) ) {
			self::$cache[ $name ] = '';
			return '';
		}

		$raw = (string) file_get_contents( $file );
		// Files come from our own synced assets, but still run them through the
		// same strict sanitizer as user-pasted SVG before ever echoing them.
		$sanitized = SvgSanitizer::sanitize( $raw );
		if ( '' === $sanitized ) {
			self::$cache[ $name ] = '';
			return '';
		}

		// Strip the outer <svg ...> wrapper, keep only the inner shapes - the
		// caller supplies its own <svg> root with runtime size/color attrs.
		$inner = preg_replace( '/^<svg\b[^>]*>|<\/svg>\s*$/i', '', $sanitized ) ?? '';
		self::$cache[ $name ] = trim( $inner );
		return self::$cache[ $name ];
	}

	/**
	 * Full self-contained `<svg>` markup for an icon (used in admin previews
	 * and localized JS data).
	 *
	 * @param string $name Icon name.
	 * @param int    $size Pixel size for width/height.
	 */
	public static function svg_markup( string $name, int $size = 20 ): string {
		$inner = self::svg_inner( $name );
		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			$size,
			$inner
		);
	}
}
