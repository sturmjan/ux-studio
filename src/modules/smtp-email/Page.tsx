import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, Download, LoaderCircle, Send, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';

type Tab = 'settings' | 'log';

interface SmtpLog {
	id: number;
	created_at: string;
	to_email: string;
	subject: string;
	status: string;
	error: string | null;
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
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Date', 'ux-studio' ) }</th>
						<th>{ __( 'To', 'ux-studio' ) }</th>
						<th>{ __( 'Subject', 'ux-studio' ) }</th>
						<th>{ __( 'Status', 'ux-studio' ) }</th>
						<th>{ __( 'Error', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.items.map( ( row ) => (
						<tr key={ row.id }>
							<td>{ row.created_at }</td>
							<td>{ row.to_email }</td>
							<td>{ row.subject }</td>
							<td>
								<span className={ `uxs-badge ${ row.status === 'success' ? 'is-success' : 'is-danger' }` }>
									{ row.status === 'success' ? __( 'Sent', 'ux-studio' ) : __( 'Failed', 'ux-studio' ) }
								</span>
							</td>
							<td>{ row.error ?? '' }</td>
						</tr>
					) ) }
				</tbody>
			</table>
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
					{ __( 'Log', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p>
						{ draft.has_password
							? __( 'A SMTP password is currently set.', 'ux-studio' )
							: __( 'No SMTP password set yet.', 'ux-studio' ) }
						{ ' · ' }
						{ draft.has_brevo_api_key
							? __( 'A Brevo API key is currently set.', 'ux-studio' )
							: __( 'No Brevo API key set yet.', 'ux-studio' ) }
						{ ' · ' }
						{ draft.has_gmail_client_secret
							? __( 'A Gmail client secret is currently set.', 'ux-studio' )
							: __( 'No Gmail client secret set yet.', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>

					{ draft.mailer === 'brevo' && (
						<BrevoVerify
							hasKey={ Boolean( draft.has_brevo_api_key ) }
							stored={ draft.brevo_status as BrevoStatus | undefined }
						/>
					) }

					{ draft.mailer === 'gmail' && (
						<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
							<p className="uxs-form__help">
								{ __( 'Redirect URI to register in your Google Cloud OAuth client:', 'ux-studio' ) }{ ' ' }
								<code>{ String( draft.gmail_redirect_uri ?? '' ) }</code>
							</p>
							{ draft.gmail_connected ? (
								<p>
									<span className="uxs-badge is-success">
										<Check size={ 12 } />{ ' ' }
										{ __( 'Connected', 'ux-studio' ) }
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
									</a>
									{ ' ' }
									<span className="uxs-form__help">
										{ __( 'Save the client ID and secret first, then connect.', 'ux-studio' ) }
									</span>
								</p>
							) }
							{ draft.gmail_last_error ? (
								<p className="uxs-badge is-danger">{ String( draft.gmail_last_error ) }</p>
							) : null }
						</div>
					) }

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
						</div>
						<button
							type="button"
							className="button"
							disabled={ test.isPending }
							onClick={ () => test.mutate() }
						>
							<Send size={ 14 } /> { __( 'Send test email', 'ux-studio' ) }
						</button>
						{ ' ' }
						<button
							type="button"
							className="button"
							disabled={ resend.isPending || ! draft.has_last_message }
							onClick={ () => resend.mutate() }
						>
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
			{ tab === 'log' && <LogTable /> }
		</>
	);
}
