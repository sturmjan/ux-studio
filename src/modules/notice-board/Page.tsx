import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'documents' | 'subscribers' | 'settings';

interface Document {
	id: number;
	created_at: string;
	title: string;
	category: string;
	attachment_id: number;
	attachment_url: string;
}

interface Category {
	id: number;
	name: string;
	slug: string;
}

interface Subscriber {
	id: number;
	created_at: string;
	email: string;
	confirmed: boolean;
}

interface WpMediaAttachment {
	id: number;
	url: string;
	filename?: string;
	title?: string;
}

interface WpMedia {
	open: () => void;
	on: ( ev: string, cb: () => void ) => void;
	state: () => { get: ( k: string ) => { first: () => { toJSON: () => WpMediaAttachment } } };
}

function AttachmentPicker( {
	attachmentId,
	onSelect,
}: {
	attachmentId: number;
	onSelect: ( attachment: WpMediaAttachment ) => void;
} ): JSX.Element {
	const wp = ( window as unknown as { wp?: { media?: ( opts: unknown ) => WpMedia } } ).wp;

	const pick = (): void => {
		if ( ! wp?.media ) {
			return;
		}
		const frame = wp.media( {
			title: __( 'Select an attachment', 'ux-studio' ),
			multiple: false,
			library: {},
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onSelect( att );
		} );
		frame.open();
	};

	return (
		<button type="button" className="button" onClick={ pick }>
			{ attachmentId ? __( 'Change attachment', 'ux-studio' ) : __( 'Select attachment', 'ux-studio' ) }
		</button>
	);
}

function AddDocumentForm(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ newCategory, setNewCategory ] = useState( '' );
	const [ attachment, setAttachment ] = useState< WpMediaAttachment | null >( null );

	const { data: categories } = useQuery( {
		queryKey: [ 'notice-board', 'categories' ],
		queryFn: () => api< Category[] >( 'notice-board/categories' ),
	} );

	const addCategory = useMutation( {
		mutationFn: () => api< Category >( 'notice-board/categories', { method: 'POST', body: JSON.stringify( { name: newCategory } ) } ),
		onSuccess: ( created ) => {
			void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'categories' ] } );
			setCategory( created.slug );
			setNewCategory( '' );
		},
	} );

	const create = useMutation( {
		mutationFn: () =>
			api< Document >( 'notice-board/documents', {
				method: 'POST',
				body: JSON.stringify( { title, category, attachment_id: attachment?.id ?? 0 } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'documents' ] } );
			setTitle( '' );
			setAttachment( null );
		},
	} );

	const canSubmit = title.trim() !== '' && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-title">{ __( 'Title', 'ux-studio' ) }</label>
				<input
					id="uxs-nb-title"
					type="text"
					value={ title }
					onChange={ ( e ) => setTitle( e.target.value ) }
					placeholder={ __( 'e.g. Public notice 4/2026', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-category">{ __( 'Category', 'ux-studio' ) }</label>
				<select id="uxs-nb-category" value={ category } onChange={ ( e ) => setCategory( e.target.value ) }>
					<option value="">{ __( '— None —', 'ux-studio' ) }</option>
					{ ( categories ?? [] ).map( ( c ) => (
						<option key={ c.id } value={ c.slug }>
							{ c.name }
						</option>
					) ) }
				</select>
				<input
					type="text"
					placeholder={ __( 'New category name', 'ux-studio' ) }
					value={ newCategory }
					onChange={ ( e ) => setNewCategory( e.target.value ) }
					style={ { marginLeft: 'var(--uxs-sp-2)', width: '180px' } }
				/>
				<button
					type="button"
					className="button"
					disabled={ newCategory.trim() === '' || addCategory.isPending }
					onClick={ () => addCategory.mutate() }
				>
					{ __( 'Add', 'ux-studio' ) }
				</button>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Attachment', 'ux-studio' ) }</label>
				<span className="uxs-media">
					<AttachmentPicker attachmentId={ attachment?.id ?? 0 } onSelect={ setAttachment } />
					{ attachment ? <span> { attachment.filename ?? attachment.title ?? attachment.url }</span> : null }
				</span>
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Add document', 'ux-studio' ) }
			</button>
		</div>
	);
}

function DocumentsTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'notice-board', 'documents' ],
		queryFn: () => api< Document[] >( 'notice-board/documents' ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `notice-board/documents/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'documents' ] } ),
	} );

	return (
		<>
			<AddDocumentForm />
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No documents yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th>{ __( 'File', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( doc ) => (
							<tr key={ doc.id }>
								<td>{ doc.title }</td>
								<td>{ doc.category || '—' }</td>
								<td>
									{ doc.attachment_url ? (
										<a href={ doc.attachment_url } target="_blank" rel="noreferrer">
											{ __( 'Download', 'ux-studio' ) }
										</a>
									) : (
										'—'
									) }
								</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this document?', 'ux-studio' ) ) ) {
												remove.mutate( doc.id );
											}
										} }
									>
										<Trash2 size={ 14 } /> { __( 'Delete', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
		</>
	);
}

function SubscribersTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'notice-board', 'subscribers' ],
		queryFn: () => api< Subscriber[] >( 'notice-board/subscribers' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No subscribers yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Email', 'ux-studio' ) }</th>
					<th>{ __( 'Confirmed', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( s ) => (
					<tr key={ s.id }>
						<td>{ s.created_at }</td>
						<td>{ s.email }</td>
						<td>
							<span className={ `uxs-badge ${ s.confirmed ? 'is-success' : '' }` }>
								{ s.confirmed ? __( 'Yes', 'ux-studio' ) : __( 'Pending', 'ux-studio' ) }
							</span>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'documents' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'notice-board' );

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
					{ __( 'Notice Board', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'documents' ? 'is-active' : '' } onClick={ () => setTab( 'documents' ) }>
					{ __( 'Documents', 'ux-studio' ) }
				</button>
				<button className={ tab === 'subscribers' ? 'is-active' : '' } onClick={ () => setTab( 'subscribers' ) }>
					{ __( 'Subscribers', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'documents' && <DocumentsTab /> }
			{ tab === 'subscribers' && <SubscribersTab /> }
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
		</>
	);
}
