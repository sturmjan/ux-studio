import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Sparkles, Trash2, Upload } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface KnowledgeDocument {
	id: number;
	title: string;
	file_name: string;
	file_type: string;
	file_size: number;
	chunk_count: number;
	use_public: number;
	use_internal: number;
	status: string;
	error_message: string | null;
	created_at: string;
}

interface ManualEntry {
	id: number;
	title: string;
	content: string;
	use_public: number;
	use_internal: number;
	created_at: string;
}

interface KnowledgeResponse {
	documents: KnowledgeDocument[];
	manual_entries: ManualEntry[];
}

interface Faq {
	id: number;
	question: string;
	answer: string;
	category: string;
	sort_order: number;
	is_active: number;
	use_public: number;
	use_internal: number;
	views: number;
}

interface FaqsResponse {
	items: Faq[];
	categories: string[];
}

interface FaqSuggestion {
	question: string;
	answer: string;
	category: string;
}

interface FaqSuggestResponse {
	success: boolean;
	suggestions?: FaqSuggestion[];
	analyzed?: number;
	error?: string;
}

const QK_KNOWLEDGE = [ 'ai-assistant', 'knowledge' ];
const QK_FAQS = [ 'ai-assistant', 'faqs' ];

function TargetBadges( { usePublic, useInternal }: { usePublic: number; useInternal: number } ): JSX.Element {
	return (
		<>
			{ usePublic ? <span className="uxs-badge is-success">{ __( 'Public', 'ux-studio' ) }</span> : null }{ ' ' }
			{ useInternal ? <span className="uxs-badge">{ __( 'Internal', 'ux-studio' ) }</span> : null }
		</>
	);
}

function UploadDocumentForm(): JSX.Element {
	const [ file, setFile ] = useState< File | null >( null );
	const [ usePublic, setUsePublic ] = useState( true );
	const [ useInternal, setUseInternal ] = useState( false );
	const [ error, setError ] = useState( '' );

	const upload = useMutation( {
		mutationFn: async () => {
			if ( ! file ) {
				throw new Error( __( 'Choose a file first.', 'ux-studio' ) );
			}
			const boot = window.uxStudioBoot;
			const form = new FormData();
			form.append( 'file', file );
			form.append( 'use_public', usePublic ? '1' : '0' );
			form.append( 'use_internal', useInternal ? '1' : '0' );

			const res = await fetch( `${ boot.restUrl }/ai-assistant/knowledge/upload`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': boot.nonce },
				body: form,
			} );
			const json = await res.json();
			if ( ! res.ok ) {
				throw new Error( json.message ?? `HTTP ${ res.status }` );
			}
			return json.data;
		},
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_KNOWLEDGE } );
			setFile( null );
			setError( '' );
		},
		onError: ( e: Error ) => setError( e.message ),
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-kb-file">{ __( 'Document (PDF, DOCX, TXT, MD)', 'ux-studio' ) }</label>
				<input
					id="uxs-kb-file"
					type="file"
					accept=".pdf,.docx,.txt,.md"
					onChange={ ( e ) => setFile( e.target.files?.[ 0 ] ?? null ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-kb-doc-public">{ __( 'Public chat', 'ux-studio' ) }</label>
				<button
					id="uxs-kb-doc-public"
					type="button"
					className={ `uxs-switch${ usePublic ? ' is-on' : '' }` }
					aria-pressed={ usePublic }
					onClick={ () => setUsePublic( ( v ) => ! v ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-kb-doc-internal">{ __( 'Internal chat', 'ux-studio' ) }</label>
				<button
					id="uxs-kb-doc-internal"
					type="button"
					className={ `uxs-switch${ useInternal ? ' is-on' : '' }` }
					aria-pressed={ useInternal }
					onClick={ () => setUseInternal( ( v ) => ! v ) }
				/>
			</div>
			<button type="button" className="button button-primary" disabled={ ! file || upload.isPending } onClick={ () => upload.mutate() }>
				{ upload.isPending ? <LoaderCircle size={ 14 } /> : <Upload size={ 14 } /> } { __( 'Upload document', 'ux-studio' ) }
			</button>
			{ error ? <p className="uxs-form__help">{ error }</p> : null }
		</div>
	);
}

function AddManualEntryForm(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ content, setContent ] = useState( '' );

	const create = useMutation( {
		mutationFn: () =>
			api( 'ai-assistant/knowledge', { method: 'POST', body: JSON.stringify( { title, content, use_public: true, use_internal: false } ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_KNOWLEDGE } );
			setTitle( '' );
			setContent( '' );
		},
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-kb-entry-title">{ __( 'Title', 'ux-studio' ) }</label>
				<input id="uxs-kb-entry-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-kb-entry-content">{ __( 'Content', 'ux-studio' ) }</label>
				<textarea id="uxs-kb-entry-content" rows={ 4 } value={ content } onChange={ ( e ) => setContent( e.target.value ) } />
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ ! title.trim() || ! content.trim() || create.isPending }
				onClick={ () => create.mutate() }
			>
				{ __( 'Add entry', 'ux-studio' ) }
			</button>
		</div>
	);
}

function KnowledgeSection(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_KNOWLEDGE,
		queryFn: () => api< KnowledgeResponse >( 'ai-assistant/knowledge' ),
	} );

	const deleteDocument = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/knowledge/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_KNOWLEDGE } ),
	} );

	const deleteEntry = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/knowledge/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_KNOWLEDGE } ),
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
			<h2>{ __( 'Documents', 'ux-studio' ) }</h2>
			<UploadDocumentForm />
			{ data.documents.length === 0 ? (
				<p>{ __( 'No documents uploaded yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Chunks', 'ux-studio' ) }</th>
							<th>{ __( 'Targets', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.documents.map( ( doc ) => (
							<tr key={ doc.id }>
								<td>{ doc.title }</td>
								<td>{ doc.file_type.toUpperCase() }</td>
								<td>{ doc.chunk_count }</td>
								<td>
									<TargetBadges usePublic={ doc.use_public } useInternal={ doc.use_internal } />
								</td>
								<td>
									<span className={ `uxs-badge ${ doc.status === 'ready' ? 'is-success' : doc.status === 'error' ? 'is-danger' : '' }` }>
										{ doc.status }
									</span>
									{ doc.error_message ? <div className="uxs-form__help">{ doc.error_message }</div> : null }
								</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ deleteDocument.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this document and its knowledge chunks?', 'ux-studio' ) ) ) {
												deleteDocument.mutate( doc.id );
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

			<h2>{ __( 'Manual entries', 'ux-studio' ) }</h2>
			<AddManualEntryForm />
			{ data.manual_entries.length === 0 ? (
				<p>{ __( 'No manual entries yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Targets', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.manual_entries.map( ( entry ) => (
							<tr key={ entry.id }>
								<td>{ entry.title || entry.content.slice( 0, 60 ) }</td>
								<td>
									<TargetBadges usePublic={ entry.use_public } useInternal={ entry.use_internal } />
								</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ deleteEntry.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this entry?', 'ux-studio' ) ) ) {
												deleteEntry.mutate( entry.id );
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
		</>
	);
}

function AddFaqForm(): JSX.Element {
	const [ question, setQuestion ] = useState( '' );
	const [ answer, setAnswer ] = useState( '' );
	const [ category, setCategory ] = useState( '' );

	const create = useMutation( {
		mutationFn: () => api( 'ai-assistant/faqs/admin', { method: 'POST', body: JSON.stringify( { question, answer, category } ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: QK_FAQS } );
			setQuestion( '' );
			setAnswer( '' );
			setCategory( '' );
		},
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-faq-question">{ __( 'Question', 'ux-studio' ) }</label>
				<input id="uxs-faq-question" type="text" value={ question } onChange={ ( e ) => setQuestion( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-faq-answer">{ __( 'Answer', 'ux-studio' ) }</label>
				<textarea id="uxs-faq-answer" rows={ 3 } value={ answer } onChange={ ( e ) => setAnswer( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-faq-category">{ __( 'Category', 'ux-studio' ) }</label>
				<input id="uxs-faq-category" type="text" value={ category } onChange={ ( e ) => setCategory( e.target.value ) } />
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ ! question.trim() || ! answer.trim() || create.isPending }
				onClick={ () => create.mutate() }
			>
				{ __( 'Add FAQ', 'ux-studio' ) }
			</button>
		</div>
	);
}

function FaqSuggestions(): JSX.Element {
	const [ topic, setTopic ] = useState( '' );

	const analyze = useMutation( {
		mutationFn: () => api< FaqSuggestResponse >( 'ai-assistant/faqs/analyze', { method: 'POST', body: JSON.stringify( {} ) } ),
	} );

	const generate = useMutation( {
		mutationFn: () => api< FaqSuggestResponse >( 'ai-assistant/faqs/generate', { method: 'POST', body: JSON.stringify( { topic } ) } ),
	} );

	const accept = useMutation( {
		mutationFn: ( suggestion: FaqSuggestion ) =>
			api( 'ai-assistant/faqs/admin', { method: 'POST', body: JSON.stringify( suggestion ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_FAQS } ),
	} );

	const result = analyze.data ?? generate.data;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-faq-topic">{ __( 'Generate from topic (optional)', 'ux-studio' ) }</label>
				<input
					id="uxs-faq-topic"
					type="text"
					placeholder={ __( 'e.g. cancellation policy', 'ux-studio' ) }
					value={ topic }
					onChange={ ( e ) => setTopic( e.target.value ) }
				/>
			</div>
			<button type="button" className="button" disabled={ analyze.isPending } onClick={ () => analyze.mutate() }>
				{ analyze.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Suggest from past chats', 'ux-studio' ) }
			</button>{ ' ' }
			<button type="button" className="button" disabled={ ! topic.trim() || generate.isPending } onClick={ () => generate.mutate() }>
				{ generate.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Suggest from topic', 'ux-studio' ) }
			</button>

			{ result && ! result.success ? <p className="uxs-form__help">{ result.error }</p> : null }

			{ result?.success && result.suggestions && result.suggestions.length > 0 ? (
				<table className="uxs-table" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<thead>
						<tr>
							<th>{ __( 'Question', 'ux-studio' ) }</th>
							<th>{ __( 'Answer', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ result.suggestions.map( ( s, i ) => (
							<tr key={ i }>
								<td>{ s.question }</td>
								<td>{ s.answer }</td>
								<td>{ s.category }</td>
								<td>
									<button type="button" className="button" disabled={ accept.isPending } onClick={ () => accept.mutate( s ) }>
										{ __( 'Add', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
			{ result?.success && result.suggestions && result.suggestions.length === 0 ? (
				<p>{ __( 'No new suggestions.', 'ux-studio' ) }</p>
			) : null }
		</div>
	);
}

function FaqSection(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: QK_FAQS,
		queryFn: () => api< FaqsResponse >( 'ai-assistant/faqs/admin' ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `ai-assistant/faqs/admin/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_FAQS } ),
	} );

	const toggleActive = useMutation( {
		mutationFn: ( faq: Faq ) =>
			api( `ai-assistant/faqs/admin/${ faq.id }`, { method: 'POST', body: JSON.stringify( { is_active: faq.is_active ? 0 : 1 } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QK_FAQS } ),
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
			<h2>{ __( 'FAQ', 'ux-studio' ) }</h2>
			<FaqSuggestions />
			<AddFaqForm />
			{ data.items.length === 0 ? (
				<p>{ __( 'No FAQs yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Question', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th>{ __( 'Targets', 'ux-studio' ) }</th>
							<th>{ __( 'Active', 'ux-studio' ) }</th>
							<th>{ __( 'Views', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( faq ) => (
							<tr key={ faq.id }>
								<td>{ faq.question }</td>
								<td>{ faq.category }</td>
								<td>
									<TargetBadges usePublic={ faq.use_public } useInternal={ faq.use_internal } />
								</td>
								<td>
									<button
										type="button"
										className={ `uxs-switch${ faq.is_active ? ' is-on' : '' }` }
										aria-pressed={ !! faq.is_active }
										disabled={ toggleActive.isPending }
										onClick={ () => toggleActive.mutate( faq ) }
									/>
								</td>
								<td>{ faq.views }</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this FAQ?', 'ux-studio' ) ) ) {
												remove.mutate( faq.id );
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
		</>
	);
}

/**
 * Knowledge Hub tab: document upload + manual knowledge entries + FAQ CRUD
 * (with AI-assisted FAQ suggestions). Rendered inside the AI Assistant
 * module page's tab switch (see src/modules/ai-assistant/Page.tsx).
 */
export function KnowledgeHubTab(): JSX.Element {
	return (
		<>
			<KnowledgeSection />
			<FaqSection />
		</>
	);
}
