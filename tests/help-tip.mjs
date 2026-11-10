// Test only (Node 22.18+): `node tests/help-tip.mjs`
// The open / pinned state machine and the placement of the help popover (src/components/help-tip-logic.ts).
import { CLOSED, helpTipReducer as r, placeHelpTip as place } from '../src/components/help-tip-logic.ts';

let failures = 0;
function check( name, actual, expected ) {
	const ok = JSON.stringify( actual ) === JSON.stringify( expected );
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + JSON.stringify( actual ) + ', expected ' + JSON.stringify( expected ) ) );
}

// State machine.
check( 'hover opens a peek', r( CLOSED, { type: 'peek' } ), { open: true, pinned: false } );
check( 'leaving closes a peek', r( { open: true, pinned: false }, { type: 'release' } ), CLOSED );
check( 'leaving does not close a pinned window', r( { open: true, pinned: true }, { type: 'release' } ), { open: true, pinned: true } );
check( 'hovering a pinned window keeps it pinned', r( { open: true, pinned: true }, { type: 'peek' } ), { open: true, pinned: true } );
check( 'a click on a peek pins it', r( { open: true, pinned: false }, { type: 'toggle' } ), { open: true, pinned: true } );
check( 'a click on a closed button opens it pinned (touch)', r( CLOSED, { type: 'toggle' } ), { open: true, pinned: true } );
check( 'a second click closes it', r( { open: true, pinned: true }, { type: 'toggle' } ), CLOSED );
check( 'a click inside a peek pins it', r( { open: true, pinned: false }, { type: 'pin' } ), { open: true, pinned: true } );
check( 'a click inside while closed does nothing', r( CLOSED, { type: 'pin' } ), CLOSED );
check( 'Escape / outside click closes even a pinned window', r( { open: true, pinned: true }, { type: 'close' } ), CLOSED );

// Placement. Viewport 1000 x 800, window 440 x 200, button 18 x 18.
const vp = { width: 1000, height: 800 };
const win = { width: 460, height: 200 };
const btn = ( left, top ) => ( { left, top, width: 18, height: 18 } );
let p = place( btn( 900, 100 ), win, vp );
check( 'button at the right: below, right edges together', [ p.side, p.left + p.width, p.top ], [ 'below', 918, 126 ] );
p = place( btn( 20, 100 ), win, vp );
check( 'button at the left: left edges together', [ p.left, p.width ], [ 20, 460 ] );
p = place( btn( 990, 100 ), win, vp );
check( 'never leaves the right edge', p.left + p.width <= 1000 - 12, true );
p = place( btn( 900, 700 ), win, vp );
check( 'no room below: above the button', [ p.side, p.top + 200 + 8 ], [ 'above', 700 ] );
p = place( btn( 900, 380 ), { width: 460, height: 700 }, vp );
check( 'fits neither side: the larger side with a max height', [ p.side, p.maxHeight !== null && p.maxHeight < 700 ], [ 'below', true ] );
p = place( btn( 300, 100 ), win, { width: 390, height: 800 } );
check( 'phone: spans the width minus the margins', [ p.left, p.width ], [ 12, 366 ] );
p = place( btn( 300, 100 ), { width: 200, height: 50 }, vp );
check( 'a small window keeps its own width', p.width, 200 );

console.log( failures ? failures + ' FAILED' : 'ALL PASSED' );
process.exit( failures ? 1 : 0 );
