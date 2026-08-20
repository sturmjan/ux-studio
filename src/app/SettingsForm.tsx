/**
 * Shared schema-driven settings primitives. ModuleSettings.tsx uses these for
 * the generic full-page renderer; group-C modules with a custom SPA screen
 * (src/modules/<id>/Page.tsx) reuse the same <SettingsFields> + useModuleSettings()
 * to embed a "Settings" tab identical in look to every other module.
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { api, queryClient } from './api';

export type Value = string | number | boolean | string[] | null;

export interface Field {
	key: string;
	type:
		| 'toggle'
		| 'text'
		| 'textarea'
		| 'number'
		| 'select'
		| 'multiselect'
		| 'color'
		| 'media'
		| 'richtext';
	label: string;
	help?: string;
	default?: Value;
	options?: Record< string, string >;
}

export interface SettingsPayload {
	name: string;
	schema: Field[];
	values: Record< string, Value >;
}

interface WpMedia {
	open: () => void;
	on: ( ev: string, cb: () => void ) => void;
	state: () => { get: ( k: string ) => { first: () => { toJSON: () => { id: number; url: string } } } };
}

/** Media picker backed by the WP media modal (wp.media, dependency wp-media). */
function MediaField( { value, onChange }: { value: number; onChange: ( v: Value ) => void } ): JSX.Element {
	const wp = ( window as unknown as { wp?: { media?: ( opts: unknown ) => WpMedia } } ).wp;
	const pick = (): void => {
		if ( ! wp?.media ) {
			return;
		}
		const frame = wp.media( {
			title: __( 'Select image', 'ux-studio' ),
			multiple: false,
			library: { type: 'image' },
		} );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			onChange( att.id );
		} );
		frame.open();
	};
	return (
		<span className="uxs-media">
			<button type="button" className="button" onClick={ pick }>
				{ value ? __( 'Change image', 'ux-studio' ) : __( 'Select image', 'ux-studio' ) }
			</button>
			{ value ? (
				<button
					type="button"
					className="button-link uxs-media__clear"
					onClick={ () => onChange( 0 ) }
				>
					{ __( 'Remove', 'ux-studio' ) }
				</button>
			) : null }
		</span>
	);
}

export function FieldInput( {
	field,
	value,
	onChange,
}: {
	field: Field;
	value: Value;
	onChange: ( v: Value ) => void;
} ): JSX.Element {
	switch ( field.type ) {
		case 'toggle':
			return (
				<button
					type="button"
					className={ `uxs-switch${ value ? ' is-on' : '' }` }
					aria-pressed={ !! value }
					aria-label={ field.label }
					onClick={ () => onChange( ! value ) }
				/>
			);
		case 'select':
			return (
				<select
					value={ String( value ?? '' ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				>
					<option value="">{ __( '— Select —', 'ux-studio' ) }</option>
					{ Object.entries( field.options ?? {} ).map( ( [ k, label ] ) => (
						<option key={ k } value={ k }>
							{ label }
						</option>
					) ) }
				</select>
			);
		case 'multiselect': {
			const selected = Array.isArray( value ) ? value : [];
			return (
				<div className="uxs-checklist">
					{ Object.entries( field.options ?? {} ).map( ( [ k, label ] ) => (
						<label key={ k } className="uxs-checklist__item">
							<input
								type="checkbox"
								checked={ selected.includes( k ) }
								onChange={ ( e ) =>
									onChange(
										e.target.checked
											? [ ...selected, k ]
											: selected.filter( ( v ) => v !== k )
									)
								}
							/>
							{ label }
						</label>
					) ) }
				</div>
			);
		}
		case 'number':
			return (
				<input
					type="number"
					value={ Number( value ?? 0 ) }
					onChange={ ( e ) => onChange( Number( e.target.value ) ) }
				/>
			);
		case 'color':
			return (
				<input
					type="color"
					value={ value ? String( value ) : '#000000' }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
		case 'media':
			return <MediaField value={ Number( value ?? 0 ) } onChange={ onChange } />;
		case 'textarea':
		case 'richtext':
			return (
				<textarea
					rows={ 5 }
					value={ String( value ?? '' ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
		default:
			return (
				<input
					type="text"
					value={ String( value ?? '' ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
	}
}

/** Renders a schema as a field grid (no header, no save button - caller owns those). */
export function SettingsFields( {
	schema,
	draft,
	setDraft,
}: {
	schema: Field[];
	draft: Record< string, Value >;
	setDraft: ( updater: ( d: Record< string, Value > ) => Record< string, Value > ) => void;
} ): JSX.Element {
	return (
		<div className="uxs-form">
			{ schema.map( ( field ) => (
				<div key={ field.key } className="uxs-form__row">
					<label htmlFor={ `uxs-${ field.key }` }>{ field.label }</label>
					<FieldInput
						field={ field }
						value={ draft[ field.key ] ?? null }
						onChange={ ( v ) => setDraft( ( d ) => ( { ...d, [ field.key ]: v } ) ) }
					/>
					{ field.help ? <p className="uxs-form__help">{ field.help }</p> : null }
				</div>
			) ) }
		</div>
	);
}

/**
 * Loads a module's settings schema/values, tracks a local draft and exposes a
 * save mutation. Shared by the generic settings page and any custom app page
 * that embeds a "Settings" tab.
 */
export function useModuleSettings( id: string ) {
	const [ draft, setDraft ] = useState< Record< string, Value > >( {} );
	const [ saved, setSaved ] = useState( false );

	const query = useQuery( {
		queryKey: [ 'settings', id ],
		queryFn: () => api< SettingsPayload >( `settings/${ id }` ),
		enabled: id !== '',
	} );

	useEffect( () => {
		if ( query.data ) {
			setDraft( query.data.values );
		}
	}, [ query.data ] );

	const save = useMutation( {
		mutationFn: () =>
			api( `settings/${ id }`, { method: 'POST', body: JSON.stringify( draft ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'settings', id ] } );
			setSaved( true );
			window.setTimeout( () => setSaved( false ), 2000 );
		},
	} );

	return { ...query, draft, setDraft, save, saved };
}
