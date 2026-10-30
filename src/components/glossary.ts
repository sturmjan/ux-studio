/**
 * Pure part of the glossary (no React), so it can be tested with plain node: term ids and the `[[term]]` markers of the
 * help texts. The components are in GlossaryTerm.tsx; the definitions come from PHP (Core\Glossary).
 */

export interface GlossaryEntry {
	term: string;
	text: string;
}

export interface GlossaryData {
	terms: Record< string, GlossaryEntry >;
	aliases: Record< string, string >;
}

/** One piece of a help text: plain text, or a marker with the term name and an optional shown text. */
export type HelpPiece = string | { name: string; shown?: string };

/** Same normalisation as Core\Glossary::id_of() (aliases are applied by findTerm). */
export function normalizeTermId( name: string ): string {
	return name
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/** The definition behind a marker text, or undefined. */
export function findTerm( name: string, data?: GlossaryData ): GlossaryEntry | undefined {
	const source = data ?? ( typeof window !== 'undefined' ? window.uxStudioBoot?.glossary : undefined );
	if ( ! source ) {
		return undefined;
	}
	const id = normalizeTermId( name );
	return source.terms[ source.aliases[ id ] ?? id ];
}

const MARKER = /\[\[([^\]|]+?)(?:\|([^\]]+?))?\]\]/g;

/** Split a help text into plain text and `[[term]]` / `[[term|shown text]]` markers. Text without markers is one piece. */
export function splitHelpText( text: string ): HelpPiece[] {
	if ( ! text.includes( '[[' ) ) {
		return [ text ];
	}
	const pieces: HelpPiece[] = [];
	let last = 0;
	for ( const match of text.matchAll( MARKER ) ) {
		const at = match.index ?? 0;
		if ( at > last ) {
			pieces.push( text.slice( last, at ) );
		}
		pieces.push( { name: match[ 1 ] ?? '', shown: match[ 2 ] } );
		last = at + match[ 0 ].length;
	}
	if ( last < text.length ) {
		pieces.push( text.slice( last ) );
	}
	return pieces;
}
