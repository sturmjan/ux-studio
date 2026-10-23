/**
 * State of "Block access to hidden pages" and, when a migration switched it on, where it came from.
 * Shown next to the toggle (Settings) and as a badge on the Menu Organizer tab.
 */
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Info } from 'lucide-react';
import { api } from '../../app/api';
import { formatDate } from '../../app/date';
import { navigate } from '../../app/route';

export interface MenuLockState {
	enabled: boolean;
	origin: { source: string; label: string; run: string; time: number } | null;
}

export const MENU_LOCK_KEY = [ 'admin-customiser', 'menu-lock' ];

export function useMenuLock() {
	return useQuery( {
		queryKey: MENU_LOCK_KEY,
		queryFn: () => api< MenuLockState >( 'admin-customiser/menu-lock' ),
		staleTime: 0,
	} );
}

/** Callout under the toggle: the lock is on because the migration brought it over. */
export function MenuLockOriginNote( { origin }: { origin: NonNullable< MenuLockState[ 'origin' ] > } ): JSX.Element {
	return (
		<div className="uxs-callout" role="note">
			<Info size={ 16 } aria-hidden />
			<p>
				{ sprintf(
					/* translators: 1: plugin name (Adminimize), 2: date of the migration. */
					__( 'Switched on by the migration from the plugin %1$s (%2$s). %1$s blocked hidden pages, so the lock is switched on here too. You can switch it off.', 'ux-studio' ),
					origin.label,
					formatDate( origin.time )
				) }{ ' ' }
				<button type="button" className="button-link" onClick={ () => navigate( 'wizard' ) }>
					{ __( 'Open the migration history', 'ux-studio' ) }
				</button>
			</p>
		</div>
	);
}

/** Badge on the Menu Organizer tab. */
export function MenuLockBadge( { state }: { state: MenuLockState } ): JSX.Element {
	const text = ! state.enabled
		? __( 'Blocking of hidden pages: off', 'ux-studio' )
		: state.origin
			? sprintf(
					/* translators: %s: plugin name (Adminimize). */
					__( 'Blocking of hidden pages: on (from the migration of %s)', 'ux-studio' ),
					state.origin.label
			  )
			: __( 'Blocking of hidden pages: on', 'ux-studio' );
	return <span className={ `uxs-badge ${ state.enabled ? 'is-success' : 'is-info' }` }>{ text }</span>;
}
