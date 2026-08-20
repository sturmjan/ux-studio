// Splits strings.json into N chunk files under .build/chunks/ for parallel
// translation. Each chunk keeps only the fields a translator needs.
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const N = Number( process.argv[ 2 ] ?? 12 );
const all = JSON.parse( readFileSync( join( here, 'strings.json' ), 'utf8' ) );
const dir = join( here, 'chunks' );
rmSync( dir, { recursive: true, force: true } );
mkdirSync( dir, { recursive: true } );

const per = Math.ceil( all.length / N );
for ( let c = 0; c < N; c++ ) {
	const slice = all.slice( c * per, ( c + 1 ) * per ).map( ( e ) => {
		const o = { i: e.i, id: e.id };
		if ( e.ctx ) o.ctx = e.ctx;
		if ( e.plural ) o.plural = e.plural;
		return o;
	} );
	if ( ! slice.length ) continue;
	const name = 'chunk-' + String( c ).padStart( 2, '0' ) + '.json';
	writeFileSync( join( dir, name ), JSON.stringify( slice, null, 0 ) );
	console.log( name, slice.length );
}
