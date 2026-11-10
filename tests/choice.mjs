// Test only (Node 22.18+): `node tests/choice.mjs`
// Which value a select / image_choice field shows (src/app/choice.ts): never "nothing chosen" when there is a default.
import { choiceValue } from '../src/app/choice.ts';

let failures = 0;
function check( name, actual, expected ) {
	const ok = actual === expected;
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + String( actual ) + ', expected ' + String( expected ) ) );
}

const f = { options: { classic: 'Classic', slim: 'Slim' }, default: 'classic' };
check( 'stored option is shown', choiceValue( f, 'slim' ), 'slim' );
check( 'empty string shows the default', choiceValue( f, '' ), 'classic' );
check( 'null shows the default', choiceValue( f, null ), 'classic' );
check( 'undefined shows the default', choiceValue( f, undefined ), 'classic' );
check( 'a value that is not an option shows the default', choiceValue( f, 'gone' ), 'classic' );
check( 'no default: empty stays empty (placeholder)', choiceValue( { options: f.options }, '' ), '' );
check( 'default that is not an option is ignored', choiceValue( { options: f.options, default: 'x' }, '' ), '' );
check( 'numeric keys compare as strings', choiceValue( { options: { 12: 'Page' }, default: 12 }, 12 ), '12' );

if ( failures ) { console.log( failures + ' failed' ); process.exit( 1 ); }
console.log( 'OK' );
