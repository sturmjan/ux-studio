// Merges the per-chunk translations back onto strings.json, validates
// completeness + placeholder integrity, and writes languages/ux-studio-cs_CZ.po.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const langDir = join( here, '..' );

const entries = JSON.parse( readFileSync( join( here, 'strings.json' ), 'utf8' ) );
const outDir = join( here, 'out' );
const map = {};
for ( const f of readdirSync( outDir ) ) {
	if ( ! f.endsWith( '.json' ) ) continue;
	const obj = JSON.parse( readFileSync( join( outDir, f ), 'utf8' ) );
	for ( const [ k, v ] of Object.entries( obj ) ) {
		map[ k ] = v;
	}
}

// --- validate ---
const missing = [];
const placeholderIssues = [];
const phRe = /%(\d+\$)?[sd]|%%/g;
function phCount( s ) {
	return ( s.match( phRe ) || [] ).sort().join( ',' );
}
for ( const e of entries ) {
	const t = map[ String( e.i ) ];
	if ( ! t || ! Array.isArray( t ) || t.length === 0 || t.some( ( x ) => typeof x !== 'string' || x === '' ) ) {
		missing.push( e.i );
		continue;
	}
	const src = phCount( e.id );
	for ( const form of t ) {
		if ( phCount( form ) !== src && ! e.plural ) {
			placeholderIssues.push( { i: e.i, id: e.id, form, src, got: phCount( form ) } );
			break;
		}
	}
}

console.log( 'total', entries.length, 'translated', Object.keys( map ).length, 'missing', missing.length );
if ( missing.length ) {
	console.log( 'MISSING indices:', missing.slice( 0, 50 ).join( ',' ) );
}
if ( placeholderIssues.length ) {
	console.log( 'PLACEHOLDER MISMATCHES:', placeholderIssues.length );
	for ( const p of placeholderIssues.slice( 0, 30 ) ) {
		console.log( `  [${ p.i }] src(${ p.src }) got(${ p.got }) :: "${ p.id }" -> "${ p.form }"` );
	}
}
if ( missing.length ) {
	console.log( 'ABORTING .po write due to missing translations.' );
	process.exit( 1 );
}

// --- write .po ---
function esc( s ) {
	return s
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\n/g, '\\n' )
		.replace( /\t/g, '\\t' );
}

const header = `msgid ""
msgstr ""
"Project-Id-Version: UX Studio\\n"
"Report-Msgid-Bugs-To: \\n"
"Last-Translator: UX Studio\\n"
"Language-Team: Czech\\n"
"Language: cs_CZ\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;\\n"
"X-Domain: ux-studio\\n"
`;

const parts = [ header ];
for ( const e of entries ) {
	const t = map[ String( e.i ) ];
	const block = [];
	if ( e.ctx ) block.push( `msgctxt "${ esc( e.ctx ) }"` );
	block.push( `msgid "${ esc( e.id ) }"` );
	if ( e.plural ) {
		block.push( `msgid_plural "${ esc( e.plural ) }"` );
		const forms = t.length >= 3 ? t : [ t[ 0 ], t[ 0 ], t[ 0 ] ];
		for ( let n = 0; n < 3; n++ ) {
			block.push( `msgstr[${ n }] "${ esc( forms[ n ] ) }"` );
		}
	} else {
		block.push( `msgstr "${ esc( t[ 0 ] ) }"` );
	}
	parts.push( block.join( '\n' ) );
}

writeFileSync( join( langDir, 'ux-studio-cs_CZ.po' ), parts.join( '\n\n' ) + '\n' );
console.log( 'WROTE ux-studio-cs_CZ.po' );
