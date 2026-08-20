import { useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, FolderPlus, LoaderCircle, Trash2 } from 'lucide-react';
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

function FolderTree( { folders }: { folders: Folder[] } ): JSX.Element {
	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `folder-manager/folders/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: FOLDERS_QUERY_KEY } ),
	} );

	const rows = useMemo( () => buildTree( folders ), [ folders ] );

	if ( rows.length === 0 ) {
		return (
			<p>
				{ __(
					'No folders yet. Create one below, then open the standard WordPress media library, bulk-select attachments and assign them to a folder from this screen (or the "Assign attachment" panel below).',
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
					<th></th>
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.id }>
						<td>
							<span style={ { paddingLeft: `${ row.depth * 1.5 }em` } }>{ row.name }</span>
						</td>
						<td>
							<span className="uxs-badge">{ row.count }</span>
						</td>
						<td>
							<button
								type="button"
								className="button"
								disabled={ remove.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete this folder? Attachments in it will become unassigned.', 'ux-studio' ) ) ) {
										remove.mutate( row.id );
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
	);
}

export default function Page(): JSX.Element {
	const [ name, setName ] = useState( '' );
	const [ parent, setParent ] = useState( 0 );
	const [ attachmentId, setAttachmentId ] = useState( '' );
	const [ assignFolder, setAssignFolder ] = useState( 0 );

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
		</>
	);
}
