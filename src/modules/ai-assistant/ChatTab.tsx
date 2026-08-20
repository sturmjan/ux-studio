/**
 * Admin preview of public chat conversations: list, detail (message
 * transcript), rating display, single + bulk delete.
 *
 * Named export (not default) - the orchestrator wires this into
 * ai-assistant/Page.tsx's tab registry alongside Settings/Usage.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Star, Trash2, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface ConversationListItem {
	id: number;
	session_id: string;
	visitor_ip: string;
	page_url: string;
	status: string;
	gdpr_consent: 0 | 1;
	rating: number;
	rating_feedback: string | null;
	message_count: number;
	created_at: string;
	updated_at: string;
}

interface ChatMessage {
	role: string;
	content: string;
}

interface ConversationDetail extends ConversationListItem {
	messages: string;
	visitor_user_agent: string;
}

const PER_PAGE = 20;

function RatingStars( { rating }: { rating: number } ): JSX.Element {
	if ( rating <= 0 ) {
		return <span className="uxs-badge">{ __( 'Not rated', 'ux-studio' ) }</span>;
	}
	return (
		<span aria-label={ sprintf( __( '%d out of 5 stars', 'ux-studio' ), rating ) }>
			{ Array.from( { length: 5 } ).map( ( _, i ) => (
				<Star
					key={ i }
					size={ 14 }
					fill={ i < rating ? 'currentColor' : 'none' }
					style={ { color: 'var(--uxs-warning, #dba617)', verticalAlign: 'text-bottom' } }
				/>
			) ) }
		</span>
	);
}

function ConversationDetailModal( { id, onClose }: { id: number; onClose: () => void } ): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'ai-assistant', 'conversation', id ],
		queryFn: () => api< ConversationDetail >( `ai-assistant/conversations/${ id }` ),
	} );

	let messages: ChatMessage[] = [];
	if ( data?.messages ) {
		try {
			messages = JSON.parse( data.messages ) as ChatMessage[];
		} catch {
			messages = [];
		}
	}

	return (
		<div
			role="dialog"
			aria-modal="true"
			style={ {
				position: 'fixed',
				inset: 0,
				background: 'rgba(0,0,0,0.4)',
				display: 'flex',
				alignItems: 'center',
				justifyContent: 'center',
				zIndex: 1000,
			} }
			onClick={ onClose }
		>
			<div
				style={ {
					background: '#fff',
					borderRadius: 8,
					width: 'min(640px, 92vw)',
					maxHeight: '80vh',
					overflowY: 'auto',
					padding: 'var(--uxs-sp-5, 20px)',
				} }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-3, 12px)' } }>
					<h2 style={ { margin: 0 } }>{ __( 'Conversation', 'ux-studio' ) } #{ id }</h2>
					<button type="button" className="button-link" onClick={ onClose } aria-label={ __( 'Close', 'ux-studio' ) }>
						<X size={ 18 } />
					</button>
				</div>

				{ isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<>
						<p>
							<strong>{ __( 'Page:', 'ux-studio' ) }</strong> { data.page_url || '—' } { ' · ' }
							<strong>{ __( 'Rating:', 'ux-studio' ) }</strong> <RatingStars rating={ data.rating } />
						</p>
						{ data.rating_feedback ? (
							<p>
								<strong>{ __( 'Feedback:', 'ux-studio' ) }</strong> { data.rating_feedback }
							</p>
						) : null }

						<div style={ { display: 'flex', flexDirection: 'column', gap: 'var(--uxs-sp-2, 8px)' } }>
							{ messages.map( ( m, i ) => (
								<div
									key={ i }
									style={ {
										alignSelf: 'user' === m.role ? 'flex-end' : 'flex-start',
										background: 'user' === m.role ? 'var(--uxs-primary, #2271b1)' : 'var(--uxs-border, #f0f0f1)',
										color: 'user' === m.role ? '#fff' : '#1e1e1e',
										borderRadius: 10,
										padding: '8px 12px',
										maxWidth: '80%',
										whiteSpace: 'pre-wrap',
									} }
								>
									{ m.content }
								</div>
							) ) }
							{ ! messages.length ? <p>{ __( 'No messages.', 'ux-studio' ) }</p> : null }
						</div>
					</>
				) }
			</div>
		</div>
	);
}

export function ChatTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ ratingFilter, setRatingFilter ] = useState< string >( '' );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ detailId, setDetailId ] = useState< number | null >( null );

	const query = ratingFilter ? `&rating=${ ratingFilter }` : '';
	const { data, isLoading } = useQuery( {
		queryKey: [ 'ai-assistant', 'conversations', page, ratingFilter ],
		queryFn: () =>
			api< ConversationListItem[] >( `ai-assistant/conversations?page=${ page }&per_page=${ PER_PAGE }${ query }`, {
				method: 'GET',
			} ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/conversations/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'ai-assistant', 'conversations' ] } ),
	} );

	const bulkRemove = useMutation( {
		mutationFn: ( ids: number[] ) =>
			api( 'ai-assistant/conversations/bulk-delete', {
				method: 'POST',
				body: JSON.stringify( { ids } ),
			} ),
		onSuccess: () => {
			setSelected( [] );
			void queryClient.invalidateQueries( { queryKey: [ 'ai-assistant', 'conversations' ] } );
		},
	} );

	const toggleSelected = ( id: number ) => {
		setSelected( ( prev ) => ( prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ] ) );
	};

	return (
		<>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3, 12px)', alignItems: 'center', marginBottom: 'var(--uxs-sp-3, 12px)' } }>
				<label htmlFor="uxs-ai-conv-rating-filter">{ __( 'Filter by rating', 'ux-studio' ) }</label>
				<select
					id="uxs-ai-conv-rating-filter"
					value={ ratingFilter }
					onChange={ ( e ) => {
						setRatingFilter( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
					<option value="0">{ __( 'Not rated', 'ux-studio' ) }</option>
					{ [ 1, 2, 3, 4, 5 ].map( ( r ) => (
						<option key={ r } value={ r }>
							{ r } { __( 'stars', 'ux-studio' ) }
						</option>
					) ) }
				</select>

				{ selected.length > 0 && (
					<button
						type="button"
						className="button"
						disabled={ bulkRemove.isPending }
						onClick={ () => bulkRemove.mutate( selected ) }
					>
						<Trash2 size={ 14 } /> { sprintf( __( 'Delete selected (%d)', 'ux-studio' ), selected.length ) }
					</button>
				) }
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th></th>
							<th>{ __( 'Session', 'ux-studio' ) }</th>
							<th>{ __( 'Page', 'ux-studio' ) }</th>
							<th>{ __( 'Messages', 'ux-studio' ) }</th>
							<th>{ __( 'Rating', 'ux-studio' ) }</th>
							<th>{ __( 'Updated', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ ( data ?? [] ).map( ( c ) => (
							<tr key={ c.id }>
								<td>
									<input
										type="checkbox"
										checked={ selected.includes( c.id ) }
										onChange={ () => toggleSelected( c.id ) }
										aria-label={ __( 'Select conversation', 'ux-studio' ) }
									/>
								</td>
								<td>
									<button type="button" className="button-link" onClick={ () => setDetailId( c.id ) }>
										{ c.session_id.slice( 0, 8 ) }…
									</button>
								</td>
								<td>{ c.page_url || '—' }</td>
								<td>{ c.message_count }</td>
								<td>
									<RatingStars rating={ c.rating } />
								</td>
								<td>{ c.updated_at }</td>
								<td>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete conversation', 'ux-studio' ) }
										onClick={ () => remove.mutate( c.id ) }
										style={ { color: 'var(--uxs-danger)' } }
									>
										<Trash2 size={ 16 } />
									</button>
								</td>
							</tr>
						) ) }
						{ ! data?.length ? (
							<tr>
								<td colSpan={ 7 }>{ __( 'No conversations yet.', 'ux-studio' ) }</td>
							</tr>
						) : null }
					</tbody>
				</table>
			) }

			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2, 8px)', marginTop: 'var(--uxs-sp-3, 12px)' } }>
				<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
					{ __( 'Previous', 'ux-studio' ) }
				</button>
				<button
					type="button"
					className="button"
					disabled={ ( data?.length ?? 0 ) < PER_PAGE }
					onClick={ () => setPage( ( p ) => p + 1 ) }
				>
					{ __( 'Next', 'ux-studio' ) }
				</button>
			</div>

			{ null !== detailId && <ConversationDetailModal id={ detailId } onClose={ () => setDetailId( null ) } /> }
		</>
	);
}
