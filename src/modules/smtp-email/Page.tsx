import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, Download, LoaderCircle, Send, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TopicSettings, withKeys } from '../../app/TopicSettings';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';

type Tab = 'settings' | 'log';

interface SmtpLog {
	id: number;
	created_at: string;
	to_email: string;
	subject: string;
	status: string;
	error: string | null;
	mailer?: string;
	attempt?: string;
}

/** Names of the transports as the log shows them (`wp` = the WordPress default transport, used when nothing was set up). */
function mailerName( id: string ): string {
	const names: Record< string, string > = {
		smtp: __( 'SMTP', 'ux-studio' ),
		brevo: __( 'Brevo API', 'ux-studio' ),
		gmail: __( 'Gmail API (OAuth)', 'ux-studio' ),
		sendgrid: __( 'SendGrid API', 'ux-studio' ),
		mailgun: __( 'Mailgun API', 'ux-studio' ),
		postmark: __( 'Postmark API', 'ux-studio' ),
		ses: __( 'Amazon SES API', 'ux-studio' ),
		wp: __( 'WordPress default', 'ux-studio' ),
	};
	return names[ id ] ?? id;
}

interface TestResult {
	success: boolean;
	to: string;
	error: string;
}

interface BrevoStatus {
	state: string;
	account: string;
	error: string;
	checked: string;
}

function BrevoVerify( { hasKey, stored }: { hasKey: boolean; stored?: BrevoStatus } ): JSX.Element {
	const verify = useMutation( {
		mutationFn: () => api< BrevoStatus >( 'smtp-email/brevo/verify', { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'settings', 'smtp-email' ] } ),
	} );
	const status = verify.data ?? ( stored && stored.state ? stored : undefined );

	return (
		<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
			<p className="uxs-form__help">
				{ __( 'The sender address (From email) must be a verified sender or belong to a verified domain in Brevo.', 'ux-studio' ) }
			</p>
			<button type="button" className="button" disabled={ ! hasKey || verify.isPending } onClick={ () => verify.mutate() }>
				{ __( 'Verify API key', 'ux-studio' ) }
			</button>{ ' ' }
			{ ! hasKey && (
				<span className="uxs-form__help">{ __( 'Enter the API key and save the settings first.', 'ux-studio' ) }</span>
			) }
			{ verify.error && <p className="uxs-badge is-danger">{ ( verify.error as Error ).message }</p> }
			{ status && (
				<p>
					{ status.state === 'ok' ? (
						<span className="uxs-badge is-success">
							<Check size={ 12 } /> { __( 'API key is valid. Account:', 'ux-studio' ) } { status.account || '—' }
						</span>
					) : (
						<span className="uxs-badge is-danger">
							{ __( 'Verification failed:', 'ux-studio' ) } { status.error }
						</span>
					) }
					{ status.checked ? <span className="uxs-form__help"> { status.checked }</span> : null }
				</p>
			) }
		</div>
	);
}

/** Test send through exactly one connection (saved settings, no fallback). */
function MailerTest( { mailer, ready, to }: { mailer: string; ready: boolean; to: string } ): JSX.Element {
	const test = useMutation( {
		mutationFn: () =>
			api< TestResult >( 'smtp-email/test', {
				method: 'POST',
				body: JSON.stringify( to ? { to, mailer } : { mailer } ),
			} ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'smtp-email', 'logs' ] } ),
	} );
	return (
		<div className="uxs-mailer-test">
			<button type="button" className="button" disabled={ ! ready || test.isPending } onClick={ () => test.mutate() }>
				<Send size={ 14 } /> { __( 'Send a test email through this connection', 'ux-studio' ) }
			</button>{ ' ' }
			{ ! ready && (
				<span className="uxs-form__help">{ __( 'Fill in and save the settings of this connection first.', 'ux-studio' ) }</span>
			) }
			{ test.error && <p className="uxs-badge is-danger">{ ( test.error as Error ).message }</p> }
			{ test.data && (
				<p>
					{ test.data.success ? (
						<span className="uxs-badge is-success">
							<Check size={ 12 } /> { __( 'Sent to', 'ux-studio' ) } { test.data.to }
						</span>
					) : (
						<span className="uxs-badge is-danger">
							{ __( 'Failed:', 'ux-studio' ) } { test.data.error || __( 'Unknown error', 'ux-studio' ) }
						</span>
					) }
				</p>
			) }
		</div>
	);
}

/** Connect / disconnect of the Gmail OAuth account (full-page redirects handled by the module). */
function GmailConnect( { draft }: { draft: Record< string, unknown > } ): JSX.Element {
	return (
		<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-3)' } }>
			<p className="uxs-form__help">
				{ __( 'Redirect URI to register in your Google Cloud OAuth client:', 'ux-studio' ) } <code>{ String( draft.gmail_redirect_uri ?? '' ) }</code>
			</p>
			{ draft.gmail_connected ? (
				<p>
					<span className="uxs-badge is-success">
						<Check size={ 12 } /> { __( 'Connected', 'ux-studio' ) }
						{ draft.gmail_email ? ` (${ String( draft.gmail_email ) })` : '' }
					</span>{ ' ' }
					<a className="button" href={ String( draft.gmail_disconnect_url ?? '' ) }>
						{ __( 'Disconnect Gmail', 'ux-studio' ) }
					</a>
				</p>
			) : (
				<p>
					<a className="button" href={ String( draft.gmail_connect_url ?? '' ) }>
						{ __( 'Connect Gmail account', 'ux-studio' ) }
					</a>{ ' ' }
					<span className="uxs-form__help">{ __( 'Save the client ID and secret first, then connect.', 'ux-studio' ) }</span>
				</p>
			) }
			{ draft.gmail_last_error ? <p className="uxs-badge is-danger">{ String( draft.gmail_last_error ) }</p> : null }
		</div>
	);
}

const PER_PAGE = 20;

interface SmtpLogPage {
	items: SmtpLog[];
	total: number;
	page: number;
	per_page: number;
}

function LogTable(): JSX.Element {
	const [ page, setPage ] = useState( 1 );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: [ 'smtp-email', 'logs', page, search ],
		queryFn: () =>
			api< SmtpLogPage >(
				`smtp-email/logs?page=${ page }&per_page=${ PER_PAGE }&search=${ encodeURIComponent( search ) }`
			),
	} );

	const exportUrl = useQuery( {
		queryKey: [ 'smtp-email', 'export-url' ],
		queryFn: () => api< { url: string } >( 'smtp-email/logs/export-url' ),
	} );

	const clear = useMutation( {
		mutationFn: () => api( 'smtp-email/logs/clear', { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => {
			setPage( 1 );
			void queryClient.invalidateQueries( { queryKey: [ 'smtp-email', 'logs' ] } );
		},
	} );

	const totalPages = data ? Math.max( 1, Math.ceil( data.total / PER_PAGE ) ) : 1;

	const toolbar = (
		<div style={ { display: 'flex', gap: '8px', flexWrap: 'wrap', marginBottom: 'var(--uxs-sp-4)' } }>
			<form
				onSubmit={ ( e ) => {
					e.preventDefault();
					setPage( 1 );
					setSearch( searchInput.trim() );
				} }
				style={ { display: 'flex', gap: '8px' } }
			>
				<input
					type="search"
					aria-label={ __( 'Search the log', 'ux-studio' ) }
					placeholder={ __( 'Search recipient, subject or error…', 'ux-studio' ) }
					value={ searchInput }
					onChange={ ( e ) => setSearchInput( e.target.value ) }
				/>
				<button type="submit" className="button">
					{ __( 'Search', 'ux-studio' ) }
				</button>
			</form>
			{ exportUrl.data && (
				<a className="button" href={ exportUrl.data.url }>
					<Download size={ 14 } /> { __( 'Download CSV', 'ux-studio' ) }
				</a>
			) }
			<button
				type="button"
				className="button"
				disabled={ clear.isPending || ! data || data.total === 0 }
				onClick={ () => {
					if ( window.confirm( __( 'Are you sure you want to clear all email logs? This action cannot be undone.', 'ux-studio' ) ) ) {
						clear.mutate();
					}
				} }
			>
				<Trash2 size={ 14 } /> { __( 'Clear logs', 'ux-studio' ) }
			</button>
			<button
				type="button"
				className="button-link"
				onClick={ () => navigate( 'module', { id: 'email-log' } ) }
			>
				{ __( 'Message bodies, headers and per-entry resend: Email Log module', 'ux-studio' ) }
			</button>
		</div>
	);

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.items.length === 0 ) {
		return (
			<>
				{ toolbar }
				<p>
					{ search
						? __( 'No log entries match your search.', 'ux-studio' )
						: __( 'No mail has been sent yet.', 'ux-studio' ) }
				</p>
			</>
		);
	}

	return (
		<>
			{ toolbar }
			<div className="uxs-table-wrap"><table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Date', 'ux-studio' ) }</th>
						<th>{ __( 'To', 'ux-studio' ) }</th>
						<th>{ __( 'Subject', 'ux-studio' ) }</th>
						<th>{ __( 'Connection', 'ux-studio' ) }</th>
						<th>{ __( 'Status', 'ux-studio' ) }</th>
						<th>{ __( 'Error', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.items.map( ( row ) => (
						<tr key={ row.id }>
							<td>{ formatDate( row.created_at ) }</td>
							<td>{ row.to_email }</td>
							<td>{ row.subject }</td>
							<td>
								{ row.mailer ? mailerName( row.mailer ) : '' }
								{ row.attempt === 'fallback' && <span className="uxs-badge"> { __( 'Fallback', 'ux-studio' ) }</span> }
								{ row.attempt === 'test' && <span className="uxs-badge"> { __( 'Test', 'ux-studio' ) }</span> }
							</td>
							<td>
								<span className={ `uxs-badge ${ row.status === 'success' ? 'is-success' : 'is-danger' }` }>
									{ row.status === 'success' ? __( 'Sent', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) }
								</span>
							</td>
							<td>{ row.error ?? '' }</td>
						</tr>
					) ) }
				</tbody>
			</table></div>
			<p style={ { display: 'flex', gap: '8px', alignItems: 'center' } }>
				<button type="button" className="button" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
					{ __( 'Previous', 'ux-studio' ) }
				</button>
				<span>
					{ page } / { totalPages } ({ data.total })
				</span>
				<button type="button" className="button" disabled={ page >= totalPages } onClick={ () => setPage( page + 1 ) }>
					{ __( 'Next', 'ux-studio' ) }
				</button>
			</p>
		</>
	);
}

/** Settings of the e-mail log - shown with the log, not in the sending settings. */
const isLogKey = withKeys( 'enable_email_logs', 'retention_days' );

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'settings' );
	const [ testTo, setTestTo ] = useState( '' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'smtp-email' );

	const test = useMutation( {
		mutationFn: () =>
			api< TestResult >( 'smtp-email/test', {
				method: 'POST',
				body: JSON.stringify( testTo ? { to: testTo } : {} ),
			} ),
	} );

	const resend = useMutation( {
		mutationFn: () =>
			api< TestResult >( 'smtp-email/resend', {
				method: 'POST',
				body: JSON.stringify( {} ),
			} ),
	} );

	const ready = ( draft.mailers_ready ?? {} ) as unknown as Record< string, boolean >;

	/** Whether a secret is stored (never its value). */
	const secretNote = ( key: string ): JSX.Element => (
		<p className="uxs-form__help">
			{ draft[ `has_${ key }` ] ? (
				<span className="uxs-badge is-success">
					<Check size={ 12 } /> { __( 'Saved. It is stored encrypted and never shown again.', 'ux-studio' ) }
				</span>
			) : (
				<span className="uxs-badge">{ __( 'Not set yet.', 'ux-studio' ) }</span>
			) }
		</p>
	);

	/** A warning under the mailer / fallback choice when the chosen connection is not set up. */
	const notReady = ( id: string, text: string ): JSX.Element | null =>
		id && id !== 'none' && ! ready[ id ] ? <p className="uxs-badge is-danger">{ text }</p> : null;

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
					{ __( 'SMTP Email', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Delivery log', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields
						schema={ data.schema.filter( ( f ) => ! isLogKey( f ) ) }
						draft={ draft }
						setDraft={ setDraft }
						notes={ {
							mailer: notReady( String( draft.mailer ?? '' ), __( 'This connection is not set up yet. Until it is, WordPress sends the mail its own way (or through the fallback).', 'ux-studio' ) ),
							fallback_mailer: notReady( String( draft.fallback_mailer ?? '' ), __( 'The fallback connection is not set up yet, so it cannot take over.', 'ux-studio' ) ),
							password: (
								<>
									{ secretNote( 'password' ) }
									<MailerTest mailer="smtp" ready={ Boolean( ready.smtp ) } to={ testTo } />
								</>
							),
							brevo_api_key: (
								<>
									{ secretNote( 'brevo_api_key' ) }
									<BrevoVerify hasKey={ Boolean( draft.has_brevo_api_key ) } stored={ draft.brevo_status as BrevoStatus | undefined } />
									<MailerTest mailer="brevo" ready={ Boolean( ready.brevo ) } to={ testTo } />
								</>
							),
							gmail_client_secret: (
								<>
									{ secretNote( 'gmail_client_secret' ) }
									<GmailConnect draft={ draft } />
									<MailerTest mailer="gmail" ready={ Boolean( ready.gmail ) } to={ testTo } />
								</>
							),
							sendgrid_api_key: secretNote( 'sendgrid_api_key' ),
							sendgrid_region: <MailerTest mailer="sendgrid" ready={ Boolean( ready.sendgrid ) } to={ testTo } />,
							mailgun_api_key: secretNote( 'mailgun_api_key' ),
							mailgun_region: <MailerTest mailer="mailgun" ready={ Boolean( ready.mailgun ) } to={ testTo } />,
							postmark_server_token: secretNote( 'postmark_server_token' ),
							postmark_message_stream: <MailerTest mailer="postmark" ready={ Boolean( ready.postmark ) } to={ testTo } />,
							ses_access_key: secretNote( 'ses_access_key' ),
							ses_secret_key: secretNote( 'ses_secret_key' ),
							ses_region: <MailerTest mailer="ses" ready={ Boolean( ready.ses ) } to={ testTo } />,
						} }
					/>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>

					<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
						<div className="uxs-form__row">
							<label htmlFor="uxs-smtp-test-to">{ __( 'Send a test email to', 'ux-studio' ) }</label>
							<input
								id="uxs-smtp-test-to"
								type="text"
								placeholder={ __( 'Defaults to the site admin email', 'ux-studio' ) }
								value={ testTo }
								onChange={ ( e ) => setTestTo( e.target.value ) }
							/>
							<p className="uxs-form__help">
								{ __( 'The first button sends the way the site sends (mailer, then fallback); each tab has its own button for one connection.', 'ux-studio' ) }
							</p>
						</div>
						<button type="button" className="button" disabled={ test.isPending } onClick={ () => test.mutate() }>
							<Send size={ 14 } /> { __( 'Send test email', 'ux-studio' ) }
						</button>
						{ ' ' }
						<button type="button" className="button" disabled={ resend.isPending || ! draft.has_last_message } onClick={ () => resend.mutate() }>
							<Send size={ 14 } /> { __( 'Resend last message', 'ux-studio' ) }
						</button>
						{ test.data && (
							<p>
								{ test.data.success ? (
									<span className="uxs-badge is-success">
										<Check size={ 12 } /> { __( 'Sent to', 'ux-studio' ) } { test.data.to }
									</span>
								) : (
									<span className="uxs-badge is-danger">
										{ __( 'Failed:', 'ux-studio' ) } { test.data.error || __( 'Unknown error', 'ux-studio' ) }
									</span>
								) }
							</p>
						) }
						{ resend.data && (
							<p>
								{ resend.data.success ? (
									<span className="uxs-badge is-success">
										<Check size={ 12 } /> { __( 'Resent to', 'ux-studio' ) } { resend.data.to }
									</span>
								) : (
									<span className="uxs-badge is-danger">
										{ __( 'Failed:', 'ux-studio' ) } { resend.data.error || __( 'Unknown error', 'ux-studio' ) }
									</span>
								) }
							</p>
						) }
					</div>
				</>
			) }
			{ tab === 'log' && (
				<>
					<TopicSettings moduleId="smtp-email" include={ isLogKey } collapsible={ __( 'Log settings', 'ux-studio' ) } />
					<LogTable />
				</>
			) }
		</>
	);
}
