import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, Copy, LoaderCircle, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'files' | 'settings';

interface DownloadFile {
	id: number;
	created_at: string;
	title: string;
	attachment_id: number;
	download_count: number;
	require_login: boolean;
	download_url: string;
}

interface WpMediaAttachment {
	id: number;
	url: string;
	filename?: string;
	title?: string;
}

interface WpMedia {
	open: () => void;
	on: ( ev: string, cb: () => void ) => void;
	state: () => { get: ( k: string ) => { first: () => { toJSON: () => WpMediaAttachment } } };
}

/**
 * File picker backed by the WP media modal, restricted only in that it opens
 * the standard media library (no custom upload endpoint) - any file type is
 * selectable, matching the "protect an existing media library file" flow.
 */
function AttachmentPicker( {
	attachmentId,
	onSelect,
}: {
	attachmentId: number;
	onSelect: ( attachment: WpMediaAttachment ) => void;
} ): JSX.Element {
	const wp = ( window as unknown as { wp?: { media?: ( opts: unknown ) => WpMedia } } ).wp;

	const pick = (): void => {
		if ( ! wp?.media ) {
			return;
		}
		const frame = wp.media( {
			title: __( 'Select a file to protect', 'ux-studio' ),
			multiple: false,
			library: {},
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onSelect( att );
		} );
		frame.open();
	};

	return (
		<button type="button" className="button" onClick={ pick }>
			{ attachmentId ? __( 'Change file', 'ux-studio' ) : __( 'Select file', 'ux-studio' ) }
		</button>
	);
}

function AddFileForm(): JSX.Element {
	const [ title, setTitle ] = useState( '' );
	const [ attachment, setAttachment ] = useState< WpMediaAttachment | null >( null );
	const [ requireLogin, setRequireLogin ] = useState( true );

	const create = useMutation( {
		mutationFn: () =>
			api< DownloadFile >( 'download-files/items', {
				method: 'POST',
				body: JSON.stringify( {
					title,
					attachment_id: attachment?.id ?? 0,
					require_login: requireLogin,
				} ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'download-files', 'items' ] } );
			setTitle( '' );
			setAttachment( null );
			setRequireLogin( true );
		},
	} );

	const canSubmit = title.trim() !== '' && !! attachment?.id && ! create.isPending;

	return (
		<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
			<div className="uxs-form__row">
				<label htmlFor="uxs-df-title">{ __( 'Title', 'ux-studio' ) }</label>
				<input
					id="uxs-df-title"
					type="text"
					value={ title }
					onChange={ ( e ) => setTitle( e.target.value ) }
					placeholder={ __( 'e.g. Price list 2026', 'ux-studio' ) }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'File', 'ux-studio' ) }</label>
				<span className="uxs-media">
					<AttachmentPicker attachmentId={ attachment?.id ?? 0 } onSelect={ setAttachment } />
					{ attachment ? (
						<span>
							{ ' ' }
							{ attachment.filename ?? attachment.title ?? attachment.url }
						</span>
					) : null }
				</span>
			</div>
			<div className="uxs-form__row">
				<label htmlFor="uxs-df-require-login">{ __( 'Require login to download', 'ux-studio' ) }</label>
				<button
					id="uxs-df-require-login"
					type="button"
					className={ `uxs-switch${ requireLogin ? ' is-on' : '' }` }
					aria-pressed={ requireLogin }
					aria-label={ __( 'Require login to download', 'ux-studio' ) }
					onClick={ () => setRequireLogin( ( v ) => ! v ) }
				/>
			</div>
			<button type="button" className="button button-primary" disabled={ ! canSubmit } onClick={ () => create.mutate() }>
				{ create.isPending ? <LoaderCircle size={ 14 } /> : null } { __( 'Add file', 'ux-studio' ) }
			</button>
			{ create.isError ? (
				<p className="uxs-form__help">{ ( create.error as Error ).message }</p>
			) : null }
		</div>
	);
}

function FilesTable(): JSX.Element {
	const [ copiedId, setCopiedId ] = useState< number | null >( null );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'download-files', 'items' ],
		queryFn: () => api< DownloadFile[] >( 'download-files/items' ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `download-files/items/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'download-files', 'items' ] } );
		},
	} );

	const copyLink = ( file: DownloadFile ): void => {
		void navigator.clipboard.writeText( file.download_url ).then( () => {
			setCopiedId( file.id );
			window.setTimeout( () => setCopiedId( null ), 2000 );
		} );
	};

	return (
		<>
			<AddFileForm />
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No protected files yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'Downloads', 'ux-studio' ) }</th>
							<th>{ __( 'Login required', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( file ) => (
							<tr key={ file.id }>
								<td>{ file.title }</td>
								<td>{ file.download_count }</td>
								<td>
									<span className={ `uxs-badge ${ file.require_login ? 'is-success' : '' }` }>
										{ file.require_login ? __( 'Yes', 'ux-studio' ) : __( 'No', 'ux-studio' ) }
									</span>
								</td>
								<td>
									<button type="button" className="button" onClick={ () => copyLink( file ) }>
										{ copiedId === file.id ? <Check size={ 14 } /> : <Copy size={ 14 } /> }{ ' ' }
										{ copiedId === file.id ? __( 'Copied', 'ux-studio' ) : __( 'Copy link', 'ux-studio' ) }
									</button>{ ' ' }
									<button
										type="button"
										className="button"
										disabled={ remove.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Delete this file entry? The media library file itself is kept.', 'ux-studio' ) ) ) {
												remove.mutate( file.id );
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
			) : null }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'files' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'download-files' );

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
					{ __( 'Download Files', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'files' ? 'is-active' : '' } onClick={ () => setTab( 'files' ) }>
					{ __( 'Files', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'files' && <FilesTable /> }
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
