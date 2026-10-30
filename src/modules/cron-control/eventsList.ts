/**
 * Pure helpers of the Cron Control events list: search, filter by source and pagination.
 * (Kept free of React so that `node tests/cron-events-list.mjs` can run them.)
 */

export interface ListedEvent {
	hook: string;
	source: string;
	schedule_label: string;
}

/** Events whose hook, source or schedule contains the query, optionally only those of one source. */
export function filterEvents< T extends ListedEvent >( events: T[], query: string, source: string ): T[] {
	const needle = query.trim().toLowerCase();
	return events.filter( ( event ) => {
		if ( source !== '' && event.source !== source ) {
			return false;
		}
		if ( needle === '' ) {
			return true;
		}
		return (
			event.hook.toLowerCase().includes( needle ) ||
			event.source.toLowerCase().includes( needle ) ||
			event.schedule_label.toLowerCase().includes( needle )
		);
	} );
}

/** Distinct, non-empty sources in alphabetical order (for the filter select). */
export function sourceOptions( events: ListedEvent[] ): string[] {
	return [ ...new Set( events.map( ( event ) => event.source ).filter( ( s ) => s !== '' ) ) ].sort( ( a, b ) => a.localeCompare( b ) );
}

export interface Paged< T > {
	items: T[];
	page: number;
	pages: number;
	total: number;
}

/** One page of a list; the requested page is clamped to the existing ones (a filter can shrink the list). */
export function paginate< T >( items: T[], page: number, perPage: number ): Paged< T > {
	const size = Math.max( 1, Math.floor( perPage ) );
	const pages = Math.max( 1, Math.ceil( items.length / size ) );
	const current = Math.min( Math.max( 1, Math.floor( page ) || 1 ), pages );
	return { items: items.slice( ( current - 1 ) * size, current * size ), page: current, pages, total: items.length };
}
