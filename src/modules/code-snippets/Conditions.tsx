import { __ } from '@wordpress/i18n';
import { Plus, X } from 'lucide-react';

export type ConditionType = 'page' | 'post_type' | 'post' | 'login' | 'role' | 'device' | 'schedule';
type Scope = 'single' | 'archive' | 'both';

/** A condition the way the REST API returns and accepts it. */
export interface ApiCondition {
	type: ConditionType;
	value?: string | string[] | number[];
	scope?: Scope;
	from?: string;
	to?: string;
	negate?: boolean;
}

/** The editor keeps every value as strings / a list of strings. */
export interface ConditionDraft {
	type: ConditionType;
	values: string[];
	text: string;
	scope: Scope;
	from: string;
	to: string;
	negate: boolean;
}

export interface EditorChoices {
	post_types: { name: string; label: string }[];
	roles: { name: string; label: string }[];
	max_conditions?: number;
}

const DEFAULT_MAX = 6;

const TYPE_LABELS: Record< ConditionType, string > = {
	page: __( 'Type of page', 'ux-studio' ),
	post_type: __( 'Post type', 'ux-studio' ),
	post: __( 'Specific posts or pages', 'ux-studio' ),
	login: __( 'Visitor login state', 'ux-studio' ),
	role: __( 'User role', 'ux-studio' ),
	device: __( 'Device', 'ux-studio' ),
	schedule: __( 'Time window', 'ux-studio' ),
};

const PAGE_KINDS: Record< string, string > = {
	front_page: __( 'Front page', 'ux-studio' ),
	blog_home: __( 'Posts page', 'ux-studio' ),
	singular: __( 'Single post or page', 'ux-studio' ),
	archive: __( 'Archive', 'ux-studio' ),
	author: __( 'Author page', 'ux-studio' ),
	search: __( 'Search results', 'ux-studio' ),
	'404': __( '404 page', 'ux-studio' ),
	admin: __( 'wp-admin', 'ux-studio' ),
};

export function emptyCondition( type: ConditionType = 'page' ): ConditionDraft {
	return { type, values: type === 'login' ? [ 'in' ] : type === 'device' ? [ 'mobile' ] : [], text: '', scope: 'single', from: '', to: '', negate: false };
}

/** "2026-10-23 08:00:00" -> value of an <input type="datetime-local">. */
function toLocalInput( value: string ): string {
	return value ? value.slice( 0, 16 ).replace( ' ', 'T' ) : '';
}

function fromLocalInput( value: string ): string {
	return value ? value.replace( 'T', ' ' ) : '';
}

export function fromApi( c: ApiCondition ): ConditionDraft {
	const list = Array.isArray( c.value ) ? c.value.map( String ) : c.value !== undefined ? [ String( c.value ) ] : [];
	return {
		type: c.type,
		values: c.type === 'post' ? [] : list,
		text: c.type === 'post' ? list.join( ', ' ) : '',
		scope: c.scope ?? 'single',
		from: c.from ?? '',
		to: c.to ?? '',
		negate: !! c.negate,
	};
}

/** Drafts in the shape the REST API takes (incomplete rows are sent as they are, the server reports them). */
export function toPayload( drafts: ConditionDraft[] ): ApiCondition[] {
	return drafts.map( ( d ): ApiCondition => {
		switch ( d.type ) {
			case 'schedule':
				return { type: 'schedule', from: d.from, to: d.to, negate: d.negate };
			case 'post':
				return { type: 'post', value: d.text.split( /[\s,;]+/ ).filter( Boolean ), negate: d.negate };
			case 'login':
			case 'device':
				return { type: d.type, value: d.values[ 0 ] ?? '', negate: d.negate };
			case 'post_type':
				return { type: 'post_type', value: d.values, scope: d.scope, negate: d.negate };
			default:
				return { type: d.type, value: d.values, negate: d.negate };
		}
	} );
}

function CheckList( {
	options,
	chosen,
	onChange,
}: {
	options: { name: string; label: string }[];
	chosen: string[];
	onChange: ( next: string[] ) => void;
} ): JSX.Element {
	return (
		<div className="uxs-cs-types">
			{ options.map( ( option ) => (
				<label key={ option.name }>
					<input
						type="checkbox"
						checked={ chosen.includes( option.name ) }
						onChange={ ( e ) => onChange( e.target.checked ? [ ...chosen, option.name ] : chosen.filter( ( n ) => n !== option.name ) ) }
					/>{ ' ' }
					{ option.label }
				</label>
			) ) }
		</div>
	);
}

export function ConditionsEditor( {
	conditions,
	choices,
	onChange,
}: {
	conditions: ConditionDraft[];
	choices: EditorChoices | undefined;
	onChange: ( next: ConditionDraft[] ) => void;
} ): JSX.Element {
	const max = choices?.max_conditions ?? DEFAULT_MAX;
	const update = ( index: number, patch: Partial< ConditionDraft > ): void => onChange( conditions.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) ) );
	const pageOptions = Object.entries( PAGE_KINDS ).map( ( [ name, label ] ) => ( { name, label } ) );

	return (
		<div className="uxs-rd-cond uxs-cs-cond">
			<div className="uxs-rd-cond__head">
				<strong>{ __( 'Run conditions (optional)', 'ux-studio' ) }</strong>
				<span className="uxs-rd-cond__help">
					{ __( 'The snippet runs only where all conditions hold, on top of the place chosen above. A PHP snippet with a page or post condition is loaded after the request is parsed (the "wp" action), so hooks it adds earlier, such as init, do not fire.', 'ux-studio' ) }
				</span>
			</div>
			{ conditions.map( ( c, index ) => (
				<div className="uxs-rd-cond__row" key={ index }>
					<select
						aria-label={ __( 'Condition type', 'ux-studio' ) }
						value={ c.type }
						onChange={ ( e ) => {
							const next = emptyCondition( e.target.value as ConditionType );
							update( index, { ...next } );
						} }
					>
						{ ( Object.keys( TYPE_LABELS ) as ConditionType[] ).map( ( t ) => (
							<option key={ t } value={ t }>
								{ TYPE_LABELS[ t ] }
							</option>
						) ) }
					</select>
					{ c.type === 'page' && <CheckList options={ pageOptions } chosen={ c.values } onChange={ ( values ) => update( index, { values } ) } /> }
					{ c.type === 'post_type' && (
						<>
							<CheckList options={ choices?.post_types ?? [] } chosen={ c.values } onChange={ ( values ) => update( index, { values } ) } />
							<select aria-label={ __( 'Where', 'ux-studio' ) } value={ c.scope } onChange={ ( e ) => update( index, { scope: e.target.value as Scope } ) }>
								<option value="single">{ __( 'Single posts', 'ux-studio' ) }</option>
								<option value="archive">{ __( 'Post type archive', 'ux-studio' ) }</option>
								<option value="both">{ __( 'Both', 'ux-studio' ) }</option>
							</select>
						</>
					) }
					{ c.type === 'post' && (
						<input type="text" className="uxs-rd-cond__value" aria-label={ __( 'Post or page ids', 'ux-studio' ) } placeholder={ __( 'ids, e.g. 12, 45', 'ux-studio' ) } value={ c.text } onChange={ ( e ) => update( index, { text: e.target.value } ) } />
					) }
					{ c.type === 'login' && (
						<select aria-label={ __( 'Login state', 'ux-studio' ) } value={ c.values[ 0 ] ?? 'in' } onChange={ ( e ) => update( index, { values: [ e.target.value ] } ) }>
							<option value="in">{ __( 'Logged in', 'ux-studio' ) }</option>
							<option value="out">{ __( 'Logged out', 'ux-studio' ) }</option>
						</select>
					) }
					{ c.type === 'role' && <CheckList options={ choices?.roles ?? [] } chosen={ c.values } onChange={ ( values ) => update( index, { values } ) } /> }
					{ c.type === 'device' && (
						<select aria-label={ __( 'Device', 'ux-studio' ) } value={ c.values[ 0 ] ?? 'mobile' } onChange={ ( e ) => update( index, { values: [ e.target.value ] } ) }>
							<option value="mobile">{ __( 'Mobile', 'ux-studio' ) }</option>
							<option value="desktop">{ __( 'Desktop', 'ux-studio' ) }</option>
						</select>
					) }
					{ c.type === 'schedule' && (
						<>
							<label>
								{ __( 'From', 'ux-studio' ) }{ ' ' }
								<input type="datetime-local" value={ toLocalInput( c.from ) } onChange={ ( e ) => update( index, { from: fromLocalInput( e.target.value ) } ) } />
							</label>
							<label>
								{ __( 'To', 'ux-studio' ) }{ ' ' }
								<input type="datetime-local" value={ toLocalInput( c.to ) } onChange={ ( e ) => update( index, { to: fromLocalInput( e.target.value ) } ) } />
							</label>
						</>
					) }
					{ c.type !== 'login' && c.type !== 'device' && (
						<label className="uxs-rd-cond__negate">
							<input type="checkbox" checked={ c.negate } onChange={ ( e ) => update( index, { negate: e.target.checked } ) } /> { __( 'Invert', 'ux-studio' ) }
						</label>
					) }
					<button type="button" className="button button-small" aria-label={ __( 'Remove condition', 'ux-studio' ) } onClick={ () => onChange( conditions.filter( ( _, i ) => i !== index ) ) }>
						<X size={ 14 } />
					</button>
				</div>
			) ) }
			{ conditions.length < max && (
				<div>
					<button type="button" className="button button-small" onClick={ () => onChange( [ ...conditions, emptyCondition() ] ) }>
						<Plus size={ 14 } /> { __( 'Add condition', 'ux-studio' ) }
					</button>
				</div>
			) }
		</div>
	);
}
