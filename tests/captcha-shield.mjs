// Test only (Node): `node tests/captcha-shield.mjs`
// Loads the very file the pages and the worker use (assets/js/shield.js): SHA-256 against known vectors and against
// Node's own implementation, the solver, the main-thread slices, and a round trip with the PHP verifier.
import { createRequire } from 'node:module';
import { createHash, randomBytes } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const require = createRequire( import.meta.url );
const shield = require( join( here, '..', 'assets', 'js', 'shield.js' ) );

let failures = 0;
function check( name, actual, expected ) {
	const ok = actual === expected;
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + String( actual ) + ', expected ' + String( expected ) ) );
}
const sha = ( s ) => createHash( 'sha256' ).update( s ).digest( 'hex' );

// FIPS 180-4 / NIST vectors.
check( 'sha256 of the empty string', shield.sha256Hex( '' ), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855' );
check( 'sha256 of abc', shield.sha256Hex( 'abc' ), 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad' );
check( 'sha256 of the 56 byte NIST message (two blocks)', shield.sha256Hex( 'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq' ), '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1' );
check( 'sha256 of a million a', shield.sha256Hex( 'a'.repeat( 1000000 ) ), 'cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0' );

// Padding edge cases around the block boundary (55, 56, 63, 64, 65 bytes).
let edge = true;
for ( const n of [ 0, 1, 54, 55, 56, 57, 63, 64, 65, 119, 120, 128, 129 ] ) {
	edge = edge && shield.sha256Hex( 'x'.repeat( n ) ) === sha( 'x'.repeat( n ) );
}
check( 'padding edge lengths agree with Node', edge, true );

// The shape the solver hashes: 32 hex characters of salt plus a decimal number.
let shape = true;
for ( let i = 0; i < 300; i++ ) {
	const msg = randomBytes( 16 ).toString( 'hex' ) + String( Math.floor( Math.random() * 5000000 ) );
	shape = shape && shield.sha256Hex( msg ) === sha( msg );
}
check( '300 random salt+number messages agree with Node', shape, true );

// The solver finds the number, from the start, the middle and the very end of the range.
const salt = randomBytes( 16 ).toString( 'hex' );
for ( const secret of [ 0, 1, 777, 12345, 49999, 50000 ] ) {
	check( 'solveRange finds ' + secret, shield.solveRange( salt, sha( salt + secret ), 0, 50001 ), secret );
}
check( 'solveRange: a number outside the range is not found', shield.solveRange( salt, sha( salt + 60000 ), 0, 50001 ), -1 );
check( 'solveRange: a wrong challenge is not found', shield.solveRange( salt, 'f'.repeat( 64 ), 0, 1000 ), -1 );
check( 'solveRange refuses a salt that would not fit one block', shield.solveRange( 'a'.repeat( 60 ), sha( 'a'.repeat( 60 ) + '1' ), 0, 10 ), -2 );

// The slices of the main-thread fallback give the same answer.
await new Promise( ( resolve ) => {
	shield.solveChunked( salt, sha( salt + 43210 ), 50000, ( found ) => { check( 'solveChunked finds 43210 across slices', found, 43210 ); resolve(); } );
} );
await new Promise( ( resolve ) => {
	shield.solveChunked( salt, sha( salt + 50000 ), 50000, ( found ) => { check( 'solveChunked finds the last number', found, 50000 ); resolve(); } );
} );
await new Promise( ( resolve ) => {
	shield.solveChunked( salt, 'e'.repeat( 64 ), 30000, ( found ) => { check( 'solveChunked reports -1 when nothing fits', found, -1 ); resolve(); } );
} );

// Round trip with the PHP verifier: PHP issues, the browser code solves and encodes, PHP verifies.
const php = [ process.env.PHP_BIN, 'D:/xampp/php/php.exe', 'php' ].find( ( p ) => p && ( p === 'php' || existsSync( p ) ) );
if ( php ) {
	const run = ( code, ...args ) => execFileSync( php, [ '-d', 'display_errors=1', '-r', code, ...args ], { encoding: 'utf8' } ).trim();
	const bs = String.fromCharCode( 92 );
	const file = join( here, '..', 'includes', 'Modules', 'SecurityOptimization', 'CaptchaShield.php' ).split( bs ).join( '/' );
	const boot = "define('ABSPATH','/'); require " + JSON.stringify( file ) + '; use UxStudio' + bs + 'Modules' + bs + 'SecurityOptimization' + bs + 'CaptchaShield as S;';
	const challenge = JSON.parse( run( boot + "echo json_encode(S::issue('k', 150000, (int)(microtime(true)*1000) - 5000));" ) );
	const started = process.hrtime.bigint();
	const number = shield.solveRange( challenge.salt, challenge.challenge, 0, challenge.number_max + 1 );
	const ms = Number( process.hrtime.bigint() - started ) / 1e6;
	check( 'PHP challenge: the JS solver finds a number', number >= 0, true );
	const data = { algorithm: challenge.algorithm, challenge: challenge.challenge, number, salt: challenge.salt, signature: challenge.signature, issued: challenge.issued, expires: challenge.expires, number_max: challenge.number_max };
	const payload = Buffer.from( JSON.stringify( data ) ).toString( 'base64' ); // what btoa() gives for ASCII
	const verdict = run( boot + "echo json_encode(S::verify('k', \$argv[1], (int)(microtime(true)*1000), 1200, fn() => true));", payload );
	check( 'PHP accepts the answer built by the browser code', verdict, '""' );
	const bad = run( boot + "echo json_encode(S::verify('k', \$argv[1], (int)(microtime(true)*1000), 1200, fn() => true));", Buffer.from( JSON.stringify( { ...data, number: number + 1 } ) ).toString( 'base64' ) );
	check( 'PHP refuses a changed number', bad, '"solution"' );
	console.log( 'INFO solving medium (150000) took ' + ms.toFixed( 1 ) + ' ms for number ' + number );
} else {
	console.log( 'SKIP PHP round trip (no php binary)' );
}

console.log( failures ? failures + ' FAILED' : 'All passed' );
process.exit( failures ? 1 : 0 );
