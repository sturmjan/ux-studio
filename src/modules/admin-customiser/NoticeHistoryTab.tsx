/**
 * "Notice history": every admin notice the bell panel captured, with its state (active / closed / gone), an
 * estimated source plugin, filters, paging, delete, "show again" and a CSV export. Fed by the REST routes
 * uxstudio/v1/admin-notices* (PLAN.md 20.16, 0.8.1). The texts can contain names and e-mail addresses, they are
 * rendered as plain React text only.
 */
import { useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { keepPreviousData, useMutation, useQuery } from '@tanstack/react-query';
import { Bell, ChevronLeft, ChevronRight, Download, Eye, Info, LoaderCircle, Search, Trash2, X } from 'lucide-react';
import { api, ApiError, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';
import { navigate } from '../../app/route';
import { pushToast } from '../../app/notify';

type NoticeType = 'error' | 'warning' | 'success' | 'info';
type NoticeState = 'active' | 'dismissed' | 'gone';
type Period = '24h' | '7d' | '30d' | 'all';

interface HistoryRow {
	id: number;
	type: NoticeType;
	title: string;
	text: string;
	source_slug: string;
	source_name: string;
	screen: string;
	dismissible: boolean;
	first_seen: string;
	last_seen: string;
	seen_count: number;
	reappeared_count: number;
	state: NoticeState;
	dismissed_at: string | null;
	dismissed_by: number;
	dismissed_by_name: string;
	dismiss_method: '' | 'panel' | 'plugin';
	can_reshow: boolean;
}

interface Stats {
	states: Record< NoticeState, number >;
	types: Record< NoticeType, number >;
	sources: { slug: string; name: string; count: number }[];
	total: number;
	enabled: boolean;
	relocate: boolean;
	capture: boolean;
	gone_days: number;
	retention_days: number;
}

interface Filters {
	state: '' | NoticeState;
	type: '' | NoticeType;
	source: string;
	period: Period;
	q: string;
}

interface ListResult {
	items: HistoryRow[];
	total: number;
	pages: number;
}

const PAGE_SIZE = 20;
const UNKNOWN_SOURCE = '_unknown';
const EMPTY_FILTERS: Filters = { state: '', type: '', source: '', period: 'all', q: '' };
const LONG_TEXT = 150;

const TYPE_LABELS: Record< NoticeType, string > = {
	error: __( 'Error', 'ux-studio' ),
	warning: __( 'Warning', 'ux-studio' ),
	success: __( 'Success', 'ux-studio' ),
	info: __( 'Information', 'ux-studio' ),
};

const STATE_LABELS: Record< NoticeState, string > = {
	active: __( 'Active', 'ux-studio' ),
	dismissed: __( 'Closed', 'ux-studio' ),
	gone: __( 'Gone', 'ux-studio' ),
};

const PERIOD_LABELS: Record< Period, string > = {
	'24h': __( 'Last 24 hours', 'ux-studio' ),
	'7d': __( 'Last 7 days', 'ux-studio' ),
	'30d': __( 'Last 30 days', 'ux-studio' ),
	all: __( 'All time', 'ux-studio' ),
};

function filterParams( filters: Filters ): URLSearchParams {
	const params = new URLSearchParams();
	( Object.keys( filters ) as ( keyof Filters )[] ).forEach( ( key ) => {
		const value = filters[ key ];
		if ( value && ! ( key === 'period' && value === 'all' ) ) {
			params.set( key, value );
		}
	} );
	return params;
}

async function fetchList( filters: Filters, page: number ): Promise< ListResult > {
	const boot = window.uxStudioBoot;
	const params = filterParams( filters );
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( PAGE_SIZE ) );
	const res = await fetch( `${ boot.restUrl }/admin-notices?${ params.toString() }`, { headers: { 'X-WP-Nonce': boot.nonce } } );
	if ( ! res.ok ) {
		const body: { message?: string } = await res.json().catch( () => ( {} ) );
		throw new Error( body.message ?? `HTTP ${ res.status }` );
	}
	const json: { data: HistoryRow[]; meta?: { total?: number; pages?: number } } = await res.json();
	return { items: json.data, total: json.meta?.total ?? json.data.length, pages: json.meta?.pages ?? 1 };
}

function errorMessage( error: unknown ): string {
	return error instanceof ApiError || error instanceof Error ? error.message : __( 'Something went wrong.', 'ux-studio' );
}

function sourceLabel( row: HistoryRow ): string {
	return row.source_name || row.source_slug || __( 'Unknown source', 'ux-studio' );
}

function RowItem( {
	row,
	selected,
	onSelect,
	onDelete,
	onReshow,
	busy,
}: {
	row: HistoryRow;
	selected: boolean;
	onSelect: ( checked: boolean ) => void;
	onDelete: () => void;
	onReshow: () => void;
	busy: boolean;
} ): JSX.Element {
	const [ expanded, setExpanded ] = useState( false );
	const long = row.text.length > LONG_TEXT;
	const unknown = '' === row.source_slug && '' === row.source_name;
	const closedBy = row.dismissed_by_name || ( row.dismissed_by > 0 ? sprintf( __( 'user #%d', 'ux-studio' ), row.dismissed_by ) : '' );

	return (
		<li className={ `uxs-nh__row is-${ row.type }${ selected ? ' is-selected' : '' }` }>
			<input
				type="checkbox"
				className="uxs-nh__check"
				checked={ selected }
				onChange={ ( e ) => onSelect( e.target.checked ) }
				aria-label={ sprintf( __( 'Select notice: %s', 'ux-studio' ), row.title || row.text.slice( 0, 60 ) ) }
			/>
			<span className="uxs-nh__dot" title={ TYPE_LABELS[ row.type ] } role="img" aria-label={ TYPE_LABELS[ row.type ] } />
			<div className="uxs-nh__main">
				{ row.title ? <strong className="uxs-nh__title">{ row.title }</strong> : null }
				<p className={ `uxs-nh__text${ expanded ? ' is-expanded' : '' }` }>{ row.text }</p>
				{ long ? (
					<button type="button" className="uxs-nh__more" aria-expanded={ expanded } onClick={ () => setExpanded( ! expanded ) }>
						{ expanded ? __( 'Show less', 'ux-studio' ) : __( 'Show full text', 'ux-studio' ) }
					</button>
				) : null }
				<div className="uxs-nh__meta">
					<span
						className={ `uxs-badge uxs-nh__source${ unknown ? ' is-unknown' : '' }` }
						title={ __( 'The source is an estimate based on the look and wording of the notice.', 'ux-studio' ) }
					>
						{ sourceLabel( row ) }
						<span className="uxs-nh__guess">{ __( 'estimate', 'ux-studio' ) }</span>
					</span>
					<span className={ `uxs-badge uxs-nh__state is-${ row.state }` }>{ STATE_LABELS[ row.state ] }</span>
					<span className="uxs-nh__stat">{ sprintf( __( 'First seen: %s', 'ux-studio' ), formatDate( row.first_seen ) ) }</span>
					<span className="uxs-nh__stat">{ sprintf( __( 'Last seen: %s', 'ux-studio' ), formatDate( row.last_seen ) ) }</span>
					<span className="uxs-nh__stat">{ sprintf( __( 'Shown %d×', 'ux-studio' ), row.seen_count ) }</span>
					{ row.reappeared_count > 0 ? (
						<span className="uxs-nh__stat">{ sprintf( _n( 'Came back %d time', 'Came back %d times', row.reappeared_count, 'ux-studio' ), row.reappeared_count ) }</span>
					) : null }
				</div>
				{ 'dismissed' === row.state ? (
					<p className="uxs-nh__closed">
						{ row.dismissed_at
							? sprintf(
									/* translators: 1: user name, 2: date. */
									__( 'Closed by %1$s, %2$s', 'ux-studio' ),
									closedBy || __( 'someone', 'ux-studio' ),
									formatDate( row.dismissed_at )
							  )
							: __( 'Closed', 'ux-studio' ) }
						{ 'plugin' === row.dismiss_method ? (
							<span className="uxs-nh__note">
								<Info size={ 13 } aria-hidden /> { __( 'Closed by the plugin, cannot be restored', 'ux-studio' ) }
							</span>
						) : null }
					</p>
				) : null }
			</div>
			<div className="uxs-nh__actions">
				{ row.can_reshow ? (
					<button type="button" className="button" disabled={ busy } onClick={ onReshow }>
						<Eye size={ 14 } aria-hidden /> { __( 'Show in panel again', 'ux-studio' ) }
					</button>
				) : null }
				<button type="button" className="button" disabled={ busy } onClick={ onDelete } aria-label={ __( 'Delete record', 'ux-studio' ) }>
					<Trash2 size={ 14 } aria-hidden /> { __( 'Delete record', 'ux-studio' ) }
				</button>
			</div>
		</li>
	);
}

export function NoticeHistoryTab(): JSX.Element {
	const [ filters, setFilters ] = useState< Filters >( EMPTY_FILTERS );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState< Set< number > >( new Set() );

	// The search box waits a moment after typing before it asks the server.
	useEffect( () => {
		const timer = window.setTimeout( () => {
			setFilters( ( f ) => ( f.q === search.trim() ? f : { ...f, q: search.trim() } ) );
			setPage( 1 );
		}, 300 );
		return () => window.clearTimeout( timer );
	}, [ search ] );

	const stats = useQuery( {
		queryKey: [ 'admin-notices', 'stats' ],
		queryFn: () => api< Stats >( 'admin-notices/stats' ),
		staleTime: 0,
	} );
	const list = useQuery( {
		queryKey: [ 'admin-notices', 'list', filters, page ],
		queryFn: () => fetchList( filters, page ),
		placeholderData: keepPreviousData,
		staleTime: 0,
	} );

	// The page may no longer exist after deleting its last rows.
	const pages = list.data?.pages ?? 1;
	useEffect( () => {
		if ( page > pages ) {
			setPage( pages );
		}
	}, [ page, pages ] );

	function refresh(): void {
		setSelected( new Set() );
		void queryClient.invalidateQueries( { queryKey: [ 'admin-notices' ] } );
	}
	function failed( error: unknown ): void {
		pushToast( 'error', errorMessage( error ) );
	}

	const del = useMutation( {
		mutationFn: ( id: number ) => api( `admin-notices/${ id }`, { method: 'DELETE' } ),
		onSuccess: refresh,
		onError: failed,
	} );
	const bulk = useMutation( {
		mutationFn: ( ids: number[] ) => api( 'admin-notices/bulk-delete', { method: 'POST', body: JSON.stringify( { ids } ) } ),
		onSuccess: refresh,
		onError: failed,
	} );
	const clearAll = useMutation( {
		mutationFn: () => api( 'admin-notices', { method: 'DELETE' } ),
		onSuccess: refresh,
		onError: failed,
	} );
	const reshow = useMutation( {
		mutationFn: ( id: number ) => api( `admin-notices/${ id }/reshow`, { method: 'POST' } ),
		onSuccess: () => {
			pushToast( 'success', __( 'The notice will appear in the panel again on the next page load.', 'ux-studio' ) );
			refresh();
		},
		onError: failed,
	} );
	const busy = del.isPending || bulk.isPending || clearAll.isPending || reshow.isPending;

	function change( patch: Partial< Filters > ): void {
		setFilters( ( f ) => ( { ...f, ...patch } ) );
		setPage( 1 );
		setSelected( new Set() );
	}
	function resetFilters(): void {
		setSearch( '' );
		setFilters( EMPTY_FILTERS );
		setPage( 1 );
		setSelected( new Set() );
	}
	function exportCsv(): void {
		const boot = window.uxStudioBoot;
		const url = new URL( `${ boot.restUrl }/admin-notices/export.csv`, window.location.href );
		filterParams( filters ).forEach( ( v, k ) => url.searchParams.set( k, v ) );
		url.searchParams.set( '_wpnonce', boot.nonce );
		window.open( url.toString(), '_blank' );
	}

	const s = stats.data;
	const items = list.data?.items ?? [];
	const total = list.data?.total ?? 0;
	const filtered = '' !== filters.state || '' !== filters.type || '' !== filters.source || 'all' !== filters.period || '' !== filters.q;
	const allOnPage = items.length > 0 && items.every( ( r ) => selected.has( r.id ) );

	if ( stats.isLoading || ( list.isLoading && ! list.data ) ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( stats.isError || ( list.isError && ! list.data ) ) {
		return (
			<div className="uxs-nh__empty" role="alert">
				<p>{ errorMessage( stats.error ?? list.error ) }</p>
				<button type="button" className="button" onClick={ () => void queryClient.invalidateQueries( { queryKey: [ 'admin-notices' ] } ) }>
					{ __( 'Try again', 'ux-studio' ) }
				</button>
			</div>
		);
	}

	const stateTab = ( value: '' | NoticeState, label: string, count: number ): JSX.Element => (
		<button
			key={ value || 'all' }
			type="button"
			className={ `uxs-nh__tab${ filters.state === value ? ' is-active' : '' }` }
			aria-pressed={ filters.state === value }
			onClick={ () => change( { state: value } ) }
		>
			{ label } <span className="uxs-nh__count">{ count }</span>
		</button>
	);

	return (
		<div className="uxs-nh">
			{ s && ! s.capture ? (
				<div className="uxs-nh__banner" role="status">
					<Bell size={ 18 } aria-hidden />
					<div>
						<strong>{ s.enabled ? __( 'The notice panel is off', 'ux-studio' ) : __( 'Hiding of admin notices is off', 'ux-studio' ) }</strong>
						<p>
							{ s.enabled
								? __( 'Notices are hidden completely, not moved to the panel under the bell. New notices are not recorded.', 'ux-studio' )
								: __( 'The history is filled by the panel under the bell in the admin bar. While notices are not hidden there, nothing new is recorded.', 'ux-studio' ) }
						</p>
					</div>
					<button type="button" className="button button-primary" onClick={ () => navigate( 'module', { id: 'admin-appearance', feature: 'admin-customiser' } ) }>
						{ __( 'Open the settings', 'ux-studio' ) }
					</button>
				</div>
			) : null }

			{ s && 0 === s.total ? (
				<div className="uxs-nh__empty">
					<Bell size={ 28 } aria-hidden />
					<h3>{ __( 'No notices recorded yet', 'ux-studio' ) }</h3>
					<p>
						{ s.capture
							? __( 'Notices that WordPress and plugins show in the admin appear here after they land in the panel under the bell.', 'ux-studio' )
							: __( 'Turn on hiding of admin notices with the panel under the bell and they will be collected here.', 'ux-studio' ) }
					</p>
				</div>
			) : (
				<>
					<div className="uxs-nh__head">
						<div className="uxs-nh__tabs" role="group" aria-label={ __( 'State', 'ux-studio' ) }>
							{ stateTab( '', __( 'All', 'ux-studio' ), s?.total ?? 0 ) }
							{ stateTab( 'active', STATE_LABELS.active, s?.states.active ?? 0 ) }
							{ stateTab( 'dismissed', STATE_LABELS.dismissed, s?.states.dismissed ?? 0 ) }
							{ stateTab( 'gone', STATE_LABELS.gone, s?.states.gone ?? 0 ) }
						</div>
						<div className="uxs-nh__tools">
							<button type="button" className="button" onClick={ exportCsv }>
								<Download size={ 14 } aria-hidden /> { __( 'Export CSV', 'ux-studio' ) }
							</button>
							<button
								type="button"
								className="button"
								disabled={ busy }
								onClick={ () => {
									if ( window.confirm( __( 'Delete the whole notice history? This cannot be undone.', 'ux-studio' ) ) ) {
										clearAll.mutate();
									}
								} }
							>
								<Trash2 size={ 14 } aria-hidden /> { __( 'Clear all history', 'ux-studio' ) }
							</button>
						</div>
					</div>

					<div className="uxs-nh__filters">
						<label className="uxs-nh__search">
							<span className="screen-reader-text">{ __( 'Search notices', 'ux-studio' ) }</span>
							<Search size={ 16 } aria-hidden />
							<input type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search title and text…', 'ux-studio' ) } maxLength={ 100 } />
						</label>
						<label>
							<span className="screen-reader-text">{ __( 'Type', 'ux-studio' ) }</span>
							<select value={ filters.type } onChange={ ( e ) => change( { type: e.target.value as Filters[ 'type' ] } ) }>
								<option value="">{ __( 'All types', 'ux-studio' ) }</option>
								{ ( Object.keys( TYPE_LABELS ) as NoticeType[] ).map( ( t ) => (
									<option key={ t } value={ t }>
										{ TYPE_LABELS[ t ] }
									</option>
								) ) }
							</select>
						</label>
						<label>
							<span className="screen-reader-text">{ __( 'Source', 'ux-studio' ) }</span>
							<select value={ filters.source } onChange={ ( e ) => change( { source: e.target.value } ) }>
								<option value="">{ __( 'All sources', 'ux-studio' ) }</option>
								{ ( s?.sources ?? [] ).map( ( src ) => (
									<option key={ src.slug || UNKNOWN_SOURCE } value={ src.slug || UNKNOWN_SOURCE }>
										{ `${ src.slug ? src.name || src.slug : __( 'Unknown source', 'ux-studio' ) } (${ src.count })` }
									</option>
								) ) }
							</select>
						</label>
						<label>
							<span className="screen-reader-text">{ __( 'Period', 'ux-studio' ) }</span>
							<select value={ filters.period } onChange={ ( e ) => change( { period: e.target.value as Period } ) }>
								{ ( Object.keys( PERIOD_LABELS ) as Period[] ).map( ( p ) => (
									<option key={ p } value={ p }>
										{ PERIOD_LABELS[ p ] }
									</option>
								) ) }
							</select>
						</label>
						{ filtered ? (
							<button type="button" className="button" onClick={ resetFilters }>
								<X size={ 14 } aria-hidden /> { __( 'Reset filters', 'ux-studio' ) }
							</button>
						) : null }
					</div>

					<div className="uxs-nh__bar" aria-live="polite">
						<label className="uxs-nh__all">
							<input
								type="checkbox"
								checked={ allOnPage }
								disabled={ 0 === items.length }
								onChange={ ( e ) => setSelected( e.target.checked ? new Set( items.map( ( r ) => r.id ) ) : new Set() ) }
							/>
							<span>{ __( 'Select all on this page', 'ux-studio' ) }</span>
						</label>
						<span className="uxs-nh__total">{ sprintf( _n( '%d notice', '%d notices', total, 'ux-studio' ), total ) }</span>
						{ selected.size > 0 ? (
							<button
								type="button"
								className="button"
								disabled={ busy }
								onClick={ () => {
									if ( window.confirm( sprintf( _n( 'Delete %d selected record?', 'Delete %d selected records?', selected.size, 'ux-studio' ), selected.size ) ) ) {
										bulk.mutate( [ ...selected ] );
									}
								} }
							>
								<Trash2 size={ 14 } aria-hidden /> { sprintf( __( 'Delete selected (%d)', 'ux-studio' ), selected.size ) }
							</button>
						) : null }
					</div>

					{ 0 === items.length ? (
						<div className="uxs-nh__empty">
							<h3>{ __( 'No notices match the filters', 'ux-studio' ) }</h3>
							<button type="button" className="button" onClick={ resetFilters }>
								{ __( 'Reset filters', 'ux-studio' ) }
							</button>
						</div>
					) : (
						<ul className={ `uxs-nh__list${ list.isFetching ? ' is-loading' : '' }` }>
							{ items.map( ( row ) => (
								<RowItem
									key={ row.id }
									row={ row }
									busy={ busy }
									selected={ selected.has( row.id ) }
									onSelect={ ( checked ) =>
										setSelected( ( prev ) => {
											const next = new Set( prev );
											if ( checked ) {
												next.add( row.id );
											} else {
												next.delete( row.id );
											}
											return next;
										} )
									}
									onDelete={ () => del.mutate( row.id ) }
									onReshow={ () => reshow.mutate( row.id ) }
								/>
							) ) }
						</ul>
					) }

					{ pages > 1 ? (
						<nav className="uxs-nh__pager" aria-label={ __( 'Pages', 'ux-studio' ) }>
							<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
								<ChevronLeft size={ 14 } aria-hidden /> { __( 'Previous', 'ux-studio' ) }
							</button>
							<span className="uxs-form__help">{ sprintf( __( 'Page %1$d of %2$d', 'ux-studio' ), page, pages ) }</span>
							<button type="button" className="button" disabled={ page >= pages } onClick={ () => setPage( page + 1 ) }>
								{ __( 'Next', 'ux-studio' ) } <ChevronRight size={ 14 } aria-hidden />
							</button>
						</nav>
					) : null }

					{ s ? (
						<p className="uxs-form__help uxs-nh__foot">
							{ sprintf(
								/* translators: 1: days until a missing notice counts as gone, 2: days the history is kept. */
								__( 'A notice that has not been seen for %1$d days is marked as gone. Records are kept for %2$d days after the last sighting (at most 1000).', 'ux-studio' ),
								s.gone_days,
								s.retention_days
							) }
						</p>
					) : null }
				</>
			) }
		</div>
	);
}
