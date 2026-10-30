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
import { ChevronDown, ChevronRight, LoaderCircle, PowerOff, TriangleAlert } from 'lucide-react';
import { api, queryClient, type ApiError, type ModuleInfo } from './api';
import { isOverlapError, OverlapModal, OverlapPanel, refreshOverlaps, useOverlaps } from './Overlaps';
import { ModuleIcon } from './moduleIcons';
import { WizardHint } from './WizardHint';
import { navigate } from './route';

/** meta.json `group` values in display order. */
export const GROUPS: { id: string; label: string }[] = [
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
	const [ conflictsOpen, setConflictsOpen ] = useState( false );
	const { data: overlaps } = useOverlaps();
	const { data: modules, isLoading, error } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );

	// Set when enabling was refused (409): the modal explains which plugin overlaps.
	const [ blocked, setBlocked ] = useState< { m: ModuleInfo; error: ApiError } | null >( null );

	const toggle = useMutation( {
		mutationFn: ( { m, confirm }: { m: ModuleInfo; confirm?: boolean } ) =>
			api( `modules/${ m.id }`, {
				method: 'POST',
				body: JSON.stringify( { enabled: ! m.enabled, ...( confirm ? { confirm_overlap: true } : {} ) } ),
			} ),
		// Optimistic: flip the switch immediately instead of waiting on a
		// refetch, so the click has instant visible feedback. Rolls back the
		// cache on failure.
		onMutate: async ( { m } ) => {
			await queryClient.cancelQueries( { queryKey: [ 'modules' ] } );
			const previous = queryClient.getQueryData< ModuleInfo[] >( [ 'modules' ] );
			queryClient.setQueryData< ModuleInfo[] >( [ 'modules' ], ( old ) =>
				old?.map( ( x ) => ( x.id === m.id ? { ...x, enabled: ! x.enabled } : x ) )
			);
			return { previous };
		},
		onSuccess: () => setBlocked( null ),
		onError: ( err, { m }, context ) => {
			// Revert the optimistic switch.
			if ( context?.previous ) {
				queryClient.setQueryData( [ 'modules' ], context.previous );
			}
			if ( isOverlapError( err ) ) {
				setBlocked( { m, error: err } );
			}
		},
		onSettled: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void refreshOverlaps();
		},
	} );

	const deactivateAll = useMutation( {
		mutationFn: () => api( 'modules/deactivate-all', { method: 'POST' } ),
		onSuccess: () => {
			void refreshOverlaps();
			return queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
		},
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
	// Everything is counted in features (what a switch controls): a group such as "Dashboard" counts as the features
	// inside it, a module without a group counts as one. So All = Enabled + Disabled, whatever the tab.
	const featuresOf = ( m: ModuleInfo ): ModuleInfo[] => {
		const subs = childrenOf( m );
		return subs.length > 0 ? subs : [ m ];
	};
	const matchesStatus = ( m: ModuleInfo ): boolean => 'all' === status || ( 'on' === status ) === m.enabled;
	const visible = topLevel.filter(
		( m ) =>
			( 'all' === group || m.group === group ) &&
			featuresOf( m ).some( matchesStatus ) &&
			( '' === needle || haystack( m ).includes( needle ) )
	);
	const featureCount = ( list: ModuleInfo[] ): number => list.reduce( ( sum, m ) => sum + featuresOf( m ).length, 0 );
	const countIn = ( id: string ): number => featureCount( topLevel.filter( ( m ) => m.group === id ) );
	const totalFeatures = featureCount( topLevel );
	const featuresEnabled = topLevel.reduce( ( sum, m ) => sum + featuresOf( m ).filter( ( f ) => f.enabled ).length, 0 );
	const conflictCount = overlaps ? overlaps.full.length + overlaps.customiser.length + overlaps.partial.length : 0;

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
			<WizardHint />
			{ conflictCount > 0 ? (
				<div className="uxs-overlaps">
					<div className="uxs-overlaps__summary">
						<TriangleAlert size={ 16 } aria-hidden />
						<strong>
							{ sprintf(
								/* translators: %d: number of conflicts with other plugins. */
								_n( '%d conflict with a plugin', '%d conflicts with plugins', conflictCount, 'ux-studio' ),
								conflictCount
							) }
						</strong>
						<button
							type="button"
							className="button button-small"
							aria-expanded={ conflictsOpen }
							onClick={ () => setConflictsOpen( ! conflictsOpen ) }
						>
							{ conflictsOpen ? __( 'Hide', 'ux-studio' ) : __( 'Show', 'ux-studio' ) }
							<ChevronDown size={ 14 } aria-hidden style={ { transform: conflictsOpen ? 'rotate(180deg)' : 'none' } } />
						</button>
					</div>
					{ conflictsOpen ? <OverlapPanel compact /> : null }
				</div>
			) : (
				<OverlapPanel />
			) }
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
						{ __( 'All', 'ux-studio' ) } ({ totalFeatures })
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
					<option value="on">{ sprintf( /* translators: %d: number of enabled features. */ __( 'Enabled (%d)', 'ux-studio' ), featuresEnabled ) }</option>
					<option value="off">{ sprintf( /* translators: %d: number of disabled features. */ __( 'Disabled (%d)', 'ux-studio' ), totalFeatures - featuresEnabled ) }</option>
				</select>
			</div>
			<p className="uxs-modulefilter__empty" style={ { marginTop: 0 } }>
				{ sprintf(
					/* translators: 1: number of features (things with their own switch), 2: number of rows in the list (modules and groups). */
					__( 'The numbers count features, the things with their own switch. A group counts as the features inside it. %1$d features in %2$d rows.', 'ux-studio' ),
					totalFeatures,
					topLevel.length
				) }
			</p>
			{ 0 === visible.length ? <p className="uxs-modulefilter__empty">{ __( 'No module matches the filter.', 'ux-studio' ) }</p> : null }
			<div className="uxs-grid is-list">
				{ visible.map( ( m ) => {
					const subs = childrenOf( m ).filter( matchesStatus );
					return (
						<div key={ m.id } className="uxs-rowgroup">
							<ModuleRow m={ m } onToggle={ ( x ) => toggle.mutate( { m: x } ) } />
							{ subs.length > 0 && (
								<div className="uxs-rowgroup__subs">
									{ subs.map( ( c ) => (
										<ModuleRow key={ c.id } m={ c } sub onToggle={ ( x ) => toggle.mutate( { m: x } ) } />
									) ) }
								</div>
							) }
						</div>
					);
				} ) }
			</div>
			{ blocked && (
				<OverlapModal
					error={ blocked.error }
					moduleName={ blocked.m.name }
					busy={ toggle.isPending }
					onClose={ () => setBlocked( null ) }
					onConfirm={ () => toggle.mutate( { m: blocked.m, confirm: true } ) }
				/>
			) }
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
				{ /* The name may wrap to two lines; the grid CSS would cut it with an ellipsis. */ }
				<span
					className="uxs-tile__name"
					title={ m.name }
					style={ { flex: sub ? '0 1 244px' : '0 1 260px', whiteSpace: 'normal', overflow: 'visible', textOverflow: 'clip', lineHeight: 1.3 } }
				>
					{ m.name }
				</span>
				<span className="uxs-tile__desc" title={ m.description }>
					{ m.description }
				</span>
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
