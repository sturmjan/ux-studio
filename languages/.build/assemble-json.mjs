// Builds the single handle-based JS translation file that WordPress loads first
// for wp_set_script_translations('ux-studio-app','ux-studio', languages/):
//   languages/ux-studio-cs_CZ-ux-studio-app.json
// It must contain EVERY string used anywhere in the JS bundle (main + all lazy
// chunks), because @wordpress/i18n loads one locale_data set per domain at once.
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );
const langDir = join( here, '..' );
const EOT = String.fromCharCode( 4 ); // gettext context separator

const entries = JSON.parse( readFileSync( join( here, 'strings.json' ), 'utf8' ) );
const outDir = join( here, 'out' );
const map = {};
for ( const f of readdirSync( outDir ) ) {
	if ( ! f.endsWith( '.json' ) ) continue;
	Object.assign( map, JSON.parse( readFileSync( join( outDir, f ), 'utf8' ) ) );
}

const messages = {
	'': {
		domain: 'messages',
		lang: 'cs_CZ',
		'plural-forms': 'nplurals=3; plural=(n==1) ? 0 : (n>=2 && n<=4) ? 1 : 2;',
	},
};

let count = 0;
for ( const e of entries ) {
	if ( ! e.js ) continue;
	const t = map[ String( e.i ) ];
	if ( ! t ) continue;
	const key = e.ctx ? e.ctx + EOT + e.id : e.id;
	messages[ key ] = e.plural ? [ t[ 0 ], t[ 1 ] ?? t[ 0 ], t[ 2 ] ?? t[ 0 ] ] : [ t[ 0 ] ];
	count++;
}

const json = {
	domain: 'messages',
	locale_data: { messages },
};

writeFileSync(
	join( langDir, 'ux-studio-cs_CZ-ux-studio-app.json' ),
	JSON.stringify( json )
);
console.log( 'WROTE ux-studio-cs_CZ-ux-studio-app.json with', count, 'JS strings' );
