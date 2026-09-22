/**
 * Actions tab: post-submit email action(s) - recipient, subject, message,
 * built-in HTML template picker, and a live preview rendered server-side by
 * EmailTemplateRenderer (no email is actually sent). PLAN.md 20.9. Webhook/
 * redirect actions are phase F2 (PLAN.md 20.11) and intentionally absent.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import { Check, Eye, Plus, Save, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { EmailAction, EmailTemplate, FormDefinition } from './types';

const TEMPLATES: { id: EmailTemplate; label: string }[] = [
	{ id: 'minimal', label: __( 'Minimal', 'ux-studio' ) },
	{ id: 'card', label: __( 'Card', 'ux-studio' ) },
	{ id: 'branded', label: __( 'Branded', 'ux-studio' ) },
];

function emptyAction(): EmailAction {
	return {
		type: 'email',
		to: '',
		subject: '',
		message: '',
		template: 'branded',
		include_table: true,
		cta_text: '',
		cta_url: '',
	};
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
					<label>{ __( 'Template', 'ux-studio' ) }</label>
					<select value={ action.template } onChange={ ( e ) => onChange( { template: e.target.value as EmailTemplate } ) }>
						{ TEMPLATES.map( ( t ) => (
							<option key={ t.id } value={ t.id }>
								{ t.label }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Button text (optional)', 'ux-studio' ) }</label>
					<input type="text" value={ action.cta_text } onChange={ ( e ) => onChange( { cta_text: e.target.value } ) } />
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Button URL', 'ux-studio' ) }</label>
					<input type="url" value={ action.cta_url } onChange={ ( e ) => onChange( { cta_url: e.target.value } ) } />
				</div>

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
	const [ actions, setActions ] = useState< EmailAction[] >( form.settings.actions );
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

	function update( index: number, patch: Partial< EmailAction > ) {
		setActions( actions.map( ( a, i ) => ( i === index ? { ...a, ...patch } : a ) ) );
		setDirty( true );
	}

	function remove( index: number ) {
		setActions( actions.filter( ( _, i ) => i !== index ) );
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
				<div className="uxs-fb-empty">{ __( 'No actions yet - add an email notification below.', 'ux-studio' ) }</div>
			) : (
				actions.map( ( action, i ) => (
					<EmailActionEditor
						key={ i }
						action={ action }
						formId={ form.id }
						onChange={ ( patch ) => update( i, patch ) }
						onRemove={ () => remove( i ) }
					/>
				) )
			) }

			<button
				type="button"
				className="button"
				onClick={ () => {
					setActions( [ ...actions, emptyAction() ] );
					setDirty( true );
				} }
			>
				<Plus size={ 14 } /> { __( 'Add email notification', 'ux-studio' ) }
			</button>
		</>
	);
}
