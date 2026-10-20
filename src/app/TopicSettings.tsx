/**
 * Settings of one topic, shown on the same page as that topic's data.
 *
 * Module pages are organised by topic (Firewall, Login, Log...), never as a
 * "Settings" item with sub-sections next to data tabs about the same things -
 * that split every topic in two places. A topic page renders the fields that
 * belong to it with this component (own Save button) and its data below.
 *
 * All instances of one module share the settings query; each saves the full
 * draft, so fields of other topics are sent back unchanged.
 */
import { LoaderCircle } from 'lucide-react';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { type Field, SettingsFields, useModuleSettings } from './SettingsForm';

export function TopicSettings( {
	moduleId,
	include,
	collapsible,
	onSaved,
	children,
}: {
	moduleId: string;
	/** Which schema fields belong to this topic. */
	include: ( field: Field ) => boolean;
	/** Title of a closed-by-default panel - for pages that lead with their data (a chat, a log). */
	collapsible?: string;
	/** Called after a successful save (e.g. to refresh a status that depends on these fields). */
	onSaved?: () => void;
	/** Extra content under the fields, before the Save button (notes, status). */
	children?: ReactNode;
} ): JSX.Element | null {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( moduleId );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	// The topic is the page; sections inside it would bring the sub-menu back.
	const schema = data.schema.filter( include ).map( ( f ) => ( { ...f, section: undefined } ) );
	if ( schema.length === 0 ) {
		return null;
	}

	const body = (
		<>
			<SettingsFields schema={ schema } draft={ draft } setDraft={ setDraft } />
			{ children }
			<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate( undefined, { onSuccess: onSaved } ) }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);

	if ( collapsible ) {
		return (
			<details className="uxs-topic-settings uxs-topic-settings--collapsible">
				<summary>{ collapsible }</summary>
				{ body }
			</details>
		);
	}

	return <div className="uxs-topic-settings">{ body }</div>;
}

/** Field filter: fields whose (translated) section label is one of `sections`. */
export function inSections( ...sections: string[] ): ( field: Field ) => boolean {
	return ( field ) => sections.includes( field.section ?? '' );
}

/** Field filter: fields whose key is listed or starts with one of the prefixes ending in "_". */
export function withKeys( ...keys: string[] ): ( field: Field ) => boolean {
	return ( field ) => keys.some( ( k ) => ( k.endsWith( '_' ) ? field.key.startsWith( k ) : field.key === k ) );
}
