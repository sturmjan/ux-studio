/**
 * Plugin overlaps (PLAN.md 26.5): a foreign plugin that does the same job as an
 * enabled UX Studio module. Three pieces share one query:
 *  - OverlapPanel    - "Plugin conflicts" panel above the module list;
 *  - OverlapBar      - slim notice on a module's own page;
 *  - OverlapModal    - shown when enabling a module is refused (HTTP 409).
 * "Start the migration wizard" opens the wizard (#/wizard) with the plugin preselected.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ChevronDown, ChevronRight, CircleAlert, Info, TriangleAlert } from 'lucide-react';
import { api, ApiError, queryClient, type OverlapErrorData, type OverlapRow, type OverlapsData } from './api';
import { navigate } from './route';

const QUERY_KEY = [ 'overlaps' ];

/** Link to the WordPress Plugins screen (relative to wp-admin/admin.php). */
const PLUGINS_URL = 'plugins.php?plugin_status=active';

/** Open the migration wizard with these plugins (folder slugs) preselected. */
function openWizard( slugs: string[] ): void {
	navigate( 'wizard', { plugin: [ ...new Set( slugs ) ].join( ',' ) } );
}

function WizardButton( { slugs, small = true }: { slugs: string[]; small?: boolean } ): JSX.Element | null {
	if ( 0 === slugs.length ) {
		return null;
	}
	return (
		<button type="button" className={ `button button-primary${ small ? ' button-small' : '' }` } onClick={ () => openWizard( slugs ) }>
			{ __( 'Start the migration wizard', 'ux-studio' ) }
		</button>
	);
}

export function useOverlaps() {
	return useQuery( {
		queryKey: QUERY_KEY,
		queryFn: () => api< OverlapsData >( 'overlaps' ),
	} );
}

export function refreshOverlaps(): Promise< void > {
	return queryClient.invalidateQueries( { queryKey: QUERY_KEY } );
}

/** True for the 409 raised by the activation guard. */
export function isOverlapError( e: unknown ): e is ApiError {
	return e instanceof ApiError && 'uxstudio_plugin_overlap' === e.code;
}

function useOverlapAction( action: 'ignore' | 'unignore' ) {
	return useMutation( {
		mutationFn: ( keys: string[] ) =>
			api( `overlaps/${ action }`, { method: 'POST', body: JSON.stringify( { keys } ) } ),
		onSettled: () => void refreshOverlaps(),
	} );
}

function typeBadge( row: Pick< OverlapRow, 'group' > ): JSX.Element {
	if ( 'full' === row.group ) {
		return <span className="uxs-badge is-danger">{ __( 'Full overlap', 'ux-studio' ) }</span>;
	}
	if ( 'customiser' === row.group ) {
		return <span className="uxs-badge is-warning">{ __( 'Partial overlap, can be migrated', 'ux-studio' ) }</span>;
	}
	return <span className="uxs-badge is-info">{ __( 'Partial overlap', 'ux-studio' ) }</span>;
}

function rowText( row: OverlapRow ): string {
	const module = row.feature ? `${ row.module_name } (${ row.feature })` : row.module_name;
	if ( 'full' === row.group ) {
		return sprintf(
			/* translators: 1: plugin name, 2: UX Studio module name. */
			__( '%1$s does the same job as the module "%2$s". Running both can conflict - deactivate the plugin or switch the module off.', 'ux-studio' ),
			row.name,
			module
		);
	}
	if ( 'customiser' === row.group ) {
		return sprintf(
			/* translators: 1: plugin name, 2: UX Studio module name. */
			__( '%1$s covers part of what the module "%2$s" does. Both can run, but check that they do not change the same screens.', 'ux-studio' ),
			row.name,
			module
		);
	}
	return sprintf(
		/* translators: 1: plugin name, 2: UX Studio module name. */
		__( '%1$s overlaps only partly with the module "%2$s". Both can run side by side; this is for your information.', 'ux-studio' ),
		row.name,
		module
	);
}

/**
 * "Plugin conflicts" panel for the Modules screen. Renders nothing without overlaps.
 * `compact` (used inside the collapsible summary) draws one slim row per conflict without a heading and
 * keeps the long explanation as a tooltip.
 */
export function OverlapPanel( { compact = false }: { compact?: boolean } ): JSX.Element | null {
	const { data } = useOverlaps();
	const ignore = useOverlapAction( 'ignore' );
	const unignore = useOverlapAction( 'unignore' );
	const [ showIgnored, setShowIgnored ] = useState( false );

	if ( ! data ) {
		return null;
	}
	const rows = [ ...data.full, ...data.customiser, ...data.partial ];
	if ( 0 === rows.length && 0 === data.ignored.length ) {
		return null;
	}
	const busy = ignore.isPending || unignore.isPending;

	return (
		<section className={ `uxs-overlaps${ compact ? ' is-compact' : '' }` } aria-labelledby={ compact ? undefined : 'uxs-overlaps-title' }>
			{ rows.length > 0 && (
				<>
					{ ! compact && (
						<h2 id="uxs-overlaps-title" className="uxs-overlaps__title">
							<TriangleAlert size={ 16 } aria-hidden />
							{ __( 'Plugin conflicts', 'ux-studio' ) }
						</h2>
					) }
					<ul className="uxs-overlaps__list">
						{ rows.map( ( row ) => (
							<li key={ row.key } className={ `uxs-overlaps__item is-${ row.group }${ compact ? ' is-compact' : '' }` } title={ compact ? rowText( row ) : undefined }>
								<span className="uxs-overlaps__icon">
									{ 'full' === row.group ? <CircleAlert size={ 18 } aria-hidden /> : <Info size={ 18 } aria-hidden /> }
								</span>
								<div className="uxs-overlaps__body">
									<div className="uxs-overlaps__head">
										<strong>{ row.name }</strong>
										{ typeBadge( row ) }
										{ compact && <span className="uxs-overlaps__module">{ row.feature ? `${ row.module_name } (${ row.feature })` : row.module_name }</span> }
									</div>
									{ ! compact && <p>{ rowText( row ) }</p> }
								</div>
								<div className="uxs-overlaps__actions">
									{ row.migratable && <WizardButton slugs={ [ row.slug ] } /> }
									<a className="button button-small" href={ PLUGINS_URL }>
										{ __( 'Open plugins', 'ux-studio' ) }
									</a>
									<button type="button" className="button button-small" disabled={ busy } onClick={ () => ignore.mutate( [ row.key ] ) }>
										{ __( 'Ignore', 'ux-studio' ) }
									</button>
								</div>
							</li>
						) ) }
					</ul>
				</>
			) }
			{ data.ignored.length > 0 && (
				<div className="uxs-overlaps__ignored">
					<button
						type="button"
						className="button-link"
						aria-expanded={ showIgnored }
						onClick={ () => setShowIgnored( ! showIgnored ) }
					>
						{ showIgnored ? <ChevronDown size={ 14 } aria-hidden /> : <ChevronRight size={ 14 } aria-hidden /> }
						{ sprintf(
							/* translators: %d: number of ignored plugin conflicts. */
							__( 'Ignored conflicts (%d)', 'ux-studio' ),
							data.ignored.length
						) }
					</button>
					{ showIgnored && (
						<ul className="uxs-overlaps__list is-muted">
							{ data.ignored.map( ( row ) => (
								<li key={ row.key } className="uxs-overlaps__item">
									<div className="uxs-overlaps__body">
										<div className="uxs-overlaps__head">
											<strong>{ row.name }</strong>
											<span>{ row.module_name }</span>
										</div>
									</div>
									<div className="uxs-overlaps__actions">
										<button type="button" className="button button-small" disabled={ busy } onClick={ () => unignore.mutate( [ row.key ] ) }>
											{ __( 'Show again', 'ux-studio' ) }
										</button>
									</div>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</section>
	);
}

/** Small notice bar on a module's own page when that module overlaps with an active plugin. */
export function OverlapBar( { moduleId }: { moduleId: string } ): JSX.Element | null {
	const { data } = useOverlaps();
	const ignore = useOverlapAction( 'ignore' );
	if ( ! data ) {
		return null;
	}
	const rows = [ ...data.full, ...data.customiser, ...data.partial ].filter( ( r ) => r.module === moduleId || '*' === r.module );
	if ( 0 === rows.length ) {
		return null;
	}
	const hard = rows.some( ( r ) => 'full' === r.group );
	const names = [ ...new Set( rows.map( ( r ) => r.name ) ) ].join( ', ' );

	return (
		<div className={ `uxs-overlapbar${ hard ? ' is-full' : '' }` } role="status">
			{ hard ? <CircleAlert size={ 16 } aria-hidden /> : <Info size={ 16 } aria-hidden /> }
			<span>
				{ hard
					? sprintf(
							/* translators: %s: comma-separated plugin names. */
							__( 'An active plugin does the same job as this module: %s. Running both can conflict.', 'ux-studio' ),
							names
					  )
					: sprintf(
							/* translators: %s: comma-separated plugin names. */
							__( 'An active plugin partly overlaps with this module: %s.', 'ux-studio' ),
							names
					  ) }
			</span>
			<WizardButton slugs={ rows.filter( ( r ) => r.migratable ).map( ( r ) => r.slug ) } />
			<a className="button button-small" href={ PLUGINS_URL }>
				{ __( 'Open plugins', 'ux-studio' ) }
			</a>
			<button type="button" className="button button-small" disabled={ ignore.isPending } onClick={ () => ignore.mutate( rows.map( ( r ) => r.key ) ) }>
				{ __( 'Ignore', 'ux-studio' ) }
			</button>
		</div>
	);
}

interface OverlapModalProps {
	/** The 409 error. */
	error: ApiError;
	/** Name of the module that was being enabled. */
	moduleName: string;
	busy?: boolean;
	onClose: () => void;
	/** Resend with `confirm_overlap: true` (only offered when the server asks for it). */
	onConfirm: () => void;
}

/** Explains why a module was not enabled; for confirmable overlaps offers "Enable anyway". */
export function OverlapModal( { error, moduleName, busy = false, onClose, onConfirm }: OverlapModalProps ): JSX.Element {
	const data = error.data as Partial< OverlapErrorData >;
	const plugins = data.plugins ?? [];
	const confirmable = !! data.requires_confirmation;
	const migratable = plugins.filter( ( p ) => p.migratable && p.slug ).map( ( p ) => p.slug as string );

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! busy ) {
				onClose();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ busy, onClose ] );

	return (
		<div className="uxs-overlay">
			<div className="uxs-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-overlap-title">
				<h2 id="uxs-overlap-title">
					{ confirmable ? __( 'This module overlaps with an active plugin', 'ux-studio' ) : __( 'The module cannot be enabled yet', 'ux-studio' ) }
				</h2>
				<p>
					{ confirmable
						? sprintf(
								/* translators: %s: module name. */
								__( 'The module "%s" covers part of what the plugin below already does. You can enable it anyway, but both will run and may change the same screens.', 'ux-studio' ),
								moduleName
						  )
						: sprintf(
								/* translators: %s: module name. */
								__( 'The module "%s" does the same job as the plugin below. Two things doing one job conflict with each other, so UX Studio did not enable it. Deactivate the plugin first, then enable the module.', 'ux-studio' ),
								moduleName
						  ) }
				</p>
				<ul className="uxs-overlaps__list">
					{ plugins.map( ( p ) => (
						<li key={ `${ p.basename }>${ p.module }` } className={ `uxs-overlaps__item is-${ 'full' === p.type ? 'full' : 'customiser' }` }>
							<div className="uxs-overlaps__body">
								<div className="uxs-overlaps__head">
									<strong>{ p.name }</strong>
									{ typeBadge( { group: 'full' === p.type ? 'full' : 'customiser' } ) }
								</div>
								<p>
									<code>{ p.basename }</code>
								</p>
							</div>
						</li>
					) ) }
				</ul>
				<div className="uxs-modal__actions">
					<button type="button" className="button" disabled={ busy } onClick={ onClose }>
						{ __( 'Close', 'ux-studio' ) }
					</button>
					{ confirmable ? (
						<button type="button" className="button button-primary" disabled={ busy } onClick={ onConfirm }>
							{ busy ? __( 'Enabling…', 'ux-studio' ) : __( 'Enable anyway', 'ux-studio' ) }
						</button>
					) : (
						<>
							<a className={ `button${ migratable.length ? '' : ' button-primary' }` } href={ PLUGINS_URL }>
								{ __( 'Open plugins', 'ux-studio' ) }
							</a>
							<WizardButton slugs={ migratable } small={ false } />
						</>
					) }
				</div>
			</div>
		</div>
	);
}
