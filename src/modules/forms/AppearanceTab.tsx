/**
 * Appearance tab: form-wide label display default, multi-step progress
 * style, and the success message shown after submit.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import { Check, Save } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { FormDefinition, FormSettings } from './types';

export default function AppearanceTab( { form }: { form: FormDefinition } ): JSX.Element {
	const [ settings, setSettings ] = useState< FormSettings >( form.settings );
	const [ dirty, setDirty ] = useState( false );

	const save = useMutation( {
		mutationFn: () => api< FormDefinition >( `forms/${ form.id }`, { method: 'POST', body: JSON.stringify( { settings } ) } ),
		onSuccess: ( updated ) => {
			setSettings( updated.settings );
			setDirty( false );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'form', form.id ] } );
		},
	} );

	function patch( p: Partial< FormSettings > ) {
		setSettings( { ...settings, ...p } );
		setDirty( true );
	}

	return (
		<div className="uxs-form" style={ { maxWidth: 560 } }>
			<div className="uxs-form__row">
				<label>{ __( 'Label display (default for all fields)', 'ux-studio' ) }</label>
				<select value={ settings.label_display } onChange={ ( e ) => patch( { label_display: e.target.value as FormSettings[ 'label_display' ] } ) }>
					<option value="visible">{ __( 'Visible labels', 'ux-studio' ) }</option>
					<option value="placeholder_only">{ __( 'Placeholder only (labels hidden visually, kept for screen readers)', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'Any field can override this individually in the Fields tab.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label>{ __( 'Multi-step progress indicator', 'ux-studio' ) }</label>
				<select value={ settings.progress_style } onChange={ ( e ) => patch( { progress_style: e.target.value as FormSettings[ 'progress_style' ] } ) }>
					<option value="steps">{ __( 'Step bar', 'ux-studio' ) }</option>
					<option value="bar">{ __( 'Progress bar', 'ux-studio' ) }</option>
					<option value="none">{ __( 'None', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'Only relevant when the form has one or more "Step break" fields.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label>{ __( 'Success message', 'ux-studio' ) }</label>
				<textarea
					rows={ 3 }
					value={ settings.success_text }
					placeholder={ __( 'Thank you, your submission has been received.', 'ux-studio' ) }
					onChange={ ( e ) => patch( { success_text: e.target.value } ) }
				/>
			</div>

			<button type="button" className="button button-primary" disabled={ ! dirty || save.isPending } onClick={ () => save.mutate() }>
				{ save.isSuccess && ! dirty ? <Check size={ 14 } /> : <Save size={ 14 } /> }{ ' ' }
				{ dirty ? __( 'Save changes', 'ux-studio' ) : __( 'Saved', 'ux-studio' ) }
			</button>
		</div>
	);
}
