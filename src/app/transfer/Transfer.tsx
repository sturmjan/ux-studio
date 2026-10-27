/**
 * Export and import of settings (`#/settings?section=transfer`, PLAN.md 29): shell with the two parts.
 * `&module=<id>` opens the export part with that module ticked (the link in every module's header).
 */
import { useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { LoaderCircle } from 'lucide-react';
import { api } from '../api';
import { hashParam, navigate } from '../route';
import { ExportPanel } from './ExportPanel';
import { ImportPanel } from './ImportPanel';
import type { TransferStatus } from './types';

export const transferStatusKey = [ 'transfer', 'status' ] as const;

export function useTransferStatus() {
	return useQuery( {
		queryKey: transferStatusKey,
		queryFn: () => api< TransferStatus >( 'transfer/status' ),
	} );
}

export function Transfer(): JSX.Element {
	const { data: status, isLoading, error } = useTransferStatus();
	const part = 'import' === hashParam( 'part' ) ? 'import' : 'export';
	const preselect = hashParam( 'module' ) ?? '';

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( error || ! status ) {
		return <p role="alert">{ __( 'Failed to load the list of modules.', 'ux-studio' ) }</p>;
	}

	const go = ( next: 'export' | 'import' ): void => navigate( 'settings', { section: 'transfer', part: next } );

	return (
		<div className="uxs-transfer">
			<div className="uxs-subtabs" role="tablist" aria-label={ __( 'Export and import', 'ux-studio' ) }>
				<button type="button" role="tab" aria-selected={ 'export' === part } className={ 'export' === part ? 'is-active' : '' } onClick={ () => go( 'export' ) }>
					{ __( 'Export', 'ux-studio' ) }
				</button>
				<button type="button" role="tab" aria-selected={ 'import' === part } className={ 'import' === part ? 'is-active' : '' } onClick={ () => go( 'import' ) }>
					{ __( 'Import', 'ux-studio' ) }
				</button>
			</div>
			{ 'export' === part ? <ExportPanel status={ status } preselect={ preselect } /> : <ImportPanel status={ status } /> }
		</div>
	);
}
