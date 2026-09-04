import { useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, FolderInput, FolderPlus, LoaderCircle, Pencil, Trash2, X } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';

interface Folder {
	id: number;
	name: string;
	parent: number;
	count: number;
}

interface FolderNode extends Folder {
	children: FolderNode[];
	depth: number;
}

interface BulkMoveResult {
	updated: number;
	skipped: number[];
	folder_id: number;
}

const FOLDERS_QUERY_KEY = [ 'folder-manager', 'folders' ];

/**
 * Build a nested tree from the flat folder list, then flatten it back into
 * a depth-ordered array so it can be rendered as an indented list.
 */
function buildTree( folders: Folder[] ): FolderNode[] {
	const byParent = new Map< number, Folder[] >();
	folders.forEach( ( folder ) => {
		const siblings = byParent.get( folder.parent ) ?? [];
		siblings.push( folder );
		byParent.set( folder.parent, siblings );
	} );

	const flat: FolderNode[] = [];
	const walk = ( parentId: number, depth: number ): void => {
		const siblings = byParent.get( parentId ) ?? [];
		siblings.forEach( ( folder ) => {
			flat.push( { ...folder, depth, children: [] } );
			walk( folder.id, depth + 1 );
		} );
	};
	walk( 0, 0 );

	return flat;
}

/**
 * Collect a folder id plus all of its descendant ids, so they can be excluded
 * from a "move under" target list (mirrors the server-side cycle guard).
 */
function selfAndDescendants( folders: Folder[], id: number ): Set< number > {
	const byParent = new Map< number, Folder[] >();
	folders.forEach( ( folder ) => {
		const siblings = byParent.get( folder.parent ) ?? [];
		siblings.push( folder );
		byParent.set( folder.parent, siblings );
	} );

	const blocked = new Set< number >( [ id ] );
	const walk = ( parentId: number ): void => {
		( byParent.get( parentId ) ?? [] ).forEach( ( child ) => {
			blocked.add( child.id );
			walk( child.id );
		} );
	};
	walk( id );

	return blocked;
}

function FolderRow( { row, folders }: { row: FolderNode; folders: Folder[] } ): JSX.Element {
	const [ editing, setEditing ] = useState( false );
	const [ draftName, setDraftName ] = useState( row.name );

	const invalidate = (): void => void queryClient.invalidateQueries( { queryKey: FOLDERS_QUERY_KEY } );

	const rename = useMutation( {
		mutationFn: ( name: string ) =>
			api< Folder >( `folder-manager/folders/${ row.id }`, {
				method: 'PUT',
				body: JSON.stringify( { name } ),
			} ),
		onSuccess: () => {
			setEditing( false );
			invalidate();
		},
	} );

	const reparent = useMutation( {
		mutationFn: ( parent: number ) =>
			api< Folder >( `folder-manager/folders/${ row.id }/move`, {
				method: 'POST',
				body: JSON.stringify( { parent } ),
			} ),
		onSuccess: invalidate,
	} );

	const remove = useMutation( {
		mutationFn: () => api( `folder-manager/folders/${ row.id }`, { method: 'DELETE' } ),
		onSuccess: invalidate,
	} );

	const blocked = useMemo( () => selfAndDescendants( folders, row.id ), [ folders, row.id ] );
	const busy = rename.isPending || reparent.isPending || remove.isPending;

	return (
		<tr>
			<td>
				{ editing ? (
					<span style={ { display: 'inline-flex', gap: 'var(--uxs-sp-2)', alignItems: 'center', paddingLeft: `${ row.depth * 1.5 }em` } }>
						<input
							type="text"
							value={ draftName }
							aria-label={ __( 'Folder name', 'ux-studio' ) }
							onChange={ ( e ) => setDraftName( e.target.value ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' && draftName.trim() ) {
									rename.mutate( draftName.trim() );
								}
								if ( e.key === 'Escape' ) {
									setEditing( false );
									setDraftName( row.name );
								}
							} }
						/>
						<button
							type="button"
							className="button"
							disabled={ ! draftName.trim() || busy }
							aria-label={ __( 'Save name', 'ux-studio' ) }
							onClick={ () => rename.mutate( draftName.trim() ) }
						>
							<Check size={ 14 } />
						</button>
						<button
							type="button"
							className="button"
							disabled={ busy }
							aria-label={ __( 'Cancel', 'ux-studio' ) }
							onClick={ () => {
								setEditing( false );
								setDraftName( row.name );
							} }
						>
							<X size={ 14 } />
						</button>
					</span>
				) : (
					<span style={ { display: 'inline-flex', gap: 'var(--uxs-sp-2)', alignItems: 'center', paddingLeft: `${ row.depth * 1.5 }em` } }>
						{ row.name }
						<button
							type="button"
							className="button-link"
							disabled={ busy }
							aria-label={ __( 'Rename folder', 'ux-studio' ) }
							onClick={ () => {
								setDraftName( row.name );
								setEditing( true );
							} }
						>
							<Pencil size={ 14 } />
						</button>
					</span>
				) }
				{ rename.isError && (
					<span className="uxs-badge is-danger" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
						{ rename.error instanceof Error ? rename.error.message : __( 'Could not rename folder.', 'ux-studio' ) }
					</span>
				) }
			</td>
			<td>
				<span className="uxs-badge">{ row.count }</span>
			</td>
			<td>
				<select
					value={ row.parent }
					disabled={ busy }
					aria-label={ __( 'Move folder under', 'ux-studio' ) }
					onChange={ ( e ) => reparent.mutate( Number( e.target.value ) ) }
				>
					<option value={ 0 }>{ __( '— Top level —', 'ux-studio' ) }</option>
					{ folders
						.filter( ( folder ) => ! blocked.has( folder.id ) )
						.map( ( folder ) => (
							<option key={ folder.id } value={ folder.id }>
								{ folder.name }
							</option>
						) ) }
				</select>
				{ reparent.isError && (
					<span className="uxs-badge is-danger" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
						{ reparent.error instanceof Error ? reparent.error.message : __( 'Could not move folder.', 'ux-studio' ) }
					</span>
				) }
			</td>
			<td>
				<button
					type="button"
					className="button"
					disabled={ busy }
					onClick={ () => {
						if ( window.confirm( __( 'Delete this folder? Attachments in it will become unassigned.', 'ux-studio' ) ) ) {
							remove.mutate();
						}
					} }
				>
					<Trash2 size={ 14 } /> { __( 'Delete', 'ux-studio' ) }
				</button>
			</td>
		</tr>
	);
}

function FolderTree( { folders }: { folders: Folder[] } ): JSX.Element {
	const rows = useMemo( () => buildTree( folders ), [ folders ] );

	if ( rows.length === 0 ) {
		return (
			<p>
				{ __(
					'No folders yet. Create one below, then assign attachments to it — either one by one, or in bulk from the "Bulk move attachments" panel.',
					'ux-studio'
				) }
			</p>
		);
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Name', 'ux-studio' ) }</th>
					<th>{ __( 'Attachments', 'ux-studio' ) }</th>
					<th>{ __( 'Move under', 'ux-studio' ) }</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<FolderRow key={ row.id } row={ row } folders={ folders } />
				) ) }
			</tbody>
		</table>
	);
}

/** Parse a free-form list of attachment ids (commas / spaces / newlines). */
function parseIds( raw: string ): number[] {
	const seen = new Set< number >();
	raw
		.split( /[\s,]+/ )
		.map( ( piece ) => Number.parseInt( piece, 10 ) )
		.forEach( ( n ) => {
			if ( Number.isInteger( n ) && n > 0 ) {
				seen.add( n );
			}
		} );
	return [ ...seen ];
}

export default function Page(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ parent, setParent ] = useState( 0 );
	const [ attachmentId, setAttachmentId ] = useState( '' );
	const [ assignFolder, setAssignFolder ] = useState( 0 );
	const [ bulkIds, setBulkIds ] = useState( '' );
	const [ bulkFolder, setBulkFolder ] = useState( 0 );

	const query = useQuery( {
		queryKey: FOLDERS_QUERY_KEY,
		queryFn: () => api< Folder[] >( 'folder-manager/folders' ),
	} );

	const folders = query.data ?? [];

	const create = useMutation( {
		mutationFn: () =>
			api< Folder >( 'folder-manager/folders', {
				method: 'POST',
				body: JSON.stringify( { name, parent } ),
			} ),
		onSuccess: () => {
			setName( '' );
			setParent( 0 );
			void queryClient.invalidateQueries( { queryKey: FOLDERS_QUERY_KEY } );
		},
	} );

	const assign = useMutation( {
		mutationFn: () =>
			api( 'folder-manager/assign', {
				method: 'POST',
				body: JSON.stringify( { attachment_id: Number( attachmentId ), folder_id: assignFolder } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: FOLDERS_QUERY_KEY } ),
	} );

	const bulkMove = useMutation( {
		mutationFn: () =>
			api< BulkMoveResult >( 'folder-manager/items/move', {
				method: 'POST',
				body: JSON.stringify( { attachment_ids: parseIds( bulkIds ), folder_id: bulkFolder } ),
			} ),
		onSuccess: () => {
			setBulkIds( '' );
			void queryClient.invalidateQueries( { queryKey: FOLDERS_QUERY_KEY } );
		},
	} );

	const bulkCount = parseIds( bulkIds ).length;

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
					{ __( 'Folder Manager', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className="is-active">{ __( 'Folders', 'ux-studio' ) }</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<FolderTree folders={ folders } />
			) }

			<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
				<h2>{ __( 'Add folder', 'ux-studio' ) }</h2>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-name">{ __( 'Name', 'ux-studio' ) }</label>
					<input id="uxs-fm-name" type="text" value={ name } onChange={ ( e ) => setName( e.target.value ) } />
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-parent">{ __( 'Parent folder', 'ux-studio' ) }</label>
					<select
						id="uxs-fm-parent"
						value={ parent }
						onChange={ ( e ) => setParent( Number( e.target.value ) ) }
					>
						<option value={ 0 }>{ __( '— None (top level) —', 'ux-studio' ) }</option>
						{ folders.map( ( folder ) => (
							<option key={ folder.id } value={ folder.id }>
								{ folder.name }
							</option>
						) ) }
					</select>
				</div>
				<button
					type="button"
					className="button button-primary"
					disabled={ ! name.trim() || create.isPending }
					onClick={ () => create.mutate() }
				>
					<FolderPlus size={ 14 } /> { __( 'Add folder', 'ux-studio' ) }
				</button>
				{ create.isError && (
					<p>
						<span className="uxs-badge is-danger">
							{ create.error instanceof Error ? create.error.message : __( 'Could not create folder.', 'ux-studio' ) }
						</span>
					</p>
				) }
			</div>

			<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
				<h2>{ __( 'Assign attachment to a folder', 'ux-studio' ) }</h2>
				<p>
					{ __(
						'Folders are assigned to media via the standard WordPress media library - select attachments there and use this taxonomy, or assign a single attachment by id here.',
						'ux-studio'
					) }
				</p>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-attachment-id">{ __( 'Attachment ID', 'ux-studio' ) }</label>
					<input
						id="uxs-fm-attachment-id"
						type="number"
						min={ 1 }
						value={ attachmentId }
						onChange={ ( e ) => setAttachmentId( e.target.value ) }
					/>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-assign-folder">{ __( 'Folder', 'ux-studio' ) }</label>
					<select
						id="uxs-fm-assign-folder"
						value={ assignFolder }
						onChange={ ( e ) => setAssignFolder( Number( e.target.value ) ) }
					>
						<option value={ 0 }>{ __( '— Unassigned —', 'ux-studio' ) }</option>
						{ folders.map( ( folder ) => (
							<option key={ folder.id } value={ folder.id }>
								{ folder.name }
							</option>
						) ) }
					</select>
				</div>
				<button
					type="button"
					className="button"
					disabled={ ! attachmentId || assign.isPending }
					onClick={ () => assign.mutate() }
				>
					{ __( 'Assign', 'ux-studio' ) }
				</button>
				{ assign.isSuccess && (
					<p>
						<span className="uxs-badge is-success">{ __( 'Assigned.', 'ux-studio' ) }</span>
					</p>
				) }
				{ assign.isError && (
					<p>
						<span className="uxs-badge is-danger">
							{ assign.error instanceof Error ? assign.error.message : __( 'Could not assign attachment.', 'ux-studio' ) }
						</span>
					</p>
				) }
			</div>

			<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
				<h2>{ __( 'Bulk move attachments', 'ux-studio' ) }</h2>
				<p>
					{ __(
						'Paste multiple attachment IDs (separated by spaces, commas or new lines) and move them all to one folder at once. Choose "Unassigned" to remove them from any folder.',
						'ux-studio'
					) }
				</p>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-bulk-ids">{ __( 'Attachment IDs', 'ux-studio' ) }</label>
					<textarea
						id="uxs-fm-bulk-ids"
						rows={ 3 }
						value={ bulkIds }
						onChange={ ( e ) => setBulkIds( e.target.value ) }
					/>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-fm-bulk-folder">{ __( 'Folder', 'ux-studio' ) }</label>
					<select
						id="uxs-fm-bulk-folder"
						value={ bulkFolder }
						onChange={ ( e ) => setBulkFolder( Number( e.target.value ) ) }
					>
						<option value={ 0 }>{ __( '— Unassigned —', 'ux-studio' ) }</option>
						{ folders.map( ( folder ) => (
							<option key={ folder.id } value={ folder.id }>
								{ folder.name }
							</option>
						) ) }
					</select>
				</div>
				<button
					type="button"
					className="button"
					disabled={ bulkCount === 0 || bulkMove.isPending }
					onClick={ () => bulkMove.mutate() }
				>
					<FolderInput size={ 14 } />{ ' ' }
					{ bulkCount > 0
						? // translators: %d: number of attachments selected.
						  __( 'Move', 'ux-studio' ) + ` (${ bulkCount })`
						: __( 'Move', 'ux-studio' ) }
				</button>
				{ bulkMove.isSuccess && bulkMove.data && (
					<p>
						<span className="uxs-badge is-success">
							{
								// translators: %d: number of attachments moved.
								`${ __( 'Moved', 'ux-studio' ) }: ${ bulkMove.data.updated }`
							}
						</span>
						{ bulkMove.data.skipped.length > 0 && (
							<span className="uxs-badge is-danger" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
								{ `${ __( 'Skipped', 'ux-studio' ) }: ${ bulkMove.data.skipped.join( ', ' ) }` }
							</span>
						) }
					</p>
				) }
				{ bulkMove.isError && (
					<p>
						<span className="uxs-badge is-danger">
							{ bulkMove.error instanceof Error ? bulkMove.error.message : __( 'Could not move attachments.', 'ux-studio' ) }
						</span>
					</p>
				) }
			</div>
		</>
	);
}
