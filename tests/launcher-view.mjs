// Test only: `node tests/launcher-view.mjs`
// The remembered display of the launcher overlay (assets/js/megamenu.js): only 'grid' | 'list' are valid values.
import { createRequire } from 'node:module';
const { normalizeView, VIEW_KEY } = createRequire( import.meta.url )( '../assets/js/megamenu.js' );

let failures = 0;
function check( name, actual, expected ) {
	const ok = actual === expected;
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + String( actual ) + ', expected ' + String( expected ) ) );
}

check( 'list is kept', normalizeView( 'list' ), 'list' );
check( 'grid is kept', normalizeView( 'grid' ), 'grid' );
check( 'null (nothing stored) is the default grid', normalizeView( null ), 'grid' );
check( 'undefined is the default grid', normalizeView( undefined ), 'grid' );
check( 'empty string is the default grid', normalizeView( '' ), 'grid' );
check( 'unknown word is the default grid', normalizeView( 'table' ), 'grid' );
check( 'case matters (LIST is not valid)', normalizeView( 'LIST' ), 'grid' );
check( 'padded value is not valid', normalizeView( ' list ' ), 'grid' );
check( 'object is the default grid', normalizeView( { toString: () => 'list' } ), 'grid' );
check( 'storage key', VIEW_KEY, 'uxstudio-launcher-view' );

if ( failures ) { console.log( failures + ' failure(s)' ); process.exit( 1 ); }
console.log( 'All launcher view checks passed' );
