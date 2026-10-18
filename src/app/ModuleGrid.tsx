/**
 * Landing page: the module "šachovnice" - tiles for every module with an
 * enable/disable switch. Only modules that actually have a settings/detail
 * page (m.settings) are clickable into it - modules without any
 * configuration (pure on/off toggles) render as static cards, matching the
 * legacy plugin's behaviour where hasSettings() gated the "Configure" link.
 */
import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronRight, LayoutGrid, List, LoaderCircle, PowerOff } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from './api';
import { ModuleIcon } from './moduleIcons';
import { getViewMode, setViewMode, type ViewMode } from './prefs';
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
	const [ view, setView ] = useState< ViewMode >( getViewMode() );
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

	const enabledCount = modules.filter( ( m ) => m.enabled ).length;

	const needle = search.trim().toLocaleLowerCase();
	const visible = modules.filter(
		( m ) =>
			( 'all' === group || m.group === group ) &&
			( 'all' === status || ( 'on' === status ) === m.enabled ) &&
			( '' === needle || `${ m.name } ${ m.description } ${ m.id }`.toLocaleLowerCase().includes( needle ) )
	);
	const countIn = ( id: string ): number => modules.filter( ( m ) => m.group === id ).length;

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

	const changeView = ( mode: ViewMode ): void => {
		setView( mode );
		setViewMode( mode );
	};

	return (
		<>
			<header className="uxs-pagehead">
				<h1>{ __( 'Modules', 'ux-studio' ) }</h1>
				<div className="uxs-pagehead__actions">
					<div className="uxs-viewswitch" role="group" aria-label={ __( 'Layout', 'ux-studio' ) }>
						<button
							type="button"
							className={ view === 'grid' ? 'is-active' : '' }
							aria-label={ __( 'Grid view', 'ux-studio' ) }
							aria-pressed={ view === 'grid' }
							onClick={ () => changeView( 'grid' ) }
						>
							<LayoutGrid size={ 16 } aria-hidden />
						</button>
						<button
							type="button"
							className={ view === 'list' ? 'is-active' : '' }
							aria-label={ __( 'List view', 'ux-studio' ) }
							aria-pressed={ view === 'list' }
							onClick={ () => changeView( 'list' ) }
						>
							<List size={ 16 } aria-hidden />
						</button>
					</div>
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
						{ __( 'All', 'ux-studio' ) } ({ modules.length })
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
					<option value="on">{ sprintf( /* translators: %d: number of enabled modules. */ __( 'Enabled (%d)', 'ux-studio' ), enabledCount ) }</option>
					<option value="off">{ sprintf( /* translators: %d: number of disabled modules. */ __( 'Disabled (%d)', 'ux-studio' ), modules.length - enabledCount ) }</option>
				</select>
			</div>
			{ 0 === visible.length ? <p className="uxs-modulefilter__empty">{ __( 'No module matches the filter.', 'ux-studio' ) }</p> : null }
			<div className={ `uxs-grid${ view === 'list' ? ' is-list' : '' }` }>
				{ visible.map( ( m ) => {
					const clickable = m.settings;
					const interactionProps = clickable
						? {
								role: 'button' as const,
								tabIndex: 0,
								onClick: () => navigate( 'module', { id: m.id } ),
								onKeyDown: ( e: React.KeyboardEvent ) => {
									if ( e.key === 'Enter' ) {
										navigate( 'module', { id: m.id } );
									}
								},
						  }
						: {};

					return (
						<div
							key={ m.id }
							className={ `uxs-tile${ clickable ? '' : ' is-static' }` }
							{ ...interactionProps }
						>
							<div className="uxs-tile__head">
								<span className="uxs-tile__icon">
									<ModuleIcon name={ m.icon } size={ 20 } />
								</span>
								{ view === 'list' && <span className="uxs-tile__name">{ m.name }</span> }
								{ view === 'list' && <span className="uxs-tile__desc">{ m.description }</span> }
								<button
									type="button"
									className={ `uxs-switch${ m.enabled ? ' is-on' : '' }` }
									aria-label={
										m.enabled
											? __( 'Disable module', 'ux-studio' )
											: __( 'Enable module', 'ux-studio' )
									}
									aria-pressed={ m.enabled }
									onClick={ ( e ) => {
										e.stopPropagation();
										toggle.mutate( m );
									} }
								/>
								{ view === 'list' && clickable && (
									<ChevronRight size={ 16 } className="uxs-tile__chevron" aria-hidden />
								) }
							</div>
							{ view === 'grid' && <span className="uxs-tile__name">{ m.name }</span> }
							{ view === 'grid' && <span className="uxs-tile__desc">{ m.description }</span> }
							{ view === 'grid' && clickable && (
								<span className="uxs-tile__configure">
									{ __( 'Configure', 'ux-studio' ) }
									<ChevronRight size={ 14 } aria-hidden />
								</span>
							) }
						</div>
					);
				} ) }
			</div>
		</>
	);
}
