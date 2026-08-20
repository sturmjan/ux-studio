// Parses ux-studio.pot into a structured JSON list of entries and appends the
// module meta (name/description) strings from every module's meta.json.
// Output: languages/.build/strings.json  — [{i, ctx, id, plural, js, php, meta}]
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const langDir = join( here, '..' );
const pluginDir = join( langDir, '..' );

const pot = readFileSync( join( langDir, 'ux-studio.pot' ), 'utf8' );

// --- minimal PO/POT parser ---
function unquote( line ) {
	const m = line.match( /"((?:[^"\\]|\\.)*)"/ );
	if ( ! m ) return '';
	return m[ 1 ].replace( /\\n/g, '\n' ).replace( /\\t/g, '\t' ).replace( /\\"/g, '"' ).replace( /\\\\/g, '\\' );
}

const blocks = pot.split( /\n\n+/ );
const entries = [];
const seen = new Set();
for ( const block of blocks ) {
	const lines = block.split( '\n' );
	let ctx = null, id = null, plural = null;
	const refs = [];
	let mode = null;
	for ( const line of lines ) {
		if ( line.startsWith( '#: ' ) ) {
			refs.push( line.slice( 3 ).trim() );
		} else if ( line.startsWith( 'msgctxt ' ) ) {
			ctx = unquote( line ); mode = 'ctx';
		} else if ( line.startsWith( 'msgid_plural ' ) ) {
			plural = unquote( line ); mode = 'plural';
		} else if ( line.startsWith( 'msgid ' ) ) {
			id = unquote( line ); mode = 'id';
		} else if ( line.startsWith( 'msgstr' ) ) {
			mode = 'str';
		} else if ( line.startsWith( '"' ) ) {
			const t = unquote( line );
			if ( mode === 'ctx' ) ctx += t;
			else if ( mode === 'plural' ) plural += t;
			else if ( mode === 'id' ) id += t;
		}
	}
	if ( id === null || id === '' ) continue; // skip header / empties
	const key = ( ctx ?? '' ) + '' + id;
	if ( seen.has( key ) ) continue;
	seen.add( key );
	const refStr = refs.join( ' ' );
	const js = /\.(tsx|ts|jsx|js)(:|\b)/.test( refStr );
	const php = /\.php(:|\b)/.test( refStr );
	entries.push( { ctx, id, plural, js, php, meta: false } );
}

// --- append module meta strings (name + description from meta.json) ---
const modulesDir = join( pluginDir, 'includes', 'Modules' );
for ( const d of readdirSync( modulesDir, { withFileTypes: true } ) ) {
	if ( ! d.isDirectory() ) continue;
	let metaRaw;
	try {
		metaRaw = readFileSync( join( modulesDir, d.name, 'meta.json' ), 'utf8' );
	} catch { continue; }
	let meta;
	try { meta = JSON.parse( metaRaw ); } catch { continue; }
	for ( const field of [ 'name', 'description' ] ) {
		const val = meta[ field ];
		if ( ! val ) continue;
		const key = '' + val;
		if ( seen.has( key ) ) continue;
		seen.add( key );
		entries.push( { ctx: null, id: val, plural: null, js: false, php: true, meta: true } );
	}
}

entries.forEach( ( e, i ) => ( e.i = i ) );
writeFileSync( join( here, 'strings.json' ), JSON.stringify( entries, null, 0 ) );
const jsCount = entries.filter( ( e ) => e.js ).length;
const metaCount = entries.filter( ( e ) => e.meta ).length;
const pluralCount = entries.filter( ( e ) => e.plural ).length;
console.log( `entries=${ entries.length} js=${ jsCount } meta=${ metaCount } plural=${ pluralCount }` );
