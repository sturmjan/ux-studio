import { useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Info, KeyRound, LoaderCircle, Lock, Plus, RefreshCw, Trash2, Unlock } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { hashParam, navigate } from '../../app/route';
import { type Field, useModuleSettings, type Value } from '../../app/SettingsForm';
import { TopicSettings, inSections } from '../../app/TopicSettings';
import { CspTab } from './CspTab';
import { UploadGuardTab } from './UploadGuardTab';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';

const MODULE = 'security-optimization';

/**
 * One menu item per topic; each page shows the topic's settings and, below,
 * its data (ban list, login attempts, CSP inventory, scan findings). CSP and
 * Upload Guard pages live in CspTab.tsx/UploadGuardTab.tsx.
 */
type TabId = 'firewall' | 'login' | 'two-factor' | 'captcha' | 'headers' | 'csp' | 'upload-guard' | 'hardening' | 'performance' | 'content';

const TABS: { id: TabId; label: string }[] = [
	{ id: 'firewall', label: __( 'Firewall & IP bans', 'ux-studio' ) },
	{ id: 'login', label: __( 'Login', 'ux-studio' ) },
	{ id: 'two-factor', label: __( 'Two-step verification', 'ux-studio' ) },
	{ id: 'captcha', label: __( 'CAPTCHA', 'ux-studio' ) },
	{ id: 'headers', label: __( 'HTTPS & headers', 'ux-studio' ) },
	{ id: 'csp', label: __( 'CSP', 'ux-studio' ) },
	{ id: 'upload-guard', label: __( 'Upload protection', 'ux-studio' ) },
	{ id: 'hardening', label: __( 'Site hardening', 'ux-studio' ) },
	{ id: 'performance', label: __( 'Performance', 'ux-studio' ) },
	{ id: 'content', label: __( 'Content', 'ux-studio' ) },
];

/** Tab ids of the old layout, still used by links in e-mails and bookmarks. */
const LEGACY_TABS: Record< string, TabId > = {
	settings: 'firewall',
	'ip-bans': 'firewall',
	'login-attempts': 'login',
};

interface LoginAttemptRow {
	id: number;
	username: string;
	ip: string;
	country: string | null;
	status: number;
	locktime: number;
	locklimit: number;
	date: string;
}

interface IpBanRow {
	id: number;
	type: 'single' | 'cidr' | 'country';
	value: string;
	label: string | null;
	note: string | null;
	origin: 'local' | 'central' | 'migration';
	is_active: number;
	expires_at: string | null;
	hit_count: number;
	last_hit_at: string | null;
	created_at: string;
}

interface HtaccessStatus {
	applied: boolean;
	stored_hash: string;
	current_hash: string;
}

/**
 * State of the module's .htaccess block - shown on the topics whose settings
 * are written there (HTTPS & headers, Site hardening).
 */
function HtaccessStatusBox(): JSX.Element {
	const htaccess = useQuery( {
		queryKey: [ MODULE, 'htaccess-status' ],
		queryFn: () => api< HtaccessStatus >( 'security-optimization/htaccess/status' ),
	} );

	const applyHtaccess = useMutation( {
		mutationFn: () => api( 'security-optimization/htaccess/apply', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ MODULE, 'htaccess-status' ] } ),
	} );

	return (
		<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
			<h3>{ __( '.htaccess rules', 'ux-studio' ) }</h3>
			{ htaccess.data && (
				<p>
					<span className={ `uxs-badge ${ htaccess.data.applied ? 'is-success' : 'is-warning' }` }>
						{ htaccess.data.applied
							? __( 'Applied and up to date', 'ux-studio' )
							: __( 'Out of date - re-apply to write current settings', 'ux-studio' ) }
					</span>
				</p>
			) }
			<button type="button" className="button" disabled={ applyHtaccess.isPending } onClick={ () => applyHtaccess.mutate() }>
				<RefreshCw size={ 14 } /> { __( 'Apply .htaccess rules', 'ux-studio' ) }
			</button>
		</div>
	);
}

interface PhpVersionStatus {
	level: 'ok' | 'warning' | 'critical';
	php_full: string;
	message: string;
	advisory_url: string;
	backport_note: string;
}

/**
 * Result of the PHP version check (same row style as the .htaccess state): a badge, the sentence of the admin
 * notice and the advisory link. Shown on the hardening topic, next to the other server-side state.
 */
function PhpVersionStatusBox(): JSX.Element | null {
	const php = useQuery( {
		queryKey: [ MODULE, 'php-version' ],
		queryFn: () => api< PhpVersionStatus >( 'security/php-version' ),
	} );
	const data = php.data;
	// Only a problem is worth a place on the screen: a safe PHP version needs no status row.
	if ( ! data || 'ok' === data.level ) {
		return null;
	}
	const badge = 'warning' === data.level ? 'is-warning' : 'is-danger';
	return (
		<div style={ { marginTop: 'var(--uxs-sp-5)' } }>
			<h3>{ __( 'PHP version', 'ux-studio' ) }</h3>
			<p>
				<span className={ `uxs-badge ${ badge }` }>
					{ 'warning' === data.level
						? sprintf(
								/* translators: %s: PHP version. */
								__( 'PHP %s will soon lose security support', 'ux-studio' ),
								data.php_full
						  )
						: sprintf(
								/* translators: %s: PHP version. */
								__( 'PHP %s is not safe', 'ux-studio' ),
								data.php_full
						  ) }
				</span>
			</p>
			{ '' !== data.message && <p>{ data.message }</p> }
			{ '' !== data.backport_note && <p className="description">{ data.backport_note }</p> }
			{ '' !== data.advisory_url && (
				<p>
					<a href={ data.advisory_url } target="_blank" rel="noopener noreferrer">
						{ __( 'Security advisory on php.net', 'ux-studio' ) }
					</a>
				</p>
			) }
		</div>
	);
}

/** Settings only the key-less Shield reads. */
const SHIELD_KEYS = [ 'captcha_shield_level', 'captcha_shield_adaptive', 'captcha_shield_min_time' ];
/** Settings that only a provider with keys reads. */
const KEY_SETTINGS = [ 'captcha_site_key', 'captcha_secret_key', 'captcha_key_source', 'captcha_cf_account_id', 'captcha_cf_api_token', 'captcha_size' ];

/**
 * Which CAPTCHA settings make sense for the chosen provider: the Shield has no keys to show, a provider with keys has
 * no difficulty, Automatic needs both (the keys are optional there) and the type of the keys.
 */
function captchaFieldVisible( field: Field, draft: Record< string, Value > ): boolean {
	const provider = String( draft.captcha_provider ?? 'auto' );
	if ( SHIELD_KEYS.includes( field.key ) ) {
		return 'shield' === provider || 'auto' === provider;
	}
	if ( KEY_SETTINGS.includes( field.key ) ) {
		return 'shield' !== provider;
	}
	if ( 'captcha_own_provider' === field.key ) {
		return 'auto' === provider;
	}
	if ( 'captcha_recaptcha_v3_threshold' === field.key ) {
		return 'recaptcha_v3' === provider || ( 'auto' === provider && 'recaptcha_v3' === draft.captcha_own_provider );
	}
	return true;
}

/** What the Shield protects against and where it stops. Honest on purpose: it is a speed bump, not a person detector. */
function ShieldInfo(): JSX.Element {
	return (
		<div className="uxs-callout" role="note">
			<Info size={ 16 } aria-hidden />
			<div>
				<p>
					<strong>{ __( 'UX Studio Shield', 'ux-studio' ) }</strong>
					{ ' - ' }
					{ __( 'a CAPTCHA with no account, no keys and no cookies. The browser of the visitor solves a small puzzle by itself (about a fraction of a second on a computer) and nothing leaves your site.', 'ux-studio' ) }
				</p>
				<p>
					<strong>{ __( 'What it protects against:', 'ux-studio' ) }</strong>{ ' ' }
					{ __( 'mass scripted attempts such as password guessing and spam bots. Every attempt costs computing time, an answer works only once, and a form sent faster than a person can fill it is refused.', 'ux-studio' ) }
				</p>
				<p>
					<strong>{ __( 'Where it stops:', 'ux-studio' ) }</strong>{ ' ' }
					{ __( 'it does not recognise a human. An attacker who has computing power passes, only more slowly, so on a site that is attacked in earnest use Cloudflare Turnstile or reCAPTCHA keys. It also counts per IP address: behind a reverse proxy set the client IP source in Firewall first.', 'ux-studio' ) }
				</p>
				<p>
					<strong>{ __( 'Without JavaScript:', 'ux-studio' ) }</strong>{ ' ' }
					{ __( 'the login, registration and lost password forms show a simple sum instead, so an administrator is never locked out. Comments and forms of the Forms module need JavaScript.', 'ux-studio' ) }
				</p>
			</div>
		</div>
	);
}

/** Whether the CAPTCHA secret is stored (it is never sent back to the browser), shown where keys are used. */
function CaptchaSecretNote( { provider }: { provider: string } ): JSX.Element | null {
	const { draft } = useModuleSettings( MODULE );
	if ( 'shield' === provider ) {
		return null;
	}
	return (
		<p>
			{ draft.has_captcha_secret_key
				? __( 'A CAPTCHA secret key is currently set.', 'ux-studio' )
				: __( 'No CAPTCHA secret key set yet.', 'ux-studio' ) }
		</p>
	);
}

/** A data block under the topic's settings. */
function TopicData( { title, children }: { title: string; children: JSX.Element } ): JSX.Element {
	return (
		<section className="uxs-topic-data">
			<h3>{ title }</h3>
			{ children }
		</section>
	);
}

function LoginAttemptsTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'security-optimization', 'login-attempts' ],
		queryFn: () => api< LoginAttemptRow[] >( 'security-optimization/login-attempts?per_page=50' ),
	} );

	const unlock = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/login-attempts/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'security-optimization', 'login-attempts' ] } ),
	} );

	const clearAll = useMutation( {
		mutationFn: () => api( 'security-optimization/login-attempts/clear', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'security-optimization', 'login-attempts' ] } ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			<p className="uxs-form__help">
				{ __( 'Failed logins and the addresses locked out because of them. Remove a row to let that address try again. "Clear all attempts" lifts every lockout at once, including those of real attackers.', 'ux-studio' ) }
			</p>
			<p>
				<button type="button" className="button" onClick={ () => clearAll.mutate() } disabled={ clearAll.isPending }>
					<Trash2 size={ 14 } /> { __( 'Clear all attempts', 'ux-studio' ) }
				</button>
			</p>
			{ ! data || data.length === 0 ? (
				<p>{ __( 'No failed login attempts recorded.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Username', 'ux-studio' ) }</th>
							<th>{ __( 'IP', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Lockout (min)', 'ux-studio' ) }</th>
							<th>{ __( 'Date', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ row.username }</td>
								<td>{ row.ip }</td>
								<td>
									<span className={ `uxs-badge ${ row.status ? 'is-danger' : 'is-success' }` }>
										{ row.status ? (
											<>
												<Lock size={ 12 } /> { __( 'Locked', 'ux-studio' ) }
											</>
										) : (
											__( 'Cleared', 'ux-studio' )
										) }
									</span>
								</td>
								<td>{ row.locktime }</td>
								<td>{ formatDate( row.date ) }</td>
								<td>
									{ !! row.status && (
										<button
											type="button"
											className="button button-small"
											onClick={ () => unlock.mutate( row.id ) }
											disabled={ unlock.isPending }
										>
											<Unlock size={ 12 } /> { __( 'Unlock', 'ux-studio' ) }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) }
		</>
	);
}

interface TwoFactorUser {
	id: number;
	login: string;
	name: string;
	roles: string;
	recovery_left: number;
}

interface TwoFactorMissing {
	id: number;
	login: string;
	name: string;
	roles: string;
	grace_left: number;
	forced: boolean;
}

interface TwoFactorStatus {
	feature: boolean;
	killed: boolean;
	kill_switch: string;
	users: TwoFactorUser[];
	missing: TwoFactorMissing[];
}

/** Who has two-step verification, who still has to set it up, and the rescue switch. */
function TwoFactorData(): JSX.Element {
	const status = useQuery( {
		queryKey: [ MODULE, 'two-factor' ],
		queryFn: () => api< TwoFactorStatus >( 'security-optimization/two-factor' ),
	} );

	const reset = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/two-factor/reset/${ id }`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ MODULE, 'two-factor' ] } ),
	} );

	const data = status.data;
	if ( status.isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<>
			{ data.killed && (
				<p>
					<span className="uxs-badge is-warning">
						<KeyRound size={ 12 } />{ ' ' }
						{ __( 'The rescue switch is set in wp-config.php: the second step is off for everybody.', 'ux-studio' ) }
					</span>
				</p>
			) }
			{ ! data.feature && ! data.killed && <p>{ __( 'Two-step verification is turned off. Switch it on above and save; every user then sets it up in their own profile.', 'ux-studio' ) }</p> }
			<p>
				{ __( 'If you get locked out, add this line to wp-config.php, then remove it again after you log in:', 'ux-studio' ) } <code>{ `define( '${ data.kill_switch }', true );` }</code>
			</p>

			<h4>{ __( 'Users with two-step verification', 'ux-studio' ) }</h4>
			{ data.users.length === 0 ? (
				<p>{ __( 'Nobody has set it up yet.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'User', 'ux-studio' ) }</th>
							<th>{ __( 'Role', 'ux-studio' ) }</th>
							<th>{ __( 'Unused recovery codes', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ data.users.map( ( u ) => (
							<tr key={ u.id }>
								<td>
									{ u.name } <code>{ u.login }</code>
								</td>
								<td>{ u.roles }</td>
								<td>{ u.recovery_left }</td>
								<td>
									<button
										type="button"
										className="button button-small"
										disabled={ reset.isPending }
										onClick={ () => {
											if ( window.confirm( __( 'Reset two-step verification of this user? They will log in with the password only until they set it up again.', 'ux-studio' ) ) ) {
												reset.mutate( u.id );
											}
										} }
									>
										{ __( 'Turn off for this user', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) }

			{ data.missing.length > 0 && (
				<>
					<h4>{ __( 'Required, not set up yet', 'ux-studio' ) }</h4>
					<div className="uxs-table-wrap"><table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'User', 'ux-studio' ) }</th>
								<th>{ __( 'Role', 'ux-studio' ) }</th>
								<th>{ __( 'Status', 'ux-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ data.missing.map( ( u ) => (
								<tr key={ u.id }>
									<td>
										{ u.name } <code>{ u.login }</code>
									</td>
									<td>{ u.roles }</td>
									<td>
										<span className={ `uxs-badge ${ u.forced || u.grace_left === 0 ? 'is-danger' : 'is-warning' }` }>
											{ u.forced || u.grace_left === 0
												? __( 'Setup is compulsory', 'ux-studio' )
												: sprintf(
														/* translators: %d: number of logins. */
														_n( '%d login left to postpone', '%d logins left to postpone', u.grace_left, 'ux-studio' ),
														u.grace_left
												  ) }
										</span>
									</td>
								</tr>
							) ) }
						</tbody>
					</table></div>
				</>
			) }
		</>
	);
}

function IpBansTab(): JSX.Element {
	const [ type, setType ] = useState< 'single' | 'cidr' | 'country' >( 'single' );
	const [ value, setValue ] = useState( '' );
	const [ note, setNote ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'security-optimization', 'ip-bans' ],
		queryFn: () => api< IpBanRow[] >( 'security-optimization/ip-bans?per_page=100' ),
	} );

	const create = useMutation( {
		mutationFn: () =>
			api( 'security-optimization/ip-bans', {
				method: 'POST',
				body: JSON.stringify( { type, value, note } ),
			} ),
		onSuccess: () => {
			setValue( '' );
			setNote( '' );
			void queryClient.invalidateQueries( { queryKey: [ 'security-optimization', 'ip-bans' ] } );
		},
	} );

	const toggle = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/ip-bans/${ id }/toggle`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'security-optimization', 'ip-bans' ] } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/ip-bans/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'security-optimization', 'ip-bans' ] } ),
	} );

	return (
		<>
			<div className="uxs-form">
				<div className="uxs-form__row">
					<label htmlFor="uxs-ipban-type">{ __( 'Type', 'ux-studio' ) }</label>
					<select id="uxs-ipban-type" value={ type } onChange={ ( e ) => setType( e.target.value as typeof type ) }>
						<option value="single">{ __( 'Single IP address', 'ux-studio' ) }</option>
						<option value="cidr">{ __( 'IP range (CIDR)', 'ux-studio' ) }</option>
						<option value="country">{ __( 'Country code (ISO 3166-1 alpha-2)', 'ux-studio' ) }</option>
					</select>
					<p className="uxs-form__help">
						{ __( 'A single address blocks one visitor. A range (CIDR) blocks a whole network, for example 203.0.113.0/24 is 256 addresses. A country code, such as CN, blocks everybody from that country.', 'ux-studio' ) }
					</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-ipban-value">{ __( 'Value', 'ux-studio' ) }</label>
					<input
						id="uxs-ipban-value"
						type="text"
						placeholder="203.0.113.5 / 203.0.113.0/24 / CN"
						value={ value }
						onChange={ ( e ) => setValue( e.target.value ) }
					/>
					<p className="uxs-form__help">
						{ __( 'Write the address, range or code that matches the type. Do not ban your own address or a shared one such as an office: you would lock yourself and others out. Logged-in administrators and the allowlist are never blocked. The ban takes effect only while the IP firewall is turned on in the settings.', 'ux-studio' ) }
					</p>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-ipban-note">{ __( 'Note', 'ux-studio' ) }</label>
					<input id="uxs-ipban-note" type="text" value={ note } onChange={ ( e ) => setNote( e.target.value ) } />
					<p className="uxs-form__help">
						{ __( 'Optional. Write why you banned it, so you remember later.', 'ux-studio' ) }
					</p>
				</div>
			</div>
			<p>
				<button
					type="button"
					className="button button-primary"
					disabled={ ! value || create.isPending }
					onClick={ () => create.mutate() }
				>
					<Plus size={ 14 } /> { __( 'Add ban', 'ux-studio' ) }
				</button>
				{ create.isError && (
					<span className="uxs-badge is-danger" style={ { marginLeft: 'var(--uxs-sp-2)' } }>
						{ ( create.error as Error ).message }
					</span>
				) }
			</p>

			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! data || data.length === 0 ? (
				<p>{ __( 'No IP bans yet.', 'ux-studio' ) }</p>
			) : (
				<div className="uxs-table-wrap"><table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Target', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Note', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Hits', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<code>{ row.value }</code>
									{ row.label ? <><br /><span>{ row.label }</span></> : null }
								</td>
								<td>{ row.type }</td>
								<td>{ row.note || '—' }</td>
								<td>
									<span className={ `uxs-badge ${ row.is_active ? 'is-success' : 'is-info' }` }>
										{ row.is_active ? __( 'Active', 'ux-studio' ) : __( 'Disabled', 'ux-studio' ) }
									</span>
									{ row.origin === 'central' ? (
										<span className="uxs-badge is-info" style={ { marginLeft: 'var(--uxs-sp-1)' } }>
											{ __( 'Central', 'ux-studio' ) }
										</span>
									) : null }
									{ row.origin === 'migration' ? (
										<span className="uxs-badge is-info" style={ { marginLeft: 'var(--uxs-sp-1)' } }>
											{ __( 'Migrated', 'ux-studio' ) }
										</span>
									) : null }
								</td>
								<td>{ row.hit_count }</td>
								<td>
									{ ( row.origin === 'local' || row.origin === 'migration' ) && (
										<>
											<button
												type="button"
												className="button button-small"
												onClick={ () => toggle.mutate( row.id ) }
											>
												{ row.is_active ? __( 'Disable', 'ux-studio' ) : __( 'Enable', 'ux-studio' ) }
											</button>{ ' ' }
											<button
												type="button"
												className="button button-small"
												onClick={ () => remove.mutate( row.id ) }
											>
												<Trash2 size={ 12 } />
											</button>
										</>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table></div>
			) }
		</>
	);
}

export default function Page(): JSX.Element {
	// Deep links (dashboard widget, e-mails) open a topic directly: #/module?id=…&tab=csp.
	const [ tab, setTab ] = useState< TabId >( () => {
		const wanted = hashParam( 'tab' ) ?? '';
		return TABS.find( ( t ) => t.id === wanted )?.id ?? LEGACY_TABS[ wanted ] ?? 'firewall';
	} );

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Security - Access Control', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				{ TABS.map( ( t ) => (
					<button key={ t.id } className={ tab === t.id ? 'is-active' : '' } onClick={ () => setTab( t.id ) }>
						{ t.label }
					</button>
				) ) }
			</TabsNav>
			<h2 className="uxs-form__section">{ TABS.find( ( t ) => t.id === tab )?.label }</h2>
			{ tab === 'firewall' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ inSections( __( 'Firewall', 'ux-studio' ) ) } />
					<TopicData title={ __( 'Blocked IP addresses', 'ux-studio' ) }>
						<IpBansTab />
					</TopicData>
				</>
			) }
			{ tab === 'login' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ inSections( __( 'Login', 'ux-studio' ) ) } />
					<TopicData title={ __( 'Login attempts', 'ux-studio' ) }>
						<LoginAttemptsTab />
					</TopicData>
				</>
			) }
			{ tab === 'two-factor' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ inSections( __( 'Two-step verification', 'ux-studio' ) ) } onSaved={ () => void queryClient.invalidateQueries( { queryKey: [ MODULE, 'two-factor' ] } ) } />
					<TopicData title={ __( 'Overview', 'ux-studio' ) }>
						<TwoFactorData />
					</TopicData>
				</>
			) }
			{ tab === 'captcha' && (
				<TopicSettings moduleId={ MODULE } include={ inSections( __( 'CAPTCHA', 'ux-studio' ) ) } showField={ captchaFieldVisible }>
					{ ( draft ) => {
						const provider = String( draft.captcha_provider ?? 'auto' );
						return (
							<>
								{ ( 'shield' === provider || 'auto' === provider ) && <ShieldInfo /> }
								<CaptchaSecretNote provider={ provider } />
							</>
						);
					} }
				</TopicSettings>
			) }
			{ tab === 'headers' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ inSections( __( 'HTTPS & headers', 'ux-studio' ) ) } />
					<HtaccessStatusBox />
				</>
			) }
			{ tab === 'csp' && <CspTab /> }
			{ tab === 'upload-guard' && <UploadGuardTab /> }
			{ tab === 'hardening' && (
				<>
					<TopicSettings moduleId={ MODULE } include={ inSections( __( 'Hardening', 'ux-studio' ) ) } />
					<HtaccessStatusBox />
					<PhpVersionStatusBox />
				</>
			) }
			{ tab === 'performance' && <TopicSettings moduleId={ MODULE } include={ inSections( __( 'Performance', 'ux-studio' ) ) } /> }
			{ tab === 'content' && <TopicSettings moduleId={ MODULE } include={ inSections( __( 'Content', 'ux-studio' ) ) } /> }
		</>
	);
}
