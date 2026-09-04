import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, RefreshCw, Plug, Unplug, Trash2, Plus, Eye, EyeOff } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'connection' | 'media' | 'feeds' | 'settings';

interface Status {
	connected: boolean;
	username: string;
	account_type: string;
	connected_at: string;
	token_expires_at: string;
	has_app_id: boolean;
	has_app_secret: boolean;
	media_count: number;
	redirect_uri: string;
}

interface MediaItem {
	id: number;
	instagram_id: string;
	media_type: string;
	media_url: string;
	thumbnail_url: string;
	permalink: string;
	caption: string;
	hashtags: string[];
	attachment_id: number;
	is_hidden: boolean;
	media_timestamp: string;
	synced_at: string;
}

interface FeedConfig {
	theme: string;
	item_limit: number;
	cols_desktop: number;
	cols_tablet: number;
	cols_mobile: number;
	gap_px: number;
	show_caption: boolean;
	show_follow: boolean;
	caption_length: number;
	link_target: string;
	media_types: string;
	include_hashtags: string;
	exclude_hashtags: string;
}

interface Feed {
	id: number;
	created_at: string;
	name: string;
	config: FeedConfig;
}

interface SyncResult {
	fetched: number;
	new: number;
	updated: number;
	failed: number;
}

interface AuthUrl {
	auth_url: string;
}

const THEMES: Record< string, string > = {
	grid: __( 'Grid', 'ux-studio' ),
	masonry: __( 'Masonry', 'ux-studio' ),
	carousel: __( 'Carousel', 'ux-studio' ),
	slider: __( 'Slider', 'ux-studio' ),
	highlight: __( 'Highlight', 'ux-studio' ),
	showcase: __( 'Showcase', 'ux-studio' ),
};

function Spinner(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
		</div>
	);
}

function ConnectionTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'instagram-feed', 'status' ],
		queryFn: () => api< Status >( 'instagram-feed/status' ),
	} );

	const connect = useMutation( {
		mutationFn: () => api< AuthUrl >( 'instagram-feed/connect', { method: 'POST', body: '{}' } ),
		onSuccess: ( result ) => {
			window.location.href = result.auth_url;
		},
	} );

	const disconnect = useMutation( {
		mutationFn: () => api( 'instagram-feed/disconnect', { method: 'POST', body: '{}' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed' ] } );
		},
	} );

	const sync = useMutation( {
		mutationFn: () => api< SyncResult >( 'instagram-feed/sync', { method: 'POST', body: '{}' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed' ] } );
		},
	} );

	if ( isLoading || ! data ) {
		return <Spinner />;
	}

	return (
		<div className="uxs-form">
			{ ! data.has_app_id || ! data.has_app_secret ? (
				<div style={ { padding: 'var(--uxs-sp-3)', borderLeft: '3px solid #d63638', background: 'rgba(214,54,56,.06)', marginBottom: 'var(--uxs-sp-4)' } }>
					<p>
						{ __( 'Set the Instagram app ID and app secret on the Settings tab before connecting.', 'ux-studio' ) }
					</p>
					<p className="uxs-form__help">
						{ __( 'Register this redirect URI in your Meta app:', 'ux-studio' ) } <code>{ data.redirect_uri }</code>
					</p>
				</div>
			) : null }

			{ data.connected ? (
				<>
					<p>
						<span className="uxs-badge is-success">{ __( 'Connected', 'ux-studio' ) }</span>{ ' ' }
						{ data.username ? <strong>@{ data.username }</strong> : null }{ ' ' }
						{ data.account_type ? <span className="uxs-form__help">{ data.account_type }</span> : null }
					</p>
					{ data.token_expires_at ? (
						<p className="uxs-form__help">
							{ __( 'Token expires:', 'ux-studio' ) } { data.token_expires_at } { __( '(auto-refreshed by cron)', 'ux-studio' ) }
						</p>
					) : null }
					<p className="uxs-form__help">
						{ __( 'Cached media items:', 'ux-studio' ) } { data.media_count }
					</p>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-3)' } }>
						<button type="button" className="button button-primary" disabled={ sync.isPending } onClick={ () => sync.mutate() }>
							{ sync.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Sync now', 'ux-studio' ) }
						</button>
						<button type="button" className="button" disabled={ disconnect.isPending } onClick={ () => disconnect.mutate() }>
							<Unplug size={ 14 } /> { __( 'Disconnect', 'ux-studio' ) }
						</button>
					</div>
					{ sync.isError ? <p className="uxs-form__help">{ ( sync.error as Error ).message }</p> : null }
					{ sync.data ? (
						<p>
							<span className="uxs-badge is-success">
								{ __( 'Synced', 'ux-studio' ) }: { sync.data.new } { __( 'new', 'ux-studio' ) },{ ' ' }
								{ sync.data.updated } { __( 'updated', 'ux-studio' ) }, { sync.data.failed } { __( 'failed', 'ux-studio' ) }
							</span>
						</p>
					) : null }
				</>
			) : (
				<>
					<p>{ __( 'No Instagram account is connected yet.', 'ux-studio' ) }</p>
					<button
						type="button"
						className="button button-primary"
						disabled={ connect.isPending || ! data.has_app_id || ! data.has_app_secret }
						onClick={ () => connect.mutate() }
					>
						{ connect.isPending ? <LoaderCircle size={ 14 } /> : <Plug size={ 14 } /> } { __( 'Connect Instagram', 'ux-studio' ) }
					</button>
					{ connect.isError ? <p className="uxs-form__help">{ ( connect.error as Error ).message }</p> : null }
				</>
			) }
		</div>
	);
}

function MediaTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'instagram-feed', 'media' ],
		queryFn: () => api< MediaItem[] >( 'instagram-feed/media' ),
	} );

	const toggle = useMutation( {
		mutationFn: ( id: number ) => api< MediaItem >( `instagram-feed/media/${ id }/toggle-hidden`, { method: 'POST', body: '{}' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed', 'media' ] } );
		},
	} );

	if ( isLoading ) {
		return <Spinner />;
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No media cached yet. Connect an account and run a sync.', 'ux-studio' ) }</p>;
	}

	return (
		<div className="uxs-grid">
			{ data.map( ( item ) => (
				<div key={ item.id } className="uxs-tile" style={ { opacity: item.is_hidden ? 0.45 : 1 } }>
					<a href={ item.permalink } target="_blank" rel="noopener noreferrer">
						<img
							src={ item.thumbnail_url || item.media_url }
							alt={ item.caption }
							style={ { width: '100%', height: '160px', objectFit: 'cover', borderRadius: 'var(--uxs-radius-s)' } }
						/>
					</a>
					<button
						type="button"
						className="button-link"
						onClick={ () => toggle.mutate( item.id ) }
						aria-label={ item.is_hidden ? __( 'Show', 'ux-studio' ) : __( 'Hide', 'ux-studio' ) }
					>
						{ item.is_hidden ? <EyeOff size={ 14 } /> : <Eye size={ 14 } /> }{ ' ' }
						{ item.is_hidden ? __( 'Hidden', 'ux-studio' ) : __( 'Visible', 'ux-studio' ) }
					</button>
				</div>
			) ) }
		</div>
	);
}

const DEFAULT_CONFIG: FeedConfig = {
	theme: 'grid',
	item_limit: 12,
	cols_desktop: 4,
	cols_tablet: 3,
	cols_mobile: 2,
	gap_px: 8,
	show_caption: false,
	show_follow: false,
	caption_length: 120,
	link_target: '_blank',
	media_types: 'IMAGE,VIDEO,CAROUSEL_ALBUM',
	include_hashtags: '',
	exclude_hashtags: '',
};

function FeedEditor( {
	initialName,
	initialConfig,
	onSubmit,
	onCancel,
	pending,
}: {
	initialName: string;
	initialConfig: FeedConfig;
	onSubmit: ( name: string, config: FeedConfig ) => void;
	onCancel: () => void;
	pending: boolean;
} ): JSX.Element {
	const [ name, setName ] = useState( initialName );
	const [ config, setConfig ] = useState< FeedConfig >( initialConfig );

	const set = < K extends keyof FeedConfig >( key: K, value: FeedConfig[ K ] ): void =>
		setConfig( ( c ) => ( { ...c, [ key ]: value } ) );

	return (
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label>{ __( 'Feed name', 'ux-studio' ) }</label>
				<input type="text" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Theme', 'ux-studio' ) }</label>
				<select value={ config.theme } onChange={ ( e ) => set( 'theme', e.target.value ) }>
					{ Object.entries( THEMES ).map( ( [ k, label ] ) => (
						<option key={ k } value={ k }>{ label }</option>
					) ) }
				</select>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Number of posts', 'ux-studio' ) }</label>
				<input type="number" value={ config.item_limit } onChange={ ( e ) => set( 'item_limit', Number( e.target.value ) ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Columns (desktop)', 'ux-studio' ) }</label>
				<input type="number" value={ config.cols_desktop } onChange={ ( e ) => set( 'cols_desktop', Number( e.target.value ) ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Gap (px)', 'ux-studio' ) }</label>
				<input type="number" value={ config.gap_px } onChange={ ( e ) => set( 'gap_px', Number( e.target.value ) ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Media types', 'ux-studio' ) }</label>
				<input type="text" value={ config.media_types } onChange={ ( e ) => set( 'media_types', e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Include hashtags', 'ux-studio' ) }</label>
				<input type="text" value={ config.include_hashtags } onChange={ ( e ) => set( 'include_hashtags', e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Exclude hashtags', 'ux-studio' ) }</label>
				<input type="text" value={ config.exclude_hashtags } onChange={ ( e ) => set( 'exclude_hashtags', e.target.value ) } />
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Show captions', 'ux-studio' ) }</label>
				<button
					type="button"
					className={ `uxs-switch${ config.show_caption ? ' is-on' : '' }` }
					aria-pressed={ config.show_caption }
					onClick={ () => set( 'show_caption', ! config.show_caption ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Show follow button', 'ux-studio' ) }</label>
				<button
					type="button"
					className={ `uxs-switch${ config.show_follow ? ' is-on' : '' }` }
					aria-pressed={ config.show_follow }
					onClick={ () => set( 'show_follow', ! config.show_follow ) }
				/>
			</div>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)' } }>
				<button type="button" className="button button-primary" disabled={ pending } onClick={ () => onSubmit( name, config ) }>
					{ __( 'Save feed', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'ux-studio' ) }
				</button>
			</div>
		</div>
	);
}

function FeedsTab(): JSX.Element {
	const [ editing, setEditing ] = useState< Feed | 'new' | null >( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'instagram-feed', 'feeds' ],
		queryFn: () => api< Feed[] >( 'instagram-feed/feeds' ),
	} );

	const invalidate = (): void => {
		void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed', 'feeds' ] } );
		setEditing( null );
	};

	const create = useMutation( {
		mutationFn: ( vars: { name: string; config: FeedConfig } ) =>
			api< Feed >( 'instagram-feed/feeds', { method: 'POST', body: JSON.stringify( vars ) } ),
		onSuccess: invalidate,
	} );

	const update = useMutation( {
		mutationFn: ( vars: { id: number; name: string; config: FeedConfig } ) =>
			api< Feed >( `instagram-feed/feeds/${ vars.id }`, { method: 'POST', body: JSON.stringify( { name: vars.name, config: vars.config } ) } ),
		onSuccess: invalidate,
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `instagram-feed/feeds/${ id }/delete`, { method: 'POST', body: '{}' } ),
		onSuccess: invalidate,
	} );

	if ( isLoading || ! data ) {
		return <Spinner />;
	}

	if ( editing === 'new' ) {
		return (
			<FeedEditor
				initialName=""
				initialConfig={ DEFAULT_CONFIG }
				pending={ create.isPending }
				onCancel={ () => setEditing( null ) }
				onSubmit={ ( name, config ) => create.mutate( { name, config } ) }
			/>
		);
	}
	if ( editing && editing !== 'new' ) {
		const feed = editing;
		return (
			<FeedEditor
				initialName={ feed.name }
				initialConfig={ feed.config }
				pending={ update.isPending }
				onCancel={ () => setEditing( null ) }
				onSubmit={ ( name, config ) => update.mutate( { id: feed.id, name, config } ) }
			/>
		);
	}

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" onClick={ () => setEditing( 'new' ) }>
					<Plus size={ 14 } /> { __( 'New feed', 'ux-studio' ) }
				</button>
			</div>
			{ data.length === 0 ? (
				<p>{ __( 'No feeds yet. Create one, then embed it with its shortcode.', 'ux-studio' ) }</p>
			) : (
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Theme', 'ux-studio' ) }</th>
							<th>{ __( 'Shortcode', 'ux-studio' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ data.map( ( feed ) => (
							<tr key={ feed.id }>
								<td>{ feed.name || `#${ feed.id }` }</td>
								<td>{ THEMES[ feed.config.theme ] ?? feed.config.theme }</td>
								<td><code>[uxstudio_instagram id="{ feed.id }"]</code></td>
								<td style={ { textAlign: 'right' } }>
									<button type="button" className="button-link" onClick={ () => setEditing( feed ) }>
										{ __( 'Edit', 'ux-studio' ) }
									</button>{ ' ' }
									<button type="button" className="button-link" onClick={ () => remove.mutate( feed.id ) } aria-label={ __( 'Delete', 'ux-studio' ) }>
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

function SettingsTab(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'instagram-feed' );

	if ( isLoading || ! data ) {
		return <Spinner />;
	}

	return (
		<>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
			<p className="uxs-form__help">
				{ __( 'Default shortcode: [uxstudio_instagram]. Target a specific feed with [uxstudio_instagram id="1"].', 'ux-studio' ) }
			</p>
			<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'connection' );

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
					{ __( 'Instagram Feed', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'connection' ? 'is-active' : '' } onClick={ () => setTab( 'connection' ) }>
					{ __( 'Connection', 'ux-studio' ) }
				</button>
				<button className={ tab === 'media' ? 'is-active' : '' } onClick={ () => setTab( 'media' ) }>
					{ __( 'Media', 'ux-studio' ) }
				</button>
				<button className={ tab === 'feeds' ? 'is-active' : '' } onClick={ () => setTab( 'feeds' ) }>
					{ __( 'Feeds', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'connection' && <ConnectionTab /> }
			{ tab === 'media' && <MediaTab /> }
			{ tab === 'feeds' && <FeedsTab /> }
			{ tab === 'settings' && <SettingsTab /> }
		</>
	);
}
