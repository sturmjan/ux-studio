import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'settings' | 'log';

interface EmailLogEntry {
	id: number;
	created_at: string;
	to_email: string;
	subject: string;
	status: string;
	error_message: string | null;
}

const PAGE_SIZE = 20;

function LogTable(): JSX.Element {
	const [ page, setPage ] = useState( 0 );

	const query = useQuery( {
		queryKey: [ 'email-log', 'entries', page ],
		queryFn: () => api< EmailLogEntry[] >( `email-log/entries?limit=${ PAGE_SIZE }&offset=${ page * PAGE_SIZE }` ),
	} );

	const deleteOne = useMutation( {
		mutationFn: ( id: number ) => api( `email-log/entries/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'email-log', 'entries' ] } ),
	} );

	const clearAll = useMutation( {
		mutationFn: () => api( 'email-log/clear', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'email-log', 'entries' ] } ),
	} );

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button
					type="button"
					className="button"
					disabled={ clearAll.isPending }
					onClick={ () => clearAll.mutate() }
				>
					<Trash2 size={ 14 } /> { __( 'Clear all', 'ux-studio' ) }
				</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>{ __( 'No mail has been sent yet.', 'ux-studio' ) }</p>
			) : (
				<>
					<table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'Date', 'ux-studio' ) }</th>
								<th>{ __( 'To', 'ux-studio' ) }</th>
								<th>{ __( 'Subject', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
								<th>{ __( 'Error', 'ux-studio' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ query.data.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.created_at }</td>
									<td>{ row.to_email }</td>
									<td>{ row.subject }</td>
									<td>
										<span className={ `uxs-badge ${ row.status === 'success' ? 'is-success' : 'is-danger' }` }>
											{ row.status === 'success' ? __( 'Sent', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) }
										</span>
									</td>
									<td>{ row.error_message ?? '' }</td>
									<td>
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Delete entry', 'ux-studio' ) }
											onClick={ () => deleteOne.mutate( row.id ) }
										>
											<Trash2 size={ 14 } />
										</button>
									</td>
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
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'email-log' );

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
					{ __( 'Email Log', 'ux-studio' ) }
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
