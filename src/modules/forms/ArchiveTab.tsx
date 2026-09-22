/**
 * Submission archive: server-side paginated, full-text searchable list of
 * submissions - used both as a per-form filtered view (Archive tab inside
 * the builder) and as the aggregated cross-form view (Page.tsx's global
 * archive, reached from the module list or the dashboard widget deep-link).
 * PLAN.md 20.6.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download, LoaderCircle, Mail, Paperclip, RotateCcw, Search, Trash2, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { ActionLogEntry, SubmissionDetail, SubmissionFile, SubmissionListItem, SubmissionStatus } from './types';

const PAGE_SIZE = 20;

const STATUS_LABELS: Record< SubmissionStatus, string > = {
	unread: __( 'Unread', 'ux-studio' ),
	read: __( 'Read', 'ux-studio' ),
	spam: __( 'Spam', 'ux-studio' ),
	trash: __( 'Trash', 'ux-studio' ),
};

interface Envelope {
	items: SubmissionListItem[];
	total: number;
}

function summaryLine( row: SubmissionListItem ): string {
	const first = Object.values( row.values ).find( ( v ) => typeof v === 'string' && v.trim() !== '' );
	return typeof first === 'string' ? first : __( '(no text fields)', 'ux-studio' );
}

function DetailModal( { id, onClose }: { id: number; onClose: () => void } ): JSX.Element {
	const query = useQuery( {
		queryKey: [ 'forms', 'submission', id ],
		queryFn: () => api< SubmissionDetail >( `forms/submissions/${ id }` ),
	} );

	const setStatus = useMutation( {
		mutationFn: ( status: SubmissionStatus ) => api( `forms/submissions/${ id }/status`, { method: 'POST', body: JSON.stringify( { status } ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'submissions' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'submission', id ] } );
		},
	} );

	const resend = useMutation( {
		mutationFn: () => api< { log: ActionLogEntry[] } >( `forms/submissions/${ id }/resend`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'forms', 'submission', id ] } ),
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
				style={ { background: 'var(--uxs-surface)', maxWidth: 720, width: '100%', padding: 'var(--uxs-sp-5, 20px)' } }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-4)' } }>
					<h2 style={ { margin: 0 } }>{ __( 'Submission detail', 'ux-studio' ) }</h2>
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
						<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', marginBottom: 'var(--uxs-sp-4)', flexWrap: 'wrap' } }>
							{ ( Object.keys( STATUS_LABELS ) as SubmissionStatus[] ).map( ( s ) => (
								<button
									key={ s }
									type="button"
									className="uxs-badge"
									style={ {
										cursor: 'pointer',
										border: detail.status === s ? '1px solid var(--uxs-brand)' : '1px solid var(--uxs-border)',
										background: detail.status === s ? 'var(--uxs-brand-soft)' : undefined,
									} }
									onClick={ () => setStatus.mutate( s ) }
								>
									{ STATUS_LABELS[ s ] }
								</button>
							) ) }
						</div>

						<table className="uxs-table" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
							<tbody>
								<tr>
									<th style={ { width: 160, textAlign: 'left' } }>{ __( 'Form', 'ux-studio' ) }</th>
									<td>{ detail.form_title }</td>
								</tr>
								<tr>
									<th style={ { textAlign: 'left' } }>{ __( 'Submitted', 'ux-studio' ) }</th>
									<td>{ detail.created_at }</td>
								</tr>
								{ Object.entries( detail.fields_snapshot ).map( ( [ key, meta ] ) => {
									const value = detail.values[ key ];
									const display = Array.isArray( value ) ? value.join( ', ' ) : value === true ? __( 'Yes', 'ux-studio' ) : value === false ? __( 'No', 'ux-studio' ) : String( value ?? '' );
									if ( meta.type === 'file' || display === '' ) {
										return null;
									}
									return (
										<tr key={ key }>
											<th style={ { textAlign: 'left' } }>{ meta.label }</th>
											<td>{ display }</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>

						{ detail.files.length > 0 ? (
							<>
								<h3>{ __( 'Attachments', 'ux-studio' ) }</h3>
								<ul>
									{ detail.files.map( ( file: SubmissionFile ) => (
										<li key={ file.id }>
											<a href={ `${ window.uxStudioBoot.restUrl }/forms/submissions/${ id }/files/${ file.id }` } target="_blank" rel="noopener noreferrer">
												<Paperclip size={ 12 } style={ { verticalAlign: '-2px' } } /> { file.original_name }
											</a>{ ' ' }
											({ Math.round( file.size / 1024 ) } KB)
										</li>
									) ) }
								</ul>
							</>
						) : null }

						<h3>{ __( 'Action log', 'ux-studio' ) }</h3>
						{ detail.action_log.length === 0 ? (
							<p className="uxs-form__help">{ __( 'No actions have run for this submission yet.', 'ux-studio' ) }</p>
						) : (
							<ul>
								{ detail.action_log.map( ( entry ) => (
									<li key={ entry.id }>
										<span className={ `uxs-badge ${ entry.status === 'ok' ? 'is-success' : 'is-danger' }` }>{ entry.action_type }</span>{ ' ' }
										{ entry.detail } <span className="uxs-form__help">({ entry.created_at })</span>
									</li>
								) ) }
							</ul>
						) }

						<button type="button" className="button" disabled={ resend.isPending } onClick={ () => resend.mutate() }>
							{ resend.isPending ? <LoaderCircle size={ 14 } /> : <Mail size={ 14 } /> } { __( 'Resend actions', 'ux-studio' ) }
						</button>
					</>
				) }
			</div>
		</div>
	);
}

export default function ArchiveTab( { formId, formTitle, initialStatus }: { formId?: number; formTitle?: string; initialStatus?: string } ): JSX.Element {
	const [ page, setPage ] = useState( 0 );
	const [ q, setQ ] = useState( '' );
	const [ status, setStatus ] = useState< string >( initialStatus ?? '' );
	const [ detailId, setDetailId ] = useState< number | null >( null );

	const params = new URLSearchParams();
	params.set( 'page', String( page + 1 ) );
	params.set( 'per_page', String( PAGE_SIZE ) );
	if ( formId ) {
		params.set( 'form_id', String( formId ) );
	}
	if ( status ) {
		params.set( 'status', status );
	}
	if ( q ) {
		params.set( 'q', q );
	}

	const query = useQuery( {
		queryKey: [ 'forms', 'submissions', formId ?? 'all', page, status, q ],
		queryFn: async (): Promise< Envelope > => {
			const boot = window.uxStudioBoot;
			const res = await fetch( `${ boot.restUrl }/forms/submissions?${ params.toString() }`, {
				headers: { 'X-WP-Nonce': boot.nonce },
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
			const json: { data: SubmissionListItem[]; meta?: { total?: number } } = await res.json();
			return { items: json.data, total: json.meta?.total ?? json.data.length };
		},
	} );

	const deleteOne = useMutation( {
		mutationFn: ( id: number ) => api( `forms/submissions/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'forms', 'submissions' ] } ),
	} );

	function exportCsv() {
		const boot = window.uxStudioBoot;
		const url = new URL( `${ boot.restUrl }/forms/submissions/export`, window.location.href );
		params.delete( 'page' );
		params.delete( 'per_page' );
		params.forEach( ( v, k ) => url.searchParams.set( k, v ) );
		url.searchParams.set( '_wpnonce', boot.nonce );
		window.open( url.toString(), '_blank' );
	}

	const total = query.data?.total ?? 0;
	const totalPages = Math.max( 1, Math.ceil( total / PAGE_SIZE ) );

	return (
		<>
			{ formTitle ? <p className="uxs-form__help">{ sprintf( __( 'Filtered to: %s', 'ux-studio' ), formTitle ) }</p> : null }

			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', marginBottom: 'var(--uxs-sp-4)', flexWrap: 'wrap', alignItems: 'center' } }>
				<div style={ { position: 'relative' } }>
					<Search size={ 14 } style={ { position: 'absolute', left: 8, top: 9, color: 'var(--uxs-text-soft)' } } />
					<input
						type="search"
						value={ q }
						onChange={ ( e ) => {
							setQ( e.target.value );
							setPage( 0 );
						} }
						placeholder={ __( 'Search submissions…', 'ux-studio' ) }
						style={ { paddingLeft: 28 } }
					/>
				</div>
				<select
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 0 );
					} }
				>
					<option value="">{ __( 'All (except trash)', 'ux-studio' ) }</option>
					{ ( Object.keys( STATUS_LABELS ) as SubmissionStatus[] ).map( ( s ) => (
						<option key={ s } value={ s }>
							{ STATUS_LABELS[ s ] }
						</option>
					) ) }
				</select>
				<button type="button" className="button" onClick={ exportCsv }>
					<Download size={ 14 } /> { __( 'Export CSV', 'ux-studio' ) }
				</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.items.length === 0 ? (
				<p>{ __( 'No submissions found.', 'ux-studio' ) }</p>
			) : (
				<>
					<table className="uxs-table">
						<thead>
							<tr>
								{ ! formId ? <th>{ __( 'Form', 'ux-studio' ) }</th> : null }
								<th>{ __( 'Summary', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
								<th>{ __( 'Submitted', 'ux-studio' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ query.data.items.map( ( row ) => (
								<tr key={ row.id } className={ row.status === 'unread' ? 'is-invalid' : '' }>
									{ ! formId ? <td>{ row.form_title }</td> : null }
									<td>
										<button type="button" className="button-link" onClick={ () => setDetailId( row.id ) }>
											{ summaryLine( row ) }
										</button>
									</td>
									<td>
										<span className={ `uxs-badge ${ row.status === 'spam' || row.status === 'trash' ? 'is-danger' : row.status === 'unread' ? 'is-success' : '' }` }>
											{ STATUS_LABELS[ row.status ] }
										</span>
									</td>
									<td>{ row.created_at }</td>
									<td>
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Delete submission', 'ux-studio' ) }
											onClick={ () => {
												if ( window.confirm( __( 'Delete this submission permanently?', 'ux-studio' ) ) ) {
													deleteOne.mutate( row.id );
												}
											} }
										>
											<Trash2 size={ 14 } />
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>

					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)', alignItems: 'center' } }>
						<button type="button" className="button" disabled={ page === 0 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							{ __( 'Previous', 'ux-studio' ) }
						</button>
						<span className="uxs-form__help">
							{ sprintf(
								/* translators: 1: current page, 2: total pages. */
								__( 'Page %1$d of %2$d', 'ux-studio' ),
								page + 1,
								totalPages
							) }
						</span>
						<button type="button" className="button" disabled={ page + 1 >= totalPages } onClick={ () => setPage( ( p ) => p + 1 ) }>
							{ __( 'Next', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }

			{ detailId !== null ? <DetailModal id={ detailId } onClose={ () => setDetailId( null ) } /> : null }
		</>
	);
}
