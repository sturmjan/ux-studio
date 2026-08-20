import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, RefreshCw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'media' | 'settings';

interface MediaItem {
	id: number;
	feed_id: number;
	media_url: string;
	permalink: string;
	caption: string | null;
	synced_at: string;
}

interface SyncResult {
	synced: number;
}

function MediaTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'instagram-feed', 'media' ],
		queryFn: () => api< MediaItem[] >( 'instagram-feed/media' ),
	} );

	const sync = useMutation( {
		mutationFn: () => api< SyncResult >( 'instagram-feed/sync', { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'instagram-feed', 'media' ] } );
		},
	} );

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" disabled={ sync.isPending } onClick={ () => sync.mutate() }>
					{ sync.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Sync now', 'ux-studio' ) }
				</button>
				{ sync.isError ? <p className="uxs-form__help">{ ( sync.error as Error ).message }</p> : null }
				{ sync.data ? (
					<p>
						<span className="uxs-badge is-success">
							{ __( 'Synced', 'ux-studio' ) } { sync.data.synced } { __( 'items', 'ux-studio' ) }
						</span>
					</p>
				) : null }
			</div>
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? (
				<p>{ __( 'No media synced yet. Configure Content Sync and click "Sync now".', 'ux-studio' ) }</p>
			) : null }
			{ ! isLoading && data && data.length > 0 ? (
				<div className="uxs-grid">
					{ data.map( ( item ) => (
						<a
							key={ item.id }
							className="uxs-tile"
							href={ item.permalink }
							target="_blank"
							rel="noopener noreferrer"
						>
							<img
								src={ item.media_url }
								alt={ item.caption ?? '' }
								style={ { width: '100%', height: '160px', objectFit: 'cover', borderRadius: 'var(--uxs-radius-s)' } }
							/>
							<span className="uxs-tile__desc">{ item.synced_at }</span>
						</a>
					) ) }
				</div>
			) : null }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'media' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'instagram-feed' );

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
				<button className={ tab === 'media' ? 'is-active' : '' } onClick={ () => setTab( 'media' ) }>
					{ __( 'Media', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'media' && <MediaTab /> }
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p className="uxs-form__help">
						{ __( 'Shortcode: [uxstudio_instagram]', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
