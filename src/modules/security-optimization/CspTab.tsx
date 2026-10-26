import { useEffect, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { AlertTriangle, Check, CheckCheck, LoaderCircle, RefreshCw, ShieldAlert, ShieldCheck, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { pushToast } from '../../app/notify';
import { formatDate } from '../../app/date';

type Level = 'high' | 'medium' | 'unknown' | 'known' | 'self';
type Mode = 'off' | 'audit' | 'report-only' | 'enforce';

interface CspViolation {
	id: number;
	directive: string;
	blocked_host: string;
	blocked_uri: string;
	document_uri: string;
	source_file: string;
	line_number: number;
	script_sample: string;
	pages: string[];
	hit_count: number;
	reporters: number;
	status: 'open' | 'resolved';
	risk_level: Level;
	risk_score: number;
	risk_reasons: string[];
	service: string;
	domain_created: string | null;
	source: string;
	allowed: boolean;
	first_seen: string;
	last_seen: string;
}

interface Probe {
	checked_at?: number;
	ok?: boolean;
	reason?: 'ok' | 'missing' | 'rewritten' | 'request_failed' | 'http_error';
	status?: number;
	seen?: string;
	cached_ok?: boolean | null;
}

interface GateCheck {
	id: 'header_delivered' | 'test_days' | 'no_test_violations' | 'no_high_risk';
	ok: boolean;
	value: number;
}

interface CspStatus {
	mode: Mode;
	since: number;
	paused: boolean;
	kill_switch: boolean;
	probe: Probe;
	stats: { total: number; open: number; high: number; medium: number; unknown: number; new_7d: number; candidate_open_7d: number };
	gate: { ready: boolean; checks: GateCheck[]; test_days: number };
	inline: { strict: boolean; total: number; capped: boolean; max: number; entries: { label: string; hash: string }[] };
}

const VIOLATIONS_KEY = [ 'security-optimization', 'csp-violations' ];
const STATUS_KEY = [ 'security-optimization', 'csp-status' ];

const DIRECTIVES: Record< string, string > = {
	'script-src': __( 'Script', 'ux-studio' ),
	'style-src': __( 'Stylesheet', 'ux-studio' ),
	'img-src': __( 'Image', 'ux-studio' ),
	'font-src': __( 'Font', 'ux-studio' ),
	'connect-src': __( 'Data connection', 'ux-studio' ),
	'frame-src': __( 'Embedded frame', 'ux-studio' ),
	'media-src': __( 'Audio/video', 'ux-studio' ),
	'worker-src': __( 'Worker', 'ux-studio' ),
	'manifest-src': __( 'Manifest', 'ux-studio' ),
	'form-action': __( 'Form submission', 'ux-studio' ),
	'object-src': __( 'Plugin object', 'ux-studio' ),
};

const LEVELS: Record< Level, { label: string; cls: string } > = {
	high: { label: __( 'High risk', 'ux-studio' ), cls: 'is-danger' },
	medium: { label: __( 'Suspicious', 'ux-studio' ), cls: 'is-warning' },
	unknown: { label: __( 'Unknown', 'ux-studio' ), cls: 'is-info' },
	known: { label: __( 'Known service', 'ux-studio' ), cls: 'is-success' },
	self: { label: __( 'Own domain', 'ux-studio' ), cls: '' },
};

/** Human label of a risk reason code ("typo:google.com" carries a domain). */
function reasonLabel( code: string ): string {
	const colon = code.indexOf( ':' );
	const kind = colon < 0 ? code : code.slice( 0, colon );
	const detail = colon < 0 ? '' : code.slice( colon + 1 );
	switch ( kind ) {
		case 'insecure_scheme':
			return __( 'unencrypted http', 'ux-studio' );
		case 'ip_address':
			return __( 'IP address instead of a domain', 'ux-studio' );
		case 'punycode':
			return __( 'internationalised (look-alike) characters', 'ux-studio' );
		case 'risky_tld':
			return __( 'domain ending often used for abuse', 'ux-studio' );
		case 'odd_host':
			return __( 'tunnel / paste / free hosting service', 'ux-studio' );
		case 'typo':
			/* translators: %s: imitated domain, e.g. google-analytics.com */
			return sprintf( __( 'imitates %s', 'ux-studio' ), detail );
		case 'brand':
			/* translators: %s: known domain with the same name, e.g. stripe.com */
			return sprintf( __( 'same name as %s, other ending', 'ux-studio' ), detail );
		case 'random_name':
			return __( 'random-looking name', 'ux-studio' );
		case 'new_domain':
			return __( 'domain registered less than 30 days ago', 'ux-studio' );
		case 'young_domain':
			return __( 'domain registered less than 6 months ago', 'ux-studio' );
		case 'obfuscated_inline':
			return __( 'obfuscated inline code', 'ux-studio' );
		default:
			return kind;
	}
}

function probeText( probe: Probe ): string {
	switch ( probe.reason ) {
		case 'ok':
			return __( 'The header reaches the browser.', 'ux-studio' );
		case 'rewritten':
			return __( 'A CSP header arrives, but not ours - the server or another plugin replaces it (known on Hostinger with "Force HTTPS"). Browsers will not send reports and Enforce would do nothing.', 'ux-studio' );
		case 'missing':
			return __( 'The response has no CSP header. A page cache or the server drops it. Purge the cache and test again; if it stays, the header has to be allowed in the cache/server settings.', 'ux-studio' );
		case 'http_error':
			/* translators: %d: HTTP status code */
			return sprintf( __( 'The home page answered with HTTP %d.', 'ux-studio' ), probe.status ?? 0 );
		case 'request_failed':
			return __( 'The website could not load its own home page (loopback request failed).', 'ux-studio' );
		default:
			return __( 'Not tested yet.', 'ux-studio' );
	}
}

function checkText( check: GateCheck, testDays: number ): string {
	switch ( check.id ) {
		case 'header_delivered':
			return __( 'The self-test confirmed the header reaches the browser (in the last 7 days)', 'ux-studio' );
		case 'test_days':
			return sprintf(
				/* translators: 1: days required, 2: days the policy test has been running */
				__( 'Policy test has been running at least %1$d days (now %2$d)', 'ux-studio' ),
				testDays,
				check.value
			);
		case 'no_test_violations':
			return sprintf(
				/* translators: %d: number of sources */
				__( 'No unresolved source the test policy would block in the last 7 days (now %d)', 'ux-studio' ),
				check.value
			);
		case 'no_high_risk':
			return sprintf(
				/* translators: %d: number of sources */
				__( 'No unresolved high-risk source (now %d)', 'ux-studio' ),
				check.value
			);
	}
}

function daysSince( ts: number ): number {
	return ts > 0 ? Math.floor( ( Date.now() / 1000 - ts ) / 86400 ) : 0;
}

/** First seen within the last 7 days (rows are stored in UTC). */
function isNew( firstSeen: string ): boolean {
	const t = Date.parse( firstSeen.replace( ' ', 'T' ) + 'Z' );
	return ! Number.isNaN( t ) && Date.now() - t < 7 * 86400 * 1000;
}

/* ------------------------------------------------------------------ */

function ModeDialog( {
	from,
	to,
	status,
	busy,
	onConfirm,
	onCancel,
}: {
	from: Mode;
	to: Mode;
	status: CspStatus | undefined;
	busy: boolean;
	onConfirm: ( override: boolean ) => void;
	onCancel: () => void;
} ): JSX.Element {
	const [ understood, setUnderstood ] = useState( false );
	const [ override, setOverride ] = useState( false );
	const gateReady = !! status?.gate.ready;
	const enforce = to === 'enforce';
	const ready = understood && ( ! enforce || gateReady || override );

	useEffect( () => {
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' && ! busy ) {
				onCancel();
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ busy, onCancel ] );

	return (
		<div className="uxs-overlay">
			<div className="uxs-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-csp-dialog-title">
				<h2 id="uxs-csp-dialog-title">
					{ enforce ? __( 'Switch CSP to Enforce?', 'ux-studio' ) : __( 'Before you turn on Content-Security-Policy', 'ux-studio' ) }
				</h2>
				<p>
					<strong>{ __( 'CSP can directly affect how the website works.', 'ux-studio' ) }</strong>{ ' ' }
					{ __( 'When a source the website needs is not allowed, the browser blocks it and that part stops working - typically payments, chat, maps, analytics, cookie bars, booking or review widgets.', 'ux-studio' ) }
				</p>
				{ to === 'audit' && (
					<p>
						{ __( 'Inventory never blocks anything. It sends the policy as Content-Security-Policy-Report-Only and every visitor browser reports each external source the page loads. You will see them in the list below and in the dashboard widget, rated by risk. Let it run for a few days so all page types get visited.', 'ux-studio' ) }
					</p>
				) }
				{ to === 'report-only' && (
					<p>
						{ __( 'Policy test never blocks anything either. Browsers report only what Enforce would block. Approve every source you recognise first ("Approve all safe" helps); what stays in the list after a week is what would break.', 'ux-studio' ) }
					</p>
				) }
				{ enforce && (
					<>
						<p>{ __( 'From now on the browser really blocks every source the policy does not allow. If something stops working, switch back to Policy test - or define UXSTUDIO_CSP_DISABLE in wp-config.php to turn CSP off without logging in.', 'ux-studio' ) }</p>
						<ul className="uxs-csp-checks">
							{ ( status?.gate.checks ?? [] ).map( ( c ) => (
								<li key={ c.id } className={ c.ok ? 'is-ok' : 'is-fail' }>
									{ c.ok ? <Check size={ 14 } /> : <AlertTriangle size={ 14 } /> } { checkText( c, status?.gate.test_days ?? 7 ) }
								</li>
							) ) }
						</ul>
						{ ! gateReady && (
							<label className="uxs-csp-confirm is-danger">
								<input type="checkbox" checked={ override } onChange={ ( e ) => setOverride( e.target.checked ) } />
								<span>{ __( 'The checks above are not all passed. I understand the risk and want to enforce anyway.', 'ux-studio' ) }</span>
							</label>
						) }
					</>
				) }
				{ from === 'enforce' && to !== 'enforce' && (
					<p>{ __( 'Leaving Enforce: nothing will be blocked any more.', 'ux-studio' ) }</p>
				) }
				<label className="uxs-csp-confirm">
					<input type="checkbox" checked={ understood } onChange={ ( e ) => setUnderstood( e.target.checked ) } />
					<span>{ __( 'I understand that CSP can stop parts of the website or connected services from working.', 'ux-studio' ) }</span>
				</label>
				<div className="uxs-modal__actions">
					<button type="button" className="button" disabled={ busy } onClick={ onCancel }>
						{ __( 'Cancel', 'ux-studio' ) }
					</button>
					<button type="button" className="button button-primary" disabled={ ! ready || busy } onClick={ () => onConfirm( enforce && ! gateReady && override ) }>
						{ busy ? __( 'Saving…', 'ux-studio' ) : __( 'Confirm and save', 'ux-studio' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

/* ------------------------------------------------------------------ */

function StatusPanel( { status, onProbe, probing }: { status: CspStatus; onProbe: () => void; probing: boolean } ): JSX.Element {
	const days = daysSince( status.since );
	const probe = status.probe;
	const modeLabel: Record< Mode, string > = {
		off: __( 'Off', 'ux-studio' ),
		audit: __( 'Inventory (Report only)', 'ux-studio' ),
		'report-only': __( 'Policy test (Report only)', 'ux-studio' ),
		enforce: __( 'Enforce (blocking)', 'ux-studio' ),
	};
	const next: Record< Mode, string > = {
		off: __( 'Start with Inventory: it blocks nothing and shows what the website loads.', 'ux-studio' ),
		audit: __( 'Next step: once the main pages have had visitors (a few days), review the list, use "Approve all safe", decide on the rest and switch to Policy test.', 'ux-studio' ),
		'report-only': __( 'Next step: what remains in the list would be blocked by Enforce. Approve what belongs to the website; Enforce unlocks after the checks below pass.', 'ux-studio' ),
		enforce: __( 'Disallowed sources are blocked. New entries in the list are sources that were blocked - check them, they may be an attack or a newly added service.', 'ux-studio' ),
	};

	return (
		<div className="uxs-csp-status">
			<div className="uxs-csp-status__row">
				<span className={ `uxs-badge ${ status.mode === 'enforce' ? 'is-danger' : status.mode === 'off' ? '' : 'is-warning' }` }>
					{ modeLabel[ status.mode ] }
				</span>
				{ status.mode !== 'off' && status.since > 0 && (
					<span className="uxs-csp-status__soft">
						{ sprintf(
							/* translators: %d: number of days */
							__( 'running %d days', 'ux-studio' ),
							days
						) }
					</span>
				) }
				<span className="uxs-csp-status__soft">
					{ sprintf(
						/* translators: 1: sources total, 2: high risk, 3: suspicious, 4: new in 7 days */
						__( '%1$d sources - %2$d high risk, %3$d suspicious, %4$d new this week', 'ux-studio' ),
						status.stats.total,
						status.stats.high,
						status.stats.medium,
						status.stats.new_7d
					) }
				</span>
			</div>
			<p className="uxs-csp-status__soft">{ next[ status.mode ] }</p>

			{ status.kill_switch && (
				<p className="uxs-csp-alert">{ __( 'UXSTUDIO_CSP_DISABLE is set in wp-config.php - no CSP header is sent regardless of this setting.', 'ux-studio' ) }</p>
			) }
			{ status.paused && (
				<p className="uxs-csp-alert">{ __( 'Over 600 reports in the last hour - collecting is paused until the next full hour. Lower the sampling on a busy site.', 'ux-studio' ) }</p>
			) }

			<div className="uxs-csp-status__row">
				<span className={ `uxs-badge ${ probe.reason ? ( probe.ok ? 'is-success' : 'is-danger' ) : '' }` }>
					{ __( 'Header self-test', 'ux-studio' ) }
				</span>
				<span>{ probeText( probe ) }</span>
				{ probe.ok && probe.cached_ok === false && (
					<span className="uxs-csp-status__warn">
						{ __( 'A plain (possibly cached) page came without the header - purge the page cache after changing CSP.', 'ux-studio' ) }
					</span>
				) }
				<button type="button" className="button button-small" disabled={ probing } onClick={ onProbe }>
					<RefreshCw size={ 12 } /> { probing ? __( 'Testing…', 'ux-studio' ) : __( 'Test again', 'ux-studio' ) }
				</button>
			</div>

			{ ( status.mode === 'report-only' || status.mode === 'enforce' ) && status.inline && (
				<>
					<h4>{ __( 'Inline scripts of UX Studio', 'ux-studio' ) }</h4>
					{ ! status.inline.strict ? (
						<p className="uxs-csp-status__soft">
							{ __( 'The policy allows inline scripts ('unsafe-inline'), so UX Studio's own inline scripts run without any extra entry. Hashes are added only to a strict policy without 'unsafe-inline', because a browser ignores 'unsafe-inline' as soon as a hash is present.', 'ux-studio' ) }
						</p>
					) : (
						<>
							<p className="uxs-csp-status__soft">
								{ sprintf(
									/* translators: %d: number of allowed inline scripts. */
									__( 'The policy is strict. These %d inline scripts of UX Studio are allowed by their hash. Inline scripts of other plugins and of your theme are not covered and must be handled separately.', 'ux-studio' ),
									status.inline.entries.length
								) }
							</p>
							{ status.inline.capped && (
								<p className="uxs-csp-alert">
									{ sprintf(
										/* translators: 1: number of scripts, 2: limit. */
										__( '%1$d inline scripts exist, only the first %2$d are allowed by hash.', 'ux-studio' ),
										status.inline.total,
										status.inline.max
									) }
								</p>
							) }
							<ul className="uxs-csp-checks">
								{ status.inline.entries.map( ( e ) => (
									<li key={ e.hash } className="is-ok">
										<Check size={ 14 } /> { e.label } <code title={ e.hash }>{ e.hash.slice( 0, 14 ) }…</code>
									</li>
								) ) }
							</ul>
						</>
					) }
				</>
			) }

			{ ( status.mode === 'report-only' || status.mode === 'enforce' ) && (
				<>
					<h4>{ __( 'Conditions for Enforce', 'ux-studio' ) }</h4>
					<ul className="uxs-csp-checks">
						{ status.gate.checks.map( ( c ) => (
							<li key={ c.id } className={ c.ok ? 'is-ok' : 'is-fail' }>
								{ c.ok ? <Check size={ 14 } /> : <AlertTriangle size={ 14 } /> } { checkText( c, status.gate.test_days ) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

/* ------------------------------------------------------------------ */

/**
 * CSP tab: settings with a confirmation dialog on every mode change, status
 * (self-test, Enforce gate) and the inventory of external sources reported
 * by visitor browsers, rated by risk.
 *
 * Named export (not default) - composed into the module's shared Page.tsx.
 */
export function CspTab(): JSX.Element {
	const [ statusFilter, setStatusFilter ] = useState< '' | 'open' | 'resolved' >( '' );
	const [ levelFilter, setLevelFilter ] = useState< '' | Level >( '' );
	const [ pendingMode, setPendingMode ] = useState< Mode | null >( null );

	const settings = useModuleSettings( 'security-optimization' );
	const savedMode = ( settings.data?.values.csp_mode ?? 'off' ) as Mode;
	const draftMode = ( settings.draft.csp_mode ?? 'off' ) as Mode;

	const status = useQuery( {
		queryKey: STATUS_KEY,
		queryFn: () => api< CspStatus >( 'security-optimization/csp/status' ),
	} );

	const query = useQuery( {
		queryKey: [ ...VIOLATIONS_KEY, statusFilter, levelFilter ],
		queryFn: () => {
			const params = new URLSearchParams();
			if ( statusFilter ) {
				params.set( 'status', statusFilter );
			}
			if ( levelFilter ) {
				params.set( 'level', levelFilter );
			}
			const qs = params.toString();
			return api< CspViolation[] >( `security-optimization/csp-violations${ qs ? `?${ qs }` : '' }` );
		},
	} );

	const refresh = (): void => {
		void queryClient.invalidateQueries( { queryKey: VIOLATIONS_KEY } );
		void queryClient.invalidateQueries( { queryKey: STATUS_KEY } );
	};

	const probe = useMutation( {
		mutationFn: () => api< Probe >( 'security-optimization/csp/probe', { method: 'POST' } ),
		onSuccess: refresh,
	} );

	const save = useMutation( {
		mutationFn: ( override: boolean ) =>
			api< { values: Record< string, unknown > } >( 'settings/security-optimization', {
				method: 'POST',
				body: JSON.stringify( { ...settings.draft, csp_enforce_override: override } ),
			} ),
		onSuccess: ( res ) => {
			setPendingMode( null );
			void queryClient.invalidateQueries( { queryKey: [ 'settings', 'security-optimization' ] } );
			if ( res.values.csp_gate_blocked ) {
				pushToast( 'error', __( 'Enforce was not switched on: the conditions are not met. The previous mode stays.', 'ux-studio' ) );
			}
			// A mode change is the moment to prove the header really arrives.
			if ( res.values.csp_mode !== 'off' && res.values.csp_mode !== savedMode ) {
				probe.mutate();
			} else {
				refresh();
			}
		},
	} );

	const resolve = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/csp-violations/${ id }/resolve`, { method: 'POST' } ),
		onSuccess: refresh,
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/csp-violations/${ id }`, { method: 'DELETE' } ),
		onSuccess: refresh,
	} );

	const allow = useMutation( {
		mutationFn: ( id: number ) =>
			api< { source: string; added: boolean } >( `security-optimization/csp-violations/${ id }/allow`, { method: 'POST' } ),
		onSuccess: ( data ) => {
			pushToast(
				'success',
				data.added
					? sprintf(
							/* translators: %s: CSP source, e.g. https://cdn.example.com */
							__( '%s was approved in the policy.', 'ux-studio' ),
							data.source
					  )
					: sprintf(
							/* translators: %s: CSP source, e.g. https://cdn.example.com */
							__( '%s was already approved.', 'ux-studio' ),
							data.source
					  )
			);
			refresh();
		},
		onError: ( e ) => pushToast( 'error', ( e as Error ).message ),
	} );

	const approveSafe = useMutation( {
		mutationFn: () => api< { approved: number; resolved: number } >( 'security-optimization/csp-violations/approve-safe', { method: 'POST' } ),
		onSuccess: ( data ) => {
			pushToast(
				'success',
				sprintf(
					/* translators: %d: number of sources */
					__( '%d sources approved. Unknown, suspicious and high-risk ones stay for your decision.', 'ux-studio' ),
					data.resolved
				)
			);
			refresh();
		},
	} );

	const onSave = (): void => {
		if ( draftMode !== savedMode && ( draftMode !== 'off' || savedMode === 'enforce' ) ) {
			setPendingMode( draftMode );
			return;
		}
		save.mutate( false );
	};

	const onApproveSafe = (): void => {
		if (
			window.confirm(
				__( 'Approve every open source of your own domains and known services without any risk signal? Unknown, suspicious and high-risk sources are left for you; approve those one by one.', 'ux-studio' )
			)
		) {
			approveSafe.mutate();
		}
	};

	const schema = ( settings.data?.schema ?? [] ).filter( ( f ) => f.key.startsWith( 'csp_' ) );

	return (
		<>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginBottom: 'var(--uxs-sp-4)', alignItems: 'center' } }>
				<ShieldAlert size={ 16 } />
				<p style={ { margin: 0, color: 'var(--uxs-text-soft)' } }>
					{ __( 'Content-Security-Policy tells the browser where the website may load content from. Here you can see every external source visitor browsers reported, rated by risk, and build the policy from them.', 'ux-studio' ) }
				</p>
			</div>

			{ status.data ? (
				<StatusPanel status={ status.data } onProbe={ () => probe.mutate() } probing={ probe.isPending } />
			) : (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }

			{ settings.data && (
				<details className="uxs-csp-settings" open={ savedMode === 'off' }>
					<summary>{ __( 'CSP settings', 'ux-studio' ) }</summary>
					<SettingsFields schema={ schema } draft={ settings.draft } setDraft={ settings.setDraft } />
					<button type="button" className="button button-primary" disabled={ save.isPending } onClick={ onSave }>
						{ __( 'Save changes', 'ux-studio' ) }
					</button>
				</details>
			) }

			<h3>{ __( 'What the website loads', 'ux-studio' ) }</h3>
			<div className="uxs-dns-toolbar">
				<select value={ statusFilter } onChange={ ( e ) => setStatusFilter( e.target.value as typeof statusFilter ) }>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
					<option value="open">{ __( 'Waiting for review', 'ux-studio' ) }</option>
					<option value="resolved">{ __( 'Approved / resolved', 'ux-studio' ) }</option>
				</select>
				<select value={ levelFilter } onChange={ ( e ) => setLevelFilter( e.target.value as typeof levelFilter ) }>
					<option value="">{ __( 'Any rating', 'ux-studio' ) }</option>
					{ ( Object.keys( LEVELS ) as Level[] ).map( ( l ) => (
						<option key={ l } value={ l }>
							{ LEVELS[ l ].label }
						</option>
					) ) }
				</select>
				<button type="button" className="button" disabled={ approveSafe.isPending || ! status.data?.stats.open } onClick={ onApproveSafe }>
					<CheckCheck size={ 14 } /> { __( 'Approve all safe', 'ux-studio' ) }
				</button>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>
					{ savedMode === 'off'
						? __( 'Nothing collected yet. Switch CSP to Inventory to find out what the website loads.', 'ux-studio' )
						: __( 'No sources reported yet. Browsers report them as visitors open pages - give it a few hours.', 'ux-studio' ) }
				</p>
			) : (
				<table className="uxs-table uxs-csp-table">
					<thead>
						<tr>
							<th>{ __( 'Source', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Rating', 'ux-studio' ) }</th>
							<th>{ __( 'Hits', 'ux-studio' ) }</th>
							<th>{ __( 'State', 'ux-studio' ) }</th>
							<th>{ __( 'Last seen', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ query.data.map( ( row ) => (
							<tr key={ row.id } className={ `is-risk-${ row.risk_level }` }>
								<td>
									<strong>{ row.blocked_host || __( '(unknown)', 'ux-studio' ) }</strong>
									{ isNew( row.first_seen ) && <span className="uxs-badge is-info uxs-csp-new">{ __( 'NEW', 'ux-studio' ) }</span> }
									{ row.service && <span className="uxs-csp-soft"> - { row.service }</span> }
									<div className="uxs-csp-soft uxs-csp-uri">{ row.blocked_uri }</div>
									{ row.risk_reasons.length > 0 && (
										<div className="uxs-csp-reasons">{ row.risk_reasons.map( reasonLabel ).join( ', ' ) }</div>
									) }
									{ row.script_sample && <code className="uxs-csp-sample">{ row.script_sample }</code> }
									{ row.pages.length > 0 && (
										<div className="uxs-csp-soft" title={ row.pages.join( '\n' ) }>
											{ sprintf(
												/* translators: 1: first page path, 2: number of pages */
												__( 'seen on %1$s (%2$d pages)', 'ux-studio' ),
												row.pages[ 0 ],
												row.pages.length
											) }
										</div>
									) }
								</td>
								<td>{ DIRECTIVES[ row.directive ] ?? row.directive }</td>
								<td>
									<span className={ `uxs-badge ${ LEVELS[ row.risk_level ]?.cls ?? '' }` }>{ LEVELS[ row.risk_level ]?.label ?? row.risk_level }</span>
								</td>
								<td>
									{ row.hit_count }
									{ row.reporters > 0 && (
										<span
											className="uxs-csp-sub"
											title={ __( 'Distinct visitor browsers that reported this source. One reporter with many hits can be a forged report.', 'ux-studio' ) }
										>
											{ ' ' }
											{ sprintf(
												/* translators: %d: number of distinct reporting browsers */
												_n( '(%d reporter)', '(%d reporters)', row.reporters, 'ux-studio' ),
												row.reporters
											) }
										</span>
									) }
								</td>
								<td>
									{ row.allowed ? (
										<span className="uxs-badge is-success">{ __( 'Allowed by policy', 'ux-studio' ) }</span>
									) : row.status === 'resolved' ? (
										<span className="uxs-badge is-success">{ __( 'Approved', 'ux-studio' ) }</span>
									) : (
										<span className="uxs-badge is-warning">{ __( 'Waiting', 'ux-studio' ) }</span>
									) }
								</td>
								<td>{ formatDate( row.last_seen, { utc: true } ) }</td>
								<td>
									<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
									{ row.status !== 'resolved' && row.source !== '' && ! row.allowed && (
										<button
											type="button"
											className="button-link"
											title={ sprintf(
												/* translators: 1: CSP source, e.g. https://cdn.example.com, 2: type, e.g. Script */
												__( 'Approve %1$s for %2$s', 'ux-studio' ),
												row.source,
												DIRECTIVES[ row.directive ] ?? row.directive
											) }
											aria-label={ __( 'Approve', 'ux-studio' ) }
											disabled={ allow.isPending }
											onClick={ () => {
												if (
													( row.risk_level === 'high' || row.risk_level === 'medium' ) &&
													! window.confirm( __( 'This source is rated as suspicious. Approve it only if you know who added it and why. Approve anyway?', 'ux-studio' ) )
												) {
													return;
												}
												if (
													row.risk_level === 'unknown' &&
													! window.confirm( __( 'This source is not a known service. Anyone can send a report for it, so approve it only if you recognise it and know why the website loads it. Approve anyway?', 'ux-studio' ) )
												) {
													return;
												}
												allow.mutate( row.id );
											} }
										>
											<ShieldCheck size={ 14 } />
										</button>
									) }
									{ row.status !== 'resolved' && (
										<button
											type="button"
											className="button-link"
											title={ __( 'Mark resolved without changing the policy (e.g. removed from the website)', 'ux-studio' ) }
											aria-label={ __( 'Mark resolved', 'ux-studio' ) }
											disabled={ resolve.isPending }
											onClick={ () => resolve.mutate( row.id ) }
										>
											<Check size={ 14 } />
										</button>
									) }
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete', 'ux-studio' ) }
										disabled={ remove.isPending }
										onClick={ () => remove.mutate( row.id ) }
									>
										<Trash2 size={ 14 } />
									</button>
									</div>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ pendingMode && (
				<ModeDialog
					from={ savedMode }
					to={ pendingMode }
					status={ status.data }
					busy={ save.isPending }
					onConfirm={ ( override ) => save.mutate( override ) }
					onCancel={ () => setPendingMode( null ) }
				/>
			) }
		</>
	);
}
