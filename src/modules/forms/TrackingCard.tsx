/**
 * "Conversion tracking" card of the Actions tab. On every counted submit the form announces itself to the page:
 * a `uxstudio:form-submitted` DOM event and a dataLayer push (no field values, ever), then the optional presets
 * (GA4, Google Ads, Meta Pixel, Sklik) fire - but only when that tracker is ALREADY on the page, so a cookie
 * consent tool keeps deciding what loads. The custom script runs last and may only be changed by a user who is
 * allowed to put scripts on the site (the server enforces it, the field is just read-only for everybody else).
 */
import { useEffect, useRef } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { Lock } from 'lucide-react';
import { codeEditorApi } from './wpGlobals';
import { META_STANDARD_EVENTS } from './types';
import type { AfterSubmitContext, TrackingSettings } from './types';

const EVENT_NAME = /^[A-Za-z][A-Za-z0-9_]{0,39}$/;
const META_CUSTOM_NAME = /^[A-Za-z][A-Za-z0-9_]{0,49}$/;
const ADS_SEND_TO = /^AW-\d+\/[\w-]+$/;
const SKLIK_ID = /^\d{1,12}$/;

function numberOrNull( raw: string ): number | null {
	if ( raw.trim() === '' ) {
		return null;
	}
	const n = Number( raw );
	return Number.isFinite( n ) && n >= 0 ? n : null;
}

/** CodeMirror (WordPress' own) when available, a plain monospace textarea otherwise. */
function ScriptField( {
	id,
	value,
	readOnly,
	onChange,
}: {
	id: string;
	value: string;
	readOnly: boolean;
	onChange: ( code: string ) => void;
} ): JSX.Element {
	const host = useRef< HTMLDivElement >( null );
	const changeRef = useRef( onChange );
	const valueRef = useRef( value );
	changeRef.current = onChange;
	valueRef.current = value;
	const cm = useRef< ReturnType< NonNullable< ReturnType< typeof codeEditorApi > >[ 'initialize' ] >[ 'codemirror' ] | null >( null );
	const useCodeMirror = ! readOnly && !! codeEditorApi();

	useEffect( () => {
		const api = codeEditorApi();
		const el = host.current;
		if ( ! api || ! el || readOnly ) {
			return undefined;
		}
		const textarea = document.createElement( 'textarea' );
		textarea.id = id;
		textarea.value = valueRef.current;
		el.appendChild( textarea );
		const instance = api.initialize( textarea, { ...( api.defaultSettings ?? {} ) } );
		cm.current = instance.codemirror;
		instance.codemirror.on( 'change', () => changeRef.current( instance.codemirror.getValue() ) );
		return () => {
			try {
				instance.codemirror.toTextArea();
			} catch {
				// The editor was never fully built.
			}
			cm.current = null;
			el.innerHTML = '';
		};
	}, [ id, readOnly ] );

	// The value was replaced from outside (e.g. a saved form came back).
	useEffect( () => {
		if ( cm.current && cm.current.getValue() !== value ) {
			cm.current.setValue( value );
		}
	}, [ value ] );

	if ( useCodeMirror ) {
		return <div ref={ host } className="uxs-code-editor" />;
	}
	return (
		<textarea
			id={ id }
			className="uxs-code-editor__plain"
			rows={ 8 }
			spellCheck={ false }
			readOnly={ readOnly }
			aria-readonly={ readOnly }
			value={ value }
			style={ { fontFamily: 'monospace', fontSize: 'var(--uxs-fs-s)' } }
			onChange={ ( e ) => onChange( e.target.value ) }
		/>
	);
}

export default function TrackingCard( {
	formId,
	value,
	context,
	onChange,
}: {
	formId: number;
	value: TrackingSettings;
	context: AfterSubmitContext | undefined;
	onChange: ( next: TrackingSettings ) => void;
} ): JSX.Element {
	const pixel = context?.pixel;
	const canEditScript = context?.can_edit_script ?? false;
	const scriptId = 'uxs-track-js-' + formId;

	function set< K extends keyof TrackingSettings >( key: K, patch: Partial< TrackingSettings[ K ] > ): void {
		onChange( { ...value, [ key ]: { ...( value[ key ] as object ), ...patch } } );
	}

	const ga4Bad = value.ga4.enabled && ! EVENT_NAME.test( value.ga4.event );
	const adsBad = value.ads.enabled && ! ADS_SEND_TO.test( value.ads.send_to );
	const metaBad = value.meta.enabled && ! ( value.meta.custom ? META_CUSTOM_NAME : /^[A-Za-z]+$/ ).test( value.meta.event );
	const sklikBad = value.sklik.enabled && ! SKLIK_ID.test( value.sklik.id );

	return (
		<div className="uxs-card uxs-mail uxs-track" style={ { marginTop: 'var(--uxs-sp-4)' } }>
			<div className="uxs-mail__head">
				<strong>{ __( 'Conversion tracking', 'ux-studio' ) }</strong>
			</div>

			<div className="uxs-form uxs-form--stacked">
				<div className="uxs-form__row">
					<p className="uxs-form__help">
						{ __(
							'Every counted submit triggers a "uxstudio:form-submitted" event on the form (bubbling, with the form id, title and submission id). Field values and personal data are never included. The presets below only fire when that tracker is already on the page - this plugin never loads a third-party script, so your cookie consent tool stays in control.',
							'ux-studio'
						) }
					</p>
					{ pixel?.active && (
						<p className="uxs-form__help">
							{ sprintf(
								/* translators: 1: Google tag id or "none", 2: Meta Pixel id or "none". */
								__( 'Pixel & Tag Manager is on (Google tag: %1$s, Meta Pixel: %2$s), its trackers are used as they are.', 'ux-studio' ),
								pixel.ga_id || __( 'none', 'ux-studio' ),
								pixel.meta_id || __( 'none', 'ux-studio' )
							) }
						</p>
					) }
				</div>

				<div className="uxs-form__row">
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ value.datalayer } onChange={ ( e ) => onChange( { ...value, datalayer: e.target.checked } ) } />{ ' ' }
						{ __( 'Push to the dataLayer (Google Tag Manager)', 'ux-studio' ) }
					</label>
					<p className="uxs-form__help">
						<code>{ '{ event: "uxstudio_form_submit", form_id, form_title, submission_id }' }</code>
					</p>
				</div>

				<fieldset className="uxs-mail__section">
					<legend>{ __( 'Google Analytics 4', 'ux-studio' ) }</legend>
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ value.ga4.enabled } onChange={ ( e ) => set( 'ga4', { enabled: e.target.checked } ) } />{ ' ' }
						{ __( 'Send a GA4 event', 'ux-studio' ) }
					</label>
					{ value.ga4.enabled && (
						<div className="uxs-form__row">
							<label htmlFor={ scriptId + '-ga4' }>{ __( 'Event name', 'ux-studio' ) }</label>
							<input id={ scriptId + '-ga4' } type="text" maxLength={ 40 } value={ value.ga4.event } aria-invalid={ ga4Bad } onChange={ ( e ) => set( 'ga4', { event: e.target.value.trim() } ) } />
							<p className={ `uxs-form__help${ ga4Bad ? ' uxs-mail__error' : '' }` }>
								{ ga4Bad
									? __( 'Use letters, digits and underscores, starting with a letter (up to 40). Anything else is replaced by generate_lead when you save.', 'ux-studio' )
									: __( 'Calls gtag("event", name, { form_id, form_title }). generate_lead is Google\'s recommended name for a form lead.', 'ux-studio' ) }
							</p>
						</div>
					) }
				</fieldset>

				<fieldset className="uxs-mail__section">
					<legend>{ __( 'Google Ads conversion', 'ux-studio' ) }</legend>
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ value.ads.enabled } onChange={ ( e ) => set( 'ads', { enabled: e.target.checked } ) } />{ ' ' }
						{ __( 'Report a Google Ads conversion', 'ux-studio' ) }
					</label>
					{ value.ads.enabled && (
						<>
							<div className="uxs-form__row">
								<label htmlFor={ scriptId + '-ads' }>{ __( 'Conversion ID and label', 'ux-studio' ) }</label>
								<input
									id={ scriptId + '-ads' }
									type="text"
									value={ value.ads.send_to }
									placeholder="AW-123456789/AbC-dEfGhIjKlMn"
									spellCheck={ false }
									aria-invalid={ adsBad }
									onChange={ ( e ) => set( 'ads', { send_to: e.target.value.trim() } ) }
								/>
								<p className={ `uxs-form__help${ adsBad ? ' uxs-mail__error' : '' }` }>
									{ adsBad
										? __( 'The format is AW-123456789/label. Anything else is dropped when you save.', 'ux-studio' )
										: __( 'Copy it from Google Ads: Goals, Conversions, your conversion, Tag setup.', 'ux-studio' ) }
								</p>
								{ pixel?.ads_id && ! value.ads.send_to && (
									<button type="button" className="button button-small" onClick={ () => set( 'ads', { send_to: pixel.ads_id + '/' } ) }>
										{ sprintf(
											/* translators: %s: Google Ads tag id such as AW-123456789. */
											__( 'Start with %s from Pixel & Tag Manager', 'ux-studio' ),
											pixel.ads_id
										) }
									</button>
								) }
							</div>
							<div className="uxs-form__row">
								<div className="uxs-track__pair">
									<div>
										<label htmlFor={ scriptId + '-ads-value' }>{ __( 'Value (optional)', 'ux-studio' ) }</label>
										<input id={ scriptId + '-ads-value' } type="number" min={ 0 } step="any" value={ value.ads.value ?? '' } onChange={ ( e ) => set( 'ads', { value: numberOrNull( e.target.value ) } ) } />
									</div>
									<div>
										<label htmlFor={ scriptId + '-ads-cur' }>{ __( 'Currency', 'ux-studio' ) }</label>
										<input id={ scriptId + '-ads-cur' } type="text" maxLength={ 3 } value={ value.ads.currency } onChange={ ( e ) => set( 'ads', { currency: e.target.value.toUpperCase() } ) } />
									</div>
								</div>
							</div>
						</>
					) }
				</fieldset>

				<fieldset className="uxs-mail__section">
					<legend>{ __( 'Meta Pixel', 'ux-studio' ) }</legend>
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ value.meta.enabled } onChange={ ( e ) => set( 'meta', { enabled: e.target.checked } ) } />{ ' ' }
						{ __( 'Send a Meta Pixel event', 'ux-studio' ) }
					</label>
					{ value.meta.enabled && (
						<>
							<div className="uxs-form__row">
								<label htmlFor={ scriptId + '-meta-kind' }>{ __( 'Event', 'ux-studio' ) }</label>
								<select
									id={ scriptId + '-meta-kind' }
									value={ value.meta.custom ? '__custom' : value.meta.event }
									onChange={ ( e ) => {
										if ( e.target.value === '__custom' ) {
											set( 'meta', { custom: true, event: 'FormSubmit' } );
										} else {
											set( 'meta', { custom: false, event: e.target.value } );
										}
									} }
								>
									{ META_STANDARD_EVENTS.map( ( ev ) => (
										<option key={ ev } value={ ev }>
											{ ev }
										</option>
									) ) }
									<option value="__custom">{ __( 'Custom event…', 'ux-studio' ) }</option>
								</select>
							</div>
							{ value.meta.custom && (
								<div className="uxs-form__row">
									<label htmlFor={ scriptId + '-meta-name' }>{ __( 'Custom event name', 'ux-studio' ) }</label>
									<input id={ scriptId + '-meta-name' } type="text" maxLength={ 50 } value={ value.meta.event } aria-invalid={ metaBad } onChange={ ( e ) => set( 'meta', { event: e.target.value.trim() } ) } />
									<p className={ `uxs-form__help${ metaBad ? ' uxs-mail__error' : '' }` }>
										{ metaBad ? __( 'Letters, digits and underscores, starting with a letter.', 'ux-studio' ) : __( 'Sent with fbq("trackCustom", name).', 'ux-studio' ) }
									</p>
								</div>
							) }
							{ ! value.meta.custom && <p className="uxs-form__help">{ __( 'Sent with fbq("track", event), no parameters.', 'ux-studio' ) }</p> }
						</>
					) }
				</fieldset>

				<fieldset className="uxs-mail__section">
					<legend>{ __( 'Sklik (Seznam)', 'ux-studio' ) }</legend>
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ value.sklik.enabled } onChange={ ( e ) => set( 'sklik', { enabled: e.target.checked } ) } />{ ' ' }
						{ __( 'Report a Sklik conversion', 'ux-studio' ) }
					</label>
					{ value.sklik.enabled && (
						<>
							<div className="uxs-form__row uxs-track__pair">
								<div>
									<label htmlFor={ scriptId + '-sk-id' }>{ __( 'Conversion ID', 'ux-studio' ) }</label>
									<input id={ scriptId + '-sk-id' } type="text" inputMode="numeric" value={ value.sklik.id } aria-invalid={ sklikBad } onChange={ ( e ) => set( 'sklik', { id: e.target.value.trim() } ) } />
								</div>
								<div>
									<label htmlFor={ scriptId + '-sk-value' }>{ __( 'Value (optional)', 'ux-studio' ) }</label>
									<input id={ scriptId + '-sk-value' } type="number" min={ 0 } step="any" value={ value.sklik.value ?? '' } onChange={ ( e ) => set( 'sklik', { value: numberOrNull( e.target.value ) } ) } />
								</div>
							</div>
							<div className="uxs-form__row">
								<label htmlFor={ scriptId + '-sk-consent' }>{ __( 'Consent for Sklik', 'ux-studio' ) }</label>
								<select
									id={ scriptId + '-sk-consent' }
									value={ String( value.sklik.consent ) }
									onChange={ ( e ) => set( 'sklik', { consent: e.target.value === 'auto' ? 'auto' : e.target.value === '0' ? 0 : 1 } ) }
								>
									<option value="auto">{ __( 'According to the cookie banner (recommended)', 'ux-studio' ) }</option>
									<option value="1">{ __( 'Always 1 (the visitor agreed)', 'ux-studio' ) }</option>
									<option value="0">{ __( 'Always 0 (anonymous measurement)', 'ux-studio' ) }</option>
								</select>
								<p className="uxs-form__help">
									{ sklikBad
										? __( 'The conversion ID is a number from your Sklik account. Without it nothing is sent.', 'ux-studio' )
										: __( 'Calls rc.conversionHit({ id, value, consent }) when the Seznam script (rc.js) is on the page. Sklik requires the consent parameter.', 'ux-studio' ) }
								</p>
							</div>
							{ value.sklik.consent === 'auto' && (
								<div className="uxs-form__row">
									<label htmlFor={ scriptId + '-sk-fallback' }>{ __( 'When no cookie banner is detected', 'ux-studio' ) }</label>
									<select
										id={ scriptId + '-sk-fallback' }
										value={ String( value.sklik.consent_fallback ) }
										onChange={ ( e ) => set( 'sklik', { consent_fallback: e.target.value === '1' ? 1 : 0 } ) }
									>
										<option value="0">{ __( '0 - no consent (recommended)', 'ux-studio' ) }</option>
										<option value="1">{ __( '1 - consent given', 'ux-studio' ) }</option>
									</select>
									<p className="uxs-form__help">
										{ __(
											'The state is read when the conversion fires from Complianz, CookieYes, Cookiebot, Google Consent Mode (ad_storage) or window.uxstudioConsent.marketing. Without a recognised banner the value above is sent; with 0 Sklik still counts the conversion, only without personalization. Meta Pixel and Google Ads are not affected: they fire only if their tracker is already on the page, which your cookie banner controls.',
											'ux-studio'
										) }
									</p>
								</div>
							) }
						</>
					) }
				</fieldset>

				<fieldset className="uxs-mail__section">
					<legend>{ __( 'Custom script after submit', 'ux-studio' ) }</legend>
					<div className="uxs-form__row">
						<label htmlFor={ scriptId }>{ __( 'JavaScript', 'ux-studio' ) }</label>
						<ScriptField id={ scriptId } value={ value.custom_js } readOnly={ ! canEditScript } onChange={ ( custom_js ) => onChange( { ...value, custom_js } ) } />
						{ ! canEditScript && (
							<p className="uxs-form__help">
								<Lock size={ 12 } style={ { verticalAlign: 'middle', marginRight: 4 } } />
								{ value.custom_js
									? __( 'Read-only: only a user who may add scripts to the site (the unfiltered_html capability, on a multisite network only a super admin) can change this script.', 'ux-studio' )
									: __( 'Only a user who may add scripts to the site (the unfiltered_html capability, on a multisite network only a super admin) can write a script here.', 'ux-studio' ) }
							</p>
						) }
						<p className="uxs-form__help">
							{ __(
								'Runs in the visitor\'s browser after a counted submit, as the body of a function with one read-only argument: detail = { formId, formTitle, submissionId }. An error in it never affects the thank-you. It is printed as an inline script, so a strict Content Security Policy must allow inline scripts (script-src \'unsafe-inline\' or a nonce) - eval is not used.',
								'ux-studio'
							) }
						</p>
					</div>
				</fieldset>
			</div>
		</div>
	);
}
