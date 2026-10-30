/**
 * Cron Control: inspect/run/delete WP-Cron events, review the nightly hook
 * watcher, and control the WP-Cron run mode (disable / local / hosting / central).
 * Route: #/module?id=cron-control
 */
import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, ChevronLeft, ChevronRight, LoaderCircle, Play, RefreshCw, Trash2 } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { TopicSettings, withKeys } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';
import { HelpText } from '../../components/GlossaryTerm';
import UrlEventsView from './UrlEvents';
import { filterEvents, paginate, sourceOptions } from './eventsList';

type Tab = 'events' | 'urls' | 'schedules' | 'watch' | 'settings';

interface CronEvent {
	hook: string;
	timestamp: number;
	next_run_human: string;
	schedule: string | null;
	schedule_label: string;
	schedule_origin: string;
	source: string;
	interval: number | null;
	args: unknown[];
}

interface CronSchedule {
	name: string;
	display: string;
	origin: string;
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
	remote_result: RemoteResult | null;
}

interface RemoteResult {
	ok: boolean;
	enabled: boolean | null;
	message: string;
	tested_at: string;
	timestamp: number;
}

interface ResyncResult {
	success: boolean;
	message: string;
	result: RemoteResult | null;
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
		<div className="uxs-table-wrap"><table className="uxs-table">
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
					<th>
						{ __( 'Tasks start on page visits', 'ux-studio' ) } <code className="uxs-form__help">DISABLE_WP_CRON</code>
					</th>
					<td>
						<span className={ `uxs-badge ${ data.disable_wp_cron ? 'is-warning' : 'is-success' }` }>
							{ data.disable_wp_cron ? __( 'No, this is turned off', 'ux-studio' ) : __( 'Yes, the default way', 'ux-studio' ) }
						</span>
						<span className="uxs-form__help">
							<HelpText text={ __( 'WordPress normally starts its scheduled tasks ([[cron|WP-Cron]]) when someone opens a page. When this is turned off, something else has to start them, for example a cron of the hosting.', 'ux-studio' ) } />
						</span>
						{ data.disable_wp_cron && ! data.mu_plugin_active ? (
							<span className="uxs-form__help">
								{ __( 'It is turned off elsewhere (wp-config.php or another plugin), not by this module.', 'ux-studio' ) }
							</span>
						) : null }
					</td>
				</tr>
				<tr>
					<th>
						{ __( 'This module can switch it off', 'ux-studio' ) } <code className="uxs-form__help">mu-plugins</code>
					</th>
					<td>
						<span className={ `uxs-badge ${ data.mu_writable ? 'is-success' : 'is-danger' }` }>
							{ data.mu_writable ? __( 'Yes, the folder for must-use plugins is writable', 'ux-studio' ) : __( 'No, the folder for must-use plugins is not writable', 'ux-studio' ) }
						</span>
						{ ! data.mu_writable ? (
							<span className="uxs-form__help">
								{ __( 'The mu-plugins folder is not writable, so this module cannot stop the start of tasks on page visits (DISABLE_WP_CRON). Add that setting to wp-config.php yourself if you need it.', 'ux-studio' ) }
							</span>
						) : null }
					</td>
				</tr>
				<tr>
					<th>
						{ __( 'Access rules can be written', 'ux-studio' ) } <code className="uxs-form__help">.htaccess</code>
					</th>
					<td>
						<span className={ `uxs-badge ${ data.htaccess_writable ? 'is-success' : 'is-warning' }` }>
							{ data.htaccess_writable ? __( 'Yes', 'ux-studio' ) : __( 'No, the access rules for the cron file cannot be applied', 'ux-studio' ) }
						</span>
					</td>
				</tr>
				<tr>
					<th>
						{ __( 'Alternative way of starting tasks', 'ux-studio' ) } <code className="uxs-form__help">ALTERNATE_WP_CRON</code>
					</th>
					<td>
						<span className={ `uxs-badge ${ data.alternate_wp_cron ? 'is-warning' : 'is-success' }` }>
							{ data.alternate_wp_cron ? __( 'On', 'ux-studio' ) : __( 'Off', 'ux-studio' ) }
						</span>
						<span className="uxs-form__help">
							{ __( 'A rarely needed method for servers that cannot start tasks in the background: the visitor is redirected once to the same page, and the tasks run during that redirect.', 'ux-studio' ) }
						</span>
					</td>
				</tr>
				<tr>
					<th>{ __( 'Next scheduled event', 'ux-studio' ) }</th>
					<td>
						{ null === data.next_scheduled
							? __( 'None', 'ux-studio' )
							: formatDate( data.next_scheduled ) }
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
		</table></div>
	);
}

function CentralSyncView(): JSX.Element | null {
	const queryClient = useQueryClient();
	const { data } = useQuery( {
		queryKey: [ 'cron-control', 'status' ],
		queryFn: () => api< CronStatus >( 'cron-control/status' ),
	} );

	const resync = useMutation( {
		mutationFn: () => api< ResyncResult >( 'cron-control/central/resync', { method: 'POST' } ),
		onSettled: () => queryClient.invalidateQueries( { queryKey: [ 'cron-control', 'status' ] } ),
	} );

	if ( ! data || data.mode !== 'central_app' ) {
		return null;
	}

	const result = data.remote_result;
	// Central mode is set, but the last confirmed state in the central app is
	// not "running cron" - e.g. a failed push or a stale "switched off".
	const outOfSync = ! result || ! result.ok || false === result.enabled;

	return (
		<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
			<h3>{ __( 'Sync with the central app', 'ux-studio' ) }</h3>
			{ result ? (
				<p>
					<span className={ `uxs-badge ${ result.ok ? 'is-success' : 'is-danger' }` }>
						{ result.ok ? __( 'Success', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) }
					</span>{ ' ' }
					{ result.message }
					<br />
					<small>
						{ __( 'Last sent:', 'ux-studio' ) }{ ' ' }
						{ result.timestamp ? formatDate( result.timestamp ) : formatDate( result.tested_at ) }
					</small>
				</p>
			) : (
				<p>{ __( 'The cron settings have not been sent to the central app yet.', 'ux-studio' ) }</p>
			) }
			{ outOfSync ? (
				<p className="uxs-form__help">
					{ __( 'The central app may not be calling wp-cron.php for this site, so scheduled tasks may not run. Resend the settings.', 'ux-studio' ) }
				</p>
			) : null }
			<button type="button" className="button" disabled={ resync.isPending } onClick={ () => resync.mutate() }>
				<RefreshCw size={ 14 } /> { __( 'Resend settings to the central app', 'ux-studio' ) }
			</button>
			{ resync.isError ? (
				<p className="uxs-form__help" role="alert">
					{ resync.error instanceof Error ? resync.error.message : String( resync.error ) }
				</p>
			) : null }
		</div>
	);
}

const PER_PAGE = 25;

function EventsTable(): JSX.Element {
	const queryClient = useQueryClient();
	const [ query, setQuery ] = useState( '' );
	const [ source, setSource ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
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

	const filtered = filterEvents( data, query, source );
	const paged = paginate( filtered, page, PER_PAGE );
	const sources = sourceOptions( data );

	return (
		<>
			<div className="uxs-actions uxs-list-toolbar">
				<input
					type="search"
					value={ query }
					aria-label={ __( 'Search by hook, source or schedule', 'ux-studio' ) }
					placeholder={ __( 'Search by hook, source or schedule', 'ux-studio' ) }
					onChange={ ( e ) => {
						setQuery( e.target.value );
						setPage( 1 );
					} }
				/>
				<select
					value={ source }
					aria-label={ __( 'Source', 'ux-studio' ) }
					onChange={ ( e ) => {
						setSource( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All sources', 'ux-studio' ) }</option>
					{ sources.map( ( name ) => (
						<option key={ name } value={ name }>
							{ name }
						</option>
					) ) }
				</select>
				<span className="uxs-form__help uxs-list-toolbar__count">
					{ sprintf(
						/* translators: %d: number of scheduled events */
						_n( '%d event', '%d events', filtered.length, 'ux-studio' ),
						filtered.length
					) }
				</span>
			</div>
			{ paged.total === 0 ? (
				<p>{ __( 'No event matches the search.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap">
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
							{ paged.items.map( ( event ) => (
								<tr key={ `${ event.hook }-${ event.timestamp }-${ JSON.stringify( event.args ) }` }>
									<td style={ { overflowWrap: 'anywhere' } }>
										<code>{ event.hook }</code>
										{ event.source ? (
											<>
												<br />
												<small className="uxs-form__help">{ event.source }</small>
											</>
										) : null }
									</td>
									<td style={ { whiteSpace: 'nowrap' } }>
										{ event.next_run_human }
										<br />
										<small>{ formatDate( event.timestamp ) }</small>
									</td>
									<td>
										{ event.schedule ? (
											<>
												{ event.schedule_label }
												{ event.schedule_origin === 'plugin' ? (
													<>
														<br />
														<small className="uxs-form__help">{ __( 'set by another plugin', 'ux-studio' ) }</small>
													</>
												) : null }
											</>
										) : (
											__( 'Runs once', 'ux-studio' )
										) }
									</td>
									<td>
										<code style={ { fontSize: '11px', overflowWrap: 'anywhere' } }>{ JSON.stringify( event.args ) }</code>
									</td>
									<td style={ { whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="button"
											title={ __( 'Run now', 'ux-studio' ) }
											aria-label={ __( 'Run now', 'ux-studio' ) }
											disabled={ run.isPending }
											onClick={ () => run.mutate( event ) }
										>
											<Play size={ 14 } />
										</button>{ ' ' }
										<button
											type="button"
											className="button"
											title={ __( 'Delete', 'ux-studio' ) }
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
				</div>
			) }
			{ paged.pages > 1 ? (
				<div className="uxs-actions uxs-pager">
					<button type="button" className="button" disabled={ paged.page <= 1 } onClick={ () => setPage( paged.page - 1 ) }>
						<ChevronLeft size={ 14 } /> { __( 'Previous', 'ux-studio' ) }
					</button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages */
							__( 'Page %1$d of %2$d', 'ux-studio' ),
							paged.page,
							paged.pages
						) }
					</span>
					<button type="button" className="button" disabled={ paged.page >= paged.pages } onClick={ () => setPage( paged.page + 1 ) }>
						{ __( 'Next', 'ux-studio' ) } <ChevronRight size={ 14 } />
					</button>
				</div>
			) : null }
		</>
	);
}

/** An interval in seconds as a short phrase ("5 min", "1 h", "1 day"). */
function formatInterval( seconds: number ): string {
	if ( seconds > 0 && seconds % 86400 === 0 ) {
		return sprintf( _n( '%d day', '%d days', seconds / 86400, 'ux-studio' ), seconds / 86400 );
	}
	if ( seconds > 0 && seconds % 3600 === 0 ) {
		return sprintf( __( '%d h', 'ux-studio' ), seconds / 3600 );
	}
	if ( seconds > 0 && seconds % 60 === 0 ) {
		return sprintf( __( '%d min', 'ux-studio' ), seconds / 60 );
	}
	return sprintf( __( '%d s', 'ux-studio' ), seconds );
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
		<div className="uxs-table-wrap"><table className="uxs-table">
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
						<td>
							{ s.display }
							{ s.origin === 'plugin' ? (
								<>
									<br />
									<small className="uxs-form__help">{ __( 'Added by another plugin or theme. Its name comes from there and cannot be translated here.', 'ux-studio' ) }</small>
								</>
							) : null }
						</td>
						<td>{ formatInterval( s.interval ) }</td>
					</tr>
				) ) }
			</tbody>
		</table></div>
	);
}

function WatchList( { title, entries, tone }: { title: string; entries: WatchEntry[]; tone: 'warning' | 'danger' } ): JSX.Element | null {
	if ( entries.length === 0 ) {
		return null;
	}
	return (
		<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
			<h3>{ title } <span className={ `uxs-badge is-${ tone }` }>{ entries.length }</span></h3>
			<div className="uxs-table-wrap"><table className="uxs-table">
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
							<td><small>{ formatDate( e.next_run ) }</small></td>
							<td>{ e.note }</td>
							<td>
								<code style={ { fontSize: '11px' } }>
									{ e.callback_hint.length ? e.callback_hint.join( ', ' ) : '—' }
								</code>
							</td>
						</tr>
					) ) }
				</tbody>
			</table></div>
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
						{ __( 'Last scan:', 'ux-studio' ) } { formatDate( data.scanned_at ) } · { data.total_events } { __( 'events', 'ux-studio' ) } ·{ ' ' }
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
	const queryClient = useQueryClient();
	// Mode + central app sync result change on save.
	const refreshStatus = (): void => void queryClient.invalidateQueries( { queryKey: [ 'cron-control', 'status' ] } );

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
			<TabsNav>
				<button className={ tab === 'events' ? 'is-active' : '' } onClick={ () => setTab( 'events' ) }>
					{ __( 'Events', 'ux-studio' ) }
				</button>
				<button className={ tab === 'urls' ? 'is-active' : '' } onClick={ () => setTab( 'urls' ) }>
					{ __( 'URL events', 'ux-studio' ) }
				</button>
				<button className={ tab === 'schedules' ? 'is-active' : '' } onClick={ () => setTab( 'schedules' ) }>
					{ __( 'Schedules', 'ux-studio' ) }
				</button>
				<button className={ tab === 'watch' ? 'is-active' : '' } onClick={ () => setTab( 'watch' ) }>
					{ __( 'Watcher', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Mode', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'events' && <EventsTable /> }
			{ tab === 'urls' && <UrlEventsView /> }
			{ tab === 'schedules' && <SchedulesTable /> }
			{ tab === 'watch' && (
				<>
					<TopicSettings moduleId="cron-control" include={ withKeys( 'cron_' ) } onSaved={ refreshStatus } />
					<WatchView />
				</>
			) }
			{ tab === 'settings' && (
				<>
					<StatusView />
					<CentralSyncView />
					<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
						<TopicSettings moduleId="cron-control" include={ withKeys( 'mode', 'central_allowed_ips' ) } onSaved={ refreshStatus } />
					</div>
				</>
			) }
		</>
	);
}
