/**
 * App shell: left sidebar (destima-style) + routed content. The landing route
 * is the module grid ("šachovnice", ux1-style). Extension pages registered via
 * window.uxStudio.registerPage render inside the same shell.
 */
import { Suspense, useEffect, useState, type ComponentType } from 'react';
import { __ } from '@wordpress/i18n';
import { LayoutGrid, Settings, Blocks, Moon, Sun, LoaderCircle } from 'lucide-react';
import { currentRoute, hashParam, navigate } from './route';
import { ModuleGrid } from './ModuleGrid';
import { ModuleSettings } from './ModuleSettings';
import { GlobalSettings } from './GlobalSettings';
import { getTheme, setTheme, type Theme } from './prefs';
import { MODULE_PAGES } from '../modules/registry';

interface PageDef {
	route: string;
	title: string;
	component: ComponentType;
}

/** Extension API surface exposed on window.uxStudio. */
const pages = new Map< string, PageDef >();

declare global {
	interface Window {
		uxStudio: {
			apiVersion: number;
			registerPage: ( def: PageDef ) => void;
			navigate: typeof navigate;
		};
	}
}

window.uxStudio = {
	apiVersion: window.uxStudioBoot?.apiVersion ?? 1,
	registerPage: ( def ) => pages.set( def.route, def ),
	navigate,
};

/**
 * Re-renders on every hash change, keyed on the FULL hash (route + query) -
 * not just the base route - so switching from #/module?id=a to
 * #/module?id=b (same base route, different query) still triggers a
 * re-render. Callers read the current route/params via currentRoute()/
 * hashParam() after this hook fires, since window.location.hash is already
 * up to date by the time the hashchange listener runs.
 */
function useHashRoute(): string {
	const [ , setTick ] = useState( 0 );
	useEffect( () => {
		const onChange = (): void => setTick( ( t ) => t + 1 );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );
	return currentRoute();
}

function ThemeToggle(): JSX.Element {
	const [ theme, set ] = useState< Theme >( getTheme() );
	const next: Theme = theme === 'dark' ? 'light' : 'dark';
	return (
		<button
			type="button"
			className="uxs-sidebar__item"
			style={ { background: 'none', border: 'none', cursor: 'pointer', width: '100%', textAlign: 'left' } }
			onClick={ () => {
				setTheme( next );
				set( next );
			} }
			aria-label={ __( 'Toggle dark mode', 'ux-studio' ) }
		>
			{ theme === 'dark' ? <Sun size={ 16 } /> : <Moon size={ 16 } /> }
			{ theme === 'dark' ? __( 'Light mode', 'ux-studio' ) : __( 'Dark mode', 'ux-studio' ) }
		</button>
	);
}

export function App(): JSX.Element {
	const route = useHashRoute();
	const extension = pages.get( route );
	const moduleId = route === 'module' ? hashParam( 'id' ) ?? '' : '';
	const CustomPage = moduleId ? MODULE_PAGES[ moduleId ] : undefined;

	const nav = [
		{ route: '', title: __( 'Modules', 'ux-studio' ), icon: <LayoutGrid size={ 16 } /> },
		{ route: 'settings', title: __( 'Settings', 'ux-studio' ), icon: <Settings size={ 16 } /> },
	];

	return (
		<div className="uxs-shell">
			<nav className="uxs-sidebar" aria-label={ __( 'UX Studio navigation', 'ux-studio' ) }>
				<span className="uxs-sidebar__brand">
					<Blocks size={ 20 } /> UX Studio
				</span>
				{ nav.map( ( item ) => (
					<a
						key={ item.route }
						href={ `#/${ item.route }` }
						className={ `uxs-sidebar__item${ route === item.route ? ' is-active' : '' }` }
					>
						{ item.icon }
						{ item.title }
					</a>
				) ) }
				{ [ ...pages.values() ].map( ( p ) => (
					<a
						key={ p.route }
						href={ `#/${ p.route }` }
						className={ `uxs-sidebar__item${ route === p.route ? ' is-active' : '' }` }
					>
						{ p.title }
					</a>
				) ) }
				<div style={ { marginTop: 'auto' } }>
					<ThemeToggle />
				</div>
			</nav>
			<main className="uxs-content">
				{ extension ? (
					<extension.component />
				) : CustomPage ? (
					<Suspense
						fallback={
							<div className="uxs-loading">
								<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
							</div>
						}
					>
						<CustomPage />
					</Suspense>
				) : route === 'module' ? (
					<ModuleSettings />
				) : route === 'settings' ? (
					<GlobalSettings />
				) : (
					<ModuleGrid />
				) }
			</main>
		</div>
	);
}
