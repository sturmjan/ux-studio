<?php
/**
 * Holds back tracking scripts and embedded content until the visitor agrees.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * How blocking works (PLAN.md 42.2, 42.3):
 *
 *  - A blocked script is not removed. Its opening tag is rewritten to `type="text/plain" data-uxc="<category>"`, which
 *    browsers do not run or fetch. The banner script (assets/consent/consent.js) re-creates it, in page order, once the
 *    category is allowed. `data-uxc` with several names ("statistics marketing") means "any of them".
 *  - An iframe loses its `src` (kept in `data-uxc-src`); the banner script puts a placeholder with a load button in
 *    its place.
 *  - Everything is decided from the URL, never from the visitor, so the HTML stays identical for everyone and a page
 *    cache can keep it.
 *
 * The rewriting functions are static and free of WordPress calls (only the hooks wiring is in Module), which is what
 * tests/consent.php exercises.
 */
final class Blocker {

	/** Script types that run JavaScript (an empty type counts). Anything else (JSON, templates, importmap) is left alone. */
	private const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

	/**
	 * Services met on this page: category => label => true. Feeds the service lists of the banner.
	 *
	 * @var array<string, array<string, bool>>
	 */
	private static array $seen = array();

	/** @var array{site_host:string, rules:list<array{pattern:string, category:string}>, gcm_loads_early:bool, nocookie:bool} */
	private static array $config = array(
		'site_host'       => '',
		'rules'           => array(),
		'gcm_loads_early' => false,
		'nocookie'        => true,
	);

	/**
	 * @param array{site_host?:string, rules?:list<array{pattern:string, category:string}>, gcm_loads_early?:bool, nocookie?:bool} $config Settings used by the rewriters.
	 */
	public static function configure( array $config ): void {
		self::$config = array_merge( self::$config, $config );
		self::$seen   = array();
	}

	/**
	 * Services met so far, by category.
	 *
	 * @return array<string, list<string>>
	 */
	public static function seen(): array {
		$out = array();
		foreach ( self::$seen as $category => $labels ) {
			$out[ $category ] = array_keys( $labels );
		}
		return $out;
	}

	/**
	 * Remember a service for the banner's list (a combined category is listed under its first name).
	 *
	 * @param string $category Category or "a b".
	 * @param string $label    Service name.
	 */
	private static function note( string $category, string $label ): void {
		$first = explode( ' ', $category )[0];
		if ( '' !== $first && '' !== $label ) {
			self::$seen[ $first ][ $label ] = true;
		}
	}

	/**
	 * Does a classification mean "hold back" under the current Consent Mode setting? In advanced mode a Google tag
	 * that reads the consent signals may load at once.
	 *
	 * @param array{category:string, label:string, gcm:bool}|null $found Result of Services::classify().
	 */
	private static function must_block( ?array $found ): bool {
		if ( null === $found ) {
			return false;
		}
		return ! ( $found['gcm'] && self::$config['gcm_loads_early'] );
	}

	/**
	 * Rewrite the opening tag of one script: type text/plain, data-uxc = category, original type kept for modules.
	 *
	 * @param string $attrs    Attribute string of the opening tag (without `<script` and `>`).
	 * @param string $category Category name(s).
	 */
	private static function gated_open_tag( string $attrs, string $category ): string {
		$extra = '';
		if ( 1 === preg_match( '/\stype\s*=\s*(["\']?)module\1(?=[\s>\/]|$)/i', ' ' . $attrs ) ) {
			$extra = ' data-uxc-type="module"';
		}
		$attrs = trim( (string) preg_replace( '/\stype\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', ' ' . $attrs ) );
		return '<script type="text/plain" data-uxc="' . htmlspecialchars( $category, ENT_QUOTES ) . '"' . $extra . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>';
	}

	/**
	 * Is this script one the blocker must not touch (not JavaScript, already gated, opted out, or ours)?
	 *
	 * @param string $attrs Attribute string of the opening tag.
	 */
	private static function is_exempt( string $attrs ): bool {
		if ( 1 === preg_match( '/\sdata-(?:uxc|no-consent|cookieconsent)\b/i', ' ' . $attrs ) ) {
			return true;
		}
		if ( 1 === preg_match( '/\sid\s*=\s*["\']?uxc-/i', ' ' . $attrs ) ) {
			return true;
		}
		$type = '';
		if ( 1 === preg_match( '/\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', ' ' . $attrs, $m ) ) {
			$type = strtolower( trim( ( $m[1] ?? '' ) . ( $m[2] ?? '' ) . ( $m[3] ?? '' ) ) );
		}
		return ! in_array( $type, self::JS_TYPES, true );
	}

	/**
	 * Gate every JavaScript `<script>` in a chunk of HTML with ONE category (the chunk of a registered script: the
	 * script, its translations and its before/after inline code travel together).
	 *
	 * @param string $html     HTML as passed to script_loader_tag.
	 * @param string $category Category name(s).
	 * @param string $label    Service name for the banner list.
	 */
	public static function gate_scripts( string $html, string $category, string $label = '' ): string {
		$changed = false;
		$out     = preg_replace_callback(
			'~<script\b([^>]*)>~i',
			static function ( array $m ) use ( $category, &$changed ): string {
				if ( self::is_exempt( $m[1] ) ) {
					return $m[0];
				}
				$changed = true;
				return self::gated_open_tag( $m[1], $category );
			},
			$html
		);
		if ( $changed ) {
			self::note( $category, $label );
		}
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * What a registered script needs, decided from its `src`.
	 *
	 * @param string $tag Tag HTML from script_loader_tag.
	 * @param string $src Script URL.
	 */
	public static function filter_script_tag( string $tag, string $src ): string {
		$found = Services::classify( $src, self::$config['site_host'], self::$config['rules'] );
		if ( ! self::must_block( $found ) ) {
			return $tag;
		}
		return self::gate_scripts( $tag, $found['category'], $found['label'] );
	}

	/**
	 * Page-buffer safety net: gate hard-coded scripts (by src or by known inline signature) and iframes anywhere in a
	 * full page. Runs only when the site owner switches it on.
	 *
	 * @param string $html Whole page.
	 */
	public static function filter_page( string $html ): string {
		if ( strlen( $html ) < 200 || false === stripos( $html, '<' ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			'~<script\b([^>]*)>(.*?)</script>~is',
			static function ( array $m ): string {
				if ( self::is_exempt( $m[1] ) ) {
					return $m[0];
				}
				$found = null;
				if ( 1 === preg_match( '/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', ' ' . $m[1], $s ) ) {
					$found = Services::classify( ( $s[1] ?? '' ) . ( $s[2] ?? '' ) . ( $s[3] ?? '' ), self::$config['site_host'], self::$config['rules'] );
				} elseif ( '' !== trim( $m[2] ) ) {
					$found = Services::classify_inline( $m[2] );
				}
				if ( ! self::must_block( $found ) ) {
					return $m[0];
				}
				self::note( $found['category'], $found['label'] );
				return self::gated_open_tag( $m[1], $found['category'] ) . $m[2] . '</script>';
			},
			$html
		);
		if ( ! is_string( $out ) ) {
			return $html; // A PCRE limit was hit: serve the page as it is rather than a broken one.
		}
		return self::gate_iframes( $out );
	}

	/**
	 * Replace the `src` of embedded third-party iframes by `data-uxc-src` (the banner script adds a placeholder).
	 * YouTube embeds go through youtube-nocookie.com when the setting is on.
	 *
	 * @param string $html HTML with iframes.
	 */
	public static function gate_iframes( string $html ): string {
		if ( false === stripos( $html, '<iframe' ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			'~<iframe\b([^>]*)>~i',
			static function ( array $m ): string {
				$attrs = $m[1];
				if ( 1 === preg_match( '/\sdata-(?:uxc|no-consent)\b/i', ' ' . $attrs ) ) {
					return $m[0];
				}
				if ( 1 !== preg_match( '/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', ' ' . $attrs, $s ) ) {
					return $m[0];
				}
				$src   = ( $s[1] ?? '' ) . ( $s[2] ?? '' );
				$found = Services::classify( $src, self::$config['site_host'], self::$config['rules'] );
				if ( null === $found ) {
					return $m[0];
				}
				// An embed is never a "Google tag that reads the signal": it always waits for the visitor.
				if ( self::$config['nocookie'] ) {
					$src = (string) preg_replace( '~^((?:https?:)?//)(?:www\.)?youtube\.com/embed/~i', '$1www.youtube-nocookie.com/embed/', $src );
				}
				self::note( $found['category'], $found['label'] );
				$attrs = (string) preg_replace( '/\ssrc\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', ' ' . $attrs, 1 );
				return '<iframe data-uxc="' . htmlspecialchars( $found['category'], ENT_QUOTES ) . '" data-uxc-service="' . htmlspecialchars( $found['label'], ENT_QUOTES )
					. '" data-uxc-src="' . htmlspecialchars( $src, ENT_QUOTES, 'UTF-8', false ) . '"' . rtrim( $attrs ) . '>';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}
}
