/**
 * Form Builder landing screen: list of forms + create/delete + shortcode hint.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Copy, Inbox, LoaderCircle, Plus, Trash2, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import TemplateGallery from './TemplateGallery';
import ArchiveTab from './ArchiveTab';
import type { FormListItem } from './types';
import { formatDate } from '../../app/date';

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
	const [ creating, setCreating ] = useState( false );
	const [ archiveForm, setArchiveForm ] = useState< number | undefined >();

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

	// With no form yet the creation panel is open from the start; otherwise it hides behind the "New form" button.
	const showCreate = creating || ( query.isSuccess && query.data.length === 0 );

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
				<button type="button" className="button button-primary" onClick={ () => setCreating( ( v ) => ! v ) } aria-expanded={ showCreate }>
					{ showCreate ? <X size={ 14 } /> : <Plus size={ 14 } /> } { showCreate ? __( 'Close', 'ux-studio' ) : __( 'New form', 'ux-studio' ) }
				</button>
			</header>

			{ showCreate ? (
				<>

			<div className="uxs-form uxs-form--inline" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-new-form-title">{ __( 'New form title', 'ux-studio' ) }</label>
					<input
						id="uxs-new-form-title"
						type="text"
						value={ newTitle }
						onChange={ ( e ) => setNewTitle( e.target.value ) }
						placeholder={ __( 'e.g. Contact form', 'ux-studio' ) }
					/>
					<p className="uxs-form__help">{ __( 'The name of the form. It is for you in the list, visitors do not see it unless you show it in the form.', 'ux-studio' ) }</p>
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

			<TemplateGallery
				title={ newTitle.trim() }
				onCreated={ ( id ) => {
					setNewTitle( '' );
					onOpen( id );
				} }
			/>

				</>
			) : null }

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>{ __( 'No forms yet - add your first one above.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
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
										onClick={ () => {
											setArchiveForm( form.id );
											document.getElementById( 'uxs-forms-submissions' )?.scrollIntoView( { behavior: 'smooth', block: 'start' } );
										} }
										title={ __( 'Show submissions of this form', 'ux-studio' ) }
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
								<td>{ formatDate( form.updated_at ) }</td>
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
				</table></div>
			) }

			<h2 id="uxs-forms-submissions" style={ { marginTop: 'var(--uxs-sp-6)' } }>{ __( 'Submitted data', 'ux-studio' ) }</h2>
			<ArchiveTab key={ archiveForm ?? 'all' } initialFormId={ archiveForm } />
		</>
	);
}
