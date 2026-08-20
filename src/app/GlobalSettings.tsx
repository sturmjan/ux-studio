/**
 * Global (plugin-wide) settings page. Reached from the "Settings" item in the
 * left sidebar. Distinct from per-module settings (ModuleSettings.tsx), which
 * open from a module tile.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Moon, Sun } from 'lucide-react';
import { getTheme, setTheme, type Theme } from './prefs';

function Row( { label, value }: { label: string; value: string } ): JSX.Element {
	return (
		<div className="uxs-form__row">
			<label>{ label }</label>
			<span>{ value }</span>
		</div>
	);
}

export function GlobalSettings(): JSX.Element {
	const boot = window.uxStudioBoot;
	const [ theme, setThemeState ] = useState< Theme >( getTheme() );
	const next: Theme = theme === 'dark' ? 'light' : 'dark';

	return (
		<>
			<header className="uxs-pagehead">
				<h1>{ __( 'Settings', 'ux-studio' ) }</h1>
			</header>

			<h2 className="uxs-section-title">{ __( 'Appearance', 'ux-studio' ) }</h2>
			<div className="uxs-form">
				<div className="uxs-form__row">
					<label>{ __( 'Theme', 'ux-studio' ) }</label>
					<button
						type="button"
						className="button"
						onClick={ () => {
							setTheme( next );
							setThemeState( next );
						} }
					>
						{ theme === 'dark' ? <Sun size={ 14 } aria-hidden /> : <Moon size={ 14 } aria-hidden /> }
						{ theme === 'dark'
							? __( 'Switch to light mode', 'ux-studio' )
							: __( 'Switch to dark mode', 'ux-studio' ) }
					</button>
				</div>
			</div>

			<h2 className="uxs-section-title">{ __( 'About', 'ux-studio' ) }</h2>
			<div className="uxs-form">
				<Row label={ __( 'Plugin version', 'ux-studio' ) } value={ boot?.version ?? '—' } />
				<Row label={ __( 'API version', 'ux-studio' ) } value={ String( boot?.apiVersion ?? '—' ) } />
				<Row label={ __( 'Language', 'ux-studio' ) } value={ boot?.locale ?? '—' } />
			</div>
		</>
	);
}
