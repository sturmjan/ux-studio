import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'settings' | 'log';

interface ActivityEntry {
	id: number;
	created_at: string;
	user_id: number;
	module: string;
	action: string;
	object_type: string;
	object_id: number;
	meta: string | null;
}

const PAGE_SIZE = 20;

function LogTable(): JSX.Element {
	const [ moduleFilter, setModuleFilter ] = useState( '' );
	const [ actionFilter, setActionFilter ] = useState( '' );
	const [ page, setPage ] = useState( 0 );

	const query = useQuery( {
		queryKey: [ 'activity-log', 'entries', moduleFilter, actionFilter, page ],
		queryFn: () =>
			api< ActivityEntry[] >(
				`activity-log/entries?module=${ encodeURIComponent( moduleFilter ) }&action=${ encodeURIComponent(
					actionFilter
				) }&limit=${ PAGE_SIZE }&offset=${ page * PAGE_SIZE }`
			),
	} );

	const purge = useMutation( {
		mutationFn: () => api( 'activity-log/entries', { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'activity-log', 'entries' ] } ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)', flexDirection: 'row', alignItems: 'flex-end', gap: 'var(--uxs-sp-4)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-al-module">{ __( 'Module', 'ux-studio' ) }</label>
					<input
						id="uxs-al-module"
						type="text"
						value={ moduleFilter }
						onChange={ ( e ) => {
							setPage( 0 );
							setModuleFilter( e.target.value );
						} }
					/>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-al-action">{ __( 'Action', 'ux-studio' ) }</label>
					<input
						id="uxs-al-action"
						type="text"
						value={ actionFilter }
						onChange={ ( e ) => {
							setPage( 0 );
							setActionFilter( e.target.value );
						} }
					/>
				</div>
				<button
					type="button"
					className="button"
					disabled={ purge.isPending }
					onClick={ () => purge.mutate() }
				>
					<Trash2 size={ 14 } /> { __( 'Purge old entries', 'ux-studio' ) }
				</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>{ __( 'No matching activity found.', 'ux-studio' ) }</p>
			) : (
				<>
					<table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'Date', 'ux-studio' ) }</th>
								<th>{ __( 'User', 'ux-studio' ) }</th>
								<th>{ __( 'Module', 'ux-studio' ) }</th>
								<th>{ __( 'Action', 'ux-studio' ) }</th>
								<th>{ __( 'Object', 'ux-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ query.data.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.created_at }</td>
									<td>{ row.user_id }</td>
									<td>{ row.module }</td>
									<td>{ row.action }</td>
									<td>{ row.object_type ? `${ row.object_type } #${ row.object_id }` : '' }</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
						<button type="button" className="button" disabled={ page === 0 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							{ __( 'Previous', 'ux-studio' ) }
						</button>
						<button
							type="button"
							className="button"
							disabled={ query.data.length < PAGE_SIZE }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'log' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'activity-log' );

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
					{ __( 'Activity Log', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Log', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
			{ tab === 'log' && <LogTable /> }
		</>
	);
}
