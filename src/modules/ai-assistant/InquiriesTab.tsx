/**
 * Admin inbox for the inquiries the chat widget collects (contact messages and callback requests): filters,
 * mark handled / new, delete (single and bulk), link to the related conversation.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, LoaderCircle, RotateCcw, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';
import { ConversationDetailModal } from './ChatTab';

type InquiryStatus = 'new' | 'handled';

interface Inquiry {
	id: number;
	type: string;
	name: string;
	email: string;
	phone: string;
	message: string;
	page_url: string;
	status: InquiryStatus;
	created_at: string;
	conversation_id: number | null;
}

interface InquiryList {
	items: Inquiry[];
	total: number;
	page: number;
	per_page: number;
	pages: number;
	new_count: number;
}

const PER_PAGE = 20;
const QK_INQUIRIES = [ 'ai-assistant', 'inquiries' ];

/** Count of new inquiries for the tab badge. */
export function useNewInquiryCount(): number {
	const { data } = useQuery( {
		queryKey: [ ...QK_INQUIRIES, 'badge' ],
		queryFn: () => api< InquiryList >( 'ai-assistant/inquiries?per_page=1&status=new' ),
	} );
	return data?.total ?? 0;
}

function typeLabel( type: string ): string {
	if ( 'callback' === type ) {
		return __( 'Callback', 'ux-studio' );
	}
	return 'contact' === type ? __( 'Contact', 'ux-studio' ) : type;
}

export function InquiriesTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ type, setType ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ conversationId, setConversationId ] = useState< number | null >( null );

	const params = new URLSearchParams( { page: String( page ), per_page: String( PER_PAGE ) } );
	if ( type ) {
		params.set( 'type', type );
	}
	if ( status ) {
		params.set( 'status', status );
	}

	const { data, isLoading } = useQuery( {
		queryKey: [ ...QK_INQUIRIES, page, type, status ],
		queryFn: () => api< InquiryList >( `ai-assistant/inquiries?${ params.toString() }` ),
	} );

	const refresh = () => {
		setSelected( [] );
		void queryClient.invalidateQueries( { queryKey: QK_INQUIRIES } );
	};

	const setOne = useMutation( {
		mutationFn: ( v: { id: number; status: InquiryStatus } ) =>
			api( `ai-assistant/inquiries/${ v.id }/status`, { method: 'POST', body: JSON.stringify( { status: v.status } ) } ),
		onSuccess: refresh,
	} );
	const setMany = useMutation( {
		mutationFn: ( v: { ids: number[]; status: InquiryStatus } ) =>
			api( 'ai-assistant/inquiries/bulk-status', { method: 'POST', body: JSON.stringify( v ) } ),
		onSuccess: refresh,
	} );
	const removeOne = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/inquiries/${ id }`, { method: 'DELETE' } ),
		onSuccess: refresh,
	} );
	const removeMany = useMutation( {
		mutationFn: ( ids: number[] ) => api( 'ai-assistant/inquiries/bulk-delete', { method: 'POST', body: JSON.stringify( { ids } ) } ),
		onSuccess: refresh,
	} );

	const items = data?.items ?? [];
	const toggle = ( id: number ) => setSelected( ( prev ) => ( prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ] ) );
	const allSelected = items.length > 0 && items.every( ( i ) => selected.includes( i.id ) );
	const busy = setOne.isPending || setMany.isPending || removeOne.isPending || removeMany.isPending;

	return (
		<>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3, 12px)', alignItems: 'center', flexWrap: 'wrap', marginBottom: 'var(--uxs-sp-3, 12px)' } }>
				<label htmlFor="uxs-ai-inq-type">{ __( 'Type', 'ux-studio' ) }</label>
				<select
					id="uxs-ai-inq-type"
					value={ type }
					onChange={ ( e ) => {
						setType( e.target.value );
						setPage( 1 );
						setSelected( [] );
					} }
				>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
					<option value="contact">{ __( 'Contact', 'ux-studio' ) }</option>
					<option value="callback">{ __( 'Callback', 'ux-studio' ) }</option>
				</select>
				<label htmlFor="uxs-ai-inq-status">{ __( 'Status', 'ux-studio' ) }</label>
				<select
					id="uxs-ai-inq-status"
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value );
						setPage( 1 );
						setSelected( [] );
					} }
				>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
					<option value="new">{ __( 'New', 'ux-studio' ) }</option>
					<option value="handled">{ __( 'Handled', 'ux-studio' ) }</option>
				</select>

				{ selected.length > 0 && (
					<>
						<button type="button" className="button" disabled={ busy } onClick={ () => setMany.mutate( { ids: selected, status: 'handled' } ) }>
							<Check size={ 14 } /> { sprintf( __( 'Mark handled (%d)', 'ux-studio' ), selected.length ) }
						</button>
						<button type="button" className="button" disabled={ busy } onClick={ () => setMany.mutate( { ids: selected, status: 'new' } ) }>
							<RotateCcw size={ 14 } /> { sprintf( __( 'Mark new (%d)', 'ux-studio' ), selected.length ) }
						</button>
						<button
							type="button"
							className="button"
							disabled={ busy }
							onClick={ () => {
								if ( window.confirm( __( 'Delete the selected inquiries?', 'ux-studio' ) ) ) {
									removeMany.mutate( selected );
								}
							} }
						>
							<Trash2 size={ 14 } /> { sprintf( __( 'Delete selected (%d)', 'ux-studio' ), selected.length ) }
						</button>
					</>
				) }
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<div className="uxs-table-wrap">
					<table className="uxs-table">
						<thead>
							<tr>
								<th>
									<input
										type="checkbox"
										checked={ allSelected }
										onChange={ () => setSelected( allSelected ? [] : items.map( ( i ) => i.id ) ) }
										aria-label={ __( 'Select all', 'ux-studio' ) }
									/>
								</th>
								<th>{ __( 'Date', 'ux-studio' ) }</th>
								<th>{ __( 'Type', 'ux-studio' ) }</th>
								<th>{ __( 'Name', 'ux-studio' ) }</th>
								<th>{ __( 'E-mail', 'ux-studio' ) }</th>
								<th>{ __( 'Phone', 'ux-studio' ) }</th>
								<th>{ __( 'Message', 'ux-studio' ) }</th>
								<th>{ __( 'Page', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( i ) => (
								<tr key={ i.id }>
									<td>
										<input type="checkbox" checked={ selected.includes( i.id ) } onChange={ () => toggle( i.id ) } aria-label={ __( 'Select inquiry', 'ux-studio' ) } />
									</td>
									<td>{ formatDate( i.created_at ) }</td>
									<td>{ typeLabel( i.type ) }</td>
									<td>{ i.name || '—' }</td>
									<td>{ i.email ? <a href={ `mailto:${ i.email }` }>{ i.email }</a> : '—' }</td>
									<td>{ i.phone ? <a href={ `tel:${ i.phone.replace( /[^+\d]/g, '' ) }` }>{ i.phone }</a> : '—' }</td>
									<td style={ { whiteSpace: 'pre-wrap', maxWidth: 360 } }>{ i.message || '—' }</td>
									<td>
										{ /^https?:\/\//i.test( i.page_url ) ? (
											<a href={ i.page_url } target="_blank" rel="noopener noreferrer">
												{ i.page_url }
											</a>
										) : (
											i.page_url || '—'
										) }
									</td>
									<td>
										<span className={ `uxs-badge${ 'new' === i.status ? ' is-warning' : ' is-success' }` }>
											{ 'new' === i.status ? __( 'New', 'ux-studio' ) : __( 'Handled', 'ux-studio' ) }
										</span>
										{ null !== i.conversation_id && (
											<>
												{ ' ' }
												<button type="button" className="button-link" onClick={ () => setConversationId( i.conversation_id ) }>
													{ __( 'Conversation', 'ux-studio' ) } #{ i.conversation_id }
												</button>
											</>
										) }
									</td>
									<td style={ { whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="button-link"
											disabled={ busy }
											onClick={ () => setOne.mutate( { id: i.id, status: 'new' === i.status ? 'handled' : 'new' } ) }
										>
											{ 'new' === i.status ? __( 'Mark handled', 'ux-studio' ) : __( 'Mark new', 'ux-studio' ) }
										</button>
										{ ' ' }
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Delete inquiry', 'ux-studio' ) }
											disabled={ busy }
											onClick={ () => {
												if ( window.confirm( __( 'Delete this inquiry?', 'ux-studio' ) ) ) {
													removeOne.mutate( i.id );
												}
											} }
											style={ { color: 'var(--uxs-danger)' } }
										>
											<Trash2 size={ 16 } />
										</button>
									</td>
								</tr>
							) ) }
							{ ! items.length ? (
								<tr>
									<td colSpan={ 10 }>{ __( 'No inquiries yet.', 'ux-studio' ) }</td>
								</tr>
							) : null }
						</tbody>
					</table>
				</div>
			) }

			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2, 8px)', alignItems: 'center', marginTop: 'var(--uxs-sp-3, 12px)' } }>
				<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
					{ __( 'Previous', 'ux-studio' ) }
				</button>
				<span>{ sprintf( __( 'Page %1$d of %2$d', 'ux-studio' ), page, data?.pages ?? 1 ) }</span>
				<button type="button" className="button" disabled={ page >= ( data?.pages ?? 1 ) } onClick={ () => setPage( ( p ) => p + 1 ) }>
					{ __( 'Next', 'ux-studio' ) }
				</button>
			</div>

			{ null !== conversationId && <ConversationDetailModal id={ conversationId } onClose={ () => setConversationId( null ) } /> }
		</>
	);
}
