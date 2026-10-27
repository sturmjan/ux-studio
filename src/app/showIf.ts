/**
 * Conditional visibility of settings fields (schema property `show_if`).
 *
 * Pure and dependency free on purpose: it runs in the form and in `tests/show-if.mjs` (Node).
 * A condition looks at ONE other field of the same schema; a list of conditions is an AND.
 *   { key, equals }  the value equals `equals` (compared as strings, so 1 and '1' match)
 *   { key, in }      the value is one of the listed ones
 *   { key, truthy }  true: the value is on / not empty; false: it is off / empty
 * A field whose condition is not met is just not drawn; its stored value is never touched.
 */
export type Scalar = string | number | boolean | string[] | null | undefined;

export interface ShowIfCondition {
	key: string;
	equals?: string | number | boolean;
	in?: Array< string | number | boolean >;
	truthy?: boolean;
}

export type ShowIf = ShowIfCondition | ShowIfCondition[];

/** Off / empty in the sense of a form: null, '', 0, '0', false and an empty list. */
export function isEmptyValue( value: Scalar ): boolean {
	if ( Array.isArray( value ) ) {
		return value.length === 0;
	}
	return value === null || value === undefined || value === '' || value === 0 || value === '0' || value === false;
}

export function conditionMet( condition: ShowIfCondition, values: Record< string, Scalar > ): boolean {
	const value = values[ condition.key ];
	if ( condition.in !== undefined ) {
		return condition.in.some( ( option ) => String( option ) === String( value ?? '' ) );
	}
	if ( condition.equals !== undefined ) {
		return String( condition.equals ) === String( value ?? '' );
	}
	const wanted = condition.truthy ?? true;
	return wanted === ! isEmptyValue( value );
}

/** No condition means always visible. */
export function isVisible( showIf: ShowIf | undefined, values: Record< string, Scalar > ): boolean {
	if ( ! showIf ) {
		return true;
	}
	const list = Array.isArray( showIf ) ? showIf : [ showIf ];
	return list.every( ( condition ) => conditionMet( condition, values ) );
}

/**
 * Keys of the fields that are visible. A field whose controlling field is itself hidden is hidden too
 * (a chain "toggle -> select -> text" collapses as a whole). Unknown controlling keys count as "no value".
 */
export function visibleKeys( schema: ReadonlyArray< { key: string; show_if?: ShowIf } >, values: Record< string, Scalar > ): Set< string > {
	const byKey = new Map( schema.map( ( f ) => [ f.key, f ] ) );
	const memo = new Map< string, boolean >();
	const visit = ( key: string, trail: string[] ): boolean => {
		const cached = memo.get( key );
		if ( cached !== undefined ) {
			return cached;
		}
		const field = byKey.get( key );
		if ( ! field || trail.includes( key ) ) {
			return true; // Not a field of this form (or a loop): the plain value decides, never a deadlock.
		}
		let ok = isVisible( field.show_if, values );
		if ( ok && field.show_if ) {
			const list = Array.isArray( field.show_if ) ? field.show_if : [ field.show_if ];
			ok = list.every( ( c ) => visit( c.key, [ ...trail, key ] ) );
		}
		memo.set( key, ok );
		return ok;
	};
	return new Set( schema.filter( ( f ) => visit( f.key, [] ) ).map( ( f ) => f.key ) );
}
