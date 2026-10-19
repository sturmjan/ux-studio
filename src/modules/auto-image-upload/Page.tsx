/**
 * Auto Image Upload: settings + "Bulk fix" tool that walks all posts in small
 * batches and sideloads external images into the media library.
 * Route: #/module?id=auto-image-upload
 */
import { useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';

type Tab = 'settings' | 'bulk';

interface BulkBatch {
	processed: number;
	updated: number;
	updated_ids: number[];
	total: number;
	done: number;
	next_offset: number;
	is_complete: boolean;
}

function BulkFix(): JSX.Element {
	const [ running, setRunning ] = useState( false );
	const [ finished, setFinished ] = useState( false );
	const [ total, setTotal ] = useState( 0 );
	const [ done, setDone ] = useState( 0 );
	const [ updated, setUpdated ] = useState( 0 );
	const [ log, setLog ] = useState< string[] >( [] );
	const [ error, setError ] = useState( '' );
	const stopRef = useRef( false );

	const run = async (): Promise< void > => {
		setRunning( true );
		setFinished( false );
		setError( '' );
		setLog( [] );
		setTotal( 0 );
		setDone( 0 );
		setUpdated( 0 );
		stopRef.current = false;

		let offset = 0;
		let updatedTotal = 0;
		try {
			for ( ;; ) {
				const batch = await api< BulkBatch >( 'auto-image-upload/bulk', {
					method: 'POST',
					body: JSON.stringify( { offset } ),
				} );
				updatedTotal += batch.updated;
				setTotal( batch.total );
				setDone( batch.done );
				setUpdated( updatedTotal );
				if ( batch.updated_ids.length > 0 ) {
					setLog( ( l ) => [
						...l,
						sprintf(
							/* translators: %s: comma-separated post IDs */
							__( 'Updated posts: %s', 'ux-studio' ),
							batch.updated_ids.join( ', ' )
						),
					] );
				}
				offset = batch.next_offset;
				if ( batch.is_complete || batch.processed === 0 ) {
					setFinished( true );
					break;
				}
				if ( stopRef.current ) {
					break;
				}
			}
		} catch ( e ) {
			setError( e instanceof Error ? e.message : __( 'Request failed.', 'ux-studio' ) );
		} finally {
			setRunning( false );
		}
	};

	const percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;

	return (
		<div className="uxs-form">
			<p>
				{ __(
					'Goes through all posts, pages and custom post types, finds images that point to other sites (or data: URLs) and uploads them to the media library. Content is rewritten to point to the local copy.',
					'ux-studio'
				) }
			</p>
			<p>
				<strong>{ __( 'Tip:', 'ux-studio' ) }</strong> { __( 'Make a database backup before you start.', 'ux-studio' ) }
			</p>
			<div>
				<button type="button" className="button button-primary" disabled={ running } onClick={ () => void run() }>
					{ running ? <LoaderCircle size={ 14 } aria-hidden="true" /> : null }{ ' ' }
					{ __( 'Run bulk fix', 'ux-studio' ) }
				</button>{ ' ' }
				{ running ? (
					<button type="button" className="button" onClick={ () => ( stopRef.current = true ) }>
						{ __( 'Stop', 'ux-studio' ) }
					</button>
				) : null }
			</div>
			{ running || done > 0 || finished ? (
				<div>
					<div
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-valuenow={ percent }
						style={ { background: 'var(--uxs-border)', borderRadius: 4, overflow: 'hidden', height: 20 } }
					>
						<div style={ { background: 'var(--uxs-brand)', height: '100%', width: `${ percent }%`, transition: 'width .2s' } } />
					</div>
					<p className="uxs-form__help" role="status">
						{ finished
							? sprintf(
									/* translators: 1: processed posts, 2: updated posts */
									__( 'Done. Checked %1$d posts, updated %2$d.', 'ux-studio' ),
									done,
									updated
							  )
							: sprintf(
									/* translators: 1: processed posts, 2: total posts, 3: updated posts */
									__( 'Processed %1$d of %2$d posts, updated %3$d…', 'ux-studio' ),
									done,
									total,
									updated
							  ) }
					</p>
					{ log.length > 0 ? (
						<pre style={ { maxHeight: 240, overflow: 'auto', fontSize: 12 } }>{ log.join( '\n' ) }</pre>
					) : null }
				</div>
			) : null }
			{ error ? (
				<div className="uxs-badge is-danger" role="alert" style={ { display: 'block', padding: 'var(--uxs-sp-2)' } }>
					{ error }
				</div>
			) : null }
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'settings' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'auto-image-upload' );

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
					{ __( 'Auto Image Upload', 'ux-studio' ) }
				</h1>
				{ tab === 'settings' && (
					<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate() }>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<TabsNav>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
				<button className={ tab === 'bulk' ? 'is-active' : '' } onClick={ () => setTab( 'bulk' ) }>
					{ __( 'Bulk fix', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
				) ) }
			{ tab === 'bulk' && <BulkFix /> }
		</>
	);
}
