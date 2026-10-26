/**
 * One e-mail action (notification to the admin or confirmation to the sender): settings on the left, the real
 * e-mail on the right. The preview is rendered by the SAME PHP renderer that sends the mail
 * (POST forms/{id}/preview-email -> EmailTemplateRenderer::build_html()), so it can not drift from what goes out;
 * it refreshes a moment after every change and is shown in a sandboxed iframe (srcdoc, no scripts).
 */
import { useEffect, useId, useRef, useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { AlertTriangle, AlignCenter, AlignLeft, AlignRight, ImagePlus, LoaderCircle, Monitor, Smartphone, Trash2, Undo2 } from 'lucide-react';
import { api } from '../../app/api';
import WpEditor, { type WpEditorHandle } from './WpEditor';
import { pickImage } from './wpGlobals';
import { LAYOUT_TYPES } from './types';
import type { EmailAction, EmailPreview, EmailTemplate, FormField, LogoPosition } from './types';

const TEMPLATES: { id: EmailTemplate; label: string }[] = [
	{ id: 'minimal', label: __( 'Minimal', 'ux-studio' ) },
	{ id: 'card', label: __( 'Card', 'ux-studio' ) },
	{ id: 'branded', label: __( 'Branded', 'ux-studio' ) },
	{ id: 'custom', label: __( 'Custom HTML', 'ux-studio' ) },
];

const POSITIONS: { id: LogoPosition; label: string; icon: typeof AlignLeft }[] = [
	{ id: 'left', label: __( 'Left', 'ux-studio' ), icon: AlignLeft },
	{ id: 'center', label: __( 'Center', 'ux-studio' ), icon: AlignCenter },
	{ id: 'right', label: __( 'Right', 'ux-studio' ), icon: AlignRight },
];

const CUSTOM_HTML_PLACEHOLDER = `<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;">
  <h1 style="color:#2563eb;">{form_title}</h1>
  <p>Hello, a new submission arrived on {submission_date}.</p>
  {submission_table}
</body>
</html>`;

/** Merge tags offered in the dropdown: the built-in ones, then one per field of the form. */
function mergeTags( fields: FormField[] ): { tag: string; label: string }[] {
	const builtIn = [
		{ tag: '{form_title}', label: __( 'Form title', 'ux-studio' ) },
		{ tag: '{submission_date}', label: __( 'Submission date', 'ux-studio' ) },
		{ tag: '{submission_table}', label: __( 'Table of all submitted fields', 'ux-studio' ) },
	];
	const skipped = [ ...LAYOUT_TYPES, 'captcha', 'password', 'file', 'signature' ];
	const fromFields = fields
		.filter( ( f ) => ! skipped.includes( f.type ) && f.key )
		.map( ( f ) => ( { tag: `{${ f.key }}`, label: `${ f.label || f.key } (${ f.key })` } ) );
	return [ ...builtIn, ...fromFields ];
}

function MergeTagSelect( { fields, onPick, label }: { fields: FormField[]; onPick: ( tag: string ) => void; label: string } ): JSX.Element {
	return (
		<select
			className="uxs-mail__tags"
			aria-label={ label }
			value=""
			onChange={ ( e ) => {
				if ( e.target.value ) {
					onPick( e.target.value );
				}
			} }
		>
			<option value="">{ __( 'Insert a merge tag…', 'ux-studio' ) }</option>
			{ mergeTags( fields ).map( ( t ) => (
				<option key={ t.tag } value={ t.tag }>
					{ t.tag } - { t.label }
				</option>
			) ) }
		</select>
	);
}

function ColorRow( {
	label,
	help,
	value,
	fallback,
	onChange,
}: {
	label: string;
	help: string;
	value: string;
	fallback: string;
	onChange: ( hex: string ) => void;
} ): JSX.Element {
	const shown = /^#[0-9a-f]{6}$/i.test( value ) ? value : fallback;
	return (
		<div className="uxs-form__row">
			<label>{ label }</label>
			<div className="uxs-mail__color">
				<input type="color" value={ shown } aria-label={ label } onChange={ ( e ) => onChange( e.target.value ) } />
				<input
					type="text"
					className="uxs-mail__hex"
					value={ value }
					placeholder={ fallback }
					maxLength={ 7 }
					spellCheck={ false }
					/* translators: %s: name of a color setting, e.g. Header color. */
					aria-label={ sprintf( __( '%s as a hex value', 'ux-studio' ), label ) }
					onChange={ ( e ) => onChange( e.target.value.trim() ) }
				/>
				<button type="button" className="button button-small" disabled={ ! value } onClick={ () => onChange( '' ) }>
					<Undo2 size={ 12 } /> { __( 'Template default', 'ux-studio' ) }
				</button>
			</div>
			<p className="uxs-form__help">{ help }</p>
		</div>
	);
}

/** A button / link address an e-mail client can open. */
function urlProblem( url: string ): boolean {
	return url !== '' && ! /^(https?:\/\/|mailto:|tel:)\S+$/i.test( url );
}

export default function EmailActionEditor( {
	action,
	formId,
	fields,
	onChange,
	onRemove,
}: {
	action: EmailAction;
	formId: number;
	fields: FormField[];
	onChange: ( patch: Partial< EmailAction > ) => void;
	onRemove: () => void;
} ): JSX.Element {
	const editorId = 'uxs-mail-msg-' + useId().replace( /[^a-zA-Z0-9_-]/g, '' );
	const editor = useRef< WpEditorHandle >( null );
	const subject = useRef< HTMLInputElement >( null );
	const [ device, setDevice ] = useState< 'desktop' | 'mobile' >( 'desktop' );
	const [ settled, setSettled ] = useState< EmailAction >( action );

	// The preview follows the settings with a short delay, so typing does not fire a request per key.
	const snapshot = JSON.stringify( action );
	useEffect( () => {
		const timer = window.setTimeout( () => setSettled( JSON.parse( snapshot ) as EmailAction ), 600 );
		return () => window.clearTimeout( timer );
	}, [ snapshot ] );

	const preview = useQuery( {
		queryKey: [ 'forms', 'email-preview', formId, JSON.stringify( settled ) ],
		queryFn: () => api< EmailPreview >( `forms/${ formId }/preview-email`, { method: 'POST', body: JSON.stringify( settled ) } ),
		placeholderData: keepPreviousData,
		staleTime: 30_000,
		gcTime: 60_000,
	} );

	const isCustom = action.template === 'custom';
	const defaults = preview.data?.layout;
	const warnings = preview.data?.warnings ?? [];
	const stale = snapshot !== JSON.stringify( settled ) || preview.isFetching;

	async function chooseLogo(): Promise< void > {
		const picked = await pickImage( __( 'Select logo', 'ux-studio' ) );
		if ( picked ) {
			onChange( { logo_id: picked.id, logo_url: picked.sizes?.medium?.url ?? picked.url } );
		}
	}

	function insertIntoSubject( tag: string ): void {
		const input = subject.current;
		if ( ! input ) {
			onChange( { subject: action.subject + tag } );
			return;
		}
		const start = input.selectionStart ?? action.subject.length;
		const end = input.selectionEnd ?? action.subject.length;
		onChange( { subject: action.subject.slice( 0, start ) + tag + action.subject.slice( end ) } );
		input.focus();
	}

	const buttonUrlBad = urlProblem( action.cta_url );

	return (
		<div className="uxs-card uxs-mail" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div className="uxs-mail__head">
				<strong>{ __( 'Email notification', 'ux-studio' ) }</strong>
				<button type="button" className="button-link" onClick={ onRemove } aria-label={ __( 'Remove this action', 'ux-studio' ) }>
					<Trash2 size={ 14 } />
				</button>
			</div>

			<div className="uxs-mail__grid">
				<div className="uxs-mail__settings">
					<div className="uxs-form uxs-form--stacked">
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
							<input ref={ subject } type="text" value={ action.subject } onChange={ ( e ) => onChange( { subject: e.target.value } ) } />
							<MergeTagSelect fields={ fields } label={ __( 'Insert a merge tag into the subject', 'ux-studio' ) } onPick={ insertIntoSubject } />
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

						{ isCustom ? (
							<div className="uxs-form__row">
								<label>{ __( 'Custom HTML', 'ux-studio' ) }</label>
								<textarea
									rows={ 14 }
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
								<fieldset className="uxs-mail__section">
									<legend>{ __( 'Header', 'ux-studio' ) }</legend>

									<div className="uxs-form__row">
										<label>{ __( 'Logo', 'ux-studio' ) }</label>
										<div className="uxs-mail__logo">
											{ action.logo_url ? <img src={ action.logo_url } alt="" /> : <span className="uxs-mail__logo-empty">{ __( 'Site logo', 'ux-studio' ) }</span> }
											<button type="button" className="button" onClick={ () => void chooseLogo() }>
												<ImagePlus size={ 14 } /> { action.logo_id ? __( 'Change logo', 'ux-studio' ) : __( 'Choose logo', 'ux-studio' ) }
											</button>
											{ action.logo_id > 0 && (
												<button type="button" className="button-link" onClick={ () => onChange( { logo_id: 0, logo_url: '' } ) }>
													{ __( 'Use the site logo', 'ux-studio' ) }
												</button>
											) }
										</div>
										<p className="uxs-form__help">{ __( 'Choose or upload an image in the Media Library. Without one the site logo is used, if the theme has one.', 'ux-studio' ) }</p>
									</div>

									<div className="uxs-form__row">
										<label>{ __( 'Logo position', 'ux-studio' ) }</label>
										<div className="uxs-fb-breakpoints" role="group" aria-label={ __( 'Logo position', 'ux-studio' ) }>
											{ POSITIONS.map( ( p ) => (
												<button
													key={ p.id }
													type="button"
													aria-pressed={ action.logo_position === p.id }
													className={ action.logo_position === p.id ? 'is-active' : '' }
													onClick={ () => onChange( { logo_position: p.id } ) }
												>
													<p.icon size={ 14 } /> { p.label }
												</button>
											) ) }
										</div>
									</div>

									<div className="uxs-form__row">
										<label>{ __( 'Logo width', 'ux-studio' ) }</label>
										<div className="uxs-mail__range">
											<input
												type="range"
												min={ 40 }
												max={ 400 }
												step={ 10 }
												value={ action.logo_width }
												aria-label={ __( 'Logo width', 'ux-studio' ) }
												onChange={ ( e ) => onChange( { logo_width: Number( e.target.value ) } ) }
											/>
											<output>{ action.logo_width } px</output>
										</div>
									</div>

									<ColorRow
										label={ __( 'Header color', 'ux-studio' ) }
										help={ __( 'The title text switches to white or dark on its own so it stays readable.', 'ux-studio' ) }
										value={ action.header_bg }
										fallback={ defaults?.header_bg ?? '#2563eb' }
										onChange={ ( header_bg ) => onChange( { header_bg } ) }
									/>
									<ColorRow
										label={ __( 'Link color', 'ux-studio' ) }
										help={ __( 'Used for links in the message and for the button.', 'ux-studio' ) }
										value={ action.link_color }
										fallback={ defaults?.link_color ?? '#2563eb' }
										onChange={ ( link_color ) => onChange( { link_color } ) }
									/>

									{ warnings.length > 0 && (
										<div className="uxs-mail__warnings" role="status">
											{ warnings.map( ( w ) => (
												<p key={ w.code }>
													<AlertTriangle size={ 14 } /> { w.message }
												</p>
											) ) }
										</div>
									) }
								</fieldset>

								<div className="uxs-form__row">
									<label id={ editorId + '-label' }>{ __( 'Message', 'ux-studio' ) }</label>
									<WpEditor ref={ editor } id={ editorId } value={ action.message } onChange={ ( message ) => onChange( { message } ) } />
									<MergeTagSelect fields={ fields } label={ __( 'Insert a merge tag into the message', 'ux-studio' ) } onPick={ ( tag ) => editor.current?.insertText( tag ) } />
									<p className="uxs-form__help">
										{ __( 'Use Add Media to put an image from the Media Library into the message. Images are sent with their full web address.', 'ux-studio' ) }
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
									<input
										type="url"
										value={ action.cta_url }
										placeholder="https://"
										aria-invalid={ buttonUrlBad }
										onChange={ ( e ) => onChange( { cta_url: e.target.value } ) }
									/>
									{ buttonUrlBad && <p className="uxs-form__help uxs-mail__error">{ __( 'Enter a full address starting with https://, mailto: or tel:. Anything else is dropped when you save.', 'ux-studio' ) }</p> }
								</div>
							</>
						) }
					</div>
				</div>

				<aside className="uxs-mail__preview" aria-label={ __( 'Email preview', 'ux-studio' ) }>
					<div className="uxs-mail__preview-bar">
						<strong>{ __( 'Live preview', 'ux-studio' ) }</strong>
						{ stale && <LoaderCircle size={ 14 } className="uxs-mail__spin" aria-label={ __( 'Updating the preview', 'ux-studio' ) } /> }
						<div className="uxs-fb-breakpoints" role="group" aria-label={ __( 'Preview size', 'ux-studio' ) }>
							<button type="button" aria-pressed={ device === 'desktop' } className={ device === 'desktop' ? 'is-active' : '' } onClick={ () => setDevice( 'desktop' ) }>
								<Monitor size={ 14 } /> { __( 'Desktop', 'ux-studio' ) }
							</button>
							<button type="button" aria-pressed={ device === 'mobile' } className={ device === 'mobile' ? 'is-active' : '' } onClick={ () => setDevice( 'mobile' ) }>
								<Smartphone size={ 14 } /> { __( 'Mobile', 'ux-studio' ) }
							</button>
						</div>
					</div>
					<div className="uxs-mail__subject">
						{ __( 'Subject:', 'ux-studio' ) } <strong>{ preview.data?.subject ?? '' }</strong>
					</div>
					<div className={ `uxs-mail__frame is-${ device }` }>
						<iframe title={ __( 'Email preview', 'ux-studio' ) } sandbox="" srcDoc={ preview.data?.html ?? '' } />
					</div>
				</aside>
			</div>
		</div>
	);
}
