import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Mail, Trash2, X } from 'lucide-react';
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
	source: string;
}

interface EmailLogDetail extends EmailLogEntry {
	message: string;
	headers: string;
	attachments: string[];
}

const PAGE_SIZE = 20;

function StatusBadge( { status }: { status: string } ): JSX.Element {
	return (
		<span className={ `uxs-badge ${ status === 'success' ? 'is-success' : status === 'error' ? 'is-danger' : '' }` }>
			{ status === 'success'
				? __( 'Sent', 'ux-studio' )
				: status === 'error'
				? __( 'Failed', 'ux-studio' )
				: __( 'Pending', 'ux-studio' ) }
		</span>
	);
}

function DetailModal( { id, onClose }: { id: number; onClose: () => void } ): JSX.Element {
	const [ notice, setNotice ] = useState< string | null >( null );

	const query = useQuery( {
		queryKey: [ 'email-log', 'entry', id ],
		queryFn: () => api< EmailLogDetail >( `email-log/entries/${ id }` ),
	} );

	const resend = useMutation( {
		mutationFn: () => api< { message: string } >( `email-log/entries/${ id }/resend`, { method: 'POST' } ),
		onSuccess: ( data ) => {
			setNotice( data.message ?? __( 'Email was re-sent.', 'ux-studio' ) );
			void queryClient.invalidateQueries( { queryKey: [ 'email-log', 'entries' ] } );
		},
		onError: ( err: Error ) => setNotice( err.message ),
	} );

	const detail = query.data;

	return (
		<div
			role="dialog"
			aria-modal="true"
			style={ {
				position: 'fixed',
				inset: 0,
				background: 'rgba(0,0,0,0.5)',
				display: 'flex',
				alignItems: 'flex-start',
				justifyContent: 'center',
				padding: 'var(--uxs-sp-6, 24px)',
				zIndex: 100000,
				overflow: 'auto',
			} }
			onClick={ onClose }
		>
			<div
				className="uxs-card"
				style={ { background: '#fff', maxWidth: 720, width: '100%', padding: 'var(--uxs-sp-5, 20px)' } }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-4)' } }>
					<h2 style={ { margin: 0 } }>{ __( 'Email detail', 'ux-studio' ) }</h2>
					<button type="button" className="button-link" aria-label={ __( 'Close', 'ux-studio' ) } onClick={ onClose }>
						<X size={ 18 } />
					</button>
				</div>

				{ query.isLoading || ! detail ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<>
						<table className="uxs-table" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
							<tbody>
								<tr>
									<th style={ { width: 140, textAlign: 'left' } }>{ __( 'Status', 'ux-studio' ) }</th>
									<td><StatusBadge status={ detail.status } /></td>
								</tr>
								<tr>
									<th style={ { textAlign: 'left' } }>{ __( 'Date', 'ux-studio' ) }</th>
									<td>{ detail.created_at }</td>
								</tr>
								<tr>
									<th style={ { textAlign: 'left' } }>{ __( 'To', 'ux-studio' ) }</th>
									<td>{ detail.to_email }</td>
								</tr>
								<tr>
									<th style={ { textAlign: 'left' } }>{ __( 'Subject', 'ux-studio' ) }</th>
									<td>{ detail.subject }</td>
								</tr>
								<tr>
									<th style={ { textAlign: 'left' } }>{ __( 'Source', 'ux-studio' ) }</th>
									<td>{ detail.source || '—' }</td>
								</tr>
								{ detail.error_message ? (
									<tr>
										<th style={ { textAlign: 'left' } }>{ __( 'Error', 'ux-studio' ) }</th>
										<td>{ detail.error_message }</td>
									</tr>
								) : null }
							</tbody>
						</table>

						<h3>{ __( 'Headers', 'ux-studio' ) }</h3>
						<pre style={ { whiteSpace: 'pre-wrap', wordBreak: 'break-word', background: 'var(--uxs-bg-muted, #f6f7f7)', padding: 'var(--uxs-sp-3, 12px)', borderRadius: 4 } }>
							{ detail.headers || __( 'No headers', 'ux-studio' ) }
						</pre>

						{ detail.attachments.length > 0 ? (
							<>
								<h3>{ __( 'Attachments', 'ux-studio' ) }</h3>
								<ul>
									{ detail.attachments.map( ( name, i ) => (
										<li key={ i }>{ name }</li>
									) ) }
								</ul>
								<p className="uxs-form__help">
									{ __( 'Only filenames are stored, so attachments are not re-attached on resend.', 'ux-studio' ) }
								</p>
							</>
						) : null }

						<h3>{ __( 'Message', 'ux-studio' ) }</h3>
						<iframe
							title={ __( 'Email body', 'ux-studio' ) }
							sandbox=""
							srcDoc={ detail.message }
							style={ { width: '100%', height: 300, border: '1px solid var(--uxs-border, #dcdcde)', borderRadius: 4, background: '#fff' } }
						/>

						{ notice ? <p style={ { marginTop: 'var(--uxs-sp-3)' } }>{ notice }</p> : null }

						<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
							<button
								type="button"
								className="button button-primary"
								disabled={ resend.isPending }
								onClick={ () => resend.mutate() }
							>
								{ resend.isPending ? <LoaderCircle size={ 14 } /> : <Mail size={ 14 } /> }{ ' ' }
								{ __( 'Resend', 'ux-studio' ) }
							</button>
							<button type="button" className="button" onClick={ onClose }>
								{ __( 'Close', 'ux-studio' ) }
							</button>
						</div>
					</>
				) }
			</div>
		</div>
	);
}

function LogTable(): JSX.Element {
	const [ page, setPage ] = useState( 0 );
	const [ detailId, setDetailId ] = useState< number | null >( null );

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
								<th>{ __( 'Source', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ query.data.map( ( row ) => (
								<tr key={ row.id }>
									<td>{ row.created_at }</td>
									<td>{ row.to_email }</td>
									<td>
										<button
											type="button"
											className="button-link"
											onClick={ () => setDetailId( row.id ) }
										>
											{ row.subject || __( '(no subject)', 'ux-studio' ) }
										</button>
									</td>
									<td>{ row.source || '—' }</td>
									<td>
										<StatusBadge status={ row.status } />
										{ row.error_message ? (
											<span className="uxs-form__help" title={ row.error_message } style={ { display: 'block' } }>
												{ row.error_message }
											</span>
										) : null }
									</td>
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

			{ detailId !== null ? <DetailModal id={ detailId } onClose={ () => setDetailId( null ) } /> : null }
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
