/**
 * Shared file upload field: drag & drop zone + "choose file(s)" trigger,
 * built on react-aria-components `<DropZone>`/`<FileTrigger>` (PLAN.md
 * 20.4b). Renders thumbnail previews for images and a generic icon for
 * everything else, with per-file removal before upload. Actual upload
 * validation (MIME/size/allowlist) always happens server-side - this is UX
 * only, see PLAN.md 20.8.
 */
import { useEffect, useMemo } from 'react';
import { Button, DropZone, FileTrigger, Text } from 'react-aria-components';
import { File as FileIcon, Upload, X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

interface FileUploadFieldProps {
	files: File[];
	onChange: ( files: File[] ) => void;
	accept?: string[];
	multiple?: boolean;
	hint?: string;
}

function FilePreview( { file, onRemove }: { file: File; onRemove: () => void } ): JSX.Element {
	const url = useMemo( () => ( file.type.startsWith( 'image/' ) ? URL.createObjectURL( file ) : '' ), [ file ] );
	useEffect( () => () => {
		if ( url ) {
			URL.revokeObjectURL( url );
		}
	}, [ url ] );

	return (
		<li className="uxs-fileupload__item">
			{ url ? (
				<img src={ url } alt="" className="uxs-fileupload__thumb" />
			) : (
				<span className="uxs-fileupload__icon">
					<FileIcon size={ 18 } />
				</span>
			) }
			<span className="uxs-fileupload__name">{ file.name }</span>
			<span className="uxs-fileupload__size">{ Math.round( file.size / 1024 ) } KB</span>
			<Button aria-label={ __( 'Remove file', 'ux-studio' ) } onPress={ onRemove } className="uxs-fileupload__remove">
				<X size={ 14 } />
			</Button>
		</li>
	);
}

export default function FileUploadField( { files, onChange, accept, multiple, hint }: FileUploadFieldProps ): JSX.Element {
	function addFiles( incoming: FileList | Iterable< File > | null ) {
		if ( ! incoming ) {
			return;
		}
		const list = Array.from( incoming );
		onChange( multiple ? [ ...files, ...list ] : list.slice( 0, 1 ) );
	}

	return (
		<div className="uxs-fileupload">
			<DropZone
				className="uxs-fileupload__zone"
				onDrop={ ( e ) => {
					const dropped = e.items
						.filter( ( item ): item is typeof item & { kind: 'file' } => item.kind === 'file' )
						.map( ( item ) => item.getFile() );
					void Promise.all( dropped ).then( addFiles );
				} }
			>
				<Upload size={ 22 } aria-hidden="true" />
				<Text slot="label" className="uxs-fileupload__zone-label">
					{ hint || __( 'Drag & drop a file here', 'ux-studio' ) }
				</Text>
				<FileTrigger acceptedFileTypes={ accept } allowsMultiple={ multiple } onSelect={ addFiles }>
					<Button className="uxs-fileupload__browse">{ __( 'Choose file(s)', 'ux-studio' ) }</Button>
				</FileTrigger>
			</DropZone>

			{ files.length > 0 ? (
				<ul className="uxs-fileupload__list">
					{ files.map( ( file, index ) => (
						<FilePreview
							key={ `${ file.name }-${ file.size }-${ index }` }
							file={ file }
							onRemove={ () => onChange( files.filter( ( _, i ) => i !== index ) ) }
						/>
					) ) }
				</ul>
			) : null }
		</div>
	);
}
