import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { ChatTab } from './ChatTab';
import { KnowledgeHubTab } from './KnowledgeHubTab';
import { RagTrainingTab } from './RagTrainingTab';
import { HandoffTab } from './HandoffTab';
import { ContentCreatorTab } from './ContentCreatorTab';
import { BlogPilotTab } from './BlogPilotTab';
import { InternalChatTab } from './InternalChatTab';
import { McpTab } from './McpTab';

/**
 * Tab registry for this module's page - covers every AI Assistant sub-feature
 * (chat, RAG, handoff, content generation, blog pilot, internal chat, MCP).
 */
type TabId =
	| 'settings'
	| 'usage'
	| 'conversations'
	| 'knowledge'
	| 'rag'
	| 'handoff'
	| 'content'
	| 'blog-pilot'
	| 'internal-chat'
	| 'mcp';

const TABS: { id: TabId; label: string }[] = [
	{ id: 'settings', label: __( 'Settings', 'ux-studio' ) },
	{ id: 'usage', label: __( 'Usage', 'ux-studio' ) },
	{ id: 'conversations', label: __( 'Conversations', 'ux-studio' ) },
	{ id: 'handoff', label: __( 'Handoff', 'ux-studio' ) },
	{ id: 'knowledge', label: __( 'Knowledge Hub', 'ux-studio' ) },
	{ id: 'rag', label: __( 'RAG Training', 'ux-studio' ) },
	{ id: 'content', label: __( 'Content Creator', 'ux-studio' ) },
	{ id: 'blog-pilot', label: __( 'Blog Pilot', 'ux-studio' ) },
	{ id: 'internal-chat', label: __( 'Internal Chat', 'ux-studio' ) },
	{ id: 'mcp', label: __( 'MCP', 'ux-studio' ) },
];

interface UsageStatRow {
	provider: string;
	model: string;
	feature: string;
	requests: number | string;
	total_input: number | string;
	total_output: number | string;
}

interface UsageSummary {
	requests: number;
	input_tokens: number;
	output_tokens: number;
	sessions: number;
}

interface LimitStatus {
	used: number;
	limit: number;
	percent: number;
	remaining: number;
}

interface UsageResponse {
	summary: UsageSummary;
	stats: UsageStatRow[];
	limit_status: Record< string, LimitStatus >;
}

const LIMIT_LABELS: Record< string, string > = {
	monthly_tokens: __( 'Monthly tokens', 'ux-studio' ),
	daily_requests: __( 'Daily requests (site-wide)', 'ux-studio' ),
	user_daily_requests: __( 'Daily requests (per user)', 'ux-studio' ),
};

function SettingsTab(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'ai-assistant' );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
			<p>
				{ draft.has_claude_api_key
					? __( 'A Claude API key is currently set.', 'ux-studio' )
					: __( 'No Claude API key set yet.', 'ux-studio' ) }
				{ ' · ' }
				{ draft.has_openai_api_key
					? __( 'An OpenAI API key is currently set.', 'ux-studio' )
					: __( 'No OpenAI API key set yet.', 'ux-studio' ) }
				{ ' · ' }
				{ draft.has_deepseek_api_key
					? __( 'A DeepSeek API key is currently set.', 'ux-studio' )
					: __( 'No DeepSeek API key set yet.', 'ux-studio' ) }
			</p>
			<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);
}

function LimitBar( { id, status }: { id: string; status: LimitStatus } ): JSX.Element {
	return (
		<div className="uxs-form__row">
			<label>{ LIMIT_LABELS[ id ] ?? id }</label>
			<div>
				<div
					style={ {
						background: 'var(--uxs-border, #e2e2e2)',
						borderRadius: 4,
						height: 8,
						overflow: 'hidden',
					} }
				>
					<div
						style={ {
							width: `${ status.percent }%`,
							background: status.percent >= 100 ? 'var(--uxs-danger, #d63638)' : 'var(--uxs-primary, #2271b1)',
							height: '100%',
						} }
					/>
				</div>
				<p className="uxs-form__help">
					{ status.used.toLocaleString() } / { status.limit.toLocaleString() } ({ status.percent }%)
				</p>
			</div>
		</div>
	);
}

function UsageTab(): JSX.Element {
	const [ days, setDays ] = useState( 30 );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'ai-assistant', 'usage', days ],
		queryFn: () => api< UsageResponse >( `ai-assistant/usage?days=${ days }` ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const limitEntries = Object.entries( data.limit_status );

	return (
		<>
			<div className="uxs-form__row">
				<label htmlFor="uxs-ai-usage-days">{ __( 'Period (days)', 'ux-studio' ) }</label>
				<input
					id="uxs-ai-usage-days"
					type="number"
					min={ 1 }
					value={ days }
					onChange={ ( e ) => setDays( Math.max( 1, Number( e.target.value ) ) ) }
				/>
			</div>

			<p>
				{ __( 'Requests:', 'ux-studio' ) } { data.summary.requests } { ' · ' }
				{ __( 'Input tokens:', 'ux-studio' ) } { data.summary.input_tokens } { ' · ' }
				{ __( 'Output tokens:', 'ux-studio' ) } { data.summary.output_tokens } { ' · ' }
				{ __( 'Sessions:', 'ux-studio' ) } { data.summary.sessions }
			</p>

			{ limitEntries.length > 0 && (
				<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
					{ limitEntries.map( ( [ id, status ] ) => (
						<LimitBar key={ id } id={ id } status={ status } />
					) ) }
				</div>
			) }

			{ data.stats.length === 0 ? (
				<p>{ __( 'No usage recorded for this period.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Provider', 'ux-studio' ) }</th>
							<th>{ __( 'Model', 'ux-studio' ) }</th>
							<th>{ __( 'Feature', 'ux-studio' ) }</th>
							<th>{ __( 'Requests', 'ux-studio' ) }</th>
							<th>{ __( 'Input tokens', 'ux-studio' ) }</th>
							<th>{ __( 'Output tokens', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.stats.map( ( row, i ) => (
							<tr key={ `${ row.provider }-${ row.model }-${ row.feature }-${ i }` }>
								<td>{ row.provider }</td>
								<td>{ row.model }</td>
								<td>{ row.feature }</td>
								<td>{ row.requests }</td>
								<td>{ row.total_input }</td>
								<td>{ row.total_output }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< TabId >( 'settings' );

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
					{ __( 'AI Assistant', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				{ TABS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label }
					</button>
				) ) }
			</div>
			{ tab === 'settings' && <SettingsTab /> }
			{ tab === 'usage' && <UsageTab /> }
			{ tab === 'conversations' && <ChatTab /> }
			{ tab === 'handoff' && <HandoffTab /> }
			{ tab === 'knowledge' && <KnowledgeHubTab /> }
			{ tab === 'rag' && <RagTrainingTab /> }
			{ tab === 'content' && <ContentCreatorTab /> }
			{ tab === 'blog-pilot' && <BlogPilotTab /> }
			{ tab === 'internal-chat' && <InternalChatTab /> }
			{ tab === 'mcp' && <McpTab /> }
		</>
	);
}
