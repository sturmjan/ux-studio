/**
 * Page of a virtual group tile (e.g. "Duplication" = duplicate-post +
 * duplicate-menu). Every feature keeps its own on/off switch and its own
 * settings page, so a site can run just one part of the group.
 * Route: #/module?id=<group-id>
 */
import { Suspense } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from './api';
import { ModuleIcon } from './moduleIcons';
import { hashParam, navigate } from './route';
import { ModuleSettings } from './ModuleSettings';
import { MODULE_PAGES } from '../modules/registry';
import { FEATURE_VIEWS, useViewEnabled } from './featureViews';
import { ExportModuleLink } from './transfer/ExportLink';

export function GroupPage( { groupId }: { groupId: string } ): JSX.Element {
	const active = hashParam( 'feature' ) ?? '';
	const activeView = FEATURE_VIEWS[ active ]?.find( ( v ) => v.id === hashParam( 'view' ) );
	const viewState = useViewEnabled( active, activeView );
	const { data: modules, isLoading } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );

	const toggle = useMutation( {
		mutationFn: ( m: ModuleInfo ) =>
			api( `modules/${ m.id }`, { method: 'POST', body: JSON.stringify( { enabled: ! m.enabled } ) } ),
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

	const group = modules?.find( ( m ) => m.id === groupId );
	if ( isLoading || ! modules || ! group ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const features = ( group.children ?? [] )
		.map( ( id ) => modules.find( ( m ) => m.id === id ) )
		.filter( ( m ): m is ModuleInfo => !! m );
	const enabledCount = features.filter( ( f ) => f.enabled ).length;
	const current = features.find( ( f ) => f.id === active ) ?? features[ 0 ];
	if ( ! current ) {
		return <p>{ group.description }</p>;
	}
	const Custom = MODULE_PAGES[ current.id ];
	const view = activeView?.enabledKey && active === current.id ? activeView : FEATURE_VIEWS[ current.id ]?.find( ( v ) => v.id === hashParam( 'view' ) );
	// A view with its own switch drives the header switch and body; otherwise the feature's.
	const switchOn = view?.enabledKey ? viewState.enabled : current.enabled;
	const onSwitch = (): void => ( view?.enabledKey ? viewState.toggle() : toggle.mutate( current ) );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ group.name }
				</h1>
				<span className="uxs-badge">
					{ sprintf(
						/* translators: 1: enabled features, 2: all features in the group. */
						__( '%1$d of %2$d on', 'ux-studio' ),
						enabledCount,
						features.length
					) }
				</span>
			</header>
			<p className="uxs-group__intro">{ group.description }</p>
			<section className="uxs-group__item">
				<div className="uxs-group__head">
					<span className="uxs-tile__icon">
						<ModuleIcon name={ current.icon } size={ 20 } />
					</span>
					<div className="uxs-group__text">
						<h2 className="uxs-tile__name">{ view ? `${ current.name }: ${ view.label }` : current.name }</h2>
						<span className="uxs-tile__desc">{ view ? '' : current.description }</span>
					</div>
					<ExportModuleLink moduleId={ current.id } />
					<span className="uxs-group__state">{ switchOn ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }</span>
					<button
						type="button"
						className={ `uxs-switch${ switchOn ? ' is-on' : '' }` }
						aria-label={ switchOn ? __( 'Disable module', 'ux-studio' ) : __( 'Enable module', 'ux-studio' ) }
						aria-pressed={ switchOn }
						onClick={ onSwitch }
					/>
				</div>
				{ current.enabled && current.settings && switchOn ? (
					<div className="uxs-group__body uxs-embedded" key={ `${ current.id }-${ view?.id ?? '' }` }>
						{ Custom ? (
							<Suspense
								fallback={
									<div className="uxs-loading">
										<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
									</div>
								}
							>
								<Custom />
							</Suspense>
						) : (
							<ModuleSettings moduleId={ current.id } embedded />
						) }
					</div>
				) : null }
			</section>
		</>
	);
}

/** "← Group name" link shown above a feature's own page when it belongs to a group. */
export function GroupBreadcrumb( { moduleId }: { moduleId: string } ): JSX.Element | null {
	const { data: modules } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );
	const module = modules?.find( ( m ) => m.id === moduleId );
	const parent = module?.parent ? modules?.find( ( m ) => m.id === module.parent ) : undefined;
	if ( ! parent ) {
		return null;
	}
	return (
		<a
			href={ `#/module?id=${ parent.id }` }
			className="uxs-group__crumb"
			onClick={ ( e ) => {
				e.preventDefault();
				navigate( 'module', { id: parent.id } );
			} }
		>
			<ArrowLeft size={ 14 } aria-hidden /> { parent.name }
		</a>
	);
}
