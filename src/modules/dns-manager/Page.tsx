import { useEffect, useMemo, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Lock, LoaderCircle, Pencil, Plus, RefreshCw, Trash2, Undo2 } from 'lucide-react';
import { api, ApiError, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';

type Tab = 'records' | 'history' | 'settings';
type RecType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'SRV' | 'CAA';
type Risk = 'mx' | 'email_auth' | 'service';

interface DnsRecord {
	id: string;
	type: RecType;
	name: string;
	content: string;
	ttl: number;
	proxied: boolean;
	priority: number | null;
	comment: string;
	modified_on: string;
	locked?: boolean;
	lock_reason?: string | null;
	read_only?: boolean;
	proxy_locked?: boolean;
	risk?: Risk[];
}

interface DnsStatus {
	active: boolean;
	reason?: string;
	message?: string;
	zone?: string;
	can_manage: boolean;
	acknowledged: boolean;
	user_id: number;
	login_fresh: boolean;
	reauth_url: string;
}

interface RecordsPayload {
	records: DnsRecord[];
	cached: boolean;
	zone?: string;
}

interface WriteResult {
	status: string;
	op?: string;
	code?: string;
	message?: string;
	warnings?: { code: string; message: string }[];
	record?: DnsRecord | null;
	before?: DnsRecord | null;
}

interface WritePayload {
	results: WriteResult[];
	batch_id: string;
}

interface LogRow {
	id: number;
	created_at: string;
	wp_user_id: number;
	wp_user_login: string;
	wp_user_name: string;
	action: string;
	record_type: string;
	record_name: string;
	before: DnsRecord | null;
	after: DnsRecord | null;
	status: string;
	error: string | null;
	reverted_by?: number | null;
}

interface LogPayload {
	rows: LogRow[];
	total: number;
	users: { id: number; login: string; name: string }[];
	source: 'central' | 'local';
}

/** What the form edits. `id` empty = new record. */
interface Draft {
	id: string;
	type: RecType;
	name: string;
	content: string;
	priority: string;
	ttl: number;
	proxied: boolean;
	comment: string;
	modified_on: string;
}

/** A pending change waiting in the confirmation dialog. */
interface Pending {
	kind: 'create' | 'update' | 'delete' | 'revert';
	title: string;
	before: Partial<DnsRecord> | null;
	after: Partial<DnsRecord> | null;
	risk: Risk[];
	requireName: string;
	run: () => Promise< WritePayload >;
}

const WRITABLE: RecType[] = [ 'A', 'AAAA', 'CNAME', 'MX', 'TXT' ];
const TTL_OPTIONS = [ 1, 60, 120, 300, 600, 1800, 3600, 7200, 14400, 43200, 86400 ];
const PAGE_SIZE = 25;
const EMPTY_DRAFT: Draft = { id: '', type: 'A', name: '', content: '', priority: '10', ttl: 1, proxied: false, comment: '', modified_on: '' };

function ttlLabel( ttl: number ): string {
	return ttl === 1 ? __( 'Auto', 'ux-studio' ) : `${ ttl } s`;
}

/** `blog.example.cz` in zone `example.cz` -> `blog`; the apex -> `@`. */
function shortName( name: string, zone: string ): string {
	if ( name === zone ) {
		return '@';
	}
	return zone && name.endsWith( `.${ zone }` ) ? name.slice( 0, -( zone.length + 1 ) ) : name;
}

/** Mirror of the central app's rule (UX only, the central app decides). */
function proxyForbidden( type: string, name: string, content: string ): boolean {
	if ( ! [ 'A', 'AAAA', 'CNAME' ].includes( type ) ) {
		return true;
	}
	if ( type === 'CNAME' ) {
		return /(\.mcsv\.net|\.mandrillapp\.com|\.sendgrid\.net|\.mailgun\.org|\.amazonses\.com)$/i.test( content ) || name.includes( '_domainkey' ) || /^_[a-z0-9-]+\./i.test( name );
	}
	return false;
}

function riskOf( rec: Partial<DnsRecord> | null, zone: string ): Risk[] {
	if ( ! rec || ! rec.type ) {
		return [];
	}
	const out: Risk[] = [];
	const name = ( rec.name ?? '' ).toLowerCase();
	const content = rec.content ?? '';
	if ( rec.type === 'MX' ) {
		out.push( 'mx' );
	}
	if ( ( rec.type === 'TXT' && ( /^\s*"?(v=spf1|v=dkim1|v=dmarc1)/i.test( content ) || name.startsWith( '_dmarc.' ) ) ) || ( rec.type === 'CNAME' && name.includes( '_domainkey' ) ) ) {
		out.push( 'email_auth' );
	}
	if ( [ 'A', 'AAAA', 'CNAME' ].includes( rec.type ) && name !== zone ) {
		out.push( 'service' );
	}
	return out;
}

function isPrivateV4( ip: string ): boolean {
	const m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec( ip );
	if ( ! m ) {
		return false;
	}
	const [ a, b ] = [ Number( m[ 1 ] ), Number( m[ 2 ] ) ];
	return a === 10 || a === 127 || a === 0 || ( a === 172 && b >= 16 && b <= 31 ) || ( a === 192 && b === 168 ) || ( a === 169 && b === 254 ) || a >= 224;
}

/** Light UX validation; returns a message or ''. The central app validates for real. */
function validateDraft( d: Draft ): string {
	if ( d.name.includes( '*' ) ) {
		return __( 'Wildcard records (*) are not supported yet.', 'ux-studio' );
	}
	if ( d.content.trim() === '' ) {
		return __( 'Fill in the record value.', 'ux-studio' );
	}
	if ( d.type === 'A' ) {
		if ( ! /^(\d{1,3}\.){3}\d{1,3}$/.test( d.content.trim() ) ) {
			return __( 'Enter a valid IPv4 address.', 'ux-studio' );
		}
		if ( isPrivateV4( d.content.trim() ) ) {
			return __( 'Private and reserved IP addresses are not allowed.', 'ux-studio' );
		}
	}
	if ( d.type === 'AAAA' && ( ! d.content.includes( ':' ) || /^(::1?|fe80|fc|fd)/i.test( d.content.trim() ) ) ) {
		return __( 'Enter a valid public IPv6 address.', 'ux-studio' );
	}
	if ( d.type === 'MX' ) {
		const p = Number( d.priority );
		if ( d.priority === '' || ! Number.isInteger( p ) || p < 0 || p > 65535 ) {
			return __( 'MX priority must be a whole number 0-65535.', 'ux-studio' );
		}
	}
	if ( d.type === 'TXT' && d.content.length > 2048 ) {
		return __( 'A TXT record can have at most 2048 characters.', 'ux-studio' );
	}
	if ( d.type === 'CNAME' && ( d.name.trim() === '' || d.name.trim() === '@' ) ) {
		return __( 'A CNAME cannot be used at the domain root.', 'ux-studio' );
	}
	return '';
}

function errorBox( err: unknown, onRefresh?: () => void ): JSX.Element | null {
	if ( ! err ) {
		return null;
	}
	if ( err instanceof ApiError ) {
		const results = ( err.data.results ?? [] ) as WriteResult[];
		const first = results.find( ( r ) => r.status !== 'skipped' && r.message );
		const message = first?.message ?? err.message;
		if ( err.code === 'reauth_required' ) {
			const url = String( err.data.reauth_url ?? '' );
			return (
				<div className="uxs-dns-danger" role="alert">
					<strong>{ __( 'Please log in again', 'ux-studio' ) }</strong>
					<p>{ err.message }</p>
					{ url && (
						<a className="button button-primary" href={ url }>
							{ __( 'Log in again', 'ux-studio' ) }
						</a>
					) }
				</div>
			);
		}
		if ( err.status === 409 && onRefresh ) {
			return (
				<div className="uxs-dns-danger" role="alert">
					<p>{ message }</p>
					<button type="button" className="button" onClick={ onRefresh }>
						<RefreshCw size={ 14 } /> { __( 'Reload records', 'ux-studio' ) }
					</button>
				</div>
			);
		}
		return (
			<div className="uxs-dns-danger" role="alert">
				{ message }
			</div>
		);
	}
	return (
		<div className="uxs-dns-danger" role="alert">
			{ ( err as Error ).message }
		</div>
	);
}

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
		</div>
	);
}

function Banner(): JSX.Element {
	return (
		<div className="uxs-dns-banner" role="note">
			<strong>{ __( 'DNS changes take effect immediately.', 'ux-studio' ) }</strong>{ ' ' }
			{ __( 'They directly affect the website, e-mail mailboxes and other services tied to the domain (Google verification, payment gateways, SSL certificate). A wrong record can mean an unreachable website or lost e-mails. If you are not sure, contact us.', 'ux-studio' ) }
		</div>
	);
}

/* ------------------------------------------------------------------ */

const FIELDS: { key: keyof DnsRecord; label: string }[] = [
	{ key: 'type', label: __( 'Type', 'ux-studio' ) },
	{ key: 'name', label: __( 'Name', 'ux-studio' ) },
	{ key: 'content', label: __( 'Value', 'ux-studio' ) },
	{ key: 'priority', label: __( 'Priority', 'ux-studio' ) },
	{ key: 'ttl', label: __( 'TTL', 'ux-studio' ) },
	{ key: 'proxied', label: __( 'Proxy', 'ux-studio' ) },
	{ key: 'comment', label: __( 'Note', 'ux-studio' ) },
];

function fieldText( rec: Partial<DnsRecord> | null, key: keyof DnsRecord ): string {
	if ( ! rec ) {
		return '';
	}
	const v = rec[ key ];
	if ( key === 'ttl' ) {
		return v === undefined ? '' : ttlLabel( Number( v ) );
	}
	if ( key === 'proxied' ) {
		return v ? __( 'on', 'ux-studio' ) : __( 'off', 'ux-studio' );
	}
	return v === null || v === undefined ? '' : String( v );
}

function DiffTable( { before, after }: { before: Partial<DnsRecord> | null; after: Partial<DnsRecord> | null } ): JSX.Element {
	const rows = FIELDS.filter( ( f ) => {
		if ( ( f.key === 'priority' ) && ( before?.type ?? after?.type ) !== 'MX' ) {
			return false;
		}
		if ( f.key === 'proxied' && ! [ 'A', 'AAAA', 'CNAME' ].includes( String( before?.type ?? after?.type ) ) ) {
			return false;
		}
		return fieldText( before, f.key ) !== '' || fieldText( after, f.key ) !== '';
	} );
	return (
		<table className="uxs-dns-diff">
			<tbody>
				{ rows.map( ( f ) => {
					const o = fieldText( before, f.key );
					const n = fieldText( after, f.key );
					const changed = before !== null && after !== null && o !== n;
					return (
						<tr key={ f.key } className={ changed || before === null || after === null ? 'is-changed' : undefined }>
							<th>{ f.label }</th>
							<td className="uxs-dns-mono">
								{ before !== null && after !== null && changed ? (
									<>
										<span className="is-old">{ o || '-' }</span> → <span className="is-new">{ n || '-' }</span>
									</>
								) : (
									n || o
								) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

function Warnings( { risk, zone, kind }: { risk: Risk[]; zone: string; kind: Pending[ 'kind' ] } ): JSX.Element {
	return (
		<>
			{ risk.includes( 'mx' ) && (
				<div className="uxs-dns-danger">
					<strong>{ __( 'E-mail delivery', 'ux-studio' ) }</strong>
					<p style={ { margin: '4px 0 0' } }>
						{ sprintf(
							/* translators: %s: domain name */
							__( 'Changing or deleting an MX record redirects or stops the delivery of e-mails to @%s.', 'ux-studio' ),
							zone
						) }
					</p>
				</div>
			) }
			{ risk.includes( 'email_auth' ) && (
				<div className="uxs-dns-danger">
					<strong>{ __( 'SPF / DKIM / DMARC', 'ux-studio' ) }</strong>
					<p style={ { margin: '4px 0 0' } }>{ __( 'Sent e-mails may start landing in spam or be rejected.', 'ux-studio' ) }</p>
				</div>
			) }
			{ risk.includes( 'service' ) && (
				<div className="uxs-dns-danger">
					<strong>{ __( 'Service on a subdomain', 'ux-studio' ) }</strong>
					<p style={ { margin: '4px 0 0' } }>
						{ kind === 'delete'
							? __( 'The service running on this subdomain will stop responding.', 'ux-studio' )
							: __( 'If a certificate or service runs on this subdomain, it may stop responding after the change.', 'ux-studio' ) }
					</p>
				</div>
			) }
		</>
	);
}

function ConfirmDialog( {
	pending,
	zone,
	busy,
	error,
	onConfirm,
	onCancel,
	onRefresh,
}: {
	pending: Pending;
	zone: string;
	busy: boolean;
	error: unknown;
	onConfirm: () => void;
	onCancel: () => void;
	onRefresh: () => void;
} ): JSX.Element {
	const [ understood, setUnderstood ] = useState( false );
	const [ typed, setTyped ] = useState( '' );
	const needsName = pending.requireName !== '';
	const ready = understood && ( ! needsName || typed.trim().toLowerCase() === pending.requireName.toLowerCase() );

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
		<div className="uxs-dns-overlay">
			<div className="uxs-dns-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-dns-confirm-title">
				<h2 id="uxs-dns-confirm-title">{ pending.title }</h2>
				<DiffTable before={ pending.before } after={ pending.after } />
				<Warnings risk={ pending.risk } zone={ zone } kind={ pending.kind } />
				<label style={ { display: 'flex', gap: 8, alignItems: 'flex-start', margin: 'var(--uxs-sp-3) 0' } }>
					<input type="checkbox" checked={ understood } onChange={ ( e ) => setUnderstood( e.target.checked ) } style={ { marginTop: 3 } } />
					<span>{ __( 'I understand that this change will affect the functioning of the website and connected services.', 'ux-studio' ) }</span>
				</label>
				{ needsName && (
					<div className="uxs-form__row" style={ { margin: 'var(--uxs-sp-3) 0' } }>
						<label htmlFor="uxs-dns-typed">
							{ sprintf(
								/* translators: %s: record name the user has to retype */
								__( 'To confirm, type the record name: %s', 'ux-studio' ),
								pending.requireName
							) }
						</label>
						<input id="uxs-dns-typed" type="text" value={ typed } autoComplete="off" onChange={ ( e ) => setTyped( e.target.value ) } style={ { width: '100%' } } />
					</div>
				) }
				{ errorBox( error, onRefresh ) }
				<div className="uxs-dns-actions">
					<button type="button" className="button" disabled={ busy } onClick={ onCancel }>
						{ __( 'Cancel', 'ux-studio' ) }
					</button>
					<button type="button" className="button button-primary" disabled={ ! ready || busy } onClick={ onConfirm }>
						{ busy ? __( 'Saving…', 'ux-studio' ) : __( 'Confirm and save', 'ux-studio' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

function AckDialog( { onAccept, onDismiss, busy, error }: { onAccept: () => void; onDismiss: () => void; busy: boolean; error: unknown } ): JSX.Element {
	return (
		<div className="uxs-dns-overlay">
			<div className="uxs-dns-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-dns-ack-title">
				<h2 id="uxs-dns-ack-title">{ __( 'Before you change DNS', 'ux-studio' ) }</h2>
				<p>{ __( 'DNS records decide where your website, e-mail and other services of the domain lead. A change is applied immediately and a mistake can make the website unreachable or lose e-mails.', 'ux-studio' ) }</p>
				<p>{ __( 'Every change is logged with your name and IP address and the administrators are notified by e-mail. You can revert a change in the history.', 'ux-studio' ) }</p>
				{ errorBox( error ) }
				<div className="uxs-dns-actions">
					<button type="button" className="button" onClick={ onDismiss } disabled={ busy }>
						{ __( 'View only', 'ux-studio' ) }
					</button>
					<button type="button" className="button button-primary" onClick={ onAccept } disabled={ busy }>
						{ __( 'I understand the risks', 'ux-studio' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

/* ------------------------------------------------------------------ */

function RecordForm( {
	draft,
	setDraft,
	zone,
	onSubmit,
	onCancel,
}: {
	draft: Draft;
	setDraft: ( d: Draft ) => void;
	zone: string;
	onSubmit: () => void;
	onCancel: () => void;
} ): JSX.Element {
	const [ localError, setLocalError ] = useState( '' );
	const editing = draft.id !== '';
	const proxyOff = proxyForbidden( draft.type, draft.name, draft.content );
	const set = ( patch: Partial<Draft> ) => setDraft( { ...draft, ...patch } );

	return (
		<div className="uxs-form" style={ { maxWidth: 'none', marginBottom: 'var(--uxs-sp-4)' } }>
			<h3 style={ { marginTop: 0 } }>{ editing ? __( 'Edit record', 'ux-studio' ) : __( 'Add record', 'ux-studio' ) }</h3>
			<div style={ { display: 'flex', flexWrap: 'wrap', gap: 'var(--uxs-sp-3)', alignItems: 'flex-end' } }>
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-type">{ __( 'Type', 'ux-studio' ) }</label>
					<select id="uxs-dns-type" value={ draft.type } disabled={ editing } onChange={ ( e ) => set( { type: e.target.value as RecType, proxied: false } ) }>
						{ WRITABLE.map( ( t ) => (
							<option key={ t } value={ t }>
								{ t }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row" style={ { flex: '1 1 160px' } }>
					<label htmlFor="uxs-dns-name">{ __( 'Name', 'ux-studio' ) }</label>
					<input id="uxs-dns-name" type="text" value={ draft.name } placeholder="@" onChange={ ( e ) => set( { name: e.target.value } ) } />
					<small style={ { color: 'var(--uxs-text-soft)' } }>{ sprintf( /* translators: %s: domain name */ __( '@ = %s, "blog" = blog.%s', 'ux-studio' ), zone, zone ) }</small>
				</div>
				<div className="uxs-form__row" style={ { flex: '2 1 260px' } }>
					<label htmlFor="uxs-dns-content">{ __( 'Value', 'ux-studio' ) }</label>
					<input id="uxs-dns-content" type="text" value={ draft.content } onChange={ ( e ) => set( { content: e.target.value } ) } />
				</div>
				{ draft.type === 'MX' && (
					<div className="uxs-form__row" style={ { width: 100 } }>
						<label htmlFor="uxs-dns-prio">{ __( 'Priority', 'ux-studio' ) }</label>
						<input id="uxs-dns-prio" type="number" min={ 0 } max={ 65535 } value={ draft.priority } onChange={ ( e ) => set( { priority: e.target.value } ) } />
					</div>
				) }
				<div className="uxs-form__row">
					<label htmlFor="uxs-dns-ttl">{ __( 'TTL', 'ux-studio' ) }</label>
					<select id="uxs-dns-ttl" value={ draft.proxied ? 1 : draft.ttl } disabled={ draft.proxied } onChange={ ( e ) => set( { ttl: Number( e.target.value ) } ) }>
						{ TTL_OPTIONS.map( ( t ) => (
							<option key={ t } value={ t }>
								{ ttlLabel( t ) }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row" style={ { flex: '1 1 160px' } }>
					<label htmlFor="uxs-dns-comment">{ __( 'Note', 'ux-studio' ) }</label>
					<input id="uxs-dns-comment" type="text" maxLength={ 100 } value={ draft.comment } onChange={ ( e ) => set( { comment: e.target.value } ) } />
				</div>
			</div>
			{ [ 'A', 'AAAA', 'CNAME' ].includes( draft.type ) && (
				<label style={ { display: 'block', marginTop: 'var(--uxs-sp-3)' } }>
					<input type="checkbox" checked={ draft.proxied && ! proxyOff } disabled={ proxyOff } onChange={ ( e ) => set( { proxied: e.target.checked } ) } />{ ' ' }
					{ __( 'Proxied through Cloudflare (orange cloud)', 'ux-studio' ) }
					{ proxyOff && <small style={ { color: 'var(--uxs-text-soft)' } }> - { __( 'not available for this record', 'ux-studio' ) }</small> }
				</label>
			) }
			{ localError && (
				<div className="uxs-dns-danger" role="alert">
					{ localError }
				</div>
			) }
			<div className="uxs-dns-actions" style={ { justifyContent: 'flex-start' } }>
				<button
					type="button"
					className="button button-primary"
					onClick={ () => {
						const msg = validateDraft( draft );
						setLocalError( msg );
						if ( msg === '' ) {
							onSubmit();
						}
					} }
				>
					{ __( 'Review change…', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'ux-studio' ) }
				</button>
			</div>
		</div>
	);
}

function PropagationNotice( { result }: { result: WriteResult } ): JSX.Element | null {
	const rec = result.record ?? result.before;
	if ( ! rec ) {
		return null;
	}
	return (
		<div className="uxs-notice" role="status" style={ { maxWidth: 'none', marginBottom: 'var(--uxs-sp-4)' } }>
			<strong>{ __( 'Change saved.', 'ux-studio' ) }</strong>{ ' ' }
			{ rec.proxied
				? __( 'The record is proxied through Cloudflare, so the change applies immediately.', 'ux-studio' )
				: sprintf(
						/* translators: %s: TTL, e.g. "300 s" or "Auto" */
						__( 'The change appears after the TTL expires (%s). Computers and networks may keep the old value in their cache for a while.', 'ux-studio' ),
						ttlLabel( rec.ttl )
				  ) }
			{ ( result.warnings ?? [] ).map( ( w ) => (
				<div key={ w.code } style={ { marginTop: 4 } }>
					<span className="uxs-badge is-warning">{ __( 'Notice', 'ux-studio' ) }</span> { w.message }
				</div>
			) ) }
		</div>
	);
}

function RecordsTab( { status, zone }: { status: DnsStatus; zone: string } ): JSX.Element {
	const canEdit = status.can_manage && status.acknowledged;
	const [ typeFilter, setTypeFilter ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ draft, setDraft ] = useState< Draft | null >( null );
	const [ pending, setPending ] = useState< Pending | null >( null );
	const [ saved, setSaved ] = useState< WriteResult | null >( null );

	const records = useQuery( {
		queryKey: [ 'dns', 'records' ],
		queryFn: () => api< RecordsPayload >( 'dns/records' ),
	} );

	const refresh = () => {
		setSaved( null );
		void api< RecordsPayload >( 'dns/records?fresh=1' ).then( ( data ) => {
			queryClient.setQueryData( [ 'dns', 'records' ], data );
		} );
		setPending( null );
	};

	const run = useMutation( {
		mutationFn: ( p: Pending ) => p.run(),
		onSuccess: ( data ) => {
			setSaved( data.results[ 0 ] ?? null );
			setPending( null );
			setDraft( null );
			void queryClient.invalidateQueries( { queryKey: [ 'dns' ] } );
		},
	} );

	const list = records.data?.records ?? [];
	const types = useMemo( () => Array.from( new Set( list.map( ( r ) => r.type ) ) ).sort(), [ list ] );
	const shown = list.filter(
		( r ) =>
			( typeFilter === '' || r.type === typeFilter ) &&
			( search === '' || `${ r.name } ${ r.content }`.toLowerCase().includes( search.toLowerCase() ) )
	);

	const toBody = ( d: Draft ) => ( {
		type: d.type,
		name: d.name.trim() === '' ? '@' : d.name.trim(),
		content: d.content.trim(),
		ttl: d.proxied ? 1 : d.ttl,
		proxied: d.proxied && ! proxyForbidden( d.type, d.name, d.content ),
		priority: d.type === 'MX' ? Number( d.priority ) : null,
		comment: d.comment,
	} );

	const fullName = ( n: string ) => {
		const t = n.trim();
		return t === '' || t === '@' ? zone : t === zone || t.endsWith( `.${ zone }` ) ? t : `${ t }.${ zone }`;
	};

	const submitDraft = () => {
		if ( ! draft ) {
			return;
		}
		const body = toBody( draft );
		const after: Partial<DnsRecord> = { ...body, name: fullName( body.name ), priority: body.priority };
		const before = draft.id ? list.find( ( r ) => r.id === draft.id ) ?? null : null;
		run.reset();
		setPending( {
			kind: draft.id ? 'update' : 'create',
			title: draft.id ? __( 'Confirm the change of the record', 'ux-studio' ) : __( 'Confirm the new record', 'ux-studio' ),
			before,
			after,
			risk: Array.from( new Set( [ ...riskOf( before, zone ), ...riskOf( after, zone ) ] ) ),
			requireName: '',
			run: () =>
				draft.id
					? api< WritePayload >( `dns/records/${ draft.id }`, { method: 'POST', body: JSON.stringify( { record: body, modified_on: draft.modified_on } ) } )
					: api< WritePayload >( 'dns/records', { method: 'POST', body: JSON.stringify( { record: body } ) } ),
		} );
	};

	const askDelete = ( rec: DnsRecord ) => {
		const lastAddress =
			[ 'A', 'AAAA' ].includes( rec.type ) && rec.name !== zone && list.filter( ( r ) => r.name === rec.name && [ 'A', 'AAAA' ].includes( r.type ) ).length === 1;
		run.reset();
		setPending( {
			kind: 'delete',
			title: __( 'Confirm deleting the record', 'ux-studio' ),
			before: rec,
			after: null,
			risk: riskOf( rec, zone ),
			requireName: rec.type === 'MX' || lastAddress ? rec.name : '',
			run: () => api< WritePayload >( `dns/records/${ rec.id }`, { method: 'DELETE', body: JSON.stringify( { modified_on: rec.modified_on } ) } ),
		} );
	};

	const startEdit = ( rec: DnsRecord ) => {
		setSaved( null );
		setDraft( {
			id: rec.id,
			type: rec.type,
			name: shortName( rec.name, zone ),
			content: rec.content,
			priority: rec.priority === null ? '10' : String( rec.priority ),
			ttl: rec.ttl,
			proxied: rec.proxied,
			comment: rec.comment,
			modified_on: rec.modified_on,
		} );
	};

	return (
		<>
			{ saved && <PropagationNotice result={ saved } /> }
			{ draft && <RecordForm draft={ draft } setDraft={ setDraft } zone={ zone } onSubmit={ submitDraft } onCancel={ () => setDraft( null ) } /> }
			<div className="uxs-dns-toolbar">
				<select aria-label={ __( 'Filter by type', 'ux-studio' ) } value={ typeFilter } onChange={ ( e ) => setTypeFilter( e.target.value ) }>
					<option value="">{ __( 'All types', 'ux-studio' ) }</option>
					{ types.map( ( t ) => (
						<option key={ t } value={ t }>
							{ t }
						</option>
					) ) }
				</select>
				<input type="search" value={ search } placeholder={ __( 'Search name or value…', 'ux-studio' ) } onChange={ ( e ) => setSearch( e.target.value ) } />
				<button type="button" className="button" onClick={ refresh }>
					<RefreshCw size={ 14 } /> { __( 'Reload', 'ux-studio' ) }
				</button>
				{ canEdit && ! draft && (
					<button
						type="button"
						className="button button-primary"
						onClick={ () => {
							setSaved( null );
							setDraft( EMPTY_DRAFT );
						} }
					>
						<Plus size={ 14 } /> { __( 'Add record', 'ux-studio' ) }
					</button>
				) }
			</div>
			{ ! canEdit && (
				<p style={ { color: 'var(--uxs-text-soft)' } }>
					{ status.can_manage
						? __( 'You are in view-only mode until you confirm that you understand the risks.', 'ux-studio' )
						: __( 'You can view the records. Editing is allowed only to administrators selected in the module settings.', 'ux-studio' ) }
				</p>
			) }
			{ records.isLoading && <Loading /> }
			{ records.isError && errorBox( records.error ) }
			{ records.data && shown.length === 0 && <p>{ __( 'No records found.', 'ux-studio' ) }</p> }
			{ shown.length > 0 && (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Name', 'ux-studio' ) }</th>
							<th>{ __( 'Value', 'ux-studio' ) }</th>
							<th>{ __( 'TTL', 'ux-studio' ) }</th>
							<th>{ __( 'Proxy', 'ux-studio' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ shown.map( ( r ) => {
							const blocked = r.locked || r.read_only;
							return (
								<tr key={ r.id }>
									<td>
										<span className="uxs-badge is-info">{ r.type }</span>
									</td>
									<td className="uxs-dns-mono">
										{ shortName( r.name, zone ) }
										{ r.comment && <div style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>{ r.comment }</div> }
									</td>
									<td className="uxs-dns-mono">
										{ r.type === 'MX' && r.priority !== null && <span className="uxs-badge is-info">{ r.priority }</span> } { r.content }
									</td>
									<td>{ ttlLabel( r.ttl ) }</td>
									<td>{ r.proxied ? <span className="uxs-badge is-warning">{ __( 'on', 'ux-studio' ) }</span> : '' }</td>
									<td style={ { whiteSpace: 'nowrap', textAlign: 'right' } }>
										{ r.locked && (
											<span className="uxs-badge is-warning" title={ r.lock_reason ?? '' }>
												<Lock size={ 12 } /> { __( 'Locked', 'ux-studio' ) }
											</span>
										) }
										{ r.read_only && ! r.locked && <span className="uxs-badge is-info">{ __( 'Read only', 'ux-studio' ) }</span> }{ ' ' }
										{ canEdit && ! blocked && (
											<>
												<button type="button" className="button button-small" aria-label={ __( 'Edit', 'ux-studio' ) } onClick={ () => startEdit( r ) }>
													<Pencil size={ 14 } />
												</button>{ ' ' }
												<button type="button" className="button button-small" aria-label={ __( 'Delete', 'ux-studio' ) } onClick={ () => askDelete( r ) }>
													<Trash2 size={ 14 } />
												</button>
											</>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
			{ pending && (
				<ConfirmDialog pending={ pending } zone={ zone } busy={ run.isPending } error={ run.error } onConfirm={ () => run.mutate( pending ) } onCancel={ () => setPending( null ) } onRefresh={ refresh } />
			) }
		</>
	);
}

/* ------------------------------------------------------------------ */

const ACTION_LABELS: Record<string, string> = {
	create: __( 'Added', 'ux-studio' ),
	update: __( 'Changed', 'ux-studio' ),
	delete: __( 'Deleted', 'ux-studio' ),
	revert: __( 'Reverted', 'ux-studio' ),
	denied: __( 'Denied', 'ux-studio' ),
	acknowledged: __( 'Risks acknowledged', 'ux-studio' ),
};

function HistoryTab( { status, zone }: { status: DnsStatus; zone: string } ): JSX.Element {
	const canEdit = status.can_manage && status.acknowledged;
	const [ userId, setUserId ] = useState( '' );
	const [ action, setAction ] = useState( '' );
	const [ logStatus, setLogStatus ] = useState( '' );
	const [ from, setFrom ] = useState( '' );
	const [ to, setTo ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ pending, setPending ] = useState< Pending | null >( null );

	const qs = new URLSearchParams( { page_no: String( page ), per_page: String( PAGE_SIZE ) } );
	if ( userId ) {
		qs.set( 'user_id', userId );
	}
	if ( action ) {
		qs.set( 'log_action', action );
	}
	if ( logStatus ) {
		qs.set( 'log_status', logStatus );
	}
	if ( from ) {
		qs.set( 'from', from );
	}
	if ( to ) {
		qs.set( 'to', to );
	}
	const log = useQuery( {
		queryKey: [ 'dns', 'log', qs.toString() ],
		queryFn: () => api< LogPayload >( `dns/log?${ qs.toString() }` ),
	} );

	const run = useMutation( {
		mutationFn: ( p: Pending ) => p.run(),
		onSuccess: () => {
			setPending( null );
			void queryClient.invalidateQueries( { queryKey: [ 'dns' ] } );
		},
	} );

	const askRevert = ( row: LogRow ) => {
		run.reset();
		setPending( {
			kind: 'revert',
			title: __( 'Confirm reverting the change', 'ux-studio' ),
			before: row.after,
			after: row.before,
			risk: Array.from( new Set( [ ...riskOf( row.before, zone ), ...riskOf( row.after, zone ) ] ) ),
			requireName: '',
			run: () => api< WritePayload >( 'dns/revert', { method: 'POST', body: JSON.stringify( { log_id: row.id } ) } ),
		} );
	};

	const rows = log.data?.rows ?? [];
	const total = log.data?.total ?? 0;
	const reset = ( fn: () => void ) => {
		setPage( 1 );
		fn();
	};

	return (
		<>
			{ log.data?.source === 'local' && (
				<div className="uxs-notice" style={ { maxWidth: 'none', marginBottom: 'var(--uxs-sp-3)' } }>
					{ __( 'The central app is not reachable, showing the local copy of the history.', 'ux-studio' ) }
				</div>
			) }
			<div className="uxs-dns-toolbar">
				<select aria-label={ __( 'User', 'ux-studio' ) } value={ userId } onChange={ ( e ) => reset( () => setUserId( e.target.value ) ) }>
					<option value="">{ __( 'All users', 'ux-studio' ) }</option>
					{ ( log.data?.users ?? [] ).map( ( u ) => (
						<option key={ u.id } value={ u.id }>
							{ u.name || u.login }
						</option>
					) ) }
				</select>
				<select aria-label={ __( 'Action', 'ux-studio' ) } value={ action } onChange={ ( e ) => reset( () => setAction( e.target.value ) ) }>
					<option value="">{ __( 'All actions', 'ux-studio' ) }</option>
					{ Object.entries( ACTION_LABELS ).map( ( [ k, label ] ) => (
						<option key={ k } value={ k }>
							{ label }
						</option>
					) ) }
				</select>
				<select aria-label={ __( 'Result', 'ux-studio' ) } value={ logStatus } onChange={ ( e ) => reset( () => setLogStatus( e.target.value ) ) }>
					<option value="">{ __( 'Any result', 'ux-studio' ) }</option>
					<option value="success">{ __( 'Success', 'ux-studio' ) }</option>
					<option value="error">{ __( 'Error', 'ux-studio' ) }</option>
					<option value="conflict">{ __( 'Conflict', 'ux-studio' ) }</option>
					<option value="denied">{ __( 'Denied', 'ux-studio' ) }</option>
				</select>
				<input type="date" aria-label={ __( 'From', 'ux-studio' ) } value={ from } onChange={ ( e ) => reset( () => setFrom( e.target.value ) ) } />
				<input type="date" aria-label={ __( 'To', 'ux-studio' ) } value={ to } onChange={ ( e ) => reset( () => setTo( e.target.value ) ) } />
			</div>
			{ log.isLoading && <Loading /> }
			{ log.isError && errorBox( log.error ) }
			{ log.data && rows.length === 0 && <p>{ __( 'No changes recorded.', 'ux-studio' ) }</p> }
			{ rows.length > 0 && (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'When', 'ux-studio' ) }</th>
							<th>{ __( 'Who', 'ux-studio' ) }</th>
							<th>{ __( 'Action', 'ux-studio' ) }</th>
							<th>{ __( 'Change', 'ux-studio' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( r ) => {
							const ok = r.status === 'success';
							const revertable = ok && [ 'create', 'update', 'delete', 'revert' ].includes( r.action ) && ! r.reverted_by && log.data?.source === 'central';
							return (
								<tr key={ r.id } style={ ok ? undefined : { opacity: 0.85 } }>
									<td style={ { whiteSpace: 'nowrap' } }>{ formatDate( r.created_at, { utc: true } ) }</td>
									<td>{ r.wp_user_name || r.wp_user_login }</td>
									<td>
										<span className={ `uxs-badge ${ ok ? 'is-success' : r.status === 'denied' ? 'is-warning' : 'is-danger' }` }>{ ACTION_LABELS[ r.action ] ?? r.action }</span>
										{ r.reverted_by ? <div style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>{ __( 'reverted', 'ux-studio' ) }</div> : null }
									</td>
									<td className="uxs-dns-mono" style={ { minWidth: 260 } }>
										{ r.error && <div style={ { color: 'var(--uxs-danger)' } }>{ r.error }</div> }
										{ ( r.before || r.after ) && <DiffTable before={ r.before } after={ r.after } /> }
									</td>
									<td>
										{ canEdit && revertable && (
											<button type="button" className="button button-small" onClick={ () => askRevert( r ) }>
												<Undo2 size={ 14 } /> { __( 'Revert', 'ux-studio' ) }
											</button>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			) }
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button" disabled={ page === 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
					{ __( 'Previous', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ page * PAGE_SIZE >= total } onClick={ () => setPage( ( p ) => p + 1 ) }>
					{ __( 'Next', 'ux-studio' ) }
				</button>
			</div>
			{ pending && (
				<ConfirmDialog
					pending={ pending }
					zone={ zone }
					busy={ run.isPending }
					error={ run.error }
					onConfirm={ () => run.mutate( pending ) }
					onCancel={ () => setPending( null ) }
					onRefresh={ () => setPending( null ) }
				/>
			) }
		</>
	);
}

/* ------------------------------------------------------------------ */

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'records' );
	const [ dismissed, setDismissed ] = useState( false );
	const settings = useModuleSettings( 'dns-manager' );

	const status = useQuery( {
		queryKey: [ 'dns', 'status' ],
		queryFn: () => api< DnsStatus >( 'dns/status' ),
	} );
	const ack = useMutation( {
		mutationFn: () => api( 'dns/ack', { method: 'POST', body: '{}' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'dns', 'status' ] } ),
	} );

	const s = status.data;
	const zone = s?.zone ?? '';
	const inactiveText: Record<string, string> = {
		broken: __( 'The connection to Cloudflare is interrupted. Please contact us.', 'ux-studio' ),
		ca_unreachable: __( 'The central app is not reachable right now. Try again later.', 'ux-studio' ),
		not_configured: __( 'DNS management is not active for this site.', 'ux-studio' ),
	};

	const tabs: [ Tab, string ][] = [
		[ 'records', __( 'Records', 'ux-studio' ) ],
		[ 'history', __( 'Change history', 'ux-studio' ) ],
		[ 'settings', __( 'Settings', 'ux-studio' ) ],
	];

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
					{ __( 'DNS Management', 'ux-studio' ) }
					{ zone && <small style={ { color: 'var(--uxs-text-soft)', marginLeft: 8 } }>{ zone }</small> }
				</h1>
			</header>
			<Banner />
			<TabsNav>
				{ tabs.map( ( [ id, label ] ) => (
					<button key={ id } className={ tab === id ? 'is-active' : '' } onClick={ () => setTab( id ) }>
						{ label }
					</button>
				) ) }
			</TabsNav>
			{ status.isLoading && <Loading /> }
			{ status.isError && errorBox( status.error ) }
			{ s && tab !== 'settings' && ! s.active && (
				<div className="uxs-notice" role="status">
					<p>{ inactiveText[ s.reason ?? '' ] ?? inactiveText.not_configured }</p>
				</div>
			) }
			{ s && s.active && tab === 'records' && <RecordsTab status={ s } zone={ zone } /> }
			{ s && s.active && tab === 'history' && <HistoryTab status={ s } zone={ zone } /> }
			{ tab === 'settings' && ( settings.isLoading || ! settings.data ) && <Loading /> }
			{ tab === 'settings' && settings.data && (
				<>
					<SettingsFields schema={ settings.data.schema } draft={ settings.draft } setDraft={ settings.setDraft } />
					<button type="button" className="button button-primary" onClick={ () => settings.save.mutate() }>
						{ settings.saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
			{ s && s.active && s.can_manage && ! s.acknowledged && ! dismissed && (
				<AckDialog busy={ ack.isPending } error={ ack.error } onAccept={ () => ack.mutate() } onDismiss={ () => setDismissed( true ) } />
			) }
		</>
	);
}
