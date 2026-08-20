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

/** Module grid layout: tiles ("šachovnice") vs. compact rows. */
export type ViewMode = 'grid' | 'list';

const VIEW_KEY = 'uxstudio-view';

export function getViewMode(): ViewMode {
	const stored = window.localStorage.getItem( VIEW_KEY );
	return stored === 'list' ? 'list' : 'grid';
}

export function setViewMode( mode: ViewMode ): void {
	window.localStorage.setItem( VIEW_KEY, mode );
}
