<?php
/**
 * Two-factor authentication: per-user data and the rules around it.
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

use UxStudio\Core\Security;
use UxStudio\Core\Totp;

defined( 'ABSPATH' ) || exit;

/**
 * Everything about one user's second factor that is not a screen: the secret (encrypted with
 * Security::seal), the recovery codes (only keyed hashes are stored), the replay guard, the
 * rate limit of code attempts, the pending-login token and the grace counter of a role that
 * requires 2FA. No output and no redirects here, so the rules can be tested without a site.
 *
 * User meta (all prefixed `uxstudio_2fa_`):
 *  - `secret`    sealed base32 secret of an active second factor
 *  - `pending`   sealed secret of a setup that has not been confirmed yet
 *  - `enabled`   '1' while the factor is on
 *  - `recovery`  list of keyed hashes of the unused recovery codes
 *  - `counter`   last accepted TOTP time step (a code cannot be used twice)
 *  - `fail`      { count, since } failed attempts in the current window
 *  - `login`     { hash, expires } token of a login that waits for the second step
 *  - `grace`     logins already spent without a second factor (role requires it)
 *  - `forced`    '1' when the grace is used up and the setup is compulsory
 */
final class TwoFactorStore {

	/** Kill switch for wp-config.php: define( 'UXSTUDIO_DISABLE_2FA', true ); */
	public const KILL_SWITCH = 'UXSTUDIO_DISABLE_2FA';

	/** Wrong codes allowed within the window, then the account is locked until the window ends. */
	public const MAX_FAILURES = 5;

	/** Window of the failure counter and length of the lockout, in seconds (10 minutes). */
	public const WINDOW = 600;

	/** How long a login waits for its second step, in seconds. */
	public const LOGIN_TTL = 600;

	/** Number of recovery codes handed out at once. */
	public const RECOVERY_COUNT = 10;

	/** Default number of logins a user may postpone the setup when the role requires 2FA. */
	public const DEFAULT_GRACE = 3;

	private const META = 'uxstudio_2fa_';

	/** Characters of recovery codes: no 0/O and 1/I/L, they are read from paper. */
	private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	/** @var callable|null Clock replacement (tests). */
	private static $clock = null;

	/**
	 * Replace the clock (tests only).
	 *
	 * @param callable|null $clock Returns a unix time.
	 */
	public static function set_clock( ?callable $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * Current unix time.
	 */
	public static function now(): int {
		return null !== self::$clock ? (int) call_user_func( self::$clock ) : time();
	}

	/**
	 * Is the second step in force? The setting must be on and the kill switch not set.
	 *
	 * @param bool $setting Value of the `two_factor_enabled` setting.
	 */
	public static function active( bool $setting ): bool {
		return $setting && ! self::killed();
	}

	/**
	 * Whether the wp-config.php kill switch is set.
	 */
	public static function killed(): bool {
		return defined( self::KILL_SWITCH ) && constant( self::KILL_SWITCH );
	}

	/* ── Reading ── */

	/**
	 * Whether the user has an active second factor.
	 *
	 * @param int $uid User id.
	 */
	public static function is_enabled( int $uid ): bool {
		return '1' === (string) get_user_meta( $uid, self::META . 'enabled', true ) && '' !== self::secret( $uid );
	}

	/**
	 * Decrypted secret of the active factor ('' when none).
	 *
	 * @param int $uid User id.
	 */
	public static function secret( int $uid ): string {
		return Security::unseal( (string) get_user_meta( $uid, self::META . 'secret', true ) );
	}

	/**
	 * Number of recovery codes still unused.
	 *
	 * @param int $uid User id.
	 */
	public static function recovery_left( int $uid ): int {
		$list = get_user_meta( $uid, self::META . 'recovery', true );
		return is_array( $list ) ? count( $list ) : 0;
	}

	/* ── Setup ── */

	/**
	 * Start (or restart) a setup: a fresh secret waiting for the first valid code.
	 *
	 * @param int $uid User id.
	 * @return string Base32 secret to show to the user.
	 */
	public static function begin_setup( int $uid ): string {
		$secret = Totp::generate_secret();
		update_user_meta( $uid, self::META . 'pending', Security::seal( $secret ) );
		return $secret;
	}

	/**
	 * Secret of an unconfirmed setup ('' when none).
	 *
	 * @param int $uid User id.
	 */
	public static function pending_secret( int $uid ): string {
		return Security::unseal( (string) get_user_meta( $uid, self::META . 'pending', true ) );
	}

	/**
	 * Confirm a setup with the first code from the app: the factor turns on and the recovery codes are created.
	 * The wrong code counts like any other failed attempt.
	 *
	 * @param int    $uid  User id.
	 * @param string $code Code from the app.
	 * @return string[]|null The recovery codes (shown once), or null when the code was wrong or the account is locked.
	 */
	public static function confirm_setup( int $uid, string $code ): ?array {
		$secret = self::pending_secret( $uid );
		if ( '' === $secret || self::lock_remaining( $uid ) > 0 ) {
			return null;
		}
		$step = Totp::verify( $secret, $code, 1, self::now() );
		if ( null === $step ) {
			self::record_failure( $uid );
			return null;
		}
		update_user_meta( $uid, self::META . 'secret', Security::seal( $secret ) );
		update_user_meta( $uid, self::META . 'enabled', '1' );
		update_user_meta( $uid, self::META . 'counter', $step );
		delete_user_meta( $uid, self::META . 'pending' );
		self::clear_failures( $uid );
		return self::generate_recovery_codes( $uid );
	}

	/**
	 * Remove everything: the factor, the pending setup, recovery codes, counters.
	 *
	 * @param int $uid User id.
	 */
	public static function disable( int $uid ): void {
		foreach ( array( 'secret', 'pending', 'enabled', 'recovery', 'counter', 'fail', 'login', 'forced' ) as $key ) {
			delete_user_meta( $uid, self::META . $key );
		}
	}

	/* ── Recovery codes ── */

	/**
	 * Create a new set of recovery codes (the old ones stop working). Only keyed hashes are stored.
	 *
	 * @param int $uid User id.
	 * @return string[] Plain codes `XXXXX-XXXXX`, shown to the user once.
	 */
	public static function generate_recovery_codes( int $uid ): array {
		$codes  = array();
		$hashes = array();
		$max    = strlen( self::CODE_ALPHABET ) - 1;
		while ( count( $codes ) < self::RECOVERY_COUNT ) {
			$raw = '';
			for ( $i = 0; $i < 10; $i++ ) {
				$raw .= self::CODE_ALPHABET[ random_int( 0, $max ) ];
			}
			if ( isset( $hashes[ self::hash_code( $uid, $raw ) ] ) ) {
				continue;
			}
			$hashes[ self::hash_code( $uid, $raw ) ] = true;
			$codes[]                                  = substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 );
		}
		update_user_meta( $uid, self::META . 'recovery', array_keys( $hashes ) );
		return $codes;
	}

	/**
	 * Keyed hash of a recovery code (uppercase, without separators), bound to the user.
	 *
	 * @param int    $uid User id.
	 * @param string $raw Code as typed.
	 */
	private static function hash_code( int $uid, string $raw ): string {
		$raw = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ) );
		return hash_hmac( 'sha256', $uid . '|' . $raw, wp_salt( 'auth' ) );
	}

	/**
	 * Use one recovery code: a valid code is removed (one time only).
	 *
	 * @param int    $uid   User id.
	 * @param string $input Code as typed.
	 */
	private static function consume_recovery( int $uid, string $input ): bool {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{5}[\s-]?[A-Za-z0-9]{5}$/', trim( $input ) ) ) {
			return false;
		}
		$list = get_user_meta( $uid, self::META . 'recovery', true );
		if ( ! is_array( $list ) || ! $list ) {
			return false;
		}
		$hash  = self::hash_code( $uid, $input );
		$match = null;
		foreach ( $list as $i => $stored ) {
			// Every stored hash is compared, so the time does not tell which one matched.
			if ( hash_equals( (string) $stored, $hash ) && null === $match ) {
				$match = $i;
			}
		}
		if ( null === $match ) {
			return false;
		}
		unset( $list[ $match ] );
		update_user_meta( $uid, self::META . 'recovery', array_values( $list ) );
		return true;
	}

	/* ── Checking a code ── */

	/**
	 * Seconds until a locked account may try again (0 = not locked). An expired window is forgotten.
	 *
	 * @param int $uid User id.
	 */
	public static function lock_remaining( int $uid ): int {
		$fail = get_user_meta( $uid, self::META . 'fail', true );
		if ( ! is_array( $fail ) ) {
			return 0;
		}
		$age = self::now() - (int) ( $fail['since'] ?? 0 );
		if ( $age >= self::WINDOW ) {
			delete_user_meta( $uid, self::META . 'fail' );
			return 0;
		}
		return (int) ( $fail['count'] ?? 0 ) >= self::MAX_FAILURES ? self::WINDOW - $age : 0;
	}

	/**
	 * Count one wrong code.
	 *
	 * @param int $uid User id.
	 * @return bool True when this failure just locked the account.
	 */
	public static function record_failure( int $uid ): bool {
		$fail = get_user_meta( $uid, self::META . 'fail', true );
		$now  = self::now();
		if ( ! is_array( $fail ) || $now - (int) ( $fail['since'] ?? 0 ) >= self::WINDOW ) {
			$fail = array(
				'count' => 0,
				'since' => $now,
			);
		}
		++$fail['count'];
		update_user_meta( $uid, self::META . 'fail', $fail );
		return self::MAX_FAILURES === (int) $fail['count'];
	}

	/**
	 * Forget the failure counter (after a good code).
	 *
	 * @param int $uid User id.
	 */
	public static function clear_failures( int $uid ): void {
		delete_user_meta( $uid, self::META . 'fail' );
	}

	/**
	 * Check what the user typed in the second step: an app code or a recovery code.
	 * While the account is locked nothing is evaluated (a guess cannot succeed during the lockout).
	 *
	 * @param int    $uid   User id.
	 * @param string $input Typed code.
	 * @return array{ok:bool,method:string,locked:bool,just_locked:bool,remaining:int,retry_in:int}
	 *         method: `totp` or `recovery` on success, '' otherwise. `remaining`: wrong tries left before the lockout.
	 */
	public static function attempt( int $uid, string $input ): array {
		$out = array(
			'ok'          => false,
			'method'      => '',
			'locked'      => false,
			'just_locked' => false,
			'remaining'   => 0,
			'retry_in'    => 0,
		);

		$locked = self::lock_remaining( $uid );
		if ( $locked > 0 ) {
			$out['locked']   = true;
			$out['retry_in'] = $locked;
			return $out;
		}

		$secret = self::secret( $uid );
		if ( '' !== $secret ) {
			$step = Totp::verify( $secret, $input, 1, self::now() );
			// A time step that was already accepted cannot be used again (replay of a sniffed code).
			if ( null !== $step && $step > (int) get_user_meta( $uid, self::META . 'counter', true ) ) {
				update_user_meta( $uid, self::META . 'counter', $step );
				self::clear_failures( $uid );
				$out['ok']     = true;
				$out['method'] = 'totp';
				return $out;
			}
		}
		if ( self::consume_recovery( $uid, $input ) ) {
			self::clear_failures( $uid );
			$out['ok']     = true;
			$out['method'] = 'recovery';
			return $out;
		}

		$out['just_locked'] = self::record_failure( $uid );
		$left               = self::lock_remaining( $uid );
		$out['locked']      = $left > 0;
		$out['retry_in']    = $left;
		$fail               = get_user_meta( $uid, self::META . 'fail', true );
		$out['remaining']   = max( 0, self::MAX_FAILURES - (int) ( is_array( $fail ) ? $fail['count'] : 0 ) );
		return $out;
	}

	/* ── Login that waits for the second step ── */

	/**
	 * Remember that the password of a user was right and the second step is next.
	 *
	 * @param int $uid User id.
	 * @return string The token to put into the second-step form (only its hash is stored).
	 */
	public static function issue_login_token( int $uid ): string {
		$token = bin2hex( random_bytes( 16 ) );
		update_user_meta(
			$uid,
			self::META . 'login',
			array(
				'hash'    => hash( 'sha256', $token ),
				'expires' => self::now() + self::LOGIN_TTL,
			)
		);
		return $token;
	}

	/**
	 * Is the token of the second-step form valid (known, not expired)? It stays valid for retries until it expires.
	 *
	 * @param int    $uid   User id.
	 * @param string $token Token from the form.
	 */
	public static function check_login_token( int $uid, string $token ): bool {
		$stored = get_user_meta( $uid, self::META . 'login', true );
		if ( ! is_array( $stored ) || '' === $token || self::now() > (int) ( $stored['expires'] ?? 0 ) ) {
			return false;
		}
		return hash_equals( (string) ( $stored['hash'] ?? '' ), hash( 'sha256', $token ) );
	}

	/**
	 * Drop the token (the login finished).
	 *
	 * @param int $uid User id.
	 */
	public static function clear_login_token( int $uid ): void {
		delete_user_meta( $uid, self::META . 'login' );
	}

	/* ── Role that requires 2FA ── */

	/**
	 * Does one of the user's roles require 2FA?
	 *
	 * @param string[] $user_roles     Roles of the user.
	 * @param string[] $required_roles Roles chosen in the settings.
	 */
	public static function role_requires( array $user_roles, array $required_roles ): bool {
		return (bool) array_intersect( array_map( 'strval', $user_roles ), array_map( 'strval', $required_roles ) );
	}

	/**
	 * Logins the user may still spend without a second factor.
	 *
	 * @param int $uid   User id.
	 * @param int $total Grace logins from the settings.
	 */
	public static function grace_left( int $uid, int $total ): int {
		return max( 0, $total - (int) get_user_meta( $uid, self::META . 'grace', true ) );
	}

	/**
	 * Spend one grace login.
	 *
	 * @param int $uid User id.
	 */
	public static function use_grace( int $uid ): void {
		update_user_meta( $uid, self::META . 'grace', (int) get_user_meta( $uid, self::META . 'grace', true ) + 1 );
	}

	/**
	 * Decide what a successful login of a user means for the role requirement and remember it.
	 * `none`: nothing required (or already set up). `grace`: required, not set up, this login is one of the
	 * postponed ones. `forced`: grace used up, from now on only the profile (setup) is reachable.
	 *
	 * @param int      $uid            User id.
	 * @param string[] $user_roles     Roles of the user.
	 * @param string[] $required_roles Roles chosen in the settings.
	 * @param int      $grace_total    Grace logins from the settings.
	 */
	public static function login_gate( int $uid, array $user_roles, array $required_roles, int $grace_total ): string {
		if ( ! self::role_requires( $user_roles, $required_roles ) || self::is_enabled( $uid ) ) {
			delete_user_meta( $uid, self::META . 'forced' );
			return 'none';
		}
		if ( self::grace_left( $uid, $grace_total ) > 0 ) {
			self::use_grace( $uid );
			delete_user_meta( $uid, self::META . 'forced' );
			return 'grace';
		}
		update_user_meta( $uid, self::META . 'forced', '1' );
		return 'forced';
	}

	/**
	 * Is the user in the forced setup state set by login_gate()? A configured factor ends it.
	 *
	 * @param int $uid User id.
	 */
	public static function is_forced( int $uid ): bool {
		return '1' === (string) get_user_meta( $uid, self::META . 'forced', true ) && ! self::is_enabled( $uid );
	}
}
