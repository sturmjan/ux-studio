/**
 * "Export this setting" link for the header of a module's settings page: opens the export part of
 * Settings > Export and import with this module ticked.
 */
import { __ } from '@wordpress/i18n';
import { Download } from 'lucide-react';
import { navigate } from '../route';
import { useTransferStatus } from './Transfer';

export function ExportModuleLink( { moduleId, row = false }: { moduleId: string; row?: boolean } ): JSX.Element | null {
	const { data } = useTransferStatus();
	if ( ! data || ! data.modules.some( ( m ) => m.id === moduleId ) ) {
		return null;
	}
	const link = (
		<a
			href={ `#/settings?section=transfer&part=export&module=${ moduleId }` }
			className="uxs-tr-link"
			onClick={ ( e ) => {
				e.preventDefault();
				navigate( 'settings', { section: 'transfer', part: 'export', module: moduleId } );
			} }
		>
			<Download size={ 13 } aria-hidden /> { __( 'Export this setting', 'ux-studio' ) }
		</a>
	);
	return row ? <div className="uxs-tr-linkrow">{ link }</div> : link;
}
