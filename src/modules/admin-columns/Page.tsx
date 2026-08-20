/**
 * Admin Columns: per-content-type column builder. Backed by
 * uxstudio/v1/admin-columns/{content-types,configured,config/{type}} — the
 * REST layer and the native manage_* rendering (ColumnRenderer.php) already
 * existed; this page is the missing configuration UI for them.
 *
 * Accordion, not tabs: only content types that already have columns are
 * shown (seeded from /configured), each in its own collapsible, independently
 * saved section, open by default. Use "Add content type" to start configuring
 * a type that has no columns yet.
 */
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import {
	AlertTriangle,
	ArrowDown,
	ArrowLeft,
	ArrowUp,
	Check,
	ChevronRight,
	LoaderCircle,
	Plus,
	X,
} from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';

interface ContentType {
	id: string;
	label: string;
}

interface ConfiguredType {
	post_type: string;
	label: string;
	columns: Column[];
}

interface Column {
	key: string;
	label: string;
	type: 'default' | 'meta' | 'taxonomy' | 'post_id' | 'thumbnail';
	enabled: boolean;
	width: string;
}

interface KeySuggestion {
	key: string;
	label: string;
}

type SuggestionsByColumnType = Record< Column[ 'type' ], KeySuggestion[] >;

const EMPTY_SUGGESTIONS: SuggestionsByColumnType = {
	default: [],
	meta: [],
	taxonomy: [],
	post_id: [],
	thumbnail: [],
};

const TYPE_LABELS: Record< Column[ 'type' ], string > = {
	default: __( 'Default (native column)', 'ux-studio' ),
	meta: __( 'Custom field (meta)', 'ux-studio' ),
	taxonomy: __( 'Taxonomy', 'ux-studio' ),
	post_id: __( 'ID', 'ux-studio' ),
	thumbnail: __( 'Thumbnail', 'ux-studio' ),
};

const KEY_HELP: Record< Column[ 'type' ], string > = {
	default: __( 'Key of an existing column — start typing to see suggestions.', 'ux-studio' ),
	meta: __( 'Custom field (meta) key — start typing to see suggestions.', 'ux-studio' ),
	taxonomy: __( 'Taxonomy slug — start typing to see suggestions.', 'ux-studio' ),
	post_id: __( 'Any unique column key.', 'ux-studio' ),
	thumbnail: __( 'Any unique column key.', 'ux-studio' ),
};

function emptyColumn(): Column {
	return { key: '', label: '', type: 'default', enabled: true, width: '' };
}

function ColumnRow( {
	column,
	onChange,
	onRemove,
	onMove,
	isFirst,
	isLast,
	datalistId,
	suggestions,
}: {
	column: Column;
	onChange: ( next: Column ) => void;
	onRemove: () => void;
	onMove: ( dir: -1 | 1 ) => void;
	isFirst: boolean;
	isLast: boolean;
	datalistId: string;
	suggestions: KeySuggestion[];
} ): JSX.Element {
	const keyMissing = column.key.trim() === '';

	return (
		<tr className={ keyMissing ? 'is-invalid' : '' }>
			<td>
				<div style={ { display: 'flex', gap: 'var(--uxs-sp-1)' } }>
					<button type="button" className="button" disabled={ isFirst } onClick={ () => onMove( -1 ) } aria-label={ __( 'Move up', 'ux-studio' ) }>
						<ArrowUp size={ 14 } aria-hidden />
					</button>
					<button type="button" className="button" disabled={ isLast } onClick={ () => onMove( 1 ) } aria-label={ __( 'Move down', 'ux-studio' ) }>
						<ArrowDown size={ 14 } aria-hidden />
					</button>
				</div>
			</td>
			<td>
				<input
					type="text"
					value={ column.label }
					placeholder={ __( 'Column title', 'ux-studio' ) }
					onChange={ ( e ) => onChange( { ...column, label: e.target.value } ) }
				/>
			</td>
			<td>
				<select
					value={ column.type }
					onChange={ ( e ) => onChange( { ...column, type: e.target.value as Column[ 'type' ] } ) }
				>
					{ Object.entries( TYPE_LABELS ).map( ( [ value, label ] ) => (
						<option key={ value } value={ value }>
							{ label }
						</option>
					) ) }
				</select>
			</td>
			<td>
				<input
					type="text"
					value={ column.key }
					placeholder={ KEY_HELP[ column.type ] }
					list={ suggestions.length > 0 ? datalistId : undefined }
					onChange={ ( e ) => onChange( { ...column, key: e.target.value } ) }
				/>
				{ suggestions.length > 0 && (
					<datalist id={ datalistId }>
						{ suggestions.map( ( s ) => (
							<option key={ s.key } value={ s.key }>
								{ s.label !== s.key ? s.label : undefined }
							</option>
						) ) }
					</datalist>
				) }
				{ keyMissing && (
					<span className="uxs-inline-warning">
						<AlertTriangle size={ 12 } aria-hidden />
						{ __( 'Required — a column with no key is discarded when you save.', 'ux-studio' ) }
					</span>
				) }
			</td>
			<td>
				<input
					type="text"
					value={ column.width }
					placeholder={ __( 'e.g. 80px', 'ux-studio' ) }
					style={ { width: '90px' } }
					onChange={ ( e ) => onChange( { ...column, width: e.target.value } ) }
				/>
			</td>
			<td>
				<button
					type="button"
					className={ `uxs-switch${ column.enabled ? ' is-on' : '' }` }
					aria-pressed={ column.enabled }
					aria-label={ column.enabled ? __( 'Disable column', 'ux-studio' ) : __( 'Enable column', 'ux-studio' ) }
					onClick={ () => onChange( { ...column, enabled: ! column.enabled } ) }
				/>
			</td>
			<td>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Delete column', 'ux-studio' ) }>
					<X size={ 16 } aria-hidden />
				</button>
			</td>
		</tr>
	);
}

function AccordionSection( {
	type,
	draft,
	expanded,
	onToggle,
	onRemoveSection,
	setDraft,
	canRemoveSection,
}: {
	type: ContentType;
	draft: Column[];
	expanded: boolean;
	onToggle: () => void;
	onRemoveSection: () => void;
	setDraft: ( updater: ( rows: Column[] ) => Column[] ) => void;
	canRemoveSection: boolean;
} ): JSX.Element {
	const [ saved, setSaved ] = useState( false );

	const suggestions = useQuery( {
		queryKey: [ 'admin-columns', 'keys', type.id ],
		queryFn: async (): Promise< SuggestionsByColumnType > => {
			const [ defaultKeys, metaKeys, taxonomyKeys ] = await Promise.all( [
				api< KeySuggestion[] >( `admin-columns/keys/${ type.id }?column_type=default` ),
				api< KeySuggestion[] >( `admin-columns/keys/${ type.id }?column_type=meta` ),
				api< KeySuggestion[] >( `admin-columns/keys/${ type.id }?column_type=taxonomy` ),
			] );
			return { ...EMPTY_SUGGESTIONS, default: defaultKeys, meta: metaKeys, taxonomy: taxonomyKeys };
		},
		enabled: expanded,
		staleTime: 5 * 60_000,
	} );

	const save = useMutation( {
		mutationFn: () =>
			api( `admin-columns/config/${ type.id }`, {
				method: 'POST',
				body: JSON.stringify( { columns: draft } ),
			} ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'admin-columns', 'configured' ] } );
			setSaved( true );
			window.setTimeout( () => setSaved( false ), 2000 );
		},
	} );

	const updateAt = ( index: number, next: Column ): void =>
		setDraft( ( rows ) => rows.map( ( r, i ) => ( i === index ? next : r ) ) );

	const removeAt = ( index: number ): void =>
		setDraft( ( rows ) => rows.filter( ( _r, i ) => i !== index ) );

	const moveAt = ( index: number, dir: -1 | 1 ): void =>
		setDraft( ( rows ) => {
			const target = index + dir;
			if ( target < 0 || target >= rows.length ) {
				return rows;
			}
			const next = [ ...rows ];
			[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
			return next;
		} );

	return (
		<div className={ `uxs-accordion__section${ expanded ? ' is-open' : '' }` }>
			<button type="button" className="uxs-accordion__header" onClick={ onToggle }>
				<span className="uxs-accordion__header-left">
					<ChevronRight size={ 16 } className="uxs-accordion__chevron" aria-hidden />
					{ type.label }
					{ draft.length > 0 && <span className="uxs-badge">{ draft.length }</span> }
				</span>
				{ canRemoveSection && draft.length === 0 && (
					<span
						role="button"
						tabIndex={ 0 }
						className="uxs-accordion__remove"
						aria-label={ __( 'Remove section', 'ux-studio' ) }
						onClick={ ( e ) => {
							e.stopPropagation();
							onRemoveSection();
						} }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.stopPropagation();
								onRemoveSection();
							}
						} }
					>
						<X size={ 16 } aria-hidden />
					</span>
				) }
			</button>
			{ expanded && (
				<div className="uxs-accordion__body">
					{ draft.length === 0 ? (
						<p>
							{ __(
								'No custom columns yet — the native columns are shown unchanged. Add one below to relabel, reorder, hide or add a column.',
								'ux-studio'
							) }
						</p>
					) : (
						<table className="uxs-table">
							<thead>
								<tr>
									<th></th>
									<th>{ __( 'Title', 'ux-studio' ) }</th>
									<th>{ __( 'Type', 'ux-studio' ) }</th>
									<th>{ __( 'Key', 'ux-studio' ) }</th>
									<th>{ __( 'Width', 'ux-studio' ) }</th>
									<th>{ __( 'Enabled', 'ux-studio' ) }</th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								{ draft.map( ( column, index ) => (
									<ColumnRow
										// eslint-disable-next-line react/no-array-index-key
										key={ index }
										column={ column }
										onChange={ ( next ) => updateAt( index, next ) }
										onRemove={ () => removeAt( index ) }
										onMove={ ( dir ) => moveAt( index, dir ) }
										isFirst={ index === 0 }
										isLast={ index === draft.length - 1 }
										datalistId={ `uxs-ac-keys-${ type.id }-${ index }` }
										suggestions={ suggestions.data?.[ column.type ] ?? [] }
									/>
								) ) }
							</tbody>
						</table>
					) }
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
						<button type="button" className="button" onClick={ () => setDraft( ( rows ) => [ ...rows, emptyColumn() ] ) }>
							<Plus size={ 14 } aria-hidden />
							{ __( 'Add column', 'ux-studio' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							disabled={ save.isPending }
							onClick={ () => save.mutate() }
						>
							{ saved ? <Check size={ 14 } /> : null }
							{ saved
								? __( 'Saved', 'ux-studio' )
								: /* translators: %s: content type label, e.g. "Posts". */
								  sprintf( __( 'Save changes for %s', 'ux-studio' ), type.label ) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}

export default function Page(): JSX.Element {
	const [ sections, setSections ] = useState< string[] >( [] );
	const [ draftsByType, setDraftsByType ] = useState< Record< string, Column[] > >( {} );
	const [ expanded, setExpanded ] = useState< Record< string, boolean > >( {} );
	const [ addingType, setAddingType ] = useState( '' );
	const seeded = useRef( false );

	const types = useQuery( {
		queryKey: [ 'admin-columns', 'content-types' ],
		queryFn: () => api< ContentType[] >( 'admin-columns/content-types' ),
	} );

	const configured = useQuery( {
		queryKey: [ 'admin-columns', 'configured' ],
		queryFn: () => api< ConfiguredType[] >( 'admin-columns/configured' ),
	} );

	// Seed the accordion from already-configured types exactly once — later
	// refetches (e.g. after a save) must not reset sections the user added
	// locally or collapsed/expanded in this session.
	useEffect( () => {
		if ( seeded.current || ! configured.data ) {
			return;
		}
		seeded.current = true;
		setSections( configured.data.map( ( c ) => c.post_type ) );
		setDraftsByType( Object.fromEntries( configured.data.map( ( c ) => [ c.post_type, c.columns ] ) ) );
		setExpanded( Object.fromEntries( configured.data.map( ( c ) => [ c.post_type, true ] ) ) );
	}, [ configured.data ] );

	const typeById = ( id: string ): ContentType =>
		( types.data ?? [] ).find( ( t ) => t.id === id ) ?? { id, label: id };

	const addableTypes = ( types.data ?? [] ).filter( ( t ) => ! sections.includes( t.id ) );

	const addSection = (): void => {
		if ( addingType === '' ) {
			return;
		}
		setSections( ( s ) => [ ...s, addingType ] );
		setDraftsByType( ( d ) => ( { ...d, [ addingType ]: [] } ) );
		setExpanded( ( e ) => ( { ...e, [ addingType ]: true } ) );
		setAddingType( '' );
	};

	const removeSection = ( id: string ): void => {
		setSections( ( s ) => s.filter( ( x ) => x !== id ) );
		setDraftsByType( ( d ) => {
			const next = { ...d };
			delete next[ id ];
			return next;
		} );
	};

	const isLoading = types.isLoading || configured.isLoading;

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
					{ __( 'Admin Columns', 'ux-studio' ) }
				</h1>
			</header>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<>
					{ sections.length === 0 ? (
						<p>
							{ __(
								'No content type has custom columns yet. Pick one below to start configuring it.',
								'ux-studio'
							) }
						</p>
					) : (
						<div className="uxs-accordion">
							{ sections.map( ( id ) => (
								<AccordionSection
									key={ id }
									type={ typeById( id ) }
									draft={ draftsByType[ id ] ?? [] }
									expanded={ !! expanded[ id ] }
									onToggle={ () => setExpanded( ( e ) => ( { ...e, [ id ]: ! e[ id ] } ) ) }
									onRemoveSection={ () => removeSection( id ) }
									setDraft={ ( updater ) =>
										setDraftsByType( ( d ) => ( { ...d, [ id ]: updater( d[ id ] ?? [] ) } ) )
									}
									canRemoveSection
								/>
							) ) }
						</div>
					) }

					{ addableTypes.length > 0 && (
						<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', alignItems: 'center' } }>
							<select value={ addingType } onChange={ ( e ) => setAddingType( e.target.value ) }>
								<option value="">{ __( '— Select content type —', 'ux-studio' ) }</option>
								{ addableTypes.map( ( t ) => (
									<option key={ t.id } value={ t.id }>
										{ t.label }
									</option>
								) ) }
							</select>
							<button type="button" className="button" disabled={ addingType === '' } onClick={ addSection }>
								<Plus size={ 14 } aria-hidden />
								{ __( 'Add content type', 'ux-studio' ) }
							</button>
						</div>
					) }
				</>
			) }
		</>
	);
}
