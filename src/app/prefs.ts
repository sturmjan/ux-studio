/**
 * Lightweight per-user UI preferences (theme) persisted in localStorage.
 */
export type Theme = 'light' | 'dark';

const KEY = 'uxstudio-theme';

export function getTheme(): Theme {
	const stored = window.localStorage.getItem( KEY );
	if ( stored === 'dark' || stored === 'light' ) {
		return stored;
	}
	return window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light';
}

export function setTheme( theme: Theme ): void {
	window.localStorage.setItem( KEY, theme );
	apply( theme );
}

export function apply( theme: Theme = getTheme() ): void {
	document.getElementById( 'ux-studio-root' )?.setAttribute( 'data-theme', theme );
}
