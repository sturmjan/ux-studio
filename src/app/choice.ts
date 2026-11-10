/**
 * The value a `select` / `image_choice` field shows: the stored one while it is one of the options, otherwise the default
 * of the field. An empty or stale value must never show as "nothing chosen" when the field has a default.
 */
export function choiceValue(
	field: { options?: Record< string, string >; default?: unknown },
	value: unknown
): string {
	const options = Object.keys( field.options ?? {} );
	const current = null === value || undefined === value ? '' : String( value );
	if ( options.includes( current ) ) {
		return current;
	}
	const fallback = null === field.default || undefined === field.default ? '' : String( field.default );
	return options.includes( fallback ) ? fallback : current;
}
