/**
 * Lightweight per-user UI preferences (theme, sidebar) persisted in localStorage.
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

const SIDEBAR_KEY = 'uxstudio-sidebar';

/** Whether the user folded the sidebar away on a wide screen (on a narrow one it is always a drawer). */
export function getSidebarHidden(): boolean {
	return window.localStorage.getItem( SIDEBAR_KEY ) === 'hidden';
}

export function setSidebarHidden( hidden: boolean ): void {
	if ( hidden ) {
		window.localStorage.setItem( SIDEBAR_KEY, 'hidden' );
	} else {
		window.localStorage.removeItem( SIDEBAR_KEY );
	}
}
