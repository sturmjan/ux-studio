/**
 * Small building blocks shared by the wizard steps.
 */
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CircleX, TriangleAlert, Info } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { api, type ModuleInfo } from '../api';
import { ModuleIcon } from '../moduleIcons';
import type { SmokeRun } from './flows';
import type { DataRow, DetailRow, MenuRoleRow, PlanItem, ScanSource, SmokeCounts, SmokeResult, SourcePlan } from './types';

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

/** Small icon of a module (the one the Modules screen shows), for links and chips. */
export function ModuleLinkIcon( { module }: { module: string } ): JSX.Element {
	const { data } = useQuery( {
		queryKey: [ 'modules' ],
		queryFn: () => api< ModuleInfo[] >( 'modules' ),
	} );
	return (
		<span className="uxs-wiz-modulelink__icon">
			<ModuleIcon name={ data?.find( ( m ) => m.id === module )?.icon ?? 'puzzle' } size={ 16 } />
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

/** "12 settings, 338 redirects": what a plugin holds, for the compact scan and selection cards. */
export function sourceSummary( summary: ScanSource[ 'summary' ] ): string {
	const first: DataRow | undefined = summary.data?.[ 0 ];
	if ( ! first ) {
		return settingsLabel( summary.settings );
	}
	const data = sprintf(
		/* translators: 1: number of objects, 2: what they are ("Redirects"). */
		__( '%1$d × %2$s', 'ux-studio' ),
		first.count,
		first.label
	);
	return 0 === summary.settings ? data : `${ settingsLabel( summary.settings ) }, ${ data }`;
}

/** The counts of the objects a plugin holds, as one line ("338 Redirects, 12 of them switched off"). */
export function DataCounts( { rows }: { rows: DataRow[] } ): JSX.Element | null {
	if ( 0 === rows.length ) {
		return null;
	}
	return (
		<p className="uxs-wiz-datacounts">
			{ rows.map( ( row, i ) => (
				<span key={ row.key }>
					<strong>{ row.count }</strong> { row.label }
					{ i < rows.length - 1 ? ', ' : '' }
				</span>
			) ) }
		</p>
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
					{ plan.transfer.length > 0 || plan.data.length > 0 ? (
						<ul className="uxs-wiz-plan__list">
							{ plan.transfer.map( ( item ) => (
								<li key={ item.target }>
									<span>{ item.label }</span>
									{ '' !== item.to && <code>{ item.to }</code> }
								</li>
							) ) }
							{ plan.data.map( ( row ) => (
								<li key={ `data-${ row.key }` }>
									<span>{ row.label }</span>
									<code>{ row.count }</code>
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
			{ plan.staged && plan.data.length > 0 && (
				<p className="uxs-wiz-note">
					<Info size={ 14 } aria-hidden />{ ' ' }
					{ __( 'The module is already on, so this is only prepared: the objects are created switched off and start working at the switch, after the safe preview.', 'ux-studio' ) }
				</p>
			) }
			{ ( plan.transfer.length > 0 || plan.data.length > 0 ) && plan.notes.length > 0 && (
				<p className="uxs-wiz-note">
					<Info size={ 14 } aria-hidden /> { plan.notes.join( ' ' ) }
				</p>
			) }
			<Warnings warnings={ plan.warnings } />
		</div>
	);
}

/** The partial-match note of a source card (Admin Customiser sources). */
export function PartialNote( { reviewed, onOpen }: { reviewed: boolean; onOpen: () => void } ): JSX.Element {
	return (
		<p className={ `uxs-wiz-partial${ reviewed ? ' is-reviewed' : '' }` }>
			<TriangleAlert size={ 15 } aria-hidden />
			<span>
				{ __( 'Partial match: check the list of what will not be transferred.', 'ux-studio' ) }{ ' ' }
				{ reviewed ? (
					<strong>{ __( 'Checked, you can tick it.', 'ux-studio' ) }</strong>
				) : (
					<button type="button" className="button-link" onClick={ onOpen }>
						{ __( 'Show the list', 'ux-studio' ) }
					</button>
				) }
			</span>
		</p>
	);
}

function nameList( names: string[] ): string {
	if ( 0 === names.length ) {
		return '-';
	}
	return names.length > 3 ? `${ names.slice( 0, 3 ).join( ', ' ) } ${ sprintf( /* translators: %d: how many more. */ __( '+ %d more', 'ux-studio' ), names.length - 3 ) }` : names.join( ', ' );
}

/** Per role: menu items hidden before (old plugin) and after (UX Studio), compact: roles with nothing hidden share one line. */
export function MenuRoleTable( { rows }: { rows: MenuRoleRow[] } ): JSX.Element {
	const shown = rows.filter( ( row ) => row.before.length > 0 || row.after.length > 0 );
	const quiet = rows.filter( ( row ) => 0 === row.before.length && 0 === row.after.length );
	return (
		<div className="uxs-wiz-rolecompare">
			<h4 className="uxs-wiz-plan__title">{ __( 'Menu by role', 'ux-studio' ) }</h4>
			{ shown.length > 0 && (
				<table className="uxs-wiz-changes">
					<thead>
						<tr>
							<th>{ __( 'Role', 'ux-studio' ) }</th>
							<th>{ __( 'Hidden now (old plugin)', 'ux-studio' ) }</th>
							<th>{ __( 'Hidden after migration', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ shown.map( ( row ) => {
							const same = row.before.join( '|' ) === row.after.join( '|' );
							return (
								<tr key={ row.role } className={ same ? 'is-same' : 'is-changed' }>
									<td>{ row.label }</td>
									<td title={ row.before.join( ', ' ) }>
										<strong>{ row.before.length }</strong> <span className="uxs-wiz-muted">{ nameList( row.before ) }</span>
									</td>
									<td title={ row.after.join( ', ' ) }>
										<strong>{ row.after.length }</strong> <span className="uxs-wiz-muted">{ nameList( row.after ) }</span>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
			{ quiet.length > 0 && (
				<p className="uxs-wiz-muted">
					{ 0 === shown.length
						? __( 'No role has a menu item hidden, before or after.', 'ux-studio' )
						: sprintf(
								/* translators: %s: role names. */
								__( 'Nothing is hidden, before or after, for: %s.', 'ux-studio' ),
								quiet.map( ( row ) => row.label ).join( ', ' )
						  ) }
				</p>
			) }
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

/** Expandable "Show detail" list for a complex value: label, value; never raw internal ids. */
export function DetailRows( { rows }: { rows: DetailRow[] } ): JSX.Element | null {
	if ( 0 === rows.length ) {
		return null;
	}
	return (
		<details className="uxs-wiz-detail">
			<summary>{ __( 'Show detail', 'ux-studio' ) }</summary>
			<dl>
				{ rows.map( ( row, i ) => (
					<div key={ `${ i }-${ row.label }` }>
						{ '' !== row.label && <dt>{ row.label }</dt> }
						<dd>{ row.value }</dd>
					</div>
				) ) }
			</dl>
		</details>
	);
}

/** One value of the change table: short text, plus the expandable detail for complex values. */
function ValueCell( { text, rows }: { text: string; rows?: DetailRow[] } ): JSX.Element {
	return (
		<>
			<code>{ '' === text ? '-' : text }</code>
			{ rows && <DetailRows rows={ rows } /> }
		</>
	);
}

/** "now -> new" table used by the dry run. */
export function ChangeTable( { items }: { items: PlanItem[] } ): JSX.Element {
	return (
		<div className="uxs-wiz-tablewrap">
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
							<td className="uxs-wiz-changes__label">{ item.label }</td>
							<td data-col={ __( 'Now in UX Studio', 'ux-studio' ) }>
								<ValueCell text={ item.from } rows={ item.from_detail } />
							</td>
							<td data-col={ __( 'After migration', 'ux-studio' ) }>
								<ValueCell text={ item.to } rows={ item.to_detail } />
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
		</div>
	);
}

/** Heading + lead paragraph of a step. */
export function StepHead( { icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode } ): JSX.Element {
	return (
		<header className="uxs-wiz-stephead">
			{ icon && <span className="uxs-wiz-stephead__icon">{ icon }</span> }
			<div>
				<h2>{ title }</h2>
				{ children && <p>{ children }</p> }
			</div>
		</header>
	);
}

export function ErrorLine( { text }: { text: string | null } ): JSX.Element | null {
	return text ? (
		<p className="uxs-wiz-error" role="alert">
			{ text }
		</p>
	) : null;
}

/** Current time in ms, refreshed on an interval (for "ends at" and expiry hints). */
export function useNow( every = 20000 ): number {
	const [ now, setNow ] = useState( () => Date.now() );
	useEffect( () => {
		const timer = window.setInterval( () => setNow( Date.now() ), every );
		return () => window.clearInterval( timer );
	}, [ every ] );
	return now;
}

function rowState( result: SmokeResult | undefined, running: boolean ): 'pending' | 'running' | 'ok' | 'error' | 'warn' {
	if ( ! result ) {
		return running ? 'running' : 'pending';
	}
	return 'fail' === result.status ? 'error' : 'warn' === result.status ? 'warn' : 'ok';
}

/** Test rows that fill in one by one (CSS animation only; static under reduced motion). */
export function TestRows( { run, labels }: { run: SmokeRun; labels?: Record< string, string > } ): JSX.Element {
	return (
		<ul className="uxs-wiz-tests" aria-live="polite">
			{ run.plan.map( ( item, index ) => {
				const result = run.results[ item.id ];
				const state = rowState( result, run.current === item.id );
				return (
					<li key={ item.id } className={ `uxs-wiz-trow is-${ state }` } style={ { '--i': index } as React.CSSProperties }>
						<StateIcon state={ state } />
						<div className="uxs-wiz-trow__body">
							<strong>{ item.label }</strong>
							{ 'site' !== item.group && labels?.[ item.group ] && <span className="uxs-wiz-muted">{ labels[ item.group ] }</span> }
							{ result && '' !== result.detail && <span className={ 'fail' === result.status ? 'uxs-wiz-error-text' : 'uxs-wiz-muted' }>{ result.detail }</span> }
						</div>
						{ result && result.ms > 0 && <span className="uxs-wiz-muted uxs-wiz-trow__ms">{ sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'ux-studio' ), result.ms ) }</span> }
					</li>
				);
			} ) }
		</ul>
	);
}

/** Totals of a finished test run as one line. */
export function summarize( results: SmokeResult[] ): SmokeCounts {
	const out: SmokeCounts = { ok: 0, warn: 0, fail: 0, info: 0 };
	results.forEach( ( r ) => {
		out[ r.status ] += 1;
	} );
	return out;
}

export function testsSentence( counts: SmokeCounts, total: number ): string {
	if ( counts.fail > 0 ) {
		return sprintf(
			/* translators: 1: number of failed tests, 2: number of tests. */
			__( '%1$d of %2$d tests failed.', 'ux-studio' ),
			counts.fail,
			total
		);
	}
	if ( counts.warn > 0 ) {
		return sprintf(
			/* translators: 1: number of tests with a warning, 2: number of tests. */
			__( 'All %2$d tests passed, %1$d with a warning.', 'ux-studio' ),
			counts.warn,
			total
		);
	}
	if ( 1 === total ) {
		return __( 'The test passed.', 'ux-studio' );
	}
	return sprintf(
		/* translators: %d: number of tests. */
		__( 'All %d tests passed.', 'ux-studio' ),
		total
	);
}

/** Confirmation dialog (Escape cancels, focus goes to the confirm button). */
export function ConfirmModal( {
	id,
	title,
	confirmLabel,
	busy = false,
	error = null,
	onConfirm,
	onCancel,
	children,
}: {
	id: string;
	title: string;
	confirmLabel: string;
	busy?: boolean;
	error?: string | null;
	onConfirm: () => void;
	onCancel: () => void;
	children: ReactNode;
} ): JSX.Element {
	const confirmRef = useRef< HTMLButtonElement >( null );
	useEffect( () => {
		confirmRef.current?.focus();
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! busy ) {
				onCancel();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ busy, onCancel ] );
	return (
		<div className="uxs-overlay">
			<div className="uxs-modal" role="dialog" aria-modal="true" aria-labelledby={ id }>
				<h2 id={ id }>{ title }</h2>
				{ children }
				<ErrorLine text={ error } />
				<div className="uxs-modal__actions">
					<button type="button" className="button" disabled={ busy } onClick={ onCancel }>
						{ __( 'Cancel', 'ux-studio' ) }
					</button>
					<button ref={ confirmRef } type="button" className="button button-primary" disabled={ busy } onClick={ onConfirm }>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>
	);
}

/* ── Undo of a migration: what is restored and what would be lost ── */

/** GET migration/rollback-impact. */
export interface RollbackImpact {
	run_id: string;
	modules: { id: string; name: string }[];
	tables: string[];
	/** False when no fingerprint of the state right after the run exists, so changes cannot be told. */
	sealed: boolean;
	changed: boolean;
	needs_ack: boolean;
	changed_settings: string[];
	changed_tables: { name: string; rows_then: number; rows_now: number }[];
}

/**
 * Confirmation of an undo. An undo puts the state from BEFORE the run back, so anything changed in the same
 * modules and tables since the run is lost. The dialog lists what is restored and, when a setting or table was
 * changed after the run (or that cannot be told), asks for an explicit tick before the button works.
 */
export function UndoMigrationModal( {
	id,
	runId,
	title,
	confirmLabel,
	busy,
	error,
	onConfirm,
	onCancel,
	children,
}: {
	id: string;
	runId: string;
	title: string;
	confirmLabel: string;
	busy: boolean;
	error: string | null;
	onConfirm: () => void;
	onCancel: () => void;
	children: ReactNode;
} ): JSX.Element {
	const [ acked, setAcked ] = useState( false );
	const q = useQuery( {
		queryKey: [ 'migration', 'rollback-impact', runId ],
		queryFn: () => api< RollbackImpact >( `migration/rollback-impact?run_id=${ encodeURIComponent( runId ) }` ),
		staleTime: 0,
		gcTime: 0,
		retry: false,
	} );
	const impact = q.data;
	// If the check itself fails we cannot rule out lost changes, so the tick is required.
	const needsAck = q.isError || ( impact?.needs_ack ?? false );
	const ready = ! q.isLoading;
	return (
		<ConfirmModal
			id={ id }
			title={ title }
			confirmLabel={ confirmLabel }
			busy={ busy || ! ready || ( needsAck && ! acked ) }
			error={ error }
			onCancel={ onCancel }
			onConfirm={ onConfirm }
		>
			{ children }
			<p>
				<strong>{ __( 'Everything changed after the migration in the places below is lost.', 'ux-studio' ) }</strong>{ ' ' }
				{ __( 'The undo does not merge, it puts back the state from before the migration.', 'ux-studio' ) }
			</p>
			{ q.isLoading && <p className="uxs-wiz-muted">{ __( 'Checking what the undo would restore…', 'ux-studio' ) }</p> }
			{ impact && (
				<ul className="uxs-wiz-checks">
					{ impact.modules.length > 0 && (
						<li>
							<span>
								{ sprintf(
									/* translators: %s: names of modules. */
									__( 'Modules whose settings are restored: %s', 'ux-studio' ),
									impact.modules.map( ( m ) => m.name ).join( ', ' )
								) }
							</span>
						</li>
					) }
					{ impact.tables.length > 0 && (
						<li>
							<span>
								{ sprintf(
									/* translators: %s: names of database tables. */
									__( 'Tables rebuilt from the backup: %s', 'ux-studio' ),
									impact.tables.join( ', ' )
								) }
							</span>
						</li>
					) }
				</ul>
			) }
			{ needsAck && ! q.isLoading && (
				<div className="uxs-wiz-banner is-bad" role="alert">
					<TriangleAlert size={ 16 } aria-hidden />
					<div>
						{ q.isError && <p>{ __( 'It could not be checked whether anything changed since the migration.', 'ux-studio' ) }</p> }
						{ impact && ! impact.sealed && <p>{ __( 'It cannot be told whether anything changed since the migration.', 'ux-studio' ) }</p> }
						{ impact && impact.changed_settings.length > 0 && (
							<p>
								{ sprintf(
									/* translators: %s: names of modules or settings. */
									__( 'Changed since the migration: settings of %s.', 'ux-studio' ),
									impact.changed_settings.join( ', ' )
								) }
							</p>
						) }
						{ impact &&
							impact.changed_tables.map( ( t ) => (
								<p key={ t.name }>
									{ sprintf(
										/* translators: 1: table name, 2: rows after the migration, 3: rows now. */
										__( 'Changed since the migration: table %1$s (%2$d rows after the migration, %3$d now).', 'ux-studio' ),
										t.name,
										t.rows_then,
										t.rows_now
									) }
								</p>
							) ) }
						<label className="uxs-wiz-ack">
							<input type="checkbox" checked={ acked } onChange={ ( e ) => setAcked( e.target.checked ) } />{ ' ' }
							{ __( 'I understand that these later changes will be lost.', 'ux-studio' ) }
						</label>
					</div>
				</div>
			) }
		</ConfirmModal>
	);
}
