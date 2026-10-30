import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, FlaskConical, KeyRound, LoaderCircle, Send, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';

type Tab = 'send' | 'subscribers' | 'segments' | 'analytics' | 'settings';

interface Notification {
	id: number;
	created_at: string;
	title: string;
	body: string;
	url: string;
	icon: string;
	segment: string;
	segments: string[];
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
	user_id: number;
	segments: string[];
	failed: number;
}

interface Segment {
	id: number;
	name: string;
	slug: string;
	type: string;
	subscribers: number;
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

function useSegments() {
	return useQuery( {
		queryKey: [ 'push-notifications', 'segments' ],
		queryFn: () => api< Segment[] >( 'push-notifications/segments' ),
	} );
}

function SendTab(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ body, setBody ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ icon, setIcon ] = useState( '' );
	const [ segment, setSegment ] = useState( 'all' );
	const [ segSel, setSegSel ] = useState< string[] >( [] );
	const [ schedule, setSchedule ] = useState< Record< number, string > >( {} );
	const [ testMsg, setTestMsg ] = useState( '' );

	const segments = useSegments();
	const { data, isLoading } = useQuery( {
		queryKey: [ 'push-notifications', 'notifications' ],
		queryFn: () => api< Notification[] >( 'push-notifications/notifications' ),
	} );

	const create = useMutation( {
		mutationFn: () =>
			api< Notification >( 'push-notifications/notifications', {
				method: 'POST',
				body: JSON.stringify( { title, body, url, icon, segment, segments: segSel } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'notifications' ] } );
			setTitle( '' );
			setBody( '' );
			setUrl( '' );
			setIcon( '' );
			setSegSel( [] );
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

	const test = useMutation( {
		mutationFn: ( id: number ) => api( `push-notifications/notifications/${ id }/test`, { method: 'POST' } ),
		onSuccess: () => setTestMsg( __( 'Test notification sent to your own subscription.', 'ux-studio' ) ),
		onError: ( e: Error ) => setTestMsg( e.message ),
	} );

	const customSegments = ( segments.data ?? [] ).filter( ( s ) => s.type === 'custom' );

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
					<label htmlFor="uxs-pn-icon">{ __( 'Icon URL', 'ux-studio' ) }</label>
					<input
						id="uxs-pn-icon"
						type="url"
						placeholder={ __( 'Empty = default icon from Settings', 'ux-studio' ) }
						value={ icon }
						onChange={ ( e ) => setIcon( e.target.value ) }
					/>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-segment">{ __( 'Audience', 'ux-studio' ) }</label>
					<select id="uxs-pn-segment" value={ segment } onChange={ ( e ) => setSegment( e.target.value ) }>
						<option value="all">{ __( 'All subscribers', 'ux-studio' ) }</option>
						<option value="recent_30d">{ __( 'Subscribed in last 30 days', 'ux-studio' ) }</option>
					</select>
				</div>
				{ customSegments.length > 0 ? (
					<div className="uxs-form__row">
						<span>{ __( 'Target segments', 'ux-studio' ) }</span>
						<div>
							{ customSegments.map( ( s ) => (
								<label key={ s.id } style={ { display: 'block' } }>
									<input
										type="checkbox"
										checked={ segSel.includes( s.slug ) }
										onChange={ ( e ) =>
											setSegSel( ( cur ) => ( e.target.checked ? [ ...cur, s.slug ] : cur.filter( ( x ) => x !== s.slug ) ) )
										}
									/>{ ' ' }
									{ s.name } ({ s.subscribers })
								</label>
							) ) }
							<p style={ { fontSize: 12 } }>{ __( 'None selected = send to the whole audience above.', 'ux-studio' ) }</p>
						</div>
					</div>
				) : null }
				<button
					type="button"
					className="button button-primary"
					disabled={ title.trim() === '' || create.isPending }
					onClick={ () => create.mutate() }
				>
					{ create.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Create draft', 'ux-studio' ) }
				</button>
			</div>

			{ testMsg ? <p role="status">{ testMsg }</p> : null }
			{ isLoading ? <Loading /> : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No notifications yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<div className="uxs-table-wrap"><table className="uxs-table">
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
									{ n.status === 'scheduled' && n.scheduled_at ? <div style={ { fontSize: 12 } }>{ formatDate( n.scheduled_at ) }</div> : null }
								</td>
								<td>{ n.status === 'sent' ? `${ n.delivered_count } / ${ n.sent_count }` : '—' }</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ test.isPending }
										title={ __( 'Send a test to your own subscription', 'ux-studio' ) }
										style={ { marginRight: 6 } }
										onClick={ () => {
											setTestMsg( '' );
											test.mutate( n.id );
										} }
									>
										<FlaskConical size={ 14 } /> { __( 'Test', 'ux-studio' ) }
									</button>
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
									) : null }
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) : null }
		</>
	);
}

function SubscribersTab(): JSX.Element {
	const segments = useSegments();
	const { data, isLoading } = useQuery( {
		queryKey: [ 'push-notifications', 'subscribers' ],
		queryFn: () => api< Subscriber[] >( 'push-notifications/subscribers' ),
	} );

	const refresh = () => {
		void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'subscribers' ] } );
		void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'segments' ] } );
		void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'analytics' ] } );
	};

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `push-notifications/subscribers/${ id }`, { method: 'DELETE' } ),
		onSuccess: refresh,
	} );

	const setSegs = useMutation( {
		mutationFn: ( { id, slugs }: { id: number; slugs: string[] } ) =>
			api( `push-notifications/subscribers/${ id }/segments`, { method: 'POST', body: JSON.stringify( { segments: slugs } ) } ),
		onSuccess: refresh,
	} );

	if ( isLoading ) {
		return <Loading />;
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No subscribers yet.', 'ux-studio' ) }</p>;
	}

	const custom = ( segments.data ?? [] ).filter( ( s ) => s.type === 'custom' );

	return (
		<div className="uxs-table-wrap"><table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'User agent', 'ux-studio' ) }</th>
					<th>{ __( 'Segments', 'ux-studio' ) }</th>
					<th>{ __( 'Failed sends', 'ux-studio' ) }</th>
					<th>
						<span className="screen-reader-text">{ __( 'Delete', 'ux-studio' ) }</span>
					</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( s ) => (
					<tr key={ s.id }>
						<td>{ formatDate( s.created_at ) }</td>
						<td>{ s.user_agent || '—' }</td>
						<td>
							{ s.segments.map( ( slug ) => (
								<button
									key={ slug }
									type="button"
									className="uxs-badge"
									title={ __( 'Remove from segment', 'ux-studio' ) }
									onClick={ () => setSegs.mutate( { id: s.id, slugs: s.segments.filter( ( x ) => x !== slug ) } ) }
								>
									{ custom.find( ( c ) => c.slug === slug )?.name ?? slug } ×
								</button>
							) ) }{ ' ' }
							{ custom.filter( ( c ) => ! s.segments.includes( c.slug ) ).length > 0 ? (
								<select
									value=""
									aria-label={ __( 'Add to segment', 'ux-studio' ) }
									onChange={ ( e ) => {
										if ( e.target.value ) {
											setSegs.mutate( { id: s.id, slugs: [ ...s.segments, e.target.value ] } );
										}
									} }
								>
									<option value="">{ __( '+ Add to segment', 'ux-studio' ) }</option>
									{ custom
										.filter( ( c ) => ! s.segments.includes( c.slug ) )
										.map( ( c ) => (
											<option key={ c.id } value={ c.slug }>
												{ c.name }
											</option>
										) ) }
								</select>
							) : null }
						</td>
						<td>{ s.failed }</td>
						<td>
							<button
								type="button"
								className="button"
								aria-label={ __( 'Delete subscriber', 'ux-studio' ) }
								disabled={ remove.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete this subscriber?', 'ux-studio' ) ) ) {
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
		</table></div>
	);
}

function SegmentsTab(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const { data, isLoading } = useSegments();

	const create = useMutation( {
		mutationFn: () => api< Segment >( 'push-notifications/segments', { method: 'POST', body: JSON.stringify( { name } ) } ),
		onSuccess: () => {
			setName( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'segments' ] } );
		},
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `push-notifications/segments/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'push-notifications', 'segments' ] } ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-pn-seg-name">{ __( 'New segment name', 'ux-studio' ) }</label>
					<input id="uxs-pn-seg-name" type="text" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
				</div>
				<button type="button" className="button button-primary" disabled={ name.trim() === '' || create.isPending } onClick={ () => create.mutate() }>
					{ __( 'Create segment', 'ux-studio' ) }
				</button>
				{ create.isError ? <p role="alert">{ ( create.error as Error ).message }</p> : null }
			</div>

			{ isLoading ? <Loading /> : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No segments yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Slug', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Subscribers', 'ux-studio' ) }</th>
							<th>
								<span className="screen-reader-text">{ __( 'Delete', 'ux-studio' ) }</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( s ) => (
							<tr key={ s.id }>
								<td>{ s.name }</td>
								<td>
									<code>{ s.slug }</code>
								</td>
								<td>{ s.type }</td>
								<td>{ s.subscribers }</td>
								<td>
									{ s.type === 'custom' ? (
										<button
											type="button"
											className="button"
											aria-label={ __( 'Delete segment', 'ux-studio' ) }
											disabled={ remove.isPending }
											onClick={ () => {
												if ( window.confirm( __( 'Delete this segment?', 'ux-studio' ) ) ) {
													remove.mutate( s.id );
												}
											} }
										>
											<Trash2 size={ 14 } />
										</button>
									) : (
										<em>{ __( 'system', 'ux-studio' ) }</em>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) : null }
		</>
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
		[ 'segments', __( 'Segments', 'ux-studio' ) ],
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
			<TabsNav>
				{ tabs.map( ( [ id, label ] ) => (
					<button key={ id } className={ tab === id ? 'is-active' : '' } onClick={ () => setTab( id ) }>
						{ label }
					</button>
				) ) }
			</TabsNav>
			{ tab === 'send' && <SendTab /> }
			{ tab === 'subscribers' && <SubscribersTab /> }
			{ tab === 'segments' && <SegmentsTab /> }
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
