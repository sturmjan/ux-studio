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
import { findTerm, splitHelpText } from './glossary';

export type { GlossaryData, GlossaryEntry } from './glossary';

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

/**
 * Plain text with `[[term]]` / `[[term|shown text]]` markers turned into glossary terms. Text without markers
 * (nearly all of it) is returned as it is.
 */
export function HelpText( { text }: { text: string } ): JSX.Element {
	const pieces = splitHelpText( text );
	if ( pieces.length === 1 && typeof pieces[ 0 ] === 'string' ) {
		return <>{ text }</>;
	}
	return (
		<>
			{ pieces.map( ( piece, i ) =>
				typeof piece === 'string' ? (
					<Fragment key={ i }>{ piece }</Fragment>
				) : (
					<GlossaryTerm key={ i } term={ piece.name }>
						{ piece.shown ?? findTerm( piece.name )?.term ?? piece.name }
					</GlossaryTerm>
				)
			) }
		</>
	);
}
