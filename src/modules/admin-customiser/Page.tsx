import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, LoaderCircle } from 'lucide-react';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { MenuOrganizerTab } from './MenuOrganizerTab';

/**
 * Tab registry for this module's page. This wave only ships the "Settings"
 * (shared schema) and "Menu Organizer" tabs. Later agent appends tabs here
 * for Admin Bar / Quick Search / Login / Icons / Notices / Clock if they
 * need custom UI beyond the Settings tab.
 */
type TabId = 'settings' | 'menu-organizer';

const TABS: { id: TabId; label: string }[] = [
	{ id: 'settings', label: __( 'Settings', 'ux-studio' ) },
	{ id: 'menu-organizer', label: __( 'Menu Organizer', 'ux-studio' ) },
];

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

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< TabId >( 'settings' );

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
					{ __( 'Admin Customiser', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				{ TABS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label }
					</button>
				) ) }
			</div>
			{ tab === 'settings' && <SettingsTab /> }
			{ tab === 'menu-organizer' && <MenuOrganizerTab /> }
		</>
	);
}
