import { __ } from '@wordpress/i18n';
import { LoaderCircle } from 'lucide-react';
import { hashParam } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { MenuLockOriginNote, useMenuLock } from './menuLock';
import { MenuOrganizerTab } from './MenuOrganizerTab';

function SettingsTab(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'admin-customiser' );
	const lock = useMenuLock();

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	// The note stays while the toggle is on in the form; after the user switches it off (and saves) it is gone for good.
	const origin = lock.data?.origin;
	const notes = origin && draft.menu_lock_enabled ? { menu_lock_enabled: <MenuLockOriginNote origin={ origin } /> } : undefined;

	return (
		<>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } notes={ notes } />
			<button type="button" className="button button-primary" onClick={ () => save.mutate( undefined, { onSuccess: () => void lock.refetch() } ) }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);
}

/** Sub-pages (Settings / Menu Organizer) are picked in the sidebar tree: `&view=menu-organizer`. */
export default function Page(): JSX.Element {
	return 'menu-organizer' === hashParam( 'view' ) ? <MenuOrganizerTab /> : <SettingsTab />;
}
