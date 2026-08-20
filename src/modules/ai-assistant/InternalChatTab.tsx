/**
 * Internal AI assistant chat: a React tab inside the admin SPA for the
 * logged-in editor/admin using this site (not the public-facing widget from
 * ChatTab.tsx, which only *previews* visitor conversations read-only).
 *
 * Streaming is implemented with fetch() + a ReadableStream reader (see
 * readStream() below) because this is a POST endpoint that returns
 * text/event-stream - EventSource can't send a POST body/nonce header, so it
 * isn't usable here. The wire format (lines starting "data: {...}") mirrors
 * InternalChatRestController::chat() on the PHP side.
 *
 * Named export (not default) - the orchestrator wires this into
 * ai-assistant/Page.tsx's tab registry.
 */
import { useEffect, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Plus, Send, Trash2 } from 'lucide-react';
import { queryClient } from '../../app/api';

interface ChatMessage {
	role: 'user' | 'assistant';
	content: string;
}

interface HistoryListItem {
	id: number;
	title: string;
	provider: string;
	model: string;
	created_at: string;
	updated_at: string;
}

interface HistoryDetail {
	id: number;
	title: string;
	messages: ChatMessage[];
	provider: string;
	model: string;
}

interface LimitStatus {
	used: number;
	limit: number;
	percent: number;
	remaining: number;
}

const QK_HISTORY = [ 'ai-assistant', 'internal-chat', 'history' ];
const QK_LIMITS = [ 'ai-assistant', 'internal-chat', 'limits' ];

const LIMIT_LABELS: Record< string, string > = {
	monthly_tokens: __( 'Monthly tokens', 'ux-studio' ),
	daily_requests: __( 'Daily requests (site-wide)', 'ux-studio' ),
	user_daily_requests: __( 'Your daily requests', 'ux-studio' ),
};

/**
 * Reads a fetch() SSE body and calls onEvent for each parsed "data: {...}"
 * payload. Same wire contract as the public widget's chat() handler, just
 * consumed with the Fetch API's ReadableStream reader instead of vanilla JS
 * DOM manipulation (see the legacy ai-assistant-internal-chat.js widget this
 * tab replaces for the non-React version of this loop).
 */
async function readStream( response: Response, onEvent: ( data: Record< string, unknown > ) => void ): Promise< void > {
	const body = response.body;
	if ( ! body ) {
		return;
	}
	const reader = body.getReader();
	const decoder = new TextDecoder();
	let buffer = '';

	for ( ;; ) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		buffer += decoder.decode( value, { stream: true } );

		const lines = buffer.split( '\n' );
		buffer = lines.pop() ?? '';

		for ( const line of lines ) {
			if ( ! line.startsWith( 'data: ' ) ) {
				continue;
			}
			try {
				onEvent( JSON.parse( line.slice( 6 ) ) as Record< string, unknown > );
			} catch {
				// Skip malformed SSE lines.
			}
		}
	}
}

function HistorySidebar( {
	selectedId,
	onSelect,
	onNew,
}: {
	selectedId: number | null;
	onSelect: ( id: number ) => void;
	onNew: () => void;
} ): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_HISTORY,
		queryFn: async (): Promise< HistoryListItem[] > => {
			const boot = window.uxStudioBoot;
			const res = await fetch( `${ boot.restUrl }/ai-assistant/internal-chat/history`, {
				headers: { 'X-WP-Nonce': boot.nonce },
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
			const json: { data: HistoryListItem[] } = await res.json();
			return json.data;
		},
	} );

	const remove = useMutation( {
		mutationFn: async ( id: number ) => {
			const boot = window.uxStudioBoot;
			const res = await fetch( `${ boot.restUrl }/ai-assistant/internal-chat/history/${ id }`, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': boot.nonce },
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
		},
		onSuccess: ( _data, id ) => {
			void queryClient.invalidateQueries( { queryKey: QK_HISTORY } );
			if ( id === selectedId ) {
				onNew();
			}
		},
	} );

	return (
		<div className="uxs-internal-chat__sidebar" style={ { width: 240, flexShrink: 0, borderRight: '1px solid var(--uxs-border, #e2e2e2)', paddingRight: 'var(--uxs-sp-3, 12px)' } }>
			<button type="button" className="button" style={ { width: '100%', marginBottom: 'var(--uxs-sp-3, 12px)' } } onClick={ onNew }>
				<Plus size={ 14 } /> { __( 'New conversation', 'ux-studio' ) }
			</button>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<ul style={ { listStyle: 'none', margin: 0, padding: 0 } }>
					{ ( data ?? [] ).map( ( item ) => (
						<li
							key={ item.id }
							style={ {
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'space-between',
								gap: 4,
								padding: '6px 8px',
								borderRadius: 4,
								cursor: 'pointer',
								background: item.id === selectedId ? 'var(--uxs-border, #f0f0f1)' : 'transparent',
							} }
						>
							<button
								type="button"
								className="button-link"
								style={ { textAlign: 'left', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }
								onClick={ () => onSelect( item.id ) }
								title={ item.title }
							>
								{ item.title || __( 'New conversation', 'ux-studio' ) }
							</button>
							<button
								type="button"
								className="button-link"
								aria-label={ __( 'Delete conversation', 'ux-studio' ) }
								style={ { color: 'var(--uxs-danger)' } }
								onClick={ () => remove.mutate( item.id ) }
							>
								<Trash2 size={ 14 } />
							</button>
						</li>
					) ) }
					{ ! data?.length ? <li style={ { padding: '6px 8px', opacity: 0.7 } }>{ __( 'No conversations yet.', 'ux-studio' ) }</li> : null }
				</ul>
			) }
		</div>
	);
}

function LimitsBar(): JSX.Element | null {
	const { data } = useQuery( {
		queryKey: QK_LIMITS,
		queryFn: async (): Promise< Record< string, LimitStatus > > => {
			const boot = window.uxStudioBoot;
			const res = await fetch( `${ boot.restUrl }/ai-assistant/internal-chat/limits`, {
				headers: { 'X-WP-Nonce': boot.nonce },
			} );
			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}
			const json: { data: Record< string, LimitStatus > } = await res.json();
			return json.data;
		},
	} );

	const entries = Object.entries( data ?? {} );
	if ( ! entries.length ) {
		return null;
	}

	return (
		<div style={ { display: 'flex', gap: 'var(--uxs-sp-4, 16px)', flexWrap: 'wrap', fontSize: 12, opacity: 0.8, marginBottom: 'var(--uxs-sp-2, 8px)' } }>
			{ entries.map( ( [ id, status ] ) => (
				<span key={ id }>
					{ LIMIT_LABELS[ id ] ?? id }: { status.used.toLocaleString() } / { status.limit.toLocaleString() } ({ status.percent }%)
				</span>
			) ) }
		</div>
	);
}

export function InternalChatTab(): JSX.Element {
	const [ conversationId, setConversationId ] = useState< number | null >( null );
	const [ messages, setMessages ] = useState< ChatMessage[] >( [] );
	const [ input, setInput ] = useState( '' );
	const [ isStreaming, setIsStreaming ] = useState( false );
	const [ streamingText, setStreamingText ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const messagesEndRef = useRef< HTMLDivElement >( null );

	useEffect( () => {
		messagesEndRef.current?.scrollIntoView( { block: 'end' } );
	}, [ messages, streamingText ] );

	const loadConversation = async ( id: number ) => {
		setError( null );
		const boot = window.uxStudioBoot;
		const res = await fetch( `${ boot.restUrl }/ai-assistant/internal-chat/history/${ id }`, {
			headers: { 'X-WP-Nonce': boot.nonce },
		} );
		if ( ! res.ok ) {
			setConversationId( null );
			setMessages( [] );
			return;
		}
		const json: { data: HistoryDetail } = await res.json();
		setConversationId( json.data.id );
		setMessages( json.data.messages.filter( ( m ) => 'user' === m.role || 'assistant' === m.role ) );
	};

	const startNew = () => {
		setConversationId( null );
		setMessages( [] );
		setStreamingText( '' );
		setError( null );
	};

	const sendMessage = async () => {
		const text = input.trim();
		if ( ! text || isStreaming ) {
			return;
		}

		const nextMessages: ChatMessage[] = [ ...messages, { role: 'user', content: text } ];
		setMessages( nextMessages );
		setInput( '' );
		setIsStreaming( true );
		setStreamingText( '' );
		setError( null );

		try {
			const boot = window.uxStudioBoot;
			const res = await fetch( `${ boot.restUrl }/ai-assistant/internal-chat`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': boot.nonce,
				},
				body: JSON.stringify( {
					messages: nextMessages,
					conversation_id: conversationId ?? 0,
				} ),
			} );

			if ( ! res.ok ) {
				throw new Error( `HTTP ${ res.status }` );
			}

			let assistantText = '';

			await readStream( res, ( data ) => {
				if ( 'string' === typeof data.error ) {
					setError( data.error );
					return;
				}
				if ( 'string' === typeof data.content ) {
					assistantText += data.content;
					setStreamingText( assistantText );
				}
				if ( data.done ) {
					const newId = Number( data.conversation_id );
					if ( newId > 0 ) {
						setConversationId( newId );
					}
				}
			} );

			if ( assistantText ) {
				setMessages( ( prev ) => [ ...prev, { role: 'assistant', content: assistantText } ] );
			}
			void queryClient.invalidateQueries( { queryKey: QK_HISTORY } );
			void queryClient.invalidateQueries( { queryKey: QK_LIMITS } );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : __( 'Failed to reach the AI assistant.', 'ux-studio' ) );
		} finally {
			setIsStreaming( false );
			setStreamingText( '' );
		}
	};

	return (
		<div style={ { display: 'flex', gap: 'var(--uxs-sp-4, 16px)', minHeight: 480 } }>
			<HistorySidebar selectedId={ conversationId } onSelect={ ( id ) => void loadConversation( id ) } onNew={ startNew } />

			<div style={ { flex: 1, display: 'flex', flexDirection: 'column' } }>
				<LimitsBar />

				<div
					style={ {
						flex: 1,
						overflowY: 'auto',
						display: 'flex',
						flexDirection: 'column',
						gap: 'var(--uxs-sp-2, 8px)',
						border: '1px solid var(--uxs-border, #e2e2e2)',
						borderRadius: 8,
						padding: 'var(--uxs-sp-3, 12px)',
						minHeight: 320,
						maxHeight: 520,
					} }
				>
					{ ! messages.length && ! streamingText ? (
						<p style={ { opacity: 0.7 } }>
							{ __( 'Ask the internal AI assistant about editing content, site settings, or how to use UX Studio.', 'ux-studio' ) }
						</p>
					) : null }

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

					{ isStreaming ? (
						<div
							style={ {
								alignSelf: 'flex-start',
								background: 'var(--uxs-border, #f0f0f1)',
								color: '#1e1e1e',
								borderRadius: 10,
								padding: '8px 12px',
								maxWidth: '80%',
								whiteSpace: 'pre-wrap',
							} }
						>
							{ streamingText || <LoaderCircle size={ 14 } aria-label={ __( 'Thinking…', 'ux-studio' ) } /> }
						</div>
					) : null }

					<div ref={ messagesEndRef } />
				</div>

				{ error ? (
					<p role="alert" style={ { color: 'var(--uxs-danger, #d63638)' } }>
						{ error }
					</p>
				) : null }

				<div style={ { display: 'flex', gap: 'var(--uxs-sp-2, 8px)', marginTop: 'var(--uxs-sp-3, 12px)' } }>
					<textarea
						rows={ 2 }
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( 'Enter' === e.key && ! e.shiftKey ) {
								e.preventDefault();
								void sendMessage();
							}
						} }
						placeholder={ __( 'Type your message…', 'ux-studio' ) }
						style={ { flex: 1, resize: 'vertical' } }
						disabled={ isStreaming }
					/>
					<button
						type="button"
						className="button button-primary"
						onClick={ () => void sendMessage() }
						disabled={ isStreaming || ! input.trim() }
					>
						<Send size={ 14 } /> { __( 'Send', 'ux-studio' ) }
					</button>
				</div>
			</div>
		</div>
	);
}
