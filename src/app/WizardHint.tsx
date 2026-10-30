/**
 * Small dismissible hint on the Modules screen: "We found N plugins UX Studio can take over - start the wizard".
 * Shown while no migration ever ran and the user did not close it (stored per user on the server).
 */
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Sparkles, Wand2, X } from 'lucide-react';
import { api, queryClient } from './api';
import { navigate } from './route';

interface HintData {
	show: boolean;
	count: number;
	/** The recommended modules (new site wizard) were never set up. */
	starter: boolean;
}

const QUERY_KEY = [ 'migration-hint' ];

export function WizardHint(): JSX.Element | null {
	const { data } = useQuery( {
		queryKey: QUERY_KEY,
		queryFn: () => api< HintData >( 'migration/hint' ),
		staleTime: 60_000,
	} );
	const dismiss = useMutation( {
		mutationFn: () => api< HintData >( 'migration/hint/dismiss', { method: 'POST' } ),
		onSuccess: () => queryClient.setQueryData< HintData >( QUERY_KEY, { show: false, count: 0, starter: false } ),
	} );

	if ( ! data || ( ! data.show && ! data.starter ) ) {
		return null;
	}
	const closeButton = (
		<button type="button" className="uxs-wizhint__close" aria-label={ __( 'Dismiss', 'ux-studio' ) } disabled={ dismiss.isPending } onClick={ () => dismiss.mutate() }>
			<X size={ 16 } aria-hidden />
		</button>
	);
	const starter = data.starter ? (
		<aside className="uxs-wizhint" aria-label={ __( 'New site setup', 'ux-studio' ) }>
			<Sparkles size={ 20 } aria-hidden />
			<p>{ __( 'Is this a new site? Turn on the modules that suit almost every site, already preset, in one short wizard.', 'ux-studio' ) }</p>
			<button type="button" className="button button-primary button-small" onClick={ () => navigate( 'wizard', { mode: 'new' } ) }>
				{ __( 'Set up the recommended modules', 'ux-studio' ) }
			</button>
			{ closeButton }
		</aside>
	) : null;
	if ( ! data.show ) {
		return starter;
	}
	return (
		<>
		<aside className="uxs-wizhint" aria-label={ __( 'Migration wizard', 'ux-studio' ) }>
			<Wand2 size={ 20 } aria-hidden />
			<p>
				{ sprintf(
					/* translators: %d: number of plugins. */
					_n(
						'We found %d plugin that UX Studio can take over, with its settings and data.',
						'We found %d plugins that UX Studio can take over, with their settings and data.',
						data.count,
						'ux-studio'
					),
					data.count
				) }
			</p>
			<button type="button" className="button button-primary button-small" onClick={ () => navigate( 'wizard' ) }>
				{ __( 'Start the wizard', 'ux-studio' ) }
			</button>
			{ closeButton }
		</aside>
		{ starter }
		</>
	);
}
