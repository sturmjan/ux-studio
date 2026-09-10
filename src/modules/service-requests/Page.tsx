import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Plus, RefreshCw, Send, Trash2, TriangleAlert } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'requests' | 'settings';
type Status = 'open' | 'in_progress' | 'done';
type SyncState = 'pending' | 'synced' | 'error';

interface ThreadMessage {
	id: number;
	author: string;
	author_kind: 'operator' | 'client' | 'system';
	body: string;
	created_at: string;
}

interface ServiceRequest {
	id: number;
	created_at: string;
	title: string;
	description: string | null;
	status: Status;
	requester_email: string;
	requester_name: string;
	page_url: string;
	type: string;
	priority: string;
	environment: Record< string, string >;
	attachment_id: number;
	/**
	 * Everything below mirrors the central app. Since F2 the central app owns
	 * the request - `status` above is only a coarse local copy, so anything
	 * shown to the client comes from `central_status`.
	 */
	central_ticket_id: number;
	central_number: string;
	central_status: string;
	thread: ThreadMessage[];
	sync_state: SyncState;
	sync_error: string;
	synced_at: string | null;
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

const CENTRAL_STATUS_LABELS: Record< string, string > = {
	new: __( 'New', 'ux-studio' ),
	triaged: __( 'Triaged', 'ux-studio' ),
	in_progress: __( 'In progress', 'ux-studio' ),
	waiting_client: __( 'Waiting for you', 'ux-studio' ),
	waiting_vendor: __( 'Waiting for a third party', 'ux-studio' ),
	resolved: __( 'Resolved', 'ux-studio' ),
	closed: __( 'Closed', 'ux-studio' ),
	rejected: __( 'Rejected', 'ux-studio' ),
};

const TYPE_LABELS: Record< string, string > = {
	bug: __( 'Something is broken', 'ux-studio' ),
	change: __( 'Change request', 'ux-studio' ),
	question: __( 'Question', 'ux-studio' ),
	task: __( 'Task', 'ux-studio' ),
};

const PRIORITY_LABELS: Record< string, string > = {
	low: __( 'Low', 'ux-studio' ),
	normal: __( 'Normal', 'ux-studio' ),
	high: __( 'High', 'ux-studio' ),
	critical: __( 'Critical - the site is unusable', 'ux-studio' ),
};

/** What the client sees as the state of their request. */
function statusLabel( item: ServiceRequest ): string {
	if ( item.central_status ) {
		return CENTRAL_STATUS_LABELS[ item.central_status ] ?? item.central_status;
	}
	return item.sync_state === 'error'
		? __( 'Not delivered', 'ux-studio' )
		: __( 'Sending…', 'ux-studio' );
}

/**
 * Attachment picker backed by the WP media modal only - no custom upload
 * endpoint, matching the DownloadFiles module's pattern.
 */
function AttachmentPicker( {
	attachment,
	onSelect,
}: {
	attachment: WpMediaAttachment | null;
	onSelect: ( attachment: WpMediaAttachment ) => void;
} ): JSX.Element {
	const wp = ( window as unknown as { wp?: { media?: ( opts: unknown ) => WpMedia } } ).wp;

	const pick = (): void => {
		if ( ! wp?.media ) {
			return;
		}
		const frame = wp.media( {
			title: __( 'Attach a file', 'ux-studio' ),
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
		<span className="uxs-media">
			<button type="button" className="button" onClick={ pick }>
				{ attachment ? __( 'Change attachment', 'ux-studio' ) : __( 'Attach a file', 'ux-studio' ) }
			</button>
			{ attachment ? <span> { attachment.filename ?? attachment.title ?? attachment.url }</span> : null }
		</span>
	);
}

function NewRequestForm(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ requesterEmail, setRequesterEmail ] = useState( '' );
	const [ pageUrl, setPageUrl ] = useState( '' );
	const [ type, setType ] = useState( 'bug' );
	const [ priority, setPriority ] = useState( 'normal' );
	const [ attachment, setAttachment ] = useState< WpMediaAttachment | null >( null );

	const create = useMutation( {
		mutationFn: () =>
			api< ServiceRequest >( 'service-requests/items', {
				method: 'POST',
				body: JSON.stringify( {
					title,
					description,
					requester_email: requesterEmail,
					page_url: pageUrl,
					type,
					priority,
					attachment_id: attachment?.id ?? 0,
				} ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
			setTitle( '' );
			setDescription( '' );
			setRequesterEmail( '' );
			setPageUrl( '' );
			setType( 'bug' );
			setPriority( 'normal' );
			setAttachment( null );
		},
	} );

	const canSubmit = title.trim() !== '' && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-title">{ __( 'What is it about?', 'ux-studio' ) }</label>
				<input id="uxs-sr-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-description">{ __( 'Description', 'ux-studio' ) }</label>
				<textarea
					id="uxs-sr-description"
					rows={ 4 }
					value={ description }
					onChange={ ( e ) => setDescription( e.target.value ) }
					placeholder={ __( 'What happens, where it is visible, and what you expected instead.', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-url">{ __( 'Page it happens on', 'ux-studio' ) }</label>
				<input
					id="uxs-sr-url"
					type="text"
					value={ pageUrl }
					onChange={ ( e ) => setPageUrl( e.target.value ) }
					placeholder="https://…"
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-type">{ __( 'Type', 'ux-studio' ) }</label>
				<select id="uxs-sr-type" value={ type } onChange={ ( e ) => setType( e.target.value ) }>
					{ Object.entries( TYPE_LABELS ).map( ( [ value, label ] ) => (
						<option key={ value } value={ value }>
							{ label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-priority">{ __( 'Urgency', 'ux-studio' ) }</label>
				<select id="uxs-sr-priority" value={ priority } onChange={ ( e ) => setPriority( e.target.value ) }>
					{ Object.entries( PRIORITY_LABELS ).map( ( [ value, label ] ) => (
						<option key={ value } value={ value }>
							{ label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-email">{ __( 'Contact email', 'ux-studio' ) }</label>
				<input
					id="uxs-sr-email"
					type="text"
					value={ requesterEmail }
					onChange={ ( e ) => setRequesterEmail( e.target.value ) }
					placeholder={ __( 'Leave blank to use your account email', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Attachment', 'ux-studio' ) }</label>
				<AttachmentPicker attachment={ attachment } onSelect={ setAttachment } />
			</div>
			<p className="uxs-form__help">
				{ __(
					'Details about this site (WordPress and PHP version, theme, plugins, your browser) are attached automatically, so you do not have to look them up.',
					'ux-studio'
				) }
			</p>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { __( 'Send request', 'ux-studio' ) }
			</button>
			{ create.isError ? <p className="uxs-form__help">{ ( create.error as Error ).message }</p> : null }
		</div>
	);
}

/** One request with its conversation, as mirrored from the central app. */
function RequestDetail( { item, onClose }: { item: ServiceRequest; onClose: () => void } ): JSX.Element {
	const [ body, setBody ] = useState( '' );

	const invalidate = (): void => {
		void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
	};

	const reply = useMutation( {
		mutationFn: () =>
			api( `service-requests/items/${ item.id }/reply`, { method: 'POST', body: JSON.stringify( { body } ) } ),
		onSuccess: () => {
			setBody( '' );
			invalidate();
		},
	} );

	const resync = useMutation( {
		mutationFn: () => api( `service-requests/items/${ item.id }/resync`, { method: 'POST' } ),
		onSuccess: invalidate,
	} );

	const envEntries = Object.entries( item.environment ?? {} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<button type="button" className="button" onClick={ onClose }>
				<ArrowLeft size={ 14 } /> { __( 'Back to the list', 'ux-studio' ) }
			</button>

			<h2 style={ { marginTop: 'var(--uxs-sp-4)' } }>
				{ item.central_number ? `${ item.central_number } — ` : '' }
				{ item.title }
			</h2>
			<p className="uxs-form__help">
				{ statusLabel( item ) } · { item.created_at }
				{ item.page_url ? ' · ' : '' }
				{ item.page_url ? (
					<a href={ item.page_url } target="_blank" rel="noreferrer">
						{ item.page_url }
					</a>
				) : null }
			</p>

			{ item.sync_state === 'error' ? (
				<p className="uxs-form__help">
					<TriangleAlert size={ 14 } />{ ' ' }
					{ __( 'This request has not reached us yet:', 'ux-studio' ) } { item.sync_error }
				</p>
			) : null }

			{ item.description ? <p style={ { whiteSpace: 'pre-wrap' } }>{ item.description }</p> : null }

			{ envEntries.length > 0 ? (
				<details style={ { marginTop: 'var(--uxs-sp-3)' } }>
					<summary>{ __( 'Site details sent with this request', 'ux-studio' ) }</summary>
					<table className="uxs-table">
						<tbody>
							{ envEntries.map( ( [ key, value ] ) => (
								<tr key={ key }>
									<td>{ key }</td>
									<td>{ value }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</details>
			) : null }

			<h3 style={ { marginTop: 'var(--uxs-sp-4)' } }>{ __( 'Conversation', 'ux-studio' ) }</h3>
			{ item.thread.length === 0 ? (
				<p className="uxs-form__help">{ __( 'No replies yet.', 'ux-studio' ) }</p>
			) : (
				<ul style={ { listStyle: 'none', padding: 0 } }>
					{ item.thread.map( ( message ) => (
						<li key={ message.id } style={ { marginBottom: 'var(--uxs-sp-3)' } }>
							<strong>{ message.author }</strong>{ ' ' }
							<span className="uxs-form__help">{ message.created_at }</span>
							<div style={ { whiteSpace: 'pre-wrap' } }>{ message.body }</div>
						</li>
					) ) }
				</ul>
			) }

			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-reply">{ __( 'Add a message', 'ux-studio' ) }</label>
				<textarea id="uxs-sr-reply" rows={ 3 } value={ body } onChange={ ( e ) => setBody( e.target.value ) } />
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ body.trim() === '' || reply.isPending || item.central_ticket_id === 0 }
				onClick={ () => reply.mutate() }
			>
				{ reply.isPending ? <LoaderCircle size={ 14 } /> : <Send size={ 14 } /> } { __( 'Send', 'ux-studio' ) }
			</button>{ ' ' }
			<button type="button" className="button" disabled={ resync.isPending } onClick={ () => resync.mutate() }>
				<RefreshCw size={ 14 } /> { __( 'Refresh', 'ux-studio' ) }
			</button>
			{ reply.isError ? <p className="uxs-form__help">{ ( reply.error as Error ).message }</p> : null }
			{ item.central_ticket_id === 0 ? (
				<p className="uxs-form__help">
					{ __( 'You can reply once the request reaches us. Use Refresh to try again now.', 'ux-studio' ) }
				</p>
			) : null }
		</div>
	);
}

function RequestsTable(): JSX.Element {
	const [ openId, setOpenId ] = useState< number | null >( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'service-requests', 'items' ],
		queryFn: () => api< ServiceRequest[] >( 'service-requests/items' ),
		// The status and the conversation live in the central app, so the list
		// goes stale on its own schedule, not on ours.
		refetchInterval: 60000,
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `service-requests/items/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
		},
	} );

	const open = data?.find( ( item ) => item.id === openId ) ?? null;
	if ( open ) {
		return <RequestDetail item={ open } onClose={ () => setOpenId( null ) } />;
	}

	return (
		<>
			<NewRequestForm />
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No service requests yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Number', 'ux-studio' ) }</th>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Created', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( item ) => (
							<tr key={ item.id }>
								<td>{ item.central_number || '—' }</td>
								<td>
									<button
										type="button"
										className="button-link"
										style={ { background: 'none', border: 'none', cursor: 'pointer', padding: 0 } }
										onClick={ () => setOpenId( item.id ) }
									>
										{ item.title }
									</button>
									{ item.thread.length > 0 ? (
										<span className="uxs-form__help"> · { item.thread.length }</span>
									) : null }
								</td>
								<td>
									{ statusLabel( item ) }
									{ item.sync_state === 'error' ? (
										<span title={ item.sync_error }>
											{ ' ' }
											<TriangleAlert size={ 14 } />
										</span>
									) : null }
								</td>
								<td>{ item.created_at }</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this service request?', 'ux-studio' ) ) ) {
												remove.mutate( item.id );
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
			<p className="uxs-form__help">
				{ __(
					'Deleting removes your local copy only. The request itself stays with us so nothing gets lost mid-conversation.',
					'ux-studio'
				) }
			</p>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'requests' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'service-requests' );

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
					{ __( 'Service Requests', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'requests' ? 'is-active' : '' } onClick={ () => setTab( 'requests' ) }>
					{ __( 'Requests', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'requests' && <RequestsTable /> }
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
