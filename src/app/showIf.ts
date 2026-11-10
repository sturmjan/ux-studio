/**
 * Conditional visibility of settings fields (schema property `show_if`).
 *
 * Pure and dependency free on purpose: it runs in the form and in `tests/show-if.mjs` (Node).
 * A condition looks at ONE other field of the same schema; a list of conditions is an AND, `{ any: [ ... ] }` is an OR of
 * conditions or lists (nest them for "A or (B and C)").
 *   { key, equals }  the value equals `equals` (compared as strings, so 1 and '1' match)
 *   { key, in }      the value is one of the listed ones
 *   { key, truthy }  true: the value is on / not empty; false: it is off / empty
 * A field whose condition is not met is just not drawn; its stored value is never touched.
 */
export type Scalar = string | number | boolean | unknown[] | null | undefined;

export interface ShowIfCondition {
	key: string;
	equals?: string | number | boolean;
	in?: Array< string | number | boolean >;
	truthy?: boolean;
}

export type ShowIf = ShowIfCondition | ShowIf[] | { any: ShowIf[] };

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
	if ( Array.isArray( showIf ) ) {
		return showIf.every( ( part ) => isVisible( part, values ) );
	}
	if ( 'any' in showIf ) {
		return showIf.any.some( ( part ) => isVisible( part, values ) );
	}
	return conditionMet( showIf, values );
}

/**
 * Keys of the fields that are visible. A field whose controlling field is itself hidden is hidden too
 * (a chain "toggle -> select -> text" collapses as a whole). Unknown controlling keys count as "no value".
 */
export function visibleKeys( schema: ReadonlyArray< { key: string; show_if?: ShowIf } >, values: Record< string, Scalar > ): Set< string > {
	const byKey = new Map( schema.map( ( f ) => [ f.key, f ] ) );
	const memo = new Map< string, boolean >();
	// A condition holds when its own test is true AND the field it looks at is visible itself; in an OR one such branch is enough.
	const holds = ( showIf: ShowIf, trail: string[] ): boolean => {
		if ( Array.isArray( showIf ) ) {
			return showIf.every( ( part ) => holds( part, trail ) );
		}
		if ( 'any' in showIf ) {
			return showIf.any.some( ( part ) => holds( part, trail ) );
		}
		return conditionMet( showIf, values ) && visit( showIf.key, trail );
	};
	const visit = ( key: string, trail: string[] ): boolean => {
		const cached = memo.get( key );
		if ( cached !== undefined ) {
			return cached;
		}
		const field = byKey.get( key );
		if ( ! field || trail.includes( key ) ) {
			return true; // Not a field of this form (or a loop): the plain value decides, never a deadlock.
		}
		const ok = ! field.show_if || holds( field.show_if, [ ...trail, key ] );
		memo.set( key, ok );
		return ok;
	};
	return new Set( schema.filter( ( f ) => visit( f.key, [] ) ).map( ( f ) => f.key ) );
}
