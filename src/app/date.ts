/**
 * Dates for display, in the admin user's language (cs_CZ: "19. 10. 2026 8:09").
 *
 * REST responses carry dates the way the database stores them
 * ("2026-10-19 08:09:01"). Never print those raw - pass them through
 * formatDate(). The wall-clock value is kept as it is (most tables store
 * site-local time); pass { utc: true } for columns stored in UTC so they are
 * shown in the browser's local time.
 */

/** BCP 47 tag of the admin user's WordPress locale ("cs_CZ" -> "cs-CZ"). */
function userLocale(): string | undefined {
	const wp = ( window as unknown as { uxStudioBoot?: { locale?: string } } ).uxStudioBoot?.locale;
	return wp ? wp.replace( '_', '-' ) : undefined;
}

const MYSQL = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/;

/**
 * Parse a DB datetime ("2026-10-19 08:09:01", "2026-10-19"), an ISO string
 * with zone, or a Unix timestamp in seconds. Returns null when unparsable.
 */
function parse( value: string | number, utc: boolean ): { date: Date; hasTime: boolean } | null {
	if ( typeof value === 'number' || /^\d{9,11}$/.test( value ) ) {
		return { date: new Date( Number( value ) * 1000 ), hasTime: true };
	}
	const m = MYSQL.exec( value.trim() );
	if ( m ) {
		const [ , y, mo, d, h, mi, s ] = m;
		const parts = [ Number( y ), Number( mo ) - 1, Number( d ), Number( h ?? 0 ), Number( mi ?? 0 ), Number( s ?? 0 ) ] as const;
		const date = utc ? new Date( Date.UTC( ...parts ) ) : new Date( ...parts );
		return { date, hasTime: undefined !== h };
	}
	const iso = new Date( value );
	return Number.isNaN( iso.getTime() ) ? null : { date: iso, hasTime: /[T ]\d{2}:\d{2}/.test( value ) };
}

/**
 * Date (and time, when the value has one) in the user's format. Empty values
 * give an empty string, unparsable ones are returned unchanged.
 */
export function formatDate( value: string | number | null | undefined, options: { utc?: boolean; dateOnly?: boolean } = {} ): string {
	if ( value === null || value === undefined || value === '' || value === '0000-00-00 00:00:00' ) {
		return '';
	}
	let raw = value;
	let utc = !! options.utc;
	if ( typeof raw === 'string' && / UTC$/.test( raw ) ) {
		raw = raw.slice( 0, -4 );
		utc = true;
	}
	const parsed = parse( raw, utc );
	if ( ! parsed ) {
		return String( value );
	}
	const withTime = parsed.hasTime && ! options.dateOnly;
	return new Intl.DateTimeFormat( userLocale(), {
		day: 'numeric',
		month: 'numeric',
		year: 'numeric',
		...( withTime ? { hour: 'numeric', minute: '2-digit' } : {} ),
	} ).format( parsed.date );
}
