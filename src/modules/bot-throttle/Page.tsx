import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'dashboard' | 'settings' | 'log' | 'test';

interface BotLogRow {
	id: number;
	created_at: string;
	ip_hash: string;
	user_agent: string;
	action: string;
	bot_category: string;
	bot_name: string;
	tier: string;
	delay_ms: number;
	response_status: number;
}

interface TierInfo {
	tier: string;
	score: number;
}

interface StatRow {
	bot_name?: string;
	bot_category?: string;
	action?: string;
	c: number;
	avg_delay: number;
}

interface Dashboard {
	tier: TierInfo;
	stats_24h: { total: number; blocked: number; delayed: number; by_action: StatRow[]; top_bots: StatRow[] };
	stats_1h: { total: number };
	cache: { count: number; bytes: number };
	categories: Array< { id: string; label: string; action: string; bots: string[] } >;
}

interface TestResult {
	detected: boolean;
	bot: { category: string; name: string; verified: boolean } | null;
	tier: TierInfo;
	plan: { action: string; delay_ms: number; status: number } | null;
}

const TIER_COLORS: Record< string, string > = {
	GREEN: '#16a34a',
	YELLOW: '#dba617',
	ORANGE: '#d97706',
	RED: '#d63638',
};

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
		</div>
	);
}

function DashboardTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'bot-throttle', 'dashboard' ],
		queryFn: () => api< Dashboard >( 'bot-throttle/dashboard' ),
	} );
	const clearCache = useMutation( {
		mutationFn: () => api( 'bot-throttle/clear-cache', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'bot-throttle', 'dashboard' ] } ),
	} );

	if ( isLoading || ! data ) {
		return <Loading />;
	}

	const color = TIER_COLORS[ data.tier.tier ] ?? '#646970';

	return (
		<>
			<div className="uxs-bt-tier" style={ { borderLeft: `4px solid ${ color }` } }>
				<span>{ __( 'Current server load', 'ux-studio' ) }</span>
				<strong style={ { color } }>{ data.tier.tier }</strong>
				<span className="uxs-bt-tier__score">{ Math.round( data.tier.score ) }%</span>
			</div>

			<div className="uxs-cards">
				<div className="uxs-card"><div className="uxs-card__num">{ data.stats_1h.total }</div><div className="uxs-card__label">{ __( 'Bot requests (1h)', 'ux-studio' ) }</div></div>
				<div className="uxs-card"><div className="uxs-card__num">{ data.stats_24h.total }</div><div className="uxs-card__label">{ __( 'Bot requests (24h)', 'ux-studio' ) }</div></div>
				<div className="uxs-card"><div className="uxs-card__num">{ data.stats_24h.delayed }</div><div className="uxs-card__label">{ __( 'Delayed (24h)', 'ux-studio' ) }</div></div>
				<div className="uxs-card"><div className="uxs-card__num">{ data.stats_24h.blocked }</div><div className="uxs-card__label">{ __( 'Blocked (24h)', 'ux-studio' ) }</div></div>
				<div className="uxs-card"><div className="uxs-card__num">{ data.cache.count }</div><div className="uxs-card__label">{ __( 'Microcache files', 'ux-studio' ) }</div></div>
			</div>

			<h3>{ __( 'Top bots (24h)', 'ux-studio' ) }</h3>
			{ data.stats_24h.top_bots.length === 0 ? (
				<p>{ __( 'No data yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Bot', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th>{ __( 'Requests', 'ux-studio' ) }</th>
							<th>{ __( 'Ø delay', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.stats_24h.top_bots.map( ( b, i ) => (
							<tr key={ i }>
								<td><strong>{ b.bot_name }</strong></td>
								<td>{ b.bot_category }</td>
								<td>{ b.c }</td>
								<td>{ Math.round( b.avg_delay ) } ms</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<h3>{ __( 'Category rules', 'ux-studio' ) }</h3>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Category', 'ux-studio' ) }</th>
						<th>{ __( 'Active action', 'ux-studio' ) }</th>
						<th>{ __( 'Bots', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.categories.map( ( c ) => (
						<tr key={ c.id }>
							<td><strong>{ c.label }</strong></td>
							<td><code>{ c.action }</code></td>
							<td style={ { fontSize: 12 } }>{ c.bots.join( ', ' ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>

			<p style={ { marginTop: 16 } }>
				<button type="button" className="button" onClick={ () => clearCache.mutate() }>
					{ __( 'Clear microcache', 'ux-studio' ) }
				</button>
			</p>
		</>
	);
}

function LogTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'bot-throttle', 'log' ],
		queryFn: () => api< BotLogRow[] >( 'bot-throttle/log' ),
	} );
	const clearLog = useMutation( {
		mutationFn: () => api( 'bot-throttle/clear-log', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'bot-throttle', 'log' ] } ),
	} );

	if ( isLoading ) {
		return <Loading />;
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No bot activity recorded yet.', 'ux-studio' ) }</p>;
	}

	return (
		<>
			<p>
				<button type="button" className="button" onClick={ () => clearLog.mutate() }>
					{ __( 'Clear log', 'ux-studio' ) }
				</button>
			</p>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Date', 'ux-studio' ) }</th>
						<th>{ __( 'Bot', 'ux-studio' ) }</th>
						<th>{ __( 'Category', 'ux-studio' ) }</th>
						<th>{ __( 'Tier', 'ux-studio' ) }</th>
						<th>{ __( 'Action', 'ux-studio' ) }</th>
						<th>{ __( 'Delay', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.map( ( row ) => (
						<tr key={ row.id }>
							<td>{ row.created_at }</td>
							<td><strong>{ row.bot_name || '—' }</strong></td>
							<td>{ row.bot_category || '—' }</td>
							<td>
								<span className="uxs-badge" style={ { background: TIER_COLORS[ row.tier ] ?? '#646970', color: '#fff' } }>
									{ row.tier }
								</span>
							</td>
							<td>
								<span className={ `uxs-badge ${ row.action === 'block' || row.action === 'ratelimit' ? 'is-danger' : 'is-success' }` }>
									{ row.action }
								</span>
							</td>
							<td>{ row.delay_ms } ms</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}

const SAMPLE_UAS: Record< string, string > = {
	Googlebot: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
	GPTBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.0; +https://openai.com/gptbot',
	ClaudeBot: 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
	PerplexityBot: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot',
	AhrefsBot: 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
	SemrushBot: 'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
};

function TestTab(): JSX.Element {
	const [ ua, setUa ] = useState( '' );
	const [ ip, setIp ] = useState( '127.0.0.1' );
	const run = useMutation( {
		mutationFn: () =>
			api< TestResult >( 'bot-throttle/test', {
				method: 'POST',
				body: JSON.stringify( { user_agent: ua, ip } ),
			} ),
	} );

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label htmlFor="uxs-bt-test-ua">{ __( 'User-Agent', 'ux-studio' ) }</label>
				<textarea id="uxs-bt-test-ua" rows={ 3 } value={ ua } onChange={ ( e ) => setUa( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bt-test-ip">{ __( 'IP (optional)', 'ux-studio' ) }</label>
				<input id="uxs-bt-test-ip" type="text" value={ ip } onChange={ ( e ) => setIp( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Samples', 'ux-studio' ) }</label>
				<div>
					{ Object.entries( SAMPLE_UAS ).map( ( [ name, value ] ) => (
						<button key={ name } type="button" className="button button-small" style={ { marginRight: 6, marginBottom: 6 } } onClick={ () => setUa( value ) }>
							{ name }
						</button>
					) ) }
				</div>
			</div>
			<p>
				<button type="button" className="button button-primary" disabled={ ! ua || run.isPending } onClick={ () => run.mutate() }>
					{ __( 'Test', 'ux-studio' ) }
				</button>
			</p>
			{ run.data && (
				<table className="uxs-table">
					<tbody>
						<tr><th>{ __( 'Detected as bot', 'ux-studio' ) }</th><td>{ run.data.detected ? __( 'Yes', 'ux-studio' ) : __( 'No', 'ux-studio' ) }</td></tr>
						{ run.data.bot && (
							<>
								<tr><th>{ __( 'Bot', 'ux-studio' ) }</th><td>{ run.data.bot.name }</td></tr>
								<tr><th>{ __( 'Category', 'ux-studio' ) }</th><td>{ run.data.bot.category }</td></tr>
							</>
						) }
						<tr><th>{ __( 'Current tier', 'ux-studio' ) }</th><td>{ run.data.tier.tier } ({ Math.round( run.data.tier.score ) }%)</td></tr>
						{ run.data.plan && (
							<>
								<tr><th>{ __( 'Planned action', 'ux-studio' ) }</th><td><code>{ run.data.plan.action }</code></td></tr>
								<tr><th>{ __( 'Delay', 'ux-studio' ) }</th><td>{ run.data.plan.delay_ms } ms</td></tr>
							</>
						) }
					</tbody>
				</table>
			) }
		</div>
	);
}

function SettingsTab(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'bot-throttle' );
	if ( isLoading || ! data ) {
		return <Loading />;
	}
	return (
		<>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
			<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'dashboard' );

	const tabs: Array< [ Tab, string ] > = [
		[ 'dashboard', __( 'Dashboard', 'ux-studio' ) ],
		[ 'settings', __( 'Settings', 'ux-studio' ) ],
		[ 'log', __( 'Log', 'ux-studio' ) ],
		[ 'test', __( 'Test', 'ux-studio' ) ],
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
					{ __( 'Bot Throttle', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				{ tabs.map( ( [ id, label ] ) => (
					<button key={ id } className={ tab === id ? 'is-active' : '' } onClick={ () => setTab( id ) }>
						{ label }
					</button>
				) ) }
			</div>
			{ tab === 'dashboard' && <DashboardTab /> }
			{ tab === 'settings' && <SettingsTab /> }
			{ tab === 'log' && <LogTab /> }
			{ tab === 'test' && <TestTab /> }
		</>
	);
}
