import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, Copy, LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';

type Tab = 'snippets' | 'settings';
type View = 'list' | 'editor';
type SnippetType = 'php' | 'html' | 'css' | 'js';

interface SnippetListItem {
	id: string;
	name: string;
	type: SnippetType;
	enabled: boolean;
	description: string;
	run_location: string;
	priority: number;
	post_types: string[];
	shortcode: string;
	last_run: string | null;
	run_result: { ok: boolean; message: string; output: string } | null;
	is_valid: boolean;
	has_integrity_issue: boolean;
	created_at: string;
	updated_at: string;
	created_by: string;
	updated_by: string;
}

interface SnippetDetail extends SnippetListItem {
	code: string;
}

interface EditorOptions {
	post_types: { name: string; label: string }[];
	default_post_types: string[];
	shortcode_tag: string;
}

const SNIPPETS_QUERY_KEY = [ 'code-snippets', 'snippets' ];

const SHORTCODE = 'shortcode';
const BEFORE_CONTENT = 'before_content';
const AFTER_CONTENT = 'after_content';
const RUN_ONCE = 'run_once';

/** Places that print into the page content or run on demand, available for every type (run once: PHP only). */
const CONTENT_LOCATIONS: Record< string, string > = {
	[ SHORTCODE ]: __( 'Shortcode (insert it where you want the output)', 'ux-studio' ),
	[ BEFORE_CONTENT ]: __( 'Before the post content', 'ux-studio' ),
	[ AFTER_CONTENT ]: __( 'After the post content', 'ux-studio' ),
};

const RUN_LOCATIONS: Record< SnippetType, Record< string, string > > = {
	php: {
		everywhere: __( 'Everywhere (frontend & admin)', 'ux-studio' ),
		admin_only: __( 'Admin only', 'ux-studio' ),
		frontend_only: __( 'Frontend only', 'ux-studio' ),
		...CONTENT_LOCATIONS,
		[ RUN_ONCE ]: __( 'Run once (on the next admin page, then it switches itself off)', 'ux-studio' ),
	},
	css: {
		everywhere: __( 'Everywhere (frontend & admin)', 'ux-studio' ),
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
		...CONTENT_LOCATIONS,
	},
	js: {
		everywhere: __( 'Everywhere (frontend & admin)', 'ux-studio' ),
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
		...CONTENT_LOCATIONS,
	},
	html: {
		everywhere: __( 'Everywhere (frontend & admin)', 'ux-studio' ),
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_body_open: __( 'Frontend body open', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
		...CONTENT_LOCATIONS,
	},
};

const DEFAULT_RUN_LOCATION: Record< SnippetType, string > = {
	php: 'everywhere',
	css: 'everywhere',
	js: 'everywhere',
	html: 'everywhere',
};

const EMPTY_DRAFT = {
	name: '',
	type: 'php' as SnippetType,
	code: '',
	description: '',
	run_location: DEFAULT_RUN_LOCATION.php,
	post_types: [] as string[],
	enabled: false,
};

type Draft = typeof EMPTY_DRAFT;

/** Place and, for run once, the last result: badges for the list. */
function PlaceBadges( { snippet }: { snippet: SnippetListItem } ): JSX.Element | null {
	const where = snippet.run_location;
	if ( where === SHORTCODE ) {
		return (
			<div className="uxs-cs-place">
				<span className="uxs-badge is-info">{ __( 'Shortcode', 'ux-studio' ) }</span>
				<code>{ snippet.shortcode }</code>
			</div>
		);
	}
	if ( where === BEFORE_CONTENT || where === AFTER_CONTENT ) {
		return (
			<div className="uxs-cs-place">
				<span className="uxs-badge is-info">{ where === BEFORE_CONTENT ? __( 'Before content', 'ux-studio' ) : __( 'After content', 'ux-studio' ) }</span>
				<span className="uxs-cs-result">{ snippet.post_types.join( ', ' ) }</span>
			</div>
		);
	}
	if ( where === RUN_ONCE ) {
		return (
			<div className="uxs-cs-place">
				<span className="uxs-badge is-info">{ __( 'Run once', 'ux-studio' ) }</span>
				{ snippet.last_run && snippet.run_result && (
					<span className={ `uxs-badge ${ snippet.run_result.ok ? 'is-success' : 'is-danger' }` } title={ snippet.run_result.message || snippet.run_result.output }>
						{ snippet.run_result.ok ? __( 'Ran', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) } { formatDate( snippet.last_run ) }
					</span>
				) }
				{ ! snippet.last_run && snippet.enabled && <span className="uxs-cs-result">{ __( 'Waiting for the next admin page', 'ux-studio' ) }</span> }
			</div>
		);
	}
	return null;
}

function CopyShortcode( { text }: { text: string } ): JSX.Element {
	const [ copied, setCopied ] = useState( false );
	return (
		<button
			type="button"
			className="button button-small"
			onClick={ () => {
				void navigator.clipboard?.writeText( text ).then( () => {
					setCopied( true );
					window.setTimeout( () => setCopied( false ), 1500 );
				} );
			} }
		>
			{ copied ? <Check size={ 14 } /> : <Copy size={ 14 } /> } { copied ? __( 'Copied', 'ux-studio' ) : __( 'Copy', 'ux-studio' ) }
		</button>
	);
}

function SnippetsTable( {
	snippets,
	isLoading,
	onEdit,
	onAdd,
}: {
	snippets: SnippetListItem[];
	isLoading: boolean;
	onEdit: ( id: string ) => void;
	onAdd: () => void;
} ): JSX.Element {
	const toggle = useMutation( {
		mutationFn: ( { id, enabled }: { id: string; enabled: boolean } ) =>
			api( `code-snippets/snippets/${ id }/toggle`, {
				method: 'PUT',
				body: JSON.stringify( { enabled } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: SNIPPETS_QUERY_KEY } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: string ) => api( `code-snippets/snippets/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: SNIPPETS_QUERY_KEY } ),
	} );

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" onClick={ onAdd }>
					<Plus size={ 14 } /> { __( 'Add snippet', 'ux-studio' ) }
				</button>
			</div>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : snippets.length === 0 ? (
				<p>{ __( 'No snippets have been created yet.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Where', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ snippets.map( ( snippet ) => (
							<tr key={ snippet.id }>
								<td>
									{ snippet.name }
									{ snippet.has_integrity_issue && (
										<span className="uxs-badge is-danger" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
											{ __( 'Integrity check failed', 'ux-studio' ) }
										</span>
									) }
									{ ! snippet.is_valid && (
										<span className="uxs-badge is-warning" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
											{ __( 'Invalid', 'ux-studio' ) }
										</span>
									) }
								</td>
								<td>{ snippet.type.toUpperCase() }</td>
								<td>
									<PlaceBadges snippet={ snippet } />
								</td>
								<td>
									<span className={ `uxs-badge ${ snippet.enabled ? 'is-success' : '' }` }>
										{ snippet.enabled ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
									</span>
								</td>
								<td style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
									<button
										type="button"
										className={ `uxs-switch${ snippet.enabled ? ' is-on' : '' }` }
										aria-pressed={ snippet.enabled }
										aria-label={ __( 'Toggle enabled', 'ux-studio' ) }
										disabled={ toggle.isPending }
										onClick={ () => toggle.mutate( { id: snippet.id, enabled: ! snippet.enabled } ) }
									/>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Edit snippet', 'ux-studio' ) }
										onClick={ () => onEdit( snippet.id ) }
									>
										<Pencil size={ 14 } />
									</button>
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete snippet', 'ux-studio' ) }
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this snippet? This cannot be undone.', 'ux-studio' ) ) ) {
												remove.mutate( snippet.id );
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
			) }
		</>
	);
}

function SnippetEditor( { id, onDone }: { id: string | null; onDone: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< Draft >( EMPTY_DRAFT );
	const [ error, setError ] = useState( '' );

	const options = useQuery( {
	queryKey: [ 'code-snippets', 'options' ],
	queryFn: () => api< EditorOptions >( 'code-snippets/options' ),
} );

const query = useQuery( {
	queryKey: [ 'code-snippets', 'snippet', id ],
		queryFn: () => api< SnippetDetail >( `code-snippets/snippets/${ id }` ),
		enabled: id !== null,
	} );

	useEffect( () => {
		if ( query.data ) {
			setDraft( {
				name: query.data.name,
				type: query.data.type,
				code: query.data.code,
				description: query.data.description,
				run_location: query.data.run_location,
				post_types: query.data.post_types,
				enabled: query.data.enabled,
			} );
		}
	}, [ query.data ] );

	const save = useMutation( {
		mutationFn: () => {
			const body = JSON.stringify( draft );
			return id
				? api( `code-snippets/snippets/${ id }`, { method: 'PUT', body } )
				: api( 'code-snippets/snippets', { method: 'POST', body } );
		},
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: SNIPPETS_QUERY_KEY } );
			onDone();
		},
		onError: ( err: unknown ) => {
			setError( err instanceof Error ? err.message : __( 'Failed to save snippet.', 'ux-studio' ) );
		},
	} );

	const isLoading = id !== null && ( query.isLoading || ! query.data );

	return (
		<div className="uxs-form" style={ { maxWidth: 'none' } }>
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-name">{ __( 'Snippet name', 'ux-studio' ) }</label>
						<input
							id="uxs-cs-name"
							type="text"
							value={ draft.name }
							onChange={ ( e ) => setDraft( ( d ) => ( { ...d, name: e.target.value } ) ) }
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-description">{ __( 'Description', 'ux-studio' ) }</label>
						<textarea
							id="uxs-cs-description"
							rows={ 2 }
							value={ draft.description }
							onChange={ ( e ) => setDraft( ( d ) => ( { ...d, description: e.target.value } ) ) }
						/>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-type">{ __( 'Snippet type', 'ux-studio' ) }</label>
						<select
							id="uxs-cs-type"
							value={ draft.type }
							onChange={ ( e ) => {
								const type = e.target.value as SnippetType;
								setDraft( ( d ) => ( { ...d, type, run_location: DEFAULT_RUN_LOCATION[ type ] } ) );
							} }
						>
							<option value="php">{ __( 'PHP', 'ux-studio' ) }</option>
							<option value="html">{ __( 'HTML', 'ux-studio' ) }</option>
							<option value="css">{ __( 'CSS', 'ux-studio' ) }</option>
							<option value="js">{ __( 'JavaScript', 'ux-studio' ) }</option>
						</select>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-code">{ __( 'Snippet code', 'ux-studio' ) }</label>
						<textarea
							id="uxs-cs-code"
							className="uxs-code-editor"
							rows={ 16 }
							spellCheck={ false }
							value={ draft.code }
							onChange={ ( e ) => setDraft( ( d ) => ( { ...d, code: e.target.value } ) ) }
						/>
						{ draft.type === 'php' && (
							<p className="uxs-form__help">
								{ __( 'PHP code only - do not include the opening <?php tag. Code is validated (syntax + duplicate declarations) before it is saved.', 'ux-studio' ) }
							</p>
						) }
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-location">{ __( 'Where to run', 'ux-studio' ) }</label>
						<select
							id="uxs-cs-location"
							value={ draft.run_location }
							onChange={ ( e ) => setDraft( ( d ) => ( { ...d, run_location: e.target.value } ) ) }
						>
							{ Object.entries( RUN_LOCATIONS[ draft.type ] ).map( ( [ key, label ] ) => (
								<option key={ key } value={ key }>
									{ label }
								</option>
							) ) }
						</select>
					</div>
					{ ( draft.run_location === BEFORE_CONTENT || draft.run_location === AFTER_CONTENT ) && (
						<div className="uxs-form__row">
							<label>{ __( 'Post types', 'ux-studio' ) }</label>
							<div className="uxs-cs-types">
								{ ( options.data?.post_types ?? [] ).map( ( type ) => {
									const chosen = draft.post_types.length ? draft.post_types : options.data?.default_post_types ?? [];
									return (
										<label key={ type.name }>
											<input
												type="checkbox"
												checked={ chosen.includes( type.name ) }
												onChange={ ( e ) =>
													setDraft( ( d ) => ( {
														...d,
														post_types: e.target.checked ? [ ...chosen, type.name ] : chosen.filter( ( n ) => n !== type.name ),
													} ) )
												}
											/>{ ' ' }
											{ type.label }
										</label>
									);
								} ) }
							</div>
							<p className="uxs-form__help">
								{ __( 'Inserted around the content of a single post or page (not in lists of posts), only for the chosen post types.', 'ux-studio' ) }
							</p>
						</div>
					) }
					{ draft.run_location === SHORTCODE && (
						<div className="uxs-form__row">
							<label>{ __( 'Shortcode', 'ux-studio' ) }</label>
							{ query.data?.shortcode ? (
								<div className="uxs-cs-place">
									<code>{ query.data.shortcode }</code>
									<CopyShortcode text={ query.data.shortcode } />
								</div>
							) : (
								<p className="uxs-form__help">{ __( 'The shortcode appears after the snippet is saved: [uxstudio_snippet id="..."]. PHP snippets get the shortcode attributes as $atts and the enclosed text as $content, and do not run in REST, AJAX or cron requests.', 'ux-studio' ) }</p>
							) }
						</div>
					) }
					{ draft.run_location === RUN_ONCE && (
						<div className="uxs-form__row">
							<label>{ __( 'Run once', 'ux-studio' ) }</label>
							<div>
								<p className="uxs-form__help">
									{ __( 'When you enable the snippet, its code runs one time on the next page of wp-admin and the snippet switches itself off. Enable it again to run it once more.', 'ux-studio' ) }
								</p>
								{ query.data?.last_run && query.data.run_result && (
									<p className="uxs-cs-result">
										<span className={ `uxs-badge ${ query.data.run_result.ok ? 'is-success' : 'is-danger' }` }>
											{ query.data.run_result.ok ? __( 'Ran', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) } { formatDate( query.data.last_run ) }
										</span>{ ' ' }
										{ query.data.run_result.message || query.data.run_result.output }
									</p>
								) }
							</div>
						</div>
					) }
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-enabled">{ __( 'Enable snippet', 'ux-studio' ) }</label>
						<button
							id="uxs-cs-enabled"
							type="button"
							className={ `uxs-switch${ draft.enabled ? ' is-on' : '' }` }
							aria-pressed={ draft.enabled }
							onClick={ () => setDraft( ( d ) => ( { ...d, enabled: ! d.enabled } ) ) }
						/>
					</div>

					{ error && (
						<p>
							<span className="uxs-badge is-danger">{ error }</span>
						</p>
					) }

					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)' } }>
						<button
							type="button"
							className="button button-primary"
							disabled={ ! draft.name.trim() || ! draft.code.trim() || save.isPending }
							onClick={ () => {
								setError( '' );
								save.mutate();
							} }
						>
							{ id ? __( 'Update snippet', 'ux-studio' ) : __( 'Save snippet', 'ux-studio' ) }
						</button>
						<button type="button" className="button" onClick={ onDone }>
							{ __( 'Back to list', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'snippets' );
	const [ view, setView ] = useState< View >( 'list' );
	const [ editingId, setEditingId ] = useState< string | null >( null );

	const query = useQuery( {
		queryKey: SNIPPETS_QUERY_KEY,
		queryFn: () => api< SnippetListItem[] >( 'code-snippets/snippets' ),
		enabled: tab === 'snippets' && view === 'list',
	} );

	const { data, isLoading: settingsLoading, draft, setDraft, save, saved } = useModuleSettings( 'code-snippets' );

	const backToList = (): void => {
		setEditingId( null );
		setView( 'list' );
	};

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
					{ __( 'Code Snippets', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				<button
					className={ tab === 'snippets' ? 'is-active' : '' }
					onClick={ () => {
						setTab( 'snippets' );
						backToList();
					} }
				>
					{ __( 'Snippets', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</TabsNav>

			{ tab === 'snippets' && view === 'list' && (
				<SnippetsTable
					snippets={ query.data ?? [] }
					isLoading={ query.isLoading }
					onEdit={ ( id ) => {
						setEditingId( id );
						setView( 'editor' );
					} }
					onAdd={ () => {
						setEditingId( null );
						setView( 'editor' );
					} }
				/>
			) }

			{ tab === 'snippets' && view === 'editor' && <SnippetEditor id={ editingId } onDone={ backToList } /> }

			{ tab === 'settings' && ( settingsLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
