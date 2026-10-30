/**
 * Global (plugin-wide) settings page. Reached from the "Settings" item in the
 * left sidebar. Distinct from per-module settings (ModuleSettings.tsx), which
 * open from a module tile.
 */
import { __ } from '@wordpress/i18n';
import { McpSettings } from './McpSettings';
import { Transfer } from './transfer/Transfer';
import { hashParam, navigate } from './route';

function Row( { label, value }: { label: string; value: string } ): JSX.Element {
	// The label has the same top padding, so a plain value lines up with it instead of sitting 8 px higher.
	return (
		<div className="uxs-form__row">
			<label>{ label }</label>
			<span style={ { paddingTop: 'var(--uxs-sp-2)' } }>{ value }</span>
		</div>
	);
}

/** "cs_CZ" -> "čeština (Česko)" in the language of the admin; falls back to the code. */
function localeName( code: string | undefined ): string {
	if ( ! code ) {
		return '—';
	}
	const tag = code.replace( '_', '-' );
	try {
		const name = new Intl.DisplayNames( [ tag ], { type: 'language' } ).of( tag );
		return name && name !== tag ? name.charAt( 0 ).toLocaleUpperCase( tag ) + name.slice( 1 ) : code;
	} catch {
		return code;
	}
}

export function GlobalSettings(): JSX.Element {
	const boot = window.uxStudioBoot;
	const sectionParam = hashParam( 'section' );
	const section = 'mcp' === sectionParam || 'transfer' === sectionParam ? sectionParam : 'general';

	const tabs = (
		<div className="uxs-tabs" role="tablist">
			<button type="button" role="tab" aria-selected={ 'general' === section } className={ 'general' === section ? 'is-active' : '' } onClick={ () => navigate( 'settings' ) }>
				{ __( 'General', 'ux-studio' ) }
			</button>
			<button type="button" role="tab" aria-selected={ 'mcp' === section } className={ 'mcp' === section ? 'is-active' : '' } onClick={ () => navigate( 'settings', { section: 'mcp' } ) }>
				{ __( 'MCP server', 'ux-studio' ) }
			</button>
			<button type="button" role="tab" aria-selected={ 'transfer' === section } className={ 'transfer' === section ? 'is-active' : '' } onClick={ () => navigate( 'settings', { section: 'transfer' } ) }>
				{ __( 'Export and import', 'ux-studio' ) }
			</button>
		</div>
	);

	if ( 'transfer' === section ) {
		return (
			<>
				<header className="uxs-pagehead">
					<h1>{ __( 'Settings', 'ux-studio' ) }</h1>
				</header>
				{ tabs }
				<Transfer />
			</>
		);
	}

	if ( 'mcp' === section ) {
		return (
			<>
				<header className="uxs-pagehead">
					<h1>{ __( 'Settings', 'ux-studio' ) }</h1>
				</header>
				{ tabs }
				<McpSettings />
			</>
		);
	}

	return (
		<>
			<header className="uxs-pagehead">
				<h1>{ __( 'Settings', 'ux-studio' ) }</h1>
			</header>
			{ tabs }

			<h2 className="uxs-section-title">{ __( 'About', 'ux-studio' ) }</h2>
			<div className="uxs-form">
				<Row label={ __( 'Plugin version', 'ux-studio' ) } value={ boot?.version ?? '—' } />
				<Row label={ __( 'API version', 'ux-studio' ) } value={ String( boot?.apiVersion ?? '—' ) } />
				<Row label={ __( 'Language', 'ux-studio' ) } value={ localeName( boot?.locale ) } />
			</div>
		</>
	);
}
