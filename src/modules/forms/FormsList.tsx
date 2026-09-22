/**
 * Form Builder landing screen: list of forms + create/delete + shortcode hint.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Archive, Copy, Inbox, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import type { FormListItem } from './types';

const STATUS_LABELS: Record< FormListItem[ 'status' ], string > = {
	active: __( 'Active', 'ux-studio' ),
	draft: __( 'Draft', 'ux-studio' ),
	archived: __( 'Archived', 'ux-studio' ),
};

function ShortcodeBadge( { id }: { id: number } ): JSX.Element {
	const [ copied, setCopied ] = useState( false );
	const shortcode = `[uxstudio_form id="${ id }"]`;
	return (
		<button
			type="button"
			className="uxs-badge"
			title={ __( 'Copy shortcode', 'ux-studio' ) }
			onClick={ () => {
				void navigator.clipboard.writeText( shortcode ).then( () => {
					setCopied( true );
					setTimeout( () => setCopied( false ), 1500 );
				} );
			} }
			style={ { cursor: 'pointer', border: 0 } }
		>
			<Copy size={ 12 } /> { copied ? __( 'Copied!', 'ux-studio' ) : shortcode }
		</button>
	);
}

export default function FormsList( { onOpen }: { onOpen: ( id: number ) => void } ): JSX.Element {
	const [ newTitle, setNewTitle ] = useState( '' );

	const query = useQuery( {
		queryKey: [ 'forms', 'list' ],
		queryFn: () => api< FormListItem[] >( 'forms' ),
	} );

	const create = useMutation( {
		mutationFn: () => api< { id: number } >( 'forms', { method: 'POST', body: JSON.stringify( { title: newTitle, status: 'draft' } ) } ),
		onSuccess: ( form ) => {
			setNewTitle( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
			onOpen( form.id );
		},
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `forms/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } ),
	} );

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
					{ __( 'Form Builder', 'ux-studio' ) }
				</h1>
				<button
					type="button"
					className="button"
					onClick={ () => navigate( 'module', { id: 'forms', tab: 'archive' } ) }
				>
					<Archive size={ 14 } /> { __( 'Submission archive', 'ux-studio' ) }
				</button>
			</header>

			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-new-form-title">{ __( 'New form title', 'ux-studio' ) }</label>
					<input
						id="uxs-new-form-title"
						type="text"
						value={ newTitle }
						onChange={ ( e ) => setNewTitle( e.target.value ) }
						placeholder={ __( 'e.g. Contact form', 'ux-studio' ) }
					/>
				</div>
				<button
					type="button"
					className="button button-primary"
					disabled={ ! newTitle || create.isPending }
					onClick={ () => create.mutate() }
				>
					{ create.isPending ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { __( 'Add form', 'ux-studio' ) }
				</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>{ __( 'No forms yet - add your first one above.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Shortcode', 'ux-studio' ) }</th>
							<th>{ __( 'Submissions', 'ux-studio' ) }</th>
							<th>{ __( 'Updated', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ query.data.map( ( form ) => (
							<tr key={ form.id }>
								<td>
									<button type="button" className="button-link" onClick={ () => onOpen( form.id ) }>
										{ form.title || __( '(untitled)', 'ux-studio' ) }
									</button>
								</td>
								<td>
									<span className={ `uxs-badge ${ form.status === 'active' ? 'is-success' : '' }` }>
										{ STATUS_LABELS[ form.status ] }
									</span>
								</td>
								<td><ShortcodeBadge id={ form.id } /></td>
								<td>
									<button
										type="button"
										className="button-link"
										onClick={ () => navigate( 'module', { id: 'forms', formId: form.id, tab: 'archive' } ) }
										title={ __( 'Open archive for this form', 'ux-studio' ) }
									>
										<Inbox size={ 12 } />{ ' ' }
										{ form.unread > 0
											? sprintf(
													/* translators: 1: total submissions, 2: unread count. */
													__( '%1$d (%2$d unread)', 'ux-studio' ),
													form.submissions,
													form.unread
											  )
											: form.submissions }
									</button>
								</td>
								<td>{ form.updated_at }</td>
								<td>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete form', 'ux-studio' ) }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this form? Submissions already collected are kept in the archive.', 'ux-studio' ) ) ) {
												remove.mutate( form.id );
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
			) }
		</>
	);
}
