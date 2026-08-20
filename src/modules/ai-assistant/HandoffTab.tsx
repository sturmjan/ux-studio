/**
 * Admin inbox for the human-handoff flow: queue of conversations waiting for
 * an operator, list of conversations already assigned, and a chat panel to
 * pick up / reply to / close the selected conversation.
 *
 * Named export (not default) - the orchestrator wires this into
 * ai-assistant/Page.tsx's tab registry alongside Settings/Usage/Conversations.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Send, UserCheck, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface QueueItem {
	id: number;
	session_id: string;
	visitor_ip: string;
	page_url: string;
	status: string;
	last_user_message: string;
	message_count: number;
	operator_unread: number;
	assigned_to: string | null;
	requested_at: string | null;
	started_at: string | null;
	last_message_at: string | null;
}

interface HandoffMessage {
	role: 'user' | 'assistant' | 'agent' | 'system';
	content: string;
	agent_name?: string;
	timestamp?: string;
}

interface ConversationDetail {
	id: number;
	session_id: string;
	visitor_ip: string;
	page_url: string;
	handoff_status: string;
	handoff_requested_at: string | null;
	handoff_started_at: string | null;
	handoff_assigned_to: number | null;
	assigned_to_name: string | null;
	messages: HandoffMessage[];
	total_count: number;
	created_at: string;
	updated_at: string;
}

const QK_QUEUE = [ 'ai-assistant', 'handoff', 'queue' ];
const QK_ACTIVE = [ 'ai-assistant', 'handoff', 'active' ];
const QUEUE_POLL_INTERVAL = 5000;
const CONVERSATION_POLL_INTERVAL = 3000;

const STATUS_LABELS: Record< string, string > = {
	ai: __( 'AI', 'ux-studio' ),
	requested: __( 'Waiting', 'ux-studio' ),
	human: __( 'With operator', 'ux-studio' ),
	closed: __( 'Closed', 'ux-studio' ),
};

function StatusBadge( { status }: { status: string } ): JSX.Element {
	const isWaiting = 'requested' === status;
	const isActive = 'human' === status;
	return (
		<span className={ `uxs-badge${ isActive ? ' is-success' : '' }${ isWaiting ? ' is-warning' : '' }` }>
			{ STATUS_LABELS[ status ] ?? status }
		</span>
	);
}

function QueueList( { selectedId, onSelect }: { selectedId: number | null; onSelect: ( id: number ) => void } ): JSX.Element {
	const pending = useQuery( {
		queryKey: QK_QUEUE,
		queryFn: () => api< QueueItem[] >( 'ai-assistant/handoff/queue' ),
		refetchInterval: QUEUE_POLL_INTERVAL,
	} );

	const active = useQuery( {
		queryKey: QK_ACTIVE,
		queryFn: () => api< QueueItem[] >( 'ai-assistant/handoff/active' ),
		refetchInterval: QUEUE_POLL_INTERVAL,
	} );

	const renderItem = ( item: QueueItem, isPending: boolean ) => (
		<button
			key={ item.id }
			type="button"
			className={ `uxs-handoff-queue-item${ selectedId === item.id ? ' is-selected' : '' }${ isPending ? ' is-pending' : '' }` }
			onClick={ () => onSelect( item.id ) }
		>
			<div className="uxs-handoff-queue-item__title">
				{ item.visitor_ip || __( 'Visitor', 'ux-studio' ) }
				{ item.operator_unread > 0 && <span className="uxs-badge is-danger">{ item.operator_unread }</span> }
			</div>
			{ item.last_user_message ? <div className="uxs-handoff-queue-item__msg">{ item.last_user_message }</div> : null }
			<div className="uxs-handoff-queue-item__meta">
				{ ! isPending && item.assigned_to ? `${ item.assigned_to } · ` : '' }
				{ isPending ? item.requested_at : item.last_message_at }
			</div>
		</button>
	);

	const isLoading = pending.isLoading || active.isLoading;
	const hasPending = ( pending.data ?? [] ).length > 0;
	const hasActive = ( active.data ?? [] ).length > 0;

	return (
		<div className="uxs-handoff-queue">
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<>
					{ hasPending && (
						<>
							<div className="uxs-handoff-queue__label">{ __( 'Waiting for pickup', 'ux-studio' ) }</div>
							{ ( pending.data ?? [] ).map( ( item ) => renderItem( item, true ) ) }
						</>
					) }
					{ hasActive && (
						<>
							<div className="uxs-handoff-queue__label">{ __( 'Active handoffs', 'ux-studio' ) }</div>
							{ ( active.data ?? [] ).map( ( item ) => renderItem( item, false ) ) }
						</>
					) }
					{ ! hasPending && ! hasActive && <p>{ __( 'No handoff requests right now.', 'ux-studio' ) }</p> }
				</>
			) }
		</div>
	);
}

function ConversationPanel( { id }: { id: number } ): JSX.Element {
	const [ reply, setReply ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'ai-assistant', 'handoff', 'conversation', id ],
		queryFn: () => api< ConversationDetail >( `ai-assistant/handoff/${ id }/messages` ),
		refetchInterval: CONVERSATION_POLL_INTERVAL,
	} );

	const invalidateAll = () => {
		void queryClient.invalidateQueries( { queryKey: [ 'ai-assistant', 'handoff' ] } );
	};

	const assign = useMutation( {
		mutationFn: () => api( `ai-assistant/handoff/${ id }/assign`, { method: 'POST' } ),
		onSuccess: invalidateAll,
	} );

	const sendReply = useMutation( {
		mutationFn: ( message: string ) =>
			api( `ai-assistant/handoff/${ id }/message`, { method: 'POST', body: JSON.stringify( { message } ) } ),
		onSuccess: () => {
			setReply( '' );
			invalidateAll();
		},
	} );

	const close = useMutation( {
		mutationFn: ( returnToAi: boolean ) =>
			api( `ai-assistant/handoff/${ id }/close`, { method: 'POST', body: JSON.stringify( { return_to_ai: returnToAi } ) } ),
		onSuccess: invalidateAll,
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const canAssign = 'requested' === data.handoff_status;
	const canReply = 'requested' === data.handoff_status || 'human' === data.handoff_status;
	const canClose = 'requested' === data.handoff_status || 'human' === data.handoff_status;

	const submitReply = () => {
		const trimmed = reply.trim();
		if ( ! trimmed || sendReply.isPending ) {
			return;
		}
		sendReply.mutate( trimmed );
	};

	return (
		<div className="uxs-handoff-conversation">
			<div className="uxs-handoff-conversation__header">
				<div>
					<strong>{ __( 'Conversation', 'ux-studio' ) } #{ data.id }</strong> <StatusBadge status={ data.handoff_status } />
					{ data.assigned_to_name && (
						<span className="uxs-handoff-conversation__assigned"> · { data.assigned_to_name }</span>
					) }
				</div>
				<div className="uxs-handoff-conversation__actions">
					{ canAssign && (
						<button type="button" className="button button-primary" disabled={ assign.isPending } onClick={ () => assign.mutate() }>
							<UserCheck size={ 14 } /> { __( 'Take over', 'ux-studio' ) }
						</button>
					) }
					{ canClose && (
						<>
							<button type="button" className="button" disabled={ close.isPending } onClick={ () => close.mutate( true ) }>
								{ __( 'Return to AI', 'ux-studio' ) }
							</button>
							<button type="button" className="button" disabled={ close.isPending } onClick={ () => close.mutate( false ) }>
								<X size={ 14 } /> { __( 'Close', 'ux-studio' ) }
							</button>
						</>
					) }
				</div>
			</div>

			<div className="uxs-handoff-conversation__messages">
				{ data.messages.map( ( m, i ) => (
					<div key={ i } className={ `uxs-handoff-msg uxs-handoff-msg--${ m.role }` }>
						{ 'agent' === m.role && m.agent_name ? <div className="uxs-handoff-msg__author">{ m.agent_name }</div> : null }
						<div className="uxs-handoff-msg__text">{ m.content }</div>
					</div>
				) ) }
				{ ! data.messages.length ? <p>{ __( 'No messages yet.', 'ux-studio' ) }</p> : null }
			</div>

			{ canReply && (
				<div className="uxs-handoff-conversation__input">
					<textarea
						value={ reply }
						onChange={ ( e ) => setReply( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( 'Enter' === e.key && ( e.ctrlKey || e.metaKey ) ) {
								e.preventDefault();
								submitReply();
							}
						} }
						placeholder={ __( 'Reply to the customer…', 'ux-studio' ) }
						rows={ 3 }
					/>
					<button type="button" className="button button-primary" disabled={ sendReply.isPending || ! reply.trim() } onClick={ submitReply }>
						<Send size={ 14 } /> { __( 'Send', 'ux-studio' ) }
					</button>
				</div>
			) }
		</div>
	);
}

export function HandoffTab(): JSX.Element {
	const [ selectedId, setSelectedId ] = useState< number | null >( null );

	return (
		<div className="uxs-handoff">
			<div className="uxs-handoff__sidebar">
				<QueueList selectedId={ selectedId } onSelect={ setSelectedId } />
			</div>
			<div className="uxs-handoff__main">
				{ null === selectedId ? (
					<p>{ __( 'Select a conversation from the list on the left.', 'ux-studio' ) }</p>
				) : (
					<ConversationPanel id={ selectedId } />
				) }
			</div>
		</div>
	);
}
