import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, RotateCcw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'bulk' | 'settings';

interface BulkStatus {
	queued: number;
	total: number;
	done: number;
	running: boolean;
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
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<p>
					{ __(
						'Compresses JPEG/PNG/GIF images already in the media library and optionally generates WebP siblings. Originals are always kept as a backup file before the first optimization.',
						'ux-studio'
					) }
				</p>
				<button type="button" className="button button-primary" disabled={ start.isPending || !! data?.running } onClick={ () => start.mutate() }>
					{ start.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Start bulk optimization', 'ux-studio' ) }
				</button>
				{ data ? (
					<p>
						{ data.running
							? __( 'Running…', 'ux-studio' )
							: __( 'Idle.', 'ux-studio' ) }
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
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'bulk' && <BulkTab /> }
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
