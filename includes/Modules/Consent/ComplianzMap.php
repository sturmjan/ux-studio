<?php
/**
 * Pure mapping of Complianz data onto the settings of this module (migration, PLAN.md 42.7 / 42.16).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * No database and no WordPress queries here: the migration source reads the raw data (options, the banner row, the Script
 * Center option) and hands it over, so everything is tested from a fixture (tests/consent-complianz.php).
 */
final class ComplianzMap {

	/** Complianz document type => our document. Other types (impressum, disclaimer, regional variants) are not generated here. */
	private const DOC_TYPES = array(
		'cookie-statement'  => 'cookies',
		'privacy-statement' => 'privacy',
	);

	/**
	 * Our document for a Complianz type (optionally with the region as a suffix), or null when we have none.
	 *
	 * @param string $type   Type, for example cookie-statement.
	 * @param string $region Region attribute (eu, us, uk...), empty when absent.
	 */
	public static function doc_for( string $type, string $region = '' ): ?string {
		$type = strtolower( trim( $type ) );
		if ( '' !== $region && 'eu' !== strtolower( $region ) ) {
			return null;
		}
		return self::DOC_TYPES[ $type ] ?? null;
	}

	/**
	 * Unserialize a value Complianz stored as a PHP array (never instantiates objects).
	 *
	 * @param mixed $value Raw column value.
	 * @return mixed
	 */
	private static function arr( $value ) {
		if ( is_string( $value ) && 1 === preg_match( '/^a:\d+:\{/', $value ) ) {
			$out = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			return is_array( $out ) ? $out : array();
		}
		return $value;
	}

	/**
	 * A colour as #rgb or #rrggbb, or ''.
	 *
	 * @param mixed $value Raw.
	 */
	private static function hex( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * Plain text of the HTML message of the banner.
	 *
	 * @param string $html HTML.
	 */
	public static function plain( string $html ): string {
		$html = preg_replace( '#</p>\s*<p[^>]*>|<br\s*/?>#i', ' ', $html ) ?? $html;
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Text of a "text with a show flag" field; '' when hidden.
	 *
	 * @param mixed $value Raw.
	 */
	private static function shown_text( $value ): string {
		$value = self::arr( $value );
		if ( is_array( $value ) ) {
			return empty( $value['show'] ) ? '' : trim( (string) ( $value['text'] ?? '' ) );
		}
		return trim( (string) $value );
	}

	/**
	 * The details of the controller Complianz holds (name, address, e-mail, telephone), as fields of the legal profile.
	 * Shared by the migration and by LegalDetect, so there is one mapping.
	 *
	 * @param array<string, mixed> $opt Option `cmplz_options`.
	 * @return array<string, string> Only the fields that have a value.
	 */
	public static function profile_from_options( array $opt ): array {
		$profile = array();
		foreach ( array(
			'organisation_name' => 'name',
			'address_company'   => 'address',
			'email_company'     => 'email',
			'telephone_company' => 'phone',
		) as $from => $to ) {
			$value = is_scalar( $opt[ $from ] ?? null ) ? trim( (string) $opt[ $from ] ) : '';
			if ( '' !== $value ) {
				$profile[ $to ] = $value;
			}
		}
		return $profile;
	}

	/**
	 * Settings of the module from the Complianz data.
	 *
	 * @param array<string, mixed>      $opt     Option `cmplz_options`.
	 * @param array<string, mixed>|null $banner  Row of the default banner (strings as stored), or null.
	 * @param array<string, mixed>      $scripts Option `complianz_options_custom-scripts`.
	 * @param array<string, mixed>      $ctx     `policy_id` (int), `prefix` (cookie prefix), `cookie_page` (URL of the cookie policy page or '').
	 * @return array{settings: array<string, mixed>, profile: array<string, string>, ga: string, gtm: string, other_stats: string, rules: list<string>, rule_problems: list<string>, add_scripts: list<array{name: string, category: string, code: string}>, lossy: list<string>, warnings: list<string>, notes: list<string>}
	 */
	public static function settings( array $opt, ?array $banner, array $scripts, array $ctx ): array {
		$s       = array();
		$lossy   = array();
		$warn    = array();
		$notes   = array();
		$yes     = static fn( string $k ): bool => 'yes' === ( $opt[ $k ] ?? '' ) || true === ( $opt[ $k ] ?? false ) || 1 === ( $opt[ $k ] ?? 0 ) || '1' === ( $opt[ $k ] ?? '' );
		$present = static fn( string $k ): bool => array_key_exists( $k, $opt );

		// ---- Consent Mode: take over the mode the site had.
		if ( $present( 'consent-mode' ) ) {
			$s['consent_mode'] = ! $yes( 'consent-mode' ) ? 'off' : ( $yes( 'gtag-basic-consent-mode' ) ? 'basic' : 'advanced' );
			if ( 'off' === $s['consent_mode'] ) {
				$notes[] = __( 'Google Consent Mode was off in Complianz, so it is off here too. Turn it on in the module if you use Google tags.', 'ux-studio' );
			}
		}
		if ( $present( 'cookie_expiry' ) && (int) $opt['cookie_expiry'] > 0 ) {
			$days = (int) $opt['cookie_expiry'];
			if ( $days > 365 ) {
				$lossy[] = __( 'The consent was valid for more than a year in Complianz; here the longest value is 365 days.', 'ux-studio' );
			}
			$s['expiry_days'] = min( 365, $days );
		}
		if ( $present( 'enable_cookie_blocker' ) ) {
			$block               = ! ( 'no' === $opt['enable_cookie_blocker'] );
			$s['block_scripts']  = $block;
			$s['block_iframes']  = $block;
		}
		if ( $yes( 'use_country' ) ) {
			$s['display_to'] = 'eu';
			$notes[]         = __( 'Complianz showed the banner by country (GEO IP). Here "Europe only" is set; the country comes from the Cloudflare header, so without Cloudflare everybody gets the banner.', 'ux-studio' );
		}
		if ( $yes( 'respect_dnt' ) ) {
			$lossy[] = __( 'Complianz respected Do Not Track and Global Privacy Control. The banner of this module does not read those signals.', 'ux-studio' );
		}
		if ( ! empty( $opt['regions'] ) && 'eu' !== $opt['regions'] && is_string( $opt['regions'] ) ) {
			$lossy[] = __( 'Complianz was set for another privacy law than the EU one. This module works with an opt-in banner for everybody (or the EU only); opt-out regions are not supported.', 'ux-studio' );
		}
		if ( 'no' === ( $opt['enable_cookie_banner'] ?? '' ) ) {
			$warn[] = __( 'The banner was switched off in Complianz. After the switch this module will show its banner.', 'ux-studio' );
		}
		if ( ! empty( $ctx['cookie_page'] ) ) {
			$s['policy_url'] = (string) $ctx['cookie_page'];
		}

		// ---- Banner texts, colours and layout.
		if ( null !== $banner ) {
			$title = self::shown_text( $banner['header'] ?? '' );
			if ( '' !== $title ) {
				$s['title'] = $title;
			}
			$msg = self::plain( (string) ( $banner['message_optin'] ?? '' ) );
			if ( '' !== $msg ) {
				$s['message'] = $msg;
				if ( 1 === preg_match( '/<a\s/i', (string) $banner['message_optin'] ) ) {
					$lossy[] = __( 'The message of the banner had links. Only the text is kept; the link to the cookie policy is added from the "Cookie policy address" setting.', 'ux-studio' );
				}
			}
			foreach ( array(
				'accept'           => 'btn_accept',
				'view_preferences' => 'btn_settings',
				'save_preferences' => 'btn_save',
			) as $from => $to ) {
				$text = trim( (string) ( $banner[ $from ] ?? '' ) );
				if ( '' !== $text ) {
					$s[ $to ] = $text;
				}
			}
			$deny = self::arr( $banner['dismiss'] ?? '' );
			if ( is_array( $deny ) ) {
				if ( empty( $deny['show'] ) ) {
					$lossy[] = __( 'Complianz had no "Deny" button. This banner always has a "Reject all" button, as the rules require.', 'ux-studio' );
				} elseif ( '' !== trim( (string) ( $deny['text'] ?? '' ) ) ) {
					$s['btn_reject'] = trim( (string) $deny['text'] );
				}
			}
			switch ( (string) ( $banner['position'] ?? '' ) ) {
				case 'bottom':
					$s['layout'] = 'bar';
					break;
				case 'bottom-left':
					$s['layout']   = 'box';
					$s['position'] = 'left';
					break;
				case 'bottom-right':
					$s['layout']   = 'box';
					$s['position'] = 'right';
					break;
				case 'center':
					$s['layout'] = 'box';
					$lossy[]     = __( 'The banner was centred on the screen. Here it is a box in the corner.', 'ux-studio' );
					break;
			}
			$bg = self::arr( $banner['colorpalette_background'] ?? '' );
			$tx = self::arr( $banner['colorpalette_text'] ?? '' );
			$ac = self::arr( $banner['colorpalette_button_accept'] ?? '' );
			foreach ( array(
				'color_bg'          => is_array( $bg ) ? ( $bg['color'] ?? '' ) : '',
				'color_text'        => is_array( $tx ) ? ( $tx['color'] ?? '' ) : '',
				'color_accent'      => is_array( $ac ) ? ( $ac['background'] ?? '' ) : '',
				'color_accent_text' => is_array( $ac ) ? ( $ac['text'] ?? '' ) : '',
			) as $key => $color ) {
				if ( '' !== self::hex( $color ) ) {
					$s[ $key ] = self::hex( $color );
				}
			}
			$radius = self::arr( $banner['colorpalette_border_radius'] ?? '' );
			if ( is_array( $radius ) && isset( $radius['top'] ) && 'px' === ( $radius['type'] ?? 'px' ) ) {
				$s['radius'] = max( 0, min( 32, (int) $radius['top'] ) );
			}
			if ( 'hide-everywhere' === (string) ( $banner['manage_consent_options'] ?? '' ) ) {
				$s['show_reopen'] = false;
			}
			if ( ! empty( $banner['soft_cookiewall'] ) ) {
				$lossy[] = __( 'The soft cookie wall (content blocked until a choice is made) is not supported.', 'ux-studio' );
			}
			if ( ! empty( $banner['dismiss_on_scroll'] ) || ! empty( $banner['dismiss_on_timeout'] ) ) {
				$lossy[] = __( 'Consent by scrolling or after a timeout is not supported (it is not a valid consent).', 'ux-studio' );
			}
			if ( ! empty( $banner['use_custom_cookie_css'] ) && '' !== trim( (string) ( $banner['custom_css'] ?? '' ) ) ) {
				$lossy[] = __( 'The custom CSS of the Complianz banner is not transferred.', 'ux-studio' );
			}
			$lossy[] = __( 'Logo, animation, switch style, fonts, shadows and the colours of the other buttons and switches are not transferred; the banner uses its own design.', 'ux-studio' );
			if ( ! empty( $banner['disable_cookiebanner'] ) ) {
				$warn[] = __( 'The banner was disabled in Complianz. After the switch this module will show its banner.', 'ux-studio' );
			}
		} else {
			$notes[] = __( 'No Complianz banner settings were found; the texts and colours of this module stay as they are.', 'ux-studio' );
		}

		// ---- Script Center: own blocking rules.
		$rules    = array();
		$problems = array();
		foreach ( (array) ( $scripts['block_script'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['enable'] ) ) {
				continue;
			}
			$category = (string) ( $row['category'] ?? 'marketing' );
			$category = 'functional' === $category ? 'necessary' : $category;
			if ( ! in_array( $category, array( 'preferences', 'statistics', 'marketing', 'necessary' ), true ) ) {
				$category = 'marketing';
			}
			foreach ( (array) ( $row['urls'] ?? array() ) as $url ) {
				self::add_rule( $rules, $problems, $url, $category, (string) ( $row['name'] ?? '' ) );
			}
		}
		foreach ( (array) ( $scripts['whitelist_script'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ( ! isset( $row['enable'] ) || ! empty( $row['enable'] ) ) ) {
				foreach ( (array) ( $row['urls'] ?? array() ) as $url ) {
					self::add_rule( $rules, $problems, $url, 'necessary', (string) ( $row['name'] ?? '' ) );
				}
			} elseif ( is_string( $row ) ) {
				self::add_rule( $rules, $problems, $row, 'necessary', '' );
			}
		}
		if ( $rules ) {
			$s['custom_rules'] = implode( "\n", $rules );
		}
		if ( $problems ) {
			$lossy[] = sprintf(
				/* translators: %s: list of script addresses. */
				__( 'These Script Center addresses cannot be written as a rule and are not transferred: %s', 'ux-studio' ),
				implode( ', ', array_slice( $problems, 0, 8 ) )
			);
		}

		$add = array();
		foreach ( (array) ( $scripts['add_script'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['enable'] ) ) {
				$add[] = array(
					'name'     => (string) ( $row['name'] ?? '' ),
					'category' => (string) ( $row['category'] ?? 'marketing' ),
					'code'     => (string) ( $row['editor'] ?? $row['script'] ?? '' ),
				);
			}
		}

		// ---- Statistics Complianz inserts by itself (it vanishes with the plugin).
		$stats = (string) ( $opt['compile_statistics'] ?? '' );
		$ga    = '';
		$gtm   = '';
		$other = '';
		if ( 'google-analytics' === $stats ) {
			$id = strtoupper( trim( (string) ( $opt['ua_code'] ?? '' ) ) );
			$ga = 1 === preg_match( '/^(G|GT|UA)-[A-Z0-9-]{4,20}$/', $id ) ? $id : '';
		} elseif ( 'google-tag-manager' === $stats ) {
			$id  = strtoupper( trim( (string) ( $opt['gtm_code'] ?? '' ) ) );
			$gtm = 1 === preg_match( '/^GTM-[A-Z0-9]{4,12}$/', $id ) ? $id : 'GTM';
		} elseif ( in_array( $stats, array( 'matomo', 'matomo-tag-manager', 'clicky', 'yandex', 'clarity', 'gtg' ), true ) ) {
			$other = $stats;
		}

		$profile = self::profile_from_options( $opt );

		return array(
			'settings'      => $s,
			'profile'       => $profile,
			'ga'            => $ga,
			'gtm'           => $gtm,
			'other_stats'   => $other,
			'rules'         => $rules,
			'rule_problems' => $problems,
			'add_scripts'   => $add,
			'lossy'         => array_values( array_unique( $lossy ) ),
			'warnings'      => $warn,
			'notes'         => $notes,
		);
	}

	/**
	 * Queue one rule line `<pattern> <category>` when the address fits the rule format of Services::parse_rules().
	 *
	 * @param list<string> $rules    Rules so far.
	 * @param list<string> $problems Addresses that do not fit.
	 * @param mixed        $url      Address.
	 * @param string       $category Category.
	 * @param string       $name     Name in Script Center (unused, for the caller's reading).
	 */
	private static function add_rule( array &$rules, array &$problems, $url, string $category, string $name ): void {
		unset( $name );
		if ( ! is_string( $url ) ) {
			return;
		}
		$pattern = strtolower( trim( (string) preg_replace( '#^https?://#i', '', trim( $url ) ) ) );
		$pattern = rtrim( $pattern, '/' );
		if ( '' === $pattern ) {
			return;
		}
		if ( 1 !== preg_match( '#^[a-z0-9._~:/?&=%@+\-]{2,200}$#', $pattern ) ) {
			$problems[] = $url;
			return;
		}
		$line = $pattern . ' ' . $category;
		if ( ! in_array( $line, $rules, true ) && count( $rules ) < 100 ) {
			$rules[] = $line;
		}
	}

	/**
	 * Replace Complianz shortcodes and blocks in content by ours.
	 *
	 * @param string              $content Post content.
	 * @param array<string, bool> $ready   Our documents that can be produced now (`cookies`, `privacy` => true).
	 * @return array{content: string, changed: bool, converted: array<string, int>, left: list<string>}
	 */
	public static function convert_content( string $content, array $ready ): array {
		$converted = array();
		$left      = array();
		$bump      = static function ( string $what ) use ( &$converted ): void {
			$converted[ $what ] = ( $converted[ $what ] ?? 0 ) + 1;
		};
		$attr      = static function ( string $atts, string $name ): string {
			return 1 === preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $atts, $m ) ? (string) ( '' !== $m[1] ? $m[1] : ( $m[2] ?? '' ) ) : '';
		};
		$doc_of    = static function ( string $type ) use ( $ready ): ?string {
			// Legacy types carry the region in the name: cookie-statement-us.
			$region = '';
			if ( 1 === preg_match( '/^(.*?)-(us|uk|ca|au|za|br)$/', $type, $m ) ) {
				$type   = $m[1];
				$region = $m[2];
			}
			$doc = self::doc_for( $type, $region );
			return null !== $doc && ! empty( $ready[ $doc ] ) ? $doc : null;
		};

		// Blocks first: <!-- wp:complianz/document {...} /--> (an unlinked document keeps the owner's own HTML).
		$content = (string) preg_replace_callback(
			'#<!-- wp:complianz/document (\{.*?\}) /-->#s',
			static function ( array $m ) use ( &$left, $bump, $doc_of ): string {
				$a = json_decode( $m[1], true );
				$a = is_array( $a ) ? $a : array();
				if ( 'unlink' === ( $a['documentSyncStatus'] ?? '' ) && isset( $a['customDocument'] ) && is_string( $a['customDocument'] ) ) {
					$bump( 'custom document' );
					return '<!-- wp:html -->' . $a['customDocument'] . '<!-- /wp:html -->';
				}
				$doc = $doc_of( (string) ( $a['selectedDocument'] ?? '' ) );
				if ( null === $doc ) {
					$left[] = 'document:' . (string) ( $a['selectedDocument'] ?? '' );
					return $m[0];
				}
				$bump( 'document' );
				return '<!-- wp:shortcode -->[uxstudio_legal doc="' . $doc . '"]<!-- /wp:shortcode -->';
			},
			$content
		);
		if ( false !== strpos( $content, 'wp:complianz/consent-area' ) ) {
			$left[] = 'block:consent-area';
		}

		$content = (string) preg_replace_callback(
			'/\[cmplz-document([^\]]*)\]/i',
			static function ( array $m ) use ( &$left, $bump, $attr, $doc_of ): string {
				$type = $attr( $m[1], 'type' );
				$reg  = $attr( $m[1], 'region' );
				$doc  = null;
				if ( '' === $reg ) {
					$doc = $doc_of( $type );
				} elseif ( 'eu' === strtolower( $reg ) ) {
					$doc = $doc_of( $type );
				}
				if ( null === $doc ) {
					$left[] = 'document:' . $type . ( '' !== $reg ? ' (' . $reg . ')' : '' );
					return $m[0];
				}
				$bump( 'document' );
				return '[uxstudio_legal doc="' . $doc . '"]';
			},
			$content
		);
		$content = (string) preg_replace_callback(
			'/\[cmplz-(manage-consent|revoke-link|accept-link)([^\]]*)\]/i',
			static function ( array $m ) use ( $bump, $attr ): string {
				$bump( 'settings button' );
				$label = $attr( $m[2], 'text' );
				return '' !== $label ? '[uxstudio_cookie_settings label="' . str_replace( '"', '', $label ) . '"]' : '[uxstudio_cookie_settings]';
			},
			$content
		);
		$content = (string) preg_replace_callback(
			'/\[cmplz-cookies[^\]]*\]/i',
			static function () use ( $bump ): string {
				$bump( 'cookie table' );
				return '[uxstudio_cookie_table]';
			},
			$content
		);
		if ( 1 === preg_match( '/\[cmplz-(consent-area|dnsmpi-request)/i', $content, $m ) ) {
			$left[] = 'shortcode:' . $m[1];
		}

		return array(
			'content'   => $content,
			'changed'   => $converted !== array(),
			'converted' => $converted,
			'left'      => array_values( array_unique( $left ) ),
		);
	}

	/**
	 * Does the content still hold anything of Complianz?
	 *
	 * @param string $content Post content.
	 */
	public static function mentions( string $content ): bool {
		return false !== stripos( $content, '[cmplz-' ) || false !== strpos( $content, 'wp:complianz/' );
	}

	/**
	 * Read the Complianz cookies of one visitor (what consent.js does in the browser), for the test of that logic.
	 *
	 * @param array<string, string> $cookies Cookie name => value.
	 * @param string                $prefix  Cookie prefix, for example cmplz_.
	 * @param int                   $policy  Policy id that was current when Complianz was switched off.
	 * @return int|null Bits (1 preferences, 2 statistics, 4 marketing) or null when the choice cannot be taken over.
	 */
	public static function legacy_bits( array $cookies, string $prefix, int $policy ): ?int {
		if ( ( $cookies[ $prefix . 'banner-status' ] ?? '' ) !== 'dismissed' ) {
			return null;
		}
		if ( $policy > 0 && (int) ( $cookies[ $prefix . 'policy_id' ] ?? 0 ) !== $policy ) {
			return null;
		}
		$seen = false;
		$bits = 0;
		foreach ( array(
			'preferences' => 1,
			'statistics'  => 2,
			'marketing'   => 4,
		) as $cat => $bit ) {
			$v = $cookies[ $prefix . $cat ] ?? '';
			if ( '' !== $v ) {
				$seen = true;
			}
			if ( 'allow' === $v ) {
				$bits |= $bit;
			}
		}
		return $seen ? $bits : null;
	}
}
