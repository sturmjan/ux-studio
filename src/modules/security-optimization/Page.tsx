import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Lock, Plus, RefreshCw, Trash2, Unlock } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { hashParam, navigate } from '../../app/route';
import { useModuleSettings } from '../../app/SettingsForm';
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
type TabId = 'firewall' | 'login' | 'captcha' | 'headers' | 'csp' | 'upload-guard' | 'hardening' | 'performance' | 'content';

const TABS: { id: TabId; label: string }[] = [
	{ id: 'firewall', label: __( 'Firewall & IP bans', 'ux-studio' ) },
	{ id: 'login', label: __( 'Login', 'ux-studio' ) },
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
	origin: 'local' | 'central';
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

/** Whether the CAPTCHA secret is stored (it is never sent back to the browser). */
function CaptchaSecretNote(): JSX.Element {
	const { draft } = useModuleSettings( MODULE );
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
			<p>
				<button type="button" className="button" onClick={ () => clearAll.mutate() } disabled={ clearAll.isPending }>
					<Trash2 size={ 14 } /> { __( 'Clear all attempts', 'ux-studio' ) }
				</button>
			</p>
			{ ! data || data.length === 0 ? (
				<p>{ __( 'No failed login attempts recorded.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
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
				</table>
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
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-ipban-note">{ __( 'Note', 'ux-studio' ) }</label>
					<input id="uxs-ipban-note" type="text" value={ note } onChange={ ( e ) => setNote( e.target.value ) } />
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
				<table className="uxs-table">
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
								</td>
								<td>{ row.hit_count }</td>
								<td>
									{ row.origin === 'local' && (
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
				</table>
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
			{ tab === 'captcha' && (
				<TopicSettings moduleId={ MODULE } include={ inSections( __( 'CAPTCHA', 'ux-studio' ) ) }>
					<CaptchaSecretNote />
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
				</>
			) }
			{ tab === 'performance' && <TopicSettings moduleId={ MODULE } include={ inSections( __( 'Performance', 'ux-studio' ) ) } /> }
			{ tab === 'content' && <TopicSettings moduleId={ MODULE } include={ inSections( __( 'Content', 'ux-studio' ) ) } /> }
		</>
	);
}
