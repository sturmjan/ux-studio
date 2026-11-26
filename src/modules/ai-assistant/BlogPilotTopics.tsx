import { useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { LoaderCircle, RefreshCw, Sparkles } from 'lucide-react';
import { HelpText } from '../../components/GlossaryTerm';
import { api, queryClient } from '../../app/api';
import { QK_CALENDAR, QK_GENERATORS, QK_POSTS, QK_STATS, type ArticleType, type TopicProposal } from './blogPilotShared';

interface TopicDefaults {
	brief: string;
	language: string;
	existing_count: number;
}

interface SuggestResponse {
	proposals: TopicProposal[];
}

interface RegenerateResponse {
	proposal: TopicProposal;
}

interface RunResult {
	post_id: number;
	title: string;
	edit_url: string;
	status?: string;
	scheduled_for?: string | null;
	warnings?: string[];
}

interface Item extends TopicProposal {
	checked: boolean;
	replacing: boolean;
}

interface CreatedRow {
	topic: string;
	result?: RunResult;
	error?: string;
}

interface Props {
	/** Saved generator the posts are created with ("Create posts from selected"); null while it is a new one. */
	generatorId: number | null;
	language: string;
	tone: string;
	articleTypes: string[];
	provider: string;
	model: string;
	/** Topics currently in the form (sent so the AI does not repeat them). */
	existingTopics: string[];
	typeNames: Record< string, ArticleType > | undefined;
	onAdd: ( items: TopicProposal[] ) => void;
}

/**
 * "Suggest topics": a brief goes to the AI together with the titles that already exist; the user ticks, edits or
 * replaces the proposals and either adds them to the generator's topics or writes the posts right away.
 */
export function TopicSuggestions( { generatorId, language, tone, articleTypes, provider, model, existingTopics, typeNames, onAdd }: Props ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ brief, setBrief ] = useState( '' );
	const [ count, setCount ] = useState( 5 );
	const [ items, setItems ] = useState< Item[] >( [] );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ creating, setCreating ] = useState< { done: number; total: number } | null >( null );
	const [ created, setCreated ] = useState< CreatedRow[] >( [] );

	const { data: defaults } = useQuery( {
		queryKey: [ 'ai-assistant', 'blog-pilot', 'topic-defaults' ],
		queryFn: () => api< TopicDefaults >( 'ai-assistant/blog-pilot/topics/defaults' ),
		enabled: open,
	} );

	useEffect( () => {
		if ( defaults && brief === '' ) {
			setBrief( defaults.brief );
		}
		// Only the first load fills the brief; later edits belong to the user.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ defaults ] );

	const payload = () => ( {
		brief,
		count,
		language,
		tone,
		types: articleTypes,
		provider,
		model,
		existing_topics: existingTopics,
	} );

	const suggest = async () => {
		setBusy( true );
		setError( '' );
		setCreated( [] );
		try {
			const res = await api< SuggestResponse >( 'ai-assistant/blog-pilot/topics/suggest', { method: 'POST', body: JSON.stringify( payload() ) } );
			setItems( res.proposals.map( ( p ) => ( { ...p, checked: true, replacing: false } ) ) );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
		} finally {
			setBusy( false );
		}
	};

	const replace = async ( id: string ) => {
		const target = items.find( ( i ) => i.id === id );
		if ( ! target ) {
			return;
		}
		setItems( ( list ) => list.map( ( i ) => ( i.id === id ? { ...i, replacing: true } : i ) ) );
		setError( '' );
		try {
			const res = await api< RegenerateResponse >( 'ai-assistant/blog-pilot/topics/regenerate-one', {
				method: 'POST',
				body: JSON.stringify( { ...payload(), rejected: strip( target ), current: items.map( strip ) } ),
			} );
			setItems( ( list ) => list.map( ( i ) => ( i.id === id ? { ...res.proposal, checked: true, replacing: false } : i ) ) );
		} catch ( e ) {
			setError( e instanceof Error ? e.message : String( e ) );
			setItems( ( list ) => list.map( ( i ) => ( i.id === id ? { ...i, replacing: false } : i ) ) );
		}
	};

	const selected = items.filter( ( i ) => i.checked && i.title.trim() !== '' );

	const createPosts = async () => {
		if ( ! generatorId || selected.length === 0 ) {
			return;
		}
		setCreating( { done: 0, total: selected.length } );
		setCreated( [] );
		setError( '' );
		const rows: CreatedRow[] = [];
		for ( const item of selected ) {
			try {
				const result = await api< RunResult >( `ai-assistant/blog-pilot/generators/${ generatorId }/run`, {
					method: 'POST',
					body: JSON.stringify( { topic: item.title.trim(), article_type: item.article_type, keyword: item.focus_keyword, summary: item.summary } ),
				} );
				rows.push( { topic: item.title, result } );
			} catch ( e ) {
				rows.push( { topic: item.title, error: e instanceof Error ? e.message : String( e ) } );
				setCreated( [ ...rows ] );
				break; // A failure (limit, key) would repeat for the rest: stop and show it.
			}
			setCreated( [ ...rows ] );
			setCreating( { done: rows.length, total: selected.length } );
		}
		setCreating( null );
		void queryClient.invalidateQueries( { queryKey: QK_POSTS } );
		void queryClient.invalidateQueries( { queryKey: QK_STATS } );
		void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } );
		void queryClient.invalidateQueries( { queryKey: QK_CALENDAR } );
	};

	if ( ! open ) {
		return (
			<div className="uxs-form__row">
				<button type="button" className="button" onClick={ () => setOpen( true ) }>
					<Sparkles size={ 14 } /> { __( 'Suggest topics', 'ux-studio' ) }
				</button>
				<p className="uxs-form__help">{ __( 'Let the AI propose article topics that are not on your site yet.', 'ux-studio' ) }</p>
			</div>
		);
	}

	return (
		<div className="uxs-bp-panel">
			<h3>{ __( 'Suggest topics', 'ux-studio' ) }</h3>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-brief">{ __( 'Brief', 'ux-studio' ) }</label>
				<textarea id="uxs-bp-brief" rows={ 3 } value={ brief } onChange={ ( e ) => setBrief( e.target.value ) } />
				<p className="uxs-form__help">
					<HelpText text={ __( 'What the blog is about and who reads it. It starts from the name and tagline of your site; titles that already exist on the site and in this generator are excluded automatically.', 'ux-studio' ) } />
				</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-bp-topic-count">{ __( 'How many topics', 'ux-studio' ) }</label>
				<div className="uxs-bp-inline">
					<input
						id="uxs-bp-topic-count"
						type="number"
						min={ 1 }
						max={ 20 }
						value={ count }
						onChange={ ( e ) => setCount( Math.max( 1, Math.min( 20, Number( e.target.value ) || 1 ) ) ) }
					/>
					<button type="button" className="button button-primary" disabled={ busy } onClick={ () => void suggest() }>
						{ busy ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { items.length > 0 ? __( 'Suggest again', 'ux-studio' ) : __( 'Suggest topics', 'ux-studio' ) }
					</button>
					<button type="button" className="button" onClick={ () => setOpen( false ) }>
						{ __( 'Close', 'ux-studio' ) }
					</button>
				</div>
			</div>
			{ error ? <p className="uxs-form__help is-error" role="alert">{ error }</p> : null }

			{ items.length > 0 ? (
				<>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th></th>
									<th>{ __( 'Title', 'ux-studio' ) }</th>
									<th>{ __( 'Summary', 'ux-studio' ) }</th>
									<th>{ __( 'Keyword', 'ux-studio' ) }</th>
									<th>{ __( 'Article type', 'ux-studio' ) }</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<td>
											<input
												type="checkbox"
												aria-label={ __( 'Select this topic', 'ux-studio' ) }
												checked={ item.checked }
												onChange={ ( e ) => setItems( ( list ) => list.map( ( i ) => ( i.id === item.id ? { ...i, checked: e.target.checked } : i ) ) ) }
											/>
										</td>
										<td>
											<input
												type="text"
												aria-label={ __( 'Title', 'ux-studio' ) }
												value={ item.title }
												onChange={ ( e ) => setItems( ( list ) => list.map( ( i ) => ( i.id === item.id ? { ...i, title: e.target.value } : i ) ) ) }
											/>
										</td>
										<td>{ item.summary }</td>
										<td>{ item.focus_keyword }</td>
										<td>{ typeNames?.[ item.article_type ]?.name ?? item.article_type }</td>
										<td>
											<button type="button" className="button" disabled={ item.replacing || busy } onClick={ () => void replace( item.id ) }>
												{ item.replacing ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Replace', 'ux-studio' ) }
											</button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<div className="uxs-bp-inline" style={ { marginTop: 'var(--uxs-sp-3)' } }>
						<button type="button" className="button button-primary" disabled={ selected.length === 0 } onClick={ () => onAdd( selected.map( strip ) ) }>
							{ __( 'Add to generator topics', 'ux-studio' ) } ({ selected.length })
						</button>
						<button type="button" className="button" disabled={ ! generatorId || selected.length === 0 || creating !== null } onClick={ () => void createPosts() }>
							{ creating ? <LoaderCircle size={ 14 } /> : null } { __( 'Create posts from selected', 'ux-studio' ) }
						</button>
					</div>
					<p className="uxs-form__help">
						{ generatorId
							? __( 'Posts are written one after another with the saved settings of this generator (status, tone, length, publish plan); every post uses AI tokens.', 'ux-studio' )
							: __( 'Save the generator first to write posts straight from the proposals.', 'ux-studio' ) }
					</p>
				</>
			) : null }

			{ creating ? (
				<p role="status">
					{ sprintf(
						/* translators: 1: posts written so far, 2: total number of posts */
						__( 'Writing posts… %1$d of %2$d', 'ux-studio' ),
						creating.done,
						creating.total
					) }
				</p>
			) : null }
			{ created.length > 0 ? (
				<ul className="uxs-bp-results">
					{ created.map( ( row, index ) => (
						<li key={ index }>
							{ row.error ? (
								<span className="is-error">{ row.topic }: { row.error }</span>
							) : (
								<>
									<a href={ row.result?.edit_url } target="_blank" rel="noreferrer">
										{ row.result?.title }
									</a>
									{ row.result?.scheduled_for ? ` - ${ sprintf( /* translators: %s: date and time */ __( 'scheduled for %s', 'ux-studio' ), row.result.scheduled_for.slice( 0, 16 ) ) }` : '' }
									{ row.result?.warnings?.includes( 'too_short' ) ? ` - ${ __( 'shorter than requested', 'ux-studio' ) }` : '' }
								</>
							) }
						</li>
					) ) }
				</ul>
			) : null }
			{ items.length > 0 && created.length === 0 && ! creating ? (
				<p className="uxs-form__help">
					{ sprintf(
						/* translators: %d: number of proposals */
						_n( '%d proposal. Edit a title, untick what you do not want, or replace a topic with a new one.', '%d proposals. Edit a title, untick what you do not want, or replace a topic with a new one.', items.length, 'ux-studio' ),
						items.length
					) }
				</p>
			) : null }
		</div>
	);
}

/** Proposal without the UI-only flags (what the API understands). */
function strip( item: Item | TopicProposal ): TopicProposal {
	return {
		id: item.id,
		title: item.title,
		summary: item.summary,
		focus_keyword: item.focus_keyword,
		article_type: item.article_type,
		variants: item.variants,
		category: item.category,
	};
}
