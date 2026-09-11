import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { FileText, LoaderCircle, Sparkles, Upload } from 'lucide-react';
import { api } from '../../app/api';

interface UsageInfo {
	input_tokens: number;
	output_tokens: number;
}

interface GeneratedContent {
	title?: string;
	content?: string;
	excerpt?: string;
	category?: string;
	tags?: string[];
	_usage?: UsageInfo;
	_provider?: string;
	_model?: string;
}

interface GeneratedWoo {
	title?: string;
	content?: string;
	short_description?: string;
	_usage?: UsageInfo;
}

interface GeneratedSeo {
	seo_title?: string;
	seo_description?: string;
	seo_keywords?: string;
	seo_plugin_detected?: string;
	_usage?: UsageInfo;
}

interface BulkSeoStatus {
	post_type: string;
	missing_count: number;
	seo_plugin: string;
}

interface BulkSeoRunResult {
	processed: Array< { post_id: number; title: string; seo_title?: string; seo_description?: string; error?: string } >;
	remaining: number;
	seo_plugin: string;
}

interface GeneratedSocial {
	facebook?: string;
	instagram?: string;
	x?: string;
	_usage?: UsageInfo;
}

interface DraftResponse {
	post_id: number;
	edit_url: string;
}

interface PublishResponse {
	post_id: number;
	url: string;
}

interface ElementorImportResponse {
	post_id: number;
	title: string;
	widgets_created: number;
	edit_url: string;
	url: string;
	source_url?: string;
	meta_description?: string;
}

const POST_TYPES: { id: string; label: string }[] = [
	{ id: 'post', label: __( 'Post', 'ux-studio' ) },
	{ id: 'page', label: __( 'Page', 'ux-studio' ) },
];

const TONES: { id: string; label: string }[] = [
	{ id: 'neutral', label: __( 'Neutral', 'ux-studio' ) },
	{ id: 'professional', label: __( 'Professional', 'ux-studio' ) },
	{ id: 'friendly', label: __( 'Friendly', 'ux-studio' ) },
	{ id: 'persuasive', label: __( 'Persuasive', 'ux-studio' ) },
	{ id: 'enthusiastic', label: __( 'Enthusiastic', 'ux-studio' ) },
];

const LENGTHS: { id: string; label: string }[] = [
	{ id: 'short', label: __( 'Short', 'ux-studio' ) },
	{ id: 'medium', label: __( 'Medium', 'ux-studio' ) },
	{ id: 'long', label: __( 'Long', 'ux-studio' ) },
];

/**
 * Post/page generator: description + tone/length/keywords -> AI draft, with
 * Create draft / Publish actions. Mirrors the legacy backend-content-creator
 * jQuery view plus the WooCommerce product generator that used to live in
 * assets/js/ai-assistant-woo.js (folded in here as a second sub-form instead
 * of a separate product-edit screen script).
 */
function PostGeneratorSection(): JSX.Element {
	const [ postType, setPostType ] = useState( 'post' );
	const [ tone, setTone ] = useState( 'neutral' );
	const [ length, setLength ] = useState( 'medium' );
	const [ description, setDescription ] = useState( '' );
	const [ focusKeyword, setFocusKeyword ] = useState( '' );
	const [ result, setResult ] = useState< GeneratedContent | null >( null );
	const [ draft, setDraft ] = useState< DraftResponse | null >( null );
	const [ published, setPublished ] = useState< PublishResponse | null >( null );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedContent >( 'ai-assistant/content/generate', {
				method: 'POST',
				body: JSON.stringify( { post_type: postType, tone, length, description, focus_keyword: focusKeyword } ),
			} ),
		onSuccess: ( data ) => {
			setResult( data );
			setDraft( null );
			setPublished( null );
		},
	} );

	const createDraft = useMutation( {
		mutationFn: () =>
			api< DraftResponse >( 'ai-assistant/content/create-draft', {
				method: 'POST',
				body: JSON.stringify( { ...result, post_type: postType } ),
			} ),
		onSuccess: ( data ) => setDraft( data ),
	} );

	const publish = useMutation( {
		mutationFn: () =>
			api< PublishResponse >( `ai-assistant/content/publish/${ draft?.post_id }`, { method: 'POST' } ),
		onSuccess: ( data ) => setPublished( data ),
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generate a post/page', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-post-type">{ __( 'Content type', 'ux-studio' ) }</label>
				<select id="uxs-cc-post-type" value={ postType } onChange={ ( e ) => setPostType( e.target.value ) }>
					{ POST_TYPES.map( ( t ) => (
						<option key={ t.id } value={ t.id }>
							{ t.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-tone">{ __( 'Tone', 'ux-studio' ) }</label>
				<select id="uxs-cc-tone" value={ tone } onChange={ ( e ) => setTone( e.target.value ) }>
					{ TONES.map( ( t ) => (
						<option key={ t.id } value={ t.id }>
							{ t.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-length">{ __( 'Length', 'ux-studio' ) }</label>
				<select id="uxs-cc-length" value={ length } onChange={ ( e ) => setLength( e.target.value ) }>
					{ LENGTHS.map( ( l ) => (
						<option key={ l.id } value={ l.id }>
							{ l.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-description">{ __( 'Description', 'ux-studio' ) }</label>
				<textarea
					id="uxs-cc-description"
					rows={ 3 }
					value={ description }
					onChange={ ( e ) => setDescription( e.target.value ) }
					placeholder={ __( 'What should this content be about?', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-keyword">{ __( 'SEO focus keyword (optional)', 'ux-studio' ) }</label>
				<input id="uxs-cc-keyword" type="text" value={ focusKeyword } onChange={ ( e ) => setFocusKeyword( e.target.value ) } />
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ description.trim().length < 10 || generate.isPending }
				onClick={ () => generate.mutate() }
			>
				{ generate.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Generate', 'ux-studio' ) }
			</button>
			{ generate.isError ? <p className="uxs-form__help">{ ( generate.error as Error ).message }</p> : null }

			{ result ? (
				<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<h3>{ result.title }</h3>
					{ result.category ? (
						<p className="uxs-form__help">
							{ __( 'Category:', 'ux-studio' ) } { result.category }
							{ result.tags && result.tags.length > 0 ? ` · ${ __( 'Tags:', 'ux-studio' ) } ${ result.tags.join( ', ' ) }` : '' }
						</p>
					) : null }
					{ result.excerpt ? <p className="uxs-form__help">{ result.excerpt }</p> : null }
					<div
						className="uxs-form__help"
						style={ { border: '1px solid var(--uxs-border, #e2e2e2)', borderRadius: 4, padding: 'var(--uxs-sp-3)', maxHeight: 300, overflow: 'auto' } }
						// eslint-disable-next-line react/no-danger -- AI-generated HTML preview, sanitised server-side via wp_kses_post() on save.
						dangerouslySetInnerHTML={ { __html: result.content ?? '' } }
					/>

					{ ! draft ? (
						<button type="button" className="button" disabled={ createDraft.isPending } onClick={ () => createDraft.mutate() } style={ { marginTop: 'var(--uxs-sp-3)' } }>
							{ createDraft.isPending ? <LoaderCircle size={ 14 } /> : <FileText size={ 14 } /> } { __( 'Create draft', 'ux-studio' ) }
						</button>
					) : (
						<p style={ { marginTop: 'var(--uxs-sp-3)' } }>
							<a href={ draft.edit_url } target="_blank" rel="noreferrer">
								{ __( 'Edit draft', 'ux-studio' ) }
							</a>
							{ ! published ? (
								<>
									{ ' · ' }
									<button type="button" className="button button-primary" disabled={ publish.isPending } onClick={ () => publish.mutate() }>
										{ publish.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Publish', 'ux-studio' ) }
									</button>
								</>
							) : (
								<>
									{ ' · ' }
									<a href={ published.url } target="_blank" rel="noreferrer">
										{ __( 'View published post', 'ux-studio' ) }
									</a>
								</>
							) }
						</p>
					) }
				</div>
			) : null }
		</div>
	);
}

/**
 * WooCommerce product description generator - the React equivalent of the
 * legacy assets/js/ai-assistant-woo.js product-edit screen script, offered
 * here as a standalone generator (copy the result into the product editor).
 */
function WooGeneratorSection(): JSX.Element {
	const [ tone, setTone ] = useState( 'neutral' );
	const [ length, setLength ] = useState( 'medium' );
	const [ description, setDescription ] = useState( '' );
	const [ result, setResult ] = useState< GeneratedWoo | null >( null );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedWoo >( 'ai-assistant/content/generate-woo', {
				method: 'POST',
				body: JSON.stringify( { tone, length, description } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generate a WooCommerce product description', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-woo-tone">{ __( 'Tone', 'ux-studio' ) }</label>
				<select id="uxs-cc-woo-tone" value={ tone } onChange={ ( e ) => setTone( e.target.value ) }>
					{ TONES.map( ( t ) => (
						<option key={ t.id } value={ t.id }>
							{ t.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-woo-length">{ __( 'Length', 'ux-studio' ) }</label>
				<select id="uxs-cc-woo-length" value={ length } onChange={ ( e ) => setLength( e.target.value ) }>
					{ LENGTHS.map( ( l ) => (
						<option key={ l.id } value={ l.id }>
							{ l.label }
						</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-woo-description">{ __( 'Product description (rough input)', 'ux-studio' ) }</label>
				<textarea id="uxs-cc-woo-description" rows={ 3 } value={ description } onChange={ ( e ) => setDescription( e.target.value ) } />
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ description.trim().length < 10 || generate.isPending }
				onClick={ () => generate.mutate() }
			>
				{ generate.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Generate', 'ux-studio' ) }
			</button>
			{ generate.isError ? <p className="uxs-form__help">{ ( generate.error as Error ).message }</p> : null }

			{ result ? (
				<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<h3>{ result.title }</h3>
					{ result.short_description ? <p className="uxs-form__help">{ result.short_description }</p> : null }
					<div
						className="uxs-form__help"
						style={ { border: '1px solid var(--uxs-border, #e2e2e2)', borderRadius: 4, padding: 'var(--uxs-sp-3)', maxHeight: 300, overflow: 'auto' } }
						// eslint-disable-next-line react/no-danger -- AI-generated HTML preview, copy target is the WooCommerce editor which sanitises on save.
						dangerouslySetInnerHTML={ { __html: result.content ?? '' } }
					/>
					<p className="uxs-form__help">{ __( 'Copy the text above into the product description field.', 'ux-studio' ) }</p>
				</div>
			) : null }
		</div>
	);
}

/**
 * Bulk SEO fix: generates + saves SEO meta for every published post/page
 * that doesn't have one yet, a bounded batch at a time (Rank Math's bulk
 * SEO title/description generation, applied to everything already on this
 * site instead of one post at a time).
 */
function BulkSeoSection(): JSX.Element {
	const queryClient = useQueryClient();
	const [ postType, setPostType ] = useState( 'post' );
	const [ log, setLog ] = useState< BulkSeoRunResult[ 'processed' ] >( [] );

	const status = useQuery( {
		queryKey: [ 'ai-assistant', 'bulk-seo-status', postType ],
		queryFn: () => api< BulkSeoStatus >( `ai-assistant/content/bulk-seo/status?post_type=${ postType }` ),
	} );

	const run = useMutation( {
		mutationFn: () =>
			api< BulkSeoRunResult >( 'ai-assistant/content/bulk-seo/run', {
				method: 'POST',
				body: JSON.stringify( { post_type: postType, limit: 5 } ),
			} ),
		onSuccess: ( data ) => {
			setLog( ( prev ) => [ ...data.processed, ...prev ] );
			void queryClient.invalidateQueries( { queryKey: [ 'ai-assistant', 'bulk-seo-status', postType ] } );
		},
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Bulk SEO fix', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-bulk-post-type">{ __( 'Post type', 'ux-studio' ) }</label>
				<select id="uxs-cc-bulk-post-type" value={ postType } onChange={ ( e ) => setPostType( e.target.value ) }>
					<option value="post">{ __( 'Posts', 'ux-studio' ) }</option>
					<option value="page">{ __( 'Pages', 'ux-studio' ) }</option>
				</select>
			</div>
			{ status.data ? (
				<p className="uxs-form__help">
					{ status.data.seo_plugin
						? sprintf_( __( 'Detected SEO plugin: %s - the generated title/description is written directly into its fields.', 'ux-studio' ), status.data.seo_plugin )
						: __( 'No SEO plugin detected - saved into UX Studio\'s own fields.', 'ux-studio' ) }
				</p>
			) : null }
			<p>
				{ status.isLoading
					? __( 'Checking…', 'ux-studio' )
					: sprintf_number( __( '%d posts still missing SEO title/description.', 'ux-studio' ), status.data?.missing_count ?? 0 ) }
			</p>
			<button
				type="button"
				className="button button-primary"
				disabled={ run.isPending || ! status.data || status.data.missing_count === 0 }
				onClick={ () => run.mutate() }
			>
				{ run.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Fix next 5', 'ux-studio' ) }
			</button>
			{ run.isError ? <p className="uxs-form__help">{ ( run.error as Error ).message }</p> : null }

			{ log.length > 0 ? (
				<table className="uxs-table" style={ { marginTop: 'var(--uxs-sp-4)' } }>
					<thead>
						<tr>
							<th>{ __( 'Post', 'ux-studio' ) }</th>
							<th>{ __( 'SEO title', 'ux-studio' ) }</th>
							<th>{ __( 'SEO description', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ log.map( ( row ) => (
							<tr key={ row.post_id }>
								<td>{ row.title }</td>
								<td>{ row.error ? <span style={ { color: '#dc2626' } }>{ row.error }</span> : row.seo_title }</td>
								<td>{ row.seo_description }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
		</div>
	);
}

function sprintf_number( template: string, value: number ): string {
	return template.replace( '%d', String( value ) );
}

/**
 * SEO meta generator: paste any content, get back a title/description/
 * keywords set. Optionally saved onto an existing post id.
 */
function SeoGeneratorSection(): JSX.Element {
	const [ content, setContent ] = useState( '' );
	const [ postId, setPostId ] = useState( '' );
	const [ result, setResult ] = useState< GeneratedSeo | null >( null );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedSeo >( 'ai-assistant/content/generate-seo', {
				method: 'POST',
				body: JSON.stringify( { content, post_id: postId ? Number( postId ) : undefined } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generate SEO meta', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-seo-content">{ __( 'Content', 'ux-studio' ) }</label>
				<textarea id="uxs-cc-seo-content" rows={ 5 } value={ content } onChange={ ( e ) => setContent( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-seo-post-id">{ __( 'Save to post ID (optional)', 'ux-studio' ) }</label>
				<input id="uxs-cc-seo-post-id" type="number" min={ 0 } value={ postId } onChange={ ( e ) => setPostId( e.target.value ) } />
			</div>
			<button type="button" className="button button-primary" disabled={ content.trim() === '' || generate.isPending } onClick={ () => generate.mutate() }>
				{ generate.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Generate SEO meta', 'ux-studio' ) }
			</button>
			{ generate.isError ? <p className="uxs-form__help">{ ( generate.error as Error ).message }</p> : null }

			{ result ? (
				<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
					{ result.seo_plugin_detected ? (
						<p className="uxs-form__help">
							{ sprintf_( __( 'Detected SEO plugin: %s. Copy these values into its fields if needed.', 'ux-studio' ), result.seo_plugin_detected ) }
						</p>
					) : null }
					<p>
						<strong>{ __( 'Title:', 'ux-studio' ) }</strong> { result.seo_title }
					</p>
					<p>
						<strong>{ __( 'Description:', 'ux-studio' ) }</strong> { result.seo_description }
					</p>
					<p>
						<strong>{ __( 'Keywords:', 'ux-studio' ) }</strong> { result.seo_keywords }
					</p>
				</div>
			) : null }
		</div>
	);
}

const SOCIAL_PLATFORMS: Array< { id: 'facebook' | 'instagram' | 'x'; label: string } > = [
	{ id: 'facebook', label: 'Facebook' },
	{ id: 'instagram', label: 'Instagram' },
	{ id: 'x', label: 'X (Twitter)' },
];

/**
 * Social caption generator: paste any content, get back a caption per
 * selected platform (Rank Math's "Facebook Post"/"Instagram Caption"/
 * "Tweet" tools, applied to content this site already has).
 */
function SocialCaptionSection(): JSX.Element {
	const [ content, setContent ] = useState( '' );
	const [ platforms, setPlatforms ] = useState< string[] >( [ 'facebook', 'instagram', 'x' ] );
	const [ result, setResult ] = useState< GeneratedSocial | null >( null );

	const generate = useMutation( {
		mutationFn: () =>
			api< GeneratedSocial >( 'ai-assistant/content/generate-social', {
				method: 'POST',
				body: JSON.stringify( { content, platforms } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	const togglePlatform = ( id: string ) =>
		setPlatforms( ( prev ) => ( prev.includes( id ) ? prev.filter( ( p ) => p !== id ) : [ ...prev, id ] ) );

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<h2>{ __( 'Generate social captions', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-social-content">{ __( 'Content', 'ux-studio' ) }</label>
				<textarea id="uxs-cc-social-content" rows={ 5 } value={ content } onChange={ ( e ) => setContent( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Platforms', 'ux-studio' ) }</label>
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)' } }>
					{ SOCIAL_PLATFORMS.map( ( p ) => (
						<label key={ p.id } style={ { display: 'flex', alignItems: 'center', gap: 'var(--uxs-sp-1)' } }>
							<input type="checkbox" checked={ platforms.includes( p.id ) } onChange={ () => togglePlatform( p.id ) } />
							{ p.label }
						</label>
					) ) }
				</div>
			</div>
			<button
				type="button"
				className="button button-primary"
				disabled={ content.trim() === '' || platforms.length === 0 || generate.isPending }
				onClick={ () => generate.mutate() }
			>
				{ generate.isPending ? <LoaderCircle size={ 14 } /> : <Sparkles size={ 14 } /> } { __( 'Generate captions', 'ux-studio' ) }
			</button>
			{ generate.isError ? <p className="uxs-form__help">{ ( generate.error as Error ).message }</p> : null }

			{ result ? (
				<div style={ { marginTop: 'var(--uxs-sp-4)' } }>
					{ SOCIAL_PLATFORMS.map( ( p ) =>
						result[ p.id ] ? (
							<div key={ p.id } style={ { marginBottom: 'var(--uxs-sp-3)' } }>
								<p style={ { marginBottom: 'var(--uxs-sp-1)' } }>
									<strong>{ p.label }</strong>{ ' ' }
									<button type="button" className="button" onClick={ () => void navigator.clipboard.writeText( result[ p.id ] as string ) }>
										{ __( 'Copy', 'ux-studio' ) }
									</button>
								</p>
								<p style={ { whiteSpace: 'pre-wrap' } }>{ result[ p.id ] }</p>
							</div>
						) : null
					) }
				</div>
			) : null }
		</div>
	);
}

function sprintf_( template: string, value: string ): string {
	return template.replace( '%s', value );
}

/**
 * HTML/URL -> Elementor page importer.
 */
function ElementorImportSection(): JSX.Element {
	const [ mode, setMode ] = useState< 'html' | 'url' >( 'html' );
	const [ html, setHtml ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ selector, setSelector ] = useState( '' );
	const [ title, setTitle ] = useState( '' );
	const [ postType, setPostType ] = useState( 'page' );
	const [ status, setStatus ] = useState( 'draft' );
	const [ result, setResult ] = useState< ElementorImportResponse | null >( null );

	const importHtml = useMutation( {
		mutationFn: () =>
			api< ElementorImportResponse >( 'ai-assistant/content/elementor-import-html', {
				method: 'POST',
				body: JSON.stringify( { html_content: html, title, post_type: postType, status } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	const importUrl = useMutation( {
		mutationFn: () =>
			api< ElementorImportResponse >( 'ai-assistant/content/elementor-import-url', {
				method: 'POST',
				body: JSON.stringify( { url, selector: selector || undefined, title: title || undefined, post_type: postType, status } ),
			} ),
		onSuccess: ( data ) => setResult( data ),
	} );

	const pending = importHtml.isPending || importUrl.isPending;
	const error = ( importHtml.error ?? importUrl.error ) as Error | null;
	const canSubmit = mode === 'html' ? html.trim() !== '' : url.trim() !== '';

	return (
		<div className="uxs-form">
			<h2>{ __( 'Import HTML/URL into Elementor', 'ux-studio' ) }</h2>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-el-mode">{ __( 'Source', 'ux-studio' ) }</label>
				<select id="uxs-cc-el-mode" value={ mode } onChange={ ( e ) => setMode( e.target.value as 'html' | 'url' ) }>
					<option value="html">{ __( 'Paste HTML', 'ux-studio' ) }</option>
					<option value="url">{ __( 'Import from URL', 'ux-studio' ) }</option>
				</select>
			</div>

			{ mode === 'html' ? (
				<div className="uxs-form__row">
					<label htmlFor="uxs-cc-el-html">{ __( 'HTML', 'ux-studio' ) }</label>
					<textarea id="uxs-cc-el-html" rows={ 6 } value={ html } onChange={ ( e ) => setHtml( e.target.value ) } />
				</div>
			) : (
				<>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cc-el-url">{ __( 'Page URL', 'ux-studio' ) }</label>
						<input id="uxs-cc-el-url" type="url" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } placeholder="https://example.com/page" />
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cc-el-selector">{ __( 'Content CSS selector (optional)', 'ux-studio' ) }</label>
						<input id="uxs-cc-el-selector" type="text" value={ selector } onChange={ ( e ) => setSelector( e.target.value ) } placeholder="#content" />
					</div>
				</>
			) }

			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-el-title">{ __( 'Title (optional)', 'ux-studio' ) }</label>
				<input id="uxs-cc-el-title" type="text" value={ title } onChange={ ( e ) => setTitle( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-el-post-type">{ __( 'Post type', 'ux-studio' ) }</label>
				<select id="uxs-cc-el-post-type" value={ postType } onChange={ ( e ) => setPostType( e.target.value ) }>
					<option value="page">{ __( 'Page', 'ux-studio' ) }</option>
					<option value="post">{ __( 'Post', 'ux-studio' ) }</option>
				</select>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cc-el-status">{ __( 'Status', 'ux-studio' ) }</label>
				<select id="uxs-cc-el-status" value={ status } onChange={ ( e ) => setStatus( e.target.value ) }>
					<option value="draft">{ __( 'Draft', 'ux-studio' ) }</option>
					<option value="publish">{ __( 'Published', 'ux-studio' ) }</option>
					<option value="pending">{ __( 'Pending review', 'ux-studio' ) }</option>
				</select>
			</div>

			<button
				type="button"
				className="button button-primary"
				disabled={ ! canSubmit || pending }
				onClick={ () => ( mode === 'html' ? importHtml.mutate() : importUrl.mutate() ) }
			>
				{ pending ? <LoaderCircle size={ 14 } /> : <Upload size={ 14 } /> } { __( 'Import into Elementor', 'ux-studio' ) }
			</button>
			{ error ? <p className="uxs-form__help">{ error.message }</p> : null }

			{ result ? (
				<p style={ { marginTop: 'var(--uxs-sp-3)' } }>
					{ sprintf_( __( 'Created "%s" with the widgets below.', 'ux-studio' ), result.title ) } { result.widgets_created }
					{ ' ' }
					{ __( 'widgets.', 'ux-studio' ) } { ' · ' }
					<a href={ result.edit_url } target="_blank" rel="noreferrer">
						{ __( 'Edit in Elementor', 'ux-studio' ) }
					</a>
					{ ' · ' }
					<a href={ result.url } target="_blank" rel="noreferrer">
						{ __( 'View', 'ux-studio' ) }
					</a>
				</p>
			) : null }
		</div>
	);
}

/**
 * Content Creator tab: AI generation for posts/pages, WooCommerce product
 * descriptions and SEO meta, plus the HTML/URL -> Elementor importer.
 * Rendered inside the AI Assistant module page's tab switch (see
 * src/modules/ai-assistant/Page.tsx, wired in by the orchestrator).
 */
export function ContentCreatorTab(): JSX.Element {
	return (
		<>
			<PostGeneratorSection />
			<WooGeneratorSection />
			<SeoGeneratorSection />
			<BulkSeoSection />
			<SocialCaptionSection />
			<ElementorImportSection />
		</>
	);
}
