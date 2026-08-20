import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

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

const SNIPPETS_QUERY_KEY = [ 'code-snippets', 'snippets' ];

const RUN_LOCATIONS: Record< SnippetType, Record< string, string > > = {
	php: {
		everywhere: __( 'Everywhere (frontend & admin)', 'ux-studio' ),
		admin_only: __( 'Admin only', 'ux-studio' ),
		frontend_only: __( 'Frontend only', 'ux-studio' ),
	},
	css: {
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
	},
	js: {
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
	},
	html: {
		site_header: __( 'Frontend header', 'ux-studio' ),
		site_body_open: __( 'Frontend body open', 'ux-studio' ),
		site_footer: __( 'Frontend footer', 'ux-studio' ),
		admin_header: __( 'Admin header', 'ux-studio' ),
		admin_footer: __( 'Admin footer', 'ux-studio' ),
	},
};

const DEFAULT_RUN_LOCATION: Record< SnippetType, string > = {
	php: 'everywhere',
	css: 'site_header',
	js: 'site_footer',
	html: 'site_header',
};

const EMPTY_DRAFT = {
	name: '',
	type: 'php' as SnippetType,
	code: '',
	description: '',
	run_location: DEFAULT_RUN_LOCATION.php,
	enabled: false,
};

type Draft = typeof EMPTY_DRAFT;

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
			<div className="uxs-tabs">
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
			</div>

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
