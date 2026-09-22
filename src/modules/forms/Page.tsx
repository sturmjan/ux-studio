/**
 * Form Builder: list of forms + per-form builder (Fields/Actions/Appearance/
 * Archive/Settings tabs) + a global Archive view across every form.
 * Route: #/module?id=forms[&formId=&tab=&status=]
 */
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { api } from '../../app/api';
import { hashParam, navigate } from '../../app/route';
import FormsList from './FormsList';
import FieldsTab from './FieldsTab';
import ActionsTab from './ActionsTab';
import AppearanceTab from './AppearanceTab';
import SettingsTab from './SettingsTab';
import ArchiveTab from './ArchiveTab';
import type { FormDefinition } from './types';

type BuilderTab = 'fields' | 'actions' | 'appearance' | 'archive' | 'settings';

const TABS: { id: BuilderTab; label: () => string }[] = [
	{ id: 'fields', label: () => __( 'Fields', 'ux-studio' ) },
	{ id: 'actions', label: () => __( 'Actions after submit', 'ux-studio' ) },
	{ id: 'appearance', label: () => __( 'Appearance', 'ux-studio' ) },
	{ id: 'archive', label: () => __( 'Archive', 'ux-studio' ) },
	{ id: 'settings', label: () => __( 'Settings', 'ux-studio' ) },
];

function Builder( { id }: { id: number } ): JSX.Element {
	const [ tab, setTab ] = useState< BuilderTab >( ( hashParam( 'tab' ) as BuilderTab ) || 'fields' );

	const query = useQuery( {
		queryKey: [ 'forms', 'form', id ],
		queryFn: () => api< FormDefinition >( `forms/${ id }` ),
	} );

	if ( query.isLoading || ! query.data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const form = query.data;

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( 'module', { id: 'forms' } ) }
						aria-label={ __( 'Back to forms', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ form.title || __( '(untitled form)', 'ux-studio' ) }
				</h1>
			</header>

			<div className="uxs-tabs">
				{ TABS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label() }
					</button>
				) ) }
			</div>

			{ tab === 'fields' && <FieldsTab form={ form } /> }
			{ tab === 'actions' && <ActionsTab form={ form } /> }
			{ tab === 'appearance' && <AppearanceTab form={ form } /> }
			{ tab === 'settings' && <SettingsTab form={ form } /> }
			{ tab === 'archive' && <ArchiveTab formId={ form.id } formTitle={ form.title } /> }
		</>
	);
}

function GlobalArchive(): JSX.Element {
	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( 'module', { id: 'forms' } ) }
						aria-label={ __( 'Back to forms', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Submission archive', 'ux-studio' ) }
				</h1>
			</header>
			<ArchiveTab initialStatus={ hashParam( 'status' ) ?? undefined } />
		</>
	);
}

export default function Page(): JSX.Element {
	const [ formId, setFormId ] = useState< number | null >( hashParam( 'formId' ) ? Number( hashParam( 'formId' ) ) : null );
	const [ globalArchive, setGlobalArchive ] = useState< boolean >( ! hashParam( 'formId' ) && hashParam( 'tab' ) === 'archive' );

	useEffect( () => {
		function onHashChange() {
			setFormId( hashParam( 'formId' ) ? Number( hashParam( 'formId' ) ) : null );
			setGlobalArchive( ! hashParam( 'formId' ) && hashParam( 'tab' ) === 'archive' );
		}
		window.addEventListener( 'hashchange', onHashChange );
		return () => window.removeEventListener( 'hashchange', onHashChange );
	}, [] );

	if ( globalArchive ) {
		return <GlobalArchive />;
	}
	if ( formId ) {
		return <Builder id={ formId } />;
	}
	return <FormsList onOpen={ ( id ) => navigate( 'module', { id: 'forms', formId: id } ) } />;
}
