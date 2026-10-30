// Test only (Node 22.18+): `node tests/glossary-markers.mjs`
// The `[[term]]` markers of the help texts and the term lookup (src/components/glossary.ts), as the components use them.
import { findTerm, normalizeTermId, splitHelpText } from '../src/components/glossary.ts';

let failures = 0;
function check( name, actual, expected ) {
	const ok = JSON.stringify( actual ) === JSON.stringify( expected );
	if ( ! ok ) { failures++; }
	console.log( ( ok ? 'PASS ' : 'FAIL ' ) + name + ( ok ? '' : ' => got ' + JSON.stringify( actual ) + ', expected ' + JSON.stringify( expected ) ) );
}

const data = {
	terms: { csp: { term: 'CSP', text: 'Policy.' }, 'rest-api': { term: 'REST API', text: 'Interface.' }, 'wp-cron': { term: 'WP-Cron', text: 'Scheduler.' }, 'llms-txt': { term: 'llms.txt', text: 'File.' } },
	aliases: { cron: 'wp-cron' },
};

check( 'plain text is one piece', splitHelpText( 'No markers here.' ), [ 'No markers here.' ] );
check( 'one marker', splitHelpText( 'Uses [[CSP]] now.' ), [ 'Uses ', { name: 'CSP', shown: undefined }, ' now.' ] );
check( 'marker with shown text', splitHelpText( 'See [[dns|DNS records]].' ), [ 'See ', { name: 'dns', shown: 'DNS records' }, '.' ] );
check( 'two markers in a row', splitHelpText( '[[CSP]][[REST API]]' ), [ { name: 'CSP', shown: undefined }, { name: 'REST API', shown: undefined } ] );
check( 'marker at the start and end', splitHelpText( '[[CSP]] and [[cron]]' ), [ { name: 'CSP', shown: undefined }, ' and ', { name: 'cron', shown: undefined } ] );
check( 'unclosed marker stays text', splitHelpText( 'Broken [[CSP here' ), [ 'Broken [[CSP here' ] );
check( 'a single bracket stays text', splitHelpText( 'array[0] and [x]' ), [ 'array[0] and [x]' ] );

check( 'id: case and spaces', normalizeTermId( ' REST  API ' ), 'rest-api' );
check( 'id: dots become hyphens', normalizeTermId( 'llms.txt' ), 'llms-txt' );
check( 'id: numbers', normalizeTermId( '301' ), '301' );

check( 'lookup by name', findTerm( 'CSP', data )?.term, 'CSP' );
check( 'lookup with spaces and case', findTerm( 'rest api', data )?.term, 'REST API' );
check( 'lookup through an alias', findTerm( 'cron', data )?.term, 'WP-Cron' );
check( 'lookup with a dot', findTerm( 'llms.txt', data )?.text, 'File.' );
check( 'unknown term is undefined', findTerm( 'no such thing', data ), undefined );
check( 'no glossary data is undefined', findTerm( 'CSP' ), undefined );

console.log( failures ? failures + ' FAILED' : 'ALL PASSED' );
process.exit( failures ? 1 : 0 );
