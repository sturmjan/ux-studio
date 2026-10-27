<?php
/**
 * Safety rules of an import (PLAN.md 29): what a file may never do on its own.
 *
 * @package UxStudio
 */

namespace UxStudio\Core\Transfer;

defined( 'ABSPATH' ) || exit;

/**
 * A settings file comes from outside. Even a file the administrator exported yesterday may hold values that
 * lock the administrator out, expose the site or run code, so the import runs every module's values through
 * these rules BEFORE anything is written. The rules only ever remove or weaken a value (with a note); they
 * never add one.
 *
 *  1. CSP: a file can never switch the policy to `enforce` (at most Policy test); the CSP gate stays in charge.
 *  2. Custom login address: saved through the two-step pending flow (wp-login.php stays open until confirmed).
 *  3. Forced two-factor login only when the importing administrator has a second factor set up.
 *  4. Country blocks only when the importing administrator's IP is on the firewall allowlist; block lists of
 *     IP addresses never keep an entry that includes the importing administrator's IP.
 *  5. Menu lock only when it keeps the administrator able to open UX Studio, Plugins, Users and Settings.
 *  6. Maintenance mode is never switched on by an import unless that was asked for explicitly.
 *  7. Code (custom CSS, scripts) only from an importing user who has `unfiltered_html`.
 *  8. HTTPS redirect and HSTS only when the import itself is made over HTTPS.
 *  9. CAPTCHA on the login form only when this site has its own CAPTCHA keys.
 * 10. The importing administrator's own login is removed from the list of blocked user names.
 *
 * Pure (no WordPress): the facts about the importing user and the site arrive in `$ctx`
 * (tests/transfer-import-rules.php).
 */
final class ImportRules {

	/**
	 * Module id => keys that hold code and need `unfiltered_html` (type `css` fields count as well).
	 *
	 * @var array<string, string[]>
	 */
	public const SCRIPT_KEYS = array(
		'admin-customiser' => array( 'login_custom_css', 'admin_custom_css' ),
		'maintenance-mode' => array( 'custom_css' ),
	);

	/**
	 * Module id => keys that are lists of IP addresses to BLOCK (none is a setting today: the firewall keeps
	 * its bans in its own table, and the allowlist settings only ever let somebody in).
	 *
	 * @var array<string, string[]>
	 */
	public const BLOCK_IP_LISTS = array();

	/**
	 * Apply the rules.
	 *
	 * @param string                           $module  Module id.
	 * @param array<int, array<string, mixed>> $schema  Schema.
	 * @param array<string, mixed>             $values  Typed values an import would set.
	 * @param array<string, mixed>             $current Current values of the module.
	 * @param array<string, mixed>             $ctx     Facts: can_unfiltered_html, admin_has_2fa, admin_roles, admin_login,
	 *                                                  admin_ip, allow_maintenance, menu_lock_safe, is_ssl, captcha_ready,
	 *                                                  block_ip_lists.
	 * @return array{values:array<string,mixed>,skipped:array<string,array{code:string,message:string}>,measures:array<int,array{code:string,key:string,message:string}>,login_pending:?string}
	 */
	public static function apply( string $module, array $schema, array $values, array $current, array $ctx ): array {
		$skipped  = array();
		$measures = array();
		$pending  = null;

		$drop = static function ( string $key, string $code, string $message ) use ( &$values, &$skipped ): void {
			unset( $values[ $key ] );
			$skipped[ $key ] = array(
				'code'    => $code,
				'message' => $message,
			);
		};
		$note = static function ( string $code, string $key, string $message ) use ( &$measures ): void {
			$measures[] = array(
				'code'    => $code,
				'key'     => $key,
				'message' => $message,
			);
		};

		// 7. Code.
		$script_keys = self::SCRIPT_KEYS[ $module ] ?? array();
		foreach ( $schema as $field ) {
			if ( 'css' === ( $field['type'] ?? '' ) ) {
				$script_keys[] = (string) $field['key'];
			}
		}
		if ( empty( $ctx['can_unfiltered_html'] ) ) {
			foreach ( array_unique( $script_keys ) as $key ) {
				if ( array_key_exists( $key, $values ) ) {
					$drop( $key, 'unfiltered_html', __( 'Skipped: code can only be imported by a user who may add scripts to the site (the unfiltered_html capability).', 'ux-studio' ) );
					$note( 'scripts', $key, __( 'Code in the file was not imported, because you may not add scripts to the site.', 'ux-studio' ) );
				}
			}
		}

		// 10 + block lists of IPs.
		foreach ( (array) ( $ctx['block_ip_lists'][ $module ] ?? self::BLOCK_IP_LISTS[ $module ] ?? array() ) as $key ) {
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== (string) ( $ctx['admin_ip'] ?? '' ) ) {
				$clean = self::strip_ip_lines( $values[ $key ], (string) $ctx['admin_ip'] );
				if ( $clean !== $values[ $key ] ) {
					$values[ $key ] = $clean;
					$note( 'admin_ip', $key, __( 'Entries that include your own IP address were left out of the block list.', 'ux-studio' ) );
				}
			}
		}

		switch ( $module ) {
			case 'security-optimization':
				// 1. CSP never to enforce.
				if ( isset( $values['csp_mode'] ) && 'enforce' === $values['csp_mode'] && 'enforce' !== ( $current['csp_mode'] ?? '' ) ) {
					$values['csp_mode'] = 'off' === ( $current['csp_mode'] ?? 'off' ) || 'audit' === ( $current['csp_mode'] ?? '' ) ? 'report-only' : (string) $current['csp_mode'];
					$note( 'csp_enforce', 'csp_mode', __( 'The file asked for the Content-Security-Policy to be enforced. An import never does that: it was set to the policy test (report only). Switch to Enforce yourself once the CSP check shows it is safe.', 'ux-studio' ) );
				}
				if ( isset( $values['csp_mode'] ) && 'enforce' === $values['csp_mode'] && 'enforce' === ( $current['csp_mode'] ?? '' ) ) {
					unset( $values['csp_mode'] ); // Nothing to change.
				}

				// 2. Custom login address: pending flow.
				$pending = self::login_pending( $values, $current, $drop, $note );

				// 3. Forced two-factor.
				if ( empty( $ctx['admin_has_2fa'] ) ) {
					if ( ! empty( $values['two_factor_enabled'] ) && empty( $current['two_factor_enabled'] ) ) {
						$drop( 'two_factor_enabled', 'two_factor', __( 'Skipped: you have no two-factor login set up yet, so switching it on could lock you out. Set up your own second factor first, then import again.', 'ux-studio' ) );
						$note( 'two_factor', 'two_factor_enabled', __( 'Two-factor login was not switched on, because the importing administrator has none.', 'ux-studio' ) );
					}
					if ( isset( $values['two_factor_required_roles'] ) && array_intersect( (array) $values['two_factor_required_roles'], (array) ( $ctx['admin_roles'] ?? array() ) ) && array_diff( (array) $values['two_factor_required_roles'], (array) ( $current['two_factor_required_roles'] ?? array() ) ) ) {
						$drop( 'two_factor_required_roles', 'two_factor', __( 'Skipped: the list would force two-factor login on your own role while you have none set up.', 'ux-studio' ) );
					}
				}

				// 4. Country blocks.
				$allowlist = (string) ( $values['ip_firewall_allowlist'] ?? $current['ip_firewall_allowlist'] ?? '' );
				if ( ( ! empty( $values['country_blocklist_enabled'] ) || ! empty( $values['blocked_countries'] ) ) && ! self::ip_in_lines( (string) ( $ctx['admin_ip'] ?? '' ), $allowlist ) ) {
					foreach ( array( 'country_blocklist_enabled', 'blocked_countries' ) as $key ) {
						if ( array_key_exists( $key, $values ) && ( true === $values[ $key ] || ( is_array( $values[ $key ] ) && array() !== $values[ $key ] ) ) ) {
							$drop( $key, 'country_block', __( 'Skipped: blocking countries could cut you off, and your IP address is not on the firewall allowlist. Add it to the allowlist first.', 'ux-studio' ) );
						}
					}
					$note( 'country_block', 'blocked_countries', __( 'Country blocks were not imported, because your IP address is not on the firewall allowlist.', 'ux-studio' ) );
				}

				// 8. HTTPS.
				if ( empty( $ctx['is_ssl'] ) ) {
					foreach ( array( 'force_https', 'enable_hsts' ) as $key ) {
						if ( ! empty( $values[ $key ] ) && empty( $current[ $key ] ) ) {
							$drop( $key, 'https', __( 'Skipped: this import was not made over HTTPS, and redirecting to HTTPS could make the site unreachable.', 'ux-studio' ) );
						}
					}
				}

				// 9. CAPTCHA.
				if ( ! empty( $values['captcha_enabled'] ) && empty( $current['captcha_enabled'] ) && empty( $ctx['captcha_ready'] ) && ! in_array( $values['captcha_provider'] ?? '', array( 'shield', 'auto' ), true ) ) {
					$drop( 'captcha_enabled', 'captcha', __( 'Skipped: this site has no CAPTCHA keys yet (they belong to a host name and are not transferred). Add them in the Security module first, or choose the key-less Shield.', 'ux-studio' ) );
				}

				// 10. Blocked user names.
				if ( isset( $values['blocked_usernames_extra'] ) && is_string( $values['blocked_usernames_extra'] ) && '' !== (string) ( $ctx['admin_login'] ?? '' ) ) {
					$clean = self::strip_lines( $values['blocked_usernames_extra'], (string) $ctx['admin_login'] );
					if ( $clean !== $values['blocked_usernames_extra'] ) {
						$values['blocked_usernames_extra'] = $clean;
						$note( 'own_login', 'blocked_usernames_extra', __( 'Your own user name was left out of the blocked user names.', 'ux-studio' ) );
					}
				}
				break;

			case 'admin-customiser':
				// 5. Menu lock.
				if ( ! empty( $values['menu_lock_enabled'] ) && empty( $current['menu_lock_enabled'] ) && empty( $ctx['menu_lock_safe'] ) ) {
					$drop( 'menu_lock_enabled', 'menu_lock', __( 'Skipped: with the menu rules of this site the lock would stop you from opening UX Studio, Plugins, Users or Settings.', 'ux-studio' ) );
					$note( 'menu_lock', 'menu_lock_enabled', __( 'The menu lock was not switched on, because it would lock the importing administrator out of key screens.', 'ux-studio' ) );
				}
				break;

			case 'maintenance-mode':
				// 6. Maintenance mode.
				if ( isset( $values['mode'] ) && 'disabled' !== $values['mode'] && ( $current['mode'] ?? 'disabled' ) !== $values['mode'] && empty( $ctx['allow_maintenance'] ) ) {
					$drop( 'mode', 'maintenance', __( 'Skipped: an import does not switch on maintenance mode unless you tick the extra box for it.', 'ux-studio' ) );
					$note( 'maintenance', 'mode', __( 'Maintenance mode was not switched on. Tick "Allow switching on maintenance mode" to do it.', 'ux-studio' ) );
				}
				break;
		}

		return array(
			'values'        => $values,
			'skipped'       => $skipped,
			'measures'      => $measures,
			'login_pending' => $pending,
		);
	}

	/**
	 * The custom login address: returns the slug that must start in the pending state, or null.
	 *
	 * @param array<string, mixed> $values  Values (changed in place by reference semantics of the closures).
	 * @param array<string, mixed> $current Current values.
	 * @param callable             $drop    Drop a key with a reason.
	 * @param callable             $note    Add a measure.
	 */
	private static function login_pending( array $values, array $current, callable $drop, callable $note ): ?string {
		if ( ! array_key_exists( 'login_url_enabled', $values ) && ! array_key_exists( 'login_url', $values ) ) {
			return null;
		}
		$enabled = (bool) ( $values['login_url_enabled'] ?? $current['login_url_enabled'] ?? false );
		$slug    = trim( (string) ( $values['login_url'] ?? $current['login_url'] ?? '' ), '/ ' );
		$was_on  = (bool) ( $current['login_url_enabled'] ?? false );
		$was     = trim( (string) ( $current['login_url'] ?? '' ), '/ ' );
		if ( ! $enabled ) {
			return null; // Switching the address off opens wp-login.php: nothing to protect.
		}
		if ( '' === $slug || 1 !== preg_match( '/^[A-Za-z0-9_-]{2,60}$/', $slug ) ) {
			$drop( 'login_url_enabled', 'login_url', __( 'Skipped: the login address in the file is empty or not a plain word.', 'ux-studio' ) );
			$drop( 'login_url', 'login_url', __( 'Skipped: the login address in the file is empty or not a plain word.', 'ux-studio' ) );
			return null;
		}
		if ( $was_on && $was === $slug ) {
			return null; // Unchanged address, nothing new to confirm.
		}
		$note( 'login_pending', 'login_url', __( 'The new login address is saved, but wp-login.php stays open until you open the new address and confirm it (Settings > Security).', 'ux-studio' ) );
		return $slug;
	}

	/**
	 * Does the IP match a line of the list (a single address or a CIDR range)?
	 *
	 * @param string $ip    IP address.
	 * @param string $lines One entry per line.
	 */
	public static function ip_in_lines( string $ip, string $lines ): bool {
		if ( '' === $ip ) {
			return false;
		}
		$ranges = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,;]+/', $lines ) ?: array() ), static fn( string $l ): bool => '' !== $l ) );
		return $ranges && \UxStudio\Core\ClientIp::in_ranges( $ip, $ranges );
	}

	/**
	 * Remove the lines of an IP list that include the given IP.
	 *
	 * @param string $lines One entry per line.
	 * @param string $ip    IP address.
	 */
	public static function strip_ip_lines( string $lines, string $ip ): string {
		$kept = array();
		foreach ( preg_split( '/\R/', $lines ) ?: array() as $line ) {
			if ( '' !== trim( $line ) && self::ip_in_lines( $ip, $line ) ) {
				continue;
			}
			$kept[] = $line;
		}
		return implode( "\n", $kept );
	}

	/**
	 * Remove the lines equal to a word (case-insensitive).
	 *
	 * @param string $lines Lines.
	 * @param string $word  Word.
	 */
	public static function strip_lines( string $lines, string $word ): string {
		$kept = array();
		foreach ( preg_split( '/\R/', $lines ) ?: array() as $line ) {
			if ( 0 === strcasecmp( trim( $line ), $word ) ) {
				continue;
			}
			$kept[] = $line;
		}
		return implode( "\n", $kept );
	}
}
