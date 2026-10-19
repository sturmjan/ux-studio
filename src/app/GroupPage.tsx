/**
 * Page of a virtual group tile (e.g. "Duplication" = duplicate-post +
 * duplicate-menu). Every feature keeps its own on/off switch and its own
 * settings page, so a site can run just one part of the group.
 * Route: #/module?id=<group-id>
 */
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowLeft, ChevronRight, LoaderCircle } from 'lucide-react';
import { api, queryClient, type ModuleInfo } from './api';
import { ModuleIcon } from './moduleIcons';
import { navigate } from './route';

export function GroupPage( { groupId }: { groupId: string } ): JSX.Element {
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
			<div className="uxs-group">
				{ features.map( ( f ) => (
					<div key={ f.id } className={ `uxs-group__item${ f.enabled ? ' is-on' : '' }` }>
						<span className="uxs-tile__icon">
							<ModuleIcon name={ f.icon } size={ 20 } />
						</span>
						<div className="uxs-group__text">
							<span className="uxs-tile__name">{ f.name }</span>
							<span className="uxs-tile__desc">{ f.description }</span>
						</div>
						<div className="uxs-group__actions">
							{ f.settings && f.enabled ? (
								<button
									type="button"
									className="button"
									onClick={ () => navigate( 'module', { id: f.id } ) }
								>
									{ __( 'Configure', 'ux-studio' ) }
									<ChevronRight size={ 14 } aria-hidden />
								</button>
							) : null }
							<button
								type="button"
								className={ `uxs-switch${ f.enabled ? ' is-on' : '' }` }
								aria-label={ f.enabled ? __( 'Disable module', 'ux-studio' ) : __( 'Enable module', 'ux-studio' ) }
								aria-pressed={ f.enabled }
								onClick={ () => toggle.mutate( f ) }
							/>
						</div>
					</div>
				) ) }
			</div>
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
