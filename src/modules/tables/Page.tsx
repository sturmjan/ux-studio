/**
 * Tables: list of tables + a spreadsheet-like editor (grid of inputs, paste
 * from Excel / Google Sheets, CSV import and export).
 * Route: #/module?id=tables
 */
import { memo, useCallback, useRef, useState } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import {
	ArrowDown,
	ArrowLeft,
	ArrowRight,
	ArrowUp,
	Check,
	Copy,
	Download,
	LoaderCircle,
	Pencil,
	Plus,
	Trash2,
	Upload,
} from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { pushToast } from '../../app/notify';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';
import { colName, parseCsv, parseDelimited, pasteBlock, toCsv } from './grid';
import type { Grid } from './grid';

type Responsive = 'scroll' | 'stack' | 'none';

interface TableOptions {
	head: boolean;
	foot: boolean;
	striped: boolean;
	hover: boolean;
	responsive: Responsive;
	sort: boolean;
	search: boolean;
	paginate: boolean;
	page_size: number;
	caption: string;
	caption_position: 'above' | 'below';
	extra_class: string;
}

interface TableSummary {
	id: number;
	title: string;
	description: string;
	options: TableOptions;
	rows: number;
	cols: number;
	legacy_id: number;
}

interface TableFull extends TableSummary {
	cells: Grid;
}

interface EditorConfig {
	defaults: TableOptions;
	max_rows: number;
	max_cols: number;
	responsive: Record< Responsive, string >;
}

interface Draft {
	id: number;
	title: string;
	description: string;
	cells: Grid;
	options: TableOptions;
}

interface Cursor {
	r: number;
	c: number;
}

function errorMessage( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

function useEditorConfig() {
	return useQuery( { queryKey: [ 'tables', 'editor-config' ], queryFn: () => api< EditorConfig >( 'tables/editor-config' ) } );
}

function shortcode( id: number ): string {
	return `[uxstudio_table id="${ id }"]`;
}

function copyShortcode( id: number ): void {
	void navigator.clipboard.writeText( shortcode( id ) ).then( () => pushToast( 'success', __( 'Shortcode copied.', 'ux-studio' ) ) );
}

function blankGrid( rows: number, cols: number ): Grid {
	return Array.from( { length: rows }, () => Array< string >( cols ).fill( '' ) );
}

function download( filename: string, content: string ): void {
	const url = URL.createObjectURL( new Blob( [ content ], { type: 'text/csv;charset=utf-8' } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}

/* --------------------------------------------------------------------- Grid */

interface RowProps {
	row: string[];
	r: number;
	kind: '' | 'head' | 'foot';
	activeCol: number;
	onFocusCell: ( r: number, c: number ) => void;
	onChangeCell: ( r: number, c: number, value: string ) => void;
	onPasteCell: ( r: number, c: number, e: ClipboardEvent< HTMLInputElement > ) => void;
	onKeyCell: ( r: number, c: number, e: KeyboardEvent< HTMLInputElement > ) => void;
}

/** One grid row; memoized so typing in a cell re-renders only that row. */
const GridRow = memo( function GridRow( { row, r, kind, activeCol, onFocusCell, onChangeCell, onPasteCell, onKeyCell }: RowProps ): JSX.Element {
	return (
		<tr className={ kind ? `uxs-tables-row--${ kind }` : undefined }>
			<th scope="row" className={ activeCol > -1 ? 'is-active' : undefined }>
				{ r + 1 }
			</th>
			{ row.map( ( value, c ) => (
				<td key={ c } className={ c === activeCol ? 'is-active' : undefined }>
					<input
						type="text"
						value={ value }
						data-r={ r }
						data-c={ c }
						aria-label={ sprintf( /* translators: 1: column letter, 2: row number */ __( 'Cell %1$s%2$d', 'ux-studio' ), colName( c ), r + 1 ) }
						onFocus={ () => onFocusCell( r, c ) }
						onChange={ ( e ) => onChangeCell( r, c, e.target.value ) }
						onPaste={ ( e ) => onPasteCell( r, c, e ) }
						onKeyDown={ ( e ) => onKeyCell( r, c, e ) }
					/>
				</td>
			) ) }
		</tr>
	);
} );

/* ------------------------------------------------------------------- Editor */

function TableEditor( { initial, ec, onClose }: { initial: Draft; ec: EditorConfig; onClose: () => void } ): JSX.Element {
	const [ draft, setDraft ] = useState< Draft >( initial );
	const [ cursor, setCursor ] = useState< Cursor >( { r: 0, c: 0 } );
	const [ dirty, setDirty ] = useState( false );
	const gridRef = useRef< HTMLDivElement >( null );
	const fileRef = useRef< HTMLInputElement >( null );
	const cellsRef = useRef< Grid >( initial.cells );

	const { max_rows: maxRows, max_cols: maxCols } = ec;
	const rows = draft.cells.length;
	const cols = draft.cells[ 0 ]?.length ?? 0;
	const opt = draft.options;
	cellsRef.current = draft.cells;

	const setCells = useCallback( ( update: ( cells: Grid ) => Grid ) => {
		setDraft( ( d ) => ( { ...d, cells: update( d.cells ) } ) );
		setDirty( true );
	}, [] );
	const setOptions = ( patch: Partial< TableOptions > ) => {
		setDraft( ( d ) => ( { ...d, options: { ...d.options, ...patch } } ) );
		setDirty( true );
	};

	const focusCell = useCallback( ( r: number, c: number ) => {
		gridRef.current?.querySelector< HTMLInputElement >( `input[data-r="${ r }"][data-c="${ c }"]` )?.focus();
	}, [] );

	const onFocusCell = useCallback( ( r: number, c: number ) => setCursor( ( cur ) => ( cur.r === r && cur.c === c ? cur : { r, c } ) ), [] );

	const onChangeCell = useCallback(
		( r: number, c: number, value: string ) =>
			setCells( ( cells ) => cells.map( ( row, ri ) => ( ri === r ? row.map( ( v, ci ) => ( ci === c ? value : v ) ) : row ) ) ),
		[ setCells ]
	);

	const onPasteCell = useCallback(
		( r: number, c: number, e: ClipboardEvent< HTMLInputElement > ) => {
			const text = e.clipboardData.getData( 'text/plain' );
			if ( ! /[\t\n]/.test( text ) ) {
				return; // One value: let the input paste it normally.
			}
			e.preventDefault();
			const block = parseDelimited( text.replace( /\r?\n$/, '' ), '\t' );
			const next = pasteBlock( cellsRef.current, block, r, c, maxRows, maxCols );
			if ( next === null ) {
				pushToast( 'error', sprintf( /* translators: 1: max rows, 2: max columns */ __( 'The pasted block does not fit. The limit is %1$d rows and %2$d columns.', 'ux-studio' ), maxRows, maxCols ) );
				return;
			}
			setCells( () => next );
			pushToast( 'success', __( 'Pasted into the table.', 'ux-studio' ) );
		},
		[ setCells, maxRows, maxCols ]
	);

	const onKeyCell = useCallback(
		( r: number, c: number, e: KeyboardEvent< HTMLInputElement > ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				focusCell( e.shiftKey ? r - 1 : r + 1, c );
			} else if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				focusCell( r + 1, c );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				focusCell( r - 1, c );
			}
		},
		[ focusCell ]
	);

	/* Structure operations act on the focused cell. */
	const limit = ( what: 'rows' | 'cols' ) =>
		pushToast( 'error', what === 'rows' ? sprintf( __( 'A table can have at most %d rows.', 'ux-studio' ), maxRows ) : sprintf( __( 'A table can have at most %d columns.', 'ux-studio' ), maxCols ) );

	const addRow = ( after: boolean ) => {
		if ( rows >= maxRows ) {
			return limit( 'rows' );
		}
		const at = cursor.r + ( after ? 1 : 0 );
		setCells( ( cells ) => [ ...cells.slice( 0, at ), Array< string >( cols ).fill( '' ), ...cells.slice( at ) ] );
		setCursor( { ...cursor, r: at } );
	};
	const deleteRow = () => {
		if ( rows <= 1 ) {
			return;
		}
		setCells( ( cells ) => cells.filter( ( _, i ) => i !== cursor.r ) );
		setCursor( { ...cursor, r: Math.min( cursor.r, rows - 2 ) } );
	};
	const moveRow = ( dir: -1 | 1 ) => {
		const to = cursor.r + dir;
		if ( to < 0 || to >= rows ) {
			return;
		}
		setCells( ( cells ) => {
			const next = [ ...cells ];
			const a = next[ cursor.r ];
			const b = next[ to ];
			if ( a && b ) {
				next[ cursor.r ] = b;
				next[ to ] = a;
			}
			return next;
		} );
		setCursor( { ...cursor, r: to } );
	};
	const addCol = ( after: boolean ) => {
		if ( cols >= maxCols ) {
			return limit( 'cols' );
		}
		const at = cursor.c + ( after ? 1 : 0 );
		setCells( ( cells ) => cells.map( ( row ) => [ ...row.slice( 0, at ), '', ...row.slice( at ) ] ) );
		setCursor( { ...cursor, c: at } );
	};
	const deleteCol = () => {
		if ( cols <= 1 ) {
			return;
		}
		setCells( ( cells ) => cells.map( ( row ) => row.filter( ( _, i ) => i !== cursor.c ) ) );
		setCursor( { ...cursor, c: Math.min( cursor.c, cols - 2 ) } );
	};
	const moveCol = ( dir: -1 | 1 ) => {
		const to = cursor.c + dir;
		if ( to < 0 || to >= cols ) {
			return;
		}
		setCells( ( cells ) =>
			cells.map( ( row ) => {
				const next = [ ...row ];
				const a = next[ cursor.c ];
				const b = next[ to ];
				if ( a !== undefined && b !== undefined ) {
					next[ cursor.c ] = b;
					next[ to ] = a;
				}
				return next;
			} )
		);
		setCursor( { ...cursor, c: to } );
	};

	const importCsv = async ( file: File ) => {
		const grid = parseCsv( await file.text() );
		const width = grid[ 0 ]?.length ?? 0;
		if ( ! grid.length || ! width ) {
			pushToast( 'error', __( 'The file is empty.', 'ux-studio' ) );
			return;
		}
		if ( grid.length > maxRows || width > maxCols ) {
			pushToast( 'error', sprintf( /* translators: 1: max rows, 2: max columns */ __( 'The file is too large. The limit is %1$d rows and %2$d columns.', 'ux-studio' ), maxRows, maxCols ) );
			return;
		}
		setCells( () => grid );
		setCursor( { r: 0, c: 0 } );
		pushToast( 'success', sprintf( /* translators: 1: rows, 2: columns */ __( 'Imported %1$d rows and %2$d columns. Save the table to keep them.', 'ux-studio' ), grid.length, width ) );
	};

	const save = useMutation( {
		mutationFn: () =>
			api< TableFull >( draft.id ? `tables/${ draft.id }` : 'tables', {
				method: 'POST',
				body: JSON.stringify( { title: draft.title, description: draft.description, cells: draft.cells, options: draft.options } ),
			} ),
		onSuccess: ( saved ) => {
			setDraft( { id: saved.id, title: saved.title, description: saved.description, cells: saved.cells, options: saved.options } );
			setDirty( false );
			void queryClient.invalidateQueries( { queryKey: [ 'tables', 'list' ] } );
			pushToast( 'success', __( 'Table saved.', 'ux-studio' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	const close = () => {
		// eslint-disable-next-line no-alert -- simple confirm, same as other module editors.
		if ( ! dirty || window.confirm( __( 'Discard the unsaved changes?', 'ux-studio' ) ) ) {
			onClose();
		}
	};

	const kindOf = ( r: number ): '' | 'head' | 'foot' => ( opt.head && r === 0 ? 'head' : opt.foot && r === rows - 1 && rows > 1 ? 'foot' : '' );

	return (
		<>
			<div className="uxs-tables-toolbar">
				<button type="button" className="button" onClick={ close }>
					<ArrowLeft size={ 14 } /> { __( 'All tables', 'ux-studio' ) }
				</button>
				<input
					type="text"
					className="uxs-tables-title"
					value={ draft.title }
					placeholder={ __( 'Table name', 'ux-studio' ) }
					aria-label={ __( 'Table name', 'ux-studio' ) }
					onChange={ ( e ) => {
						setDraft( ( d ) => ( { ...d, title: e.target.value } ) );
						setDirty( true );
					} }
				/>
				{ draft.id ? (
					<button type="button" className="button" onClick={ () => copyShortcode( draft.id ) }>
						<Copy size={ 14 } /> <code>{ shortcode( draft.id ) }</code>
					</button>
				) : null }
				<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate() }>
					{ save.isPending ? <LoaderCircle size={ 14 } /> : <Check size={ 14 } /> } { __( 'Save table', 'ux-studio' ) }
				</button>
			</div>

			<div className="uxs-tables-editor">
				<div className="uxs-tables-editor__main">
					<div className="uxs-tables-toolbar uxs-tables-toolbar--tight" role="toolbar" aria-label={ __( 'Table structure', 'ux-studio' ) }>
						<span className="uxs-tables-group">
							<span className="uxs-tables-group__label">{ sprintf( /* translators: %d: row number */ __( 'Row %d', 'ux-studio' ), cursor.r + 1 ) }</span>
							<button type="button" className="button" onClick={ () => addRow( false ) }>
								<Plus size={ 14 } /> { __( 'Insert above', 'ux-studio' ) }
							</button>
							<button type="button" className="button" onClick={ () => addRow( true ) }>
								<Plus size={ 14 } /> { __( 'Insert below', 'ux-studio' ) }
							</button>
							<button type="button" className="button" aria-label={ __( 'Move row up', 'ux-studio' ) } title={ __( 'Move row up', 'ux-studio' ) } onClick={ () => moveRow( -1 ) }>
								<ArrowUp size={ 14 } />
							</button>
							<button type="button" className="button" aria-label={ __( 'Move row down', 'ux-studio' ) } title={ __( 'Move row down', 'ux-studio' ) } onClick={ () => moveRow( 1 ) }>
								<ArrowDown size={ 14 } />
							</button>
							<button type="button" className="button" disabled={ rows <= 1 } onClick={ deleteRow }>
								<Trash2 size={ 14 } /> { __( 'Delete', 'ux-studio' ) }
							</button>
						</span>
						<span className="uxs-tables-group">
							<span className="uxs-tables-group__label">{ sprintf( /* translators: %s: column letter */ __( 'Column %s', 'ux-studio' ), colName( cursor.c ) ) }</span>
							<button type="button" className="button" onClick={ () => addCol( false ) }>
								<Plus size={ 14 } /> { __( 'Insert left', 'ux-studio' ) }
							</button>
							<button type="button" className="button" onClick={ () => addCol( true ) }>
								<Plus size={ 14 } /> { __( 'Insert right', 'ux-studio' ) }
							</button>
							<button type="button" className="button" aria-label={ __( 'Move column left', 'ux-studio' ) } title={ __( 'Move column left', 'ux-studio' ) } onClick={ () => moveCol( -1 ) }>
								<ArrowLeft size={ 14 } />
							</button>
							<button type="button" className="button" aria-label={ __( 'Move column right', 'ux-studio' ) } title={ __( 'Move column right', 'ux-studio' ) } onClick={ () => moveCol( 1 ) }>
								<ArrowRight size={ 14 } />
							</button>
							<button type="button" className="button" disabled={ cols <= 1 } onClick={ deleteCol }>
								<Trash2 size={ 14 } /> { __( 'Delete', 'ux-studio' ) }
							</button>
						</span>
						<span className="uxs-tables-group">
							<button type="button" className="button" onClick={ () => fileRef.current?.click() }>
								<Upload size={ 14 } /> { __( 'Import CSV', 'ux-studio' ) }
							</button>
							<button type="button" className="button" onClick={ () => download( `${ draft.title.trim() || 'table' }.csv`, toCsv( draft.cells ) ) }>
								<Download size={ 14 } /> { __( 'Export CSV', 'ux-studio' ) }
							</button>
							<input
								ref={ fileRef }
								type="file"
								accept=".csv,.tsv,.txt,text/csv,text/plain"
								hidden
								onChange={ ( e ) => {
									const file = e.target.files?.[ 0 ];
									e.target.value = '';
									if ( file ) {
										void importCsv( file );
									}
								} }
							/>
						</span>
					</div>
					<p className="uxs-form__help">
						{ __( 'Copy a block of cells in Excel or Google Sheets, click the cell where it should start and paste. Cells can contain links, line breaks (<br>), bold and italic text and images.', 'ux-studio' ) }
					</p>

					<div className="uxs-tables-grid" ref={ gridRef }>
						<table>
							<thead>
								<tr>
									<th aria-hidden="true" />
									{ Array.from( { length: cols }, ( _, c ) => (
										<th key={ c } scope="col" className={ c === cursor.c ? 'is-active' : undefined }>
											{ colName( c ) }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ draft.cells.map( ( row, r ) => (
									<GridRow
										key={ r }
										row={ row }
										r={ r }
										kind={ kindOf( r ) }
										activeCol={ r === cursor.r ? cursor.c : -1 }
										onFocusCell={ onFocusCell }
										onChangeCell={ onChangeCell }
										onPasteCell={ onPasteCell }
										onKeyCell={ onKeyCell }
									/>
								) ) }
							</tbody>
						</table>
					</div>
					<p className="uxs-form__help">
						{ sprintf( /* translators: 1: rows, 2: columns, 3: max rows, 4: max columns */ __( '%1$d rows x %2$d columns (limit %3$d x %4$d).', 'ux-studio' ), rows, cols, maxRows, maxCols ) }
					</p>
				</div>

				<div className="uxs-tables-editor__side uxs-form">
					<h3>{ __( 'Table options', 'ux-studio' ) }</h3>
					<div className="uxs-tables-fields">
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.head } onChange={ ( e ) => setOptions( { head: e.target.checked } ) } />
							{ __( 'The first row is a header', 'ux-studio' ) }
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.foot } onChange={ ( e ) => setOptions( { foot: e.target.checked } ) } />
							{ __( 'The last row is a footer', 'ux-studio' ) }
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.striped } onChange={ ( e ) => setOptions( { striped: e.target.checked } ) } />
							{ __( 'Striped rows', 'ux-studio' ) }
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.hover } onChange={ ( e ) => setOptions( { hover: e.target.checked } ) } />
							{ __( 'Highlight the row under the mouse', 'ux-studio' ) }
						</label>
						<label>
							{ __( 'On narrow screens', 'ux-studio' ) }
							<select value={ opt.responsive } onChange={ ( e ) => setOptions( { responsive: e.target.value as Responsive } ) }>
								{ ( Object.keys( ec.responsive ) as Responsive[] ).map( ( key ) => (
									<option key={ key } value={ key }>
										{ ec.responsive[ key ] }
									</option>
								) ) }
							</select>
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.sort } disabled={ ! opt.head } onChange={ ( e ) => setOptions( { sort: e.target.checked } ) } />
							{ __( 'Visitors can sort by a column (needs a header row)', 'ux-studio' ) }
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.search } onChange={ ( e ) => setOptions( { search: e.target.checked } ) } />
							{ __( 'Show a search box', 'ux-studio' ) }
						</label>
						<label className="uxs-tables-check">
							<input type="checkbox" checked={ opt.paginate } onChange={ ( e ) => setOptions( { paginate: e.target.checked } ) } />
							{ __( 'Split into pages', 'ux-studio' ) }
						</label>
						{ opt.paginate ? (
							<label>
								{ __( 'Rows per page', 'ux-studio' ) }
								<input type="number" min={ 1 } max={ 200 } value={ opt.page_size } onChange={ ( e ) => setOptions( { page_size: Math.max( 1, Number( e.target.value ) ) } ) } />
							</label>
						) : null }
						<label>
							{ __( 'Caption', 'ux-studio' ) }
							<input type="text" value={ opt.caption } onChange={ ( e ) => setOptions( { caption: e.target.value } ) } />
						</label>
						{ opt.caption ? (
							<label>
								{ __( 'Caption position', 'ux-studio' ) }
								<select value={ opt.caption_position } onChange={ ( e ) => setOptions( { caption_position: e.target.value === 'below' ? 'below' : 'above' } ) }>
									<option value="above">{ __( 'Above the table', 'ux-studio' ) }</option>
									<option value="below">{ __( 'Below the table', 'ux-studio' ) }</option>
								</select>
							</label>
						) : null }
						<label>
							{ __( 'Extra CSS class', 'ux-studio' ) }
							<input type="text" value={ opt.extra_class } onChange={ ( e ) => setOptions( { extra_class: e.target.value } ) } />
						</label>
						<label>
							{ __( 'Description (internal note)', 'ux-studio' ) }
							<textarea
								rows={ 3 }
								value={ draft.description }
								onChange={ ( e ) => {
									setDraft( ( d ) => ( { ...d, description: e.target.value } ) );
									setDirty( true );
								} }
							/>
						</label>
					</div>
				</div>
			</div>
		</>
	);
}

/* --------------------------------------------------------------------- List */

function TablesTab(): JSX.Element {
	const ec = useEditorConfig();
	const [ editing, setEditing ] = useState< Draft | null >( null );
	const fileRef = useRef< HTMLInputElement >( null );
	const tables = useQuery( { queryKey: [ 'tables', 'list' ], queryFn: () => api< TableSummary[] >( 'tables' ) } );

	const refresh = () => queryClient.invalidateQueries( { queryKey: [ 'tables', 'list' ] } );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `tables/${ id }`, { method: 'DELETE' } ),
		onSuccess: refresh,
	} );
	const duplicate = useMutation( {
		mutationFn: ( id: number ) => api< TableFull >( `tables/${ id }/duplicate`, { method: 'POST' } ),
		onSuccess: () => {
			void refresh();
			pushToast( 'success', __( 'Table duplicated.', 'ux-studio' ) );
		},
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );
	const open = useMutation( {
		mutationFn: ( id: number ) => api< TableFull >( `tables/${ id }` ),
		onSuccess: ( t ) => setEditing( { id: t.id, title: t.title, description: t.description, cells: t.cells, options: t.options } ),
		onError: ( e ) => pushToast( 'error', errorMessage( e ) ),
	} );

	if ( ec.isLoading || tables.isLoading || ! ec.data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( editing ) {
		return <TableEditor key={ editing.id || 'new' } initial={ editing } ec={ ec.data } onClose={ () => setEditing( null ) } />;
	}

	const defaults = ec.data.defaults;
	const list = tables.data ?? [];

	const importNew = async ( file: File ) => {
		const grid = parseCsv( await file.text() );
		const width = grid[ 0 ]?.length ?? 0;
		if ( ! grid.length || ! width ) {
			pushToast( 'error', __( 'The file is empty.', 'ux-studio' ) );
			return;
		}
		if ( ec.data && ( grid.length > ec.data.max_rows || width > ec.data.max_cols ) ) {
			pushToast( 'error', sprintf( /* translators: 1: max rows, 2: max columns */ __( 'The file is too large. The limit is %1$d rows and %2$d columns.', 'ux-studio' ), ec.data.max_rows, ec.data.max_cols ) );
			return;
		}
		setEditing( { id: 0, title: file.name.replace( /\.[^.]+$/, '' ), description: '', cells: grid, options: defaults } );
	};

	return (
		<>
			<div className="uxs-tables-toolbar">
				<button type="button" className="button button-primary" onClick={ () => setEditing( { id: 0, title: '', description: '', cells: blankGrid( 4, 3 ), options: defaults } ) }>
					<Plus size={ 14 } /> { __( 'New table', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => fileRef.current?.click() }>
					<Upload size={ 14 } /> { __( 'New table from CSV', 'ux-studio' ) }
				</button>
				<input
					ref={ fileRef }
					type="file"
					accept=".csv,.tsv,.txt,text/csv,text/plain"
					hidden
					onChange={ ( e ) => {
						const file = e.target.files?.[ 0 ];
						e.target.value = '';
						if ( file ) {
							void importNew( file );
						}
					} }
				/>
			</div>
			{ list.length ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Shortcode', 'ux-studio' ) }</th>
							<th>{ __( 'Size', 'ux-studio' ) }</th>
							<th aria-label={ __( 'Actions', 'ux-studio' ) } />
						</tr>
					</thead>
					<tbody>
						{ list.map( ( t ) => (
							<tr key={ t.id }>
								<td>
									<button type="button" className="button-link" onClick={ () => open.mutate( t.id ) }>
										{ t.title }
									</button>
									{ t.legacy_id ? <span className="uxs-badge" title={ `[table id="${ t.legacy_id }" /]` }>TablePress { t.legacy_id }</span> : null }
								</td>
								<td>
									<button type="button" className="button-link" onClick={ () => copyShortcode( t.id ) } title={ __( 'Copy', 'ux-studio' ) }>
										<code>{ shortcode( t.id ) }</code> <Copy size={ 12 } />
									</button>
								</td>
								<td>{ sprintf( /* translators: 1: rows, 2: columns */ __( '%1$d x %2$d', 'ux-studio' ), t.rows, t.cols ) }</td>
								<td style={ { textAlign: 'right', whiteSpace: 'nowrap' } }>
									<button type="button" className="button-link" aria-label={ __( 'Edit table', 'ux-studio' ) } onClick={ () => open.mutate( t.id ) }>
										<Pencil size={ 16 } />
									</button>{ ' ' }
									<button type="button" className="button-link" aria-label={ __( 'Duplicate table', 'ux-studio' ) } disabled={ duplicate.isPending } onClick={ () => duplicate.mutate( t.id ) }>
										<Copy size={ 16 } />
									</button>{ ' ' }
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete table', 'ux-studio' ) }
										style={ { color: 'var(--uxs-danger)' } }
										onClick={ () => {
											// eslint-disable-next-line no-alert -- simple confirm, same as other module lists.
											if ( window.confirm( __( 'Delete this table? Pages using its shortcode will show nothing.', 'ux-studio' ) ) ) {
												remove.mutate( t.id );
											}
										} }
									>
										<Trash2 size={ 16 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				<p>{ __( 'No tables yet. Create one and paste its shortcode into a page (Elementor: Shortcode widget).', 'ux-studio' ) }</p>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< 'tables' | 'settings' >( 'tables' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'tables' );

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
					{ __( 'Tables', 'ux-studio' ) }
				</h1>
				{ tab === 'settings' && (
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () =>
							save.mutate( undefined, {
								onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'tables', 'editor-config' ] } ),
							} )
						}
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				) }
			</header>
			<TabsNav>
				<button className={ tab === 'tables' ? 'is-active' : '' } onClick={ () => setTab( 'tables' ) }>
					{ __( 'Tables', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'tables' && <TablesTab /> }
			{ tab === 'settings' &&
				( isLoading || ! data ? (
					<div className="uxs-loading">
						<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
					</div>
				) : (
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
				) ) }
		</>
	);
}
