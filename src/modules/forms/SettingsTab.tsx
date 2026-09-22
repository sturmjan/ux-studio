/**
 * Settings tab: title/description/status, anti-spam and the danger zone
 * (delete the form and every submission it has collected).
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import { Check, Save, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import type { FormDefinition, FormStatus } from './types';

export default function SettingsTab( { form }: { form: FormDefinition } ): JSX.Element {
	const [ title, setTitle ] = useState( form.title );
	const [ description, setDescription ] = useState( form.description );
	const [ status, setStatus ] = useState< FormStatus >( form.status );
	const [ captchaEnabled, setCaptchaEnabled ] = useState( form.settings.captcha_enabled );
	const [ dirty, setDirty ] = useState( false );

	const save = useMutation( {
		mutationFn: () =>
			api< FormDefinition >( `forms/${ form.id }`, {
				method: 'POST',
				body: JSON.stringify( {
					title,
					description,
					status,
					settings: { ...form.settings, captcha_enabled: captchaEnabled },
				} ),
			} ),
		onSuccess: () => {
			setDirty( false );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'form', form.id ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
		},
	} );

	const deleteAll = useMutation( {
		mutationFn: () => api( `forms/${ form.id }/delete-with-submissions`, { method: 'POST' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
			navigate( 'module', { id: 'forms' } );
		},
	} );

	return (
		<div className="uxs-form" style={ { maxWidth: 560 } }>
			<div className="uxs-form__row">
				<label>{ __( 'Title', 'ux-studio' ) }</label>
				<input
					type="text"
					value={ title }
					onChange={ ( e ) => {
						setTitle( e.target.value );
						setDirty( true );
					} }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Description (admin-only)', 'ux-studio' ) }</label>
				<textarea
					rows={ 3 }
					value={ description }
					onChange={ ( e ) => {
						setDescription( e.target.value );
						setDirty( true );
					} }
				/>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Status', 'ux-studio' ) }</label>
				<select
					value={ status }
					onChange={ ( e ) => {
						setStatus( e.target.value as FormStatus );
						setDirty( true );
					} }
				>
					<option value="draft">{ __( 'Draft (not visible on the site)', 'ux-studio' ) }</option>
					<option value="active">{ __( 'Active', 'ux-studio' ) }</option>
					<option value="archived">{ __( 'Archived (not visible on the site)', 'ux-studio' ) }</option>
				</select>
			</div>
			<div className="uxs-form__row">
				<label>
					<input
						type="checkbox"
						checked={ captchaEnabled }
						onChange={ ( e ) => {
							setCaptchaEnabled( e.target.checked );
							setDirty( true );
						} }
					/>{ ' ' }
					{ __( 'Show CAPTCHA field when placed in this form', 'ux-studio' ) }
				</label>
				<p className="uxs-form__help">
					{ __( 'A honeypot field is always active regardless of this setting. Full CAPTCHA enforcement requires the Security Optimization module to be configured.', 'ux-studio' ) }
				</p>
			</div>

			<button type="button" className="button button-primary" disabled={ ! dirty || save.isPending } onClick={ () => save.mutate() }>
				{ save.isSuccess && ! dirty ? <Check size={ 14 } /> : <Save size={ 14 } /> }{ ' ' }
				{ dirty ? __( 'Save changes', 'ux-studio' ) : __( 'Saved', 'ux-studio' ) }
			</button>

			<div className="uxs-card" style={ { marginTop: 'var(--uxs-sp-6)', borderColor: 'var(--uxs-danger)' } }>
				<strong style={ { color: 'var(--uxs-danger)' } }>{ __( 'Danger zone', 'ux-studio' ) }</strong>
				<p className="uxs-form__help">
					{ __( 'Permanently delete this form AND every submission (and attached files) it has ever collected. This cannot be undone.', 'ux-studio' ) }
				</p>
				<button
					type="button"
					className="button"
					style={ { color: 'var(--uxs-danger)', borderColor: 'var(--uxs-danger)' } }
					disabled={ deleteAll.isPending }
					onClick={ () => {
						if ( window.confirm( __( 'Delete this form and ALL of its submissions? This cannot be undone.', 'ux-studio' ) ) ) {
							deleteAll.mutate();
						}
					} }
				>
					<Trash2 size={ 14 } /> { __( 'Delete form and all submissions', 'ux-studio' ) }
				</button>
			</div>
		</div>
	);
}
