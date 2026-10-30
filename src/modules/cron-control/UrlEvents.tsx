/**
 * Cron Control: events that call a web address on a schedule (PLAN.md 27.10). No event runs PHP code; the only thing an
 * event does is request one address (GET or POST), and addresses of the server itself or of a private network are blocked.
 */
import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Check, LoaderCircle, Pencil, Play, Plus, Trash2 } from 'lucide-react';
import { api } from '../../app/api';
import { formatDate } from '../../app/date';

interface UrlEvent {
	id: string;
	name: string;
	url: string;
	method: 'GET' | 'POST';
	body: string;
	schedule: string;
	schedule_name: string;
	timeout: number;
	enabled: boolean;
	first_run: number;
	next_run: number;
	last_run: number;
	last_code: number;
	last_status: '' | 'ok' | 'error' | 'blocked';
	last_error: string;
	last_ms: number;
}

interface Schedule {
	name: string;
	display: string;
	interval: number;
}

interface RunResult {
	status: string;
	code: number;
	error: string;
	ms: number;
}

interface Draft {
	id: string;
	name: string;
	url: string;
	method: 'GET' | 'POST';
	body: string;
	schedule: string;
	timeout: number;
	enabled: boolean;
	first_run: string;
}

const EMPTY: Draft = { id: '', name: '', url: '', method: 'GET', body: '', schedule: 'hourly', timeout: 15, enabled: true, first_run: '' };

function StatusBadge( { event }: { event: UrlEvent } ): JSX.Element {
	if ( ! event.last_run ) {
		return <span className="uxs-badge">{ __( 'Not run yet', 'ux-studio' ) }</span>;
	}
	const tone = event.last_status === 'ok' ? 'is-success' : 'is-danger';
	const label =
		event.last_status === 'ok'
			? __( 'OK', 'ux-studio' )
			: event.last_status === 'blocked'
			? __( 'Blocked', 'ux-studio' )
			: __( 'Failed', 'ux-studio' );
	return (
		<>
			<span className={ `uxs-badge ${ tone }` }>
				{ event.last_status === 'ok' ? <Check size={ 12 } /> : null } { label }
				{ event.last_code ? ` · HTTP ${ event.last_code }` : '' }
			</span>
			<br />
			<small>
				{ formatDate( event.last_run ) }
				{ event.last_ms ? ` · ${ sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'ux-studio' ), event.last_ms ) }` : '' }
			</small>
			{ event.last_error ? (
				<>
					<br />
					<small className="uxs-url-event__error">{ event.last_error }</small>
				</>
			) : null }
		</>
	);
}

export default function UrlEventsView(): JSX.Element {
	const queryClient = useQueryClient();
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ message, setMessage ] = useState< { ok: boolean; text: string } | null >( null );

	const events = useQuery( {
		queryKey: [ 'cron-control', 'url-events' ],
		queryFn: () => api< UrlEvent[] >( 'cron-control/url-events' ),
	} );
	const schedules = useQuery( {
		queryKey: [ 'cron-control', 'schedules' ],
		queryFn: () => api< Schedule[] >( 'cron-control/schedules' ),
	} );
	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'cron-control' ] } );

	const save = useMutation( {
		mutationFn: ( d: Draft ) => api< UrlEvent >( 'cron-control/url-events', { method: 'POST', body: JSON.stringify( d ) } ),
		onSuccess: () => {
			setDraft( null );
			setMessage( null );
			refresh();
		},
		onError: ( e: Error ) => setMessage( { ok: false, text: e.message } ),
	} );
	const del = useMutation( {
		mutationFn: ( id: string ) => api( 'cron-control/url-events', { method: 'DELETE', body: JSON.stringify( { id } ) } ),
		onSuccess: refresh,
	} );
	const run = useMutation( {
		mutationFn: ( id: string ) => api< RunResult >( 'cron-control/url-events/run', { method: 'POST', body: JSON.stringify( { id } ) } ),
		onSuccess: ( r ) => {
			setMessage( {
				ok: r.status === 'ok',
				text:
					r.status === 'ok'
						? sprintf( /* translators: %d: HTTP status code. */ __( 'The address answered with HTTP %d.', 'ux-studio' ), r.code )
						: r.error || __( 'The call failed.', 'ux-studio' ),
			} );
			refresh();
		},
		onError: ( e: Error ) => setMessage( { ok: false, text: e.message } ),
	} );

	const edit = ( e: UrlEvent ): void =>
		setDraft( {
			id: e.id,
			name: e.name,
			url: e.url,
			method: e.method,
			body: e.body,
			schedule: e.schedule,
			timeout: e.timeout,
			enabled: e.enabled,
			first_run: '',
		} );

	const set = < K extends keyof Draft >( key: K, value: Draft[ K ] ): void => setDraft( ( d ) => ( d ? { ...d, [ key ]: value } : d ) );

	return (
		<>
			<p className="uxs-form__help">
				{ __(
					'An event calls one web address (GET or POST) on a schedule, for example the cron URL of another application. It never runs PHP code. Addresses of this server or of a private network (localhost, 192.168.x.x, 10.x.x.x ...) are blocked.',
					'ux-studio'
				) }
			</p>

			<p>
				<button type="button" className="button button-primary" onClick={ () => setDraft( { ...EMPTY } ) }>
					<Plus size={ 14 } /> { __( 'Add URL event', 'ux-studio' ) }
				</button>
			</p>

			{ message ? (
				<p role="status">
					<span className={ `uxs-badge ${ message.ok ? 'is-success' : 'is-danger' }` }>{ message.text }</span>
				</p>
			) : null }

			{ draft ? (
				<form
					className="uxs-form uxs-url-event-form"
					onSubmit={ ( e ) => {
						e.preventDefault();
						save.mutate( draft );
					} }
				>
					<h3>{ draft.id ? __( 'Edit URL event', 'ux-studio' ) : __( 'New URL event', 'ux-studio' ) }</h3>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-name">{ __( 'Name', 'ux-studio' ) }</label>
						<input id="uxs-ue-name" type="text" value={ draft.name } onChange={ ( e ) => set( 'name', e.target.value ) } />
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-url">{ __( 'Address', 'ux-studio' ) }</label>
						<input
							id="uxs-ue-url"
							type="url"
							required
							placeholder="https://example.com/cron?key=..."
							value={ draft.url }
							onChange={ ( e ) => set( 'url', e.target.value ) }
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-method">{ __( 'Method', 'ux-studio' ) }</label>
						<select id="uxs-ue-method" value={ draft.method } onChange={ ( e ) => set( 'method', e.target.value as 'GET' | 'POST' ) }>
							<option value="GET">GET</option>
							<option value="POST">POST</option>
						</select>
					</div>
					{ draft.method === 'POST' ? (
						<div className="uxs-form__row uxs-form__row--wide">
							<label htmlFor="uxs-ue-body">{ __( 'Request body', 'ux-studio' ) }</label>
							<textarea id="uxs-ue-body" rows={ 3 } value={ draft.body } onChange={ ( e ) => set( 'body', e.target.value ) } />
							<p className="uxs-form__help">{ __( 'Sent as JSON when it is valid JSON, otherwise as a form (a=1&b=2).', 'ux-studio' ) }</p>
						</div>
					) : null }
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-schedule">{ __( 'Repeat', 'ux-studio' ) }</label>
						<select id="uxs-ue-schedule" value={ draft.schedule } onChange={ ( e ) => set( 'schedule', e.target.value ) }>
							<option value="">{ __( 'Only once', 'ux-studio' ) }</option>
							{ ( schedules.data ?? [] ).map( ( s ) => (
								<option key={ s.name } value={ s.name }>
									{ s.display }
								</option>
							) ) }
						</select>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-first">{ draft.schedule ? __( 'First run', 'ux-studio' ) : __( 'Run at', 'ux-studio' ) }</label>
						<input id="uxs-ue-first" type="datetime-local" value={ draft.first_run } onChange={ ( e ) => set( 'first_run', e.target.value ) } />
						<p className="uxs-form__help">{ draft.id ? __( 'Time of the site. Leave empty to keep the current schedule.', 'ux-studio' ) : __( 'Time of the site. Leave empty to start in a minute.', 'ux-studio' ) }</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-timeout">{ __( 'Timeout (seconds)', 'ux-studio' ) }</label>
						<input
							id="uxs-ue-timeout"
							type="number"
							min={ 1 }
							max={ 60 }
							value={ draft.timeout }
							onChange={ ( e ) => set( 'timeout', Number( e.target.value ) ) }
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-ue-enabled">{ __( 'Enabled', 'ux-studio' ) }</label>
						<button
							id="uxs-ue-enabled"
							type="button"
							className={ `uxs-switch${ draft.enabled ? ' is-on' : '' }` }
							aria-pressed={ draft.enabled }
							aria-label={ __( 'Enabled', 'ux-studio' ) }
							onClick={ () => set( 'enabled', ! draft.enabled ) }
						/>
					</div>
					<p>
						<button type="submit" className="button button-primary" disabled={ save.isPending }>
							{ __( 'Save event', 'ux-studio' ) }
						</button>{ ' ' }
						<button
							type="button"
							className="button"
							onClick={ () => {
								setDraft( null );
								setMessage( null );
							} }
						>
							{ __( 'Cancel', 'ux-studio' ) }
						</button>
					</p>
				</form>
			) : null }

			{ events.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! events.data || events.data.length === 0 ? (
				<p>{ __( 'No URL events yet.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Event', 'ux-studio' ) }</th>
							<th>{ __( 'Repeat', 'ux-studio' ) }</th>
							<th>{ __( 'Next run', 'ux-studio' ) }</th>
							<th>{ __( 'Last result', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ events.data.map( ( e ) => (
							<tr key={ e.id }>
								<td>
									<strong>{ e.name }</strong>
									{ e.enabled ? null : <span className="uxs-badge"> { __( 'Off', 'ux-studio' ) }</span> }
									<br />
									<code className="uxs-url-event__url">
										{ e.method } { e.url }
									</code>
								</td>
								<td>{ e.schedule ? e.schedule_name : __( 'Only once', 'ux-studio' ) }</td>
								<td>{ e.enabled && e.next_run ? formatDate( e.next_run ) : '—' }</td>
								<td>
									<StatusBadge event={ e } />
								</td>
								<td>
									<button
										type="button"
										className="button"
										aria-label={ sprintf( /* translators: %s: event name. */ __( 'Call %s now', 'ux-studio' ), e.name ) }
										disabled={ run.isPending }
										onClick={ () => run.mutate( e.id ) }
									>
										<Play size={ 14 } />
									</button>{ ' ' }
									<button
										type="button"
										className="button"
										aria-label={ sprintf( /* translators: %s: event name. */ __( 'Edit %s', 'ux-studio' ), e.name ) }
										onClick={ () => edit( e ) }
									>
										<Pencil size={ 14 } />
									</button>{ ' ' }
									<button
										type="button"
										className="button"
										aria-label={ sprintf( /* translators: %s: event name. */ __( 'Delete %s', 'ux-studio' ), e.name ) }
										disabled={ del.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this URL event?', 'ux-studio' ) ) ) {
												del.mutate( e.id );
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
			) }
		</>
	);
}
