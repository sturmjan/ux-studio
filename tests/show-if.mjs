// Test only (Node 22.18+): `node tests/show-if.mjs`
// The visibility evaluator of the generic settings form (src/app/showIf.ts), imported as the form uses it.
import { isVisible, isEmptyValue, visibleKeys } from '../src/app/showIf.ts';

let failures = 0;
function check( name, actual, expected ) {
	const ok = actual === expected;
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + String( actual ) + ', expected ' + String( expected ) ) );
}

const v = { type: 'video', on: true, off: false, n: 0, one: '1', list: [ 'a' ], none: [], text: '', size: 160 };
check( 'no condition is visible', isVisible( undefined, v ), true );
check( 'equals match', isVisible( { key: 'type', equals: 'video' }, v ), true );
check( 'equals miss', isVisible( { key: 'type', equals: 'image' }, v ), false );
check( 'equals compares as strings (1 and "1")', isVisible( { key: 'one', equals: 1 }, v ), true );
check( 'in match', isVisible( { key: 'type', in: [ 'image', 'video' ] }, v ), true );
check( 'in miss', isVisible( { key: 'type', in: [ 'image', 'youtube' ] }, v ), false );
check( 'in on a missing key', isVisible( { key: 'nope', in: [ 'image' ] }, v ), false );
check( 'truthy on a true toggle', isVisible( { key: 'on', truthy: true }, v ), true );
check( 'truthy defaults to true', isVisible( { key: 'on' }, v ), true );
check( 'truthy on a false toggle', isVisible( { key: 'off', truthy: true }, v ), false );
check( 'truthy false on a false toggle', isVisible( { key: 'off', truthy: false }, v ), true );
check( 'truthy false on a true toggle', isVisible( { key: 'on', truthy: false }, v ), false );
check( 'truthy: 0 is off', isVisible( { key: 'n', truthy: true }, v ), false );
check( 'truthy: non empty list is on', isVisible( { key: 'list', truthy: true }, v ), true );
check( 'truthy: empty list is off', isVisible( { key: 'none', truthy: true }, v ), false );
check( 'truthy: empty text is off', isVisible( { key: 'text', truthy: true }, v ), false );
check( 'truthy: missing key is off', isVisible( { key: 'nope', truthy: true }, v ), false );
check( 'truthy: a number above zero is on', isVisible( { key: 'size', truthy: true }, v ), true );
check( 'AND of conditions: all met', isVisible( [ { key: 'on' }, { key: 'type', equals: 'video' } ], v ), true );
check( 'AND of conditions: one missed', isVisible( [ { key: 'on' }, { key: 'type', equals: 'image' } ], v ), false );
check( 'empty list of conditions is visible', isVisible( [], v ), true );
check( 'isEmptyValue "0"', isEmptyValue( '0' ), true );
check( 'isEmptyValue "a"', isEmptyValue( 'a' ), false );

const schema = [
	{ key: 'master', show_if: undefined },
	{ key: 'mode', show_if: { key: 'master', truthy: true } },
	{ key: 'detail', show_if: [ { key: 'mode', equals: 'x' } ] },
	{ key: 'loop_a', show_if: { key: 'loop_b' } },
	{ key: 'loop_b', show_if: { key: 'loop_a' } },
];
const keys = ( values ) => [ ...visibleKeys( schema, values ) ].join( ',' );
check( 'chain: master off hides the whole chain', keys( { master: false, mode: 'x' } ), 'master' );
check( 'chain: master on, mode matches', keys( { master: true, mode: 'x' } ).startsWith( 'master,mode,detail' ), true );
check( 'chain: master on, mode differs hides detail only', keys( { master: true, mode: 'y' } ).startsWith( 'master,mode' ) && ! keys( { master: true, mode: 'y' } ).includes( 'detail' ), true );
check( 'a loop of conditions does not hang', typeof keys( {} ), 'string' );

if ( failures ) {
	console.log( '\nFAILED: ' + failures );
	process.exit( 1 );
}
console.log( '\nALL PASSED' );
