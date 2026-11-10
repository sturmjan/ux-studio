<?php
/**
 * Suggestions for the details of the controller, found in the data the site already has.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.19. Nothing here saves anything: `detect()` returns `field => {value, source, confidence, label}` and the
 * wizard shows them next to the fields; the person decides by pressing Save.
 *
 * Sources, strongest first (the first source that has a valid value for a field wins):
 *  1. ARES (public register of the Czech state), only on request and only for a company number that passed the checksum;
 *  2. the shop address of WooCommerce;
 *  3. the Organization / Person of an SEO plugin (Yoast SEO, Rank Math, SEOPress, All in One SEO);
 *  4. Complianz (the same mapping as the migration, `ComplianzMap::profile_from_options()`);
 *  5. the page Contact (or About, Imprint) and the home page with its footer, rendered by a request to the site itself;
 *  6. the e-mail of the administrator (last resort, always marked "check it").
 *
 * The extraction is pure (strings in, strings out) so that tests/consent-legal-detect.php runs it without WordPress.
 * Everything that is returned passes `LegalProfile::sanitize()` field by field before it leaves.
 */
final class LegalDetect {

	/** Largest page we read (bytes). */
	public const MAX_BYTES = 1500000;

	/** Seconds one request may take. */
	public const TIMEOUT = 8;

	/** Slugs and titles that mark a page with the details of the company, best first. */
	public const PAGE_SLUGS = array( 'kontakt', 'kontakty', 'contact', 'contacts', 'contact-us', 'o-nas', 'o-firme', 'impressum', 'about', 'about-us' );

	/** Fields that carry text found in the pages. */
	public const FIELDS = array( 'name', 'ico', 'dic', 'address', 'register_note', 'email', 'phone' );

	/** Legal forms (regex fragment, case-insensitive, no capture groups). */
	private const FORMS = 's\.\s*r\.\s*o\.|spol\.\s*s\s*r\.\s*o\.|a\.\s*s\.|v\.\s*o\.\s*s\.|k\.\s*s\.|z\.\s*s\.|z\.\s*ú\.|o\.\s*p\.\s*s\.|s\.\s*p\.';

	/** Domains of free mailboxes: an address there is a weaker sign of a business mailbox. */
	private const FREE_MAIL = array( 'gmail.com', 'googlemail.com', 'seznam.cz', 'email.cz', 'centrum.cz', 'atlas.cz', 'volny.cz', 'post.cz', 'outlook.com', 'outlook.cz', 'hotmail.com', 'live.com', 'icloud.com', 'yahoo.com', 'yahoo.cz', 'proton.me', 'protonmail.com', 'azet.sk', 'zoznam.sk', 'centrum.sk' );

	// ================================================================== pure: company number

	/**
	 * Does the company number (IČO) pass the checksum of the Czech and Slovak registers?
	 *
	 * @param string $ico Digits, 8 of them.
	 */
	public static function ico_valid( string $ico ): bool {
		if ( 1 !== preg_match( '/^\d{8}$/', $ico ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += (int) $ico[ $i ] * ( 8 - $i );
		}
		$check = 11 - ( $sum % 11 );
		if ( 10 === $check ) {
			$check = 0;
		} elseif ( 11 === $check ) {
			$check = 1;
		}
		return $check === (int) $ico[7];
	}

	// ================================================================== pure: text of a page

	/**
	 * Visible text of an HTML page, one line per block.
	 *
	 * @param string $html HTML.
	 */
	public static function html_to_text( string $html ): string {
		$html = substr( $html, 0, self::MAX_BYTES );
		$keep = static function ( ?string $changed, string $before ): string {
			return null === $changed ? $before : $changed;
		};
		$html = $keep( preg_replace( '#<(script|style|noscript|svg|template|head)\b[^>]*>.*?</\1\s*>#is', ' ', $html ), $html );
		$html = $keep( preg_replace( '/<!--.*?-->/s', ' ', $html ), $html );
		$html = $keep( preg_replace( '#<br\s*/?>|</(?:p|div|li|ul|ol|h[1-6]|tr|td|th|table|section|footer|header|address|dd|dt|dl|article|aside|nav|form|label|button|a)>#i', "\n", $html ), $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\r" ), array( ' ', '', "\n" ), $text );
		$out  = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t]+/', ' ', $line ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Everything the text of one page tells about the controller.
	 *
	 * @param string $html HTML of the page (the raw `mailto:` and `tel:` links are read from it too).
	 * @param string $host Host of the site (to prefer e-mail addresses on its domain).
	 * @return array<string, array{value: string, confidence: string}>
	 */
	public static function from_page( string $html, string $host ): array {
		$html  = substr( $html, 0, self::MAX_BYTES );
		$text  = self::html_to_text( $html );
		$lines = '' === $text ? array() : explode( "\n", $text );
		$out   = array();
		$put   = static function ( string $field, string $value, string $confidence ) use ( &$out ): void {
			if ( '' !== $value && ! isset( $out[ $field ] ) ) {
				$out[ $field ] = array(
					'value'      => $value,
					'confidence' => $confidence,
				);
			}
		};

		// Company number: the label, then 8 digits (also written 12 345 678), with a valid checksum.
		$ico      = '';
		$ico_line = -1;
		if ( preg_match_all( '/(?<![\p{L}\d])I[ČC]O?(?![\p{L}])\.?\s*[:\-]?\s*(\d{8}|\d{2} \d{3} \d{3}|\d{3} \d{2} \d{3})(?!\d)/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$digits = (string) preg_replace( '/\D/', '', $hit[0] );
				if ( self::ico_valid( $digits ) ) {
					$ico      = $digits;
					$ico_line = substr_count( substr( $text, 0, (int) $hit[1] ), "\n" );
					break;
				}
			}
		}
		$put( 'ico', $ico, 'high' );

		// VAT number.
		if ( preg_match( '/(?<![\p{L}\d])(CZ\s?\d{8,10}|SK\s?\d{10})(?!\d)/u', $text, $m ) ) {
			$put( 'dic', (string) preg_replace( '/\s+/', '', $m[1] ), 'high' );
		}

		$put( 'register_note', self::register_note( $text ), 'medium' );

		$email = self::pick_email( self::emails( $html, $text ), $host );
		if ( '' !== $email ) {
			$put( 'email', $email, self::email_on_domain( $email, $host ) ? 'high' : 'medium' );
		}
		$put( 'phone', self::pick_phone( $html, $text ), 'medium' );

		$addr = self::find_address( $lines, $ico_line );
		if ( null !== $addr ) {
			$put( 'address', $addr['value'], $addr['confidence'] );
		}

		$name = self::find_name( $text, $lines, $ico_line, (string) ( $addr['city'] ?? '' ) );
		if ( null !== $name ) {
			$put( 'name', $name['value'], $name['confidence'] );
		}
		return $out;
	}

	// ================================================================== pure: register entry

	/**
	 * The entry in the commercial register as a sentence for the documents, or ''.
	 *
	 * @param string $text Text of the page.
	 */
	public static function register_note( string $text ): string {
		$flat = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$court = static function ( string $stem ): string {
			return 0 === stripos( $stem, 'm' ) ? 'Městským soudem' : 'Krajským soudem';
		};
		// "u Krajského soudu v Hradci Králové, oddíl C, vložka 40698"
		if ( preg_match( '/(Krajsk|Městsk)\p{L}*\s+soud\p{L}*\s+(?:v|ve|pro)\s+([\p{L}\.\- ]{2,40}?)\s*,?\s*(?:sp\.\s?zn\.|spisová značka)?\s*:?\s*(?:oddíl|odd\.)\s*([A-Z]{1,2})\s*,?\s*(?:vložka|vl\.)\s*(\d{1,7})/iu', $flat, $m ) ) {
			return sprintf( 'zapsaná v obchodním rejstříku vedeném %s v %s, oddíl %s, vložka %s', $court( $m[1] ), trim( $m[2] ), strtoupper( $m[3] ), $m[4] );
		}
		// "spisová značka C 40698 vedená u Krajského soudu v Hradci Králové"
		if ( preg_match( '/(?:sp\.\s?zn\.|spisová značka)\s*:?\s*([A-Z]{1,2})\s*(\d{1,7})(?:\s*\/\s*[A-Z]+)?\s*,?\s*(?:vedená|vedený)?\s*u\s+(Krajsk|Městsk)\p{L}*\s+soud\p{L}*\s+(?:v|ve)\s+(\p{Lu}[\p{L}\.\- ]{1,38}\p{L})/iu', $flat, $m ) ) {
			return sprintf( 'zapsaná v obchodním rejstříku vedeném %s v %s, oddíl %s, vložka %s', $court( $m[3] ), trim( $m[4] ), strtoupper( $m[1] ), $m[2] );
		}
		// Slovak: "Obchodný register Okresného súdu Bratislava I, oddiel: Sro, vložka č.: 12345/B"
		if ( preg_match( '/Okresn\p{L}*\s+súd\p{L}*\s+(\p{Lu}[\p{L}\.\- ]{1,30}?)\s*,\s*oddiel\s*:?\s*(\p{L}{1,4})\s*,\s*vložka\s*(?:č\.)?\s*:?\s*(\d{1,7}(?:\/[A-Z])?)/iu', $flat, $m ) ) {
			return sprintf( 'zapísaná v Obchodnom registri Okresného súdu %s, oddiel %s, vložka č. %s', trim( $m[1] ), $m[2], $m[3] );
		}
		return '';
	}

	// ================================================================== pure: e-mail and phone

	/**
	 * All e-mail addresses of a page (links and text), without the ones that are never a contact.
	 *
	 * @param string $html Raw HTML.
	 * @param string $text Text of it.
	 * @return list<string>
	 */
	public static function emails( string $html, string $text ): array {
		$found = array();
		if ( preg_match_all( '/href\s*=\s*["\']mailto:([^"\'?#]+)/i', $html, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$found[] = rawurldecode( (string) $raw );
			}
		}
		if ( preg_match_all( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $text, $m ) ) {
			$found = array_merge( $found, $m[0] );
		}
		$out = array();
		foreach ( $found as $mail ) {
			$mail = strtolower( trim( (string) $mail, " \t.,;:" ) );
			if ( 1 !== preg_match( '/^[a-z0-9._%+\-]+@[a-z0-9\-]+(?:\.[a-z0-9\-]+)*\.[a-z]{2,}$/', $mail ) ) {
				continue;
			}
			[ $local, $domain ] = explode( '@', $mail, 2 );
			if ( 1 === preg_match( '/\.(png|jpe?g|gif|webp|svg|css|js)$/', $domain ) || 1 === preg_match( '/^(example|test|sentry|wixpress|wordpress|w3|schema)\b/', $domain ) || 1 === preg_match( '/^(no-?reply|donotreply|wordpress|mailer-daemon)/', $local ) ) {
				continue;
			}
			$out[] = $mail;
		}
		return $out;
	}

	/**
	 * Is the address on the domain of the site (or a subdomain of it)?
	 *
	 * @param string $email Address.
	 * @param string $host  Host of the site.
	 */
	public static function email_on_domain( string $email, string $host ): bool {
		$host   = (string) preg_replace( '/^www\./', '', strtolower( $host ) );
		$domain = strtolower( (string) substr( strrchr( $email, '@' ) ?: '', 1 ) );
		return '' !== $host && '' !== $domain && ( $domain === $host || substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain || substr( $domain, -strlen( $host ) - 1 ) === '.' . $host );
	}

	/**
	 * The address a visitor should write to: on the domain of the site first, then a business mailbox, then a free one;
	 * the one that is listed most often and first wins within the same rank.
	 *
	 * @param list<string> $emails Addresses in the order of the page.
	 * @param string       $host   Host of the site.
	 */
	public static function pick_email( array $emails, string $host ): string {
		$best  = '';
		$score = -1;
		$count = array_count_values( $emails );
		foreach ( array_values( array_unique( $emails ) ) as $i => $mail ) {
			$domain = (string) substr( (string) strrchr( $mail, '@' ), 1 );
			$rank   = ( self::email_on_domain( $mail, $host ) ? 100 : 0 ) + ( in_array( $domain, self::FREE_MAIL, true ) ? 0 : 10 ) + min( 5, $count[ $mail ] ) - ( $i * 0.01 );
			if ( $rank > $score ) {
				$score = $rank;
				$best  = $mail;
			}
		}
		return $best;
	}

	/**
	 * A Czech or Slovak number in the form +420 123 456 789, or '' when it is not one.
	 *
	 * @param string $raw Number as written.
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = (string) preg_replace( '/\D/', '', $raw );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		$cc = '420';
		if ( 12 === strlen( $digits ) && ( 0 === strpos( $digits, '420' ) || 0 === strpos( $digits, '421' ) ) ) {
			$cc     = substr( $digits, 0, 3 );
			$digits = substr( $digits, 3 );
		}
		if ( 9 !== strlen( $digits ) || $digits[0] < '2' ) {
			return '';
		}
		return '+' . $cc . ' ' . substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3, 3 ) . ' ' . substr( $digits, 6, 3 );
	}

	/**
	 * The first telephone number of a page: with the country code in the text, then a `tel:` link, then a number
	 * right after a word like "Tel".
	 *
	 * @param string $html Raw HTML.
	 * @param string $text Text of it.
	 */
	public static function pick_phone( string $html, string $text ): string {
		if ( preg_match_all( '/(?<![\d+])(?:\+|00)\s?(42[01])[\s.\-]?(\d{3})[\s.\-]?(\d{3})[\s.\-]?(\d{3})(?![\d\/])/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$phone = self::normalize_phone( $hit[1] . $hit[2] . $hit[3] . $hit[4] );
				if ( '' !== $phone ) {
					return $phone;
				}
			}
		}
		if ( preg_match_all( '/href\s*=\s*["\']tel:([^"\']+)/i', $html, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$phone = self::normalize_phone( rawurldecode( (string) $raw ) );
				if ( '' !== $phone ) {
					return $phone;
				}
			}
		}
		if ( preg_match_all( '/(?:tel|telefon|mobil|mob|phone|zavolat|volejte)\p{L}*\.?\s*:?\s*(\d{3})[\s.\-]?(\d{3})[\s.\-]?(\d{3})(?![\d\/])/iu', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $hit ) {
				$phone = self::normalize_phone( $hit[1] . $hit[2] . $hit[3] );
				if ( '' !== $phone ) {
					return $phone;
				}
			}
		}
		return '';
	}

	// ================================================================== pure: address and name

	/**
	 * Postal code written as 123 45.
	 *
	 * @param string $psc Five digits, with or without a space.
	 */
	public static function format_psc( string $psc ): string {
		$digits = (string) preg_replace( '/\D/', '', $psc );
		return 5 === strlen( $digits ) ? substr( $digits, 0, 3 ) . ' ' . substr( $digits, 3 ) : trim( $psc );
	}

	/**
	 * The address with a postal code that is nearest to the company number (or the first one).
	 *
	 * @param list<string> $lines    Lines of the page.
	 * @param int          $ref_line Line of the company number, -1 when unknown.
	 * @return array{value: string, confidence: string, city: string}|null
	 */
	public static function find_address( array $lines, int $ref_line ): ?array {
		$stop   = '/^(Tel|Telefon|Mobil|E-?mail|IČO?|IČ|DIČ|Web|Zapsáno|Zapsána|Zapsaná|Jednatel|Datová|Fax|Bank|Č\.|Číslo|Obchodní|Sídlo|Adresa)\b/u';
		$best   = null;
		$best_d = PHP_INT_MAX;
		foreach ( $lines as $i => $line ) {
			foreach ( preg_split( '/\s*[|•·]\s*/u', $line ) ?: array() as $segment ) {
				if ( 1 !== preg_match( '/(?<![\d])(\d{3})\s?(\d{2})(?!\d)\s+(\p{Lu}.*)$/u', $segment, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				$left = substr( $segment, 0, (int) $m[0][1] );
				$city = self::city_from( (string) $m[3][0], $stop );
				if ( '' === $city ) {
					continue;
				}
				$distance = $ref_line >= 0 ? abs( $i - $ref_line ) : $i;
				if ( $distance >= $best_d ) {
					continue;
				}
				$street = self::street_from( $left, 0 < $i ? (string) $lines[ $i - 1 ] : '' );
				$best_d = $distance;
				$best   = array(
					'value'      => ( '' === $street ? '' : $street . ', ' ) . $m[1][0] . ' ' . $m[2][0] . ' ' . $city,
					'confidence' => '' === $street ? 'low' : 'medium',
					'city'       => $city,
				);
			}
		}
		return $best;
	}

	/**
	 * The name of the town after a postal code: up to three words, never a label of the next item.
	 *
	 * @param string $rest Text after the postal code.
	 * @param string $stop Regex of words that start the next item.
	 */
	private static function city_from( string $rest, string $stop ): string {
		$words = preg_split( '/\s+/u', trim( $rest ) ) ?: array();
		$city  = array();
		foreach ( $words as $n => $word ) {
			$clean = rtrim( $word, ',;.' );
			$ok    = ( 0 === $n && 1 === preg_match( '/^\p{Lu}[\p{L}\-]*$/u', $clean ) )
				|| ( $n > 0 && 1 === preg_match( '/^(\p{Lu}[\p{L}\-]*|\d{1,2})$/u', $clean ) && 1 !== preg_match( $stop, $clean ) && ! str_ends_with( $word, ':' ) )
				|| ( $n > 0 && in_array( $clean, array( 'nad', 'pod', 'u', 'na', 'v', 've' ), true ) && isset( $words[ $n + 1 ] ) && 1 === preg_match( '/^\p{Lu}/u', $words[ $n + 1 ] ) );
			if ( ! $ok || count( $city ) >= 3 ) {
				break;
			}
			$city[] = $clean;
			if ( $clean !== $word ) {
				break;
			}
		}
		return implode( ' ', $city );
	}

	/**
	 * The street part in front of a postal code: after the legal form, after a label, or from the first part that holds a number.
	 *
	 * @param string $left     Text in front of the postal code.
	 * @param string $previous The line above (the street may stand alone on it).
	 */
	private static function street_from( string $left, string $previous ): string {
		$left = trim( $left, " \t,;" );
		if ( '' === $left && '' !== $previous && strlen( $previous ) < 80 && 1 === preg_match( '/\d/', $previous ) && 1 !== preg_match( '/@|I[ČC]O?\b|DI[ČC]|\+\d/u', $previous ) ) {
			$left = trim( $previous, " \t,;" );
		}
		if ( preg_match_all( '/(?<![\p{L}\d])(?:' . self::FORMS . ')/iu', $left, $m, PREG_OFFSET_CAPTURE ) ) {
			$last = end( $m[0] );
			$left = ltrim( substr( $left, (int) $last[1] + strlen( (string) $last[0] ) ), " \t,.;" );
		} elseif ( false !== ( $colon = strrpos( $left, ':' ) ) ) {
			$left = ltrim( substr( $left, $colon + 1 ) );
		}
		$parts = array();
		$from  = false;
		foreach ( preg_split( '/\s*,\s*/', $left ) ?: array() as $chunk ) {
			if ( 1 === preg_match( '/I[ČC]O?\b|DI[ČC]|tel|@/iu', $chunk ) ) {
				continue;
			}
			if ( ! $from && 1 === preg_match( '/\d/', $chunk ) ) {
				$from = true;
			}
			if ( $from && '' !== trim( $chunk ) ) {
				$parts[] = trim( $chunk );
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * The company name: the words in front of the most often used legal form; failing that, a person's name next to the
	 * company number (a sole trader).
	 *
	 * @param string       $text     Text of the page.
	 * @param list<string> $lines    Its lines.
	 * @param int          $ico_line Line of the company number or -1.
	 * @param string       $city     Town of the found address (a town is not a person).
	 * @return array{value: string, confidence: string}|null
	 */
	public static function find_name( string $text, array $lines, int $ico_line, string $city = '' ): ?array {
		$counts = array();
		$shown  = array();
		if ( preg_match_all( '/(?<![\p{L}\d])(' . self::FORMS . ')/iu', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $hit ) {
				$pos    = (int) $hit[1];
				$start  = strrpos( substr( $text, 0, $pos ), "\n" );
				$before = substr( $text, false === $start ? 0 : $start + 1, $pos - ( false === $start ? 0 : $start + 1 ) );
				$pieces = preg_split( '/[|•·©]/u', $before ) ?: array( $before );
				$tokens = preg_split( '/\s+/u', trim( (string) end( $pieces ) ) ) ?: array();
				$taken  = array();
				for ( $i = count( $tokens ) - 1; $i >= 0 && count( $taken ) < 7; $i-- ) {
					$token = $tokens[ $i ];
					if ( '' === $token || ( $i < count( $tokens ) - 1 && ',' === substr( $token, -1 ) ) ) {
						break;
					}
					if ( 1 === preg_match( '/^\d+[.,]?$/', $token ) ) {
						break;
					}
					if ( 1 !== preg_match( '/^[\p{Lu}\d&(\-\x{2013}]/u', $token ) && ! in_array( strtolower( $token ), array( 'a', 'and' ), true ) ) {
						break;
					}
					array_unshift( $taken, $token );
				}
				while ( $taken && in_array( strtolower( $taken[0] ), array( 'a', 'and', '&' ), true ) ) {
					array_shift( $taken );
				}
				if ( array() === $taken ) {
					continue;
				}
				$name = trim( implode( ' ', $taken ) . ' ' . preg_replace( '/\s+/', ' ', $hit[0] ) );
				$key  = strtolower( (string) preg_replace( '/[^\p{L}\d]/u', '', $name ) );
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
				$shown[ $key ]  = $shown[ $key ] ?? $name;
			}
		}
		if ( array() !== $counts ) {
			arsort( $counts );
			return array(
				'value'      => $shown[ (string) array_key_first( $counts ) ],
				'confidence' => 'medium',
			);
		}
		// A sole trader: a person's name on the line of the company number or just above it.
		if ( $ico_line >= 0 ) {
			$person = '/^(?:(?:Ing|Mgr|Bc|MUDr|JUDr|PhDr|RNDr|MVDr|doc|prof|Dr)\.?\s+)*\p{Lu}\p{Ll}+(?:\s+\p{Lu}\p{Ll}+){1,2}(?:,\s*(?:Ph\.?D\.|MBA|CSc\.|DiS\.|MSc\.))?$/u';
			for ( $i = $ico_line; $i >= max( 0, $ico_line - 2 ); $i-- ) {
				$candidate = (string) ( $lines[ $i ] ?? '' );
				if ( $i === $ico_line ) {
					$candidate = (string) preg_split( '/(?<![\p{L}\d])I[ČC]O?(?![\p{L}])/u', $candidate )[0];
				}
				$candidate = trim( (string) preg_replace( '/^(?:Provozovatel|Prodávající|Dodavatel|Správce|Fakturační údaje|Obchodní jméno)\s*:?\s*/iu', '', trim( $candidate, " \t,;|" ) ) );
				if ( 1 === preg_match( $person, $candidate ) && 1 !== preg_match( '/\b(Kontakt\p{L}*|Údaje|Fakturační|Adresa|Sídlo|Firma|Společnost|Informace)\b/iu', $candidate ) && ( '' === $city || false === stripos( $candidate, $city ) ) ) {
					return array(
						'value'      => $candidate,
						'confidence' => 'low',
					);
				}
			}
		}
		return null;
	}

	// ================================================================== pure: other sources

	/**
	 * Fields from the answer of ARES (`/ekonomicke-subjekty/{ico}`).
	 *
	 * @param array<string, mixed> $json Decoded answer.
	 * @return array<string, string> name, dic, address (only what the answer holds), or empty when it is not about a company.
	 */
	public static function from_ares( array $json ): array {
		$ico = (string) ( $json['ico'] ?? '' );
		if ( ! self::ico_valid( $ico ) ) {
			return array();
		}
		$out  = array( 'ico' => $ico );
		$name = trim( (string) ( $json['obchodniJmeno'] ?? '' ) );
		if ( '' !== $name ) {
			$out['name'] = $name;
		}
		$dic = strtoupper( (string) preg_replace( '/\s+/', '', (string) ( $json['dic'] ?? '' ) ) );
		if ( 1 === preg_match( '/^CZ\d{8,10}$/', $dic ) ) {
			$out['dic'] = $dic;
		}
		$seat = is_array( $json['sidlo'] ?? null ) ? $json['sidlo'] : array();
		$text = trim( (string) ( $seat['textovaAdresa'] ?? '' ) );
		if ( '' !== $text ) {
			// ARES writes the postal code as 54341.
			$text = (string) preg_replace_callback( '/(?<!\d)(\d{5})(?!\d)/', static fn( array $m ): string => self::format_psc( $m[1] ), $text );
		} elseif ( ! empty( $seat['nazevObce'] ) ) {
			$text = trim( implode( ' ', array_filter( array( self::format_psc( (string) ( $seat['psc'] ?? '' ) ), (string) $seat['nazevObce'] ) ) ) );
		}
		if ( '' !== $text ) {
			$out['address'] = $text;
		}
		return $out;
	}

	/**
	 * The store address of WooCommerce.
	 *
	 * @param array<string, mixed> $o Options: address, address_2, city, postcode.
	 * @return string The address as one line, or ''.
	 */
	public static function woocommerce_address( array $o ): string {
		$street = trim( trim( (string) ( $o['address'] ?? '' ) ) . ' ' . trim( (string) ( $o['address_2'] ?? '' ) ) );
		$town   = trim( self::format_psc( (string) ( $o['postcode'] ?? '' ) ) . ' ' . trim( (string) ( $o['city'] ?? '' ) ) );
		return implode( ', ', array_filter( array( $street, $town ) ) );
	}

	/**
	 * What the SEO plugins say about the owner of the site (their Organization / Person). Only the options the plugins
	 * really use: Yoast SEO `wpseo_titles`, Rank Math `rank-math-options-titles`, SEOPress `seopress_social_option_name`,
	 * All in One SEO `aioseo_options` (JSON). A name that is just the title of the site is a plugin default, so it is
	 * marked weak.
	 *
	 * @param array<string, mixed> $options   Option name => value.
	 * @param string               $site_name Title of the site.
	 * @param callable             $user_name Receives a user id, returns the display name or ''.
	 * @return list<array{source: string, confidence: string, fields: array<string, string>}>
	 */
	public static function from_seo( array $options, string $site_name, callable $user_name ): array {
		$out  = array();
		$weak = static fn( string $name ): string => ( '' !== $site_name && 0 === strcasecmp( trim( $name ), trim( $site_name ) ) ) ? 'low' : 'medium';
		$str  = static fn( $v ): string => is_scalar( $v ) ? trim( (string) $v ) : '';

		$y = $options['wpseo_titles'] ?? null;
		if ( is_array( $y ) ) {
			$name = '';
			if ( 'person' === ( $y['company_or_person'] ?? '' ) ) {
				$name = (int) ( $y['company_or_person_user_id'] ?? 0 ) > 0 ? (string) $user_name( (int) $y['company_or_person_user_id'] ) : '';
			} else {
				$name = $str( $y['company_name'] ?? '' );
			}
			if ( '' !== $name ) {
				$out[] = array(
					'source'     => 'yoast',
					'confidence' => $weak( $name ),
					'fields'     => array( 'name' => $name ),
				);
			}
		}

		$r = $options['rank-math-options-titles'] ?? null;
		if ( is_array( $r ) ) {
			$fields = array();
			$name   = $str( $r['knowledgegraph_name'] ?? '' );
			if ( '' !== $name ) {
				$fields['name'] = $name;
			}
			if ( '' !== $str( $r['email'] ?? '' ) ) {
				$fields['email'] = $str( $r['email'] );
			}
			$phone = $str( $r['phone'] ?? '' );
			if ( '' === $phone && is_array( $r['phone_numbers'] ?? null ) && is_array( reset( $r['phone_numbers'] ) ) ) {
				$phone = $str( reset( $r['phone_numbers'] )['number'] ?? '' );
			}
			if ( '' !== $phone ) {
				$fields['phone'] = $phone;
			}
			if ( is_array( $r['local_address'] ?? null ) ) {
				$address = self::woocommerce_address(
					array(
						'address'  => $str( $r['local_address']['streetAddress'] ?? '' ),
						'city'     => $str( $r['local_address']['addressLocality'] ?? '' ),
						'postcode' => $str( $r['local_address']['postalCode'] ?? '' ),
					)
				);
				if ( '' !== $address ) {
					$fields['address'] = $address;
				}
			}
			if ( array() !== $fields ) {
				$out[] = array(
					'source'     => 'rankmath',
					'confidence' => isset( $fields['name'] ) ? $weak( $fields['name'] ) : 'medium',
					'fields'     => $fields,
				);
			}
		}

		$s = $options['seopress_social_option_name'] ?? null;
		if ( is_array( $s ) && 'none' !== strtolower( $str( $s['seopress_social_knowledge_type'] ?? '' ) ) ) {
			$fields = array();
			$name   = 'person' === strtolower( $str( $s['seopress_social_knowledge_type'] ?? '' ) ) && (int) ( $s['seopress_social_knowledge_user_id'] ?? 0 ) > 0
				? (string) $user_name( (int) $s['seopress_social_knowledge_user_id'] )
				: $str( $s['seopress_social_knowledge_name'] ?? '' );
			if ( '' !== $name ) {
				$fields['name'] = $name;
			}
			if ( '' !== $str( $s['seopress_social_knowledge_email'] ?? '' ) ) {
				$fields['email'] = $str( $s['seopress_social_knowledge_email'] );
			}
			if ( '' !== $str( $s['seopress_social_knowledge_phone'] ?? '' ) ) {
				$fields['phone'] = $str( $s['seopress_social_knowledge_phone'] );
			}
			if ( array() !== $fields ) {
				$out[] = array(
					'source'     => 'seopress',
					'confidence' => isset( $fields['name'] ) ? $weak( $fields['name'] ) : 'medium',
					'fields'     => $fields,
				);
			}
		}

		$a = $options['aioseo_options'] ?? null;
		if ( is_string( $a ) ) {
			$a = json_decode( $a, true );
		}
		$schema = is_array( $a ) && is_array( $a['searchAppearance']['global']['schema'] ?? null ) ? $a['searchAppearance']['global']['schema'] : null;
		if ( null !== $schema ) {
			$fields = array();
			// A value like "#site_title" is a smart tag of the plugin, not a name.
			$real = static fn( string $v ): string => ( '' === $v || '#' === $v[0] ) ? '' : $v;
			if ( 'person' === ( $schema['siteRepresents'] ?? '' ) ) {
				$name = $real( $str( $schema['personName'] ?? '' ) );
				if ( '' === $name && (int) ( $schema['person'] ?? 0 ) > 0 ) {
					$name = (string) $user_name( (int) $schema['person'] );
				}
			} else {
				$name = $real( $str( $schema['organizationName'] ?? '' ) );
			}
			if ( '' !== $name ) {
				$fields['name'] = $name;
			}
			if ( '' !== $real( $str( $schema['email'] ?? '' ) ) ) {
				$fields['email'] = $str( $schema['email'] );
			}
			if ( '' !== $real( $str( $schema['phone'] ?? '' ) ) ) {
				$fields['phone'] = $str( $schema['phone'] );
			}
			if ( array() !== $fields ) {
				$out[] = array(
					'source'     => 'aioseo',
					'confidence' => isset( $fields['name'] ) ? $weak( $fields['name'] ) : 'medium',
					'fields'     => $fields,
				);
			}
		}
		return $out;
	}

	/**
	 * Purposes of processing that the site's own features imply.
	 *
	 * @param array<string, bool> $f forms, analytics, marketing, shop, registration, reviews, newsletter.
	 * @return list<string> Keys of legal/purposes.php in catalogue order.
	 */
	public static function purposes_for( array $f ): array {
		$want = array( 'security' );
		if ( ! empty( $f['forms'] ) ) {
			$want[] = 'contact';
		}
		if ( ! empty( $f['analytics'] ) ) {
			$want[] = 'analytics';
		}
		if ( ! empty( $f['marketing'] ) ) {
			$want[] = 'marketing';
		}
		if ( ! empty( $f['shop'] ) ) {
			$want[] = 'orders';
			if ( ! empty( $f['registration'] ) ) {
				$want[] = 'accounts';
			}
			if ( ! empty( $f['reviews'] ) ) {
				$want[] = 'reviews';
			}
		}
		if ( ! empty( $f['newsletter'] ) ) {
			$want[] = 'newsletter';
		}
		return array_values( array_intersect( LegalProfile::purpose_keys(), $want ) );
	}

	/**
	 * Pick, for every field, the first candidate (candidates come strongest first) that passes `$valid`.
	 *
	 * @param list<array{field: string, value: mixed, source: string, confidence: string, label: string}> $candidates Strongest first.
	 * @param callable                                                                                     $valid      Receives field and value, returns the cleaned value or null.
	 * @return array<string, array{value: mixed, source: string, confidence: string, label: string}>
	 */
	public static function choose( array $candidates, callable $valid ): array {
		$out = array();
		foreach ( $candidates as $c ) {
			if ( isset( $out[ $c['field'] ] ) ) {
				continue;
			}
			$clean = $valid( $c['field'], $c['value'] );
			if ( null === $clean ) {
				continue;
			}
			$out[ $c['field'] ] = array(
				'value'      => $clean,
				'source'     => $c['source'],
				'confidence' => $c['confidence'],
				'label'      => $c['label'],
			);
		}
		return $out;
	}

	/**
	 * A text the wizard prints next to a suggested value.
	 *
	 * @param string $source Source key.
	 * @param string $title  Title of the page for the page source.
	 */
	public static function label( string $source, string $title = '' ): string {
		switch ( $source ) {
			case 'ares':
				return __( 'from ARES', 'ux-studio' );
			case 'woocommerce':
				return __( 'from the WooCommerce settings', 'ux-studio' );
			case 'yoast':
				return __( 'from Yoast SEO', 'ux-studio' );
			case 'rankmath':
				return __( 'from Rank Math', 'ux-studio' );
			case 'seopress':
				return __( 'from SEOPress', 'ux-studio' );
			case 'aioseo':
				return __( 'from All in One SEO', 'ux-studio' );
			case 'complianz':
				return __( 'from Complianz', 'ux-studio' );
			case 'page':
				/* translators: %s: title of a page of the site. */
				return sprintf( __( 'from the page "%s"', 'ux-studio' ), $title );
			case 'footer':
				return __( 'from the home page and its footer', 'ux-studio' );
			case 'admin_email':
				return __( 'administrator e-mail, please check', 'ux-studio' );
			default:
				return __( 'from the settings of the site', 'ux-studio' );
		}
	}

	// ================================================================== WordPress

	/**
	 * Gather suggestions from everything the site knows. Saves nothing.
	 *
	 * @param bool   $use_ares Ask ARES (a request to the public register of the state; only when the administrator asks).
	 * @param string $ico      A company number to look up when the pages hold none (what is already typed in the wizard).
	 * @return array{suggestions: array<string, array<string, mixed>>, pages: list<string>, ares: string}
	 */
	public static function detect( bool $use_ares = false, string $ico = '' ): array {
		$until = microtime( true ) + 30;
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$cands = array();
		$add   = static function ( string $field, $value, string $source, string $confidence, string $title = '' ) use ( &$cands ): void {
			$cands[] = array(
				'field'      => $field,
				'value'      => $value,
				'source'     => $source,
				'confidence' => $confidence,
				'label'      => self::label( $source, $title ),
			);
		};

		// Pages first: they are the only place the company number comes from, and ARES needs it.
		$pages = array();
		$read  = array();
		foreach ( self::pages_to_read() as $page ) {
			if ( microtime( true ) > $until ) {
				break;
			}
			$html = self::fetch( $page['url'] );
			if ( '' === $html ) {
				continue;
			}
			$pages[] = $page['url'];
			$read[]  = array(
				'source' => $page['source'],
				'title'  => $page['title'],
				'facts'  => self::from_page( $html, $host ),
			);
		}
		if ( ! self::ico_valid( $ico ) ) {
			$ico = '';
		}
		foreach ( $read as $r ) {
			if ( '' === $ico && isset( $r['facts']['ico'] ) ) {
				$ico = $r['facts']['ico']['value'];
			}
		}

		// 1. ARES.
		$ares = 'skipped';
		if ( $use_ares ) {
			$ares = '' === $ico ? 'no_ico' : 'error';
			if ( '' !== $ico && microtime( true ) < $until ) {
				$answer = self::ares_fetch( $ico );
				if ( null === $answer ) {
					$ares = 'error';
				} elseif ( array() === $answer ) {
					$ares = 'not_found';
				} else {
					$ares = 'ok';
					foreach ( array( 'name', 'dic', 'address' ) as $field ) {
						if ( isset( $answer[ $field ] ) ) {
							$add( $field, $answer[ $field ], 'ares', 'high' );
						}
					}
				}
			}
		}

		// 2. WooCommerce.
		$shop = class_exists( 'WooCommerce' );
		if ( $shop ) {
			$address = self::woocommerce_address(
				array(
					'address'   => get_option( 'woocommerce_store_address', '' ),
					'address_2' => get_option( 'woocommerce_store_address_2', '' ),
					'city'      => get_option( 'woocommerce_store_city', '' ),
					'postcode'  => get_option( 'woocommerce_store_postcode', '' ),
				)
			);
			if ( '' !== $address ) {
				$add( 'address', $address, 'woocommerce', 'high' );
			}
		}

		// 3. SEO plugins.
		$opts = array();
		foreach ( array( 'wpseo_titles', 'rank-math-options-titles', 'seopress_social_option_name', 'aioseo_options' ) as $name ) {
			$value = get_option( $name, false );
			if ( false !== $value && '' !== $value ) {
				$opts[ $name ] = $value;
			}
		}
		$user_name = static function ( int $id ): string {
			$user = get_userdata( $id );
			return $user ? (string) $user->display_name : '';
		};
		foreach ( self::from_seo( $opts, (string) get_bloginfo( 'name' ), $user_name ) as $row ) {
			foreach ( $row['fields'] as $field => $value ) {
				$add( $field, $value, $row['source'], 'name' === $field ? $row['confidence'] : 'medium' );
			}
		}

		// 4. Complianz.
		$cmplz = get_option( 'cmplz_options', array() );
		if ( is_array( $cmplz ) ) {
			foreach ( ComplianzMap::profile_from_options( $cmplz ) as $field => $value ) {
				$add( $field, $value, 'complianz', 'medium' );
			}
		}

		// 5. Pages (the contact page before the home page).
		foreach ( $read as $r ) {
			foreach ( $r['facts'] as $field => $fact ) {
				$add( $field, $fact['value'], $r['source'], $fact['confidence'], $r['title'] );
			}
		}

		// 6. Administrator e-mail.
		$admin = trim( (string) get_option( 'admin_email', '' ) );
		if ( '' !== $admin ) {
			$add( 'email', $admin, 'admin_email', 'low' );
		}

		$chosen = self::choose(
			$cands,
			static function ( string $field, $value ): ?string {
				$result = LegalProfile::sanitize( array( $field => $value ), LegalProfile::defaults() );
				if ( isset( $result['errors'][ $field ] ) || '' === trim( (string) ( $result['values'][ $field ] ?? '' ) ) ) {
					return null;
				}
				return (string) $result['values'][ $field ];
			}
		);
		$suggestions = array();
		foreach ( $chosen as $field => $row ) {
			$suggestions[ $field ] = $row;
		}

		// The shop and the purposes follow from what the site has.
		if ( $shop ) {
			$suggestions['has_eshop'] = array(
				'value'      => true,
				'source'     => 'woocommerce',
				'confidence' => 'high',
				'label'      => self::label( 'woocommerce' ),
			);
		}
		$report   = Report::site();
		$purposes = self::purposes_for(
			array(
				'forms'        => self::has_forms(),
				'analytics'    => ! empty( $report['categories']['statistics'] ),
				'marketing'    => ! empty( $report['categories']['marketing'] ),
				'shop'         => $shop,
				'registration' => $shop && ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) || 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout' ) ),
				'reviews'      => $shop && 'no' !== get_option( 'woocommerce_enable_reviews', 'yes' ),
				'newsletter'   => self::has_newsletter(),
			)
		);
		$suggestions['purposes'] = array(
			'value'      => $purposes,
			'source'     => 'site',
			'confidence' => 'medium',
			'label'      => self::label( 'site' ),
		);

		return array(
			'suggestions' => $suggestions,
			'pages'       => $pages,
			'ares'        => $ares,
		);
	}

	/** Is there a form on the site (the Forms module or a known form plugin)? */
	private static function has_forms(): bool {
		return in_array( 'forms', (array) get_option( 'uxstudio_active_modules', array() ), true )
			|| defined( 'WPCF7_VERSION' ) || defined( 'WPFORMS_VERSION' ) || class_exists( 'GFForms' ) || function_exists( 'Ninja_Forms' ) || defined( 'FLUENTFORM' );
	}

	/** Is a newsletter plugin active? */
	private static function has_newsletter(): bool {
		$known = array( 'mailchimp-for-wp', 'mailpoet', 'newsletter', 'fluent-crm', 'mailin', 'official-mailerlite-sign-up-forms' );
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			if ( in_array( strtok( (string) $plugin, '/' ), $known, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Pages to read: the contact-like pages of the site, then the home page.
	 *
	 * @return list<array{url: string, title: string, source: string}>
	 */
	private static function pages_to_read(): array {
		$found = array();
		$posts = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'numberposts'      => 200,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$slug  = (string) $post->post_name;
			$title = strtolower( remove_accents( (string) $post->post_title ) );
			$rank  = array_search( $slug, self::PAGE_SLUGS, true );
			if ( false === $rank ) {
				$rank = array_search( sanitize_title( $title ), self::PAGE_SLUGS, true );
			}
			if ( false !== $rank ) {
				$found[ (int) $rank * 1000 + (int) $post->ID ] = array(
					'url'    => (string) get_permalink( $post ),
					'title'  => (string) $post->post_title,
					'source' => 'page',
				);
			}
		}
		ksort( $found );
		$list   = array_slice( array_values( $found ), 0, 3 );
		$list[] = array(
			'url'    => home_url( '/' ),
			'title'  => '',
			'source' => 'footer',
		);
		return $list;
	}

	/**
	 * Fetch a page of this very site (a request to the site's own address, taken from its permalinks, never from input).
	 *
	 * @param string $url Address.
	 * @return string HTML or ''.
	 */
	private static function fetch( string $url ): string {
		$own = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$to  = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
		if ( '' === $own || $own !== $to ) {
			return '';
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 2,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook for loopback requests.
				'user-agent'          => 'UXStudio-Consent-Detect/1',
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Accept' => 'text/html' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || false === stripos( (string) wp_remote_retrieve_header( $response, 'content-type' ), 'html' ) ) {
			return '';
		}
		return substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_BYTES );
	}

	/**
	 * Ask ARES about one company number.
	 *
	 * @param string $ico Eight digits that passed the checksum.
	 * @return array<string, string>|null Fields; empty array when ARES does not know the number; null when it did not answer.
	 */
	private static function ares_fetch( string $ico ): ?array {
		if ( ! self::ico_valid( $ico ) ) {
			return null;
		}
		$response = wp_remote_get(
			'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $ico,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'user-agent'          => 'UXStudio-Consent-Detect/1',
				'limit_response_size' => 512 * 1024,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return array();
		}
		if ( 200 !== $code ) {
			return null;
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		$fields = self::from_ares( $json );
		if ( ( $fields['ico'] ?? '' ) !== $ico ) {
			return array();
		}
		unset( $fields['ico'] );
		return $fields;
	}
}
