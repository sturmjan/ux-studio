/**
 * Wizard steps of phase F4: the safe preview, the switch and the finish with the live checks.
 * PLAN.md 26.10.
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowLeftRight, ArrowRight, Check, CircleCheck, Copy, ExternalLink, Eye, History as HistoryIcon, KeyRound, PlugZap, RefreshCw, ShieldAlert, Sparkles, Undo2 } from 'lucide-react';
import { formatDate } from '../date';
import { navigate } from '../route';
import type { WizardCtx } from './context';
import { ConfirmModal, DrawnCheck, ErrorLine, StepHead, TestRows, Warnings, reducedMotion, summarize, testsSentence, useNow } from './parts';
import type { SmokeCounts, WizardTarget } from './types';

/** Copy text to the clipboard; resolves with whether it worked. */
export async function copyText( text: string ): Promise< boolean > {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch {
		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		const ok = document.execCommand( 'copy' );
		document.body.removeChild( area );
		return ok;
	}
}

/** Shown when the step is opened without a migrated run to work on. */
function NoRun( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <Eye size={ 26 } aria-hidden /> } title={ __( 'No migration to work on', 'ux-studio' ) }>
				{ __( 'The preview and the switch work on a finished migration. Run one first, or continue a run from the run history below.', 'ux-studio' ) }
			</StepHead>
			<div className="uxs-wiz-actions">
				<button type="button" className="button button-primary" onClick={ () => ctx.goTo( 'welcome' ) }>
					{ __( 'Start a migration', 'ux-studio' ) }
				</button>
			</div>
		</section>
	);
}

/** "Classic Editor, Disable Comments" for the plugin basenames of a preview. */
function pluginNames( basenames: string[], target: WizardTarget ): string {
	return basenames
		.map( ( basename ) => {
			const slug = basename.split( '/' )[ 0 ];
			return target.sources.find( ( s ) => s.id === slug )?.label ?? slug;
		} )
		.join( ', ' );
}

function labelsOf( target: WizardTarget ): Record< string, string > {
	return Object.fromEntries( target.sources.map( ( s ) => [ s.id, s.label ] ) );
}

/* ── 7 Safe preview ── */

export function PreviewStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { target, preview } = ctx;
	const now = useNow();
	const load = preview.load;
	useEffect( () => {
		void load();
	}, [ load ] );

	if ( ! target ) {
		return <NoRun ctx={ ctx } />;
	}
	const entry = preview.payload?.entry ?? null;
	const available = preview.payload?.available;
	const tests = preview.tests;
	const finished = tests.plan.length > 0 && tests.done;
	const counts = summarize( Object.values( tests.results ) );
	const over = !! entry && ( tests.expired || entry.expires * 1000 <= now );
	const points = [
		{ icon: <Eye size={ 18 } aria-hidden />, text: __( 'Only your browser sees the site without the old plugins, for 30 minutes. Visitors keep getting the old plugins the whole time.', 'ux-studio' ) },
		{ icon: <ShieldAlert size={ 18 } aria-hidden />, text: __( 'Nothing is saved while you look: no .htaccess, no schedule, no module data. The preview ends by itself after 30 minutes, or when you end it.', 'ux-studio' ) },
		{ icon: <CircleCheck size={ 18 } aria-hidden />, text: __( 'The tests compare the site now with the site in the preview and stop the switch when something breaks.', 'ux-studio' ) },
	];

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <Eye size={ 26 } aria-hidden /> } title={ __( 'Safe preview', 'ux-studio' ) }>
				{ __( 'See the site the way it will work after the switch, before anything is switched.', 'ux-studio' ) }
			</StepHead>

			{ ! entry && (
				<ul className="uxs-wiz-points">
					{ points.map( ( p ) => (
						<li key={ p.text }>
							{ p.icon }
							<span>{ p.text }</span>
						</li>
					) ) }
				</ul>
			) }

			<ErrorLine text={ preview.error ?? tests.error } />

			{ available && ! available.ok && (
				<div className="uxs-wiz-callout is-warn" role="alert">
					<ShieldAlert size={ 18 } aria-hidden />
					<div>
						<strong>{ __( 'The preview is not available on this server', 'ux-studio' ) }</strong>
						<p>{ available.reason }</p>
						<p>{ __( 'You can still switch without a preview, but then nothing has been tested before the old plugins are deactivated. Everything can be undone for 30 days.', 'ux-studio' ) }</p>
					</div>
				</div>
			) }

			{ entry && (
				<dl className="uxs-wiz-facts">
					<div>
						<dt>{ __( 'The preview ends', 'ux-studio' ) }</dt>
						<dd>{ over ? __( 'It has ended', 'ux-studio' ) : formatDate( entry.expires_at ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Switched off in the preview', 'ux-studio' ) }</dt>
						<dd>{ entry.plugins.length > 0 ? pluginNames( entry.plugins, target ) : __( 'No active plugin', 'ux-studio' ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Switched on in the preview', 'ux-studio' ) }</dt>
						<dd>{ entry.modules.map( ( m ) => m.name ).join( ', ' ) }</dd>
					</div>
				</dl>
			) }

			{ over && (
				<p className="uxs-wiz-banner is-bad" role="status">
					<ShieldAlert size={ 16 } aria-hidden />
					{ __( 'The preview has ended. Start it again to test or look at the site.', 'ux-studio' ) }
				</p>
			) }

			{ entry && tests.plan.length > 0 && <TestRows run={ tests } labels={ labelsOf( target ) } /> }

			{ finished && (
				<p className={ `uxs-wiz-banner ${ 0 === counts.fail ? 'is-ok' : 'is-bad' }` } role="status">
					{ 0 === counts.fail ? <CircleCheck size={ 16 } aria-hidden /> : <ShieldAlert size={ 16 } aria-hidden /> }
					{ testsSentence( counts, tests.plan.length ) }
					{ counts.fail > 0 && ` ${ __( 'Fix the cause, or switch anyway only if you accept the risk.', 'ux-studio' ) }` }
				</p>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className="button" onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				{ ! entry || over ? (
					<button type="button" className="button button-primary" disabled={ preview.busy || preview.loading || ! available?.ok } onClick={ () => void preview.start() }>
						<Eye size={ 16 } aria-hidden /> { preview.busy ? __( 'Starting…', 'ux-studio' ) : __( 'Start the safe preview', 'ux-studio' ) }
					</button>
				) : (
					<>
						<a className="button" href={ entry.enter_url } target="_blank" rel="noopener noreferrer">
							<ExternalLink size={ 16 } aria-hidden /> { __( 'Open the site in the preview', 'ux-studio' ) }
						</a>
						<button type="button" className="button" disabled={ tests.running || preview.busy } onClick={ () => void preview.rerun() }>
							<RefreshCw size={ 16 } aria-hidden /> { __( 'Run the tests again', 'ux-studio' ) }
						</button>
						<button type="button" className="button" disabled={ tests.running || preview.busy } onClick={ () => void preview.end() }>
							{ __( 'End the preview', 'ux-studio' ) }
						</button>
					</>
				) }
				<button type="button" className="button button-primary" disabled={ tests.running || preview.busy } onClick={ ctx.next }>
					{ available && ! available.ok ? __( 'Continue without the preview', 'ux-studio' ) : __( 'Continue to the switch', 'ux-studio' ) } <ArrowRight size={ 16 } aria-hidden />
				</button>
			</div>
		</section>
	);
}

/* ── 8 Switch ── */

/** The new login address, big, with a copy button. */
export function LoginAddress( { url }: { url: string } ): JSX.Element {
	const [ copied, setCopied ] = useState( false );
	return (
		<div className="uxs-wiz-address">
			<code>{ url }</code>
			<button
				type="button"
				className="button"
				onClick={ () => {
					void copyText( url ).then( ( ok ) => {
						setCopied( ok );
						window.setTimeout( () => setCopied( false ), 2400 );
					} );
				} }
			>
				{ copied ? <Check size={ 16 } aria-hidden /> : <Copy size={ 16 } aria-hidden /> } { copied ? __( 'Copied', 'ux-studio' ) : __( 'Copy the address', 'ux-studio' ) }
			</button>
		</div>
	);
}

export function SwitchStep( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { target, sw } = ctx;
	const [ confirm, setConfirm ] = useState( false );
	const load = sw.loadPlan;
	useEffect( () => {
		void load();
	}, [ load ] );

	const plan = sw.plan;
	const flags = sw.flags;
	const blockers = useMemo( (): string[] => {
		if ( ! plan ) {
			return [];
		}
		const list: string[] = [];
		const pv = plan.preview;
		if ( plan.errors > 0 ) {
			list.push( __( 'The migration had errors. Undo it and run it again before switching.', 'ux-studio' ) );
		}
		if ( pv.available.ok ) {
			if ( ! pv.tested ) {
				list.push( __( 'Run the safe preview and its tests first.', 'ux-studio' ) );
			} else if ( ! pv.complete ) {
				list.push( __( 'The tests of the safe preview are incomplete or older than a day. Run them again.', 'ux-studio' ) );
			} else if ( pv.counts.fail > 0 && ! flags.override_failures ) {
				list.push( __( 'Some preview tests failed. Fix the cause, or confirm below that you want to switch anyway.', 'ux-studio' ) );
			}
		} else if ( ! flags.no_preview ) {
			list.push( __( 'The safe preview is not available here. Confirm below that you want to switch without it.', 'ux-studio' ) );
		}
		if ( plan.login.changes && ! flags.ack_login ) {
			list.push( __( 'Save the new login address and tick the box to confirm it.', 'ux-studio' ) );
		}
		return list;
	}, [ flags, plan ] );

	const doSwitch = useCallback( async (): Promise< void > => {
		if ( await sw.doSwitch() ) {
			setConfirm( false );
			ctx.goTo( 'done' );
		}
	}, [ ctx, sw ] );

	if ( ! target ) {
		return <NoRun ctx={ ctx } />;
	}

	const staged = plan ? plan.staging.settings + plan.staging.secrets : 0;

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <ArrowLeftRight size={ 26 } aria-hidden /> } title={ __( 'Switch to UX Studio', 'ux-studio' ) }>
				{ __( 'UX Studio modules take over and the old plugins are deactivated. Nothing is deleted, and you can undo it all for 30 days.', 'ux-studio' ) }
			</StepHead>
			<ErrorLine text={ sw.error } />
			{ sw.loading && ! plan && <p className="uxs-wiz-muted">{ __( 'Loading…', 'ux-studio' ) }</p> }

			{ plan && (
				<>
					<div className="uxs-wiz-twocols">
						<div className="uxs-wiz-box">
							<h3>{ __( 'These plugins will be deactivated', 'ux-studio' ) }</h3>
							{ plan.plugins.length > 0 ? (
								<ul className="uxs-wiz-plain">
									{ plan.plugins.map( ( p ) => (
										<li key={ p.basename }>
											<PlugZap size={ 15 } aria-hidden /> <span>{ p.name }</span>
										</li>
									) ) }
								</ul>
							) : (
								<p className="uxs-wiz-muted">{ __( 'None of the migrated plugins is active, so nothing is deactivated.', 'ux-studio' ) }</p>
							) }
						</div>
						<div className="uxs-wiz-box">
							<h3>{ __( 'These modules will be switched on', 'ux-studio' ) }</h3>
							{ plan.modules.length > 0 ? (
								<ul className="uxs-wiz-plain">
									{ plan.modules.map( ( m ) => (
										<li key={ m.id }>
											<DrawnCheck size={ 15 } /> <span>{ m.name }</span>
											{ m.enabled && <span className="uxs-wiz-muted">{ __( 'already on', 'ux-studio' ) }</span> }
										</li>
									) ) }
								</ul>
							) : (
								<p className="uxs-wiz-muted">{ __( 'No module needs to be switched on.', 'ux-studio' ) }</p>
							) }
						</div>
					</div>

					<div className="uxs-wiz-next is-wide">
						<h3>{ __( 'What happens', 'ux-studio' ) }</h3>
						<ul>
							{ staged + plan.staging.activations > 0 && (
								<li>
									{ sprintf(
										/* translators: 1: number of prepared settings, 2: number of prepared objects (rules, snippets ...). */
										__( 'The prepared values go live: %1$d settings and %2$d objects (rules, snippets, menu fields) are switched on.', 'ux-studio' ),
										staged,
										plan.staging.activations
									) }
								</li>
							) }
							<li>{ __( 'The old plugins are deactivated the normal WordPress way, so their own deactivation steps run. Their files and data stay where they are.', 'ux-studio' ) }</li>
							<li>{ __( 'Right after that the home page is checked from outside. If it fails, everything is undone automatically.', 'ux-studio' ) }</li>
							<li>
								{ sprintf(
									/* translators: %d: number of days. */
									_n( 'You can undo the whole switch with one click for %d day.', 'You can undo the whole switch with one click for %d days.', plan.revert_days, 'ux-studio' ),
									plan.revert_days
								) }
							</li>
						</ul>
					</div>

					{ plan.login.changes && (
						<div className="uxs-wiz-callout is-warn uxs-wiz-login" role="group" aria-labelledby="uxs-wiz-login-title">
							<KeyRound size={ 20 } aria-hidden />
							<div>
								<strong id="uxs-wiz-login-title">{ __( 'The login address changes', 'ux-studio' ) }</strong>
								<p>{ __( 'After the switch you log in at this address. The old one stops working for everybody. Save it now.', 'ux-studio' ) }</p>
								<LoginAddress url={ plan.login.url } />
								<label className="uxs-wiz-checkrow">
									<input type="checkbox" checked={ flags.ack_login } onChange={ ( e ) => sw.setFlag( 'ack_login', e.target.checked ) } />
									<span>{ __( 'I have saved the new login address', 'ux-studio' ) }</span>
								</label>
								<p className="uxs-wiz-muted">
									{ sprintf(
										/* translators: %s: PHP constant name. */
										__( 'Locked out anyway? Add define( \'%s\', true ); to wp-config.php and the normal wp-login.php works again.', 'ux-studio' ),
										plan.kill_switch
									) }
								</p>
							</div>
						</div>
					) }

					{ plan.preview.available.ok && plan.preview.tested && plan.preview.counts.fail > 0 && (
						<div className="uxs-wiz-callout is-warn" role="group">
							<ShieldAlert size={ 20 } aria-hidden />
							<div>
								<strong>{ testsSentence( plan.preview.counts, plan.preview.total ) }</strong>
								<label className="uxs-wiz-checkrow">
									<input type="checkbox" checked={ flags.override_failures } onChange={ ( e ) => sw.setFlag( 'override_failures', e.target.checked ) } />
									<span>{ __( 'Switch anyway, I accept that these tests failed', 'ux-studio' ) }</span>
								</label>
								<button type="button" className="button-link" onClick={ () => ctx.goTo( 'preview' ) }>
									{ __( 'Back to the preview tests', 'ux-studio' ) }
								</button>
							</div>
						</div>
					) }

					{ ! plan.preview.available.ok && (
						<div className="uxs-wiz-callout is-warn" role="group">
							<ShieldAlert size={ 20 } aria-hidden />
							<div>
								<strong>{ __( 'No safe preview on this server', 'ux-studio' ) }</strong>
								<p>{ plan.preview.available.reason }</p>
								<label className="uxs-wiz-checkrow">
									<input type="checkbox" checked={ flags.no_preview } onChange={ ( e ) => sw.setFlag( 'no_preview', e.target.checked ) } />
									<span>{ __( 'Switch without a preview, I accept the risk', 'ux-studio' ) }</span>
								</label>
							</div>
						</div>
					) }

					{ blockers.length > 0 && <Warnings warnings={ blockers } /> }
					{ plan.preview.available.ok && ! plan.preview.complete && (
						<p>
							<button type="button" className="button-link" onClick={ () => ctx.goTo( 'preview' ) }>
								{ __( 'Go to the safe preview', 'ux-studio' ) }
							</button>
						</p>
					) }
				</>
			) }
			<ErrorLine text={ sw.switchError } />

			<div className="uxs-wiz-actions">
				<button type="button" className="button" disabled={ sw.switching } onClick={ ctx.back }>
					{ __( 'Back', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ ! plan || plan.switched || blockers.length > 0 || sw.switching } onClick={ () => setConfirm( true ) }>
					<ArrowLeftRight size={ 16 } aria-hidden /> { __( 'Switch now', 'ux-studio' ) }
				</button>
			</div>

			{ confirm && plan && (
				<ConfirmModal
					id="uxs-wiz-switch-title"
					title={ __( 'Switch to UX Studio now?', 'ux-studio' ) }
					confirmLabel={ sw.switching ? __( 'Switching…', 'ux-studio' ) : __( 'Switch now', 'ux-studio' ) }
					busy={ sw.switching }
					error={ sw.switchError }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ () => void doSwitch() }
				>
					<p>
						{ sprintf(
							/* translators: 1: number of plugins, 2: number of modules. */
							__( '%1$d plugin(s) will be deactivated and %2$d module(s) switched on. The site is checked right after, and you can undo the switch from the run history.', 'ux-studio' ),
							plan.plugins.length,
							plan.modules.filter( ( m ) => ! m.enabled ).length
						) }
					</p>
					{ plan.login.changes && (
						<p>
							<strong>{ __( 'New login address:', 'ux-studio' ) }</strong> <code>{ plan.login.url }</code>
						</p>
					) }
				</ConfirmModal>
			) }
		</section>
	);
}

/* ── 9 Done after the switch ── */

function liveCounts( ctx: WizardCtx ): SmokeCounts {
	return summarize( Object.values( ctx.sw.live.results ) );
}

/** The finish of a switched run: live checks, undo. */
export function SwitchedDone( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	const { sw, target } = ctx;
	const [ confirm, setConfirm ] = useState( false );
	const live = sw.live;
	const counts = liveCounts( ctx );
	const finished = live.plan.length > 0 && live.done;
	const bad = counts.fail > 0;
	const result = sw.result;
	const labels = target ? labelsOf( target ) : {};

	return (
		<section className="uxs-wiz-panel">
			<StepHead icon={ <span className="uxs-wiz-finish__mini"><DrawnCheck size={ 26 } /></span> } title={ __( 'UX Studio has taken over', 'ux-studio' ) }>
				{ result &&
					sprintf(
						/* translators: 1: number of deactivated plugins, 2: number of modules switched on. */
						__( '%1$d plugin(s) deactivated, %2$d module(s) switched on. The site is being tested now, from outside, without the preview.', 'ux-studio' ),
						result.deactivated.length,
						result.enabled.length
					) }
			</StepHead>

			{ result && result.errors.length > 0 && <Warnings warnings={ result.errors } /> }

			{ sw.plan?.login.changes && (
				<div className="uxs-wiz-callout is-warn uxs-wiz-login" role="group">
					<KeyRound size={ 20 } aria-hidden />
					<div>
						<strong>{ __( 'The login address is now', 'ux-studio' ) }</strong>
						<LoginAddress url={ sw.plan.login.url } />
					</div>
				</div>
			) }

			<TestRows run={ live } labels={ labels } />
			<ErrorLine text={ live.error } />

			{ finished && (
				<p className={ `uxs-wiz-banner ${ bad ? 'is-bad' : 'is-ok' }` } role="status">
					{ bad ? <ShieldAlert size={ 16 } aria-hidden /> : <CircleCheck size={ 16 } aria-hidden /> }
					{ testsSentence( counts, live.plan.length ) }
					{ bad && ` ${ __( 'You can undo the switch below.', 'ux-studio' ) }` }
				</p>
			) }

			{ result && (
				<p className="uxs-wiz-muted">
					{ sprintf(
						/* translators: %s: date until which the switch can be undone. */
						__( 'You can undo the switch until %s.', 'ux-studio' ),
						formatDate( result.revert_until, { dateOnly: true } )
					) }
				</p>
			) }

			<div className="uxs-wiz-actions">
				<button type="button" className={ bad ? 'button button-primary' : 'button' } disabled={ sw.reverting } onClick={ () => setConfirm( true ) }>
					<Undo2 size={ 16 } aria-hidden /> { __( 'Undo the switch', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ live.running } onClick={ () => void sw.rerunLive() }>
					<RefreshCw size={ 16 } aria-hidden /> { __( 'Run the tests again', 'ux-studio' ) }
				</button>
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
				<button type="button" className="button" onClick={ () => navigate( 'wizard', { mode: 'new' } ) }>
					<Sparkles size={ 16 } aria-hidden /> { __( 'Continue with the recommended modules', 'ux-studio' ) }
				</button>
				<button type="button" className={ bad ? 'button' : 'button button-primary' } onClick={ () => navigate( '' ) }>
					{ __( 'Back to modules', 'ux-studio' ) }
				</button>
			</div>

			{ confirm && (
				<ConfirmModal
					id="uxs-wiz-revert-title"
					title={ __( 'Undo the switch?', 'ux-studio' ) }
					confirmLabel={ sw.reverting ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo the switch', 'ux-studio' ) }
					busy={ sw.reverting }
					error={ sw.revertError }
					onCancel={ () => setConfirm( false ) }
					onConfirm={ () => {
						void sw.revert().then( ( ok ) => {
							if ( ok ) {
								setConfirm( false );
							}
						} );
					} }
				>
					<p>{ __( 'The old plugins are activated again, the modules the switch turned on go off, and the settings and data return to how they were before the switch. Changes made in these modules since then are lost. The migrated values stay prepared.', 'ux-studio' ) }</p>
				</ConfirmModal>
			) }
		</section>
	);
}

/** The finish after the switch was undone from the wizard. */
export function RevertedDone( { ctx }: { ctx: WizardCtx } ): JSX.Element {
	return (
		<section className="uxs-wiz-panel uxs-wiz-finish">
			<span className="uxs-wiz-finish__badge is-undo">
				<Undo2 size={ 30 } aria-hidden />
			</span>
			<h2>{ __( 'The switch was undone', 'ux-studio' ) }</h2>
			<p>{ __( 'The old plugins are active again and the modules are as they were before. The migrated values are prepared again: you can preview and switch once more, or undo the migration from the run history.', 'ux-studio' ) }</p>
			<div className="uxs-wiz-actions is-center">
				<button type="button" className="button" onClick={ () => ctx.goTo( 'preview' ) }>
					<Eye size={ 16 } aria-hidden /> { __( 'Back to the preview', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" onClick={ () => navigate( '' ) }>
					{ __( 'Back to modules', 'ux-studio' ) }
				</button>
			</div>
		</section>
	);
}
