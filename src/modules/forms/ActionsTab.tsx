/**
 * Actions tab: the post-submit action chain (PLAN.md 20.2/20.11 F2/F4) - email
 * notification(s) edited in EmailActionEditor (settings + live preview through
 * EmailTemplateRenderer, PLAN.md 20.9/20.12), webhook (HMAC-signed POST, PLAN.md
 * 20.2/20.8), redirect and create-a-post (PLAN.md 20.11 F4). Multiple `email`
 * actions can coexist (e.g. one "branded" notification to the site owner plus
 * a second "minimal"/"card" autoresponder to the submitter via `to: {email}`)
 * - there is no separate "autoresponder" action type, it is just a second
 * email action.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, Copy, FilePlus2, Mail, Save, Trash2, Webhook as WebhookIcon } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import AfterSubmitCard from './AfterSubmitCard';
import EmailActionEditor from './EmailActionEditor';
import TrackingCard from './TrackingCard';
import type { AfterSubmitContext, AfterSubmitSettings, CreatePostAction, EmailAction, FormAction, FormDefinition, PostStatus, PostTypeOption, TrackingSettings, WebhookAction } from './types';

const POST_STATUSES: { id: PostStatus; label: string }[] = [
	{ id: 'draft', label: __( 'Draft', 'ux-studio' ) },
	{ id: 'pending', label: __( 'Pending review', 'ux-studio' ) },
	{ id: 'private', label: __( 'Private', 'ux-studio' ) },
	{ id: 'publish', label: __( 'Published', 'ux-studio' ) },
];

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
		logo_id: 0,
		logo_url: '',
		logo_position: 'left',
		logo_width: 160,
		header_bg: '',
		link_color: '',
	};
}

function emptyWebhookAction(): WebhookAction {
	return { type: 'webhook', url: '' };
}

function emptyCreatePostAction(): CreatePostAction {
	return { type: 'create_post', post_type: 'post', post_status: 'draft', title_template: '', content_template: '', author_id: 0 };
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

function CreatePostActionEditor( {
	action,
	onChange,
	onRemove,
}: {
	action: CreatePostAction;
	onChange: ( patch: Partial< CreatePostAction > ) => void;
	onRemove: () => void;
} ): JSX.Element {
	const postTypes = useQuery( {
		queryKey: [ 'forms', 'post-types' ],
		queryFn: () => api< PostTypeOption[] >( 'forms/post-types' ),
		staleTime: 5 * 60 * 1000,
	} );

	return (
		<div className="uxs-card" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 'var(--uxs-sp-3)' } }>
				<strong>
					<FilePlus2 size={ 14 } style={ { verticalAlign: 'middle', marginRight: 6 } } /> { __( 'Create a post', 'ux-studio' ) }
				</strong>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Remove this action', 'ux-studio' ) }>
					<Trash2 size={ 14 } />
				</button>
			</div>

			<div className="uxs-form">
				<div className="uxs-form__row">
					<label>{ __( 'Post type', 'ux-studio' ) }</label>
					<select value={ action.post_type } onChange={ ( e ) => onChange( { post_type: e.target.value } ) }>
						{ ( postTypes.data ?? [ { id: action.post_type, label: action.post_type } ] ).map( ( t ) => (
							<option key={ t.id } value={ t.id }>
								{ t.label }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Status of the created post', 'ux-studio' ) }</label>
					<select value={ action.post_status } onChange={ ( e ) => onChange( { post_status: e.target.value as PostStatus } ) }>
						{ POST_STATUSES.map( ( s ) => (
							<option key={ s.id } value={ s.id }>
								{ s.label }
							</option>
						) ) }
					</select>
					<p className="uxs-form__help">{ __( 'What state the new post gets. Draft and Pending review wait for you to publish. Published goes live at once.', 'ux-studio' ) }</p>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Title', 'ux-studio' ) }</label>
					<input
						type="text"
						value={ action.title_template }
						placeholder={ __( '{form_title} submission - {submission_date}', 'ux-studio' ) }
						onChange={ ( e ) => onChange( { title_template: e.target.value } ) }
					/>
					<p className="uxs-form__help">
						{ __( 'Leave empty to use "{form_title} submission - {submission_date}". Supports merge tags, e.g. {email}.', 'ux-studio' ) }
					</p>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Content', 'ux-studio' ) }</label>
					<textarea rows={ 5 } value={ action.content_template } onChange={ ( e ) => onChange( { content_template: e.target.value } ) } />
					<p className="uxs-form__help">
						{ __( 'Merge tags: {form_title}, {submission_date}, {submission_table}, and any field key like {email}.', 'ux-studio' ) }
					</p>
				</div>
				<div className="uxs-form__row">
					<label>{ __( 'Author user ID (optional)', 'ux-studio' ) }</label>
					<input
						type="number"
						min={ 0 }
						value={ action.author_id || '' }
						placeholder={ __( 'Auto (form creator, else the first administrator)', 'ux-studio' ) }
						onChange={ ( e ) => onChange( { author_id: e.target.value ? Number( e.target.value ) : 0 } ) }
					/>
				</div>
				<p className="uxs-form__help">
					{ __( 'Every submitted field is also saved as post meta, so a theme template or another plugin can read the raw values.', 'ux-studio' ) }
				</p>
			</div>
		</div>
	);
}

export default function ActionsTab( { form }: { form: FormDefinition } ): JSX.Element {
	const [ actions, setActions ] = useState< FormAction[] >( form.settings.actions );
	const [ afterSubmit, setAfterSubmit ] = useState< AfterSubmitSettings >( form.settings.after_submit );
	const [ tracking, setTracking ] = useState< TrackingSettings >( form.settings.tracking );
	const [ dirty, setDirty ] = useState( false );

	const context = useQuery( {
		queryKey: [ 'forms', 'after-submit-context' ],
		queryFn: () => api< AfterSubmitContext >( 'forms/after-submit-context' ),
		staleTime: 60 * 1000,
	} );

	const save = useMutation( {
		mutationFn: () =>
			api< FormDefinition >( `forms/${ form.id }`, {
				method: 'POST',
				body: JSON.stringify( { settings: { ...form.settings, actions, after_submit: afterSubmit, tracking } } ),
			} ),
		onSuccess: ( updated ) => {
			setActions( updated.settings.actions );
			setAfterSubmit( updated.settings.after_submit );
			setTracking( updated.settings.tracking );
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

	function patchAfterSubmit( patch: Partial< AfterSubmitSettings > ) {
		setAfterSubmit( ( current ) => ( { ...current, ...patch } ) );
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

			<AfterSubmitCard formId={ form.id } fields={ form.fields } value={ afterSubmit } context={ context.data } onChange={ patchAfterSubmit } />

			<h3 className="uxs-fb-subhead">{ __( 'Actions', 'ux-studio' ) }</h3>
			{ actions.length === 0 ? (
			<div className="uxs-fb-empty">{ __( 'No actions yet - add one below (email, webhook or create a post).', 'ux-studio' ) }</div>
			) : (
				actions.map( ( action, i ) => {
					if ( 'email' === action.type ) {
						return (
							<EmailActionEditor
								key={ i }
								action={ action }
								formId={ form.id }
								fields={ form.fields }
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
					if ( 'create_post' === action.type ) {
						return (
							<CreatePostActionEditor
								key={ i }
								action={ action }
								onChange={ ( patch ) => update< CreatePostAction >( i, patch ) }
								onRemove={ () => remove( i ) }
							/>
						);
					}
					return null;
				} )
			) }

			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', flexWrap: 'wrap' } }>
				<button type="button" className="button" onClick={ () => add( emptyEmailAction() ) }>
					<Mail size={ 14 } /> { __( 'Add email notification', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => add( emptyWebhookAction() ) }>
					<WebhookIcon size={ 14 } /> { __( 'Add webhook', 'ux-studio' ) }
				</button>
				<button type="button" className="button" onClick={ () => add( emptyCreatePostAction() ) }>
					<FilePlus2 size={ 14 } /> { __( 'Add create post', 'ux-studio' ) }
				</button>
			</div>
		
			<TrackingCard
				formId={ form.id }
				value={ tracking }
				context={ context.data }
				onChange={ ( next ) => {
					setTracking( next );
					setDirty( true );
				} }
			/>
		</>
	);
}
