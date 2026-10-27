/**
 * Hidden widgets: one table of every widget of the wp-admin dashboard (core, plugins, UX Studio). Each row can be hidden
 * for everybody or limited to some roles; the toolbar and the bulk bar change several rows at once.
 *
 * Every change is saved at once (a toast confirms it) through uxstudio/v1/dashboard-widgets/rules; the server checks the
 * ids and roles again. "Revert changes" puts back the rules as they were when the screen opened.
 */
import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, DialogTrigger, ListBox, ListBoxItem, Popover } from 'react-aria-components';
import { ChevronDown, Info, LoaderCircle, RefreshCw, Search, Trash2, Undo2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';
import { pushToast } from '../../app/notify';

interface Rule {
	hidden: boolean;
	roles: string[];
}

type Rules = Record< string, Rule >;

interface WidgetRow {
	id: string;
	title: string;
	context: string;
	priority: string;
	source: string;
	source_name: string;
	guess: boolean;
	first_seen: number;
	last_seen: number;
	stale: boolean;
}

interface RoleInfo {
	id: string;
	name: string;
}

interface RulesPayload {
	rules: Rules;
	widgets: WidgetRow[];
	roles: RoleInfo[];
	scan_at: number;
	notice: boolean;
	index: string;
}

type Filter = 'all' | 'hidden' | 'limited' | 'stale';

interface RulesRequest {
	items?: { id: string; hidden?: boolean; roles?: string[] }[];
	action?: 'hide' | 'show' | 'roles' | 'reset' | 'replace' | 'dismiss_notice';
	ids?: string[];
	roles?: string[];
	rules?: Rules;
}

const KEY = [ 'dashboard-widgets', 'rules' ];
const NO_RULE: Rule = { hidden: false, roles: [] };

/** Where the widget sits on the dashboard. */
function contextLabel( context: string ): string {
	switch ( context ) {
		case 'normal':
			return __( 'Main column', 'ux-studio' );
		case 'side':
			return __( 'Side column', 'ux-studio' );
		case 'column3':
			return __( 'Third column', 'ux-studio' );
		case 'column4':
			return __( 'Fourth column', 'ux-studio' );
		case 'welcome':
			return __( 'Top of the page', 'ux-studio' );
		default:
			return context;
	}
}

/** Who provides the widget, as the snapshot recorded it (`core`, `uxstudio`, `plugin:<folder>`, `theme:<folder>`, `mu`). */
function sourceLabel( row: WidgetRow ): string {
	const [ kind, folder = '' ] = row.source.split( ':' );
	switch ( kind ) {
		case 'core':
			return __( 'WordPress', 'ux-studio' );
		case 'uxstudio':
			return 'UX Studio';
		case 'plugin':
			return row.source_name || folder;
		case 'theme':
			return sprintf(
				/* translators: %s: theme name. */
				__( 'Theme %s', 'ux-studio' ),
				row.source_name || folder
			);
		case 'mu':
			return __( 'Must-use plugin', 'ux-studio' );
		default:
			return __( 'Unknown source', 'ux-studio' );
	}
}

function ruleOf( rules: Rules, id: string ): Rule {
	return rules[ id ] ?? NO_RULE;
}

/**
 * What the server will do with a request, applied to the screen at once (the answer of a heavy site may take a second).
 * Mirrors WidgetRules on the server; the answer of the server replaces this when it arrives.
 */
function applyLocal( rules: Rules, body: RulesRequest, allRoles: string[] ): Rules {
	if ( 'replace' === body.action ) {
		return body.rules ?? rules;
	}
	const next: Rules = { ...rules };
	const put = ( id: string, hidden: boolean, roles: string[] ): void => {
		if ( hidden || roles.length > 0 ) {
			next[ id ] = { hidden, roles };
		} else {
			delete next[ id ];
		}
	};
	// A set of roles that covers every role of the site means everybody.
	const norm = ( roles: string[] ): string[] => ( allRoles.length > 0 && allRoles.every( ( r ) => roles.includes( r ) ) ? [] : roles );
	for ( const item of body.items ?? [] ) {
		const cur = ruleOf( next, item.id );
		put( item.id, item.hidden ?? cur.hidden, item.roles ? norm( item.roles ) : cur.roles );
	}
	for ( const id of body.ids ?? [] ) {
		const cur = ruleOf( next, id );
		switch ( body.action ) {
			case 'hide':
				put( id, true, cur.roles );
				break;
			case 'show':
				put( id, false, cur.roles );
				break;
			case 'roles':
				put( id, cur.hidden, norm( body.roles ?? [] ) );
				break;
			case 'reset':
				delete next[ id ];
				break;
		}
	}
	return next;
}

/** Rules in a form that compares equal whatever the order (for "has anything changed"). */
function fingerprint( rules: Rules ): string {
	return JSON.stringify(
		Object.keys( rules )
			.sort()
			.map( ( id ) => {
				const rule = ruleOf( rules, id );
				return [ id, rule.hidden, [ ...rule.roles ].sort() ];
			} )
	);
}

/** The roles of a rule as small chips in a popover list; nothing chosen = everybody. */
function RoleSelect( {
	roles,
	value,
	onChange,
	label,
	disabled,
}: {
	roles: RoleInfo[];
	value: string[];
	onChange: ( next: string[] ) => void;
	label: string;
	disabled?: boolean;
} ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const names = new Map( roles.map( ( r ) => [ r.id, r.name ] ) );
	const shown = value.slice( 0, 2 );
	const rest = value.length - shown.length;
	// A long allow list (what the conversion of the old per-role lists produces) reads better as "everybody except ...".
	const excluded = roles.filter( ( r ) => ! value.includes( r.id ) );
	const asExcept = value.length > 0 && excluded.length > 0 && excluded.length <= 2 && excluded.length < value.length;

	return (
		<DialogTrigger>
			<Button
				className="uxs-wt__roles"
				isDisabled={ disabled }
				onPress={ () => setOpen( ( v ) => ! v ) }
				aria-label={ sprintf(
					/* translators: 1: what the control is for, 2: roles chosen or "everybody". */
					__( '%1$s: %2$s', 'ux-studio' ),
					label,
					asExcept
						? sprintf(
								/* translators: %s: role names. */
								__( 'everybody except %s', 'ux-studio' ),
								excluded.map( ( r ) => r.name ).join( ', ' )
						  )
						: value.length
						? value.map( ( v ) => names.get( v ) ?? v ).join( ', ' )
						: __( 'Everybody', 'ux-studio' )
				) }
			>
				{ value.length === 0 ? (
					<span className="uxs-wt__all">{ __( 'Everybody', 'ux-studio' ) }</span>
				) : asExcept ? (
					<span className="uxs-wt__chips">
						<span className="uxs-wt__all">{ __( 'All except', 'ux-studio' ) }</span>
						{ excluded.map( ( r ) => (
							<span key={ r.id } className="uxs-wt__chip is-except">
								{ r.name }
							</span>
						) ) }
					</span>
				) : (
					<span className="uxs-wt__chips">
						{ shown.map( ( v ) => (
							<span key={ v } className="uxs-wt__chip">
								{ names.get( v ) ?? v }
							</span>
						) ) }
						{ rest > 0 ? <span className="uxs-wt__chip is-more">+{ rest }</span> : null }
					</span>
				) }
				<ChevronDown size={ 14 } aria-hidden="true" />
			</Button>
			<Popover
				// Portalled to <body>, outside #ux-studio-root: carry the theme over (dark mode included).
				data-theme={ document.getElementById( 'ux-studio-root' )?.getAttribute( 'data-theme' ) ?? 'light' }
				maxHeight={ 340 }
				containerPadding={ 40 }
				isOpen={ open }
				onOpenChange={ setOpen }
				className="uxs-multiselect__popover uxs-portal"
			>
				{ /* Escape would clear the whole selection of a multiple list; here it only closes the popover. */ }
				<div
					onKeyDownCapture={ ( e ) => {
						if ( 'Escape' === e.key ) {
							e.stopPropagation();
							setOpen( false );
						}
					} }
				>
				<ListBox
					aria-label={ label }
					selectionMode="multiple"
					selectedKeys={ new Set( value ) }
					onSelectionChange={ ( keys ) => onChange( keys === 'all' ? roles.map( ( r ) => r.id ) : Array.from( keys ).map( String ) ) }
					className="uxs-multiselect__listbox"
				>
					{ roles.map( ( role ) => (
						<ListBoxItem key={ role.id } id={ role.id } textValue={ role.name } className="uxs-multiselect__item">
							{ role.name }
						</ListBoxItem>
					) ) }
				</ListBox>
				</div>
				<div className="uxs-wt__popfoot">
					<button
						type="button"
						className="button-link"
						disabled={ value.length === 0 }
						onClick={ () => {
							onChange( [] );
							setOpen( false );
						} }
					>
						{ __( 'Everybody (no limit)', 'ux-studio' ) }
					</button>
				</div>
			</Popover>
		</DialogTrigger>
	);
}

export default function WidgetTable( { hideAll }: { hideAll: boolean } ): JSX.Element {
	const { data, isLoading, error } = useQuery( {
		queryKey: KEY,
		queryFn: () => api< RulesPayload >( 'dashboard-widgets/rules' ),
	} );

	const baseline = useRef< string | null >( null );
	const baselineRules = useRef< Rules >( {} );
	const [ search, setSearch ] = useState( '' );
	const [ filter, setFilter ] = useState< Filter >( 'all' );
	const [ sourceFilter, setSourceFilter ] = useState( '' );
	const [ selected, setSelected ] = useState< Set< string > >( new Set() );
	const [ bulkRoles, setBulkRoles ] = useState< string[] >( [] );
	const [ toast, setToast ] = useState< number | undefined >( undefined );
	const allBox = useRef< HTMLInputElement >( null );

	const rules: Rules = useMemo( () => ( data?.rules && ! Array.isArray( data.rules ) ? data.rules : {} ), [ data ] );

	useEffect( () => {
		if ( data && null === baseline.current ) {
			baseline.current = fingerprint( rules );
			baselineRules.current = rules;
		}
	}, [ data, rules ] );

	const pending = useRef( 0 );
	const save = useMutation( {
		mutationFn: ( body: RulesRequest ) => api< RulesPayload >( 'dashboard-widgets/rules', { method: 'POST', body: JSON.stringify( body ) } ),
		onMutate: async ( body ): Promise< { previous?: RulesPayload } > => {
			pending.current += 1;
			await queryClient.cancelQueries( { queryKey: KEY } );
			const previous = queryClient.getQueryData< RulesPayload >( KEY );
			if ( previous ) {
				const current = previous.rules && ! Array.isArray( previous.rules ) ? previous.rules : {};
				queryClient.setQueryData< RulesPayload >( KEY, {
					...previous,
					rules: applyLocal( current, body, previous.roles.map( ( r ) => r.id ) ),
					notice: 'dismiss_notice' === body.action ? false : previous.notice,
				} );
			}
			return { previous };
		},
		onSuccess: ( payload, body ) => {
			// With more requests on their way, the screen keeps its own picture until the last answer.
			if ( pending.current <= 1 ) {
				queryClient.setQueryData( KEY, payload );
			}
			if ( 'dismiss_notice' !== body.action ) {
				setToast( pushToast( 'success', __( 'Saved.', 'ux-studio' ), toast ) );
			}
		},
		onError: ( e, _body, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData( KEY, context.previous );
			}
			setToast( pushToast( 'error', e instanceof Error ? e.message : String( e ), toast ) );
		},
		onSettled: () => {
			pending.current -= 1;
		},
	} );

	const forget = useMutation( {
		mutationFn: ( id: string ) => api< RulesPayload >( `dashboard-widgets/snapshot/${ encodeURIComponent( id ) }`, { method: 'DELETE' } ),
		onSuccess: ( payload, id ) => {
			queryClient.setQueryData( KEY, payload );
			setSelected( ( s ) => new Set( [ ...s ].filter( ( x ) => x !== id ) ) );
		},
		onError: ( e ) => setToast( pushToast( 'error', e instanceof Error ? e.message : String( e ), toast ) ),
	} );

	const refresh = useMutation( {
		mutationFn: async (): Promise< RulesPayload > => {
			const { url } = await api< { url: string } >( 'dashboard-widgets/refresh', { method: 'POST', body: '{}' } );
			// The Dashboard itself registers the widgets: load it in the background with the cookies of this administrator.
			const target = new URL( url, window.location.href );
			const controller = new AbortController();
			const timer = window.setTimeout( () => controller.abort(), 30_000 );
			try {
				const res = await fetch( target.pathname + target.search, { credentials: 'same-origin', signal: controller.signal } );
				await res.text();
			} catch {
				setToast( pushToast( 'error', __( 'The Dashboard did not answer in time, the list may be out of date.', 'ux-studio' ), toast ) );
			} finally {
				window.clearTimeout( timer );
			}
			return api< RulesPayload >( 'dashboard-widgets/rules' );
		},
		onSuccess: ( payload ) => queryClient.setQueryData( KEY, payload ),
	} );

	const widgets = data?.widgets ?? [];
	const roles = data?.roles ?? [];

	const sources = useMemo( () => {
		const map = new Map< string, string >();
		for ( const w of widgets ) {
			map.set( w.source, sourceLabel( w ) );
		}
		return [ ...map.entries() ].sort( ( a, b ) => a[ 1 ].localeCompare( b[ 1 ] ) );
	}, [ widgets ] );

	const rows = useMemo( () => {
		const needle = search.trim().toLocaleLowerCase();
		return widgets
			.filter( ( w ) => {
				const rule = ruleOf( rules, w.id );
				if ( 'hidden' === filter && ! rule.hidden ) {
					return false;
				}
				if ( 'limited' === filter && ( rule.hidden || 0 === rule.roles.length ) ) {
					return false;
				}
				if ( 'stale' === filter && ! w.stale ) {
					return false;
				}
				if ( sourceFilter && w.source !== sourceFilter ) {
					return false;
				}
				return ! needle || `${ w.title } ${ w.id } ${ sourceLabel( w ) }`.toLocaleLowerCase().includes( needle );
			} )
			.sort( ( a, b ) => a.title.localeCompare( b.title ) );
	}, [ widgets, rules, filter, sourceFilter, search ] );

	const counts = useMemo( () => {
		let hidden = 0;
		let limited = 0;
		let stale = 0;
		for ( const w of widgets ) {
			const rule = ruleOf( rules, w.id );
			hidden += rule.hidden ? 1 : 0;
			limited += ! rule.hidden && rule.roles.length > 0 ? 1 : 0;
			stale += w.stale ? 1 : 0;
		}
		return { hidden, limited, stale };
	}, [ widgets, rules ] );

	const visibleSelected = rows.filter( ( w ) => selected.has( w.id ) ).length;
	const allSelected = rows.length > 0 && visibleSelected === rows.length;

	useEffect( () => {
		if ( allBox.current ) {
			allBox.current.indeterminate = visibleSelected > 0 && ! allSelected;
		}
	}, [ visibleSelected, allSelected ] );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( error || ! data ) {
		return <p className="uxs-badge is-danger">{ error instanceof Error ? error.message : __( 'The widget list could not be loaded.', 'ux-studio' ) }</p>;
	}

	// Changes are shown at once and saved in the background; only forgetting a widget waits for the server.
	const busy = forget.isPending;
	const changed = null !== baseline.current && baseline.current !== fingerprint( rules );
	const ids = [ ...selected ];

	const toggle = ( id: string ): void =>
		setSelected( ( s ) => {
			const next = new Set( s );
			if ( next.has( id ) ) {
				next.delete( id );
			} else {
				next.add( id );
			}
			return next;
		} );

	return (
		<div className="uxs-wt">
			{ data.notice ? (
				<div className="uxs-wt__notice" role="status">
					<Info size={ 18 } aria-hidden />
					<div>
						<strong>{ __( 'The settings were converted from the original format.', 'ux-studio' ) }</strong>
						<p>
							{ __( 'The lists of widgets hidden for everybody and per role are now rules in the table below. A widget that was hidden for some roles is shown only to the other roles. Check the result and change what you need.', 'ux-studio' ) }
						</p>
					</div>
					<button type="button" className="button" onClick={ () => save.mutate( { action: 'dismiss_notice' } ) }>
						{ __( 'Got it', 'ux-studio' ) }
					</button>
				</div>
			) : null }

			{ hideAll ? (
				<p className="uxs-callout">
					<Info size={ 16 } aria-hidden />
					<span>{ __( '"Hide all dashboard widgets" is on, so the rules below do not apply. Only the widgets of UX Studio stay.', 'ux-studio' ) }</span>
				</p>
			) : null }

			<div className="uxs-wt__toolbar">
				<label className="uxs-wt__search">
					<span className="screen-reader-text">{ __( 'Search widgets', 'ux-studio' ) }</span>
					<Search size={ 16 } aria-hidden />
					<input type="search" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } placeholder={ __( 'Search by name or ID…', 'ux-studio' ) } maxLength={ 100 } />
				</label>
				<label>
					<span className="screen-reader-text">{ __( 'Show', 'ux-studio' ) }</span>
					<select value={ filter } onChange={ ( e ) => setFilter( e.target.value as Filter ) }>
						<option value="all">{ __( 'All widgets', 'ux-studio' ) }</option>
						<option value="hidden">{ __( 'Hidden', 'ux-studio' ) }</option>
						<option value="limited">{ __( 'Limited to roles', 'ux-studio' ) }</option>
						<option value="stale">{ __( 'Not found', 'ux-studio' ) }</option>
					</select>
				</label>
				<label>
					<span className="screen-reader-text">{ __( 'Source', 'ux-studio' ) }</span>
					<select value={ sourceFilter } onChange={ ( e ) => setSourceFilter( e.target.value ) }>
						<option value="">{ __( 'All sources', 'ux-studio' ) }</option>
						{ sources.map( ( [ key, label ] ) => (
							<option key={ key } value={ key }>
								{ label }
							</option>
						) ) }
					</select>
				</label>
				<button type="button" className="button" disabled={ refresh.isPending } onClick={ () => refresh.mutate() }>
					<RefreshCw size={ 14 } aria-hidden className={ refresh.isPending ? 'uxs-spin' : undefined } /> { __( 'Refresh list', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ ! changed || busy } onClick={ () => save.mutate( { action: 'replace', rules: baselineRules.current } ) }>
					<Undo2 size={ 14 } aria-hidden /> { __( 'Revert changes', 'ux-studio' ) }
				</button>
			</div>

			<p className="uxs-wt__stats" aria-live="polite">
				<span>{ sprintf( _n( '%d widget', '%d widgets', widgets.length, 'ux-studio' ), widgets.length ) }</span>
				<span>{ sprintf( __( 'Hidden: %d', 'ux-studio' ), counts.hidden ) }</span>
				<span>{ sprintf( __( 'Limited to roles: %d', 'ux-studio' ), counts.limited ) }</span>
				{ counts.stale > 0 ? <span>{ sprintf( __( 'Not found: %d', 'ux-studio' ), counts.stale ) }</span> : null }
				<span className="uxs-wt__scan">
					{ data.scan_at > 0
						? sprintf(
								/* translators: %s: date and time of the last refresh. */
								__( 'Last refreshed %s', 'ux-studio' ),
								formatDate( data.scan_at )
						  )
						: __( 'Not refreshed yet', 'ux-studio' ) }
				</span>
			</p>

			{ widgets.length === 0 ? (
				<div className="uxs-wt__empty">
					<h3>{ __( 'No widgets known yet', 'ux-studio' ) }</h3>
					<p>{ __( 'The list is filled when the Dashboard loads. Open the Dashboard or click Refresh list.', 'ux-studio' ) }</p>
				</div>
			) : rows.length === 0 ? (
				<div className="uxs-wt__empty">
					<h3>{ __( 'No widget matches', 'ux-studio' ) }</h3>
					<p>{ __( 'Change the search or the filters.', 'ux-studio' ) }</p>
				</div>
			) : (
				<table className="uxs-wt__table">
					<thead>
						<tr>
							<th className="uxs-wt__c-check">
								<input
									ref={ allBox }
									type="checkbox"
									checked={ allSelected }
									aria-label={ __( 'Select all listed widgets', 'ux-studio' ) }
									onChange={ ( e ) => setSelected( e.target.checked ? new Set( [ ...selected, ...rows.map( ( w ) => w.id ) ] ) : new Set( [ ...selected ].filter( ( id ) => ! rows.some( ( w ) => w.id === id ) ) ) ) }
								/>
							</th>
							<th>{ __( 'Widget', 'ux-studio' ) }</th>
							<th>{ __( 'Source', 'ux-studio' ) }</th>
							<th>{ __( 'Area', 'ux-studio' ) }</th>
							<th className="uxs-wt__c-hide">{ __( 'Hide', 'ux-studio' ) }</th>
							<th>{ __( 'Show to', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( w ) => {
							const rule = ruleOf( rules, w.id );
							const isSel = selected.has( w.id );
							return (
								<tr key={ w.id } className={ `${ rule.hidden ? 'is-hidden' : '' }${ w.stale ? ' is-stale' : '' }${ isSel ? ' is-selected' : '' }` }>
									<td className="uxs-wt__c-check">
										<input type="checkbox" checked={ isSel } aria-label={ sprintf( __( 'Select %s', 'ux-studio' ), w.title ) } onChange={ () => toggle( w.id ) } />
									</td>
									<td data-label={ __( 'Widget', 'ux-studio' ) } className="uxs-wt__c-title">
										<strong>{ w.title }</strong>
										<code>{ w.id }</code>
										{ w.stale ? (
											<span className="uxs-wt__stale">
												<span className="uxs-badge is-warning">{ __( 'not found', 'ux-studio' ) }</span>
												<button
													type="button"
													className="button-link"
													aria-label={ sprintf( __( 'Remove the entry %s', 'ux-studio' ), w.title ) }
													disabled={ forget.isPending }
													onClick={ () => {
														if ( window.confirm( __( 'Remove this widget from the list together with its rule?', 'ux-studio' ) ) ) {
															forget.mutate( w.id );
														}
													} }
												>
													<Trash2 size={ 14 } aria-hidden /> { __( 'Remove', 'ux-studio' ) }
												</button>
											</span>
										) : null }
									</td>
									<td data-label={ __( 'Source', 'ux-studio' ) } className="uxs-wt__c-source">
										{ sourceLabel( w ) }
										{ w.guess ? <span className="uxs-wt__guess">{ __( 'estimate', 'ux-studio' ) }</span> : null }
									</td>
									<td data-label={ __( 'Area', 'ux-studio' ) } className="uxs-wt__c-area">
										{ contextLabel( w.context ) }
									</td>
									<td data-label={ __( 'Hide', 'ux-studio' ) } className="uxs-wt__c-hide">
										<button
											type="button"
											className={ `uxs-switch${ rule.hidden ? ' is-on' : '' }` }
											aria-pressed={ rule.hidden }
											aria-label={ sprintf( __( 'Hide %s for everybody', 'ux-studio' ), w.title ) }
											disabled={ busy }
											onClick={ () => save.mutate( { items: [ { id: w.id, hidden: ! rule.hidden } ] } ) }
										/>
									</td>
									<td data-label={ __( 'Show to', 'ux-studio' ) }>
										<RoleSelect
											roles={ roles }
											value={ rule.roles }
											label={ sprintf( __( 'Roles that see %s', 'ux-studio' ), w.title ) }
											disabled={ rule.hidden || busy }
											onChange={ ( next ) => save.mutate( { items: [ { id: w.id, roles: next } ] } ) }
										/>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }

			{ ids.length > 0 ? (
				<div className="uxs-wt__bulk" role="region" aria-label={ __( 'Actions for the selected widgets', 'ux-studio' ) }>
					<strong>{ sprintf( __( '%d selected', 'ux-studio' ), ids.length ) }</strong>
					<button type="button" className="button" disabled={ busy } onClick={ () => save.mutate( { action: 'hide', ids } ) }>
						{ __( 'Hide', 'ux-studio' ) }
					</button>
					<button type="button" className="button" disabled={ busy } onClick={ () => save.mutate( { action: 'show', ids } ) }>
						{ __( 'Show', 'ux-studio' ) }
					</button>
					<span className="uxs-wt__bulkroles">
						<span>{ __( 'Show to:', 'ux-studio' ) }</span>
						<RoleSelect roles={ roles } value={ bulkRoles } onChange={ setBulkRoles } label={ __( 'Roles for the selected widgets', 'ux-studio' ) } />
						<button type="button" className="button" disabled={ busy } onClick={ () => save.mutate( { action: 'roles', ids, roles: bulkRoles } ) }>
							{ __( 'Apply', 'ux-studio' ) }
						</button>
					</span>
					<button type="button" className="button" disabled={ busy } onClick={ () => save.mutate( { action: 'reset', ids } ) }>
						{ __( 'Reset to default (everybody)', 'ux-studio' ) }
					</button>
					<span className="uxs-wt__bulkend">
						<button type="button" className="button-link" onClick={ () => setSelected( new Set( rows.map( ( w ) => w.id ) ) ) }>
							{ __( 'Select all', 'ux-studio' ) }
						</button>
						<button type="button" className="button-link" onClick={ () => setSelected( new Set() ) }>
							{ __( 'Select none', 'ux-studio' ) }
						</button>
					</span>
				</div>
			) : null }
		</div>
	);
}
