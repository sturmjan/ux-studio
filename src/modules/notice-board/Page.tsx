import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Pencil, Plus, Trash2, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'notices' | 'categories' | 'subscribers' | 'settings';

interface Attachment {
	id: number;
	url: string;
	filename: string;
	mime: string;
	size: number;
}

interface Notice {
	id: number;
	created_at: string;
	updated_at: string;
	title: string;
	body: string;
	category: string;
	reference: string;
	attachments: Attachment[];
	publish_date: string;
	expiry_date: string;
	is_archived: boolean;
}

interface Category {
	id: number;
	name: string;
	slug: string;
	sort_order: number;
	count: number;
}

interface Subscriber {
	id: number;
	created_at: string;
	email: string;
	confirmed: boolean;
	categories: string[];
}

interface WpMediaAttachment {
	id: number;
	url: string;
	filename?: string;
	title?: string;
	mime?: string;
}

interface WpMediaSelection {
	toJSON: () => WpMediaAttachment[];
}

interface WpMediaFrame {
	open: () => void;
	on: ( ev: string, cb: () => void ) => void;
	state: () => { get: ( k: string ) => WpMediaSelection };
}

interface NoticeDraft {
	title: string;
	body: string;
	category: string;
	reference: string;
	publish_date: string;
	expiry_date: string;
	attachments: Attachment[];
}

const emptyDraft: NoticeDraft = {
	title: '',
	body: '',
	category: '',
	reference: '',
	publish_date: '',
	expiry_date: '',
	attachments: [],
};

/** Open the WP media modal to pick one or more attachments. */
function pickAttachments( onSelect: ( atts: Attachment[] ) => void ): void {
	const wp = ( window as unknown as { wp?: { media?: ( opts: unknown ) => WpMediaFrame } } ).wp;
	if ( ! wp?.media ) {
		return;
	}
	const frame = wp.media( {
		title: __( 'Select attachments', 'ux-studio' ),
		multiple: 'add',
		library: {},
	} );
	frame.on( 'select', () => {
		const items = frame.state().get( 'selection' ).toJSON();
		onSelect(
			items.map( ( a ) => ( {
				id: a.id,
				url: a.url,
				filename: a.filename ?? a.title ?? a.url,
				mime: a.mime ?? '',
				size: 0,
			} ) )
		);
	} );
	frame.open();
}

function NoticeForm( {
	initial,
	editingId,
	categories,
	onDone,
}: {
	initial: NoticeDraft;
	editingId: number | null;
	categories: Category[];
	onDone: () => void;
} ): JSX.Element {
	const [ draft, setDraft ] = useState< NoticeDraft >( initial );

	const set = < K extends keyof NoticeDraft >( key: K, value: NoticeDraft[ K ] ): void =>
		setDraft( ( d ) => ( { ...d, [ key ]: value } ) );

	const payload = (): string =>
		JSON.stringify( {
			title: draft.title,
			body: draft.body,
			category: draft.category,
			reference: draft.reference,
			publish_date: draft.publish_date,
			expiry_date: draft.expiry_date,
			attachments: draft.attachments.map( ( a ) => a.id ),
		} );

	const save = useMutation( {
		mutationFn: () =>
			editingId
				? api< Notice >( `notice-board/notices/${ editingId }`, { method: 'PUT', body: payload() } )
				: api< Notice >( 'notice-board/notices', { method: 'POST', body: payload() } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'notices' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'categories' ] } );
			onDone();
		},
	} );

	const canSubmit = draft.title.trim() !== '' && ! save.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-title">{ __( 'Title', 'ux-studio' ) }</label>
				<input
					id="uxs-nb-title"
					type="text"
					value={ draft.title }
					onChange={ ( e ) => set( 'title', e.target.value ) }
					placeholder={ __( 'e.g. Public notice 4/2026', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-body">{ __( 'Body / description', 'ux-studio' ) }</label>
				<textarea id="uxs-nb-body" rows={ 5 } value={ draft.body } onChange={ ( e ) => set( 'body', e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-category">{ __( 'Category', 'ux-studio' ) }</label>
				<select id="uxs-nb-category" value={ draft.category } onChange={ ( e ) => set( 'category', e.target.value ) }>
					<option value="">{ __( '— None —', 'ux-studio' ) }</option>
					{ categories.map( ( c ) => (
						<option key={ c.id } value={ c.slug }>
							{ c.name }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-reference">{ __( 'Reference number', 'ux-studio' ) }</label>
				<input
					id="uxs-nb-reference"
					type="text"
					value={ draft.reference }
					onChange={ ( e ) => set( 'reference', e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-publish">{ __( 'Publish date', 'ux-studio' ) }</label>
				<input
					id="uxs-nb-publish"
					type="date"
					value={ draft.publish_date }
					onChange={ ( e ) => set( 'publish_date', e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-nb-expiry">{ __( 'Expiry date (auto-archives after)', 'ux-studio' ) }</label>
				<input
					id="uxs-nb-expiry"
					type="date"
					value={ draft.expiry_date }
					onChange={ ( e ) => set( 'expiry_date', e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Attachments', 'ux-studio' ) }</label>
				<div>
					<button
						type="button"
						className="button"
						onClick={ () =>
							pickAttachments( ( atts ) =>
								set(
									'attachments',
									// De-duplicate by id when appending.
									[ ...draft.attachments, ...atts ].filter(
										( a, i, arr ) => arr.findIndex( ( x ) => x.id === a.id ) === i
									)
								)
							)
						}
					>
						<Plus size={ 14 } /> { __( 'Add attachments', 'ux-studio' ) }
					</button>
					{ draft.attachments.length > 0 ? (
						<ul style={ { listStyle: 'none', margin: 'var(--uxs-sp-2) 0 0', padding: 0 } }>
							{ draft.attachments.map( ( a ) => (
								<li key={ a.id } style={ { display: 'flex', alignItems: 'center', gap: 'var(--uxs-sp-2)' } }>
									<a href={ a.url } target="_blank" rel="noreferrer">
										{ a.filename }
									</a>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Remove attachment', 'ux-studio' ) }
										onClick={ () =>
											set(
												'attachments',
												draft.attachments.filter( ( x ) => x.id !== a.id )
											)
										}
									>
										<X size={ 14 } />
									</button>
								</li>
							) ) }
						</ul>
					) : null }
				</div>
			</div>
			{ save.isError ? <p className="uxs-form__help">{ ( save.error as Error ).message }</p> : null }
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
				<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => save.mutate() }>
					{ save.isPending ? <LoaderCircle size={ 14 } /> : null }{ ' ' }
					{ editingId ? __( 'Save changes', 'ux-studio' ) : __( 'Add notice', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ onDone }>
					{ __( 'Cancel', 'ux-studio' ) }
				</button>
			</div>
		</div>
	);
}

function NoticesTab(): JSX.Element {
	const [ editing, setEditing ] = useState< NoticeDraft | null >( null );
	const [ editingId, setEditingId ] = useState< number | null >( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'notice-board', 'notices' ],
		queryFn: () => api< Notice[] >( 'notice-board/notices' ),
	} );
	const { data: categories } = useQuery( {
		queryKey: [ 'notice-board', 'categories' ],
		queryFn: () => api< Category[] >( 'notice-board/categories' ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `notice-board/notices/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'notices' ] } ),
	} );
	const toggleArchive = useMutation( {
		mutationFn: ( notice: Notice ) =>
			api( `notice-board/notices/${ notice.id }`, {
				method: 'PUT',
				body: JSON.stringify( { is_archived: ! notice.is_archived } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'notices' ] } ),
	} );

	const startEdit = ( n: Notice ): void => {
		setEditingId( n.id );
		setEditing( {
			title: n.title,
			body: n.body,
			category: n.category,
			reference: n.reference,
			publish_date: n.publish_date,
			expiry_date: n.expiry_date,
			attachments: n.attachments,
		} );
	};
	const done = (): void => {
		setEditing( null );
		setEditingId( null );
	};

	return (
		<>
			{ editing ? (
				<NoticeForm initial={ editing } editingId={ editingId } categories={ categories ?? [] } onDone={ done } />
			) : (
				<button
					type="button"
					className="button button-primary"
					style={ { marginBottom: 'var(--uxs-sp-4)' } }
					onClick={ () => {
						setEditingId( null );
						setEditing( emptyDraft );
					} }
				>
					<Plus size={ 14 } /> { __( 'New notice', 'ux-studio' ) }
				</button>
			) }

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No notices yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th>{ __( 'Published', 'ux-studio' ) }</th>
							<th>{ __( 'Expires', 'ux-studio' ) }</th>
							<th>{ __( 'Files', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( n ) => (
							<tr key={ n.id }>
								<td>{ n.title }</td>
								<td>{ n.category || '—' }</td>
								<td>{ n.publish_date || '—' }</td>
								<td>{ n.expiry_date || '—' }</td>
								<td>{ n.attachments.length }</td>
								<td>
									<span className={ `uxs-badge ${ n.is_archived ? '' : 'is-success' }` }>
										{ n.is_archived ? __( 'Archived', 'ux-studio' ) : __( 'Active', 'ux-studio' ) }
									</span>
								</td>
								<td>
									<div style={ { display: 'flex', gap: 'var(--uxs-sp-1)' } }>
										<button type="button" className="button" onClick={ () => startEdit( n ) }>
											<Pencil size={ 14 } />
										</button>
										<button type="button" className="button" onClick={ () => toggleArchive.mutate( n ) }>
											{ n.is_archived ? __( 'Unarchive', 'ux-studio' ) : __( 'Archive', 'ux-studio' ) }
										</button>
										<button
											type="button"
											className="button"
											disabled={ remove.isPending }
											onClick={ () => {
												if ( window.confirm( __( 'Delete this notice?', 'ux-studio' ) ) ) {
													remove.mutate( n.id );
												}
											} }
										>
											<Trash2 size={ 14 } />
										</button>
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
		</>
	);
}

function CategoriesTab(): JSX.Element {
	const [ name, setName ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'notice-board', 'categories' ],
		queryFn: () => api< Category[] >( 'notice-board/categories' ),
	} );

	const add = useMutation( {
		mutationFn: () => api< Category >( 'notice-board/categories', { method: 'POST', body: JSON.stringify( { name } ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'categories' ] } );
			setName( '' );
		},
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `notice-board/categories/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'categories' ] } ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-nb-catname">{ __( 'New category', 'ux-studio' ) }</label>
					<input
						id="uxs-nb-catname"
						type="text"
						value={ name }
						onChange={ ( e ) => setName( e.target.value ) }
						placeholder={ __( 'Category name', 'ux-studio' ) }
					/>
					<button
						type="button"
						className="button button-primary"
						disabled={ name.trim() === '' || add.isPending }
						onClick={ () => add.mutate() }
						style={ { marginLeft: 'var(--uxs-sp-2)' } }
					>
						<Plus size={ 14 } /> { __( 'Add', 'ux-studio' ) }
					</button>
				</div>
				{ add.isError ? <p className="uxs-form__help">{ ( add.error as Error ).message }</p> : null }
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No categories yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Slug', 'ux-studio' ) }</th>
							<th>{ __( 'Notices', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( c ) => (
							<tr key={ c.id }>
								<td>{ c.name }</td>
								<td>
									<code>{ c.slug }</code>
								</td>
								<td>{ c.count }</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this category?', 'ux-studio' ) ) ) {
												remove.mutate( c.id );
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
	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `notice-board/subscribers/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'notice-board', 'subscribers' ] } ),
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
					<th>{ __( 'Categories', 'ux-studio' ) }</th>
					<th>{ __( 'Confirmed', 'ux-studio' ) }</th>
					<th>{ __( 'Actions', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( s ) => (
					<tr key={ s.id }>
						<td>{ s.created_at }</td>
						<td>{ s.email }</td>
						<td>{ s.categories.length > 0 ? s.categories.join( ', ' ) : __( 'All', 'ux-studio' ) }</td>
						<td>
							<span className={ `uxs-badge ${ s.confirmed ? 'is-success' : '' }` }>
								{ s.confirmed ? __( 'Yes', 'ux-studio' ) : __( 'Pending', 'ux-studio' ) }
							</span>
						</td>
						<td>
							<button
								type="button"
								className="button"
								disabled={ remove.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Remove this subscriber?', 'ux-studio' ) ) ) {
										remove.mutate( s.id );
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
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'notices' );
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
				<button className={ tab === 'notices' ? 'is-active' : '' } onClick={ () => setTab( 'notices' ) }>
					{ __( 'Notices', 'ux-studio' ) }
				</button>
				<button className={ tab === 'categories' ? 'is-active' : '' } onClick={ () => setTab( 'categories' ) }>
					{ __( 'Categories', 'ux-studio' ) }
				</button>
				<button className={ tab === 'subscribers' ? 'is-active' : '' } onClick={ () => setTab( 'subscribers' ) }>
					{ __( 'Subscribers', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'notices' && <NoticesTab /> }
			{ tab === 'categories' && <CategoriesTab /> }
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
