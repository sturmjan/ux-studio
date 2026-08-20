import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Database, LoaderCircle, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface TrainingSource {
	id: number;
	type: string;
	title: string;
	source_url: string;
	chunk_count: number;
	vector_count: number;
	use_public: number;
	use_internal: number;
	status: string;
	error_message: string | null;
	last_crawled_at: string | null;
	created_at: string;
}

interface TrainingSourcesResponse {
	items: TrainingSource[];
	total: number;
	pages: number;
}

interface CountRow {
	source_type?: string;
	chat_target?: string;
	embedding_model?: string;
	count: number | string;
}

interface VectorStats {
	total_vectors: number;
	by_source_type: CountRow[];
	by_chat_target: CountRow[];
	by_model: CountRow[];
	total_sources: number;
	pending_sources: number;
}

interface EmbeddingStatus {
	provider: string;
	model: string;
	dimensions: number;
	available: boolean;
	has_vectors: boolean;
}

interface IndexStats {
	total: number;
	last_indexed: string | null;
}

interface RagStatsResponse {
	vectors: VectorStats;
	embedding: EmbeddingStatus;
	content: IndexStats;
	products: IndexStats;
}

interface QueueStatus {
	queued: number;
	total: number;
	done: number;
	running: boolean;
}

const QK_SOURCES = [ 'ai-assistant', 'training-sources' ];
const QK_STATS = [ 'ai-assistant', 'rag-stats' ];

type SourceType = 'url' | 'sitemap' | 'text';

function AddSourceForm(): JSX.Element {
	const [ type, setType ] = useState< SourceType >( 'url' );
	const [ title, setTitle ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ text, setText ] = useState( '' );
	const [ usePublic, setUsePublic ] = useState( true );
	const [ useInternal, setUseInternal ] = useState( false );

	const create = useMutation( {
		mutationFn: () =>
			api( 'ai-assistant/training-sources', {
				method: 'POST',
				body: JSON.stringify( { type, title, url, text, use_public: usePublic, use_internal: useInternal } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_SOURCES } );
			setTitle( '' );
			setUrl( '' );
			setText( '' );
		},
	} );

	const canSubmit = type === 'text' ? text.trim() !== '' : url.trim() !== '';

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-rag-source-type">{ __( 'Source type', 'ux-studio' ) }</label>
				<select id="uxs-rag-source-type" value={ type } onChange={ ( e ) => setType( e.target.value as SourceType ) }>
					<option value="url">{ __( 'Web page URL', 'ux-studio' ) }</option>
					<option value="sitemap">{ __( 'Sitemap URL', 'ux-studio' ) }</option>
					<option value="text">{ __( 'Raw text', 'ux-studio' ) }</option>
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-rag-source-title">{ __( 'Title (optional)', 'ux-studio' ) }</label>
				<input id="uxs-rag-source-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
			</div>
			{ type === 'text' ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-rag-source-text">{ __( 'Text', 'ux-studio' ) }</label>
					<textarea id="uxs-rag-source-text" rows={ 4 } value={ text } onChange={ ( e ) => setText( e.target.value ) } />
				</div>
			) : (
				<div className="uxs-form__row">
					<label htmlFor="uxs-rag-source-url">{ __( 'URL', 'ux-studio' ) }</label>
					<input id="uxs-rag-source-url" type="text" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } />
				</div>
			) }
			<div className="uxs-form__row">
				<label htmlFor="uxs-rag-source-public">{ __( 'Public chat', 'ux-studio' ) }</label>
				<button
					id="uxs-rag-source-public"
					type="button"
					className={ `uxs-switch${ usePublic ? ' is-on' : '' }` }
					aria-pressed={ usePublic }
					onClick={ () => setUsePublic( ( v ) => ! v ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-rag-source-internal">{ __( 'Internal chat', 'ux-studio' ) }</label>
				<button
					id="uxs-rag-source-internal"
					type="button"
					className={ `uxs-switch${ useInternal ? ' is-on' : '' }` }
					aria-pressed={ useInternal }
					onClick={ () => setUseInternal( ( v ) => ! v ) }
				/>
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit || create.isPending } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Add source', 'ux-studio' ) }
			</button>
		</div>
	);
}

function SourcesTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_SOURCES,
		queryFn: () => api< TrainingSourcesResponse >( 'ai-assistant/training-sources' ),
		refetchInterval: 10_000,
	} );

	const reindex = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/training-sources/${ id }/reindex`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_SOURCES } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/training-sources/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_SOURCES } ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( data.items.length === 0 ) {
		return <p>{ __( 'No training sources yet.', 'ux-studio' ) }</p>;
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Type', 'ux-studio' ) }</th>
					<th>{ __( 'Title / URL', 'ux-studio' ) }</th>
					<th>{ __( 'Vectors', 'ux-studio' ) }</th>
					<th>{ __( 'Status', 'ux-studio' ) }</th>
					<th>{ __( 'Actions', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.items.map( ( source ) => (
					<tr key={ source.id }>
						<td>{ source.type }</td>
						<td>
							{ source.title || source.source_url }
							{ source.error_message ? <div className="uxs-form__help">{ source.error_message }</div> : null }
						</td>
						<td>{ source.vector_count }</td>
						<td>
							<span
								className={ `uxs-badge ${
									source.status === 'completed' ? 'is-success' : source.status === 'error' ? 'is-danger' : ''
								}` }
							>
								{ source.status }
							</span>
						</td>
						<td>
							<button type="button" className="button" disabled={ reindex.isPending } onClick={ () => reindex.mutate( source.id ) }>
								<RefreshCw size={ 14 } /> { __( 'Reindex', 'ux-studio' ) }
							</button>{ ' ' }
							<button
								type="button"
								className="button"
								disabled={ remove.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete this source and its vectors?', 'ux-studio' ) ) ) {
										remove.mutate( source.id );
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
	);
}

function ReindexButton( {
	label,
	action,
	statusKey,
}: {
	label: string;
	action: string;
	statusKey: string;
} ): JSX.Element {
	const { data: status } = useQuery( {
		queryKey: [ 'ai-assistant', 'reindex-status', statusKey ],
		queryFn: () => api< QueueStatus >( `ai-assistant/${ statusKey }/status` ),
		refetchInterval: ( query ) => ( query.state.data?.running ? 3_000 : false ),
	} );

	const start = useMutation( {
		mutationFn: () => api( `ai-assistant/${ action }`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'ai-assistant', 'reindex-status', statusKey ] } ),
	} );

	return (
		<div className="uxs-form__row">
			<label>{ label }</label>
			<div>
				<button type="button" className="button" disabled={ start.isPending || !! status?.running } onClick={ () => start.mutate() }>
					{ start.isPending || status?.running ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Reindex', 'ux-studio' ) }
				</button>
				{ status ? (
					<p className="uxs-form__help">
						{ status.running
							? `${ __( 'Running…', 'ux-studio' ) } ${ status.done }/${ status.total }`
							: `${ __( 'Indexed items:', 'ux-studio' ) } ${ status.total }` }
					</p>
				) : null }
			</div>
		</div>
	);
}

function VectorizeExistingButton(): JSX.Element {
	const index = useMutation( {
		mutationFn: () =>
			api( 'ai-assistant/rag/index-existing', {
				method: 'POST',
				body: JSON.stringify( { types: [ 'knowledge', 'faq', 'product', 'content' ] } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_STATS } ),
	} );

	return (
		<div className="uxs-form__row">
			<label>{ __( 'Vectorize existing data', 'ux-studio' ) }</label>
			<div>
				<button type="button" className="button button-primary" disabled={ index.isPending } onClick={ () => index.mutate() }>
					{ index.isPending ? <LoaderCircle size={ 14 } /> : <Database size={ 14 } /> } { __( 'Index knowledge base, FAQ, products & content', 'ux-studio' ) }
				</button>
				<p className="uxs-form__help">
					{ __( 'Computes embeddings for the current knowledge base entries, FAQ, product index and content index.', 'ux-studio' ) }
				</p>
			</div>
		</div>
	);
}

function StatsPanel(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_STATS,
		queryFn: () => api< RagStatsResponse >( 'ai-assistant/rag/stats' ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<p>
				{ __( 'Embedding provider:', 'ux-studio' ) } <strong>{ data.embedding.provider }</strong> ({ data.embedding.model }, { data.embedding.dimensions }
				{ ' ' }
				{ __( 'dims', 'ux-studio' ) }) { ' · ' }
				{ __( 'Total vectors:', 'ux-studio' ) } { data.vectors.total_vectors } { ' · ' }
				{ __( 'Training sources:', 'ux-studio' ) } { data.vectors.total_sources } ({ data.vectors.pending_sources } { __( 'pending', 'ux-studio' ) })
			</p>
			{ data.vectors.by_source_type.length > 0 ? (
				<p className="uxs-form__help">
					{ data.vectors.by_source_type.map( ( row ) => `${ row.source_type }: ${ row.count }` ).join( ' · ' ) }
				</p>
			) : null }
		</>
	);
}

/**
 * RAG Training tab: vector-search stats, training source CRUD (web/sitemap/
 * text), and batch reindex triggers for products/content and existing data
 * vectorization. Rendered inside the AI Assistant module page's tab switch
 * (see src/modules/ai-assistant/Page.tsx).
 */
export function RagTrainingTab(): JSX.Element {
	return (
		<>
			<h2>{ __( 'RAG status', 'ux-studio' ) }</h2>
			<StatsPanel />

			<div className="uxs-form" style={ { margin: 'var(--uxs-sp-5) 0' } }>
				<ReindexButton label={ __( 'Products (WooCommerce)', 'ux-studio' ) } action="reindex-products" statusKey="reindex-products" />
				<ReindexButton label={ __( 'Content (posts/pages)', 'ux-studio' ) } action="reindex-content" statusKey="reindex-content" />
				<VectorizeExistingButton />
			</div>

			<h2>{ __( 'Training sources', 'ux-studio' ) }</h2>
			<AddSourceForm />
			<SourcesTable />
		</>
	);
}
