/**
 * Pure helpers of the Tables editor: delimited text (clipboard from Excel /
 * Google Sheets, CSV files) to a grid and back.
 */

export type Grid = string[][];

/**
 * Parse delimited text with RFC 4180 quoting (a quoted cell may contain the
 * delimiter, quotes doubled, and line breaks, which is how Excel copies
 * multi-line cells).
 */
export function parseDelimited( input: string, delimiter: string ): Grid {
	const rows: Grid = [];
	let row: string[] = [];
	let cell = '';
	let quoted = false;
	let i = 0;
	const text = input.replace( /\r\n?/g, '\n' );

	while ( i < text.length ) {
		const ch = text.charAt( i );
		if ( quoted ) {
			if ( ch === '"' && text.charAt( i + 1 ) === '"' ) {
				cell += '"';
				i += 2;
				continue;
			}
			if ( ch === '"' ) {
				quoted = false;
			} else {
				cell += ch;
			}
		} else if ( ch === '"' && cell === '' ) {
			quoted = true;
		} else if ( ch === delimiter ) {
			row.push( cell );
			cell = '';
		} else if ( ch === '\n' ) {
			row.push( cell );
			rows.push( row );
			row = [];
			cell = '';
		} else {
			cell += ch;
		}
		i++;
	}
	if ( cell !== '' || row.length ) {
		row.push( cell );
		rows.push( row );
	}
	return rows;
}

/** Tab for clipboard data, otherwise the most frequent of ; , tab in the first line. */
export function detectDelimiter( text: string ): string {
	const first = text.split( /\r?\n/ )[ 0 ] ?? '';
	let best = ',';
	let max = 0;
	for ( const candidate of [ ',', ';', '\t' ] ) {
		const count = first.split( candidate ).length - 1;
		if ( count > max ) {
			max = count;
			best = candidate;
		}
	}
	return best;
}

/** Excel multi-line cells become <br>, because a grid input is a single line. */
export function toCellText( value: string ): string {
	return value.replace( /\n/g, '<br>' );
}

/** Make every row as long as the longest one (CSV rows are often ragged). */
export function rectangular( rows: Grid ): Grid {
	const width = rows.reduce( ( max, r ) => Math.max( max, r.length ), 0 );
	return rows.map( ( r ) => [ ...r, ...Array< string >( width - r.length ).fill( '' ) ] );
}

/** Formula-injection guard of the PHP Csv helper: such a cell gets a leading apostrophe. */
function safeCell( value: string ): string {
	return value !== '' && '=+-@\t\r'.includes( value.charAt( 0 ) ) ? `'${ value }` : value;
}

/** Undo safeCell() on import so an export/import round trip keeps "+420 ..." intact. */
function unguardCell( value: string ): string {
	return /^'[=+\-@]/.test( value ) ? value.slice( 1 ) : value;
}

export function parseCsv( text: string ): Grid {
	const clean = text.replace( /^﻿/, '' );
	return rectangular( parseDelimited( clean, detectDelimiter( clean ) ).map( ( r ) => r.map( ( c ) => toCellText( unguardCell( c ) ) ) ) );
}

/** CSV with a BOM and comma delimiter; opens correctly in Excel and Sheets. */
export function toCsv( rows: Grid ): string {
	const encode = ( value: string ) => {
		const v = safeCell( value );
		return /[",\n\r]/.test( v ) ? `"${ v.replace( /"/g, '""' ) }"` : v;
	};
	return '﻿' + rows.map( ( r ) => r.map( encode ).join( ',' ) ).join( '\r\n' ) + '\r\n';
}

/** Letters of a column: 0 = A, 25 = Z, 26 = AA. */
export function colName( index: number ): string {
	let n = index;
	let name = '';
	do {
		name = String.fromCharCode( 65 + ( n % 26 ) ) + name;
		n = Math.floor( n / 26 ) - 1;
	} while ( n >= 0 );
	return name;
}

/**
 * Write a pasted block into the grid starting at (row, col); the grid grows
 * as needed. Returns null when the result would exceed the limits.
 */
export function pasteBlock( grid: Grid, block: Grid, row: number, col: number, maxRows: number, maxCols: number ): Grid | null {
	const rowsNeeded = row + block.length;
	const colsNeeded = col + block.reduce( ( max, r ) => Math.max( max, r.length ), 0 );
	if ( rowsNeeded > maxRows || colsNeeded > maxCols ) {
		return null;
	}
	const width = Math.max( grid[ 0 ]?.length ?? 0, colsNeeded );
	const next: Grid = rectangular( grid.map( ( r ) => [ ...r ] ) ).map( ( r ) => [ ...r, ...Array< string >( width - r.length ).fill( '' ) ] );
	while ( next.length < rowsNeeded ) {
		next.push( Array< string >( width ).fill( '' ) );
	}
	block.forEach( ( cells, ri ) => {
		cells.forEach( ( value, ci ) => {
			const target = next[ row + ri ];
			if ( target ) {
				target[ col + ci ] = toCellText( value );
			}
		} );
	} );
	return next;
}
