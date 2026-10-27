/**
 * App shell: left sidebar (destima-style) + routed content. The landing route
 * is the module grid ("šachovnice", ux1-style). Extension pages registered via
 * window.uxStudio.registerPage render inside the same shell.
 */
import { Suspense, useEffect, useState, type ComponentType } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LayoutGrid, Settings, Blocks, Moon, Sun, LoaderCircle, Wand2 } from 'lucide-react';
import { currentRoute, hashParam, navigate } from './route';
import { api, queryClient, type ApiError, type ModuleInfo } from './api';
import { isOverlapError, OverlapBar, OverlapModal, refreshOverlaps } from './Overlaps';
import { ModuleGrid } from './ModuleGrid';
import { ModuleSettings } from './ModuleSettings';
import { GlobalSettings } from './GlobalSettings';
import { GroupBreadcrumb, GroupPage } from './GroupPage';
import { ModuleIcon } from './moduleIcons';
import { FEATURE_VIEWS, useViewEnabled, type FeatureView } from './featureViews';
import { Toasts } from './Toasts';
import { Wizard } from './wizard/Wizard';
import { ExportModuleLink } from './transfer/ExportLink';
import { SubNavSlot } from './SubNav';
import { getTheme, setTheme, type Theme } from './prefs';
import { MODULE_PAGES } from '../modules/registry';

/**
 * Gate for a group-C module's own SPA page: a disabled module hasn't booted, so
 * its REST routes aren't registered and its page would only fire 404s. Show a
 * "module is off" state with an "Enable" button right here (same endpoint as
 * the switch on the Modules screen) instead of rendering it. Once enabled, the
 * module boots on the next request, so the page's own REST calls work.
 */
function ModulePageGate( { moduleId, Page }: { moduleId: string; Page: ComponentType } ): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );

	// Set when enabling was refused (409): the modal names the overlapping plugin.
	const [ blocked, setBlocked ] = useState< ApiError | null >( null );

	const enable = useMutation( {
		mutationFn: ( confirm?: boolean ) =>
			api( `modules/${ moduleId }`, {
				method: 'POST',
				body: JSON.stringify( { enabled: true, ...( confirm ? { confirm_overlap: true } : {} ) } ),
			} ),
		onSuccess: () => {
			setBlocked( null );
			void queryClient.invalidateQueries( { queryKey: [ 'modules' ] } );
			void refreshOverlaps();
		},
		onError: ( err ) => {
			if ( isOverlapError( err ) ) {
				setBlocked( err );
			}
		},
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const module = data?.find( ( m ) => m.id === moduleId );
	if ( module && ! module.enabled ) {
		return (
			<>
				<header className="uxs-pagehead">
					<h1>{ module.name }</h1>
				</header>
				<div className="uxs-notice">
					<p>{ __( 'This module is turned off. Enable it to use its settings.', 'ux-studio' ) }</p>
					{ enable.isError && ! isOverlapError( enable.error ) && <p className="uxs-notice__error">{ ( enable.error as Error ).message }</p> }
					<div className="uxs-notice__actions">
						<button type="button" className="button button-primary" disabled={ enable.isPending } onClick={ () => enable.mutate( false ) }>
							{ enable.isPending ? __( 'Enabling…', 'ux-studio' ) : __( 'Enable module', 'ux-studio' ) }
						</button>
						<button type="button" className="button" disabled={ enable.isPending } onClick={ () => navigate( '' ) }>
							{ __( 'Back to modules', 'ux-studio' ) }
						</button>
					</div>
				</div>
				{ blocked && (
					<OverlapModal
						error={ blocked }
						moduleName={ module.name }
						busy={ enable.isPending }
						onClose={ () => setBlocked( null ) }
						onConfirm={ () => enable.mutate( true ) }
					/>
				) }
			</>
		);
	}

	return (
		<>
			{ module ? <OverlapBar moduleId={ moduleId } /> : null }
			<Page />
		</>
	);
}

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

/** Sidebar-tree entry of a feature sub-page (with a status dot when it has its own switch). */
function TreeView( { groupId, featureId, view, active }: { groupId: string; featureId: string; view: FeatureView; active: boolean } ): JSX.Element {
	const { enabled } = useViewEnabled( featureId, view );
	return (
		<a
			href={ `#/module?id=${ groupId }&feature=${ featureId }&view=${ view.id }` }
			className={ `uxs-tree__item uxs-tree__view${ active ? ' is-active' : '' }` }
			onClick={ ( e ) => {
				e.preventDefault();
				navigate( 'module', { id: groupId, feature: featureId, view: view.id } );
			} }
		>
			{ view.enabledKey ? <span className={ `uxs-dot${ enabled ? ' is-on' : '' }` } aria-hidden /> : null }
			{ view.label }
		</a>
	);
}

export function App(): JSX.Element {
	const route = useHashRoute();
	const [ subNavSlot, setSubNavSlot ] = useState< HTMLElement | null >( null );
	const extension = pages.get( route );
	const moduleId = route === 'module' ? hashParam( 'id' ) ?? '' : '';
	const CustomPage = moduleId ? MODULE_PAGES[ moduleId ] : undefined;
	const { data: moduleList } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
		enabled: route === 'module',
	} );
	const isGroup = !! moduleId && !! moduleList?.find( ( m ) => m.id === moduleId )?.children?.length;

	// Sidebar tree: on a group page (or one of its features) list the group's features.
	const byId = new Map( ( moduleList ?? [] ).map( ( m ) => [ m.id, m ] ) );
	const current = moduleId ? byId.get( moduleId ) : undefined;
	const treeGroup = current?.children?.length ? current : current?.parent ? byId.get( current.parent ) : undefined;
	const treeFeatures = ( treeGroup?.children ?? [] )
		.map( ( id ) => byId.get( id ) )
		.filter( ( m ): m is ModuleInfo => !! m );
	const treeView = hashParam( 'view' );
	const treeActive = current?.parent ? current.id : hashParam( 'feature' ) ?? treeFeatures[ 0 ]?.id;

	const nav = [
		{ route: '', title: __( 'Modules', 'ux-studio' ), icon: <LayoutGrid size={ 16 } /> },
		{ route: 'wizard', title: __( 'Migration wizard', 'ux-studio' ), icon: <Wand2 size={ 16 } /> },
		{ route: 'settings', title: __( 'Settings', 'ux-studio' ), icon: <Settings size={ 16 } /> },
	];

	return (
		<div className="uxs-shell">
			<nav className="uxs-sidebar" aria-label={ __( 'UX Studio navigation', 'ux-studio' ) }>
				<span className="uxs-sidebar__brand">
					<Blocks size={ 20 } /> UX Studio
				</span>
				{ nav.map( ( item ) => (
					<div key={ item.route }>
						<a
							href={ `#/${ item.route }` }
							className={ `uxs-sidebar__item${ route === item.route ? ' is-active' : '' }` }
						>
							{ item.icon }
							{ item.title }
						</a>
						{ '' === item.route && ! treeGroup && current ? (
							<div className="uxs-tree" role="group" aria-label={ current.name }>
								<span className="uxs-tree__group">
									<span className="uxs-tree__icon">
										<ModuleIcon name={ current.icon } size={ 20 } />
									</span>
									{ current.name }
								</span>
								<div className="uxs-tree__children">
									<div ref={ setSubNavSlot } className="uxs-subnav" />
								</div>
							</div>
						) : null }
						{ '' === item.route && treeGroup ? (
							<div className="uxs-tree" role="group" aria-label={ treeGroup.name }>
								<a
									href={ `#/module?id=${ treeGroup.id }` }
									className="uxs-tree__group"
									onClick={ ( e ) => {
										e.preventDefault();
										navigate( 'module', { id: treeGroup.id } );
									} }
								>
									<span className="uxs-tree__icon">
										<ModuleIcon name={ treeGroup.icon } size={ 20 } />
									</span>
									{ treeGroup.name }
								</a>
								<div className="uxs-tree__children">
								{ treeFeatures.map( ( f ) => {
									const views = FEATURE_VIEWS[ f.id ] ?? [];
									const featureActive = f.id === treeActive;
									return (
										<div key={ f.id } className="uxs-tree__feature">
											<a
												href={ `#/module?id=${ treeGroup.id }&feature=${ f.id }` }
												className={ `uxs-tree__item${ featureActive && ! treeView ? ' is-active' : '' }` }
												onClick={ ( e ) => {
													e.preventDefault();
													navigate( 'module', { id: treeGroup.id, feature: f.id } );
												} }
											>
												<span className={ `uxs-dot${ f.enabled ? ' is-on' : '' }` } aria-hidden />
												{ f.name }
											</a>
											{ featureActive && ! treeView ? <div ref={ setSubNavSlot } className="uxs-subnav" /> : null }
											{ f.enabled && views.map( ( v ) => (
												<TreeView
													key={ v.id }
													groupId={ treeGroup.id }
													featureId={ f.id }
													view={ v }
													active={ featureActive && treeView === v.id }
												/>
											) ) }
										</div>
									);
								} ) }
								</div>
							</div>
						) : null }
					</div>
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
			<Toasts />
			<SubNavSlot.Provider value={ subNavSlot }>
			<main className="uxs-content">
				{ moduleId && ! isGroup ? <GroupBreadcrumb moduleId={ moduleId } /> : null }
				{ extension ? (
					<extension.component />
				) : isGroup ? (
					<GroupPage groupId={ moduleId } />
				) : CustomPage ? (
					<Suspense
						fallback={
							<div className="uxs-loading">
								<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
							</div>
						}
					>
						<ExportModuleLink moduleId={ moduleId } row />
						<ModulePageGate moduleId={ moduleId } Page={ CustomPage } />
					</Suspense>
				) : route === 'module' ? (
					// Wait for the module list so a group id never hits the generic
					// settings page (groups have no settings of their own).
					moduleList ? (
						<ModuleSettings />
					) : (
						<div className="uxs-loading">
							<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
						</div>
					)
				) : route === 'wizard' ? (
					<Wizard />
				) : route === 'settings' ? (
					<GlobalSettings />
				) : (
					<ModuleGrid />
				) }
			</main>
			</SubNavSlot.Provider>
		</div>
	);
}
