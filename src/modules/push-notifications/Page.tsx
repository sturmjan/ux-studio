import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, KeyRound, LoaderCircle, Send } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'send' | 'subscribers' | 'analytics' | 'settings';

interface Notification {
	id: number;
	created_at: string;
	title: string;
	body: string;
	url: string;
	icon: string;
	segment: string;
	scheduled_at: string | null;
	sent_count: number;
	delivered_count: number;
	status: 'draft' | 'scheduled' | 'sent';
}

interface Subscriber {
	id: number;
	created_at: string;
	endpoint: string;
	user_agent: string;
}

interface Analytics {
	subscribers: number;
	notifications: number;
	delivered: number;
	failed: number;
	clicked: number;
}

function statusLabel( status: Notification[ 'status' ] ): string {
	if ( status === 'scheduled' ) {
		return __( 'Scheduled', 'ux-studio' );
	}
	if ( status === 'sent' ) {
		return __( 'Sent', 'ux-studio' );
	}
	return __( 'Draft', 'ux-studio' );
}

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
		</div>
	);
}

function SendTab(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ body, setBody ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ segment, setSegment ] = useState( 'all' );
	const [ schedule, setSchedule ] = useState< Record< number, string > >( {} );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'push-notifications', 'notifications' ],
		queryFn: () => api< Notification[] >( 'push-notifications/notifications' ),
	} );

	const create = useMutation( {
		mutationFn: () =>
			api< Notification >( 'push-notifications/notifications', {
				method: 'POST',
				body: JSON.stringify( { title, body, url, segment } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'notifications' ] } );
			setTitle( '' );
			setBody( '' );
			setUrl( '' );
		},
	} );

	const send = useMutation( {
		mutationFn: ( { id, scheduledAt }: { id: number; scheduledAt?: string } ) =>
			api( `push-notifications/notifications/${ id }/send`, {
				method: 'POST',
				body: JSON.stringify( scheduledAt ? { scheduled_at: scheduledAt } : {} ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'notifications' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'analytics' ] } );
		},
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-title">{ __( 'Title', 'ux-studio' ) }</label>
					<input id="uxs-pn-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-body">{ __( 'Body', 'ux-studio' ) }</label>
					<textarea id="uxs-pn-body" rows={ 3 } value={ body } onChange={ ( e ) => setBody( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-url">{ __( 'Click URL', 'ux-studio' ) }</label>
					<input id="uxs-pn-url" type="url" placeholder="https://…" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-segment">{ __( 'Audience', 'ux-studio' ) }</label>
					<select id="uxs-pn-segment" value={ segment } onChange={ ( e ) => setSegment( e.target.value ) }>
						<option value="all">{ __( 'All subscribers', 'ux-studio' ) }</option>
						<option value="recent_30d">{ __( 'Subscribed in last 30 days', 'ux-studio' ) }</option>
					</select>
				</div>
				<button
					type="button"
					className="button button-primary"
					disabled={ title.trim() === '' || create.isPending }
					onClick={ () => create.mutate() }
				>
					{ create.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Create draft', 'ux-studio' ) }
				</button>
			</div>

			{ isLoading ? <Loading /> : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No notifications yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Delivered', 'ux-studio' ) }</th>
							<th>{ __( 'Send', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( n ) => (
							<tr key={ n.id }>
								<td>{ n.title }</td>
								<td>
									<span className={ `uxs-badge ${ n.status === 'sent' ? 'is-success' : '' }` }>{ statusLabel( n.status ) }</span>
									{ n.status === 'scheduled' && n.scheduled_at ? <div style={ { fontSize: 12 } }>{ n.scheduled_at }</div> : null }
								</td>
								<td>{ n.status === 'sent' ? `${ n.delivered_count } / ${ n.sent_count }` : '—' }</td>
								<td>
									{ n.status !== 'sent' ? (
										<>
											<input
												type="datetime-local"
												value={ schedule[ n.id ] ?? '' }
												onChange={ ( e ) => setSchedule( ( s ) => ( { ...s, [ n.id ]: e.target.value } ) ) }
												style={ { marginRight: 6 } }
											/>
											<button
												type="button"
												className="button"
												disabled={ send.isPending }
												onClick={ () => {
													const raw = schedule[ n.id ];
													const scheduledAt = raw ? raw.replace( 'T', ' ' ) + ':00' : undefined;
													send.mutate( { id: n.id, scheduledAt } );
												} }
											>
												<Send size={ 14 } /> { schedule[ n.id ] ? __( 'Schedule', 'ux-studio' ) : __( 'Send now', 'ux-studio' ) }
											</button>
										</>
									) : (
										'—'
									) }
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
		queryKey: [ 'push-notifications', 'subscribers' ],
		queryFn: () => api< Subscriber[] >( 'push-notifications/subscribers' ),
	} );

	if ( isLoading ) {
		return <Loading />;
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No subscribers yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'User agent', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( s ) => (
					<tr key={ s.id }>
						<td>{ s.created_at }</td>
						<td>{ s.user_agent || '—' }</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function AnalyticsTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'push-notifications', 'analytics' ],
		queryFn: () => api< Analytics >( 'push-notifications/analytics' ),
	} );

	if ( isLoading || ! data ) {
		return <Loading />;
	}

	return (
		<div className="uxs-cards">
			<div className="uxs-card"><div className="uxs-card__num">{ data.subscribers }</div><div className="uxs-card__label">{ __( 'Subscribers', 'ux-studio' ) }</div></div>
			<div className="uxs-card"><div className="uxs-card__num">{ data.notifications }</div><div className="uxs-card__label">{ __( 'Notifications', 'ux-studio' ) }</div></div>
			<div className="uxs-card"><div className="uxs-card__num">{ data.delivered }</div><div className="uxs-card__label">{ __( 'Delivered', 'ux-studio' ) }</div></div>
			<div className="uxs-card"><div className="uxs-card__num">{ data.clicked }</div><div className="uxs-card__label">{ __( 'Clicked', 'ux-studio' ) }</div></div>
			<div className="uxs-card"><div className="uxs-card__num">{ data.failed }</div><div className="uxs-card__label">{ __( 'Failed', 'ux-studio' ) }</div></div>
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'send' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'push-notifications' );

	const regenerate = useMutation( {
		mutationFn: () => api( 'push-notifications/vapid/generate', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'settings', 'push-notifications' ] } ),
	} );

	const tabs: Array< [ Tab, string ] > = [
		[ 'send', __( 'Send', 'ux-studio' ) ],
		[ 'subscribers', __( 'Subscribers', 'ux-studio' ) ],
		[ 'analytics', __( 'Analytics', 'ux-studio' ) ],
		[ 'settings', __( 'Settings', 'ux-studio' ) ],
	];

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
					{ __( 'Push Notifications', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				{ tabs.map( ( [ id, label ] ) => (
					<button key={ id } className={ tab === id ? 'is-active' : '' } onClick={ () => setTab( id ) }>
						{ label }
					</button>
				) ) }
			</div>
			{ tab === 'send' && <SendTab /> }
			{ tab === 'subscribers' && <SubscribersTab /> }
			{ tab === 'analytics' && <AnalyticsTab /> }
			{ tab === 'settings' && ( isLoading || ! data ) && <Loading /> }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p>
						{ draft.has_vapid_keys
							? __( 'A VAPID keypair is currently configured.', 'ux-studio' )
							: __( 'No VAPID keypair yet - one will be generated automatically.', 'ux-studio' ) }
						{ ' ' }
						{ draft.public_key ? <code>{ String( draft.public_key ) }</code> : null }
					</p>
					<button type="button" className="button" disabled={ regenerate.isPending } onClick={ () => regenerate.mutate() }>
						<KeyRound size={ 14 } /> { __( 'Regenerate VAPID keys', 'ux-studio' ) }
					</button>{ ' ' }
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
