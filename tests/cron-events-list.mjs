// Test only (Node 22.18+): `node tests/cron-events-list.mjs`
// Search, source filter and pagination of the Cron Control events list (src/modules/cron-control/eventsList.ts).
import { filterEvents, paginate, sourceOptions } from '../src/modules/cron-control/eventsList.ts';

let failures = 0;
function check( name, actual, expected ) {
	const ok = JSON.stringify( actual ) === JSON.stringify( expected );
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + JSON.stringify( actual ) + ', expected ' + JSON.stringify( expected ) ) );
}

const events = [
	{ hook: 'wp_version_check', source: 'WordPress', schedule_label: 'Twice a day' },
	{ hook: 'woocommerce_cleanup_sessions', source: 'WooCommerce', schedule_label: 'Twice a day' },
	{ hook: 'wc_admin_daily', source: 'WooCommerce', schedule_label: 'Once a day' },
	{ hook: 'orphan_hook', source: '', schedule_label: '' },
];

check( 'no filter keeps everything', filterEvents( events, '', '' ).length, 4 );
check( 'search by hook is case-insensitive', filterEvents( events, 'WC_ADMIN', '' ).map( ( e ) => e.hook ), [ 'wc_admin_daily' ] );
check( 'search by source', filterEvents( events, 'woocommerce', '' ).length, 2 );
check( 'search by schedule label', filterEvents( events, 'once a', '' ).map( ( e ) => e.hook ), [ 'wc_admin_daily' ] );
check( 'source filter', filterEvents( events, '', 'WooCommerce' ).length, 2 );
check( 'source filter and search together', filterEvents( events, 'sessions', 'WooCommerce' ).map( ( e ) => e.hook ), [ 'woocommerce_cleanup_sessions' ] );
check( 'whitespace-only search is no search', filterEvents( events, '   ', '' ).length, 4 );
check( 'source options are unique, sorted, without empty', sourceOptions( events ), [ 'WooCommerce', 'WordPress' ] );

const list = Array.from( { length: 53 }, ( _, i ) => i );
check( 'first page of 25', paginate( list, 1, 25 ).items.length, 25 );
check( 'pages of 53 by 25', paginate( list, 1, 25 ).pages, 3 );
check( 'last page has the rest', paginate( list, 3, 25 ).items, [ 50, 51, 52 ] );
check( 'page beyond the end is clamped', paginate( list, 9, 25 ).page, 3 );
check( 'page below 1 is clamped', paginate( list, 0, 25 ).page, 1 );
check( 'empty list has one empty page', paginate( [], 1, 25 ), { items: [], page: 1, pages: 1, total: 0 } );

if ( failures > 0 ) {
	console.log( failures + ' FAILED' );
	process.exit( 1 );
}
console.log( 'ALL PASSED' );
