/**
 * Hash-routing helpers. The router matches only the hash BASE (before `?`),
 * so detail pages can take params, e.g. `#/module?id=smtp-email`.
 */

/** Navigate to a route, optionally with query params. */
export function navigate( route: string, params?: Record< string, string | number > ): void {
	let q = '';
	if ( params ) {
		const usp = new URLSearchParams();
		Object.entries( params ).forEach( ( [ k, v ] ) => usp.set( k, String( v ) ) );
		q = '?' + usp.toString();
	}
	window.location.hash = '#/' + route + q;
}

/** Current route base (without `#/` prefix and query). */
export function currentRoute(): string {
	const h = window.location.hash.replace( /^#\/?/, '' );
	const i = h.indexOf( '?' );
	return i >= 0 ? h.slice( 0, i ) : h;
}

/** Query params from the current hash. */
export function hashQuery(): URLSearchParams {
	const h = window.location.hash;
	const i = h.indexOf( '?' );
	return new URLSearchParams( i >= 0 ? h.slice( i + 1 ) : '' );
}

/** One query param from the hash (or null). */
export function hashParam( key: string ): string | null {
	return hashQuery().get( key );
}
