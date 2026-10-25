/**
 * The wizard steps. `getSteps()` is the ordered list the shell renders - to add a step
 * insert an entry there; nothing else needs to know how many steps there are.
 * The safe preview, the switch and the finish of a switched run are in steps-preview.tsx.
 */
import { useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowRight, Camera, ChevronDown, CircleCheck, Eye, History as HistoryIcon, Power, Radar, ShieldCheck, Sparkles, Undo2, Wand2 } from 'lucide-react';
import { navigate } from '../route';
import type { WizardCtx } from './context';
import { ChangeTable, DataCounts, DetailRows, DrawnCheck, ErrorLine, MatchBadge, MenuRoleTable, PartialNote, PlanDetails, SourceIcon, StateIcon, StatusBadge, StepHead, UndoMigrationModal, Warnings, reducedMotion, sourceSummary } from './parts';
import { PreviewStep, RevertedDone, SwitchStep, SwitchedDone } from './steps-preview';
import type { RunSource, ScanSource } from './types';

export interface WizardStep {
	id: string;
	title: string;
	render: ( ctx: WizardCtx ) => JSX.Element;
}

/* ── 1 Welcome ── */

function WelcomeStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const points: { icon: JSX.Element; text: string }[] = [
		{ icon: <Radar size={ 18 } aria-hidden />, text: __( 'The wizard finds plugins on this site whose job a UX Studio module does as well.', 'ux-studio' ) },
		{ icon: <ShieldCheck size={ 18 } aria-hidden />, text: __( 'It only reads the plugins. Nothing in them changes, and nothing is switched on or deactivated until you confirm the switch.', 'ux-studio' ) },
		{ icon: <Camera size={ 18 } aria-hidden />, text: __( 'Before the first write it saves a snapshot, so you can undo the whole migration for 30 days.', 'ux-studio' ) },
		{ icon: <Eye size={ 18 } aria-hidden />, text: __( 'Before the switch you can look at the site without the old plugins in a safe preview that only your browser sees. Visitors keep the old plugins the whole time.', 'ux-studio' ) },
		{ icon: <Power size={ 18 } aria-hidden />, text: __( 'No old plugin is ever deactivated automatically. You decide at the end, and even then you can go back.', 'ux-studio' ) },
	];
	return (
		<section className="uxs-wiz-panel uxs-wiz-welcome">
			<StepHead icon={ <Wand2 size={ 26 } aria-hidden /> } title={ __( 'Move your settings to UX Studio', 'ux-studio' ) }>
				{ __( 'Copy the settings and data of other plugins (redirects, snippets, folders, mail settings ...) into the matching UX Studio modules, check that nothing got lost and keep the option to go back.', 'ux-studio' ) }
			</StepHead>
			<ul className="uxs-wiz-points">
				{ points.map( ( p ) => (
					<li key={ p.text }>
						{ p.icon }
						<span>{ p.text }</span>
					</li>
				) ) }
			</ul>
			<p className="uxs-wiz-muted">{ __( 'Switching to the module and deactivating the old plugin is a separate step that comes after a safe preview of the site without the old plugins.', 'ux-studio' ) }</p>
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
						sourceSummary( source.summary )
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

/** A partial match (Admin Customiser) can only be ticked after its list of what is lost was opened. */
function SelectCard( { source, ctx, reviewed, onReview }: { source: ScanSource; ctx: WizardCtx; reviewed: boolean; onReview: ( id: string ) => void } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const checked = ctx.selected.includes( source.id );
	const plan = ctx.plans?.[ source.id ];
	const bodyId = `uxs-wiz-plan-${ source.id }`;
	const partial = 'partial' === source.match;
	const locked = partial && ! reviewed && ! checked;
	const expand = ( next: boolean ): void => {
		setOpen( next );
		if ( next && partial ) {
			onReview( source.id );
		}
	};

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
					disabled={ locked }
					title={ locked ? __( 'Open the list of what is not transferred first.', 'ux-studio' ) : undefined }
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
						<span className="uxs-wiz-muted">{ sourceSummary( source.summary ) }</span>
					</div>
					{ partial && <PartialNote reviewed={ reviewed || checked } onOpen={ () => expand( true ) } /> }
					{ plan && plan.options.length > 0 && (
						<div className="uxs-wiz-options">
							{ plan.options.map( ( option ) => (
								<label key={ option.key } className="uxs-wiz-option" title={ option.help }>
									<input
										type="checkbox"
										disabled={ ! checked }
										checked={ ctx.options[ source.id ]?.[ option.key ] ?? option.default }
										onChange={ ( e ) => ctx.setOption( source.id, option.key, e.target.checked ) }
									/>
									<span>{ option.label }</span>
									<span className="uxs-wiz-muted">{ option.help }</span>
								</label>
							) ) }
						</div>
					) }
				</div>
				<button type="button" className="button-link uxs-wiz-expand" aria-expanded={ open } aria-controls={ bodyId } onClick={ () => expand( ! open ) }>
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
	// Partial matches the admin has looked at (the list of what is lost was opened).
	const [ reviewed, setReviewed ] = useState< string[] >( [] );
	const review = ( id: string ): void => setReviewed( ( prev ) => ( prev.includes( id ) ? prev : [ ...prev, id ] ) );
	const all = sources.filter( ( s ) => 'partial' !== s.match || reviewed.includes( s.id ) || ctx.selected.includes( s.id ) ).map( ( s ) => s.id );

	// Two selected plugins that both carry SETTINGS for one module overwrite each other (the later one wins).
	const clashes = Object.values(
		sources
			.filter( ( s ) => ctx.selected.includes( s.id ) && s.summary.settings > 0 && '*' !== s.module )
			.reduce< Record< string, ScanSource[] > >( ( acc, s ) => ( { ...acc, [ s.module ]: [ ...( acc[ s.module ] ?? [] ), s ] } ), {} )
	).filter( ( group ) => group.length > 1 );

	return (
		<section className="uxs-wiz-panel">
			<StepHead title={ __( 'Choose what to migrate', 'ux-studio' ) }>
				{ __( 'Tick the plugins whose settings should be copied. Inactive plugins can be migrated too. Open "What moves over" to see exactly what is and is not transferred. Partial matches can only be ticked once you have opened that list.', 'ux-studio' ) }
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
					<SelectCard key={ s.id } source={ s } ctx={ ctx } reviewed={ reviewed.includes( s.id ) } onReview={ review } />
				) ) }
			</ul>
			<Warnings
				warnings={ clashes.map( ( group ) =>
					sprintf(
						/* translators: 1: plugin names, 2: UX Studio module name. */
						__( '%1$s all carry settings for the module "%2$s". The one migrated last overwrites the others, so tick only the plugin you actually use.', 'ux-studio' ),
						group.map( ( s ) => s.label ).join( ', ' ),
						group[ 0 ]?.module_name ?? ''
					)
				) }
			/>
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
						{ plan && <DataCounts rows={ plan.data } /> }
						{ plan && plan.options.map( ( option ) => (
							<p key={ option.key } className="uxs-wiz-muted">
								{ option.label }: <strong>{ ( ctx.options[ id ]?.[ option.key ] ?? option.default ) ? __( 'yes', 'ux-studio' ) : __( 'no', 'ux-studio' ) }</strong>
							</p>
						) ) }
						{ result && result.items.length > 0 && <ChangeTable items={ result.items } /> }
						{ result && 0 === result.items.length && <p className="uxs-wiz-muted">{ plan?.notes[ 0 ] ?? __( 'Nothing to transfer.', 'ux-studio' ) }</p> }
						{ result && result.errors.length > 0 && <Warnings warnings={ result.errors } /> }
						{ plan && <Warnings warnings={ plan.warnings } /> }
						{ plan && plan.menu_table && plan.menu_table.length > 0 && <MenuRoleTable rows={ plan.menu_table } /> }
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
		if ( 'pending' === row.status ) {
			return __( 'Waiting', 'ux-studio' );
		}
		return ( row.total ?? 0 ) > 0
			? sprintf(
					/* translators: 1: objects handled so far, 2: objects in total. */
					__( 'Working… %1$d / %2$d', 'ux-studio' ),
					row.processed ?? 0,
					row.total ?? 0
			  )
			: __( 'Working…', 'ux-studio' );
	}
	if ( 0 === row.written ) {
		return __( 'Nothing to change', 'ux-studio' );
	}
	if ( ( row.staged ?? 0 ) > 0 ) {
		return sprintf(
			/* translators: 1: number of values written, 2: number of values only prepared. */
			__( '%1$d written, %2$d of them only prepared (they take effect after the switch)', 'ux-studio' ),
			row.written,
			row.staged ?? 0
		);
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
										{ c.detail && <span className="uxs-wiz-muted uxs-wiz-checks__detail">{ c.detail }</span> }
										{ c.staged && <span className="uxs-badge is-info">{ __( 'Prepared - takes effect after the switch', 'ux-studio' ) }</span> }
										{ c.rows && c.rows.length > 0 && (
											<div className="uxs-wiz-checks__rows">
												<DetailRows rows={ c.rows } />
											</div>
										) }
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
					{ __( 'Continue to the safe preview', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>

			{ confirm && ctx.run && (
				<UndoMigrationModal
					id="uxs-wiz-undo-title"
					runId={ ctx.run.id }
					title={ __( 'Undo this migration?', 'ux-studio' ) }
					confirmLabel={ ctx.rollbackBusy ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo the migration', 'ux-studio' ) }
					busy={ ctx.rollbackBusy }
					error={ null }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ () => {
						ctx.rollback();
						setConfirm( false );
					} }
				>
					<p>{ __( 'The UX Studio settings go back exactly to how they were before the migration, and everything the migration created (rules, snippets, folders, menu fields) is removed. The old plugins are not touched.', 'ux-studio' ) }</p>
				</UndoMigrationModal>
			) }
		</section>
	);
}

/* ── 9 Done ── */

function DoneStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const rows = Object.values( ctx.run?.per_source ?? {} );
	const written = rows.reduce( ( sum, r ) => sum + r.written, 0 );
	const staged = rows.reduce( ( sum, r ) => sum + ( r.staged ?? 0 ), 0 );

	if ( ctx.rolledBack ) {
		return (
			<section className="uxs-wiz-panel uxs-wiz-finish">
				<span className="uxs-wiz-finish__badge is-undo">
					<Undo2 size={ 30 } aria-hidden />
				</span>
				<h2>{ __( 'The migration was undone', 'ux-studio' ) }</h2>
				<p>{ __( 'UX Studio is back to how it was before. The old plugins were never changed.', 'ux-studio' ) }</p>
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
	if ( ctx.sw.reverted ) {
		return <RevertedDone ctx={ ctx } />;
	}
	if ( ctx.sw.result ) {
		return <SwitchedDone ctx={ ctx } />;
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
					__( '%1$d plugin(s) processed, %2$d item(s) written to UX Studio.', 'ux-studio' ),
					rows.length,
					written
				) }
			</p>
			<div className="uxs-wiz-next">
				<h3>{ __( 'What happens next', 'ux-studio' ) }</h3>
				<ul>
					<li>{ __( 'The old plugins still run and nothing was switched on - the site behaves exactly as before.', 'ux-studio' ) }</li>
					{ staged > 0 && (
						<li>
							{ sprintf(
								/* translators: %d: number of values only prepared. */
								_n(
									'%d value was only prepared, because its module is already on. It takes effect at the switch, after the safe preview.',
									'%d values were only prepared, because their modules are already on. They take effect at the switch, after the safe preview.',
									staged,
									'ux-studio'
								),
								staged
							) }
						</li>
					) }
					<li>{ __( 'To switch, open the safe preview (the site without the old plugins, only for you), then the switch. You can do that later from the run history.', 'ux-studio' ) }</li>
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
				<button type="button" className="button" onClick={ () => navigate( '' ) }>
					{ __( 'Back to modules', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => navigate( 'wizard', { mode: 'new' } ) }>
					<Sparkles size={ 16 } aria-hidden /> { __( 'Continue with the recommended modules', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" onClick={ () => ctx.goTo( 'preview' ) }>
					{ __( 'Continue to the safe preview', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/**
 * Ordered steps (a function, so the titles are translated when the wizard opens,
 * not when the bundle loads).
 */
export function getSteps(): WizardStep[] {
	return [
		{ id: 'welcome', title: __( 'Welcome', 'ux-studio' ), render: ( ctx ) => <WelcomeStep ctx={ ctx } /> },
		{ id: 'scan', title: __( 'Scan', 'ux-studio' ), render: ( ctx ) => <ScanStep ctx={ ctx } /> },
		{ id: 'select', title: __( 'Selection', 'ux-studio' ), render: ( ctx ) => <SelectStep ctx={ ctx } /> },
		{ id: 'check', title: __( 'Check', 'ux-studio' ), render: ( ctx ) => <CheckStep ctx={ ctx } /> },
		{ id: 'migrate', title: __( 'Migration', 'ux-studio' ), render: ( ctx ) => <MigrateStep ctx={ ctx } /> },
		{ id: 'verify', title: __( 'Verification', 'ux-studio' ), render: ( ctx ) => <VerifyStep ctx={ ctx } /> },
		{ id: 'preview', title: __( 'Preview', 'ux-studio' ), render: ( ctx ) => <PreviewStep ctx={ ctx } /> },
		{ id: 'switch', title: __( 'Switch', 'ux-studio' ), render: ( ctx ) => <SwitchStep ctx={ ctx } /> },
		{ id: 'done', title: __( 'Done', 'ux-studio' ), render: ( ctx ) => <DoneStep ctx={ ctx } /> },
	];
}
