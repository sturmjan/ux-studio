/**
 * Generic schema-driven settings page. Renders any module's settings from its
 * declared schema - this is what makes ~50 modules look identical for free.
 * Route: #/module?id=<module-id>
 */
import { __ } from '@wordpress/i18n';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { hashParam, navigate } from './route';
import { SettingsFields, useModuleSettings } from './SettingsForm';

export function ModuleSettings(): JSX.Element {
	const id = hashParam( 'id' ) ?? '';
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( id );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

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
					{ data.name }
				</h1>
				<button
					type="button"
					className="button button-primary"
					disabled={ save.isPending }
					onClick={ () => save.mutate() }
				>
					{ saved ? <Check size={ 14 } /> : null }
					{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
				</button>
			</header>
			<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
		</>
	);
}
