import { __ } from '@wordpress/i18n';
import { LoaderCircle } from 'lucide-react';
import { hashParam } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { MenuOrganizerTab } from './MenuOrganizerTab';

function SettingsTab(): JSX.Element {
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'admin-customiser' );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
			<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
				{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
			</button>
		</>
	);
}

/** Sub-pages (Settings / Menu Organizer) are picked in the sidebar tree: `&view=menu-organizer`. */
export default function Page(): JSX.Element {
	return 'menu-organizer' === hashParam( 'view' ) ? <MenuOrganizerTab /> : <SettingsTab />;
}
