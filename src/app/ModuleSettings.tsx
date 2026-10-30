/**
 * Generic schema-driven settings page. Renders any module's settings from its
 * declared schema - this is what makes ~50 modules look identical for free.
 * Route: #/module?id=<module-id>
 */
import { useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { api, type ModuleInfo } from './api';
import { NoSettings } from './NoSettings';
import { hashParam, navigate } from './route';
import { SettingsFields, useModuleSettings } from './SettingsForm';
import { ExportModuleLink } from './transfer/ExportLink';

/** `embedded`: rendered inside a group page - no back arrow / title, only the save button. */
export function ModuleSettings( { moduleId, embedded = false }: { moduleId?: string; embedded?: boolean } ): JSX.Element {
	const id = moduleId ?? hashParam( 'id' ) ?? '';
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( id );
	const { data: modules } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
		enabled: ! embedded,
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	// Nothing to configure: say so, instead of an empty card with a save button.
	const module = modules?.find( ( m ) => m.id === id );
	if ( ! embedded && module && ! data.schema.some( ( f ) => ! f.managed ) ) {
		return <NoSettings module={ module } />;
	}

	return (
		<>
			<header className={ `uxs-pagehead${ embedded ? ' is-embedded' : '' }` }>
				{ embedded ? <span /> : <h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ data.name }
				</h1> }
				<div className="uxs-pagehead__actions">
					{ /* A group page shows the export link once, in its own header. */ }
					{ embedded ? null : <ExportModuleLink moduleId={ id } /> }
					<button
						type="button"
						className="button button-primary"
						disabled={ save.isPending }
						onClick={ () => save.mutate() }
					>
						{ saved ? <Check size={ 14 } /> : null }
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</div>
			</header>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
		</>
	);
}
