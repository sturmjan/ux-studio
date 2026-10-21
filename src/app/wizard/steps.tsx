/**
 * The wizard steps. `STEPS` is the ordered list the shell renders - to add a step
 * (the safe preview and the deactivation come in phase F4) append an entry here;
 * nothing else needs to know how many steps there are.
 */
import { useEffect, useState, type ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowRight, Camera, ChevronDown, CircleCheck, History as HistoryIcon, Radar, ShieldCheck, Undo2, Wand2 } from 'lucide-react';
import { navigate } from '../route';
import type { WizardCtx } from './context';
import { ChangeTable, DrawnCheck, MatchBadge, PlanDetails, SourceIcon, StateIcon, StatusBadge, Warnings, reducedMotion, settingsLabel } from './parts';
import type { RunSource, ScanSource } from './types';

export interface WizardStep {
	id: string;
	title: string;
	render: ( ctx: WizardCtx ) => JSX.Element;
}

/** Heading + lead paragraph of a step. */
function StepHead( { icon, title, children }: { icon?: ReactNode; title: string; children?: ReactNode } ): JSX.Element {
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

function ErrorLine( { text }: { text: string | null } ): JSX.Element | null {
	return text ? (
		<p className="uxs-wiz-error" role="alert">
			{ text }
		</p>
	) : null;
}

/* ── 1 Welcome ── */

function WelcomeStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const points: { icon: JSX.Element; text: string }[] = [
		{ icon: <Radar size={ 18 } aria-hidden />, text: __( 'The wizard finds plugins on this site whose job a UX Studio module does as well.', 'ux-studio' ) },
		{ icon: <ShieldCheck size={ 18 } aria-hidden />, text: __( 'It only reads the plugins. Nothing in them changes, and nothing is switched on or deactivated.', 'ux-studio' ) },
		{ icon: <Camera size={ 18 } aria-hidden />, text: __( 'Before the first write it saves a snapshot, so you can undo the whole migration for 30 days.', 'ux-studio' ) },
	];
	return (
		<section className="uxs-wiz-panel uxs-wiz-welcome">
			<StepHead icon={ <Wand2 size={ 26 } aria-hidden /> } title={ __( 'Move your settings to UX Studio', 'ux-studio' ) }>
				{ __( 'Copy the settings of other plugins into the matching UX Studio modules, check that nothing got lost and keep the option to go back.', 'ux-studio' ) }
			</StepHead>
			<ul className="uxs-wiz-points">
				{ points.map( ( p ) => (
					<li key={ p.text }>
						{ p.icon }
						<span>{ p.text }</span>
					</li>
				) ) }
			</ul>
			<p className="uxs-wiz-muted">{ __( 'Switching to the module and deactivating the old plugin is a separate step that comes after a safe preview of the site (next version).', 'ux-studio' ) }</p>
			<div className="uxs-wiz-actions">
				<button type="button" className="button button-primary" onClick={ ctx.next }>
					{ __( 'Scan the site', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 2 Scan ── */

function ScanCard( { source, index }: { source: ScanSource; index: number } ): JSX.Element {
	return (
		<li className="uxs-wiz-scancard" style={ { '--i': index } as React.CSSProperties }>
			<SourceIcon module={ source.module } />
			<div className="uxs-wiz-scancard__body">
				<div className="uxs-wiz-scancard__head">
					<strong>{ source.label }</strong>
					{ source.version && <span className="uxs-wiz-muted">{ source.version }</span> }
				</div>
				<div className="uxs-wiz-badges">
					<StatusBadge source={ source } />
					<MatchBadge match={ source.match } />
				</div>
				<p className="uxs-wiz-muted">
					{ sprintf(
						/* translators: 1: UX Studio module name, 2: "12 settings". */
						__( 'Module: %1$s - %2$s', 'ux-studio' ),
						source.module_name,
						settingsLabel( source.summary.settings )
					) }
				</p>
			</div>
		</li>
	);
}

function ScanStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const [ reveal, setReveal ] = useState( false );

	// The radar runs at least a moment even when the answer is instant.
	useEffect( () => {
		if ( ! ctx.scan ) {
			setReveal( false );
			return undefined;
		}
		const timer = window.setTimeout( () => setReveal( true ), reducedMotion() ? 0 : 1500 );
		return () => window.clearTimeout( timer );
	}, [ ctx.scan ] );

	if ( ctx.scanError ) {
		return (
			<section className="uxs-wiz-panel">
				<StepHead title={ __( 'Scan failed', 'ux-studio' ) } />
				<ErrorLine text={ ctx.scanError } />
				<div className="uxs-wiz-actions">
					<button type="button" className="button" onClick={ () => ctx.goTo( 'welcome' ) }>
						{ __( 'Back', 'ux-studio' ) }
					</button>
				</div>
			</section>
		);
	}

	if ( ! ctx.scan || ! reveal ) {
		return (
			<section className="uxs-wiz-panel uxs-wiz-scanning" aria-live="polite">
				<div className="uxs-wiz-radar" aria-hidden>
					<span className="uxs-wiz-radar__sweep" />
					<span className="uxs-wiz-radar__pulse" />
					<span className="uxs-wiz-radar__pulse" />
					<span className="uxs-wiz-radar__pulse" />
					<Radar size={ 34 } />
				</div>
				<h2>{ __( 'Scanning the site…', 'ux-studio' ) }</h2>
				<p className="uxs-wiz-muted">{ __( 'Looking for plugins UX Studio can take over from.', 'ux-studio' ) }</p>
			</section>
		);
	}

	const found = ctx.scan;
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <Radar size={ 26 } aria-hidden /> } title={ 0 === found.length ? __( 'No plugin to migrate found', 'ux-studio' ) : sprintf(
				/* translators: %d: number of plugins found. */
				_n( 'Found %d plugin', 'Found %d plugins', found.length, 'ux-studio' ),
				found.length
			) }>
				{ 0 === found.length
					? __( 'None of the plugins UX Studio can migrate is installed here, and no leftover settings of them were found.', 'ux-studio' )
					: __( 'These plugins (or their leftover settings) have a matching UX Studio module.', 'ux-studio' ) }
			</StepHead>
			{ found.length > 0 && (
				<ul className="uxs-wiz-scangrid">
					{ found.map( ( s, i ) => (
						<ScanCard key={ s.id } source={ s } index={ i } />
					) ) }
				</ul>
			) }
			<div className="uxs-wiz-actions">
				<button type="button" className="button" onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ 0 === found.length } onClick={ ctx.next }>
					{ __( 'Choose what to migrate', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 3 Select ── */

function SelectCard( { source, ctx }: { source: ScanSource; ctx: WizardCtx } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const checked = ctx.selected.includes( source.id );
	const plan = ctx.plans?.[ source.id ];
	const bodyId = `uxs-wiz-plan-${ source.id }`;

	return (
		<li className={ `uxs-wiz-choice${ checked ? ' is-selected' : '' }` }>
			<div className="uxs-wiz-choice__row">
				<button
					type="button"
					role="checkbox"
					aria-checked={ checked }
					aria-label={ sprintf(
						/* translators: %s: plugin name. */
						__( 'Migrate %s', 'ux-studio' ),
						source.label
					) }
					className="uxs-wiz-tick"
					onClick={ () => ctx.toggle( source.id ) }
				>
					{ checked && <DrawnCheck size={ 16 } /> }
				</button>
				<SourceIcon module={ source.module } />
				<div className="uxs-wiz-choice__body">
					<div className="uxs-wiz-scancard__head">
						<strong>{ source.label }</strong>
						{ source.version && <span className="uxs-wiz-muted">{ source.version }</span> }
					</div>
					<div className="uxs-wiz-badges">
						<StatusBadge source={ source } />
						<MatchBadge match={ source.match } />
						<span className="uxs-wiz-muted">{ settingsLabel( source.summary.settings ) }</span>
					</div>
				</div>
				<button type="button" className="button-link uxs-wiz-expand" aria-expanded={ open } aria-controls={ bodyId } onClick={ () => setOpen( ! open ) }>
					{ __( 'What moves over', 'ux-studio' ) }
					<ChevronDown size={ 15 } aria-hidden className={ open ? 'is-open' : '' } />
				</button>
			</div>
			{ open && (
				<div id={ bodyId } className="uxs-wiz-choice__plan">
					{ plan ? <PlanDetails plan={ plan } /> : <p className="uxs-wiz-muted">{ __( 'Loading…', 'ux-studio' ) }</p> }
				</div>
			) }
		</li>
	);
}

function SelectStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const sources = ctx.scan ?? [];
	const all = sources.map( ( s ) => s.id );
	return (
		<section className="uxs-wiz-panel">
			<StepHead title={ __( 'Choose what to migrate', 'ux-studio' ) }>
				{ __( 'Tick the plugins whose settings should be copied. Inactive plugins can be migrated too. Open "What moves over" to see exactly what is and is not transferred.', 'ux-studio' ) }
			</StepHead>
			<div className="uxs-wiz-bulk">
				<button type="button" className="button-link" onClick={ () => ctx.setSelected( all ) }>
					{ __( 'Select all', 'ux-studio' ) }
				</button>
				<button type="button" className="button-link" onClick={ () => ctx.setSelected( [] ) }>
					{ __( 'Clear selection', 'ux-studio' ) }
				</button>
			</div>
			<ul className="uxs-wiz-choices">
				{ sources.map( ( s ) => (
					<SelectCard key={ s.id } source={ s } ctx={ ctx } />
				) ) }
			</ul>
			<div className="uxs-wiz-actions">
				<button type="button" className="button" onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ 0 === ctx.selected.length } onClick={ ctx.next }>
					{ sprintf(
						/* translators: %d: number of selected plugins. */
						_n( 'Check %d plugin', 'Check %d plugins', ctx.selected.length, 'ux-studio' ),
						ctx.selected.length
					) }{ ' ' }
					<ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 4 Check (dry run) ── */

function CheckStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const rows = ctx.selected.map( ( id ) => ( {
		id,
		plan: ctx.plans?.[ id ],
		result: ctx.dry?.per_source[ id ],
	} ) );
	const finished = !! ctx.dry && 'running' !== ctx.dry.status;
	const problems = rows.some( ( r ) => r.result && 'error' === r.result.status );

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <CircleCheck size={ 26 } aria-hidden /> } title={ __( 'Check before writing', 'ux-studio' ) }>
				{ __( 'This is a trial run: nothing has been written. Below is what each plugin would change in UX Studio.', 'ux-studio' ) }
			</StepHead>

			<p className="uxs-wiz-banner">
				<Camera size={ 16 } aria-hidden />
				{ __( 'When you start the migration, a snapshot of the current UX Studio settings is saved first. You can restore it for 30 days.', 'ux-studio' ) }
			</p>

			<ErrorLine text={ ctx.dryError } />
			{ ! finished && ! ctx.dryError && <p className="uxs-wiz-muted">{ __( 'Running the trial…', 'ux-studio' ) }</p> }

			<div className="uxs-wiz-sourcelist">
				{ rows.map( ( { id, plan, result } ) => (
					<article key={ id } className="uxs-wiz-source">
						<header className="uxs-wiz-source__head">
							<SourceIcon module={ plan?.module ?? '' } />
							<div>
								<strong>{ plan?.label ?? id }</strong>
								<span className="uxs-wiz-muted"> - { plan?.module_name }</span>
							</div>
							<StateIcon state={ ! result ? 'pending' : 'error' === result.status ? 'error' : 'done' === result.status ? 'ok' : 'running' } />
						</header>
						{ result && result.items.length > 0 && <ChangeTable items={ result.items } /> }
						{ result && 0 === result.items.length && <p className="uxs-wiz-muted">{ plan?.notes[ 0 ] ?? __( 'Nothing to transfer.', 'ux-studio' ) }</p> }
						{ result && result.errors.length > 0 && <Warnings warnings={ result.errors } /> }
						{ plan && <Warnings warnings={ plan.warnings } /> }
						{ plan && plan.lossy.length > 0 && (
							<div className="uxs-wiz-lossy">
								<h4 className="uxs-wiz-plan__title is-no">{ __( 'Will not be transferred', 'ux-studio' ) }</h4>
								<ul className="uxs-wiz-plan__list is-lossy">
									{ plan.lossy.map( ( t ) => (
										<li key={ t }>{ t }</li>
									) ) }
								</ul>
							</div>
						) }
					</article>
				) ) }
			</div>

			<div className="uxs-wiz-actions">
				<button type="button" className="button" onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				{ ctx.dryError && (
					<button type="button" className="button" onClick={ ctx.retryDry }>
						{ __( 'Try again', 'ux-studio' ) }
					</button>
				) }
				<button type="button" className="button button-primary" disabled={ ! finished || problems || !! ctx.dryError || ctx.runBusy } onClick={ ctx.startMigration }>
					{ __( 'Start the migration', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 5 Migrate ── */

function rowState( row: RunSource | undefined ): 'pending' | 'running' | 'ok' | 'error' {
	if ( ! row ) {
		return 'pending';
	}
	if ( 'error' === row.status ) {
		return 'error';
	}
	return 'done' === row.status ? 'ok' : 'pending' === row.status ? 'pending' : 'running';
}

function resultText( row: RunSource | undefined ): string {
	if ( ! row ) {
		return '';
	}
	if ( 'error' === row.status ) {
		return row.errors[ 0 ] ?? __( 'Failed', 'ux-studio' );
	}
	if ( 'done' !== row.status ) {
		return 'pending' === row.status ? __( 'Waiting', 'ux-studio' ) : __( 'Working…', 'ux-studio' );
	}
	if ( 0 === row.written ) {
		return __( 'Nothing to change', 'ux-studio' );
	}
	return sprintf(
		/* translators: %d: number of settings written. */
		_n( '%d setting written', '%d settings written', row.written, 'ux-studio' ),
		row.written
	);
}

function MigrateStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const run = ctx.run;
	const done = !! run && 'running' !== run.status;
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ArrowRight size={ 26 } aria-hidden /> } title={ done ? __( 'Migration finished', 'ux-studio' ) : __( 'Migrating…', 'ux-studio' ) }>
				{ __( 'Please keep this page open. If it closes, come back to the wizard and it will carry on.', 'ux-studio' ) }
			</StepHead>
			<ErrorLine text={ ctx.runError } />
			<ul className="uxs-wiz-progress">
				<li className="uxs-wiz-prow">
					<StateIcon state={ run?.snapshot ? 'ok' : 'running' } />
					<div className="uxs-wiz-prow__body">
						<strong>{ __( 'Snapshot of the current settings', 'ux-studio' ) }</strong>
						<span className="uxs-wiz-muted">{ run?.snapshot ? __( 'Saved - can be restored for 30 days', 'ux-studio' ) : __( 'Saving…', 'ux-studio' ) }</span>
					</div>
					<span className="uxs-wiz-bar" aria-hidden>
						<span style={ { width: run?.snapshot ? '100%' : '30%' } } />
					</span>
				</li>
				{ ( run?.sources ?? ctx.selected ).map( ( id ) => {
					const row = run?.per_source[ id ];
					const state = rowState( row );
					return (
						<li key={ id } className={ `uxs-wiz-prow is-${ state }` }>
							<StateIcon state={ state } />
							<div className="uxs-wiz-prow__body">
								<strong>{ row?.label ?? ctx.plans?.[ id ]?.label ?? id }</strong>
								<span className={ 'error' === state ? 'uxs-wiz-error-text' : 'uxs-wiz-muted' }>{ resultText( row ) }</span>
							</div>
							<span
								className="uxs-wiz-bar"
								role="progressbar"
								aria-valuemin={ 0 }
								aria-valuemax={ 100 }
								aria-valuenow={ row?.progress ?? 0 }
								aria-label={ row?.label ?? id }
							>
								<span className={ 'error' === state ? 'is-error' : '' } style={ { width: `${ row?.progress ?? 0 }%` } } />
							</span>
						</li>
					);
				} ) }
			</ul>
			<div className="uxs-wiz-actions">
				<button type="button" className="button button-primary" disabled={ ! done } onClick={ ctx.next }>
					{ __( 'Verify the result', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 6 Verify ── */

function VerifyStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const [ confirm, setConfirm ] = useState( false );
	const verify = ctx.verify;
	const failed = verify ? Object.values( verify ).flat().filter( ( c ) => ! c.ok ).length : 0;
	const labels = ctx.run?.per_source ?? {};

	useEffect( () => {
		if ( ! confirm ) {
			return undefined;
		}
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! ctx.rollbackBusy ) {
				setConfirm( false );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ confirm, ctx.rollbackBusy ] );

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ShieldCheck size={ 26 } aria-hidden /> } title={ __( 'Verify the result', 'ux-studio' ) }>
				{ __( 'The old plugin settings are compared with what is now stored in UX Studio.', 'ux-studio' ) }
			</StepHead>
			<ErrorLine text={ ctx.verifyError } />
			{ ctx.verifyBusy && ! verify && <p className="uxs-wiz-muted">{ __( 'Comparing…', 'ux-studio' ) }</p> }

			{ verify && (
				<p className={ `uxs-wiz-banner ${ 0 === failed ? 'is-ok' : 'is-bad' }` } role="status">
					{ 0 === failed ? <CircleCheck size={ 16 } aria-hidden /> : <Undo2 size={ 16 } aria-hidden /> }
					{ 0 === failed
						? __( 'Everything matches.', 'ux-studio' )
						: sprintf(
								/* translators: %d: number of differences. */
								_n( '%d difference found. You can undo the migration or continue and fix it by hand.', '%d differences found. You can undo the migration or continue and fix them by hand.', failed, 'ux-studio' ),
								failed
						  ) }
				</p>
			) }

			<div className="uxs-wiz-sourcelist">
				{ verify &&
					Object.entries( verify ).map( ( [ id, checks ] ) => (
						<article key={ id } className="uxs-wiz-source">
							<header className="uxs-wiz-source__head">
								<strong>{ labels[ id ]?.label ?? id }</strong>
								<StateIcon state={ checks.every( ( c ) => c.ok ) ? 'ok' : 'error' } />
							</header>
							<ul className="uxs-wiz-checks">
								{ checks.map( ( c ) => (
									<li key={ c.id } className={ c.ok ? 'is-ok' : 'is-bad' }>
										<StateIcon state={ c.ok ? 'ok' : 'error' } />
										<span>{ c.label }</span>
										{ c.detail && <span className="uxs-wiz-muted">{ c.detail }</span> }
									</li>
								) ) }
							</ul>
						</article>
					) ) }
			</div>

			<div className="uxs-wiz-actions">
				<button type="button" className="button" disabled={ ctx.rollbackBusy || ! ctx.run?.snapshot || ctx.rolledBack } onClick={ () => setConfirm( true ) }>
					<Undo2 size={ 16 } aria-hidden /> { __( 'Undo the migration', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ ! verify } onClick={ ctx.next }>
					{ __( 'Continue', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>

			{ confirm && (
				<div className="uxs-overlay">
					<div className="uxs-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-wiz-undo-title">
						<h2 id="uxs-wiz-undo-title">{ __( 'Undo this migration?', 'ux-studio' ) }</h2>
						<p>{ __( 'The UX Studio settings go back exactly to how they were before the migration. The old plugins are not touched.', 'ux-studio' ) }</p>
						<div className="uxs-modal__actions">
							<button type="button" className="button" disabled={ ctx.rollbackBusy } onClick={ () => setConfirm( false ) }>
								{ __( 'Cancel', 'ux-studio' ) }
							</button>
							<button
								type="button"
								className="button button-primary"
								disabled={ ctx.rollbackBusy }
								onClick={ () => {
									ctx.rollback();
									setConfirm( false );
								} }
							>
								{ ctx.rollbackBusy ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo the migration', 'ux-studio' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</section>
	);
}

/* ── 7 Done ── */

function DoneStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const rows = Object.values( ctx.run?.per_source ?? {} );
	const written = rows.reduce( ( sum, r ) => sum + r.written, 0 );

	if ( ctx.rolledBack ) {
		return (
			<section className="uxs-wiz-panel uxs-wiz-finish">
				<span className="uxs-wiz-finish__badge is-undo">
					<Undo2 size={ 30 } aria-hidden />
				</span>
				<h2>{ __( 'The migration was undone', 'ux-studio' ) }</h2>
				<p>{ __( 'UX Studio settings are back to how they were before. The old plugins were never changed.', 'ux-studio' ) }</p>
				<div className="uxs-wiz-actions is-center">
					<button type="button" className="button" onClick={ ctx.restart }>
						{ __( 'Start over', 'ux-studio' ) }
					</button>
					<button type="button" className="button button-primary" onClick={ () => navigate( '' ) }>
						{ __( 'Back to modules', 'ux-studio' ) }
					</button>
				</div>
			</section>
		);
	}

	return (
		<section className="uxs-wiz-panel uxs-wiz-finish">
			<span className="uxs-wiz-finish__badge">
				<DrawnCheck size={ 34 } />
			</span>
			<h2>{ __( 'Settings migrated', 'ux-studio' ) }</h2>
			<p>
				{ sprintf(
					/* translators: 1: number of plugins, 2: number of settings written. */
					__( '%1$d plugin(s) processed, %2$d setting(s) written to UX Studio.', 'ux-studio' ),
					rows.length,
					written
				) }
			</p>
			<div className="uxs-wiz-next">
				<h3>{ __( 'What happens next', 'ux-studio' ) }</h3>
				<ul>
					<li>{ __( 'The modules are still switched off and the old plugins still run - the site behaves exactly as before.', 'ux-studio' ) }</li>
					<li>{ __( 'Switching to the module and deactivating the plugin will follow in the next version, after a safe preview of the site without the old plugins.', 'ux-studio' ) }</li>
					<li>{ __( 'Until then you can undo this migration from the run history for 30 days.', 'ux-studio' ) }</li>
				</ul>
			</div>
			<div className="uxs-wiz-actions is-center">
				<a
					className="button"
					href="#uxs-wiz-history"
					onClick={ ( e ) => {
						e.preventDefault();
						document.getElementById( 'uxs-wiz-history' )?.scrollIntoView( { behavior: reducedMotion() ? 'auto' : 'smooth' } );
					} }
				>
					<HistoryIcon size={ 16 } aria-hidden /> { __( 'Run history', 'ux-studio' ) }
				</a>
				<button type="button" className="button button-primary" onClick={ () => navigate( '' ) }>
					{ __( 'Back to modules', 'ux-studio' ) }
				</button>
			</div>
		</section>
	);
}

/**
 * Ordered steps (a function, so the titles are translated when the wizard opens,
 * not when the bundle loads). Phase F4 inserts "preview" and "deactivate" before `done`.
 */
export function getSteps(): WizardStep[] {
	return [
		{ id: 'welcome', title: __( 'Welcome', 'ux-studio' ), render: ( ctx ) => <WelcomeStep ctx={ ctx } /> },
		{ id: 'scan', title: __( 'Scan', 'ux-studio' ), render: ( ctx ) => <ScanStep ctx={ ctx } /> },
		{ id: 'select', title: __( 'Selection', 'ux-studio' ), render: ( ctx ) => <SelectStep ctx={ ctx } /> },
		{ id: 'check', title: __( 'Check', 'ux-studio' ), render: ( ctx ) => <CheckStep ctx={ ctx } /> },
		{ id: 'migrate', title: __( 'Migration', 'ux-studio' ), render: ( ctx ) => <MigrateStep ctx={ ctx } /> },
		{ id: 'verify', title: __( 'Verification', 'ux-studio' ), render: ( ctx ) => <VerifyStep ctx={ ctx } /> },
		{ id: 'done', title: __( 'Done', 'ux-studio' ), render: ( ctx ) => <DoneStep ctx={ ctx } /> },
	];
}
