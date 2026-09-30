/**
 * Minifies the public AI chat widget assets (served on every frontend page,
 * outside the webpack/SPA build): writes a `.min` file next to each source.
 * ChatBootstrap serves the `.min` files unless SCRIPT_DEBUG is on.
 *
 * Run: npm run build:widget  (terser + csso come with @wordpress/scripts)
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { minify as minifyJs } from 'terser';
import { minify as minifyCss } from 'csso';

const root = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );

const jsFiles = [ 'assets/js/ai-assistant-widget.js', 'assets/js/ai-assistant-widget-loader.js' ];
const cssFiles = [ 'assets/css/ai-assistant-widget.css' ];

const minName = ( file ) => file.replace( /\.(js|css)$/, '.min.$1' );
const report = ( file, before, after ) =>
	console.log( `${ minName( file ) }: ${ ( before / 1024 ).toFixed( 1 ) } kB -> ${ ( after / 1024 ).toFixed( 1 ) } kB` );

for ( const file of jsFiles ) {
	const source = readFileSync( resolve( root, file ), 'utf8' );
	const result = await minifyJs( source, { compress: true, mangle: true, format: { comments: false } } );
	writeFileSync( resolve( root, minName( file ) ), result.code );
	report( file, source.length, result.code.length );
}

for ( const file of cssFiles ) {
	const source = readFileSync( resolve( root, file ), 'utf8' );
	const { css } = minifyCss( source );
	writeFileSync( resolve( root, minName( file ) ), css );
	report( file, source.length, css.length );
}
