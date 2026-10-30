/**
 * Empty state of a module that has no settings of its own (`#/module?id=<id>`): what it is, that it works as soon
 * as it is on, and the one switch that matters. No form and no save button, because there is nothing to save.
 * An optional `how` text (meta.json key `how`, a few sentences) becomes the "How it works" paragraph.
 */
import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { api, queryClient, type ApiError, type ModuleInfo } from './api';
import { isOverlapError, OverlapModal, refreshOverlaps } from './Overlaps';
import { ModuleIcon } from './moduleIcons';
import { navigate } from './route';
import { ArrowLeft } from 'lucide-react';

export function NoSettings( { module, backArrow = true }: { module: ModuleInfo; backArrow?: boolean } ): JSX.Element {
	// Set when enabling was refused (409): the modal names the overlapping plugin.
	const [ blocked, setBlocked ] = useState< ApiError | null >( null );
	const toggle = useMutation( {
		mutationFn: ( confirm?: boolean ) =>
			api( `modules/${ module.id }`, {
				method: 'POST',
				body: JSON.stringify( { enabled: ! module.enabled, ...( confirm ? { confirm_overlap: true } : {} ) } ),
			} ),
		onMutate: async () => {
			await queryClient.cancelQueries( { queryKey: [ 'modules' ] } );
			const previous = queryClient.getQueryData< ModuleInfo[] >( [ 'modules' ] );
			queryClient.setQueryData< ModuleInfo[] >( [ 'modules' ], ( old ) =>
				old?.map( ( x ) => ( x.id === module.id ? { ...x, enabled: ! x.enabled } : x ) )
			);
			return { previous };
		},
		onSuccess: () => setBlocked( null ),
		onError: ( err, _confirm, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData( [ 'modules' ], context.previous );
			}
			if ( isOverlapError( err ) ) {
				setBlocked( err );
			}
		},
		onSettled: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void refreshOverlaps();
		},
	} );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					{ backArrow ? (
						<button type="button" onClick={ () => navigate( '' ) } aria-label={ __( 'Back to modules', 'ux-studio' ) }>
							<ArrowLeft size={ 18 } />
						</button>
					) : null }{ ' ' }
					{ module.name }
				</h1>
			</header>
			<section className="uxs-empty" aria-labelledby="uxs-empty-title">
				<span className="uxs-tile__icon uxs-empty__icon">
					<ModuleIcon name={ module.icon } size={ 28 } />
				</span>
				<h2 id="uxs-empty-title" className="uxs-empty__title">
					{ module.name }
				</h2>
				{ module.description ? <p className="uxs-empty__desc">{ module.description }</p> : null }
				<p className="uxs-empty__note">{ __( 'This module has no settings. It works as soon as it is switched on.', 'ux-studio' ) }</p>
				<div className="uxs-empty__switch">
					<span className="uxs-group__state">{ module.enabled ? __( 'Enabled', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }</span>
					<button
						type="button"
						className={ `uxs-switch${ module.enabled ? ' is-on' : '' }` }
						aria-label={ module.enabled ? __( 'Disable module', 'ux-studio' ) : __( 'Enable module', 'ux-studio' ) }
						aria-pressed={ module.enabled }
						disabled={ toggle.isPending }
						onClick={ () => toggle.mutate( false ) }
					/>
				</div>
				{ module.how ? (
					<div className="uxs-empty__how">
						<h3>{ __( 'How it works', 'ux-studio' ) }</h3>
						<p>{ module.how }</p>
					</div>
				) : null }
			</section>
			{ blocked ? (
				<OverlapModal
					error={ blocked }
					moduleName={ module.name }
					busy={ toggle.isPending }
					onClose={ () => setBlocked( null ) }
					onConfirm={ () => toggle.mutate( true ) }
				/>
			) : null }
		</>
	);
}
