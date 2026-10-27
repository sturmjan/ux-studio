<?php
// Test only: never runs over HTTP (the release zip also excludes tests/).
if ( PHP_SAPI !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Standalone test of the passphrase encryption of the secrets block (libsodium argon2id + secretbox) and of the
 * guarantee that secrets never appear in the clear part of an export file.
 *
 *   php -d extension=sodium tests/transfer-crypto.php
 *
 * Without the sodium extension the encryption cases are reported as SKIP (the feature itself is switched off
 * on such a server, and the test checks that too). Exits non-zero when any case fails.
 *
 * @package UxStudio
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
define( 'UXSTUDIO_PATH', dirname( __DIR__ ) . '/' );

function __( $text, $domain = '' ) { return $text; }

require_once dirname( __DIR__ ) . '/includes/Autoloader.php';
\UxStudio\Autoloader::register();

use UxStudio\Core\Transfer\Crypto;
use UxStudio\Core\Transfer\Envelope;

$failures = 0;
$assert   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $detail ? '' : ' => ' . $detail ) . PHP_EOL;
};

/* ── Passphrase rules (pure) ── */

$assert( 'a passphrase under 12 characters is refused', 'short' === Crypto::check_new_passphrase( 'short-one', 'short-one' ) );
$assert( 'exactly 12 characters is fine', '' === Crypto::check_new_passphrase( 'abcdefghijkl', 'abcdefghijkl' ) );
$assert( 'twelve multibyte characters count as twelve', '' === Crypto::check_new_passphrase( 'žluťoučký kůň', 'žluťoučký kůň' ) );
$assert( 'a confirmation that differs is refused', 'mismatch' === Crypto::check_new_passphrase( 'abcdefghijkl', 'abcdefghijkm' ) );
$assert( 'an absurdly long passphrase is refused', 'long' === Crypto::check_new_passphrase( str_repeat( 'a', 2000 ), str_repeat( 'a', 2000 ) ) );
$assert( 'a block of the wrong shape is not valid', ! Crypto::valid_block( null ) && ! Crypto::valid_block( array() ) && ! Crypto::valid_block( array( 'kdf' => 'argon2id' ) ) );
$assert( 'a block that asks for absurd memory is not valid (no denial of service from a file)', ! Crypto::valid_block( array( 'kdf' => 'argon2id', 'v' => 1, 'opslimit' => 3, 'memlimit' => 8589934592, 'salt' => 'AAAA', 'nonce' => 'AAAA', 'ciphertext' => 'AAAA' ) ) );
$assert( 'a block with a different KDF is not valid', ! Crypto::valid_block( array( 'kdf' => 'md5', 'v' => 1, 'opslimit' => 3, 'memlimit' => 67108864, 'salt' => 'AAAA', 'nonce' => 'AAAA', 'ciphertext' => 'AAAA' ) ) );

if ( ! Crypto::available() ) {
	$assert( 'without sodium encrypt() says no instead of writing plain text', null === Crypto::encrypt( array( 'm' => array( 'k' => 'v' ) ), 'abcdefghijkl' ) );
	$assert( 'without sodium decrypt() says no', null === Crypto::decrypt( array(), 'abcdefghijkl' ) );
	echo 'SKIP encryption round trip (the sodium extension is not loaded; run with: php -d extension=sodium tests/transfer-crypto.php)' . PHP_EOL;
	echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED (encryption cases skipped)' : $failures . ' FAILED' ) . PHP_EOL;
	exit( 0 === $failures ? 0 : 1 );
}

/* ── Round trip ── */

$secrets = array(
	'smtp-email'  => array( 'password' => 'Pa$$w0rd-Žluťoučký', 'brevo_api_key' => 'xkeysib-1234567890abcdef' ),
	'stock-photos' => array( 'pexels_api_key' => 'PEXELS-SECRET-KEY' ),
);
$pass  = 'correct horse battery staple';
$block = Crypto::encrypt( $secrets, $pass );
$assert( 'encrypt returns a block', is_array( $block ) && Crypto::valid_block( $block ) );
$assert( 'the block names its KDF and version', 'argon2id' === $block['kdf'] && 1 === $block['v'] );
$assert( 'the block is the shape of the file format', array( 'kdf', 'v', 'opslimit', 'memlimit', 'salt', 'nonce', 'ciphertext' ) === array_keys( $block ) );
$assert( 'the right passphrase opens it', $secrets === Crypto::decrypt( $block, $pass ) );
$assert( 'a wrong passphrase fails (null, nothing leaks)', null === Crypto::decrypt( $block, 'correct horse battery stapleX' ) );
$assert( 'an empty passphrase fails', null === Crypto::decrypt( $block, '' ) );
$assert( 'a passphrase differing in case fails', null === Crypto::decrypt( $block, 'Correct horse battery staple' ) );

$flat = json_encode( $block );
foreach ( array( 'Pa$$w0rd', 'xkeysib', 'PEXELS-SECRET-KEY', 'smtp-email', 'brevo_api_key' ) as $needle ) {
	$assert( "the encrypted block does not contain \"$needle\"", false === strpos( $flat, $needle ) && false === strpos( base64_decode( $block['ciphertext'] ), $needle ) );
}

$other = Crypto::encrypt( $secrets, $pass );
$assert( 'a second encryption of the same data differs (random salt and nonce)', $other['salt'] !== $block['salt'] && $other['nonce'] !== $block['nonce'] && $other['ciphertext'] !== $block['ciphertext'] );
$assert( 'salt and nonce have the sizes libsodium wants', SODIUM_CRYPTO_PWHASH_SALTBYTES === strlen( base64_decode( $block['salt'] ) ) && SODIUM_CRYPTO_SECRETBOX_NONCEBYTES === strlen( base64_decode( $block['nonce'] ) ) );

/* ── Tampering ── */

$tamper = $block;
$raw    = base64_decode( $tamper['ciphertext'] );
$raw[0] = chr( ord( $raw[0] ) ^ 1 );
$tamper['ciphertext'] = base64_encode( $raw );
$assert( 'a flipped bit in the ciphertext is detected', null === Crypto::decrypt( $tamper, $pass ) );
$tamper = $block;
$tamper['salt'] = $other['salt'];
$assert( 'a swapped salt is detected', null === Crypto::decrypt( $tamper, $pass ) );
$tamper = $block;
$tamper['nonce'] = $other['nonce'];
$assert( 'a swapped nonce is detected', null === Crypto::decrypt( $tamper, $pass ) );
$tamper = $block;
$tamper['ciphertext'] = base64_encode( 'short' );
$assert( 'a truncated ciphertext is detected', null === Crypto::decrypt( $tamper, $pass ) );
$tamper = $block;
$tamper['ciphertext'] = '%%%not base64%%%';
$assert( 'a ciphertext that is not base64 is refused', null === Crypto::decrypt( $tamper, $pass ) );

/* ── Secrets never in the clear part of the file ── */

$env  = Envelope::build(
	array(
		'plugin_version' => '0.8.0',
		'modules'        => array(
			'smtp-email'   => array( 'settings' => array( 'host' => 'smtp.example.test', 'port' => 587 ), 'needs_manual' => array() ),
			'stock-photos' => array( 'settings' => array(), 'needs_manual' => array() ),
		),
		'secrets'        => $block,
	)
);
$text = Envelope::encode( $env );
$assert( 'the file says it has secrets', true === $env['options']['has_secrets'] );
foreach ( array( 'Pa$$w0rd', 'xkeysib-1234567890abcdef', 'PEXELS-SECRET-KEY', $pass ) as $needle ) {
	$assert( 'the whole file text does not contain "' . substr( $needle, 0, 8 ) . '..."', false === strpos( $text, $needle ) );
}
$parsed = Envelope::parse( $text, '0.8.0' );
$assert( 'the file with an encrypted block parses and the checksum matches', $parsed['ok'] && array() === $parsed['warnings'], json_encode( $parsed['warnings'] ?? array() ) );
$assert( 'the secrets from the parsed file open with the passphrase', $secrets === Crypto::decrypt( $parsed['envelope']['secrets'], $pass ) );
$assert( 'the checksum covers the secrets block', ! Envelope::parse( str_replace( substr( $block['ciphertext'], 0, 6 ), 'AAAAAA', $text ), '0.8.0' )['ok'] || 'checksum' === ( Envelope::parse( str_replace( substr( $block['ciphertext'], 0, 6 ), 'AAAAAA', $text ), '0.8.0' )['warnings'][0]['code'] ?? '' ) );

echo PHP_EOL . ( 0 === $failures ? 'ALL PASSED' : $failures . ' FAILED' ) . PHP_EOL;
exit( 0 === $failures ? 0 : 1 );
