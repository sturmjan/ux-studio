/**
 * Landing page: the module list - one row per module with an enable/disable
 * switch. A virtual group (several features bundled in one entry) lists its
 * features as indented sub-rows, each with its own switch. Only modules that
 * actually have a settings/detail page (m.settings) are clickable into it -
 * modules without any configuration render as static rows.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronRight, LoaderCircle, PowerOff } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from './api';
import { ModuleIcon } from './moduleIcons';
import { navigate } from './route';

/** meta.json `group` values in display order. */
const GROUPS: { id: string; label: string }[] = [
	{ id: 'security', label: __( 'Security', 'ux-studio' ) },
	{ id: 'content', label: __( 'Content', 'ux-studio' ) },
	{ id: 'media', label: __( 'Media', 'ux-studio' ) },
	{ id: 'admin', label: __( 'Administration', 'ux-studio' ) },
	{ id: 'performance', label: __( 'Performance', 'ux-studio' ) },
	{ id: 'developer', label: __( 'Developer', 'ux-studio' ) },
];

type StatusFilter = 'all' | 'on' | 'off';

export function ModuleGrid(): JSX.Element {
	const [ group, setGroup ] = useState( 'all' );
	const [ status, setStatus ] = useState< StatusFilter >( 'all' );
	const [ search, setSearch ] = useState( '' );
	const { data: modules, isLoading, error } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );

	const toggle = useMutation( {
		mutationFn: ( m: ModuleInfo ) =>
			api( `modules/${ m.id }`, {
				method: 'POST',
				body: JSON.stringify( { enabled: ! m.enabled } ),
			} ),
		// Optimistic: flip the switch immediately instead of waiting on a
		// refetch, so the click has instant visible feedback. Rolls back the
		// cache on failure.
		onMutate: async ( m ) => {
			await queryClient.cancelQueries( { queryKey: [ 'modules' ] } );
			const previous = queryClient.getQueryData< ModuleInfo[] >( [ 'modules' ] );
			queryClient.setQueryData< ModuleInfo[] >( [ 'modules' ], ( old ) =>
				old?.map( ( x ) => ( x.id === m.id ? { ...x, enabled: ! x.enabled } : x ) )
			);
			return { previous };
		},
		onError: ( _err, _m, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData( [ 'modules' ], context.previous );
			}
		},
		onSettled: () => void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } ),
	} );

	const deactivateAll = useMutation( {
		mutationFn: () => api( 'modules/deactivate-all', { method: 'POST' } ),
		onSuccess: () => queryClient.invalidateQueries( { queryKey: [ 'modules' ] } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( error || ! modules ) {
		return <p role="alert">{ __( 'Failed to load modules.', 'ux-studio' ) }</p>;
	}

	// Real (non-group) modules drive "deactivate all"; the grid shows only
	// top-level tiles - features nested in a group live on the group's page.
	const enabledCount = modules.filter( ( m ) => m.enabled && ! m.children?.length ).length;
	const byId = new Map( modules.map( ( m ) => [ m.id, m ] ) );
	const topLevel = modules.filter( ( m ) => ! m.parent );
	const childrenOf = ( m: ModuleInfo ): ModuleInfo[] =>
		( m.children ?? [] ).map( ( id ) => byId.get( id ) ).filter( ( c ): c is ModuleInfo => !! c );
	const haystack = ( m: ModuleInfo ): string =>
		[ m, ...childrenOf( m ) ].map( ( x ) => `${ x.name } ${ x.description } ${ x.id }` ).join( ' ' ).toLocaleLowerCase();

	const needle = search.trim().toLocaleLowerCase();
	const visible = topLevel.filter(
		( m ) =>
			( 'all' === group || m.group === group ) &&
			( 'all' === status || ( 'on' === status ) === m.enabled ) &&
			( '' === needle || haystack( m ).includes( needle ) )
	);
	const countIn = ( id: string ): number => topLevel.filter( ( m ) => m.group === id ).length;
	const topEnabled = topLevel.filter( ( m ) => m.enabled ).length;

	const onDeactivateAll = (): void => {
		if ( enabledCount === 0 || deactivateAll.isPending ) {
			return;
		}
		// eslint-disable-next-line no-alert
		if (
			window.confirm(
				sprintf(
					/* translators: %d: number of currently active modules. */
					_n(
						'Deactivate %d active module?',
						'Deactivate all %d active modules?',
						enabledCount,
						'ux-studio'
					),
					enabledCount
				)
			)
		) {
			deactivateAll.mutate();
		}
	};

	return (
		<>
			<header className="uxs-pagehead">
				<h1>{ __( 'Modules', 'ux-studio' ) }</h1>
				<div className="uxs-pagehead__actions">
					<button
						type="button"
						className="button"
						disabled={ enabledCount === 0 || deactivateAll.isPending }
						onClick={ onDeactivateAll }
					>
						<PowerOff size={ 14 } aria-hidden />
						{ __( 'Deactivate all modules', 'ux-studio' ) }
					</button>
				</div>
			</header>
			<div className="uxs-modulefilter">
				<input
					type="search"
					className="uxs-modulefilter__search"
					placeholder={ __( 'Search modules…', 'ux-studio' ) }
					aria-label={ __( 'Search modules', 'ux-studio' ) }
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
				/>
				<div className="uxs-subtabs" role="tablist" aria-label={ __( 'Module group', 'ux-studio' ) }>
					<button type="button" role="tab" aria-selected={ 'all' === group } className={ 'all' === group ? 'is-active' : '' } onClick={ () => setGroup( 'all' ) }>
						{ __( 'All', 'ux-studio' ) } ({ topLevel.length })
					</button>
					{ GROUPS.filter( ( g ) => countIn( g.id ) > 0 ).map( ( g ) => (
						<button key={ g.id } type="button" role="tab" aria-selected={ g.id === group } className={ g.id === group ? 'is-active' : '' } onClick={ () => setGroup( g.id ) }>
							{ g.label } ({ countIn( g.id ) })
						</button>
					) ) }
				</div>
				<select
					className="uxs-modulefilter__status"
					aria-label={ __( 'Status', 'ux-studio' ) }
					value={ status }
					onChange={ ( e ) => setStatus( e.target.value as StatusFilter ) }
				>
					<option value="all">{ __( 'All statuses', 'ux-studio' ) }</option>
					<option value="on">{ sprintf( /* translators: %d: number of enabled modules. */ __( 'Enabled (%d)', 'ux-studio' ), topEnabled ) }</option>
					<option value="off">{ sprintf( /* translators: %d: number of disabled modules. */ __( 'Disabled (%d)', 'ux-studio' ), topLevel.length - topEnabled ) }</option>
				</select>
			</div>
			{ 0 === visible.length ? <p className="uxs-modulefilter__empty">{ __( 'No module matches the filter.', 'ux-studio' ) }</p> : null }
			<div className="uxs-grid is-list">
				{ visible.map( ( m ) => {
					const subs = childrenOf( m );
					return (
						<div key={ m.id } className="uxs-rowgroup">
							<ModuleRow m={ m } onToggle={ ( x ) => toggle.mutate( x ) } />
							{ subs.length > 0 && (
								<div className="uxs-rowgroup__subs">
									{ subs.map( ( c ) => (
										<ModuleRow key={ c.id } m={ c } sub onToggle={ ( x ) => toggle.mutate( x ) } />
									) ) }
								</div>
							) }
						</div>
					);
				} ) }
			</div>
		</>
	);
}

interface ModuleRowProps {
	m: ModuleInfo;
	sub?: boolean;
	onToggle: ( m: ModuleInfo ) => void;
}

function ModuleRow( { m, sub = false, onToggle }: ModuleRowProps ): JSX.Element {
	const clickable = m.settings;
	const open = (): void => navigate( 'module', { id: m.id } );

	return (
		<div
			className={ `uxs-tile${ clickable ? '' : ' is-static' }${ sub ? ' is-sub' : '' }` }
			{ ...( clickable
				? {
						role: 'button' as const,
						tabIndex: 0,
						onClick: open,
						onKeyDown: ( e: React.KeyboardEvent ) => {
							if ( e.key === 'Enter' ) {
								open();
							}
						},
				  }
				: {} ) }
		>
			<div className="uxs-tile__head">
				<span className="uxs-tile__icon">
					<ModuleIcon name={ m.icon } size={ sub ? 16 : 20 } />
				</span>
				<span className="uxs-tile__name">{ m.name }</span>
				<span className="uxs-tile__desc">{ m.description }</span>
				<button
					type="button"
					className={ `uxs-switch${ m.enabled ? ' is-on' : '' }` }
					aria-label={ m.enabled ? __( 'Disable module', 'ux-studio' ) : __( 'Enable module', 'ux-studio' ) }
					aria-pressed={ m.enabled }
					onClick={ ( e ) => {
						e.stopPropagation();
						onToggle( m );
					} }
				/>
				{ clickable ? (
					<ChevronRight size={ 16 } className="uxs-tile__chevron" aria-hidden />
				) : (
					<span className="uxs-tile__chevron" aria-hidden style={ { width: 16 } } />
				) }
			</div>
		</div>
	);
}
