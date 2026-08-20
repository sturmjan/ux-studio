import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Pause, Play, Plus, Sparkles, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface ArticleType {
	name: string;
	description: string;
	prompt_hint: string;
}

interface ProviderInfo {
	label: string;
	models: Record< string, string >;
}

interface GeneratorConfig {
	language?: string;
	tone?: string;
	length?: string;
	post_status?: string;
	category_id?: number;
	author_id?: number;
	custom_instructions?: string;
}

interface ScheduleConfig {
	time_from?: string;
	time_to?: string;
	days_of_week?: number[];
}

interface Generator {
	id: number;
	title: string;
	topics: string[];
	article_types: string[];
	provider: string;
	model: string;
	config: GeneratorConfig;
	schedule_type: string;
	schedule_config: ScheduleConfig;
	posts_per_run: number;
	status: string;
	total_posts: number;
	last_run_at: string | null;
	last_error: string | null;
	next_run?: string | null;
	created_at: string;
}

interface GeneratorsResponse {
	items: Generator[];
	total: number;
	page: number;
	per_page: number;
}

interface GeneratedPost {
	id: number;
	generator_id: number;
	post_id: number;
	topic: string;
	article_type: string;
	tokens_used: number;
	created_at: string;
	wp_title: string | null;
	wp_status: string | null;
	generator_title: string | null;
}

interface GeneratedPostsResponse {
	items: GeneratedPost[];
	total: number;
	page: number;
	per_page: number;
}

interface Stats {
	total_generators: number;
	active_generators: number;
	total_posts: number;
	total_tokens: number;
	posts_last_7_days: number;
	last_generation: string | null;
}

interface RunResult {
	post_id: number;
	title: string;
	edit_url: string;
}

const QK_GENERATORS = [ 'ai-assistant', 'blog-pilot', 'generators' ];
const QK_POSTS = [ 'ai-assistant', 'blog-pilot', 'posts' ];
const QK_STATS = [ 'ai-assistant', 'blog-pilot', 'stats' ];

const SCHEDULE_TYPES: { id: string; label: string }[] = [
	{ id: 'daily', label: __( 'Daily', 'ux-studio' ) },
	{ id: 'weekly', label: __( 'Weekly', 'ux-studio' ) },
	{ id: 'monthly', label: __( 'Monthly', 'ux-studio' ) },
];

function StatsPanel(): JSX.Element | null {
	const { data } = useQuery( {
		queryKey: QK_STATS,
		queryFn: () => api< Stats >( 'ai-assistant/blog-pilot/stats' ),
	} );

	if ( ! data ) {
		return null;
	}

	return (
		<p style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			{ __( 'Generators:', 'ux-studio' ) } { data.active_generators }/{ data.total_generators } { __( 'active', 'ux-studio' ) } { ' · ' }
			{ __( 'Posts generated:', 'ux-studio' ) } { data.total_posts } ({ data.posts_last_7_days } { __( 'in the last 7 days', 'ux-studio' ) }) { ' · ' }
			{ __( 'Tokens used:', 'ux-studio' ) } { data.total_tokens.toLocaleString() }
		</p>
	);
}

interface GeneratorFormState {
	title: string;
	topicsText: string;
	articleTypes: string[];
	provider: string;
	model: string;
	tone: string;
	length: string;
	language: string;
	postStatus: string;
	scheduleType: string;
	postsPerRun: number;
	timeFrom: string;
	timeTo: string;
}

const EMPTY_FORM: GeneratorFormState = {
	title: '',
	topicsText: '',
	articleTypes: [],
	provider: '',
	model: '',
	tone: 'professional',
	length: 'medium',
	language: 'cs',
	postStatus: 'draft',
	scheduleType: 'daily',
	postsPerRun: 1,
	timeFrom: '08:00',
	timeTo: '18:00',
};

function GeneratorForm( { editing, onDone }: { editing: Generator | null; onDone: () => void } ): JSX.Element {
	const { data: articleTypes } = useQuery( {
		queryKey: [ 'ai-assistant', 'blog-pilot', 'article-types' ],
		queryFn: () => api< Record< string, ArticleType > >( 'ai-assistant/blog-pilot/article-types' ),
	} );
	const { data: providers } = useQuery( {
		queryKey: [ 'ai-assistant', 'blog-pilot', 'providers' ],
		queryFn: () => api< Record< string, ProviderInfo > >( 'ai-assistant/blog-pilot/providers' ),
	} );

	const [ form, setForm ] = useState< GeneratorFormState >(
		editing
			? {
					title: editing.title,
					topicsText: editing.topics.join( '\n' ),
					articleTypes: editing.article_types,
					provider: editing.provider,
					model: editing.model,
					tone: editing.config.tone ?? 'professional',
					length: editing.config.length ?? 'medium',
					language: editing.config.language ?? 'cs',
					postStatus: editing.config.post_status ?? 'draft',
					scheduleType: editing.schedule_type,
					postsPerRun: editing.posts_per_run,
					timeFrom: editing.schedule_config.time_from ?? '08:00',
					timeTo: editing.schedule_config.time_to ?? '18:00',
			  }
			: EMPTY_FORM
	);

	const payload = () => ( {
		title: form.title,
		topics: form.topicsText.split( '\n' ).map( ( t ) => t.trim() ).filter( Boolean ),
		article_types: form.articleTypes,
		provider: form.provider,
		model: form.model,
		config: {
			tone: form.tone,
			length: form.length,
			language: form.language,
			post_status: form.postStatus,
		},
		schedule_type: form.scheduleType,
		schedule_config: { time_from: form.timeFrom, time_to: form.timeTo },
		posts_per_run: form.postsPerRun,
	} );

	const create = useMutation( {
		mutationFn: () => api( 'ai-assistant/blog-pilot/generators', { method: 'POST', body: JSON.stringify( payload() ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } );
			onDone();
		},
	} );

	const update = useMutation( {
		mutationFn: () =>
			api( `ai-assistant/blog-pilot/generators/${ editing?.id }`, { method: 'PUT', body: JSON.stringify( payload() ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } );
			onDone();
		},
	} );

	const saving = create.isPending || update.isPending;
	const canSubmit = form.title.trim() !== '' && form.topicsText.trim() !== '';
	const error = ( create.error ?? update.error ) as Error | null;

	const toggleArticleType = ( id: string ) => {
		setForm( ( f ) => ( {
			...f,
			articleTypes: f.articleTypes.includes( id ) ? f.articleTypes.filter( ( t ) => t !== id ) : [ ...f.articleTypes, id ],
		} ) );
	};

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-title">{ __( 'Generator name', 'ux-studio' ) }</label>
				<input id="uxs-bp-title" type="text" value={ form.title } onChange={ ( e ) => setForm( { ...form, title: e.target.value } ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-topics">{ __( 'Topics (one per line)', 'ux-studio' ) }</label>
				<textarea
					id="uxs-bp-topics"
					rows={ 4 }
					value={ form.topicsText }
					onChange={ ( e ) => setForm( { ...form, topicsText: e.target.value } ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Article types', 'ux-studio' ) }</label>
				<div style={ { display: 'flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-2)' } }>
					{ Object.entries( articleTypes ?? {} ).map( ( [ id, type ] ) => (
						<label key={ id } style={ { display: 'inline-flex', alignItems: 'center', gap: 4, fontWeight: 'normal' } }>
							<input type="checkbox" checked={ form.articleTypes.includes( id ) } onChange={ () => toggleArticleType( id ) } />
							{ type.name }
						</label>
					) ) }
				</div>
				<p className="uxs-form__help">{ __( 'Leave empty to rotate through every article type.', 'ux-studio' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-provider">{ __( 'AI provider', 'ux-studio' ) }</label>
				<select
					id="uxs-bp-provider"
					value={ form.provider }
					onChange={ ( e ) => setForm( { ...form, provider: e.target.value, model: '' } ) }
				>
					<option value="">{ __( 'Default provider', 'ux-studio' ) }</option>
					{ Object.entries( providers ?? {} ).map( ( [ id, p ] ) => (
						<option key={ id } value={ id }>
							{ p.label }
						</option>
					) ) }
				</select>
			</div>
			{ ( () => {
				const providerInfo = form.provider ? providers?.[ form.provider ] : undefined;
				if ( ! providerInfo ) {
					return null;
				}
				return (
					<div className="uxs-form__row">
						<label htmlFor="uxs-bp-model">{ __( 'Model', 'ux-studio' ) }</label>
						<select id="uxs-bp-model" value={ form.model } onChange={ ( e ) => setForm( { ...form, model: e.target.value } ) }>
							<option value="">{ __( 'Default model', 'ux-studio' ) }</option>
							{ Object.entries( providerInfo.models ).map( ( [ id, label ] ) => (
								<option key={ id } value={ id }>
									{ label }
								</option>
							) ) }
						</select>
					</div>
				);
			} )() }
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-tone">{ __( 'Tone', 'ux-studio' ) }</label>
				<input id="uxs-bp-tone" type="text" value={ form.tone } onChange={ ( e ) => setForm( { ...form, tone: e.target.value } ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-length">{ __( 'Length', 'ux-studio' ) }</label>
				<select id="uxs-bp-length" value={ form.length } onChange={ ( e ) => setForm( { ...form, length: e.target.value } ) }>
					<option value="short">{ __( 'Short', 'ux-studio' ) }</option>
					<option value="medium">{ __( 'Medium', 'ux-studio' ) }</option>
					<option value="long">{ __( 'Long', 'ux-studio' ) }</option>
					<option value="extra-long">{ __( 'Extra long', 'ux-studio' ) }</option>
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-post-status">{ __( 'Post status', 'ux-studio' ) }</label>
				<select id="uxs-bp-post-status" value={ form.postStatus } onChange={ ( e ) => setForm( { ...form, postStatus: e.target.value } ) }>
					<option value="draft">{ __( 'Draft', 'ux-studio' ) }</option>
					<option value="publish">{ __( 'Published', 'ux-studio' ) }</option>
					<option value="pending">{ __( 'Pending review', 'ux-studio' ) }</option>
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-schedule">{ __( 'Schedule', 'ux-studio' ) }</label>
				<select id="uxs-bp-schedule" value={ form.scheduleType } onChange={ ( e ) => setForm( { ...form, scheduleType: e.target.value } ) }>
					{ SCHEDULE_TYPES.map( ( s ) => (
						<option key={ s.id } value={ s.id }>
							{ s.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-window">{ __( 'Publish time window', 'ux-studio' ) }</label>
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'center' } }>
					<input id="uxs-bp-window" type="time" value={ form.timeFrom } onChange={ ( e ) => setForm( { ...form, timeFrom: e.target.value } ) } />
					<span>{ __( 'to', 'ux-studio' ) }</span>
					<input type="time" value={ form.timeTo } onChange={ ( e ) => setForm( { ...form, timeTo: e.target.value } ) } />
				</div>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-per-run">{ __( 'Posts per run', 'ux-studio' ) }</label>
				<input
					id="uxs-bp-per-run"
					type="number"
					min={ 1 }
					max={ 10 }
					value={ form.postsPerRun }
					onChange={ ( e ) => setForm( { ...form, postsPerRun: Math.max( 1, Math.min( 10, Number( e.target.value ) ) ) } ) }
				/>
			</div>

			<button
				type="button"
				className="button button-primary"
				disabled={ ! canSubmit || saving }
				onClick={ () => ( editing ? update.mutate() : create.mutate() ) }
			>
				{ saving ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { editing ? __( 'Save changes', 'ux-studio' ) : __( 'Create generator', 'ux-studio' ) }
			</button>{ ' ' }
			{ editing ? (
				<button type="button" className="button" onClick={ onDone }>
					{ __( 'Cancel', 'ux-studio' ) }
				</button>
			) : null }
			{ error ? <p className="uxs-form__help">{ error.message }</p> : null }
		</div>
	);
}

function GeneratorsSection(): JSX.Element {
	const [ formOpen, setFormOpen ] = useState( false );
	const [ editing, setEditing ] = useState< Generator | null >( null );
	const [ runResults, setRunResults ] = useState< Record< number, RunResult | string > >( {} );

	const { data, isLoading } = useQuery( {
		queryKey: QK_GENERATORS,
		queryFn: () => api< GeneratorsResponse >( 'ai-assistant/blog-pilot/generators?per_page=50' ),
	} );

	const toggle = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/blog-pilot/generators/${ id }/toggle`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/blog-pilot/generators/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } ),
	} );

	const run = useMutation( {
		mutationFn: ( id: number ) => api< RunResult >( `ai-assistant/blog-pilot/generators/${ id }/run`, { method: 'POST' } ),
		onSuccess: ( result, id ) => {
			setRunResults( ( r ) => ( { ...r, [ id ]: result } ) );
			void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } );
			void queryClient.invalidateQueries( { queryKey: QK_POSTS } );
			void queryClient.invalidateQueries( { queryKey: QK_STATS } );
		},
		onError: ( error: Error, id ) => setRunResults( ( r ) => ( { ...r, [ id ]: error.message } ) ),
	} );

	return (
		<div style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generators', 'ux-studio' ) }</h2>

			{ formOpen ? (
				<GeneratorForm editing={ null } onDone={ () => setFormOpen( false ) } />
			) : (
				<button type="button" className="button button-primary" style={ { marginBottom: 'var(--uxs-sp-4)' } } onClick={ () => setFormOpen( true ) }>
					<Plus size={ 14 } /> { __( 'New generator', 'ux-studio' ) }
				</button>
			) }

			{ editing ? <GeneratorForm editing={ editing } onDone={ () => setEditing( null ) } /> : null }

			{ isLoading || ! data ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : data.items.length === 0 ? (
				<p>{ __( 'No generators yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Schedule', 'ux-studio' ) }</th>
							<th>{ __( 'Posts', 'ux-studio' ) }</th>
							<th>{ __( 'Next run', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( g ) => (
							<tr key={ g.id }>
								<td>
									{ g.title }
									{ g.last_error ? <div className="uxs-form__help">{ g.last_error }</div> : null }
									{ runResults[ g.id ] ? (
										<div className="uxs-form__help">
											{ typeof runResults[ g.id ] === 'string' ? (
												runResults[ g.id ] as string
											) : (
												<a href={ ( runResults[ g.id ] as RunResult ).edit_url } target="_blank" rel="noreferrer">
													{ __( 'Generated:', 'ux-studio' ) } { ( runResults[ g.id ] as RunResult ).title }
												</a>
											) }
										</div>
									) : null }
								</td>
								<td>{ SCHEDULE_TYPES.find( ( s ) => s.id === g.schedule_type )?.label ?? g.schedule_type }</td>
								<td>{ g.total_posts }</td>
								<td>{ g.next_run ?? '—' }</td>
								<td>
									<span className={ `uxs-badge ${ g.status === 'active' ? 'is-success' : '' }` }>{ g.status }</span>
								</td>
								<td>
									<button type="button" className="button" disabled={ run.isPending } onClick={ () => run.mutate( g.id ) }>
										<Sparkles size={ 14 } /> { __( 'Run now', 'ux-studio' ) }
									</button>{ ' ' }
									<button type="button" className="button" onClick={ () => setEditing( g ) }>
										{ __( 'Edit', 'ux-studio' ) }
									</button>{ ' ' }
									<button type="button" className="button" disabled={ toggle.isPending } onClick={ () => toggle.mutate( g.id ) }>
										{ g.status === 'active' ? <Pause size={ 14 } /> : <Play size={ 14 } /> }{ ' ' }
										{ g.status === 'active' ? __( 'Pause', 'ux-studio' ) : __( 'Activate', 'ux-studio' ) }
									</button>{ ' ' }
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this generator?', 'ux-studio' ) ) ) {
												remove.mutate( g.id );
											}
										} }
									>
										<Trash2 size={ 14 } /> { __( 'Delete', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}

function GeneratedPostsSection(): JSX.Element {
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ bulkAction, setBulkAction ] = useState( 'draft' );

	const { data, isLoading } = useQuery( {
		queryKey: QK_POSTS,
		queryFn: () => api< GeneratedPostsResponse >( 'ai-assistant/blog-pilot/posts?per_page=50' ),
	} );

	const apply = useMutation( {
		mutationFn: () =>
			api( 'ai-assistant/blog-pilot/posts/bulk-action', {
				method: 'POST',
				body: JSON.stringify( { action: bulkAction, post_ids: selected } ),
			} ),
		onSuccess: () => {
			setSelected( [] );
			void queryClient.invalidateQueries( { queryKey: QK_POSTS } );
		},
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<div>
			<h2>{ __( 'Generated posts', 'ux-studio' ) }</h2>
			{ data.items.length === 0 ? (
				<p>{ __( 'No posts generated yet.', 'ux-studio' ) }</p>
			) : (
				<>
					<div className="uxs-form__row" style={ { alignItems: 'center' } }>
						<label htmlFor="uxs-bp-bulk-action">{ __( 'Bulk action', 'ux-studio' ) }</label>
						<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
							<select id="uxs-bp-bulk-action" value={ bulkAction } onChange={ ( e ) => setBulkAction( e.target.value ) }>
								<option value="draft">{ __( 'Set to draft', 'ux-studio' ) }</option>
								<option value="publish">{ __( 'Publish', 'ux-studio' ) }</option>
								<option value="trash">{ __( 'Move to trash', 'ux-studio' ) }</option>
							</select>
							<button type="button" className="button" disabled={ selected.length === 0 || apply.isPending } onClick={ () => apply.mutate() }>
								{ __( 'Apply', 'ux-studio' ) } ({ selected.length })
							</button>
						</div>
					</div>
					<table className="uxs-table">
						<thead>
							<tr>
								<th></th>
								<th>{ __( 'Title', 'ux-studio' ) }</th>
								<th>{ __( 'Generator', 'ux-studio' ) }</th>
								<th>{ __( 'Article type', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
								<th>{ __( 'Tokens', 'ux-studio' ) }</th>
								<th>{ __( 'Created', 'ux-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.items.map( ( p ) => (
								<tr key={ p.id }>
									<td>
										<input
											type="checkbox"
											checked={ selected.includes( p.post_id ) }
											onChange={ ( e ) =>
												setSelected( ( s ) => ( e.target.checked ? [ ...s, p.post_id ] : s.filter( ( id ) => id !== p.post_id ) ) )
											}
										/>
									</td>
									<td>
										<a href={ `/wp-admin/post.php?post=${ p.post_id }&action=edit` } target="_blank" rel="noreferrer">
											{ p.wp_title ?? p.topic }
										</a>
									</td>
									<td>{ p.generator_title }</td>
									<td>{ p.article_type }</td>
									<td>{ p.wp_status }</td>
									<td>{ p.tokens_used }</td>
									<td>{ p.created_at }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }
		</div>
	);
}

/**
 * Blog Pilot tab: scheduled AI article generator CRUD + generated-post
 * listing with bulk actions. Rendered inside the AI Assistant module page's
 * tab switch (see src/modules/ai-assistant/Page.tsx, wired in by the
 * orchestrator).
 */
export function BlogPilotTab(): JSX.Element {
	return (
		<>
			<StatsPanel />
			<GeneratorsSection />
			<GeneratedPostsSection />
		</>
	);
}
