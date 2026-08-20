/**
 * Menu Organizer editor: functional equivalent of the legacy jQuery-UI
 * drag-and-drop admin-menu editor, built with simple list controls instead
 * (no drag-and-drop dependency in this project). Categories are reordered
 * with up/down buttons, items are assigned to a category via a dropdown and
 * reordered within it with up/down buttons, and every native item gets
 * inline fields for title/icon/URL overrides, a hide checkbox and a role
 * multiselect.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import {
	AlertTriangle,
	ArrowDown,
	ArrowUp,
	CheckCircle2,
	Download,
	LoaderCircle,
	Plus,
	Trash2,
	Upload,
} from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface MenuChild {
	slug: string;
	title: string;
}

interface MenuItem {
	slug: string;
	title: string;
	icon: string;
	children: MenuChild[];
}

interface CustomLink {
	id: string;
	title: string;
	url: string;
	icon: string;
	target: string;
	category: string;
}

interface Separator {
	id: string;
	label: string;
	category: string;
}

interface MenuConfig {
	layout: 'vertical' | 'horizontal';
	categories: Record< string, string >;
	category_order: string[];
	assignments: Record< string, string >;
	order: Record< string, string[] >;
	category_icons: Record< string, string >;
	category_roles: Record< string, string[] >;
	item_titles: Record< string, string >;
	item_icons: Record< string, string >;
	item_urls: Record< string, string >;
	item_roles: Record< string, string[] >;
	custom_links: CustomLink[];
	promoted_subs: Record< string, { parent: string; title: string; icon: string; category: string } >;
	separators: Separator[];
	hidden_items: string[];
	show_icons_level1: boolean;
	show_icons_level2: boolean;
	show_icons_level3: boolean;
	native_flyouts: boolean;
	seen_slugs: string[];
}

interface Conflict {
	id: string;
	name: string;
	severity: 'handled' | 'warn';
	message: string;
}

const CATEGORY_QUERY_KEY = [ 'admin-customiser', 'menu-organizer', 'config' ];

// WordPress core roles. Custom roles added by other plugins aren't listed
// here (the REST contract for this module doesn't expose a roles endpoint) -
// site owners with custom roles can still restrict by editing capabilities
// elsewhere; this covers the common case.
const CORE_ROLES: Record< string, string > = {
	administrator: __( 'Administrator', 'ux-studio' ),
	editor: __( 'Editor', 'ux-studio' ),
	author: __( 'Author', 'ux-studio' ),
	contributor: __( 'Contributor', 'ux-studio' ),
	subscriber: __( 'Subscriber', 'ux-studio' ),
};

function reorder< T >( list: T[], index: number, dir: -1 | 1 ): T[] {
	const target = index + dir;
	if ( target < 0 || target >= list.length ) {
		return list;
	}
	const next = [ ...list ];
	const a = next[ index ] as T;
	const b = next[ target ] as T;
	next[ index ] = b;
	next[ target ] = a;
	return next;
}

export function MenuOrganizerTab(): JSX.Element {
	const configQuery = useQuery( {
		queryKey: CATEGORY_QUERY_KEY,
		queryFn: () => api< MenuConfig >( 'admin-customiser/menu-organizer/config' ),
	} );
	const menuQuery = useQuery( {
		queryKey: [ 'admin-customiser', 'menu-organizer', 'current-menu' ],
		queryFn: () => api< MenuItem[] >( 'admin-customiser/menu-organizer/current-menu' ),
	} );
	const conflictsQuery = useQuery( {
		queryKey: [ 'admin-customiser', 'menu-organizer', 'conflicts' ],
		queryFn: () => api< Conflict[] >( 'admin-customiser/menu-organizer/detect-conflicts', { method: 'POST' } ),
	} );

	const [ draft, setDraft ] = useState< MenuConfig | null >( null );
	const [ newCategoryName, setNewCategoryName ] = useState( '' );
	const [ linkTitle, setLinkTitle ] = useState( '' );
	const [ linkUrl, setLinkUrl ] = useState( '' );
	const [ linkCategory, setLinkCategory ] = useState( '' );
	const [ sepLabel, setSepLabel ] = useState( '' );
	const [ sepCategory, setSepCategory ] = useState( '' );
	const [ saved, setSaved ] = useState( false );

	useEffect( () => {
		if ( configQuery.data ) {
			setDraft( configQuery.data );
		}
	}, [ configQuery.data ] );

	const save = useMutation( {
		mutationFn: ( payload: MenuConfig ) =>
			api< MenuConfig >( 'admin-customiser/menu-organizer/config', {
				method: 'POST',
				body: JSON.stringify( payload ),
			} ),
		onSuccess: ( data ) => {
			setDraft( data );
			void queryClient.invalidateQueries( { queryKey: CATEGORY_QUERY_KEY } );
			setSaved( true );
			window.setTimeout( () => setSaved( false ), 2000 );
		},
	} );

	const exportDefault = useMutation( {
		mutationFn: () => api< MenuConfig >( 'admin-customiser/menu-organizer/export-default', { method: 'POST' } ),
		onSuccess: ( data ) => {
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = 'admin-menu-config.json';
			a.click();
			URL.revokeObjectURL( url );
		},
	} );

	if ( configQuery.isLoading || menuQuery.isLoading || ! draft ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const items = menuQuery.data ?? [];
	const categoryIds = Object.keys( draft.categories );

	const setConfig = ( updater: ( d: MenuConfig ) => MenuConfig ): void => {
		setDraft( ( d ) => ( d ? updater( d ) : d ) );
	};

	const categoryOf = ( slug: string ): string =>
		draft.assignments[ slug ] ?? categoryIds[ 0 ] ?? 'other';

	const itemsInCategory = ( catId: string ): MenuItem[] => {
		const ordered = draft.order[ catId ] ?? [];
		const inCat = items.filter(
			( it ) => categoryOf( it.slug ) === catId && ! draft.hidden_items.includes( it.slug )
		);
		const bySlug = new Map( inCat.map( ( it ) => [ it.slug, it ] ) );
		const result: MenuItem[] = [];
		for ( const slug of ordered ) {
			const it = bySlug.get( slug );
			if ( it ) {
				result.push( it );
				bySlug.delete( slug );
			}
		}
		result.push( ...bySlug.values() );
		return result;
	};

	const moveCategory = ( catId: string, dir: -1 | 1 ): void => {
		setConfig( ( d ) => {
			const ids = Object.keys( d.categories );
			const idx = ids.indexOf( catId );
			const nextIds = reorder( ids, idx, dir );
			const categories: Record< string, string > = {};
			nextIds.forEach( ( id ) => ( categories[ id ] = d.categories[ id ] ?? '' ) );
			return { ...d, categories, category_order: nextIds };
		} );
	};

	const renameCategory = ( catId: string, label: string ): void => {
		setConfig( ( d ) => ( { ...d, categories: { ...d.categories, [ catId ]: label } } ) );
	};

	const deleteCategory = ( catId: string ): void => {
		setConfig( ( d ) => {
			const categories = { ...d.categories };
			delete categories[ catId ];
			const assignments = { ...d.assignments };
			Object.keys( assignments ).forEach( ( slug ) => {
				if ( assignments[ slug ] === catId ) {
					delete assignments[ slug ];
				}
			} );
			return {
				...d,
				categories,
				assignments,
				category_order: d.category_order.filter( ( id ) => id !== catId ),
			};
		} );
	};

	const addCategory = (): void => {
		const label = newCategoryName.trim();
		if ( '' === label ) {
			return;
		}
		const id = label
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' ) || `cat-${ Date.now() }`;
		setConfig( ( d ) => ( {
			...d,
			categories: { ...d.categories, [ id ]: label },
			category_order: [ ...d.category_order, id ],
		} ) );
		setNewCategoryName( '' );
	};

	const assignItem = ( slug: string, catId: string ): void => {
		setConfig( ( d ) => ( { ...d, assignments: { ...d.assignments, [ slug ]: catId } } ) );
	};

	const moveItem = ( catId: string, slug: string, dir: -1 | 1 ): void => {
		setConfig( ( d ) => {
			const current = itemsInCategory( catId ).map( ( it ) => it.slug );
			const idx = current.indexOf( slug );
			const nextOrder = reorder( current, idx, dir );
			return { ...d, order: { ...d.order, [ catId ]: nextOrder } };
		} );
	};

	const setItemField = (
		field: 'item_titles' | 'item_icons' | 'item_urls',
		slug: string,
		value: string
	): void => {
		setConfig( ( d ) => {
			const next = { ...d[ field ] };
			if ( '' === value ) {
				delete next[ slug ];
			} else {
				next[ slug ] = value;
			}
			return { ...d, [ field ]: next };
		} );
	};

	const toggleHidden = ( slug: string, hide: boolean ): void => {
		setConfig( ( d ) => ( {
			...d,
			hidden_items: hide
				? [ ...d.hidden_items, slug ]
				: d.hidden_items.filter( ( s ) => s !== slug ),
		} ) );
	};

	const toggleItemRole = ( slug: string, role: string ): void => {
		setConfig( ( d ) => {
			const current = d.item_roles[ slug ] ?? [];
			const next = current.includes( role )
				? current.filter( ( r ) => r !== role )
				: [ ...current, role ];
			const item_roles = { ...d.item_roles };
			if ( 0 === next.length ) {
				delete item_roles[ slug ];
			} else {
				item_roles[ slug ] = next;
			}
			return { ...d, item_roles };
		} );
	};

	const addCustomLink = (): void => {
		if ( '' === linkTitle.trim() || '' === linkUrl.trim() ) {
			return;
		}
		const id = `link-${ Date.now() }`;
		setConfig( ( d ) => ( {
			...d,
			custom_links: [
				...d.custom_links,
				{
					id,
					title: linkTitle.trim(),
					url: linkUrl.trim(),
					icon: '',
					target: '',
					category: linkCategory || categoryIds[ 0 ] || 'other',
				},
			],
		} ) );
		setLinkTitle( '' );
		setLinkUrl( '' );
	};

	const removeCustomLink = ( id: string ): void => {
		setConfig( ( d ) => ( { ...d, custom_links: d.custom_links.filter( ( l ) => l.id !== id ) } ) );
	};

	const addSeparator = (): void => {
		const id = `sep-${ Date.now() }`;
		setConfig( ( d ) => ( {
			...d,
			separators: [
				...d.separators,
				{ id, label: sepLabel.trim(), category: sepCategory || categoryIds[ 0 ] || 'other' },
			],
		} ) );
		setSepLabel( '' );
	};

	const removeSeparator = ( id: string ): void => {
		setConfig( ( d ) => ( { ...d, separators: d.separators.filter( ( s ) => s.id !== id ) } ) );
	};

	const onImportFile = ( file: File | undefined ): void => {
		if ( ! file ) {
			return;
		}
		const reader = new FileReader();
		reader.onload = () => {
			try {
				const parsed = JSON.parse( String( reader.result ) ) as MenuConfig;
				setDraft( parsed );
			} catch {
				// Ignore invalid JSON silently - user can retry with a valid export file.
			}
		};
		reader.readAsText( file );
	};

	return (
		<div className="uxs-form">
			{ conflictsQuery.data && conflictsQuery.data.length > 0 && (
				<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
					{ conflictsQuery.data.map( ( c ) => (
						<p key={ c.id } className={ `uxs-badge ${ 'handled' === c.severity ? 'is-info' : 'is-warning' }` } style={ { display: 'block', marginBottom: 'var(--uxs-sp-2)' } }>
							{ 'handled' === c.severity ? <CheckCircle2 size={ 12 } /> : <AlertTriangle size={ 12 } /> } { c.name }
						</p>
					) ) }
				</div>
			) }

			<div className="uxs-form__row">
				<label htmlFor="uxs-amo-layout">{ __( 'Layout', 'ux-studio' ) }</label>
				<select
					id="uxs-amo-layout"
					value={ draft.layout }
					onChange={ ( e ) => setConfig( ( d ) => ( { ...d, layout: e.target.value as MenuConfig[ 'layout' ] } ) ) }
				>
					<option value="vertical">{ __( 'Vertical sidebar (default)', 'ux-studio' ) }</option>
					<option value="horizontal">{ __( 'Horizontal top bar', 'ux-studio' ) }</option>
				</select>
			</div>

			<p>
				<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate( draft ) }>
					{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
				</button>{ ' ' }
				<button type="button" className="button" onClick={ () => exportDefault.mutate() }>
					<Download size={ 14 } /> { __( 'Export configuration', 'ux-studio' ) }
				</button>{ ' ' }
				<label className="button" style={ { display: 'inline-flex', alignItems: 'center', gap: 4, cursor: 'pointer' } }>
					<Upload size={ 14 } /> { __( 'Import configuration', 'ux-studio' ) }
					<input
						type="file"
						accept="application/json"
						onChange={ ( e ) => onImportFile( e.target.files?.[ 0 ] ) }
						style={ { display: 'none' } }
					/>
				</label>
			</p>

			<h3>{ __( 'Categories', 'ux-studio' ) }</h3>
			<div className="uxs-form__row">
				<label htmlFor="uxs-amo-new-cat">{ __( 'Add category', 'ux-studio' ) }</label>
				<span>
					<input
						id="uxs-amo-new-cat"
						type="text"
						value={ newCategoryName }
						onChange={ ( e ) => setNewCategoryName( e.target.value ) }
						placeholder={ __( 'e.g. Reports', 'ux-studio' ) }
					/>{ ' ' }
					<button type="button" className="button" onClick={ addCategory }>
						<Plus size={ 14 } /> { __( 'Add', 'ux-studio' ) }
					</button>
				</span>
			</div>

			{ categoryIds.map( ( catId, idx ) => (
				<div key={ catId } className="uxs-form__row" style={ { alignItems: 'flex-start' } }>
					<label>
						<input
							type="text"
							value={ draft.categories[ catId ] }
							onChange={ ( e ) => renameCategory( catId, e.target.value ) }
						/>
					</label>
					<div>
						<button type="button" className="button button-small" disabled={ 0 === idx } onClick={ () => moveCategory( catId, -1 ) }>
							<ArrowUp size={ 12 } />
						</button>{ ' ' }
						<button
							type="button"
							className="button button-small"
							disabled={ idx === categoryIds.length - 1 }
							onClick={ () => moveCategory( catId, 1 ) }
						>
							<ArrowDown size={ 12 } />
						</button>{ ' ' }
						<button type="button" className="button button-small button-link-delete" onClick={ () => deleteCategory( catId ) }>
							<Trash2 size={ 12 } /> { __( 'Delete', 'ux-studio' ) }
						</button>

						<table className="uxs-table" style={ { marginTop: 'var(--uxs-sp-2)' } }>
							<thead>
								<tr>
									<th>{ __( 'Item', 'ux-studio' ) }</th>
									<th>{ __( 'Category', 'ux-studio' ) }</th>
									<th>{ __( 'Order', 'ux-studio' ) }</th>
									<th>{ __( 'Custom title', 'ux-studio' ) }</th>
									<th>{ __( 'Custom icon', 'ux-studio' ) }</th>
									<th>{ __( 'Custom URL', 'ux-studio' ) }</th>
									<th>{ __( 'Visible to roles', 'ux-studio' ) }</th>
									<th>{ __( 'Hide', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ itemsInCategory( catId ).map( ( it, itemIdx, arr ) => (
									<tr key={ it.slug }>
										<td>
											{ it.title }
											<br />
											<code>{ it.slug }</code>
										</td>
										<td>
											<select value={ catId } onChange={ ( e ) => assignItem( it.slug, e.target.value ) }>
												{ categoryIds.map( ( id ) => (
													<option key={ id } value={ id }>
														{ draft.categories[ id ] }
													</option>
												) ) }
											</select>
										</td>
										<td>
											<button
												type="button"
												className="button button-small"
												disabled={ 0 === itemIdx }
												onClick={ () => moveItem( catId, it.slug, -1 ) }
											>
												<ArrowUp size={ 12 } />
											</button>{ ' ' }
											<button
												type="button"
												className="button button-small"
												disabled={ itemIdx === arr.length - 1 }
												onClick={ () => moveItem( catId, it.slug, 1 ) }
											>
												<ArrowDown size={ 12 } />
											</button>
										</td>
										<td>
											<input
												type="text"
												value={ draft.item_titles[ it.slug ] ?? '' }
												placeholder={ it.title }
												onChange={ ( e ) => setItemField( 'item_titles', it.slug, e.target.value ) }
											/>
										</td>
										<td>
											<input
												type="text"
												value={ draft.item_icons[ it.slug ] ?? '' }
												placeholder="dashicons-admin-generic"
												onChange={ ( e ) => setItemField( 'item_icons', it.slug, e.target.value ) }
											/>
										</td>
										<td>
											<input
												type="text"
												value={ draft.item_urls[ it.slug ] ?? '' }
												placeholder={ it.slug }
												onChange={ ( e ) => setItemField( 'item_urls', it.slug, e.target.value ) }
											/>
										</td>
										<td>
											<div className="uxs-checklist">
												{ Object.entries( CORE_ROLES ).map( ( [ role, label ] ) => (
													<label key={ role } className="uxs-checklist__item" title={ label }>
														<input
															type="checkbox"
															checked={ ( draft.item_roles[ it.slug ] ?? [] ).includes( role ) }
															onChange={ () => toggleItemRole( it.slug, role ) }
														/>
														{ label }
													</label>
												) ) }
											</div>
											<p className="uxs-form__help">{ __( 'None checked = visible to everyone.', 'ux-studio' ) }</p>
										</td>
										<td>
											<input
												type="checkbox"
												checked={ draft.hidden_items.includes( it.slug ) }
												onChange={ ( e ) => toggleHidden( it.slug, e.target.checked ) }
											/>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</div>
			) ) }

			<h3>{ __( 'Custom links', 'ux-studio' ) }</h3>
			<div className="uxs-form__row">
				<label>{ __( 'Add link', 'ux-studio' ) }</label>
				<span>
					<input type="text" placeholder={ __( 'Title', 'ux-studio' ) } value={ linkTitle } onChange={ ( e ) => setLinkTitle( e.target.value ) } />{ ' ' }
					<input type="url" placeholder="https://…" value={ linkUrl } onChange={ ( e ) => setLinkUrl( e.target.value ) } />{ ' ' }
					<select value={ linkCategory } onChange={ ( e ) => setLinkCategory( e.target.value ) }>
						<option value="">{ __( '— category —', 'ux-studio' ) }</option>
						{ categoryIds.map( ( id ) => (
							<option key={ id } value={ id }>
								{ draft.categories[ id ] }
							</option>
						) ) }
					</select>{ ' ' }
					<button type="button" className="button" onClick={ addCustomLink }>
						<Plus size={ 14 } /> { __( 'Add link', 'ux-studio' ) }
					</button>
				</span>
			</div>
			{ draft.custom_links.length > 0 && (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Title', 'ux-studio' ) }</th>
							<th>{ __( 'URL', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ draft.custom_links.map( ( l ) => (
							<tr key={ l.id }>
								<td>{ l.title }</td>
								<td>{ l.url }</td>
								<td>{ draft.categories[ l.category ] ?? l.category }</td>
								<td>
									<button type="button" className="button button-small" onClick={ () => removeCustomLink( l.id ) }>
										<Trash2 size={ 12 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<h3>{ __( 'Separators', 'ux-studio' ) }</h3>
			<div className="uxs-form__row">
				<label>{ __( 'Add separator', 'ux-studio' ) }</label>
				<span>
					<input type="text" placeholder={ __( 'Label (optional)', 'ux-studio' ) } value={ sepLabel } onChange={ ( e ) => setSepLabel( e.target.value ) } />{ ' ' }
					<select value={ sepCategory } onChange={ ( e ) => setSepCategory( e.target.value ) }>
						<option value="">{ __( '— category —', 'ux-studio' ) }</option>
						{ categoryIds.map( ( id ) => (
							<option key={ id } value={ id }>
								{ draft.categories[ id ] }
							</option>
						) ) }
					</select>{ ' ' }
					<button type="button" className="button" onClick={ addSeparator }>
						<Plus size={ 14 } /> { __( 'Add separator', 'ux-studio' ) }
					</button>
				</span>
			</div>
			{ draft.separators.length > 0 && (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Label', 'ux-studio' ) }</th>
							<th>{ __( 'Category', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ draft.separators.map( ( s ) => (
							<tr key={ s.id }>
								<td>{ s.label || <em>{ __( '(no label)', 'ux-studio' ) }</em> }</td>
								<td>{ draft.categories[ s.category ] ?? s.category }</td>
								<td>
									<button type="button" className="button button-small" onClick={ () => removeSeparator( s.id ) }>
										<Trash2 size={ 12 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<p style={ { marginTop: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ () => save.mutate( draft ) }>
					{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
				</button>
			</p>
		</div>
	);
}
