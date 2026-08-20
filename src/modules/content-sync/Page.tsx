import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Plus } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'sites' | 'log' | 'settings';

interface Site {
	id: number;
	created_at: string;
	name: string;
	url: string;
	last_sync: string | null;
}

interface LogRow {
	id: number;
	created_at: string;
	site_id: number;
	action: string;
	status: string;
}

function AddSiteForm(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ url, setUrl ] = useState( '' );

	const create = useMutation( {
		mutationFn: () =>
			api< Site >( 'content-sync/sites', {
				method: 'POST',
				body: JSON.stringify( { name, url } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'sites' ] } );
			setName( '' );
			setUrl( '' );
		},
	} );

	const canSubmit = name.trim() !== '' && url.trim() !== '' && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cs-name">{ __( 'Site name', 'ux-studio' ) }</label>
				<input
					id="uxs-cs-name"
					type="text"
					value={ name }
					onChange={ ( e ) => setName( e.target.value ) }
					placeholder={ __( 'e.g. Main hotel site', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cs-url">{ __( 'Site URL', 'ux-studio' ) }</label>
				<input
					id="uxs-cs-url"
					type="text"
					value={ url }
					onChange={ ( e ) => setUrl( e.target.value ) }
					placeholder="https://example.com"
				/>
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { __( 'Add site', 'ux-studio' ) }
			</button>
			{ create.isError ? <p className="uxs-form__help">{ ( create.error as Error ).message }</p> : null }
		</div>
	);
}

function SitesTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'content-sync', 'sites' ],
		queryFn: () => api< Site[] >( 'content-sync/sites' ),
	} );

	return (
		<>
			<AddSiteForm />
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No sites registered yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'URL', 'ux-studio' ) }</th>
							<th>{ __( 'Last sync', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( site ) => (
							<tr key={ site.id }>
								<td>{ site.name }</td>
								<td>{ site.url }</td>
								<td>{ site.last_sync ?? __( 'Never', 'ux-studio' ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
		</>
	);
}

function LogTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'content-sync', 'log' ],
		queryFn: () => api< LogRow[] >( 'content-sync/log' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No sync activity yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Action', 'ux-studio' ) }</th>
					<th>{ __( 'Status', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row ) => (
					<tr key={ row.id }>
						<td>{ row.created_at }</td>
						<td>{ row.action }</td>
						<td>
							<span className={ `uxs-badge ${ row.status === 'success' ? 'is-success' : 'is-danger' }` }>
								{ row.status }
							</span>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'sites' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'content-sync' );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Content Sync', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'sites' ? 'is-active' : '' } onClick={ () => setTab( 'sites' ) }>
					{ __( 'Sites', 'ux-studio' ) }
				</button>
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Log', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'sites' && <SitesTab /> }
			{ tab === 'log' && <LogTab /> }
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p>
						{ draft.has_hmac_secret
							? __( 'An HMAC secret is currently set.', 'ux-studio' )
							: __( 'No HMAC secret set yet.', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
