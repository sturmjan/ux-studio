/**
 * "After submit" card of the Actions tab: what the visitor sees once the form went through - a thank-you that
 * replaces the form, a message above the form, or a redirect. Settings on the left, the real thank-you on the
 * right: the preview is rendered by the SAME PHP code (sanitizer, merge tags, markup and CSS) the public form
 * uses (POST forms/{id}/preview-after-submit) and shown in a sandboxed iframe, so it can not drift.
 */
import { useEffect, useId, useRef, useState } from 'react';
import { __ } from '@wordpress/i18n';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { LoaderCircle, Moon, RotateCcw, Sun } from 'lucide-react';
import { api } from '../../app/api';
import WpEditor, { type WpEditorHandle } from './WpEditor';
import { ColorRow } from './EmailActionEditor';
import { LAYOUT_TYPES } from './types';
import type { AfterSubmitContext, AfterSubmitMode, AfterSubmitPreview, AfterSubmitSettings, FormField } from './types';

const MODES: { id: AfterSubmitMode; label: () => string; help: () => string }[] = [
	{
		id: 'replace',
		label: () => __( 'Hide the form and show a thank-you', 'ux-studio' ),
		help: () => __( 'The form is replaced by a thank-you with an animated check mark.', 'ux-studio' ),
	},
	{
		id: 'message',
		label: () => __( 'Show a message above the form (the form stays)', 'ux-studio' ),
		help: () => __( 'Good for forms people fill in repeatedly, e.g. a newsletter box in a footer.', 'ux-studio' ),
	},
	{
		id: 'redirect',
		label: () => __( 'Redirect to a page', 'ux-studio' ),
		help: () => __( 'Sends the visitor to a thank-you page of your own.', 'ux-studio' ),
	},
];

/** Merge tags that make sense in a thank-you: the form title and one per field. */
function thankYouTags( fields: FormField[] ): { tag: string; label: string }[] {
	const skipped = [ ...LAYOUT_TYPES, 'captcha', 'password', 'file', 'signature' ];
	return [
		{ tag: '{form_title}', label: __( 'Form title', 'ux-studio' ) },
		...fields.filter( ( f ) => ! skipped.includes( f.type ) && f.key ).map( ( f ) => ( { tag: `{${ f.key }}`, label: `${ f.label || f.key } (${ f.key })` } ) ),
	];
}

function TagSelect( { fields, label, onPick }: { fields: FormField[]; label: string; onPick: ( tag: string ) => void } ): JSX.Element {
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
			{ thankYouTags( fields ).map( ( t ) => (
				<option key={ t.tag } value={ t.tag }>
					{ t.tag } - { t.label }
				</option>
			) ) }
		</select>
	);
}

export default function AfterSubmitCard( {
	formId,
	fields,
	value,
	context,
	onChange,
}: {
	formId: number;
	fields: FormField[];
	value: AfterSubmitSettings;
	context: AfterSubmitContext | undefined;
	onChange: ( patch: Partial< AfterSubmitSettings > ) => void;
} ): JSX.Element {
	const editorId = 'uxs-after-msg-' + useId().replace( /[^a-zA-Z0-9_-]/g, '' );
	const editor = useRef< WpEditorHandle >( null );
	const titleInput = useRef< HTMLInputElement >( null );
	const [ scheme, setScheme ] = useState< 'light' | 'dark' >( 'light' );
	const [ replay, setReplay ] = useState( 0 );
	const [ settled, setSettled ] = useState< AfterSubmitSettings >( value );

	// The preview follows the settings with a short delay, so typing does not fire a request per key.
	const snapshot = JSON.stringify( value );
	useEffect( () => {
		const timer = window.setTimeout( () => setSettled( JSON.parse( snapshot ) as AfterSubmitSettings ), 600 );
		return () => window.clearTimeout( timer );
	}, [ snapshot ] );

	const preview = useQuery( {
		queryKey: [ 'forms', 'after-submit-preview', formId, JSON.stringify( settled ), scheme ],
		queryFn: () =>
			api< AfterSubmitPreview >( `forms/${ formId }/preview-after-submit`, { method: 'POST', body: JSON.stringify( { config: settled, scheme } ) } ),
		placeholderData: keepPreviousData,
		staleTime: 30_000,
		gcTime: 60_000,
	} );

	const stale = snapshot !== JSON.stringify( settled ) || preview.isFetching;
	const isRedirect = value.mode === 'redirect';
	const pages = context?.pages ?? [];

	function insertIntoTitle( tag: string ): void {
		const input = titleInput.current;
		if ( ! input ) {
			onChange( { title: value.title + tag } );
			return;
		}
		const start = input.selectionStart ?? value.title.length;
		const end = input.selectionEnd ?? value.title.length;
		onChange( { title: value.title.slice( 0, start ) + tag + value.title.slice( end ) } );
		input.focus();
	}

	return (
		<div className="uxs-card uxs-mail" style={ { marginBottom: 'var(--uxs-sp-4)' } }>
			<div className="uxs-mail__head">
				<strong>{ __( 'After submit', 'ux-studio' ) }</strong>
			</div>

			<div className="uxs-mail__grid">
				<div className="uxs-mail__settings">
					<div className="uxs-form uxs-form--stacked">
						<div className="uxs-form__row">
							<label id="uxs-after-mode-label">{ __( 'What happens', 'ux-studio' ) }</label>
							<div role="radiogroup" aria-labelledby="uxs-after-mode-label" className="uxs-after__modes">
								{ MODES.map( ( m ) => (
									<label key={ m.id } className="uxs-checklist__item uxs-after__mode">
										<input type="radio" name={ editorId + '-mode' } checked={ value.mode === m.id } onChange={ () => onChange( { mode: m.id } ) } />
										<span>
											<strong>{ m.label() }</strong>
											<small>{ m.help() }</small>
										</span>
									</label>
								) ) }
							</div>
						</div>

						{ isRedirect ? (
							<fieldset className="uxs-mail__section">
								<legend>{ __( 'Redirect target', 'ux-studio' ) }</legend>
								<div className="uxs-form__row">
									<label htmlFor={ editorId + '-rtype' }>{ __( 'Go to', 'ux-studio' ) }</label>
									<select id={ editorId + '-rtype' } value={ value.redirect_type } onChange={ ( e ) => onChange( { redirect_type: e.target.value === 'url' ? 'url' : 'page' } ) }>
										<option value="page">{ __( 'A page of this site', 'ux-studio' ) }</option>
										<option value="url">{ __( 'A web address', 'ux-studio' ) }</option>
									</select>
									<p className="uxs-form__help">{ __( 'Where the visitor lands after sending the form: one of your own pages or any web address.', 'ux-studio' ) }</p>
								</div>
								{ value.redirect_type === 'page' ? (
									<div className="uxs-form__row">
										<label htmlFor={ editorId + '-rpage' }>{ __( 'Page', 'ux-studio' ) }</label>
										<select id={ editorId + '-rpage' } value={ value.redirect_page_id } onChange={ ( e ) => onChange( { redirect_page_id: Number( e.target.value ) } ) }>
											<option value={ 0 }>{ __( 'Choose a page…', 'ux-studio' ) }</option>
											{ pages.map( ( p ) => (
												<option key={ p.id } value={ p.id }>
													{ p.title }
												</option>
											) ) }
										</select>
									</div>
								) : (
									<>
										<div className="uxs-form__row">
											<label htmlFor={ editorId + '-rurl' }>{ __( 'Web address', 'ux-studio' ) }</label>
											<input id={ editorId + '-rurl' } type="url" value={ value.redirect_url } placeholder="https://example.com/thank-you" onChange={ ( e ) => onChange( { redirect_url: e.target.value } ) } />
											<p className="uxs-form__help">{ __( 'The full address including https://. Check it once, a wrong address sends visitors to an error page.', 'ux-studio' ) }</p>
										</div>
										<div className="uxs-form__row">
											<label className="uxs-checklist__item">
												<input type="checkbox" checked={ value.redirect_external } onChange={ ( e ) => onChange( { redirect_external: e.target.checked } ) } />{ ' ' }
												{ __( 'Allow an address on another website', 'ux-studio' ) }
											</label>
											<p className="uxs-form__help">{ __( 'Off: only addresses on this site are kept, anything else is dropped when you save.', 'ux-studio' ) }</p>
										</div>
									</>
								) }
								<p className="uxs-form__help">
									{ __(
										'The visitor leaves the page right after the submit, so the thank-you settings are not used in this mode. This replaces the old "Redirect" action - there is only one redirect per form, so two can never fight over the visitor. Conversion tracking still runs first.',
										'ux-studio'
									) }
								</p>
							</fieldset>
						) : (
							<>
								<div className="uxs-form__row">
									<label htmlFor={ editorId + '-title' }>{ __( 'Title', 'ux-studio' ) }</label>
									<input
										id={ editorId + '-title' }
										ref={ titleInput }
										type="text"
										maxLength={ 120 }
										value={ value.title }
										placeholder={ __( 'Thank you', 'ux-studio' ) }
										onChange={ ( e ) => onChange( { title: e.target.value } ) }
									/>
									<TagSelect fields={ fields } label={ __( 'Insert a merge tag into the title', 'ux-studio' ) } onPick={ insertIntoTitle } />
								</div>

								<div className="uxs-form__row">
									<label id={ editorId + '-label' }>{ __( 'Message', 'ux-studio' ) }</label>
									<WpEditor ref={ editor } id={ editorId } value={ value.message } onChange={ ( message ) => onChange( { message } ) } />
									<TagSelect fields={ fields } label={ __( 'Insert a merge tag into the message', 'ux-studio' ) } onPick={ ( tag ) => editor.current?.insertText( tag ) } />
									<p className="uxs-form__help">
										{ __( 'Merge tags such as {name} are replaced by what the visitor typed (escaped on the server). Leave the message empty to use a short default sentence.', 'ux-studio' ) }
									</p>
								</div>

								<fieldset className="uxs-mail__section">
									<legend>{ __( 'Look', 'ux-studio' ) }</legend>
									<div className="uxs-form__row">
										<label htmlFor={ editorId + '-icon' }>{ __( 'Icon', 'ux-studio' ) }</label>
										<select id={ editorId + '-icon' } value={ value.icon } onChange={ ( e ) => onChange( { icon: e.target.value === 'none' ? 'none' : 'check' } ) }>
											<option value="check">{ __( 'Animated check mark', 'ux-studio' ) }</option>
											<option value="none">{ __( 'No icon', 'ux-studio' ) }</option>
										</select>
									</div>
									<ColorRow
										label={ __( 'Accent color', 'ux-studio' ) }
										help={ __( 'Colors the icon. Empty = the default green.', 'ux-studio' ) }
										value={ value.accent }
										fallback="#1a7f37"
										onChange={ ( accent ) => onChange( { accent } ) }
									/>
									{ value.mode === 'replace' && (
										<div className="uxs-form__row">
											<label className="uxs-checklist__item">
												<input type="checkbox" checked={ value.again } onChange={ ( e ) => onChange( { again: e.target.checked } ) } />{ ' ' }
												{ __( 'Show a "Send another response" button', 'ux-studio' ) }
											</label>
											<p className="uxs-form__help">{ __( 'Lets the visitor fill in the form again without reloading the page.', 'ux-studio' ) }</p>
										</div>
									) }
									<div className="uxs-form__row">
										<label className="uxs-checklist__item">
											<input type="checkbox" checked={ value.scroll } onChange={ ( e ) => onChange( { scroll: e.target.checked } ) } />{ ' ' }
											{ __( 'Scroll the page to the thank-you', 'ux-studio' ) }
										</label>
										<p className="uxs-form__help">{ __( 'Only when it is out of view. Visitors who prefer reduced motion get a jump instead of a glide.', 'ux-studio' ) }</p>
									</div>
								</fieldset>
							</>
						) }
					</div>
				</div>

				<aside className="uxs-mail__preview" aria-label={ __( 'Thank-you preview', 'ux-studio' ) }>
					<div className="uxs-mail__preview-bar">
						<strong>{ __( 'Live preview', 'ux-studio' ) }</strong>
						{ stale && <LoaderCircle size={ 14 } className="uxs-mail__spin" aria-label={ __( 'Updating the preview', 'ux-studio' ) } /> }
						<div className="uxs-fb-breakpoints" role="group" aria-label={ __( 'Page color', 'ux-studio' ) }>
							<button type="button" aria-pressed={ scheme === 'light' } className={ scheme === 'light' ? 'is-active' : '' } onClick={ () => setScheme( 'light' ) }>
								<Sun size={ 14 } /> { __( 'Light', 'ux-studio' ) }
							</button>
							<button type="button" aria-pressed={ scheme === 'dark' } className={ scheme === 'dark' ? 'is-active' : '' } onClick={ () => setScheme( 'dark' ) }>
								<Moon size={ 14 } /> { __( 'Dark', 'ux-studio' ) }
							</button>
						</div>
						{ ! isRedirect && (
							<button type="button" className="button button-small" onClick={ () => setReplay( replay + 1 ) }>
								<RotateCcw size={ 12 } /> { __( 'Play animation again', 'ux-studio' ) }
							</button>
						) }
					</div>
					{ isRedirect ? (
						<div className="uxs-after__redirect">
							{ preview.data?.redirect ? (
								<p>
									{ __( 'The visitor is sent to:', 'ux-studio' ) } <strong>{ preview.data.redirect }</strong>
								</p>
							) : (
								<p className="uxs-mail__error">{ __( 'No valid target yet - until you choose one the visitor sees the thank-you instead.', 'ux-studio' ) }</p>
							) }
						</div>
					) : (
						<div className="uxs-mail__frame uxs-after__frame">
							<iframe key={ replay } title={ __( 'Thank-you preview', 'ux-studio' ) } sandbox="" srcDoc={ preview.data?.document ?? '' } />
						</div>
					) }
				</aside>
			</div>
		</div>
	);
}
