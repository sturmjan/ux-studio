/**
 * AI Markdown: serves a local (non-AI) markdown version of content for bots.
 * Route: #/module?id=ai-markdown
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { TopicSettings, withKeys } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';

type Tab = 'cache' | 'log' | 'settings';

interface Stats {
	cached_posts: number;
	log_entries_24h: number;
	/** Empty when the endpoint is switched off. */
	llms_txt_url: string;
	ai_sitemap_url: string;
}

interface CacheRow {
	post_id: number;
	title: string;
	generated_at: string;
}

function CacheTab(): JSX.Element {
	const { data: stats } = useQuery( {
		queryKey: [ 'ai-markdown', 'stats' ],
		queryFn: () => api< Stats >( 'ai-markdown/stats' ),
	} );
	const { data: rows, isLoading } = useQuery( {
		queryKey: [ 'ai-markdown', 'list' ],
		queryFn: () => api< CacheRow[] >( 'ai-markdown/list' ),
	} );

	const regenerateOne = useMutation( {
		mutationFn: ( id: number ) => api( `ai-markdown/regenerate/${ id }`, { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'ai-markdown' ] } ),
	} );

	const regenerateAll = useMutation( {
		mutationFn: () => api( 'ai-markdown/regenerate-all', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'ai-markdown' ] } ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)', flexDirection: 'row', alignItems: 'center', gap: 'var(--uxs-sp-4)' } }>
				<p className="uxs-form__help" style={ { margin: 0 } }>
					{ __( 'Cached posts', 'ux-studio' ) }: { stats?.cached_posts ?? 0 } &nbsp;·&nbsp;
					{ __( 'Bot requests (24h)', 'ux-studio' ) }: { stats?.log_entries_24h ?? 0 }
				</p>
				<button
					type="button"
					className="button button-primary"
					disabled={ regenerateAll.isPending }
					onClick={ () => regenerateAll.mutate() }
				>
					<RefreshCw size={ 14 } /> { __( 'Regenerate all', 'ux-studio' ) }
				</button>
			</div>

			{ stats && ( stats.llms_txt_url || stats.ai_sitemap_url ) ? (
				<p className="uxs-form__help" style={ { marginTop: 0, marginBottom: 'var(--uxs-sp-5)' } }>
					{ stats.llms_txt_url ? (
						<>
							<strong>llms.txt:</strong>{ ' ' }
							<a href={ stats.llms_txt_url } target="_blank" rel="noreferrer">{ stats.llms_txt_url }</a>
							&nbsp;&nbsp;
						</>
					) : null }
					{ stats.ai_sitemap_url ? (
						<>
							<strong>{ __( 'AI sitemap', 'ux-studio' ) }:</strong>{ ' ' }
							<a href={ stats.ai_sitemap_url } target="_blank" rel="noreferrer">{ stats.ai_sitemap_url }</a>
						</>
					) : null }
				</p>
			) : null }

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Post', 'ux-studio' ) }</th>
							<th>{ __( 'Generated', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ ( rows ?? [] ).map( ( row ) => (
							<tr key={ row.post_id }>
								<td>{ row.title }</td>
								<td>{ row.generated_at }</td>
								<td>
									<button
										type="button"
										className="button-link"
										disabled={ regenerateOne.isPending }
										onClick={ () => regenerateOne.mutate( row.post_id ) }
									>
										{ __( 'Regenerate', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
						{ ! rows?.length ? (
							<tr>
								<td colSpan={ 3 }>{ __( 'Nothing cached yet.', 'ux-studio' ) }</td>
							</tr>
						) : null }
					</tbody>
				</table>
			) }
		</>
	);
}

interface LogRow {
	id: number;
	created_at: string;
	post_id: number;
	bot_name: string;
	url: string;
	content_type: string;
	ip_address: string;
	user_agent: string;
}

interface LogPage {
	items: LogRow[];
	total: number;
}

interface LogStats {
	total: number;
	today: number;
	bots: Array< { bot_name: string; count: number } >;
}

const LOG_PAGE_SIZE = 30;

/** Readable label of a logged content type (llms_txt | sitemap | post type). */
function typeLabel( type: string ): string {
	const labels: Record< string, string > = {
		llms_txt: 'llms.txt',
		sitemap: __( 'AI sitemap', 'ux-studio' ),
		post: __( 'Post', 'ux-studio' ),
		page: __( 'Page', 'ux-studio' ),
		product: __( 'Product', 'ux-studio' ),
	};
	return labels[ type ] ?? type;
}

const inputStyle = {
	background: 'var(--uxs-surface)',
	color: 'var(--uxs-text)',
	border: '1px solid var(--uxs-border)',
	borderRadius: 'var(--uxs-radius-s)',
	padding: 'var(--uxs-sp-2) var(--uxs-sp-3)',
};

function LogTab(): JSX.Element {
	const [ search, setSearch ] = useState( '' );
	const [ bot, setBot ] = useState( '' );
	const [ type, setType ] = useState( '' );
	const [ page, setPage ] = useState( 0 );
	const [ selected, setSelected ] = useState< number[] >( [] );

	const { data: stats } = useQuery( {
		queryKey: [ 'ai-markdown', 'log-stats' ],
		queryFn: () => api< LogStats >( 'ai-markdown/log/stats' ),
	} );
	const { data, isLoading } = useQuery( {
		queryKey: [ 'ai-markdown', 'log', search, bot, type, page ],
		queryFn: () =>
			api< LogPage >(
				`ai-markdown/log?search=${ encodeURIComponent( search ) }&bot=${ encodeURIComponent( bot ) }&type=${ encodeURIComponent( type ) }&limit=${ LOG_PAGE_SIZE }&offset=${ page * LOG_PAGE_SIZE }`
			),
	} );

	const remove = useMutation( {
		mutationFn: ( ids: number[] ) => api< { deleted: number } >( 'ai-markdown/log', { method: 'DELETE', body: JSON.stringify( { ids } ) } ),
		onSuccess: () => {
			setSelected( [] );
			void queryClient.invalidateQueries( { queryKey: [ 'ai-markdown' ] } );
		},
	} );

	const rows = data?.items ?? [];
	const total = data?.total ?? 0;
	const allChecked = rows.length > 0 && rows.every( ( r ) => selected.includes( r.id ) );
	const resetPage = (): void => {
		setPage( 0 );
		setSelected( [] );
	};
	const top = stats?.bots[ 0 ];

	return (
		<>
			<p className="uxs-form__help" style={ { marginTop: 0 } }>
				{ __( 'Total', 'ux-studio' ) }: { stats?.total ?? 0 } &nbsp;·&nbsp;
				{ __( 'Today', 'ux-studio' ) }: { stats?.today ?? 0 }
				{ top ? (
					<>
						&nbsp;·&nbsp;{ __( 'Top bot', 'ux-studio' ) }: { top.bot_name } ({ top.count })
					</>
				) : null }
			</p>

			{ stats && stats.bots.length > 0 ? (
				<table className="uxs-table" style={ { maxWidth: 480, marginBottom: 'var(--uxs-sp-5)' } }>
					<thead>
						<tr>
							<th>{ __( 'AI bot', 'ux-studio' ) }</th>
							<th>{ __( 'Requests', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ stats.bots.map( ( b ) => (
							<tr key={ b.bot_name }>
								<td>
									<button
										type="button"
										className="button-link"
										onClick={ () => {
											setBot( b.bot_name );
											resetPage();
										} }
									>
										{ b.bot_name }
									</button>
								</td>
								<td>{ b.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }

			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-3)', alignItems: 'center', marginBottom: 'var(--uxs-sp-3)' } }>
				<input
					type="search"
					style={ { ...inputStyle, minWidth: 240 } }
					value={ search }
					placeholder={ __( 'Search bot, URL, IP…', 'ux-studio' ) }
					onChange={ ( e ) => {
						setSearch( e.target.value );
						resetPage();
					} }
				/>
				<select
					style={ inputStyle }
					value={ bot }
					aria-label={ __( 'AI bot', 'ux-studio' ) }
					onChange={ ( e ) => {
						setBot( e.target.value );
						resetPage();
					} }
				>
					<option value="">{ __( 'All bots', 'ux-studio' ) }</option>
					{ ( stats?.bots ?? [] ).map( ( b ) => (
						<option key={ b.bot_name } value={ b.bot_name }>{ b.bot_name }</option>
					) ) }
				</select>
				<select
					style={ inputStyle }
					value={ type }
					aria-label={ __( 'Content type', 'ux-studio' ) }
					onChange={ ( e ) => {
						setType( e.target.value );
						resetPage();
					} }
				>
					<option value="">{ __( 'All types', 'ux-studio' ) }</option>
					{ [ 'llms_txt', 'sitemap', 'post', 'page', 'product' ].map( ( t ) => (
						<option key={ t } value={ t }>{ typeLabel( t ) }</option>
					) ) }
				</select>
				<button
					type="button"
					className="button"
					disabled={ selected.length === 0 || remove.isPending }
					onClick={ () => {
						if ( window.confirm( __( 'Delete the selected log entries?', 'ux-studio' ) ) ) {
							remove.mutate( selected );
						}
					} }
				>
					<Trash2 size={ 14 } /> { __( 'Delete selected', 'ux-studio' ) } ({ selected.length })
				</button>
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : rows.length === 0 ? (
				<p>{ __( 'No AI bot has accessed the markdown endpoints yet.', 'ux-studio' ) }</p>
			) : (
				<>
					<table className="uxs-table">
						<thead>
							<tr>
								<th style={ { width: 28 } }>
									<input
										type="checkbox"
										checked={ allChecked }
										aria-label={ __( 'Select all', 'ux-studio' ) }
										onChange={ () => setSelected( allChecked ? [] : rows.map( ( r ) => r.id ) ) }
									/>
								</th>
								<th>{ __( 'AI bot', 'ux-studio' ) }</th>
								<th>{ __( 'URL', 'ux-studio' ) }</th>
								<th>{ __( 'Content type', 'ux-studio' ) }</th>
								<th>{ __( 'IP address', 'ux-studio' ) }</th>
								<th>{ __( 'Date', 'ux-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										<input
											type="checkbox"
											checked={ selected.includes( row.id ) }
											aria-label={ __( 'Select', 'ux-studio' ) }
											onChange={ () =>
												setSelected( ( cur ) => ( cur.includes( row.id ) ? cur.filter( ( id ) => id !== row.id ) : [ ...cur, row.id ] ) )
											}
										/>
									</td>
									<td title={ row.user_agent }>
										<span className="uxs-badge">{ row.bot_name }</span>
									</td>
									<td style={ { wordBreak: 'break-all' } }>
										{ row.url ? (
											<a href={ row.url } target="_blank" rel="noreferrer">
												{ row.url.length > 80 ? `${ row.url.slice( 0, 80 ) }…` : row.url }
											</a>
										) : (
											'—'
										) }
									</td>
									<td>{ typeLabel( row.content_type ) }</td>
									<td>
										<code>{ row.ip_address || '—' }</code>
									</td>
									<td>{ row.created_at }</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', alignItems: 'center', marginTop: 'var(--uxs-sp-4)' } }>
						<button type="button" className="button" disabled={ page === 0 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							{ __( 'Previous', 'ux-studio' ) }
						</button>
						<span className="uxs-form__help" style={ { margin: 0 } }>
							{ page * LOG_PAGE_SIZE + 1 }–{ Math.min( total, ( page + 1 ) * LOG_PAGE_SIZE ) } / { total }
						</span>
						<button
							type="button"
							className="button"
							disabled={ ( page + 1 ) * LOG_PAGE_SIZE >= total }
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Next', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }
		</>
	);
}

const MODULE = 'ai-markdown';
const isCacheKey = withKeys( 'cache_ttl', 'auto_regenerate', 'auto_regenerate_interval' );
const isLogKey = withKeys( 'enable_logging', 'log_retention_days' );

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'cache' );

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
					{ __( 'AI Markdown', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				<button className={ tab === 'cache' ? 'is-active' : '' } onClick={ () => setTab( 'cache' ) }>
					{ __( 'Cache', 'ux-studio' ) }
				</button>
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Log', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'cache' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ isCacheKey } collapsible={ __( 'Cache settings', 'ux-studio' ) } />
					<CacheTab />
				</>
			) }
			{ tab === 'log' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ isLogKey } collapsible={ __( 'Log settings', 'ux-studio' ) } />
					<LogTab />
				</>
			) }
			{ tab === 'settings' && <TopicSettings moduleId={ MODULE } include={ ( f ) => ! isCacheKey( f ) && ! isLogKey( f ) } /> }
		</>
	);
}
