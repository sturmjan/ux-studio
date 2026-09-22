/**
 * Actions tab: the post-submit action chain (PLAN.md 20.2/20.11 F2) - email
 * notification(s) with a built-in HTML template picker and live preview
 * (EmailTemplateRenderer, PLAN.md 20.9), webhook (HMAC-signed POST, PLAN.md
 * 20.2/20.8) and redirect. Multiple `email` actions can coexist (e.g. one
 * "branded" notification to the site owner plus a second "minimal"/"card"
 * autoresponder to the submitter via `to: {email}`) - there is no separate
 * "autoresponder" action type, it is just a second email action.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import { ArrowRight, Check, Copy, Eye, Mail, Save, Trash2, Webhook as WebhookIcon } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { EmailAction, EmailTemplate, FormAction, FormDefinition, RedirectAction, WebhookAction } from './types';

const TEMPLATES: { id: EmailTemplate; label: string }[] = [
	{ id: 'minimal', label: __( 'Minimal', 'ux-studio' ) },
	{ id: 'card', label: __( 'Card', 'ux-studio' ) },
	{ id: 'branded', label: __( 'Branded', 'ux-studio' ) },
	{ id: 'custom', label: __( 'Custom HTML', 'ux-studio' ) },
];

const CUSTOM_HTML_PLACEHOLDER = `<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;">
  <h1 style="color:#2563eb;">{form_title}</h1>
  <p>Hello, a new submission arrived on {submission_date}.</p>
  {submission_table}
</body>
</html>`;

function emptyEmailAction(): EmailAction {
	return {
		type: 'email',
		to: '',
		subject: '',
		message: '',
		template: 'branded',
		include_table: true,
		cta_text: '',
		cta_url: '',
		custom_html: '',
	};
}

function emptyWebhookAction(): WebhookAction {
	return { type: 'webhook', url: '' };
}

function emptyRedirectAction(): RedirectAction {
	return { type: 'redirect', url: '' };
}

function WebhookActionEditor( {
	action,
	secret,
	onChange,
	onRemove,
}: {
	action: WebhookAction;
	secret: string;
	onChange: ( patch: Partial< WebhookAction > ) => void;
	onRemove: () => void;
} ): JSX.Element {
	const [ copied, setCopied ] = useState( false );

	return (
		<div className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-3)' } }>
				<strong>
					<WebhookIcon size={ 14 } style={ { verticalAlign: 'middle', marginRight: 6 } } /> { __( 'Webhook', 'ux-studio' ) }
				</strong>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Remove this action', 'ux-studio' ) }>
					<Trash2 size={ 14 } />
				</button>
			</div>

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label>{ __( 'Webhook URL', 'ux-studio' ) }</label>
					<input
						type="url"
						value={ action.url }
						placeholder="https://example.com/webhook"
						onChange={ ( e ) => onChange( { url: e.target.value } ) }
					/>
					<p className="uxs-form__help">
						{ __( 'On every submission a JSON payload is POSTed to this URL (form, submission and field values).', 'ux-studio' ) }
					</p>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Signing secret', 'ux-studio' ) }</label>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
						<input type="text" readOnly value={ secret } onFocus={ ( e ) => e.currentTarget.select() } />
						<button
							type="button"
							className="button"
							onClick={ () => {
								void navigator.clipboard.writeText( secret );
								setCopied( true );
								window.setTimeout( () => setCopied( false ), 1500 );
							} }
						>
							{ copied ? <Check size={ 14 } /> : <Copy size={ 14 } /> } { __( 'Copy', 'ux-studio' ) }
						</button>
					</div>
					<p className="uxs-form__help">
						{ __(
							'Sent with every request in the X-UxStudio-Signature header - HMAC-SHA256 of the raw JSON body, using this secret. Verify it on your side to confirm the request came from this site.',
							'ux-studio'
						) }
					</p>
				</div>
			</div>
		</div>
	);
}

function RedirectActionEditor( {
	action,
	onChange,
	onRemove,
}: {
	action: RedirectAction;
	onChange: ( patch: Partial< RedirectAction > ) => void;
	onRemove: () => void;
} ): JSX.Element {
	return (
		<div className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-3)' } }>
				<strong>
					<ArrowRight size={ 14 } style={ { verticalAlign: 'middle', marginRight: 6 } } /> { __( 'Redirect', 'ux-studio' ) }
				</strong>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Remove this action', 'ux-studio' ) }>
					<Trash2 size={ 14 } />
				</button>
			</div>

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label>{ __( 'Redirect URL after submit', 'ux-studio' ) }</label>
					<input
						type="url"
						value={ action.url }
						placeholder="https://example.com/thank-you"
						onChange={ ( e ) => onChange( { url: e.target.value } ) }
					/>
					<p className="uxs-form__help">{ __( 'Sends the visitor here instead of showing the inline success message.', 'ux-studio' ) }</p>
				</div>
			</div>
		</div>
	);
}

function EmailActionEditor( {
	action,
	formId,
	onChange,
	onRemove,
}: {
	action: EmailAction;
	formId: number;
	onChange: ( patch: Partial< EmailAction > ) => void;
	onRemove: () => void;
} ): JSX.Element {
	const [ preview, setPreview ] = useState< { subject: string; html: string } | null >( null );

	const previewMutation = useMutation( {
		mutationFn: () => api< { subject: string; html: string } >( `forms/${ formId }/preview-email`, { method: 'POST', body: JSON.stringify( action ) } ),
		onSuccess: ( result ) => setPreview( result ),
	} );

	return (
		<div className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-3)' } }>
				<strong>{ __( 'Email notification', 'ux-studio' ) }</strong>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Remove this action', 'ux-studio' ) }>
					<Trash2 size={ 14 } />
				</button>
			</div>

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label>{ __( 'Send to', 'ux-studio' ) }</label>
					<input
						type="text"
						value={ action.to }
						placeholder={ __( 'admin_email or {email}', 'ux-studio' ) }
						onChange={ ( e ) => onChange( { to: e.target.value } ) }
					/>
					<p className="uxs-form__help">{ __( 'Leave empty to use the site admin email. Supports merge tags, e.g. {email}.', 'ux-studio' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Subject', 'ux-studio' ) }</label>
					<input type="text" value={ action.subject } onChange={ ( e ) => onChange( { subject: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Template', 'ux-studio' ) }</label>
					<select value={ action.template } onChange={ ( e ) => onChange( { template: e.target.value as EmailTemplate } ) }>
						{ TEMPLATES.map( ( t ) => (
							<option key={ t.id } value={ t.id }>
								{ t.label }
							</option>
						) ) }
					</select>
				</div>

				{ action.template === 'custom' ? (
					<div className="uxs-form__row">
						<label>{ __( 'Custom HTML', 'ux-studio' ) }</label>
						<textarea
							rows={ 12 }
							style={ { fontFamily: 'monospace', fontSize: 'var(--uxs-fs-s)' } }
							value={ action.custom_html }
							placeholder={ CUSTOM_HTML_PLACEHOLDER }
							onChange={ ( e ) => onChange( { custom_html: e.target.value } ) }
						/>
						<p className="uxs-form__help">
							{ __(
								'Your own complete HTML email - no built-in layout is added around it. Merge tags: {form_title}, {submission_date}, {submission_table}, and any field key like {email}. Use inline style="" attributes; most email clients ignore <style> blocks and external CSS.',
								'ux-studio'
							) }
						</p>
					</div>
				) : (
					<>
						<div className="uxs-form__row">
							<label>{ __( 'Message', 'ux-studio' ) }</label>
							<textarea rows={ 5 } value={ action.message } onChange={ ( e ) => onChange( { message: e.target.value } ) } />
							<p className="uxs-form__help">
								{ __( 'Merge tags: {form_title}, {submission_date}, {submission_table}, and any field key like {email}.', 'ux-studio' ) }
							</p>
						</div>
						<div className="uxs-form__row">
							<label>
								<input type="checkbox" checked={ action.include_table } onChange={ ( e ) => onChange( { include_table: e.target.checked } ) } />{ ' ' }
								{ __( 'Include a table of all submitted fields', 'ux-studio' ) }
							</label>
						</div>
						<div className="uxs-form__row">
							<label>{ __( 'Button text (optional)', 'ux-studio' ) }</label>
							<input type="text" value={ action.cta_text } onChange={ ( e ) => onChange( { cta_text: e.target.value } ) } />
						</div>
						<div className="uxs-form__row">
							<label>{ __( 'Button URL', 'ux-studio' ) }</label>
							<input type="url" value={ action.cta_url } onChange={ ( e ) => onChange( { cta_url: e.target.value } ) } />
						</div>
					</>
				) }

				<button type="button" className="button" disabled={ previewMutation.isPending } onClick={ () => previewMutation.mutate() }>
					<Eye size={ 14 } /> { __( 'Preview', 'ux-studio' ) }
				</button>

				{ preview && (
					<div className="uxs-fb-email-preview" style={ { marginTop: 'var(--uxs-sp-3)' } }>
						<div style={ { padding: 'var(--uxs-sp-2) var(--uxs-sp-3)', fontSize: 'var(--uxs-fs-s)', color: 'var(--uxs-text-soft)' } }>
							{ __( 'Subject:', 'ux-studio' ) } <strong>{ preview.subject }</strong>
						</div>
						<iframe title={ __( 'Email preview', 'ux-studio' ) } sandbox="" srcDoc={ preview.html } />
					</div>
				) }
			</div>
		</div>
	);
}

export default function ActionsTab( { form }: { form: FormDefinition } ): JSX.Element {
	const [ actions, setActions ] = useState< FormAction[] >( form.settings.actions );
	const [ dirty, setDirty ] = useState( false );

	const save = useMutation( {
		mutationFn: () =>
			api< FormDefinition >( `forms/${ form.id }`, {
				method: 'POST',
				body: JSON.stringify( { settings: { ...form.settings, actions } } ),
			} ),
		onSuccess: ( updated ) => {
			setActions( updated.settings.actions );
			setDirty( false );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'form', form.id ] } );
		},
	} );

	function update< A extends FormAction >( index: number, patch: Partial< A > ) {
		setActions( actions.map( ( a, i ) => ( i === index ? ( { ...a, ...patch } as A ) : a ) ) );
		setDirty( true );
	}

	function remove( index: number ) {
		setActions( actions.filter( ( _, i ) => i !== index ) );
		setDirty( true );
	}

	function add( action: FormAction ) {
		setActions( [ ...actions, action ] );
		setDirty( true );
	}

	return (
		<>
			<div style={ { display: 'flex', justifyContent: 'flex-end', marginBottom: 'var(--uxs-sp-3)' } }>
				<button type="button" className="button button-primary" disabled={ ! dirty || save.isPending } onClick={ () => save.mutate() }>
					{ save.isSuccess && ! dirty ? <Check size={ 14 } /> : <Save size={ 14 } /> }{ ' ' }
					{ dirty ? __( 'Save changes', 'ux-studio' ) : __( 'Saved', 'ux-studio' ) }
				</button>
			</div>

			{ actions.length === 0 ? (
				<div className="uxs-fb-empty">{ __( 'No actions yet - add one below (email, webhook or redirect).', 'ux-studio' ) }</div>
			) : (
				actions.map( ( action, i ) => {
					if ( 'email' === action.type ) {
						return (
							<EmailActionEditor
								key={ i }
								action={ action }
								formId={ form.id }
								onChange={ ( patch ) => update< EmailAction >( i, patch ) }
								onRemove={ () => remove( i ) }
							/>
						);
					}
					if ( 'webhook' === action.type ) {
						return (
							<WebhookActionEditor
								key={ i }
								action={ action }
								secret={ form.settings.webhook_secret }
								onChange={ ( patch ) => update< WebhookAction >( i, patch ) }
								onRemove={ () => remove( i ) }
							/>
						);
					}
					return (
						<RedirectActionEditor
							key={ i }
							action={ action }
							onChange={ ( patch ) => update< RedirectAction >( i, patch ) }
							onRemove={ () => remove( i ) }
						/>
					);
				} )
			) }

			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', flexWrap: 'wrap' } }>
				<button type="button" className="button" onClick={ () => add( emptyEmailAction() ) }>
					<Mail size={ 14 } /> { __( 'Add email notification', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => add( emptyWebhookAction() ) }>
					<WebhookIcon size={ 14 } /> { __( 'Add webhook', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => add( emptyRedirectAction() ) }>
					<ArrowRight size={ 14 } /> { __( 'Add redirect', 'ux-studio' ) }
				</button>
			</div>
		</>
	);
}
