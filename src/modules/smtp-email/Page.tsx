import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Check, LoaderCircle, Send } from 'lucide-react';
import { api } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

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

function LogTable(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'smtp-email', 'logs' ],
		queryFn: () => api< SmtpLog[] >( 'smtp-email/logs' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No mail has been sent yet.', 'ux-studio' ) }</p>;
	}

	return (
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
				{ data.map( ( row ) => (
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
			<div className="uxs-tabs">
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
				<button className={ tab === 'log' ? 'is-active' : '' } onClick={ () => setTab( 'log' ) }>
					{ __( 'Log', 'ux-studio' ) }
				</button>
			</div>
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
