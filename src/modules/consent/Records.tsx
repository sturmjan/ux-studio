import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';
import { Download, LoaderCircle, Search } from 'lucide-react';
import { api } from '../../app/api';
import { formatDate } from '../../app/date';

type Action = 'accept_all' | 'reject_all' | 'custom' | 'withdraw';

interface Summary {
	days: number;
	total: number;
	visitors: number;
	actions: Record< Action, number >;
	categories: { preferences: number; statistics: number; marketing: number };
	all_time: number;
	oldest: string | null;
	retention: number;
	enabled: boolean;
}

interface RecordRow {
	id: number;
	consent_id: string;
	created_at: string;
	policy_version: number;
	preferences: boolean;
	statistics: boolean;
	marketing: boolean;
	action: Action;
	page_path: string;
}

const ACTION_LABELS: Record< Action, string > = {
	accept_all: __( 'Accepted all', 'ux-studio' ),
	reject_all: __( 'Rejected all', 'ux-studio' ),
	custom: __( 'Own choice', 'ux-studio' ),
	withdraw: __( 'Withdrawn', 'ux-studio' ),
};

const ACTIONS: Action[] = [ 'accept_all', 'reject_all', 'custom', 'withdraw' ];

const yesNo = ( on: boolean ): string => ( on ? __( 'yes', 'ux-studio' ) : __( 'no', 'ux-studio' ) );

const pct = ( part: number, whole: number ): string => ( whole > 0 ? `${ Math.round( ( 100 * part ) / whole ) } %` : '-' );

function errorText( e: unknown ): string {
	return e instanceof Error ? e.message : String( e );
}

/** Overview of the answers, look-up by consent ID and the CSV export for proof. Settings sit above, on the same tab. */
export function RecordsPanel(): JSX.Element {
	const [ days, setDays ] = useState< number >( 30 );
	const [ idInput, setIdInput ] = useState< string >( '' );
	const [ lookup, setLookup ] = useState< string >( '' );
	const [ from, setFrom ] = useState< string >( '' );
	const [ to, setTo ] = useState< string >( '' );
	const [ exportError, setExportError ] = useState< string >( '' );
	const [ exporting, setExporting ] = useState< boolean >( false );

	const summary = useQuery( {
		queryKey: [ 'consent', 'records', 'summary', days ],
		queryFn: () => api< Summary >( `consent/records/summary?days=${ days }` ),
	} );

	const found = useQuery( {
		queryKey: [ 'consent', 'records', 'find', lookup ],
		queryFn: () => api< RecordRow[] >( `consent/records/find?id=${ encodeURIComponent( lookup ) }` ),
		enabled: lookup !== '',
		retry: false,
	} );

	async function exportCsv(): Promise< void > {
		setExportError( '' );
		setExporting( true );
		try {
			const qs = new URLSearchParams();
			if ( from ) {
				qs.set( 'from', from );
			}
			if ( to ) {
				qs.set( 'to', to );
			}
			if ( idInput.trim() ) {
				qs.set( 'id', idInput.trim() );
			}
			const res = await api< { url: string } >( `consent/records/export-url?${ qs.toString() }` );
			window.location.href = res.url;
		} catch ( e ) {
			setExportError( errorText( e ) );
		} finally {
			setExporting( false );
		}
	}

	const s = summary.data;

	return (
		<section style={ { marginTop: 'var(--uxs-sp-6)' } }>
			<h2>{ __( 'Recorded answers', 'ux-studio' ) }</h2>
			<p className="uxs-form__help">
				{ __(
					'Every answer to the banner is saved as proof of consent (GDPR article 7): a random anonymous ID, the time, the version of your consent policy, the categories allowed, the kind of answer and the page path. No IP address, no browser details and no query string are stored.',
					'ux-studio'
				) }
			</p>

			<p>
				<label>
					{ __( 'Period', 'ux-studio' ) }{ ' ' }
					<select value={ days } onChange={ ( e ) => setDays( Number( e.target.value ) ) }>
						<option value={ 7 }>{ __( 'Last 7 days', 'ux-studio' ) }</option>
						<option value={ 30 }>{ __( 'Last 30 days', 'ux-studio' ) }</option>
						<option value={ 90 }>{ __( 'Last 90 days', 'ux-studio' ) }</option>
						<option value={ 365 }>{ __( 'Last 365 days', 'ux-studio' ) }</option>
					</select>
				</label>
			</p>

			{ summary.isLoading || ! s ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : (
				<>
					{ ! s.enabled && <p className="uxs-form__help">{ __( 'Recording is switched off. Nothing new is saved; the records below are the ones kept from before.', 'ux-studio' ) }</p> }
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ __( 'Answer', 'ux-studio' ) }</th>
									<th>{ __( 'Count', 'ux-studio' ) }</th>
									<th>{ __( 'Share', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ ACTIONS.map( ( a ) => (
									<tr key={ a }>
										<td>{ ACTION_LABELS[ a ] }</td>
										<td>{ s.actions[ a ] }</td>
										<td>{ pct( s.actions[ a ], s.total ) }</td>
									</tr>
								) ) }
								<tr>
									<td>
										<strong>{ __( 'Total', 'ux-studio' ) }</strong>
									</td>
									<td>
										<strong>{ s.total }</strong>
									</td>
									<td />
								</tr>
							</tbody>
						</table>
					</div>
					<p className="uxs-form__help">
						{ sprintf(
							/* translators: 1: number of visitors, 2: share allowing preferences, 3: share allowing statistics, 4: share allowing marketing */
							__( 'Different visitors: %1$d. Allowed at the time of the answer: preferences %2$s, statistics %3$s, marketing %4$s of all answers.', 'ux-studio' ),
							s.visitors,
							pct( s.categories.preferences, s.total ),
							pct( s.categories.statistics, s.total ),
							pct( s.categories.marketing, s.total )
						) }{ ' ' }
						{ sprintf(
							/* translators: 1: stored records, 2: days of retention */
							__( 'Stored in total: %1$d. Records are deleted after %2$d days.', 'ux-studio' ),
							s.all_time,
							s.retention
						) }
						{ s.oldest ? ' ' + sprintf( /* translators: %s: date */ __( 'Oldest record: %s.', 'ux-studio' ), formatDate( s.oldest, { utc: true } ) ) : '' }
					</p>
				</>
			) }

			<h3>{ __( 'Find the records of one visitor', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">
				{ __( 'A visitor sees their consent ID in the cookie settings of the banner. Paste it here to see every answer given under it.', 'ux-studio' ) }
			</p>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-2)', flexWrap: 'wrap', alignItems: 'center', marginBottom: 'var(--uxs-sp-4)' } }>
				<input
					type="text"
					className="uxs-input"
					value={ idInput }
					maxLength={ 48 }
					spellCheck={ false }
					placeholder={ __( 'Consent ID', 'ux-studio' ) }
					onChange={ ( e ) => setIdInput( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							setLookup( idInput.trim() );
						}
					} }
					style={ { minWidth: 320, fontFamily: 'monospace' } }
				/>
				<button type="button" className="button" onClick={ () => setLookup( idInput.trim() ) }>
					<Search size={ 14 } /> { __( 'Find', 'ux-studio' ) }
				</button>
			</div>
			{ found.isFetching && (
				<div className="uxs-loading">
					<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ found.isError && <p className="uxs-form__help">{ errorText( found.error ) }</p> }
			{ found.data && found.data.length === 0 && <p>{ __( 'No record under this ID. It may be older than the retention, or from before records were kept.', 'ux-studio' ) }</p> }
			{ found.data && found.data.length > 0 && (
				<div className="uxs-table-wrap">
					<table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'Time', 'ux-studio' ) }</th>
								<th>{ __( 'Answer', 'ux-studio' ) }</th>
								<th>{ __( 'Preferences', 'ux-studio' ) }</th>
								<th>{ __( 'Statistics', 'ux-studio' ) }</th>
								<th>{ __( 'Marketing', 'ux-studio' ) }</th>
								<th>{ __( 'Policy version', 'ux-studio' ) }</th>
								<th>{ __( 'Page', 'ux-studio' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ found.data.map( ( r ) => (
								<tr key={ r.id }>
									<td>{ formatDate( r.created_at, { utc: true } ) }</td>
									<td>{ ACTION_LABELS[ r.action ] ?? r.action }</td>
									<td>{ yesNo( r.preferences ) }</td>
									<td>{ yesNo( r.statistics ) }</td>
									<td>{ yesNo( r.marketing ) }</td>
									<td>{ r.policy_version }</td>
									<td>{ r.page_path }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<h3>{ __( 'Export for proof', 'ux-studio' ) }</h3>
			<p className="uxs-form__help">
				{ __( 'A CSV file with the records of the chosen dates (all of them when left empty). If a consent ID is filled in above, only its records are exported. Times are in UTC.', 'ux-studio' ) }
			</p>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', flexWrap: 'wrap', alignItems: 'center' } }>
				<label>
					{ __( 'From', 'ux-studio' ) }{ ' ' }
					<input type="date" className="uxs-input" value={ from } onChange={ ( e ) => setFrom( e.target.value ) } />
				</label>
				<label>
					{ __( 'To', 'ux-studio' ) }{ ' ' }
					<input type="date" className="uxs-input" value={ to } onChange={ ( e ) => setTo( e.target.value ) } />
				</label>
				<button type="button" className="button" disabled={ exporting } onClick={ () => void exportCsv() }>
					<Download size={ 14 } /> { __( 'Export CSV', 'ux-studio' ) }
				</button>
			</div>
			{ exportError && <p className="uxs-form__help">{ exportError }</p> }
		</section>
	);
}
