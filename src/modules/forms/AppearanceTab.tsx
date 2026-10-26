/**
 * Appearance tab: look presets (card with header and footer, two columns, numbered sections), the form-wide
 * label display default, multi-step progress style, the text of the final button and the success message.
 * Everything is a setting, nothing needs custom CSS.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation } from '@tanstack/react-query';
import { Check, Columns2, ListOrdered, PanelTop, Save, Square } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { FormDefinition, FormSettings } from './types';

type LookSettings = Pick< FormSettings, 'layout' | 'columns' | 'numbered_sections' | 'progress_style' >;

interface Preset {
	id: string;
	label: string;
	description: string;
	icon: typeof Square;
	patch: Partial< LookSettings >;
	isActive: ( s: FormSettings ) => boolean;
}

const PRESETS: Preset[] = [
	{
		id: 'plain',
		label: __( 'Plain', 'ux-studio' ),
		description: __( 'Just the fields, no frame around them.', 'ux-studio' ),
		icon: Square,
		patch: { layout: 'plain', columns: 1, numbered_sections: false },
		isActive: ( s ) => s.layout === 'plain' && s.columns === 1 && ! s.numbered_sections,
	},
	{
		id: 'card',
		label: __( 'Card with header and footer', 'ux-studio' ),
		description: __( 'Form title and description on top, the buttons in a footer bar.', 'ux-studio' ),
		icon: PanelTop,
		patch: { layout: 'card', columns: 1, numbered_sections: false },
		isActive: ( s ) => s.layout === 'card' && s.columns === 1 && ! s.numbered_sections,
	},
	{
		id: 'two',
		label: __( 'Two columns', 'ux-studio' ),
		description: __( 'Short fields that have the full width sit side by side.', 'ux-studio' ),
		icon: Columns2,
		patch: { columns: 2 },
		isActive: ( s ) => s.columns === 2,
	},
	{
		id: 'numbered',
		label: __( 'Numbered sections', 'ux-studio' ),
		description: __( 'Every step gets a number and the progress shows numbered steps, like a checkout.', 'ux-studio' ),
		icon: ListOrdered,
		patch: { layout: 'card', numbered_sections: true, progress_style: 'numbers' },
		isActive: ( s ) => s.numbered_sections,
	},
];

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
		<div className="uxs-form">
			<div className="uxs-form__row">
				<label id="uxs-fb-presets-label">{ __( 'Look', 'ux-studio' ) }</label>
				<div>
					<div className="uxs-fb-presets" role="group" aria-labelledby="uxs-fb-presets-label">
						{ PRESETS.map( ( preset ) => {
							const active = preset.isActive( settings );
							return (
								<button
									key={ preset.id }
									type="button"
									aria-pressed={ active }
									className={ `uxs-fb-preset${ active ? ' is-active' : '' }` }
									onClick={ () => patch( preset.patch ) }
								>
									<preset.icon size={ 18 } aria-hidden="true" />
									<strong>{ preset.label }</strong>
									<span>{ preset.description }</span>
								</button>
							);
						} ) }
					</div>
					<p className="uxs-form__help">{ __( 'A preset only sets the options below, you can fine-tune each of them.', 'ux-studio' ) }</p>
				</div>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-layout">{ __( 'Frame', 'ux-studio' ) }</label>
				<select id="uxs-fb-layout" value={ settings.layout } onChange={ ( e ) => patch( { layout: e.target.value as FormSettings[ 'layout' ] } ) }>
					<option value="plain">{ __( 'None (just the fields)', 'ux-studio' ) }</option>
					<option value="card">{ __( 'Card with header and footer', 'ux-studio' ) }</option>
					<option value="inline">{ __( 'One row (e-mail and button next to each other)', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'The header shows the form title and description. The one-row layout suits a short signup form.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-columns">{ __( 'Columns', 'ux-studio' ) }</label>
				<select id="uxs-fb-columns" value={ settings.columns } onChange={ ( e ) => patch( { columns: Number( e.target.value ) === 2 ? 2 : 1 } ) }>
					<option value={ 1 }>{ __( 'One column (widths from the Fields tab)', 'ux-studio' ) }</option>
					<option value={ 2 }>{ __( 'Two columns for short fields', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'Fields you gave a narrower width in the Fields tab keep it. On a phone every field uses its mobile width.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-label-display">{ __( 'Label display (default for all fields)', 'ux-studio' ) }</label>
				<select id="uxs-fb-label-display" value={ settings.label_display } onChange={ ( e ) => patch( { label_display: e.target.value as FormSettings[ 'label_display' ] } ) }>
					<option value="visible">{ __( 'Visible labels', 'ux-studio' ) }</option>
					<option value="placeholder_only">{ __( 'Placeholder only (labels hidden visually, kept for screen readers)', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'Any field can override this individually in the Fields tab.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-progress">{ __( 'Multi-step progress indicator', 'ux-studio' ) }</label>
				<select id="uxs-fb-progress" value={ settings.progress_style } onChange={ ( e ) => patch( { progress_style: e.target.value as FormSettings[ 'progress_style' ] } ) }>
					<option value="numbers">{ __( 'Numbered steps with titles', 'ux-studio' ) }</option>
					<option value="steps">{ __( 'Step bar', 'ux-studio' ) }</option>
					<option value="bar">{ __( 'Progress bar', 'ux-studio' ) }</option>
					<option value="none">{ __( 'None', 'ux-studio' ) }</option>
				</select>
				<p className="uxs-form__help">{ __( 'Only relevant when the form has one or more "Step break" fields.', 'ux-studio' ) }</p>
			</div>

			<div className="uxs-form__row">
				<label>{ __( 'Section numbers', 'ux-studio' ) }</label>
				<div>
					<label className="uxs-checklist__item">
						<input type="checkbox" checked={ settings.numbered_sections } onChange={ ( e ) => patch( { numbered_sections: e.target.checked } ) } />{ ' ' }
						{ __( 'Show a number in front of every step title', 'ux-studio' ) }
					</label>
				</div>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-submit-text">{ __( 'Submit button text', 'ux-studio' ) }</label>
				<input
					id="uxs-fb-submit-text"
					type="text"
					maxLength={ 60 }
					value={ settings.submit_text }
					placeholder={ __( 'Submit', 'ux-studio' ) }
					onChange={ ( e ) => patch( { submit_text: e.target.value } ) }
				/>
			</div>

			<div className="uxs-form__row">
				<label htmlFor="uxs-fb-success">{ __( 'Success message', 'ux-studio' ) }</label>
				<textarea
					id="uxs-fb-success"
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
