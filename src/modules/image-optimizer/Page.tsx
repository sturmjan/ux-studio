import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, RotateCcw, Trash2, RefreshCw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'bulk' | 'scanner' | 'settings';

interface BulkStatus {
	queued: number;
	total: number;
	done: number;
	running: boolean;
}

interface DeliveryStatus {
	active: boolean;
	writable: boolean;
	path: string;
	server_note: string;
}

interface CapabilityStatus {
	supports_webp: boolean;
	supports_avif: boolean;
	delivery: DeliveryStatus;
}

interface UnusedItem {
	id: number;
	title: string;
	filename: string;
	file_size: number;
	thumb_url: string;
	edit_url: string;
	upload_date: string;
	mime_type: string;
}

interface UnusedListing {
	items: UnusedItem[];
	total: number;
	page: number;
	per_page: number;
	total_pages: number;
	total_size: number;
}

function formatSize( bytes: number ): string {
	if ( bytes >= 1_073_741_824 ) {
		return `${ ( bytes / 1_073_741_824 ).toFixed( 2 ) } GB`;
	}
	if ( bytes >= 1_048_576 ) {
		return `${ ( bytes / 1_048_576 ).toFixed( 1 ) } MB`;
	}
	if ( bytes >= 1024 ) {
		return `${ Math.round( bytes / 1024 ) } KB`;
	}
	return `${ bytes } B`;
}

function StatusPanel(): JSX.Element | null {
	const status = useQuery( {
		queryKey: [ 'image-optimizer', 'status' ],
		queryFn: () => api< CapabilityStatus >( 'image-optimizer/status' ),
	} );

	if ( ! status.data ) {
		return null;
	}

	const { supports_webp: webp, supports_avif: avif, delivery } = status.data;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<span>{ __( 'WebP encoding', 'ux-studio' ) }</span>
				<span className={ `uxs-badge ${ webp ? 'is-success' : 'is-danger' }` }>
					{ webp ? __( 'Supported', 'ux-studio' ) : __( 'Unavailable', 'ux-studio' ) }
				</span>
			</div>
			<div className="uxs-form__row">
				<span>{ __( 'AVIF encoding', 'ux-studio' ) }</span>
				<span className={ `uxs-badge ${ avif ? 'is-success' : 'is-danger' }` }>
					{ avif ? __( 'Supported', 'ux-studio' ) : __( 'Unavailable on this server', 'ux-studio' ) }
				</span>
			</div>
			<div className="uxs-form__row">
				<span>{ __( 'Next-gen delivery (.htaccess)', 'ux-studio' ) }</span>
				<span className={ `uxs-badge ${ delivery.active ? 'is-success' : '' }` }>
					{ delivery.active ? __( 'Active', 'ux-studio' ) : __( 'Inactive', 'ux-studio' ) }
				</span>
			</div>
			{ ! delivery.writable ? (
				<p className="uxs-form__help">
					{ __( 'uploads/.htaccess is not writable — delivery rules cannot be managed automatically.', 'ux-studio' ) }
				</p>
			) : null }
			{ delivery.server_note ? <p className="uxs-form__help">{ delivery.server_note }</p> : null }
		</div>
	);
}

function BulkTab(): JSX.Element {
	const [ restoreId, setRestoreId ] = useState( '' );

	const status = useQuery( {
		queryKey: [ 'image-optimizer', 'bulk-status' ],
		queryFn: () => api< BulkStatus >( 'image-optimizer/bulk-status' ),
		refetchInterval: ( query ) => ( query.state.data?.running ? 4000 : false ),
	} );

	const start = useMutation( {
		mutationFn: () => api< BulkStatus >( 'image-optimizer/bulk-start', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'image-optimizer', 'bulk-status' ] } ),
	} );

	const restore = useMutation( {
		mutationFn: ( id: number ) => api( `image-optimizer/restore/${ id }`, { method: 'POST' } ),
	} );

	useEffect( () => {
		void status.refetch();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const data = status.data;
	const progress = data && data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;

	return (
		<>
			<StatusPanel />

			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<p>
					{ __(
						'Compresses JPEG/PNG/GIF images already in the media library and optionally generates WebP/AVIF siblings. Originals are always kept as a backup file before the first optimization.',
						'ux-studio'
					) }
				</p>
				<button type="button" className="button button-primary" disabled={ start.isPending || !! data?.running } onClick={ () => start.mutate() }>
					{ start.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Start bulk optimization', 'ux-studio' ) }
				</button>
				{ data ? (
					<p>
						{ data.running ? __( 'Running…', 'ux-studio' ) : __( 'Idle.', 'ux-studio' ) }
						{ ' ' }
						{ data.done } / { data.total } ({ progress }%)
					</p>
				) : null }
			</div>

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="uxs-io-restore-id">{ __( 'Restore original by attachment ID', 'ux-studio' ) }</label>
					<input
						id="uxs-io-restore-id"
						type="number"
						min={ 1 }
						value={ restoreId }
						onChange={ ( e ) => setRestoreId( e.target.value ) }
					/>
				</div>
				<button
					type="button"
					className="button"
					disabled={ ! restoreId || restore.isPending }
					onClick={ () => restore.mutate( Number( restoreId ) ) }
				>
					<RotateCcw size={ 14 } /> { __( 'Restore', 'ux-studio' ) }
				</button>
				{ restore.isSuccess ? <p className="uxs-badge is-success">{ __( 'Restored.', 'ux-studio' ) }</p> : null }
				{ restore.isError ? <p className="uxs-badge is-danger">{ ( restore.error as Error ).message }</p> : null }
			</div>
		</>
	);
}

function ScannerTab(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState< number[] >( [] );

	const listing = useQuery( {
		queryKey: [ 'image-optimizer', 'unused', page ],
		queryFn: () => api< UnusedListing >( `image-optimizer/unused?page=${ page }` ),
	} );

	const refresh = useMutation( {
		mutationFn: () => api< UnusedListing >( 'image-optimizer/unused?page=1&refresh=1' ),
		onSuccess: () => {
			setPage( 1 );
			setSelected( [] );
			void queryClient.invalidateQueries( { queryKey: [ 'image-optimizer', 'unused' ] } );
		},
	} );

	const trash = useMutation( {
		mutationFn: ( ids: number[] ) =>
			api< { trashed: number; skipped: number } >( 'image-optimizer/trash-unused', {
				method: 'POST',
				body: JSON.stringify( { ids } ),
			} ),
		onSuccess: () => {
			setSelected( [] );
			void queryClient.invalidateQueries( { queryKey: [ 'image-optimizer', 'unused' ] } );
		},
	} );

	const data = listing.data;

	const toggle = ( id: number ): void => {
		setSelected( ( prev ) => ( prev.includes( id ) ? prev.filter( ( x ) => x !== id ) : [ ...prev, id ] ) );
	};

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<p>
					{ __(
						'Heuristically finds image attachments not referenced in content, featured images, term meta, widgets or the site logo. Detection can never be perfect, so selected images are moved to the WordPress trash (fully recoverable) — never permanently deleted.',
						'ux-studio'
					) }
				</p>
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', flexWrap: 'wrap' } }>
					<button type="button" className="button" disabled={ refresh.isPending } onClick={ () => refresh.mutate() }>
						{ refresh.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Re-scan', 'ux-studio' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						disabled={ selected.length === 0 || trash.isPending }
						onClick={ () => trash.mutate( selected ) }
					>
						<Trash2 size={ 14 } />{ ' ' }
						{ __( 'Move selected to trash', 'ux-studio' ) }
						{ selected.length > 0 ? ` (${ selected.length })` : '' }
					</button>
				</div>
				{ data ? (
					<p>
						{ data.total } { __( 'unused images', 'ux-studio' ) } · { formatSize( data.total_size ) }
					</p>
				) : null }
				{ trash.isSuccess ? (
					<p className="uxs-badge is-success">
						{ __( 'Moved to trash:', 'ux-studio' ) } { trash.data.trashed }
						{ trash.data.skipped ? ` · ${ __( 'skipped', 'ux-studio' ) }: ${ trash.data.skipped }` : '' }
					</p>
				) : null }
				{ trash.isError ? <p className="uxs-badge is-danger">{ ( trash.error as Error ).message }</p> : null }
			</div>

			{ listing.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }

			{ data && data.items.length === 0 ? (
				<p>{ __( 'No unused images detected.', 'ux-studio' ) }</p>
			) : null }

			{ data && data.items.length > 0 ? (
				<table className="widefat striped">
					<thead>
						<tr>
							<th style={ { width: 32 } } />
							<th style={ { width: 56 } } />
							<th>{ __( 'File', 'ux-studio' ) }</th>
							<th>{ __( 'Size', 'ux-studio' ) }</th>
							<th>{ __( 'Uploaded', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( item ) => (
							<tr key={ item.id }>
								<td>
									<input
										type="checkbox"
										checked={ selected.includes( item.id ) }
										onChange={ () => toggle( item.id ) }
										aria-label={ item.filename }
									/>
								</td>
								<td>
									{ item.thumb_url ? (
										<img src={ item.thumb_url } alt="" width={ 40 } height={ 40 } style={ { objectFit: 'cover' } } />
									) : null }
								</td>
								<td>
									{ item.edit_url ? (
										<a href={ item.edit_url } target="_blank" rel="noreferrer">
											{ item.filename || item.title || `#${ item.id }` }
										</a>
									) : (
										item.filename || item.title || `#${ item.id }`
									) }
								</td>
								<td>{ formatSize( item.file_size ) }</td>
								<td>{ item.upload_date.slice( 0, 10 ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }

			{ data && data.total_pages > 1 ? (
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', alignItems: 'center', marginTop: 'var(--uxs-sp-4)' } }>
					<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
						{ __( 'Previous', 'ux-studio' ) }
					</button>
					<span>{ page } / { data.total_pages }</span>
					<button type="button" className="button" disabled={ page >= data.total_pages } onClick={ () => setPage( ( p ) => p + 1 ) }>
						{ __( 'Next', 'ux-studio' ) }
					</button>
				</div>
			) : null }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'bulk' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'image-optimizer' );

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
					{ __( 'Image Optimizer', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'bulk' ? 'is-active' : '' } onClick={ () => setTab( 'bulk' ) }>
					{ __( 'Bulk Optimize', 'ux-studio' ) }
				</button>
				<button className={ tab === 'scanner' ? 'is-active' : '' } onClick={ () => setTab( 'scanner' ) }>
					{ __( 'Unused Images', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'bulk' && <BulkTab /> }
			{ tab === 'scanner' && <ScannerTab /> }
			{ tab === 'settings' && ( isLoading || ! data ) && (
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
