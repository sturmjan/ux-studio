<?php
/**
 * The controller data of the legal pages wizard: defaults, validation and sanitising.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\Consent;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md 42.5 / 42.14. One profile per site feeds all documents. Everything that arrives from the browser goes through
 * `sanitize()` on the server; the browser form checks nothing that matters. Needs only a few WordPress string helpers
 * (sanitize_text_field, sanitize_textarea_field, is_email, esc_url_raw), which tests/consent-legal.php stubs.
 */
final class LegalProfile {

	public const DOCS = array( 'cookies', 'privacy', 'terms' );

	public const RECIPIENT_KINDS = array( 'carrier', 'payment', 'accounting', 'hosting', 'email', 'other' );

	/** Longest list of own recipients. */
	public const MAX_RECIPIENTS = 30;

	/**
	 * The purposes the wizard knows (keys of legal/purposes.php).
	 *
	 * @return list<string>
	 */
	public static function purpose_keys(): array {
		return array_keys( self::purposes() );
	}

	/**
	 * The purposes data file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function purposes(): array {
		static $cache = null;
		if ( null === $cache ) {
			$cache = (array) require dirname( __FILE__ ) . '/legal/purposes.php';
		}
		return $cache;
	}

	/**
	 * Values for a site that has filled in nothing yet.
	 *
	 * @param bool $shop WooCommerce is active.
	 * @return array<string, mixed>
	 */
	public static function defaults( bool $shop = false ): array {
		return array(
			'name'              => '',
			'ico'               => '',
			'dic'               => '',
			'address'           => '',
			'register_note'     => '',
			'email'             => '',
			'phone'             => '',
			'dpo'               => false,
			'dpo_contact'       => '',
			'authority_name'    => 'Úřad pro ochranu osobních údajů, Pplk. Sochora 27, 170 00 Praha 7',
			'authority_url'     => 'https://www.uoou.gov.cz',
			'purposes'          => $shop ? array( 'orders', 'accounts', 'contact', 'security' ) : array( 'contact', 'security' ),
			'retention'         => array(),
			'recipients'        => array(),
			'has_eshop'         => $shop,
			'return_costs'      => 'customer',
			'complaints_address' => '',
			'complaints_email'  => '',
			'withdrawal_address' => '',
			'delivery_note'     => '',
			'payment_note'      => '',
			'language'          => 'auto',
		);
	}

	/**
	 * Clean the data from the browser. Unknown keys are dropped, every value is cut to its limit. Returns the clean
	 * profile and the problems found, by field (message text for the person filling in the form).
	 *
	 * @param array<string, mixed> $in   Raw input.
	 * @param array<string, mixed> $base Profile to start from (values missing in the input stay as they were).
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function sanitize( array $in, array $base ): array {
		$out    = array_merge( self::defaults(), $base );
		$errors = array();

		$text = static function ( string $key, int $max ) use ( $in, &$out, &$errors ): void {
			if ( ! array_key_exists( $key, $in ) ) {
				return;
			}
			$value = sanitize_text_field( is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '' );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) > $max : strlen( $value ) > $max ) {
				/* translators: %d: maximum number of characters. */
				$errors[ $key ] = sprintf( __( 'Too long, at most %d characters.', 'ux-studio' ), $max );
				return;
			}
			$out[ $key ] = $value;
		};
		$area = static function ( string $key, int $max ) use ( $in, &$out, &$errors ): void {
			if ( ! array_key_exists( $key, $in ) ) {
				return;
			}
			$value = trim( sanitize_textarea_field( is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '' ) );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) > $max : strlen( $value ) > $max ) {
				/* translators: %d: maximum number of characters. */
				$errors[ $key ] = sprintf( __( 'Too long, at most %d characters.', 'ux-studio' ), $max );
				return;
			}
			// Several lines are written as one line with commas, so the address is the same in every sentence.
			$out[ $key ] = trim( (string) preg_replace( '/\s*[\r\n]+\s*/', ', ', $value ) );
		};

		$text( 'name', 150 );
		$text( 'register_note', 200 );
		$area( 'address', 300 );
		$text( 'authority_name', 200 );
		$text( 'dpo_contact', 200 );
		$text( 'delivery_note', 400 );
		$text( 'payment_note', 400 );
		$area( 'complaints_address', 300 );
		$area( 'withdrawal_address', 300 );

		if ( array_key_exists( 'ico', $in ) ) {
			$ico = preg_replace( '/\s+/', '', sanitize_text_field( is_scalar( $in['ico'] ) ? (string) $in['ico'] : '' ) );
			if ( '' !== $ico && 1 !== preg_match( '/^\d{6,10}$/', (string) $ico ) ) {
				$errors['ico'] = __( 'The company number has 6 to 10 digits.', 'ux-studio' );
			} else {
				$out['ico'] = (string) $ico;
			}
		}
		if ( array_key_exists( 'dic', $in ) ) {
			$dic = strtoupper( (string) preg_replace( '/\s+/', '', sanitize_text_field( is_scalar( $in['dic'] ) ? (string) $in['dic'] : '' ) ) );
			if ( '' !== $dic && 1 !== preg_match( '/^[A-Z]{2}[0-9A-Z]{2,12}$/', $dic ) ) {
				$errors['dic'] = __( 'The VAT number starts with two letters, for example CZ12345678.', 'ux-studio' );
			} else {
				$out['dic'] = $dic;
			}
		}
		foreach ( array( 'email', 'complaints_email' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$mail = trim( sanitize_text_field( is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '' ) );
				if ( '' !== $mail && ! is_email( $mail ) ) {
					$errors[ $key ] = __( 'This is not an e-mail address.', 'ux-studio' );
				} else {
					$out[ $key ] = $mail;
				}
			}
		}
		if ( array_key_exists( 'phone', $in ) ) {
			$phone = trim( sanitize_text_field( is_scalar( $in['phone'] ) ? (string) $in['phone'] : '' ) );
			if ( '' !== $phone && ( strlen( $phone ) > 30 || 1 !== preg_match( '/^[0-9 +()\-\/]+$/', $phone ) ) ) {
				$errors['phone'] = __( 'A telephone number may contain digits, spaces and + ( ) - only.', 'ux-studio' );
			} else {
				$out['phone'] = $phone;
			}
		}
		if ( array_key_exists( 'authority_url', $in ) ) {
			$url = esc_url_raw( is_scalar( $in['authority_url'] ) ? trim( (string) $in['authority_url'] ) : '', array( 'http', 'https' ) );
			if ( '' !== trim( (string) $in['authority_url'] ) && '' === $url ) {
				$errors['authority_url'] = __( 'This is not a web address (http or https).', 'ux-studio' );
			} else {
				$out['authority_url'] = $url;
			}
		}

		foreach ( array( 'dpo', 'has_eshop' ) as $key ) {
			if ( array_key_exists( $key, $in ) ) {
				$out[ $key ] = filter_var( $in[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( array_key_exists( 'return_costs', $in ) ) {
			$out['return_costs'] = 'seller' === $in['return_costs'] ? 'seller' : 'customer';
		}
		if ( array_key_exists( 'language', $in ) ) {
			$out['language'] = in_array( $in['language'], array( 'cs', 'en' ), true ) ? (string) $in['language'] : 'auto';
		}

		if ( array_key_exists( 'purposes', $in ) ) {
			$wanted = is_array( $in['purposes'] ) ? array_map( 'strval', $in['purposes'] ) : array();
			// Keep the catalogue order, drop duplicates and anything unknown.
			$out['purposes'] = array_values( array_intersect( self::purpose_keys(), $wanted ) );
		}
		if ( array_key_exists( 'retention', $in ) ) {
			$clean = array();
			foreach ( is_array( $in['retention'] ) ? $in['retention'] : array() as $key => $value ) {
				if ( ! in_array( (string) $key, self::purpose_keys(), true ) || ! is_scalar( $value ) ) {
					continue;
				}
				$value = sanitize_text_field( (string) $value );
				if ( '' === $value ) {
					continue;
				}
				if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ) ) > 300 ) {
					$errors[ 'retention.' . $key ] = sprintf( /* translators: %d: maximum number of characters. */ __( 'Too long, at most %d characters.', 'ux-studio' ), 300 );
					continue;
				}
				$clean[ (string) $key ] = $value;
			}
			$out['retention'] = $clean;
		}
		if ( array_key_exists( 'recipients', $in ) ) {
			$list = array();
			foreach ( is_array( $in['recipients'] ) ? $in['recipients'] : array() as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$name = sanitize_text_field( is_scalar( $row['name'] ?? null ) ? (string) $row['name'] : '' );
				if ( '' === $name ) {
					continue;
				}
				if ( count( $list ) >= self::MAX_RECIPIENTS ) {
					$errors['recipients'] = sprintf( /* translators: %d: maximum number of recipients. */ __( 'At most %d recipients.', 'ux-studio' ), self::MAX_RECIPIENTS );
					break;
				}
				if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name ) ) > 120 ) {
					$errors[ 'recipients.' . $i ] = sprintf( /* translators: %d: maximum number of characters. */ __( 'Too long, at most %d characters.', 'ux-studio' ), 120 );
					continue;
				}
				$kind   = (string) ( $row['kind'] ?? 'other' );
				$list[] = array(
					'kind'          => in_array( $kind, self::RECIPIENT_KINDS, true ) ? $kind : 'other',
					'name'          => $name,
					'third_country' => filter_var( $row['third_country'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				);
			}
			$out['recipients'] = $list;
		}

		if ( ! empty( $out['dpo'] ) && '' === trim( (string) $out['dpo_contact'] ) && ! isset( $errors['dpo_contact'] ) ) {
			$errors['dpo_contact'] = __( 'Fill in how to reach the data protection officer.', 'ux-studio' );
		}
		return array(
			'values' => $out,
			'errors' => $errors,
		);
	}

	/**
	 * What is still missing before a document can be made. A document with gaps would carry empty brackets or a
	 * controller nobody can reach, so publishing is refused until these are filled in.
	 *
	 * @param string               $doc     cookies | privacy | terms.
	 * @param array<string, mixed> $profile Clean profile.
	 * @return array<string, string> field => message
	 */
	public static function missing( string $doc, array $profile ): array {
		$need = array();
		$must = array(
			'name'    => __( 'Name of the controller (company or person)', 'ux-studio' ),
			'address' => __( 'Address', 'ux-studio' ),
			'email'   => __( 'E-mail for questions', 'ux-studio' ),
		);
		foreach ( $must as $key => $label ) {
			if ( '' === trim( (string) ( $profile[ $key ] ?? '' ) ) ) {
				$need[ $key ] = $label;
			}
		}
		if ( 'privacy' === $doc && empty( $profile['purposes'] ) ) {
			$need['purposes'] = __( 'At least one purpose of processing', 'ux-studio' );
		}
		if ( 'terms' === $doc && '' === trim( (string) ( $profile['ico'] ?? '' ) ) ) {
			$need['ico'] = __( 'Company number (a seller must be identifiable)', 'ux-studio' );
		}
		if ( 'terms' === $doc && empty( $profile['has_eshop'] ) ) {
			$need['has_eshop'] = __( 'Terms and conditions are for an online shop: switch "We run an online shop" on', 'ux-studio' );
		}
		if ( ! empty( $profile['dpo'] ) && '' === trim( (string) ( $profile['dpo_contact'] ?? '' ) ) ) {
			$need['dpo_contact'] = __( 'Contact of the data protection officer', 'ux-studio' );
		}
		return $need;
	}
}
