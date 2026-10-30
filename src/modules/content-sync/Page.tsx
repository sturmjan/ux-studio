import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Pencil, Plus, RefreshCw, Trash2, KeyRound, Send } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { useModuleSettings } from '../../app/SettingsForm';
import { TopicSettings, withKeys } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';

type Tab = 'sites' | 'push' | 'remote' | 'log' | 'settings' | 'sso';

interface Site {
	id: number;
	created_at: string;
	name: string;
	url: string;
	status: string;
	acf_active: number;
	last_ping: string | null;
	last_sync: string | null;
	has_api_key: boolean;
}

interface LogRow {
	id: number;
	created_at: string;
	site_id: number;
	site_name: string;
	action: string;
	status: string;
	object_type: string;
	object_id: number;
	object_title: string;
	message: string;
}

interface LocalPost {
	id: number;
	title: string;
	status: string;
	post_type: string;
	date: string;
}

interface PushResult {
	site_id: number;
	site_name: string;
	success: boolean;
	remote_id?: number;
	message: string;
}

interface TestResult {
	success: boolean;
	error?: string;
}

interface SsoResult {
	success: boolean;
	login_url?: string;
	error?: string;
}

function StatusBadge( { status }: { status: string } ): JSX.Element {
	const ok = status === 'connected' || status === 'success';
	const cls = ok ? 'is-success' : status === 'unchecked' ? '' : 'is-danger';
	return <span className={ `uxs-badge ${ cls }` }>{ status }</span>;
}

function AddSiteForm(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ apiKey, setApiKey ] = useState( '' );

	const create = useMutation( {
		mutationFn: () =>
			api< Site >( 'content-sync/sites', {
				method: 'POST',
				body: JSON.stringify( { name, url, api_key: apiKey } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'sites' ] } );
			setName( '' );
			setUrl( '' );
			setApiKey( '' );
		},
	} );

	const canSubmit = name.trim() !== '' && url.trim() !== '' && apiKey.trim() !== '' && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cs-name">{ __( 'Node name', 'ux-studio' ) }</label>
				<input id="uxs-cs-name" type="text" value={ name } onChange={ ( e ) => setName( e.target.value ) } placeholder={ __( 'e.g. Client hotel site', 'ux-studio' ) } />
				<p className="uxs-form__help">{ __( 'A name for you, to recognize the site in the list. A node is a site managed from this one.', 'ux-studio' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cs-url">{ __( 'Node URL', 'ux-studio' ) }</label>
				<input id="uxs-cs-url" type="text" value={ url } onChange={ ( e ) => setUrl( e.target.value ) } placeholder="https://node.example.com" />
				<p className="uxs-form__help">{ __( 'The address of the managed site, with https:// at the start.', 'ux-studio' ) }</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-cs-key">{ __( 'Shared API key', 'ux-studio' ) }</label>
				<input id="uxs-cs-key" type="password" value={ apiKey } onChange={ ( e ) => setApiKey( e.target.value ) } placeholder={ __( 'Same value as the node’s API key', 'ux-studio' ) } />
				<p className="uxs-form__help">{ __( 'Stored encrypted; never shown again.', 'ux-studio' ) }</p>
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : <Plus size={ 14 } /> } { __( 'Add node', 'ux-studio' ) }
			</button>
			{ create.isError ? <p className="uxs-form__help">{ ( create.error as Error ).message }</p> : null }
		</div>
	);
}

function SitesTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'content-sync', 'sites' ],
		queryFn: () => api< Site[] >( 'content-sync/sites' ),
	} );
	const [ notice, setNotice ] = useState< string >( '' );

	const test = useMutation( {
		mutationFn: ( id: number ) => api< TestResult >( `content-sync/sites/${ id }/test`, { method: 'POST' } ),
		onSuccess: ( res ) => {
			setNotice( res.success ? __( 'Connection OK.', 'ux-studio' ) : ( res.error ?? __( 'Connection failed.', 'ux-studio' ) ) );
			void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'sites' ] } );
		},
	} );

	const sso = useMutation( {
		mutationFn: ( id: number ) => api< SsoResult >( `content-sync/sites/${ id }/sso`, { method: 'POST' } ),
		onSuccess: ( res ) => {
			if ( res.success && res.login_url ) {
				window.open( res.login_url, '_blank', 'noopener' );
				setNotice( __( 'SSO link opened in a new tab.', 'ux-studio' ) );
			} else {
				setNotice( res.error ?? __( 'SSO issue failed.', 'ux-studio' ) );
			}
		},
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `content-sync/sites/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'sites' ] } ),
	} );

	return (
		<>
			<AddSiteForm />
			{ notice ? <p className="uxs-form__help">{ notice }</p> : null }
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No nodes registered yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'URL', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Last sync', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( site ) => (
							<tr key={ site.id }>
								<td>{ site.name }</td>
								<td>{ site.url }</td>
								<td><StatusBadge status={ site.status } /></td>
								<td>{ site.last_sync ?? __( 'Never', 'ux-studio' ) }</td>
								<td>
									<button type="button" className="button" title={ __( 'Test connection', 'ux-studio' ) } onClick={ () => test.mutate( site.id ) }>
										<RefreshCw size={ 14 } />
									</button>{ ' ' }
									<button type="button" className="button" title={ __( 'Open SSO login', 'ux-studio' ) } onClick={ () => sso.mutate( site.id ) }>
										<KeyRound size={ 14 } />
									</button>{ ' ' }
									<button type="button" className="button" title={ __( 'Remove', 'ux-studio' ) } onClick={ () => remove.mutate( site.id ) }>
										<Trash2 size={ 14 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) : null }
		</>
	);
}

function PushTab(): JSX.Element {
	const [ search, setSearch ] = useState( '' );
	const [ postId, setPostId ] = useState< number | null >( null );
	const [ selected, setSelected ] = useState< number[] >( [] );

	const sites = useQuery( {
		queryKey: [ 'content-sync', 'sites' ],
		queryFn: () => api< Site[] >( 'content-sync/sites' ),
	} );

	const posts = useQuery( {
		queryKey: [ 'content-sync', 'local-posts', search ],
		queryFn: () => api< LocalPost[] >( `content-sync/local-posts?search=${ encodeURIComponent( search ) }` ),
	} );

	const push = useMutation( {
		mutationFn: () =>
			api< PushResult[] >( 'content-sync/push', {
				method: 'POST',
				body: JSON.stringify( { post_id: postId, site_ids: selected } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'log' ] } ),
	} );

	const toggleSite = ( id: number ): void =>
		setSelected( ( s ) => ( s.includes( id ) ? s.filter( ( x ) => x !== id ) : [ ...s, id ] ) );

	const canPush = postId !== null && selected.length > 0 && ! push.isPending;

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-cs-search">{ __( 'Find content to push', 'ux-studio' ) }</label>
					<input id="uxs-cs-search" type="text" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search posts…', 'ux-studio' ) } />
					<p className="uxs-form__help">{ __( 'Type part of the title of the post or page you want to send to a node.', 'ux-studio' ) }</p>
				</div>
			</div>

			{ posts.isLoading ? (
				<div className="uxs-loading"><LoaderCircle size={ 24 } /></div>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th></th>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ ( posts.data ?? [] ).map( ( p ) => (
							<tr key={ p.id }>
								<td>
									<input type="radio" name="uxs-cs-post" checked={ postId === p.id } onChange={ () => setPostId( p.id ) } />
								</td>
								<td>{ p.title || __( '(no title)', 'ux-studio' ) }</td>
								<td>{ p.post_type }</td>
								<td><StatusBadge status={ p.status } /></td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) }

			<h3 style={ { marginTop: 'var(--uxs-sp-4)' } }>{ __( 'Target nodes', 'ux-studio' ) }</h3>
			<div className="uxs-checklist">
				{ ( sites.data ?? [] ).map( ( s ) => (
					<label key={ s.id } className="uxs-checklist__item">
						<input type="checkbox" checked={ selected.includes( s.id ) } onChange={ () => toggleSite( s.id ) } />
						{ s.name } { ! s.has_api_key ? __( '(no key)', 'ux-studio' ) : '' }
					</label>
				) ) }
			</div>

			<p style={ { marginTop: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" disabled={ ! canPush } onClick={ () => push.mutate() }>
					{ push.isPending ? <LoaderCircle size={ 14 } /> : <Send size={ 14 } /> } { __( 'Push to selected nodes', 'ux-studio' ) }
				</button>
			</p>

			{ push.data ? (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Node', 'ux-studio' ) }</th>
							<th>{ __( 'Result', 'ux-studio' ) }</th>
							<th>{ __( 'Detail', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ push.data.map( ( r ) => (
							<tr key={ r.site_id }>
								<td>{ r.site_name }</td>
								<td><StatusBadge status={ r.success ? 'success' : 'error' } /></td>
								<td>{ r.success ? `#${ r.remote_id ?? '' }` : r.message }</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) : null }
		</>
	);
}

interface RemotePostItem {
	id: number;
	title: string;
	status: string;
	post_type: string;
	date: string;
}

interface RemotePostList {
	items: RemotePostItem[];
	total: number;
	total_pages: number;
	page: number;
}

interface RemotePostDetail {
	id: number;
	title: string;
	content: string;
	excerpt: string;
	status: string;
}

interface RemoteDraft {
	id: number | null;
	title: string;
	content: string;
	excerpt: string;
	status: string;
}

const EMPTY_DRAFT: RemoteDraft = { id: null, title: '', content: '', excerpt: '', status: 'draft' };
const REMOTE_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

function RemoteTab(): JSX.Element {
	const [ siteId, setSiteId ] = useState< number | null >( null );
	const [ search, setSearch ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ draft, setDraft ] = useState< RemoteDraft | null >( null );
	const [ notice, setNotice ] = useState( '' );

	const sites = useQuery( {
		queryKey: [ 'content-sync', 'sites' ],
		queryFn: () => api< Site[] >( 'content-sync/sites' ),
	} );

	const posts = useQuery( {
		queryKey: [ 'content-sync', 'remote-posts', siteId, search, status, page ],
		enabled: siteId !== null,
		queryFn: () =>
			api< RemotePostList >(
				`content-sync/sites/${ siteId }/posts?page=${ page }&per_page=20&search=${ encodeURIComponent( search ) }&status=${ encodeURIComponent( status ) }`
			),
	} );

	const refresh = (): void => void queryClient.invalidateQueries( { queryKey: [ 'content-sync', 'remote-posts' ] } );

	const edit = useMutation( {
		mutationFn: ( id: number ) => api< RemotePostDetail >( `content-sync/sites/${ siteId }/posts/${ id }` ),
		onSuccess: ( p ) => setDraft( { id: p.id, title: p.title, content: p.content, excerpt: p.excerpt, status: p.status } ),
		onError: ( e: Error ) => setNotice( e.message ),
	} );

	const save = useMutation( {
		mutationFn: ( d: RemoteDraft ) => {
			const body = JSON.stringify( { title: d.title, content: d.content, excerpt: d.excerpt, status: d.status } );
			return d.id === null
				? api( `content-sync/sites/${ siteId }/posts`, { method: 'POST', body } )
				: api( `content-sync/sites/${ siteId }/posts/${ d.id }`, { method: 'PUT', body } );
		},
		onSuccess: () => {
			setDraft( null );
			setNotice( __( 'Saved on the node.', 'ux-studio' ) );
			refresh();
		},
		onError: ( e: Error ) => setNotice( e.message ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `content-sync/sites/${ siteId }/posts/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			setNotice( __( 'Post moved to trash on the node.', 'ux-studio' ) );
			refresh();
		},
		onError: ( e: Error ) => setNotice( e.message ),
	} );

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-cs-remote-site">{ __( 'Node', 'ux-studio' ) }</label>
					<select
						id="uxs-cs-remote-site"
						value={ siteId ?? '' }
						onChange={ ( e ) => {
							setSiteId( e.target.value === '' ? null : Number( e.target.value ) );
							setPage( 1 );
							setDraft( null );
						} }
					>
						<option value="">{ __( 'Select a node…', 'ux-studio' ) }</option>
						{ ( sites.data ?? [] ).filter( ( s ) => s.has_api_key ).map( ( s ) => (
							<option key={ s.id } value={ s.id }>{ s.name }</option>
						) ) }
					</select>
				</div>
			</div>
			{ notice ? <p className="uxs-form__help">{ notice }</p> : null }

			{ siteId !== null && draft === null ? (
				<>
					<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
						<div className="uxs-form__row">
							<label htmlFor="uxs-cs-remote-search">{ __( 'Search', 'ux-studio' ) }</label>
							<input
								id="uxs-cs-remote-search"
								type="text"
								value={ search }
								onChange={ ( e ) => {
									setSearch( e.target.value );
									setPage( 1 );
								} }
								placeholder={ __( 'Search posts…', 'ux-studio' ) }
							/>
						</div>
						<div className="uxs-form__row">
							<label htmlFor="uxs-cs-remote-status">{ __( 'Status', 'ux-studio' ) }</label>
							<select
								id="uxs-cs-remote-status"
								value={ status }
								onChange={ ( e ) => {
									setStatus( e.target.value );
									setPage( 1 );
								} }
							>
								<option value="">{ __( 'All', 'ux-studio' ) }</option>
								{ REMOTE_STATUSES.map( ( st ) => (
									<option key={ st } value={ st }>{ st }</option>
								) ) }
							</select>
						</div>
						<button type="button" className="button button-primary" onClick={ () => setDraft( EMPTY_DRAFT ) }>
							<Plus size={ 14 } /> { __( 'New post', 'ux-studio' ) }
						</button>
					</div>

					{ posts.isLoading ? <div className="uxs-loading"><LoaderCircle size={ 24 } /></div> : null }
					{ posts.isError ? <p className="uxs-form__help">{ ( posts.error as Error ).message }</p> : null }
					{ posts.data ? (
						<>
							<div className="uxs-table-wrap"><table className="uxs-table">
								<thead>
									<tr>
										<th>{ __( 'Title', 'ux-studio' ) }</th>
										<th>{ __( 'Type', 'ux-studio' ) }</th>
										<th>{ __( 'Status', 'ux-studio' ) }</th>
										<th>{ __( 'Date', 'ux-studio' ) }</th>
										<th>{ __( 'Actions', 'ux-studio' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ posts.data.items.map( ( p ) => (
										<tr key={ p.id }>
											<td>{ p.title || __( '(no title)', 'ux-studio' ) }</td>
											<td>{ p.post_type }</td>
											<td><StatusBadge status={ p.status } /></td>
											<td>{ formatDate( p.date ) }</td>
											<td>
												<button type="button" className="button" title={ __( 'Edit', 'ux-studio' ) } onClick={ () => edit.mutate( p.id ) }>
													<Pencil size={ 14 } />
												</button>{ ' ' }
												<button
													type="button"
													className="button"
													title={ __( 'Delete', 'ux-studio' ) }
													onClick={ () => {
														if ( window.confirm( __( 'Move this post to trash on the node?', 'ux-studio' ) ) ) {
															remove.mutate( p.id );
														}
													} }
												>
													<Trash2 size={ 14 } />
												</button>
											</td>
										</tr>
									) ) }
								</tbody>
							</table></div>
							<p>
								<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( ( n ) => n - 1 ) }>
									{ __( 'Previous', 'ux-studio' ) }
								</button>{ ' ' }
								{ page } / { Math.max( 1, posts.data.total_pages ) }{ ' ' }
								<button type="button" className="button" disabled={ page >= posts.data.total_pages } onClick={ () => setPage( ( n ) => n + 1 ) }>
									{ __( 'Next', 'ux-studio' ) }
								</button>
							</p>
						</>
					) : null }
				</>
			) : null }

			{ siteId !== null && draft !== null ? (
				<div className="uxs-form">
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-rp-title">{ __( 'Title', 'ux-studio' ) }</label>
						<input id="uxs-cs-rp-title" type="text" value={ draft.title } onChange={ ( e ) => setDraft( { ...draft, title: e.target.value } ) } />
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-rp-status">{ __( 'Status', 'ux-studio' ) }</label>
						<select id="uxs-cs-rp-status" value={ draft.status } onChange={ ( e ) => setDraft( { ...draft, status: e.target.value } ) }>
							{ REMOTE_STATUSES.map( ( st ) => (
								<option key={ st } value={ st }>{ st }</option>
							) ) }
						</select>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-rp-excerpt">{ __( 'Excerpt', 'ux-studio' ) }</label>
						<textarea id="uxs-cs-rp-excerpt" rows={ 3 } value={ draft.excerpt } onChange={ ( e ) => setDraft( { ...draft, excerpt: e.target.value } ) } />
						<p className="uxs-form__help">{ __( 'A short summary that lists and search results show instead of the whole text.', 'ux-studio' ) }</p>
					</div>
					<div className="uxs-form__row">
						<label htmlFor="uxs-cs-rp-content">{ __( 'Content (HTML)', 'ux-studio' ) }</label>
						<textarea id="uxs-cs-rp-content" rows={ 14 } value={ draft.content } onChange={ ( e ) => setDraft( { ...draft, content: e.target.value } ) } />
						<p className="uxs-form__help">{ __( 'The text of the post as HTML code. A mistake here changes the live page on the node once you save.', 'ux-studio' ) }</p>
					</div>
					<button type="button" className="button button-primary" disabled={ save.isPending || draft.title.trim() === '' } onClick={ () => save.mutate( draft ) }>
						{ save.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Save on node', 'ux-studio' ) }
					</button>{ ' ' }
					<button type="button" className="button" onClick={ () => setDraft( null ) }>
						{ __( 'Cancel', 'ux-studio' ) }
					</button>
				</div>
			) : null }
		</>
	);
}

function LogTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'content-sync', 'log' ],
		queryFn: () => api< LogRow[] >( 'content-sync/log' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No sync activity yet.', 'ux-studio' ) }</p>;
	}

	return (
		<div className="uxs-table-wrap"><table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Node', 'ux-studio' ) }</th>
					<th>{ __( 'Action', 'ux-studio' ) }</th>
					<th>{ __( 'Object', 'ux-studio' ) }</th>
					<th>{ __( 'Status', 'ux-studio' ) }</th>
					<th>{ __( 'Detail', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row ) => (
					<tr key={ row.id }>
						<td>{ formatDate( row.created_at ) }</td>
						<td>{ row.site_name || ( row.site_id ? `#${ row.site_id }` : '—' ) }</td>
						<td>{ row.action }</td>
						<td>{ row.object_title || ( row.object_id ? `#${ row.object_id }` : '' ) }</td>
						<td><StatusBadge status={ row.status } /></td>
						<td>{ row.message }</td>
					</tr>
				) ) }
			</tbody>
		</table></div>
	);
}

/** Whether the secrets are stored (they are never sent back to the browser). */
function SecretsNote(): JSX.Element {
	const { draft } = useModuleSettings( 'content-sync' );
	return (
		<p>
			{ draft.has_node_api_key
				? __( 'A node API key is currently set.', 'ux-studio' )
				: __( 'No node API key set yet.', 'ux-studio' ) }
			{ ' ' }
			{ draft.has_hmac_secret
				? __( 'A central-app HMAC secret is set.', 'ux-studio' )
				: __( 'No central-app HMAC secret set.', 'ux-studio' ) }
		</p>
	);
}

/** Role of this site plus how it connects (as a node, to the central app). */
const isConnectionKey = withKeys( 'mode', 'node_', 'central_app_url', 'hmac_secret' );

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'sites' );

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
					{ __( 'Content Sync', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				<button className={ tab === 'sites' ? 'is-active' : '' } onClick={ () => setTab( 'sites' ) }>
					{ __( 'Nodes', 'ux-studio' ) }
				</button>
				<button className={ tab === 'push' ? 'is-active' : '' } onClick={ () => setTab( 'push' ) }>
					{ __( 'Push', 'ux-studio' ) }
				</button>
				<button className={ tab === 'remote' ? 'is-active' : '' } onClick={ () => setTab( 'remote' ) }>
					{ __( 'Remote posts', 'ux-studio' ) }
				</button>
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Log', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Role and connection', 'ux-studio' ) }
				</button>
				<button className={ tab === 'sso' ? 'is-active' : '' } onClick={ () => setTab( 'sso' ) }>
					{ __( 'SSO login', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'sites' && <SitesTab /> }
			{ tab === 'push' && <PushTab /> }
			{ tab === 'remote' && <RemoteTab /> }
			{ tab === 'log' && (
				<>
					<TopicSettings moduleId="content-sync" include={ withKeys( 'retention_days' ) } collapsible={ __( 'Log settings', 'ux-studio' ) } />
					<LogTab />
				</>
			) }
			{ tab === 'settings' && (
				<TopicSettings moduleId="content-sync" include={ isConnectionKey }>
					<SecretsNote />
				</TopicSettings>
			) }
			{ tab === 'sso' && <TopicSettings moduleId="content-sync" include={ withKeys( 'sso_' ) } /> }
		</>
	);
}
