import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'requests' | 'settings';
type Status = 'open' | 'in_progress' | 'done';

interface ServiceRequest {
	id: number;
	created_at: string;
	title: string;
	description: string | null;
	status: Status;
	requester_email: string;
	attachment_id: number;
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

const STATUS_LABELS: Record< Status, string > = {
	open: __( 'Open', 'ux-studio' ),
	in_progress: __( 'In progress', 'ux-studio' ),
	done: __( 'Done', 'ux-studio' ),
};

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
	const [ attachment, setAttachment ] = useState< WpMediaAttachment | null >( null );

	const create = useMutation( {
		mutationFn: () =>
			api< ServiceRequest >( 'service-requests/items', {
				method: 'POST',
				body: JSON.stringify( {
					title,
					description,
					requester_email: requesterEmail,
					attachment_id: attachment?.id ?? 0,
				} ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
			setTitle( '' );
			setDescription( '' );
			setRequesterEmail( '' );
			setAttachment( null );
		},
	} );

	const canSubmit = title.trim() !== '' && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-title">{ __( 'Title', 'ux-studio' ) }</label>
				<input id="uxs-sr-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-description">{ __( 'Description', 'ux-studio' ) }</label>
				<textarea
					id="uxs-sr-description"
					rows={ 4 }
					value={ description }
					onChange={ ( e ) => setDescription( e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-sr-email">{ __( 'Requester email', 'ux-studio' ) }</label>
				<input
					id="uxs-sr-email"
					type="text"
					value={ requesterEmail }
					onChange={ ( e ) => setRequesterEmail( e.target.value ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Attachment', 'ux-studio' ) }</label>
				<AttachmentPicker attachment={ attachment } onSelect={ setAttachment } />
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { __( 'Create request', 'ux-studio' ) }
			</button>
			{ create.isError ? <p className="uxs-form__help">{ ( create.error as Error ).message }</p> : null }
		</div>
	);
}

function RequestsTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'service-requests', 'items' ],
		queryFn: () => api< ServiceRequest[] >( 'service-requests/items' ),
	} );

	const updateStatus = useMutation( {
		mutationFn: ( { id, status }: { id: number; status: Status } ) =>
			api( `service-requests/items/${ id }/status`, { method: 'POST', body: JSON.stringify( { status } ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
		},
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `service-requests/items/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'service-requests', 'items' ] } );
		},
	} );

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
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Requester', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Created', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( item ) => (
							<tr key={ item.id }>
								<td>{ item.title }</td>
								<td>{ item.requester_email }</td>
								<td>
									<select
										value={ item.status }
										onChange={ ( e ) => updateStatus.mutate( { id: item.id, status: e.target.value as Status } ) }
									>
										{ Object.entries( STATUS_LABELS ).map( ( [ value, label ] ) => (
											<option key={ value } value={ value }>
												{ label }
											</option>
										) ) }
									</select>
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
