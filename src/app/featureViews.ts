/**
 * Extra sidebar-tree entries ("views") of a feature that has its own sub-pages,
 * e.g. the Menu Organizer inside Admin Customiser. Selected via `&view=<id>`
 * in the route; the feature's page reads it with hashParam( 'view' ).
 */
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { api, queryClient } from './api';

export interface FeatureView {
	id: string;
	label: string;
	/** Boolean key in the feature's settings that switches this view's functionality on/off. */
	enabledKey?: string;
}

export const FEATURE_VIEWS: Record< string, FeatureView[] > = {
	'admin-customiser': [ { id: 'menu-organizer', label: __( 'Menu Organizer', 'ux-studio' ), enabledKey: 'menu_organizer_enabled' } ],
};

interface SettingsData {
	values: Record< string, unknown >;
}

/** On/off state of a view that has its own switch (stored as a setting of its feature). */
export function useViewEnabled( featureId: string, view: FeatureView | undefined ): { enabled: boolean; toggle: () => void } {
	const key = view?.enabledKey;
	const { data } = useQuery( {
		queryKey: [ 'settings', featureId ],
		queryFn: () => api< SettingsData >( `settings/${ featureId }` ),
		enabled: !! key,
	} );
	const mutation = useMutation( {
		mutationFn: ( next: boolean ) =>
			api( `settings/${ featureId }`, { method: 'POST', body: JSON.stringify( { ...data?.values, [ key ?? '' ]: next } ) } ),
		onSettled: () => void queryClient.invalidateQueries( { queryKey: [ 'settings', featureId ] } ),
	} );
	const enabled = !! key && !! data?.values[ key ];
	return { enabled, toggle: () => mutation.mutate( ! enabled ) };
}
