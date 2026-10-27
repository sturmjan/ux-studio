<?php
/**
 * Shield of UX Studio: a key-less proof-of-work CAPTCHA (the protocol and the decisions, no WordPress calls).
 *
 * @package UxStudio
 */

namespace UxStudio\Modules\SecurityOptimization;

defined( 'ABSPATH' ) || exit;

/**
 * Follows the open ALTCHA idea, implemented natively. The server hands out a puzzle, the browser finds the number
 * that solves it, the server checks the answer and burns the puzzle. Pure functions: the secret, the clock and the
 * "has this puzzle been used" store are passed in, so the whole protocol is tested without WordPress
 * (tests/captcha-shield.php). The WordPress side (secret, storage, REST route, widget) is ShieldRuntime.
 *
 * Challenge (JSON, as the REST route returns it):
 *
 *   algorithm   'SHA-256'
 *   salt        32 hex characters, random per challenge
 *   number_max  the secret number is in [0, number_max] (this is the difficulty)
 *   challenge   hex sha256( salt . number ) for a random secret number
 *   issued      milliseconds since the epoch, when the server issued it
 *   expires     seconds since the epoch, after which it is dead
 *   signature   hex HMAC-SHA256( secret, 'v1|algorithm|challenge|salt|number_max|issued|expires' )
 *
 * The browser answers with base64( JSON { algorithm, challenge, number, salt, signature, issued, expires, number_max } ).
 * The signature covers every number the client sends back, so none of them can be changed. The server accepts the
 * answer only when the signature is right, the challenge is alive and old enough (the fill time rule), the number
 * really solves it and nobody used this challenge before (one-time use).
 *
 * An honest note on strength: a proof of work does not tell a human from a script. It makes every automatic attempt
 * cost some computing time and rules out replaying one answer, which stops mass scripted attempts. A determined
 * attacker with a computer passes, only slower.
 */
final class CaptchaShield {

	public const ALGORITHM = 'SHA-256';

	/** Name of the form field / request parameter with the answer of the browser. */
	public const FIELD = 'uxstudio_shield';

	/** Honeypot input: invisible to people, bots that fill every input fill this one too. */
	public const TRAP_FIELD = 'uxs_trap';

	/** No-JS fallback: the typed answer and the signed token that carries the question. */
	public const NOJS_FIELD       = 'uxstudio_shield_nojs';
	public const NOJS_TOKEN_FIELD = 'uxstudio_shield_nojs_token';

	/** How long a challenge lives, in seconds. */
	public const TTL = 300;

	/** Fill time: a challenge answered sooner than this after it was issued is refused (milliseconds). */
	public const DEFAULT_MIN_MS = 1200;
	public const MAX_MIN_MS     = 10000;

	/** The no-JS question needs a person to read it, so it has its own, longer minimum (seconds) and lifetime. */
	public const NOJS_MIN_S = 3;
	public const NOJS_TTL_S = 21600;

	/** Hard limits on what the verifier will even look at. */
	private const MAX_PAYLOAD_BYTES = 1024;
	private const MAX_NUMBER        = 5000000;

	/** Difficulty levels: highest possible secret number. The browser needs about half of it on average. */
	public const LEVELS = array(
		'easy'   => 50000,
		'medium' => 150000,
		'hard'   => 500000,
	);

	public const DEFAULT_LEVEL = 'medium';

	/* ── Difficulty ── */

	/**
	 * Level name from a setting value (anything unknown is the default).
	 *
	 * @param mixed $level Raw value.
	 */
	public static function normalize_level( $level ): string {
		$level = is_string( $level ) ? $level : '';
		return isset( self::LEVELS[ $level ] ) ? $level : self::DEFAULT_LEVEL;
	}

	/**
	 * Highest secret number of a level.
	 *
	 * @param mixed $level Level name.
	 */
	public static function number_max( $level ): int {
		return self::LEVELS[ self::normalize_level( $level ) ];
	}

	/**
	 * The level to use for this visitor: one step harder when the address had recent failed logins.
	 *
	 * @param string $level  Configured level.
	 * @param bool   $raised The address has recent failures.
	 */
	public static function effective_level( string $level, bool $raised ): string {
		$level = self::normalize_level( $level );
		if ( ! $raised ) {
			return $level;
		}
		$order = array_keys( self::LEVELS );
		$next  = min( count( $order ) - 1, (int) array_search( $level, $order, true ) + 1 );
		return $order[ $next ];
	}

	/**
	 * Fill time setting (seconds, may be a fraction) to milliseconds, kept in the allowed range. 0 switches the rule off.
	 *
	 * @param mixed $seconds Raw setting.
	 */
	public static function min_ms( $seconds ): int {
		if ( ! is_numeric( $seconds ) ) {
			return self::DEFAULT_MIN_MS;
		}
		return (int) max( 0, min( self::MAX_MIN_MS, round( (float) $seconds * 1000 ) ) );
	}

	/* ── Challenge ── */

	/**
	 * Create a challenge.
	 *
	 * @param string   $secret     Server secret (never leaves the server).
	 * @param int      $number_max Difficulty.
	 * @param int      $now_ms     Now, in milliseconds.
	 * @param int|null $number     The secret number; null = random (a parameter only for tests).
	 * @param string   $salt       Salt; empty = random (a parameter only for tests).
	 * @return array{algorithm:string,salt:string,number_max:int,challenge:string,issued:int,expires:int,signature:string}
	 */
	public static function issue( string $secret, int $number_max, int $now_ms, ?int $number = null, string $salt = '' ): array {
		$number_max = max( 1, min( self::MAX_NUMBER, $number_max ) );
		$salt       = '' !== $salt ? $salt : bin2hex( random_bytes( 16 ) );
		$number     = null === $number ? random_int( 0, $number_max ) : max( 0, min( $number_max, $number ) );
		$challenge  = array(
			'algorithm'  => self::ALGORITHM,
			'salt'       => $salt,
			'number_max' => $number_max,
			'challenge'  => hash( 'sha256', $salt . $number ),
			'issued'     => $now_ms,
			'expires'    => intdiv( $now_ms, 1000 ) + self::TTL,
		);
		$challenge['signature'] = self::sign( $secret, $challenge );
		return $challenge;
	}

	/**
	 * Signature over everything the browser sends back (the number it found is not part of it: the proof is the hash).
	 *
	 * @param string              $secret    Server secret.
	 * @param array<string,mixed> $challenge Challenge fields.
	 */
	public static function sign( string $secret, array $challenge ): string {
		$message = implode(
			'|',
			array(
				'v1',
				(string) ( $challenge['algorithm'] ?? '' ),
				(string) ( $challenge['challenge'] ?? '' ),
				(string) ( $challenge['salt'] ?? '' ),
				(string) (int) ( $challenge['number_max'] ?? 0 ),
				(string) (int) ( $challenge['issued'] ?? 0 ),
				(string) (int) ( $challenge['expires'] ?? 0 ),
			)
		);
		return hash_hmac( 'sha256', $message, $secret );
	}

	/**
	 * Check the answer of a browser.
	 *
	 * @param string   $secret  Server secret.
	 * @param string   $payload The posted base64 JSON.
	 * @param int      $now_ms  Now, in milliseconds.
	 * @param int      $min_ms  Fill time rule in milliseconds (0 = off).
	 * @param callable $consume fn( string $id, int $ttl ): bool - true on the FIRST use of this challenge id.
	 * @return string '' when the answer is good, otherwise a code: missing | malformed | algorithm | signature | expired | toosoon | solution | replay
	 */
	public static function verify( string $secret, string $payload, int $now_ms, int $min_ms, callable $consume ): string {
		$payload = trim( $payload );
		if ( '' === $payload ) {
			return 'missing';
		}
		if ( strlen( $payload ) > self::MAX_PAYLOAD_BYTES ) {
			return 'malformed';
		}
		$json = base64_decode( strtr( $payload, '-_', '+/' ), true );
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return 'malformed';
		}

		foreach ( array( 'algorithm', 'challenge', 'salt', 'signature' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
				return 'malformed';
			}
		}
		foreach ( array( 'number', 'number_max', 'issued', 'expires' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_int( $data[ $key ] ) ) {
				return 'malformed';
			}
		}
		if ( ! hash_equals( self::ALGORITHM, $data['algorithm'] ) ) {
			return 'algorithm';
		}
		if (
			! preg_match( '/^[a-f0-9]{32}$/', $data['salt'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $data['challenge'] )
			|| ! preg_match( '/^[a-f0-9]{64}$/', $data['signature'] )
			|| $data['number'] < 0
			|| $data['number_max'] < 1
			|| $data['number'] > $data['number_max']
			|| $data['number_max'] > self::MAX_NUMBER
		) {
			return 'malformed';
		}

		// Signature first: nothing below is trusted before this passes. Constant-time compare.
		if ( ! hash_equals( self::sign( $secret, $data ), $data['signature'] ) ) {
			return 'signature';
		}
		if ( $data['issued'] > $now_ms + 5000 ) {
			return 'malformed'; // Issued in the future (signed by us, so only a clock jump explains it).
		}
		if ( $data['expires'] * 1000 < $now_ms ) {
			return 'expired';
		}
		if ( $min_ms > 0 && $now_ms - $data['issued'] < $min_ms ) {
			return 'toosoon';
		}
		// The proof itself.
		if ( ! hash_equals( $data['challenge'], hash( 'sha256', $data['salt'] . $data['number'] ) ) ) {
			return 'solution';
		}
		// Burn it, last: a rejected answer must not use up the challenge of a good one.
		$id  = hash( 'sha256', $data['challenge'] . '|' . $data['salt'] );
		$ttl = max( 60, $data['expires'] - intdiv( $now_ms, 1000 ) + 60 );
		if ( ! $consume( $id, $ttl ) ) {
			return 'replay';
		}
		return '';
	}

	/**
	 * Encode an answer the way the browser does (used by tests and by the server-side tools).
	 *
	 * @param array<string,mixed> $challenge Challenge from issue().
	 * @param int                 $number    Found number.
	 */
	public static function answer( array $challenge, int $number ): string {
		$data = array(
			'algorithm'  => (string) $challenge['algorithm'],
			'challenge'  => (string) $challenge['challenge'],
			'number'     => $number,
			'salt'       => (string) $challenge['salt'],
			'signature'  => (string) $challenge['signature'],
			'issued'     => (int) $challenge['issued'],
			'expires'    => (int) $challenge['expires'],
			'number_max' => (int) $challenge['number_max'],
		);
		return base64_encode( (string) json_encode( $data ) );
	}

	/**
	 * Brute force a challenge (what the browser does). Used by the tests and for timing.
	 *
	 * @param array<string,mixed> $challenge Challenge from issue().
	 * @return int The number, or -1.
	 */
	public static function solve( array $challenge ): int {
		$salt   = (string) $challenge['salt'];
		$target = (string) $challenge['challenge'];
		$max    = (int) $challenge['number_max'];
		for ( $n = 0; $n <= $max; $n++ ) {
			if ( hash( 'sha256', $salt . $n ) === $target ) {
				return $n;
			}
		}
		return -1;
	}

	/* ── Fallback without JavaScript ── */

	/**
	 * A signed question. The question is derived from the nonce and the secret, so nothing about the answer is in the
	 * token; the token is `issued.nonce.mac`.
	 *
	 * @param string $secret  Server secret.
	 * @param int    $now_s   Now, in seconds.
	 * @param string $nonce   Nonce; empty = random (a parameter only for tests).
	 * @return array{token:string,a:int,b:int}
	 */
	public static function nojs_issue( string $secret, int $now_s, string $nonce = '' ): array {
		$nonce = '' !== $nonce ? $nonce : bin2hex( random_bytes( 8 ) );
		$mac   = hash_hmac( 'sha256', 'nojs|' . $now_s . '|' . $nonce, $secret );
		list( $a, $b ) = self::nojs_numbers( $secret, $nonce );
		return array(
			'token' => $now_s . '.' . $nonce . '.' . $mac,
			'a'     => $a,
			'b'     => $b,
		);
	}

	/**
	 * The two numbers of a question.
	 *
	 * @param string $secret Server secret.
	 * @param string $nonce  Nonce of the token.
	 * @return array{0:int,1:int}
	 */
	public static function nojs_numbers( string $secret, string $nonce ): array {
		$h = hash_hmac( 'sha256', 'nojs-q|' . $nonce, $secret, true );
		return array( 3 + ( ord( $h[0] ) % 17 ), 3 + ( ord( $h[1] ) % 17 ) );
	}

	/**
	 * Check a typed answer.
	 *
	 * @param string   $secret  Server secret.
	 * @param string   $token   The posted token.
	 * @param string   $answer  What was typed.
	 * @param int      $now_s   Now, in seconds.
	 * @param callable $consume fn( string $id, int $ttl ): bool - true on the first use.
	 * @return string '' when good, otherwise missing | malformed | signature | expired | toosoon | solution | replay
	 */
	public static function nojs_verify( string $secret, string $token, string $answer, int $now_s, callable $consume ): string {
		$token  = trim( $token );
		$answer = trim( $answer );
		if ( '' === $token || '' === $answer ) {
			return 'missing';
		}
		if ( ! preg_match( '/^(\d{1,12})\.([a-f0-9]{16})\.([a-f0-9]{64})$/', $token, $m ) ) {
			return 'malformed';
		}
		$issued = (int) $m[1];
		$expect = hash_hmac( 'sha256', 'nojs|' . $issued . '|' . $m[2], $secret );
		if ( ! hash_equals( $expect, $m[3] ) ) {
			return 'signature';
		}
		if ( $issued + self::NOJS_TTL_S < $now_s ) {
			return 'expired';
		}
		if ( $now_s - $issued < self::NOJS_MIN_S ) {
			return 'toosoon';
		}
		list( $a, $b ) = self::nojs_numbers( $secret, $m[2] );
		if ( ! preg_match( '/^\d{1,3}$/', $answer ) || ! hash_equals( (string) ( $a + $b ), (string) (int) $answer ) ) {
			return 'solution';
		}
		if ( ! $consume( hash( 'sha256', 'nojs|' . $m[2] ), self::NOJS_TTL_S ) ) {
			return 'replay';
		}
		return '';
	}

	/**
	 * Which forms may fall back to the typed question. Comments are left out: their page is often kept in a page cache,
	 * where one token would be shown to many visitors and burn after the first, and the forms of the Forms module are
	 * sent by JavaScript anyway.
	 *
	 * @param string $context Where the check runs: login, register, lostpassword, gate, woo_login, woo_register,
	 *                        woo_lostpassword, woo_checkout, comment, forms.
	 */
	public static function nojs_allowed( string $context ): bool {
		return ! in_array( $context, array( 'comment', 'forms' ), true );
	}

	/**
	 * Decide a whole request: the JS answer when it was posted, else (only then) the typed question, and the honeypot
	 * before both. Pure, so the lockout rules for the login form are tested as a table.
	 *
	 * @param array<string,string> $req     Posted values: shield, trap, nojs, nojs_token.
	 * @param string               $context Context (see nojs_allowed()).
	 * @param callable             $js      fn( string $payload ): string - verify() verdict ('' = good).
	 * @param callable             $nojs    fn( string $token, string $answer ): string - nojs_verify() verdict.
	 * @return string '' when the request passes, otherwise the reason: trap | <js code> | nojs_<code> | nojs_off
	 */
	public static function decide( array $req, string $context, callable $js, callable $nojs ): string {
		if ( '' !== trim( (string) ( $req['trap'] ?? '' ) ) ) {
			return 'trap';
		}
		$payload = trim( (string) ( $req['shield'] ?? '' ) );
		if ( '' !== $payload ) {
			return $js( $payload );
		}
		if ( ! self::nojs_allowed( $context ) ) {
			return 'missing';
		}
		$verdict = $nojs( (string) ( $req['nojs_token'] ?? '' ), (string) ( $req['nojs'] ?? '' ) );
		return '' === $verdict ? '' : 'nojs_' . $verdict;
	}
}
