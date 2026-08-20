import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Play, Trash2 } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';

type Tab = 'events' | 'status';

interface CronEvent {
	hook: string;
	timestamp: number;
	next_run_human: string;
	schedule: string | null;
	interval: number | null;
	args: unknown[];
}

interface CronStatus {
	disable_wp_cron: boolean;
	alternate_wp_cron: boolean;
	next_scheduled: number | null;
	total_events: number;
	late: boolean;
}

interface EventActionResult {
	success: boolean;
	message: string;
}

function EventsTable(): JSX.Element {
	const queryClient = useQueryClient();
	const { data, isLoading } = useQuery( {
		queryKey: [ 'cron-control', 'events' ],
		queryFn: () => api< CronEvent[] >( 'cron-control/events' ),
	} );

	const run = useMutation( {
		mutationFn: ( event: CronEvent ) =>
			api< EventActionResult >( 'cron-control/events/run', {
				method: 'POST',
				body: JSON.stringify( { hook: event.hook, timestamp: event.timestamp, args: event.args } ),
			} ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'cron-control' ] } ),
	} );

	const del = useMutation( {
		mutationFn: ( event: CronEvent ) =>
			api< EventActionResult >( 'cron-control/events', {
				method: 'DELETE',
				body: JSON.stringify( { hook: event.hook, timestamp: event.timestamp, args: event.args } ),
			} ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'cron-control' ] } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No events are currently scheduled.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Hook', 'ux-studio' ) }</th>
					<th>{ __( 'Next run', 'ux-studio' ) }</th>
					<th>{ __( 'Schedule', 'ux-studio' ) }</th>
					<th>{ __( 'Args', 'ux-studio' ) }</th>
					<th>{ __( 'Actions', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( event ) => (
					<tr key={ `${ event.hook }-${ event.timestamp }` }>
						<td>{ event.hook }</td>
						<td>
							{ event.next_run_human }
							<br />
							<small>{ new Date( event.timestamp * 1000 ).toLocaleString() }</small>
						</td>
						<td>{ event.schedule ?? __( 'Non-repeating', 'ux-studio' ) }</td>
						<td>
							<code style={ { fontSize: '11px' } }>{ JSON.stringify( event.args ) }</code>
						</td>
						<td>
							<button
								type="button"
								className="button"
								aria-label={ __( 'Run now', 'ux-studio' ) }
								disabled={ run.isPending }
								onClick={ () => run.mutate( event ) }
							>
								<Play size={ 14 } />
							</button>{ ' ' }
							<button
								type="button"
								className="button"
								aria-label={ __( 'Delete', 'ux-studio' ) }
								disabled={ del.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete this scheduled event?', 'ux-studio' ) ) ) {
										del.mutate( event );
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

function StatusView(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'cron-control', 'status' ],
		queryFn: () => api< CronStatus >( 'cron-control/status' ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<table className="uxs-table">
			<tbody>
				<tr>
					<th>{ __( 'DISABLE_WP_CRON', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.disable_wp_cron ? 'is-warning' : 'is-success' }` }>
							{ data.disable_wp_cron ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
						</span>
					</td>
				</tr>
				<tr>
					<th>{ __( 'ALTERNATE_WP_CRON', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.alternate_wp_cron ? 'is-warning' : 'is-success' }` }>
							{ data.alternate_wp_cron ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
						</span>
					</td>
				</tr>
				<tr>
					<th>{ __( 'Next scheduled event', 'ux-studio' ) }</th>
					<td>
						{ null === data.next_scheduled
							? __( 'None', 'ux-studio' )
							: new Date( data.next_scheduled * 1000 ).toLocaleString() }
					</td>
				</tr>
				<tr>
					<th>{ __( 'Total scheduled events', 'ux-studio' ) }</th>
					<td>{ data.total_events }</td>
				</tr>
				<tr>
					<th>{ __( 'Cron running late', 'ux-studio' ) }</th>
					<td>
						{ data.late ? (
							<span className="uxs-badge is-warning">{ __( 'Late', 'ux-studio' ) }</span>
						) : (
							<span className="uxs-badge is-success">{ __( 'On time', 'ux-studio' ) }</span>
						) }
					</td>
				</tr>
			</tbody>
		</table>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'events' );

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
					{ __( 'Cron Control', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'events' ? 'is-active' : '' } onClick={ () => setTab( 'events' ) }>
					{ __( 'Events', 'ux-studio' ) }
				</button>
				<button className={ tab === 'status' ? 'is-active' : '' } onClick={ () => setTab( 'status' ) }>
					{ __( 'Status', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'events' && <EventsTable /> }
			{ tab === 'status' && <StatusView /> }
		</>
	);
}
