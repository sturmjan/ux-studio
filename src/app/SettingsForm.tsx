/**
 * Shared schema-driven settings primitives. ModuleSettings.tsx uses these for
 * the generic full-page renderer; group-C modules with a custom SPA screen
 * (src/modules/<id>/Page.tsx) reuse the same <SettingsFields> + useModuleSettings()
 * to embed a "Settings" tab identical in look to every other module.
 */
import { Fragment, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { ChevronDown } from 'lucide-react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { api, queryClient } from './api';
import { TabsNav } from './SubNav';
import MultiSelectField from '../components/MultiSelectField';
import SettingExplain from '../components/SettingExplain';
import type { Explain } from '../components/SettingExplain';
import { type ShowIf, isEmptyValue, visibleKeys } from './showIf';

export type Value = string | number | boolean | string[] | null;

export interface Field {
	key: string;
	type:
		| 'toggle'
		| 'text'
		| 'textarea'
		| 'css'
		| 'number'
		| 'select'
		| 'multiselect'
		| 'color'
		| 'media'
		| 'datetime'
		| 'richtext'
		| 'widget_rules';
	label: string;
	help?: string;
	/** Plain-language details (what it does, the risk, when to switch it off), shown under the help. */
	explain?: Explain;
	default?: Value;
	options?: Record< string, string >;
	/** Optional group label; two or more distinct sections render as sub-tabs. */
	section?: string;
	/** Card of the sub-tab this field belongs to (title); fields of one group are drawn together under its heading. */
	group?: string;
	/** One-line description under the group heading (taken from the first field of the group that has one). */
	group_help?: string;
	/** Draw the field only while another field has a given value (see showIf.ts). The stored value is kept as is. */
	show_if?: ShowIf;
	/** Span the full row of the field grid (long text, checklists). */
	wide?: boolean;
	/** Edited by a screen of its own (not by the generic form): never drawn here and never sent back by the form's save. */
	managed?: boolean;
}

/** Field types whose input needs a full grid row to stay readable. */
const WIDE_TYPES: ReadonlyArray< Field[ 'type' ] > = [ 'textarea', 'css', 'richtext', 'multiselect' ];

export interface SettingsPayload {
	name: string;
	schema: Field[];
	values: Record< string, Value >;
}

interface WpAttachment {
	get: ( k: string ) => unknown;
	fetch: () => { then: ( cb: () => void ) => unknown };
}

interface WpMedia {
	open: () => void;
	on: ( ev: string, cb: () => void ) => void;
	state: () => { get: ( k: string ) => { first: () => { toJSON: () => { id: number; url: string } } } };
}

/** Media picker backed by the WP media modal (wp.media, dependency wp-media). */
function MediaField( { value, onChange }: { value: number; onChange: ( v: Value ) => void } ): JSX.Element {
	const wp = ( window as unknown as { wp?: { media?: ( ( opts: unknown ) => WpMedia ) & { attachment?: ( id: number ) => WpAttachment } } } ).wp;
	const [ previewUrl, setPreviewUrl ] = useState( '' );
	// Preview of the stored attachment, also for an id that was set outside this form (for example by the starter pack).
	useEffect( () => {
		const attachment = value > 0 ? wp?.media?.attachment?.( value ) : undefined;
		if ( ! attachment ) {
			setPreviewUrl( '' );
			return undefined;
		}
		let alive = true;
		const show = (): void => {
			const sizes = attachment.get( 'sizes' ) as Record< string, { url?: string } > | undefined;
			const url = sizes?.medium?.url ?? sizes?.thumbnail?.url ?? attachment.get( 'url' );
			if ( alive ) {
				setPreviewUrl( typeof url === 'string' ? url : '' );
			}
		};
		if ( attachment.get( 'url' ) ) {
			show();
		} else {
			attachment.fetch().then( show );
		}
		return () => {
			alive = false;
		};
	}, [ value, wp ] );
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
			{ previewUrl ? <img className="uxs-media__preview" src={ previewUrl } alt="" /> : null }
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
			const selected = Array.isArray( value ) ? value.map( String ) : [];
			// Long lists (e.g. countries) become a searchable dropdown with chips instead of a wall of checkboxes.
			if ( Object.keys( field.options ?? {} ).length > 8 ) {
				return (
					<MultiSelectField
						id={ `uxs-${ field.key }` }
						options={ Object.entries( field.options ?? {} ).map( ( [ k, label ] ) => ( { value: k, label } ) ) }
						value={ selected }
						onChange={ onChange }
					/>
				);
			}
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
			// A native color input cannot be empty, so an unset value is shown as
			// a faded swatch + "Default" instead of a misleading black.
			return (
				<span className="uxs-color">
					<input
						type="color"
						className={ value ? '' : 'is-empty' }
						value={ value ? String( value ) : '#000000' }
						onChange={ ( e ) => onChange( e.target.value ) }
					/>
					<code>{ value ? String( value ) : __( 'Default', 'ux-studio' ) }</code>
					{ value ? (
						<button type="button" className="button-link" onClick={ () => onChange( '' ) }>
							{ __( 'Reset', 'ux-studio' ) }
						</button>
					) : null }
				</span>
			);
		case 'media':
			return <MediaField value={ Number( value ?? 0 ) } onChange={ onChange } />;
		case 'datetime':
			// Date and time of the site, stored as YYYY-MM-DDTHH:MM (what datetime-local writes).
			return (
				<span className="uxs-datetime">
					<input
						type="datetime-local"
						id={ `uxs-${ field.key }` }
						value={ String( value ?? '' ) }
						onChange={ ( e ) => onChange( e.target.value ) }
					/>
					{ value ? (
						<button type="button" className="button-link" onClick={ () => onChange( '' ) }>
							{ __( 'Clear', 'ux-studio' ) }
						</button>
					) : null }
				</span>
			);
		case 'css':
			return (
				<textarea
					rows={ 10 }
					className="uxs-code-editor"
					spellCheck={ false }
					value={ String( value ?? '' ) }
					onChange={ ( e ) => onChange( e.target.value ) }
				/>
			);
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

function prefersReducedMotion(): boolean {
	return typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
}

/**
 * Smooth expand / collapse. While `show` is false the children are not rendered at all (a hidden field has no input
 * in the page), after the collapse animation they are unmounted. With reduced motion there is no animation.
 */
export function Reveal( { show, children }: { show: boolean; children: ReactNode } ): JSX.Element | null {
	const [ mounted, setMounted ] = useState( show );
	const [ open, setOpen ] = useState( show );
	const [ settled, setSettled ] = useState( show );
	useEffect( () => {
		if ( prefersReducedMotion() ) {
			setMounted( show );
			setOpen( show );
			setSettled( show );
			return undefined;
		}
		if ( show ) {
			setMounted( true );
			const frame = window.requestAnimationFrame( () => window.requestAnimationFrame( () => setOpen( true ) ) );
			return () => window.cancelAnimationFrame( frame );
		}
		setOpen( false );
		setSettled( false );
		const timer = window.setTimeout( () => setMounted( false ), 260 );
		return () => window.clearTimeout( timer );
	}, [ show ] );
	if ( ! mounted ) {
		return null;
	}
	return (
		<div
			className={ `uxs-reveal${ open ? ' is-open' : '' }${ settled ? ' is-settled' : '' }` }
			onTransitionEnd={ ( e ) => {
				if ( e.target === e.currentTarget && open ) {
					setSettled( true );
				}
			} }
		>
			<div className="uxs-reveal__inner">{ children }</div>
		</div>
	);
}

/** Same value as the default? Empty things (null, '', 0, false, []) are all "not set". */
function isDefault( field: Field, value: Value | undefined ): boolean {
	const fallback = field.default ?? null;
	if ( isEmptyValue( value ?? null ) && isEmptyValue( fallback ) ) {
		return true;
	}
	return JSON.stringify( value ?? null ) === JSON.stringify( fallback );
}

interface FieldGroup {
	key: string;
	title: string;
	help: string;
	fields: Field[];
}

function groupFields( fields: Field[], tab: string ): FieldGroup[] {
	const groups: FieldGroup[] = [];
	for ( const field of fields ) {
		const title = field.group ?? '';
		let group = groups.find( ( g ) => g.title === title );
		if ( ! group ) {
			group = { key: `${ tab }::${ title }`, title, help: '', fields: [] };
			groups.push( group );
		}
		group.help = group.help || field.group_help || '';
		group.fields.push( field );
	}
	return groups;
}

/**
 * Renders a schema as a responsive field grid that uses the full page width
 * (no header, no save button - caller owns those). When the schema groups its
 * fields into two or more `section`s, each section becomes a sub-tab; fields
 * without a section belong to a leading "General" group. Inside a tab, fields
 * with a `group` are drawn as cards with a heading and a one-line description
 * (the first card, and every card with a changed value, starts open; the rest
 * starts closed), and a field with `show_if` appears only while its condition holds.
 */
export function SettingsFields( {
	schema: fullSchema,
	draft,
	setDraft,
	notes,
}: {
	schema: Field[];
	draft: Record< string, Value >;
	setDraft: ( updater: ( d: Record< string, Value > ) => Record< string, Value > ) => void;
	/** Extra content under the help text of a field, by field key (a state note, a link). */
	notes?: Record< string, ReactNode >;
} ): JSX.Element {
	// Fields with a screen of their own (a table, a builder) are not part of the generic form.
	const schema = fullSchema.filter( ( f ) => ! f.managed );
	const general = __( 'General', 'ux-studio' );
	const sections: string[] = [];
	for ( const field of schema ) {
		const name = field.section || general;
		if ( ! sections.includes( name ) ) {
			sections.push( name );
		}
	}
	const [ active, setActive ] = useState( 0 );
	const [ toggled, setToggled ] = useState< Record< string, boolean > >( {} );
	const initialOpen = useRef< Record< string, boolean > >( {} );
	const current = sections[ Math.min( active, sections.length - 1 ) ] ?? general;
	const visible = sections.length > 1 ? schema.filter( ( f ) => ( f.section || general ) === current ) : schema;

	// Before the stored values arrive the defaults decide, so the first paint is not a flash of hidden fields.
	const ready = Object.keys( draft ).length > 0;
	const values: Record< string, Value > = ready ? draft : Object.fromEntries( schema.map( ( f ) => [ f.key, f.default ?? null ] ) );
	const shown = visibleKeys( schema, values );

	const row = ( field: Field ): JSX.Element => (
		<div
			className={ `uxs-form__row${ field.wide || WIDE_TYPES.includes( field.type ) ? ' uxs-form__row--wide' : '' }` }
		>
			<label htmlFor={ `uxs-${ field.key }` }>{ field.label }</label>
			<FieldInput
				field={ field }
				value={ draft[ field.key ] ?? null }
				onChange={ ( v ) => setDraft( ( d ) => ( { ...d, [ field.key ]: v } ) ) }
			/>
			{ field.help ? <p className="uxs-form__help">{ field.help }</p> : null }
			{ field.explain ? <SettingExplain explain={ field.explain } /> : null }
			{ notes?.[ field.key ] ?? null }
		</div>
	);
	const rows = ( fields: Field[] ): JSX.Element[] =>
		fields.map( ( field ) =>
			field.show_if ? (
				<Reveal key={ field.key } show={ shown.has( field.key ) }>
					{ row( field ) }
				</Reveal>
			) : (
				<Fragment key={ field.key }>{ row( field ) }</Fragment>
			)
		);

	const grouped = visible.some( ( f ) => f.group );
	let body: JSX.Element;
	if ( ! grouped ) {
		body = <div className="uxs-form uxs-form--grid">{ rows( visible ) }</div>;
	} else {
		const groups = groupFields( visible, current );
		body = (
			<div className="uxs-groups">
				{ groups.map( ( group, index ) => {
					if ( ! group.fields.some( ( f ) => shown.has( f.key ) ) ) {
						return null; // Every field of the card is switched off by its condition.
					}
					if ( ready && initialOpen.current[ group.key ] === undefined ) {
						initialOpen.current[ group.key ] = index === 0 || group.fields.some( ( f ) => ! isDefault( f, draft[ f.key ] ) );
					}
					const open = toggled[ group.key ] ?? initialOpen.current[ group.key ] ?? index === 0;
					const regionId = `uxs-group-${ group.key.replace( /\W+/g, '-' ) }`;
					return (
						<div key={ group.key } className="uxs-form uxs-form--grid uxs-group">
							<button
								type="button"
								className="uxs-group__head"
								aria-expanded={ open }
								aria-controls={ regionId }
								onClick={ () => setToggled( ( t ) => ( { ...t, [ group.key ]: ! open } ) ) }
							>
								<span className="uxs-group__titles">
									<span className="uxs-group__title">{ group.title || general }</span>
									{ group.help ? <span className="uxs-group__help">{ group.help }</span> : null }
								</span>
								<ChevronDown size={ 18 } aria-hidden="true" className="uxs-group__chevron" />
							</button>
							<Reveal show={ open }>
								<div id={ regionId } className="uxs-group__body">
									{ rows( group.fields ) }
								</div>
							</Reveal>
						</div>
					);
				} ) }
			</div>
		);
	}

	if ( sections.length < 2 ) {
		return body;
	}

	return (
		<>
			<TabsNav level={ 2 } inlineClass="uxs-subtabs">
				{ sections.map( ( name, i ) => (
					<button
						key={ name }
						type="button"
						role="tab"
						aria-selected={ name === current }
						className={ name === current ? 'is-active' : '' }
						onClick={ () => setActive( i ) }
					>
						{ name }
					</button>
				) ) }
			</TabsNav>
			<h2 className="uxs-form__section">{ current }</h2>
			{ body }
		</>
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
		mutationFn: () => {
			// What a screen of its own edits (and saves itself) is never sent back from this form's older copy.
			const managed = new Set( ( query.data?.schema ?? [] ).filter( ( f ) => f.managed ).map( ( f ) => f.key ) );
			const body = Object.fromEntries( Object.entries( draft ).filter( ( [ key ] ) => ! managed.has( key ) ) );
			return api( `settings/${ id }`, { method: 'POST', body: JSON.stringify( body ) } );
		},
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'settings', id ] } );
			setSaved( true );
			window.setTimeout( () => setSaved( false ), 2000 );
		},
	} );

	return { ...query, draft, setDraft, save, saved };
}
