import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';

type Scenario = 'none' | 'accept' | 'reject';

interface ScenarioState {
	status: string;
	judged: boolean;
	note: string;
	cookies: number;
	requests: number;
	storage: number;
}

interface Violation {
	key: string;
	scenario: Scenario;
	kind: 'cookie' | 'request';
	name: string;
	host: string;
	category: string;
	service: string;
	phase: string;
}

interface UnknownCookie {
	name: string;
	domain: string;
	third_party: boolean;
	scenarios: Scenario[];
}

interface FoundCookie {
	name: string;
	domain: string;
	category: string;
	service: string;
	purpose: string;
	duration: string;
	third_party: boolean;
	scenarios: Scenario[];
}

interface ScanData {
	paired: boolean;
	active: boolean;
	received_at: number;
	requested_at: number;
	scanned_at: string;
	pages: string[];
	banner: { kind?: string; found?: boolean };
	scenarios: Partial< Record< Scenario, ScenarioState > >;
	summary: { cookies?: number; unknown_cookies?: number; violations?: number; new_violations?: number; new_unknown?: number };
	violations: Violation[];
	unknown_cookies: UnknownCookie[];
	unknown_hosts: string[];
	cookies: FoundCookie[];
}

const KEY = [ 'consent', 'scan' ];
const SCENARIOS: Scenario[] = [ 'none', 'accept', 'reject' ];

function scenarioLabel( s: Scenario ): string {
	switch ( s ) {
		case 'none':
			return __( 'Without consent', 'ux-studio' );
		case 'accept':
			return __( 'After "Accept all"', 'ux-studio' );
		default:
			return __( 'After "Reject all"', 'ux-studio' );
	}
}

function noteLabel( note: string, status: string ): string {
	if ( status === 'error' ) {
		return __( 'The run failed', 'ux-studio' );
	}
	if ( note === 'no_banner' ) {
		return __( 'The banner was not visible, so this run cannot be judged', 'ux-studio' );
	}
	if ( note === 'banner_not_found' ) {
		return __( 'The banner or its button was not found', 'ux-studio' );
	}
	return '';
}

function categoryLabel( category: string ): string {
	switch ( category ) {
		case 'necessary':
			return __( 'Necessary', 'ux-studio' );
		case 'preferences':
			return __( 'Preferences', 'ux-studio' );
		case 'statistics':
			return __( 'Statistics', 'ux-studio' );
		case 'marketing':
			return __( 'Marketing', 'ux-studio' );
		default:
			return __( 'Unknown', 'ux-studio' );
	}
}

/** Tab of the Cookie Consent page: what a real browser of UX1 Network app saw on the site, and what breaks the visitor's choice. */
export default function Scan(): JSX.Element {
	const scan = useQuery( { queryKey: KEY, queryFn: () => api< ScanData >( '/consent/scan' ) } );
	const request = useMutation( {
		mutationFn: () => api< { queued: boolean; message: string; state: ScanData } >( '/consent/scan/request', { method: 'POST' } ),
		onSuccess: ( data ) => queryClient.setQueryData( KEY, data.state ),
	} );

	if ( scan.isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 18 } /> { __( 'Loading…', 'ux-studio' ) }
			</div>
		);
	}
	if ( scan.isError || ! scan.data ) {
		return <p className="uxs-badge is-danger">{ ( scan.error as Error | null )?.message ?? __( 'Could not load the scan.', 'ux-studio' ) }</p>;
	}
	const data = scan.data;
	const violations = data.violations;

	return (
		<div className="uxs-form" style={ { maxWidth: 'none' } }>
			<p className="uxs-form__help">
				{ __(
					'A browser of UX1 Network app opens your home page and a few more pages three times, each time with a clean profile: without any choice, after "Accept all" and after "Reject all". It records the cookies, the storage keys and the requests to other hosts. A cookie or a request that needs consent by the catalogue, yet appears without consent or after the visitor rejected, is a violation. The scan runs once a month and on request; a request is queued and takes a few minutes.',
					'ux-studio'
				) }
			</p>
			<div className="uxs-actions">
				<button type="button" className="button button-primary" disabled={ request.isPending || ! data.active } onClick={ () => request.mutate() }>
					{ request.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Ask UX1 Network app for a scan', 'ux-studio' ) }
				</button>
				<span className="uxs-form__help">
					{ data.received_at
						? sprintf(
								/* translators: %s: date of the last scan result. */
								__( 'Last result: %s.', 'ux-studio' ),
								formatDate( data.received_at )
						  )
						: __( 'No scan result yet.', 'ux-studio' ) }
					{ data.requested_at > data.received_at ? ' ' + sprintf( /* translators: %s: date of the request. */ __( 'A scan was requested %s and is waiting.', 'ux-studio' ), formatDate( data.requested_at ) ) : '' }
				</span>
			</div>
			{ ! data.paired && <p className="uxs-badge is-danger">{ __( 'This site is not connected to UX1 Network app, so it cannot be scanned.', 'ux-studio' ) }</p> }
			{ data.paired && ! data.active && <p className="uxs-badge is-danger">{ __( 'The connection to UX1 Network app is paused.', 'ux-studio' ) }</p> }
			{ request.isError && <p className="uxs-badge is-danger">{ ( request.error as Error ).message }</p> }
			{ request.isSuccess && <p className="uxs-badge is-success">{ request.data.message }</p> }

			{ data.received_at > 0 && (
				<>
					<h3>{ __( 'Result', 'ux-studio' ) }</h3>
					<p>
						<span className={ 'uxs-badge ' + ( violations.length > 0 ? 'is-danger' : 'is-success' ) }>
							{ sprintf( /* translators: %d: number of violations. */ __( '%d violation(s)', 'ux-studio' ), violations.length ) }
						</span>{ ' ' }
						<span className="uxs-badge">{ sprintf( /* translators: %d: number of cookies. */ __( '%d cookie(s) found', 'ux-studio' ), data.summary.cookies ?? 0 ) }</span>{ ' ' }
						<span className={ 'uxs-badge' + ( ( data.summary.unknown_cookies ?? 0 ) > 0 ? ' is-warning' : '' ) }>
							{ sprintf( /* translators: %d: number of cookies. */ __( '%d unknown cookie(s)', 'ux-studio' ), data.summary.unknown_cookies ?? 0 ) }
						</span>
						{ data.banner.kind && (
							<>
								{ ' ' }
								<span className="uxs-badge">{ sprintf( /* translators: %s: name of the cookie banner. */ __( 'Banner: %s', 'ux-studio' ), data.banner.kind ) }</span>
							</>
						) }
					</p>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ __( 'Run', 'ux-studio' ) }</th>
									<th>{ __( 'Cookies', 'ux-studio' ) }</th>
									<th>{ __( 'Requests to other hosts', 'ux-studio' ) }</th>
									<th>{ __( 'Judged', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ SCENARIOS.map( ( s ) => {
									const st = data.scenarios[ s ];
									if ( ! st ) {
										return null;
									}
									const note = noteLabel( st.note, st.status );
									return (
										<tr key={ s }>
											<td>{ scenarioLabel( s ) }</td>
											<td>{ st.cookies }</td>
											<td>{ st.requests }</td>
											<td>{ s === 'accept' ? __( 'reference only', 'ux-studio' ) : st.judged ? __( 'yes', 'ux-studio' ) : note || __( 'no', 'ux-studio' ) }</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>

					<h3>{ __( 'Violations', 'ux-studio' ) }</h3>
					{ violations.length === 0 ? (
						<p>{ __( 'Nothing that needs consent appeared without consent or after a rejection in the runs that could be judged.', 'ux-studio' ) }</p>
					) : (
						<div className="uxs-table-wrap">
							<table className="uxs-table">
								<thead>
									<tr>
										<th>{ __( 'What', 'ux-studio' ) }</th>
										<th>{ __( 'Service', 'ux-studio' ) }</th>
										<th>{ __( 'Category', 'ux-studio' ) }</th>
										<th>{ __( 'When', 'ux-studio' ) }</th>
									</tr>
								</thead>
								<tbody>
									{ violations.map( ( v ) => (
										<tr key={ v.key }>
											<td>
												<code>{ v.name }</code>
												<div className="uxs-form__help">{ v.kind === 'cookie' ? __( 'cookie', 'ux-studio' ) : __( 'request', 'ux-studio' ) }</div>
											</td>
											<td>{ v.service }</td>
											<td>
												<span className="uxs-badge">{ categoryLabel( v.category ) }</span>
											</td>
											<td>{ scenarioLabel( v.scenario ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					{ data.unknown_cookies.length > 0 && (
						<>
							<h3>{ __( 'Unknown cookies', 'ux-studio' ) }</h3>
							<p className="uxs-form__help">
								{ __( 'The catalogue does not know these cookies, so they are not judged. Find out what sets them and what they are for; unknown hosts are in the queue of the Services and cookies tab.', 'ux-studio' ) }
							</p>
							<div className="uxs-table-wrap">
								<table className="uxs-table">
									<thead>
										<tr>
											<th>{ __( 'Cookie', 'ux-studio' ) }</th>
											<th>{ __( 'Domain', 'ux-studio' ) }</th>
											<th>{ __( 'Seen', 'ux-studio' ) }</th>
										</tr>
									</thead>
									<tbody>
										{ data.unknown_cookies.map( ( c ) => (
											<tr key={ c.name + c.domain }>
												<td>
													<code>{ c.name }</code>
												</td>
												<td>
													{ c.domain }
													{ c.third_party && <span className="uxs-badge" style={ { marginLeft: 6 } }>{ __( 'third party', 'ux-studio' ) }</span> }
												</td>
												<td>{ c.scenarios.map( scenarioLabel ).join( ', ' ) }</td>
											</tr>
										) ) }
									</tbody>
								</table>
							</div>
						</>
					) }

					{ data.unknown_hosts.length > 0 && (
						<p className="uxs-form__help">
							{ sprintf(
								/* translators: %s: list of hosts. */
								__( 'Unknown hosts the scan saw were added to the queue of the Services and cookies tab: %s.', 'ux-studio' ),
								data.unknown_hosts.join( ', ' )
							) }
						</p>
					) }

					<h3>{ __( 'All cookies found', 'ux-studio' ) }</h3>
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th>{ __( 'Cookie', 'ux-studio' ) }</th>
									<th>{ __( 'Service', 'ux-studio' ) }</th>
									<th>{ __( 'Category', 'ux-studio' ) }</th>
									<th>{ __( 'Seen', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.cookies.map( ( c ) => (
									<tr key={ c.name + c.domain }>
										<td>
											<code>{ c.name }</code>
											<div className="uxs-form__help">{ c.domain }</div>
										</td>
										<td>
											{ c.service }
											{ c.purpose && <div className="uxs-form__help">{ c.purpose }</div> }
										</td>
										<td>
											<span className="uxs-badge">{ categoryLabel( c.category ) }</span>
										</td>
										<td>{ c.scenarios.map( scenarioLabel ).join( ', ' ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }
		</div>
	);
}
