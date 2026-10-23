/**
 * Sub-items and advanced rules of the Menu Organizer (PLAN.md 27.7): rename, hide per role, move under another parent and
 * re-order sub-items, plus a required capability and show/hide rules for single users on every item and sub-item.
 */
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { api } from '../../app/api';
import type { MenuConfig, MenuItem } from './MenuOrganizerTab';

interface People {
	roles: Record< string, string >;
	users: { login: string; name: string }[];
}

type SetConfig = ( updater: ( d: MenuConfig ) => MenuConfig ) => void;

const subKey = ( parent: string, sub: string ): string => `${ parent }>${ sub }`;

/** Comma separated logins <-> list, keeping what the user is typing. */
function LoginsInput( {
	value,
	onChange,
	label,
	listId,
}: {
	value: string[];
	onChange: ( next: string[] ) => void;
	label: string;
	listId: string;
} ): JSX.Element {
	const [ text, setText ] = useState( value.join( ', ' ) );
	const parse = ( raw: string ): string[] =>
		raw
			.split( /[\s,;]+/ )
			.map( ( l ) => l.trim() )
			.filter( ( l ) => '' !== l );
	useEffect( () => {
		if ( parse( text ).join( ',' ) !== value.join( ',' ) ) {
			setText( value.join( ', ' ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value.join( ',' ) ] );
	return (
		<input
			type="text"
			list={ listId }
			value={ text }
			aria-label={ label }
			placeholder={ __( 'user logins, comma separated', 'ux-studio' ) }
			onChange={ ( e ) => {
				setText( e.target.value );
				onChange( parse( e.target.value ) );
			} }
		/>
	);
}

/** Required capability and the two user lists of one item (a top-level slug or `parent>sub`). */
function ItemRules( {
	itemKey,
	draft,
	setConfig,
}: {
	itemKey: string;
	draft: MenuConfig;
	setConfig: SetConfig;
} ): JSX.Element {
	const users = draft.item_users[ itemKey ] ?? {};
	const setUsers = ( kind: 'show' | 'hide', next: string[] ): void =>
		setConfig( ( d ) => {
			const entry = { ...( d.item_users[ itemKey ] ?? {} ), [ kind ]: next };
			if ( 0 === next.length ) {
				delete entry[ kind ];
			}
			const item_users = { ...d.item_users };
			if ( entry.show?.length || entry.hide?.length ) {
				item_users[ itemKey ] = entry;
			} else {
				delete item_users[ itemKey ];
			}
			return { ...d, item_users };
		} );
	return (
		<>
			<td>
				<input
					type="text"
					value={ draft.item_caps[ itemKey ] ?? '' }
					aria-label={ __( 'Required capability', 'ux-studio' ) }
					placeholder="edit_others_posts"
					onChange={ ( e ) =>
						setConfig( ( d ) => {
							const item_caps = { ...d.item_caps };
							if ( '' === e.target.value.trim() ) {
								delete item_caps[ itemKey ];
							} else {
								item_caps[ itemKey ] = e.target.value.trim();
							}
							return { ...d, item_caps };
						} )
					}
				/>
			</td>
			<td>
				<LoginsInput
					value={ users.show ?? [] }
					onChange={ ( next ) => setUsers( 'show', next ) }
					label={ __( 'Always show to these users', 'ux-studio' ) }
					listId="uxs-amo-logins"
				/>
			</td>
			<td>
				<LoginsInput
					value={ users.hide ?? [] }
					onChange={ ( next ) => setUsers( 'hide', next ) }
					label={ __( 'Never show to these users', 'ux-studio' ) }
					listId="uxs-amo-logins"
				/>
			</td>
		</>
	);
}

function ruleCount( draft: MenuConfig, item: MenuItem ): number {
	let n = 0;
	const keys = [ item.slug, ...item.children.map( ( c ) => subKey( item.slug, c.slug ) ) ];
	for ( const key of keys ) {
		n += ( draft.item_caps[ key ] ? 1 : 0 ) + ( draft.item_users[ key ] ? 1 : 0 );
		n += ( draft.sub_titles[ key ] ? 1 : 0 ) + ( draft.sub_hidden[ key ] ? 1 : 0 ) + ( draft.sub_moves[ key ] ? 1 : 0 );
	}
	return n + ( draft.sub_order[ item.slug ] ? 1 : 0 );
}

export function SubmenuRules( {
	draft,
	items,
	setConfig,
}: {
	draft: MenuConfig;
	items: MenuItem[];
	setConfig: SetConfig;
} ): JSX.Element {
	const people = useQuery( {
		queryKey: [ 'admin-customiser', 'menu-organizer', 'people' ],
		queryFn: () => api< People >( 'admin-customiser/menu-organizer/people' ),
	} );
	const roles = people.data?.roles ?? {};

	const setMap = ( field: 'sub_titles' | 'sub_moves', key: string, value: string ): void =>
		setConfig( ( d ) => {
			const next: Record< string, string > = { ...d[ field ] };
			if ( '' === value ) {
				delete next[ key ];
			} else {
				next[ key ] = value;
			}
			return field === 'sub_titles' ? { ...d, sub_titles: next } : { ...d, sub_moves: next };
		} );

	const toggleRole = ( key: string, role: string, on: boolean ): void =>
		setConfig( ( d ) => {
			const current = d.sub_hidden[ key ] ?? [];
			let next: string[];
			if ( '*' === role ) {
				next = on ? [ '*' ] : [];
			} else {
				next = on ? [ ...current.filter( ( r ) => '*' !== r ), role ] : current.filter( ( r ) => r !== role );
			}
			const sub_hidden = { ...d.sub_hidden };
			if ( 0 === next.length ) {
				delete sub_hidden[ key ];
			} else {
				sub_hidden[ key ] = next;
			}
			return { ...d, sub_hidden };
		} );

	/** Children of a parent in the order the menu shows them (the saved order first, new ones after). */
	const ordered = ( item: MenuItem ): string[] => {
		const saved = draft.sub_order[ item.slug ] ?? [];
		const slugs = item.children.map( ( c ) => c.slug );
		return [ ...saved.filter( ( s ) => slugs.includes( s ) ), ...slugs.filter( ( s ) => ! saved.includes( s ) ) ];
	};

	const moveChild = ( item: MenuItem, slug: string, dir: -1 | 1 ): void =>
		setConfig( ( d ) => {
			const list = ordered( item );
			const from = list.indexOf( slug );
			const to = from + dir;
			if ( from < 0 || to < 0 || to >= list.length ) {
				return d;
			}
			[ list[ from ], list[ to ] ] = [ list[ to ] as string, list[ from ] as string ];
			return { ...d, sub_order: { ...d.sub_order, [ item.slug ]: list } };
		} );

	return (
		<>
			<h3>{ __( 'Sub-items and advanced rules', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">
				{ __(
					'Open an item to rename, hide, move or re-order its sub-items, to require a capability, or to show or hide an item for single users (by login). Rules of users and capabilities apply on top of the role limits above. Users who can manage options always keep UX Studio, Plugins, Users and Settings. With "Block access to hidden pages" on, hidden sub-items are refused by address too.',
					'ux-studio'
				) }
			</p>
			<datalist id="uxs-amo-logins">
				{ ( people.data?.users ?? [] ).map( ( u ) => (
					<option key={ u.login } value={ u.login }>
						{ u.name }
					</option>
				) ) }
			</datalist>

			{ items.map( ( item ) => {
				const count = ruleCount( draft, item );
				const rows = ordered( item );
				return (
					<details key={ item.slug } className="uxs-topic-settings uxs-topic-settings--collapsible">
						<summary>
							{ item.title } <code>{ item.slug }</code>
							{ count > 0 && <span className="uxs-badge">{ count }</span> }
						</summary>
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ __( 'Item', 'ux-studio' ) }</th>
									<th>{ __( 'Rename', 'ux-studio' ) }</th>
									<th>{ __( 'Move under', 'ux-studio' ) }</th>
									<th>{ __( 'Hidden for roles', 'ux-studio' ) }</th>
									<th>{ __( 'Required capability', 'ux-studio' ) }</th>
									<th>{ __( 'Always show to users', 'ux-studio' ) }</th>
									<th>{ __( 'Never show to users', 'ux-studio' ) }</th>
									<th>{ __( 'Order', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td>
										{ item.title }
										<br />
										<em>{ __( 'the item itself', 'ux-studio' ) }</em>
									</td>
									<td></td>
									<td></td>
									<td></td>
									<ItemRules itemKey={ item.slug } draft={ draft } setConfig={ setConfig } />
									<td></td>
								</tr>
								{ rows.map( ( slug, index ) => {
									const child = item.children.find( ( c ) => c.slug === slug );
									if ( ! child ) {
										return null;
									}
									const key = subKey( item.slug, slug );
									const hidden = draft.sub_hidden[ key ] ?? [];
									return (
										<tr key={ key }>
											<td>
												{ child.title }
												<br />
												<code>{ slug }</code>
											</td>
											<td>
												<input
													type="text"
													value={ draft.sub_titles[ key ] ?? '' }
													placeholder={ child.title }
													aria-label={ sprintf( /* translators: %s: sub-item title. */ __( 'New title of %s', 'ux-studio' ), child.title ) }
													onChange={ ( e ) => setMap( 'sub_titles', key, e.target.value ) }
												/>
											</td>
											<td>
												<select
													value={ draft.sub_moves[ key ] ?? '' }
													aria-label={ sprintf( /* translators: %s: sub-item title. */ __( 'Move %s under', 'ux-studio' ), child.title ) }
													onChange={ ( e ) => setMap( 'sub_moves', key, e.target.value ) }
												>
													<option value="">{ __( '(stays here)', 'ux-studio' ) }</option>
													{ items
														.filter( ( other ) => other.slug !== item.slug )
														.map( ( other ) => (
															<option key={ other.slug } value={ other.slug }>
																{ other.title }
															</option>
														) ) }
												</select>
											</td>
											<td>
												<div className="uxs-checklist">
													<label className="uxs-checklist__item">
														<input
															type="checkbox"
															checked={ hidden.includes( '*' ) }
															onChange={ ( e ) => toggleRole( key, '*', e.target.checked ) }
														/>
														{ __( 'Everybody', 'ux-studio' ) }
													</label>
													{ Object.entries( roles ).map( ( [ role, label ] ) => (
														<label key={ role } className="uxs-checklist__item">
															<input
																type="checkbox"
																disabled={ hidden.includes( '*' ) }
																checked={ hidden.includes( role ) }
																onChange={ ( e ) => toggleRole( key, role, e.target.checked ) }
															/>
															{ label }
														</label>
													) ) }
												</div>
											</td>
											<ItemRules itemKey={ key } draft={ draft } setConfig={ setConfig } />
											<td>
												<button
													type="button"
													className="button button-small"
													disabled={ 0 === index }
													aria-label={ __( 'Move up', 'ux-studio' ) }
													onClick={ () => moveChild( item, slug, -1 ) }
												>
													<ArrowUp size={ 12 } />
												</button>{ ' ' }
												<button
													type="button"
													className="button button-small"
													disabled={ index === rows.length - 1 }
													aria-label={ __( 'Move down', 'ux-studio' ) }
													onClick={ () => moveChild( item, slug, 1 ) }
												>
													<ArrowDown size={ 12 } />
												</button>
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</details>
				);
			} ) }
		</>
	);
}
