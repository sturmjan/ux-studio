/**
 * Glossary: a technical term with a small "?" that opens a plain-language definition.
 *
 * Help texts mark terms with `[[CSP]]` or `[[term|shown text]]` (see docs/help-texts.md); <HelpText> turns the
 * markers into <GlossaryTerm>. The definitions come from PHP (Core\Glossary) with the boot data of the page, in the
 * language of the user. A marker that matches no term is drawn as plain text.
 *
 * The popover is react-aria's, so it is a real dialog: the "?" is focusable, Enter / Space open it, Esc closes it
 * and returns the focus, a click outside closes it.
 */
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Button, Dialog, DialogTrigger, Popover } from 'react-aria-components';
import { __, sprintf } from '@wordpress/i18n';

export interface GlossaryEntry {
	term: string;
	text: string;
}

export interface GlossaryData {
	terms: Record< string, GlossaryEntry >;
	aliases: Record< string, string >;
}

/** Same normalisation as Core\Glossary::id_of() (aliases are applied by the caller). */
export function normalizeTermId( name: string ): string {
	return name
		.trim()
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}

/** The definition behind a marker text, or undefined. */
export function findTerm( name: string, data: GlossaryData | undefined = window.uxStudioBoot?.glossary ): GlossaryEntry | undefined {
	if ( ! data ) {
		return undefined;
	}
	const id = normalizeTermId( name );
	return data.terms[ data.aliases[ id ] ?? id ];
}

interface GlossaryTermProps {
	/** Term id or name, as written in the marker (`CSP`, `REST API`). */
	term: string;
	/** Text shown for the term; defaults to the glossary's own name. */
	children?: ReactNode;
}

export function GlossaryTerm( { term, children }: GlossaryTermProps ): JSX.Element {
	const entry = findTerm( term );
	if ( ! entry ) {
		return <>{ children ?? term }</>;
	}
	return (
		<span className="uxs-gloss">
			{ children ?? entry.term }
			<DialogTrigger>
				<Button
					className="uxs-gloss__btn"
					// translators: %s: a technical term, for example CSP.
					aria-label={ sprintf( __( 'What is %s?', 'ux-studio' ), entry.term ) }
				>
					<span aria-hidden="true">?</span>
				</Button>
				<Popover
					// Portalled to <body>, outside #ux-studio-root: carry the theme so the --uxs-* variables resolve.
					data-theme={ document.getElementById( 'ux-studio-root' )?.getAttribute( 'data-theme' ) ?? 'light' }
					className="uxs-gloss__popover uxs-portal"
					placement="bottom start"
					offset={ 6 }
					containerPadding={ 12 }
				>
					<Dialog className="uxs-gloss__dialog" aria-label={ entry.term }>
						<strong>{ entry.term }</strong>
						<span>{ entry.text }</span>
					</Dialog>
				</Popover>
			</DialogTrigger>
		</span>
	);
}

const MARKER = /\[\[([^\]|]+?)(?:\|([^\]]+?))?\]\]/g;

/**
 * Plain text with `[[term]]` / `[[term|shown text]]` markers turned into glossary terms. Text without markers
 * (nearly all of it) is returned as it is.
 */
export function HelpText( { text }: { text: string } ): JSX.Element {
	if ( ! text.includes( '[[' ) ) {
		return <>{ text }</>;
	}
	const parts: ReactNode[] = [];
	let last = 0;
	let index = 0;
	for ( const match of text.matchAll( MARKER ) ) {
		const at = match.index ?? 0;
		if ( at > last ) {
			parts.push( text.slice( last, at ) );
		}
		const name = match[ 1 ] ?? '';
		const shown = match[ 2 ];
		parts.push(
			<GlossaryTerm key={ `${ index++ }-${ name }` } term={ name }>
				{ shown ?? ( findTerm( name )?.term ?? name ) }
			</GlossaryTerm>
		);
		last = at + match[ 0 ].length;
	}
	if ( last < text.length ) {
		parts.push( text.slice( last ) );
	}
	return (
		<>
			{ parts.map( ( part, i ) => (
				<Fragment key={ i }>{ part }</Fragment>
			) ) }
		</>
	);
}
