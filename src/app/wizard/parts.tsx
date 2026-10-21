/**
 * Small building blocks shared by the wizard steps.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { CircleX, TriangleAlert, Info } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { api, type ModuleInfo } from '../api';
import { ModuleIcon } from '../moduleIcons';
import type { PlanItem, ScanSource, SourcePlan } from './types';

/** True when the user asked the system for less motion. */
export function reducedMotion(): boolean {
	return window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ?? false;
}

/** Pause that is skipped under reduced motion (gives the progress bars time to be seen). */
export function pause( ms: number ): Promise< void > {
	return reducedMotion() ? Promise.resolve() : new Promise( ( resolve ) => window.setTimeout( resolve, ms ) );
}

/** A check mark that draws itself (CSS stroke animation, static under reduced motion). */
export function DrawnCheck( { size = 18 }: { size?: number } ): JSX.Element {
	return (
		<svg className="uxs-wiz-check" width={ size } height={ size } viewBox="0 0 24 24" fill="none" aria-hidden>
			<path d="M5 12.5l4.5 4.5L19 7.5" pathLength={ 1 } />
		</svg>
	);
}

/** Round state icon: drawn check, cross, spinner or an empty ring. */
export function StateIcon( { state }: { state: 'pending' | 'running' | 'ok' | 'error' | 'warn' } ): JSX.Element {
	return (
		<span className={ `uxs-wiz-state is-${ state }` } aria-hidden>
			{ 'ok' === state && <DrawnCheck size={ 16 } /> }
			{ 'error' === state && <CircleX size={ 18 } /> }
			{ 'warn' === state && <TriangleAlert size={ 16 } /> }
		</span>
	);
}

/** Icon of the module that takes over from a plugin (module list is cached by the app). */
export function SourceIcon( { module }: { module: string } ): JSX.Element {
	const { data } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );
	const icon = '*' === module ? 'puzzle' : data?.find( ( m ) => m.id === module )?.icon ?? 'puzzle';
	return (
		<span className="uxs-wiz-sourceicon">
			<ModuleIcon name={ icon } size={ 22 } />
		</span>
	);
}

/** "12 settings" / "No settings". */
export function settingsLabel( count: number ): string {
	if ( 0 === count ) {
		return __( 'No settings to transfer', 'ux-studio' );
	}
	return sprintf(
		/* translators: %d: number of settings. */
		_n( '%d setting', '%d settings', count, 'ux-studio' ),
		count
	);
}

/** Active / inactive / leftover-data badge. */
export function StatusBadge( { source }: { source: Pick< ScanSource, 'status' > } ): JSX.Element {
	if ( 'active' === source.status ) {
		return <span className="uxs-badge is-success">{ __( 'Active', 'ux-studio' ) }</span>;
	}
	if ( 'inactive' === source.status ) {
		return <span className="uxs-badge is-info">{ __( 'Inactive', 'ux-studio' ) }</span>;
	}
	return <span className="uxs-badge is-warning">{ __( 'Plugin removed, settings remain', 'ux-studio' ) }</span>;
}

/** Full / partial match badge. */
export function MatchBadge( { match }: { match: ScanSource[ 'match' ] } ): JSX.Element {
	return 'full' === match ? (
		<span className="uxs-badge is-success">{ __( 'Full match', 'ux-studio' ) }</span>
	) : (
		<span className="uxs-badge is-info">{ __( 'Partial match', 'ux-studio' ) }</span>
	);
}

/** One "will / will not be transferred" block for a source. */
export function PlanDetails( { plan }: { plan: SourcePlan } ): JSX.Element {
	return (
		<div className="uxs-wiz-plan">
			<div className="uxs-wiz-plan__cols">
				<div>
					<h4 className="uxs-wiz-plan__title is-yes">{ __( 'Will be transferred', 'ux-studio' ) }</h4>
					{ plan.transfer.length > 0 ? (
						<ul className="uxs-wiz-plan__list">
							{ plan.transfer.map( ( item ) => (
								<li key={ item.target }>
									<span>{ item.label }</span>
									{ '' !== item.to && <code>{ item.to }</code> }
								</li>
							) ) }
						</ul>
					) : (
						<p className="uxs-wiz-muted">{ plan.notes[ 0 ] ?? __( 'Nothing to transfer.', 'ux-studio' ) }</p>
					) }
				</div>
				<div>
					<h4 className="uxs-wiz-plan__title is-no">{ __( 'Will not be transferred', 'ux-studio' ) }</h4>
					{ plan.lossy.length > 0 ? (
						<ul className="uxs-wiz-plan__list is-lossy">
							{ plan.lossy.map( ( text ) => (
								<li key={ text }>{ text }</li>
							) ) }
						</ul>
					) : (
						<p className="uxs-wiz-muted">{ __( 'Nothing is lost.', 'ux-studio' ) }</p>
					) }
				</div>
			</div>
			{ plan.transfer.length > 0 && plan.notes.length > 0 && (
				<p className="uxs-wiz-note">
					<Info size={ 14 } aria-hidden /> { plan.notes.join( ' ' ) }
				</p>
			) }
			<Warnings warnings={ plan.warnings } />
		</div>
	);
}

/** Amber warning list. */
export function Warnings( { warnings }: { warnings: string[] } ): JSX.Element | null {
	if ( 0 === warnings.length ) {
		return null;
	}
	return (
		<ul className="uxs-wiz-warnings">
			{ warnings.map( ( text ) => (
				<li key={ text }>
					<TriangleAlert size={ 15 } aria-hidden />
					<span>{ text }</span>
				</li>
			) ) }
		</ul>
	);
}

/** "now -> new" table used by the dry run. */
export function ChangeTable( { items }: { items: PlanItem[] } ): JSX.Element {
	return (
		<table className="uxs-wiz-changes">
			<thead>
				<tr>
					<th>{ __( 'Setting', 'ux-studio' ) }</th>
					<th>{ __( 'Now in UX Studio', 'ux-studio' ) }</th>
					<th>{ __( 'After migration', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ items.map( ( item ) => (
					<tr key={ item.target } className={ item.changed ? 'is-changed' : 'is-same' }>
						<td>{ item.label }</td>
						<td>
							<code>{ '' === item.from ? '-' : item.from }</code>
						</td>
						<td>
							<code>{ '' === item.to ? '-' : item.to }</code>
							{ ! item.changed && <span className="uxs-wiz-muted"> { __( 'no change', 'ux-studio' ) }</span> }
							{ item.staged && item.write && <span className="uxs-badge is-info">{ __( 'Prepared', 'ux-studio' ) }</span> }
							{ item.dropped && (
								<span className="uxs-wiz-dropped" title={ __( 'The module does not accept the whole value.', 'ux-studio' ) }>
									<TriangleAlert size={ 13 } aria-hidden />
								</span>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}
