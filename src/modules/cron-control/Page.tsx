/**
 * Cron Control: inspect/run/delete WP-Cron events, review the nightly hook
 * watcher, and control the WP-Cron run mode (disable / local / hosting / central).
 * Route: #/module?id=cron-control
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, LoaderCircle, Play, RefreshCw, Trash2 } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'events' | 'schedules' | 'watch' | 'settings';

interface CronEvent {
	hook: string;
	timestamp: number;
	next_run_human: string;
	schedule: string | null;
	interval: number | null;
	args: unknown[];
}

interface CronSchedule {
	name: string;
	display: string;
	interval: number;
}

interface CronStatus {
	mode: string;
	central_app_active: boolean;
	disable_wp_cron: boolean;
	alternate_wp_cron: boolean;
	next_scheduled: number | null;
	total_events: number;
	late: boolean;
	mu_plugin_active: boolean;
	mu_writable: boolean;
	htaccess_writable: boolean;
	cron_url: string;
	external_cron_cmd: string;
}

interface WatchEntry {
	hook: string;
	next_run: string;
	next_run_ts: number;
	schedule: string;
	callback_hint: string[];
	note: string;
}

interface WatchResult {
	scanned_at: string;
	timestamp: number;
	total_events: number;
	unknown: WatchEntry[];
	suspicious: WatchEntry[];
	removed: string[];
	autoremove: boolean;
}

interface EventActionResult {
	success: boolean;
	message: string;
}

const MODE_LABELS: Record< string, string > = {
	none: __( 'Running normally', 'ux-studio' ),
	block_all: __( 'Fully disabled', 'ux-studio' ),
	local_only: __( 'Local requests only', 'ux-studio' ),
	external: __( 'External hosting cron', 'ux-studio' ),
	central_app: __( 'Driven by central app', 'ux-studio' ),
};

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

	const showExternal = data.mode === 'external' || data.mode === 'central_app';

	return (
		<table className="uxs-table">
			<tbody>
				<tr>
					<th>{ __( 'Mode', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.mode === 'none' ? 'is-success' : data.mode === 'block_all' ? 'is-danger' : 'is-warning' }` }>
							{ MODE_LABELS[ data.mode ] ?? data.mode }
						</span>
					</td>
				</tr>
				<tr>
					<th>{ __( 'DISABLE_WP_CRON', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.disable_wp_cron ? 'is-warning' : 'is-success' }` }>
							{ data.disable_wp_cron ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
						</span>
						{ data.disable_wp_cron && ! data.mu_plugin_active ? (
							<span className="uxs-form__help">
								{ __( 'Defined elsewhere (wp-config.php or another plugin), not by this module.', 'ux-studio' ) }
							</span>
						) : null }
					</td>
				</tr>
				<tr>
					<th>{ __( 'DISABLE_WP_CRON writable', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.mu_writable ? 'is-success' : 'is-danger' }` }>
							{ data.mu_writable ? __( 'mu-plugins writable', 'ux-studio' ) : __( 'mu-plugins NOT writable', 'ux-studio' ) }
						</span>
						{ ! data.mu_writable ? (
							<span className="uxs-form__help">
								{ __( 'The mu-plugins directory is not writable, so this module cannot set DISABLE_WP_CRON. Define it manually in wp-config.php if you need to disable WP-Cron.', 'ux-studio' ) }
							</span>
						) : null }
					</td>
				</tr>
				<tr>
					<th>{ __( '.htaccess writable', 'ux-studio' ) }</th>
					<td>
						<span className={ `uxs-badge ${ data.htaccess_writable ? 'is-success' : 'is-warning' }` }>
							{ data.htaccess_writable ? __( 'Yes', 'ux-studio' ) : __( 'No (Apache access rules cannot be applied)', 'ux-studio' ) }
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
				{ showExternal ? (
					<tr>
						<th>{ __( 'External trigger', 'ux-studio' ) }</th>
						<td>
							<p className="uxs-form__help" style={ { marginTop: 0 } }>
								{ __( 'Configure a hosting/system cron to call this URL every 5-15 minutes:', 'ux-studio' ) }
							</p>
							<code style={ { fontSize: '11px', wordBreak: 'break-all' } }>{ data.cron_url }</code>
							<p className="uxs-form__help">{ __( 'Example crontab line:', 'ux-studio' ) }</p>
							<code style={ { fontSize: '11px', wordBreak: 'break-all' } }>{ data.external_cron_cmd }</code>
						</td>
					</tr>
				) : null }
			</tbody>
		</table>
	);
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

function SchedulesTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'cron-control', 'schedules' ],
		queryFn: () => api< CronSchedule[] >( 'cron-control/schedules' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No schedules registered.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'ux-studio' ) }</th>
					<th>{ __( 'Display', 'ux-studio' ) }</th>
					<th>{ __( 'Interval', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( s ) => (
					<tr key={ s.name }>
						<td><code>{ s.name }</code></td>
						<td>{ s.display }</td>
						<td>{ s.interval }s</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

function WatchList( { title, entries, tone }: { title: string; entries: WatchEntry[]; tone: 'warning' | 'danger' } ): JSX.Element | null {
	if ( entries.length === 0 ) {
		return null;
	}
	return (
		<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
			<h3>{ title } <span className={ `uxs-badge is-${ tone }` }>{ entries.length }</span></h3>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Hook', 'ux-studio' ) }</th>
						<th>{ __( 'Next run', 'ux-studio' ) }</th>
						<th>{ __( 'Note', 'ux-studio' ) }</th>
						<th>{ __( 'Callback', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ entries.map( ( e ) => (
						<tr key={ `${ e.hook }-${ e.next_run_ts }` }>
							<td><code>{ e.hook }</code></td>
							<td><small>{ e.next_run }</small></td>
							<td>{ e.note }</td>
							<td>
								<code style={ { fontSize: '11px' } }>
									{ e.callback_hint.length ? e.callback_hint.join( ', ' ) : '—' }
								</code>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function WatchView(): JSX.Element {
	const queryClient = useQueryClient();
	const { data, isLoading } = useQuery( {
		queryKey: [ 'cron-control', 'watch' ],
		queryFn: () => api< WatchResult | null >( 'cron-control/watch' ),
	} );

	const run = useMutation( {
		mutationFn: () => api< WatchResult >( 'cron-control/watch', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'cron-control', 'watch' ] } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button" disabled={ run.isPending } onClick={ () => run.mutate() }>
					<RefreshCw size={ 14 } /> { __( 'Run check now', 'ux-studio' ) }
				</button>
			</div>

			{ ! data ? (
				<p>{ __( 'The watcher has not run yet.', 'ux-studio' ) }</p>
			) : (
				<>
					<p className="uxs-form__help" style={ { marginTop: 0 } }>
						{ __( 'Last scan:', 'ux-studio' ) } { data.scanned_at } · { data.total_events } { __( 'events', 'ux-studio' ) } ·{ ' ' }
						{ data.unknown.length } { __( 'unknown', 'ux-studio' ) } · { data.suspicious.length } { __( 'suspicious', 'ux-studio' ) }
						{ data.autoremove ? ` · ${ __( 'auto-remove ON', 'ux-studio' ) }` : '' }
					</p>
					{ data.removed.length > 0 ? (
						<div className="uxs-badge is-danger" style={ { display: 'block', padding: 'var(--uxs-sp-2)' } }>
							{ __( 'Automatically removed:', 'ux-studio' ) } { data.removed.join( '; ' ) }
						</div>
					) : null }
					<WatchList title={ __( 'Suspicious', 'ux-studio' ) } entries={ data.suspicious } tone="danger" />
					<WatchList title={ __( 'Unknown', 'ux-studio' ) } entries={ data.unknown } tone="warning" />
					{ data.suspicious.length === 0 && data.unknown.length === 0 ? (
						<p>{ __( 'All scheduled hooks are recognised. Nothing to report.', 'ux-studio' ) }</p>
					) : null }
				</>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'events' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'cron-control' );

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
				{ tab === 'settings' && (
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () => save.mutate() }
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'events' ? 'is-active' : '' } onClick={ () => setTab( 'events' ) }>
					{ __( 'Events', 'ux-studio' ) }
				</button>
				<button className={ tab === 'schedules' ? 'is-active' : '' } onClick={ () => setTab( 'schedules' ) }>
					{ __( 'Schedules', 'ux-studio' ) }
				</button>
				<button className={ tab === 'watch' ? 'is-active' : '' } onClick={ () => setTab( 'watch' ) }>
					{ __( 'Watcher', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Mode & Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'events' && <EventsTable /> }
			{ tab === 'schedules' && <SchedulesTable /> }
			{ tab === 'watch' && <WatchView /> }
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<>
						<StatusView />
						<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
							<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
						</div>
					</>
				) ) }
		</>
	);
}
