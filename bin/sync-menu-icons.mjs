/**
 * Copies the curated Lucide icon set used by the Menu Icons module from the
 * `lucide-static` package into includes/Modules/MenuIcons/assets/lucide/.
 *
 * Run `npm run icons:sync` after installing lucide-static as a devDependency
 * (`npm i -D lucide-static`) and whenever the allowlist below changes - it
 * must stay in sync with IconLibrary::ALLOWLIST (PHP).
 */
import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ICONS = [
	'house', 'mail', 'phone', 'map-pin', 'star', 'heart', 'user', 'users',
	'search', 'menu', 'x', 'check', 'chevron-right', 'chevron-down',
	'arrow-right', 'external-link', 'calendar', 'clock', 'globe',
	'facebook', 'instagram', 'linkedin', 'youtube', 'twitter',
	'download', 'upload', 'file-text', 'settings', 'info', 'bell',
	'lock', 'shopping-cart', 'tag', 'circle-help', 'message-circle',
];

const root = path.dirname( fileURLToPath( import.meta.url ) );
const src = path.resolve( root, '..', 'node_modules', 'lucide-static', 'icons' );
const dest = path.resolve( root, '..', 'includes', 'Modules', 'MenuIcons', 'assets', 'lucide' );

if ( ! existsSync( src ) ) {
	console.error( 'lucide-static not found - run `npm i -D lucide-static` first.' );
	process.exit( 1 );
}

mkdirSync( dest, { recursive: true } );

let copied = 0;
for ( const name of ICONS ) {
	const from = path.join( src, `${ name }.svg` );
	const to = path.join( dest, `${ name }.svg` );
	if ( ! existsSync( from ) ) {
		console.warn( `Missing in lucide-static: ${ name }.svg` );
		continue;
	}
	copyFileSync( from, to );
	copied++;
}

console.log( `Synced ${ copied }/${ ICONS.length } icons to ${ path.relative( process.cwd(), dest ) }` );
