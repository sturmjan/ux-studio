import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Download, ListFilter, LoaderCircle, Pencil, Play, RotateCcw, Trash2, Upload } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';
import { type ApiCondition, type ConditionDraft, ConditionsEditor, describeConditions, fromApi, toPayload } from './Conditions';

type Tab = 'rules' | '404' | 'audit' | 'import' | 'settings';
type Code = 301 | 302 | 307 | 308 | 410;
type MatchType = 'exact' | 'regex';
type QueryMode = 'default' | 'exact' | 'ignore' | 'pass';

interface Rule {
	id: number;
	source: string;
	match_type: MatchType;
	target: string;
	target_url: string;
	target_post_id: number;
	target_post_title: string;
	code: Code;
	enabled: boolean;
	hits: number;
	last_hit: string | null;
	origin: string;
	note: string;
	conditions: ApiCondition[];
	position: number;
	case_sensitive: boolean;
	query_mode: QueryMode;
}

interface Issue {
	code: string;
	message: string;
}

interface SaveResult {
	status: string;
	id: number | null;
	source: string;
	target_url: string;
	errors: Issue[];
	warnings: Issue[];
	index?: number;
}

interface BulkResult {
	summary: Record< string, number >;
	results: SaveResult[];
}

interface TestResult {
	tested_url: string;
	matched: boolean;
	hops: number;
	final_url: string | null;
	final_code: number | null;
	loop: boolean;
	issues: string[];
	skipped_rule_ids?: number[];
	steps?: { rule_id: number; conditional: boolean }[];
	live?: { ok: boolean; final_status: number; hops: number; chain: { url: string; status: number }[]; error?: string };
}

interface NotFoundRow {
	id: number;
	url: string;
	full_url: string;
	hits: number;
	last_seen: string;
	referrer: string;
	resolved_by: number | null;
}

interface AuditResult {
	summary: { rules: number; errors: number; warnings: number; live_checked: number; fixed: number };
	issues: { severity: 'error' | 'warning'; code: string; rule_id: number | null; source: string; message: string }[];
}

interface Draft {
	id: number | null;
	source: string;
	target: string;
	code: Code;
	match_type: MatchType;
	note: string;
	conditions: ConditionDraft[];
	position: number;
	case_sensitive: boolean;
	query_mode: QueryMode;
}

const PAGE_SIZE = 50;
const EMPTY_DRAFT: Draft = { id: null, source: '', target: '', code: 301, match_type: 'exact', note: '', conditions: [], position: 0, case_sensitive: false, query_mode: 'default' };

interface Visitor {
	logged_in: boolean;
	roles: string;
	referrer: string;
	agent: string;
	ip: string;
	cookie_name: string;
	cookie_value: string;
}
const EMPTY_VISITOR: Visitor = { logged_in: false, roles: '', referrer: '', agent: '', ip: '', cookie_name: '', cookie_value: '' };

/** The simulated visitor in the shape the REST route takes; empty fields are left out. */
function visitorPayload( v: Visitor ): Record< string, unknown > {
	const out: Record< string, unknown > = {};
	if ( v.logged_in || v.roles.trim() ) {
		out.logged_in = true;
		out.roles = v.roles;
	}
	if ( v.referrer.trim() ) {
		out.referrer = v.referrer.trim();
	}
	if ( v.agent.trim() ) {
		out.agent = v.agent.trim();
	}
	if ( v.ip.trim() ) {
		out.ip = v.ip.trim();
	}
	if ( v.cookie_name.trim() ) {
		out.cookies = { [ v.cookie_name.trim() ]: v.cookie_value };
	}
	return out;
}

const QUERY_LABELS: Record< QueryMode, string > = {
	default: __( 'By the module setting (default)', 'ux-studio' ),
	exact: __( 'Exact: no other parameters allowed', 'ux-studio' ),
	ignore: __( 'Ignore parameters', 'ux-studio' ),
	pass: __( 'Ignore and pass on to the new URL', 'ux-studio' ),
};

const CODE_LABELS: Record< Code, string > = {
	301: __( '301 Moved permanently (recommended)', 'ux-studio' ),
	308: __( '308 Permanent, keeps method', 'ux-studio' ),
	302: __( '302 Temporary', 'ux-studio' ),
	307: __( '307 Temporary, keeps method', 'ux-studio' ),
	410: __( '410 Gone (content removed)', 'ux-studio' ),
};

function codeBadge( code: number ): JSX.Element {
	const cls = code === 410 ? 'is-danger' : code === 301 || code === 308 ? 'is-success' : 'is-warning';
	return <span className={ `uxs-badge ${ cls }` }>{ code }</span>;
}

function Loading(): JSX.Element {
	return (
		<div className="uxs-loading">
			<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
		</div>
	);
}

function Issues( { result }: { result: SaveResult } ): JSX.Element | null {
	if ( ! result.errors.length && ! result.warnings.length ) {
		return null;
	}
	return (
		<ul style={ { margin: 'var(--uxs-sp-2) 0 0', paddingLeft: 'var(--uxs-sp-4)' } }>
			{ result.errors.map( ( e ) => (
				<li key={ `e-${ e.code }` }>
					<span className="uxs-badge is-danger">{ __( 'Error', 'ux-studio' ) }</span> { e.message }
				</li>
			) ) }
			{ result.warnings.map( ( w ) => (
				<li key={ `w-${ w.code }` }>
					<span className="uxs-badge is-warning">{ __( 'Warning', 'ux-studio' ) }</span> { w.message }
				</li>
			) ) }
		</ul>
	);
}

/* ------------------------------------------------------------------ */

function TestBox(): JSX.Element {
	const [ url, setUrl ] = useState( '' );
	const [ live, setLive ] = useState( false );
	const [ showVisitor, setShowVisitor ] = useState( false );
	const [ visitor, setVisitor ] = useState< Visitor >( EMPTY_VISITOR );
	const test = useMutation( {
		mutationFn: () =>
			api< TestResult[] >( 'redirects/test', {
				method: 'POST',
				body: JSON.stringify( { urls: [ url ], live, ...( showVisitor ? { visitor: visitorPayload( visitor ) } : {} ) } ),
			} ),
	} );
	const result = test.data?.[ 0 ];

	return (
		<div className="uxs-form" style={ { maxWidth: 'none', marginBottom: 'var(--uxs-sp-4)' } }>
			<div className="uxs-form__line" style={ { display: 'flex', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }>
				<div className="uxs-form__row" style={ { flex: 1 } }>
					<label htmlFor="uxs-rd-test">{ __( 'Test a URL', 'ux-studio' ) }</label>
					<input id="uxs-rd-test" type="text" value={ url } placeholder="/old-page.html" onChange={ ( e ) => setUrl( e.target.value ) } />
				</div>
				<label style={ { whiteSpace: 'nowrap' } }>
					<input type="checkbox" checked={ live } onChange={ ( e ) => setLive( e.target.checked ) } /> { __( 'Live HTTP check', 'ux-studio' ) }
				</label>
				<label style={ { whiteSpace: 'nowrap' } }>
					<input type="checkbox" checked={ showVisitor } onChange={ ( e ) => setShowVisitor( e.target.checked ) } /> { __( 'As a specific visitor', 'ux-studio' ) }
				</label>
				<button type="button" className="button" disabled={ ! url || test.isPending } onClick={ () => test.mutate() }>
					<Play size={ 14 } /> { __( 'Test', 'ux-studio' ) }
				</button>
			</div>
			{ showVisitor && (
				<div className="uxs-rd-visitor">
					<label>
						<input type="checkbox" checked={ visitor.logged_in } onChange={ ( e ) => setVisitor( { ...visitor, logged_in: e.target.checked } ) } /> { __( 'Logged in', 'ux-studio' ) }
					</label>
					<input type="text" aria-label={ __( 'User role', 'ux-studio' ) } placeholder={ __( 'role, e.g. editor', 'ux-studio' ) } value={ visitor.roles } onChange={ ( e ) => setVisitor( { ...visitor, roles: e.target.value } ) } />
					<input type="text" aria-label={ __( 'Referrer', 'ux-studio' ) } placeholder={ __( 'referrer URL', 'ux-studio' ) } value={ visitor.referrer } onChange={ ( e ) => setVisitor( { ...visitor, referrer: e.target.value } ) } />
					<input type="text" aria-label={ __( 'User agent', 'ux-studio' ) } placeholder={ __( 'user agent', 'ux-studio' ) } value={ visitor.agent } onChange={ ( e ) => setVisitor( { ...visitor, agent: e.target.value } ) } />
					<input type="text" aria-label={ __( 'IP address', 'ux-studio' ) } placeholder={ __( 'IP address', 'ux-studio' ) } value={ visitor.ip } onChange={ ( e ) => setVisitor( { ...visitor, ip: e.target.value } ) } />
					<input type="text" aria-label={ __( 'Cookie name', 'ux-studio' ) } placeholder={ __( 'cookie name', 'ux-studio' ) } value={ visitor.cookie_name } onChange={ ( e ) => setVisitor( { ...visitor, cookie_name: e.target.value } ) } />
					<input type="text" aria-label={ __( 'Cookie value', 'ux-studio' ) } placeholder={ __( 'cookie value', 'ux-studio' ) } value={ visitor.cookie_value } onChange={ ( e ) => setVisitor( { ...visitor, cookie_value: e.target.value } ) } />
				</div>
			) }
			{ test.isError && <p className="uxs-badge is-danger">{ ( test.error as Error ).message }</p> }
			{ result && (
				<div style={ { marginTop: 'var(--uxs-sp-3)' } }>
					{ ! result.matched ? (
						<p>{ __( 'No rule matches this URL.', 'ux-studio' ) }</p>
					) : (
						<p>
							{ result.final_code !== null && codeBadge( result.final_code ) }{ ' ' }
							{ result.final_url || __( '(gone)', 'ux-studio' ) }{ ' ' }
							{ /* translators: %d: number of redirect hops */ }
							<span className="uxs-badge is-info">{ sprintf( __( '%d hop(s)', 'ux-studio' ), result.hops ) }</span>
						</p>
					) }
					{ result.issues.map( ( issue ) => (
						<span key={ issue } className="uxs-badge is-danger" style={ { marginRight: 4 } }>
							{ issue }
						</span>
					) ) }
					{ ( result.skipped_rule_ids?.length ?? 0 ) > 0 && (
						<span className="uxs-badge is-info">
							{ /* translators: %d: number of rules */ }
							{ sprintf( __( '%d rule(s) for this URL skipped, their conditions do not hold for this visitor', 'ux-studio' ), result.skipped_rule_ids?.length ?? 0 ) }
						</span>
					) }
					{ result.live && (
						<ol style={ { marginTop: 'var(--uxs-sp-2)' } }>
							{ result.live.chain.map( ( hop ) => (
								<li key={ hop.url }>
									<code>{ hop.status }</code> { hop.url }
								</li>
							) ) }
							{ result.live.error && <li>{ result.live.error }</li> }
						</ol>
					) }
				</div>
			) }
		</div>
	);
}

function RuleForm( { draft, setDraft }: { draft: Draft; setDraft: ( d: Draft ) => void } ): JSX.Element {
	const [ result, setResult ] = useState< SaveResult | null >( null );
	const save = useMutation( {
		mutationFn: async (): Promise< SaveResult > => {
			const payload = {
				source: draft.source,
				target: draft.code === 410 ? '' : draft.target,
				code: draft.code,
				match_type: draft.match_type,
				note: draft.note,
				conditions: toPayload( draft.conditions ),
				position: draft.position,
				case_sensitive: draft.case_sensitive,
				query_mode: draft.query_mode,
			};
			if ( draft.id ) {
				return api< SaveResult >( `redirects/rules/${ draft.id }`, { method: 'POST', body: JSON.stringify( { ...payload, target_post_id: 0 } ) } );
			}
			const bulk = await api< BulkResult >( 'redirects/rules', {
				method: 'POST',
				body: JSON.stringify( { items: [ payload ], on_conflict: 'error' } ),
			} );
			const first = bulk.results[ 0 ];
			if ( ! first ) {
				throw new Error( __( 'Unexpected empty response.', 'ux-studio' ) );
			}
			return first;
		},
		onSuccess: ( res ) => {
			setResult( res );
			if ( res.status !== 'error' ) {
				setDraft( EMPTY_DRAFT );
				void queryClient.invalidateQueries( { queryKey: [ 'redirects' ] } );
			}
		},
	} );

	return (
		<div className="uxs-form" style={ { maxWidth: 'none', marginBottom: 'var(--uxs-sp-4)' } }>
			<div className="uxs-form__line" style={ { display: 'flex', flexWrap: 'wrap', alignItems: 'flex-end', gap: 'var(--uxs-sp-3)' } }>
				<div className="uxs-form__row" style={ { flex: '1 1 220px' } }>
					<label htmlFor="uxs-rd-source">{ __( 'Old URL', 'ux-studio' ) }</label>
					<input id="uxs-rd-source" type="text" value={ draft.source } placeholder="/old-page.html" onChange={ ( e ) => setDraft( { ...draft, source: e.target.value } ) } />
				</div>
				<div className="uxs-form__row" style={ { flex: '1 1 220px' } }>
					<label htmlFor="uxs-rd-target">{ __( 'New URL', 'ux-studio' ) }</label>
					<input
						id="uxs-rd-target"
						type="text"
						value={ draft.code === 410 ? '' : draft.target }
						disabled={ draft.code === 410 }
						placeholder="/new-page/"
						onChange={ ( e ) => setDraft( { ...draft, target: e.target.value } ) }
					/>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-rd-code">{ __( 'Type', 'ux-studio' ) }</label>
					<select id="uxs-rd-code" value={ draft.code } onChange={ ( e ) => setDraft( { ...draft, code: Number( e.target.value ) as Code } ) }>
						{ ( Object.keys( CODE_LABELS ) as unknown as Code[] ).map( ( c ) => (
							<option key={ c } value={ c }>
								{ CODE_LABELS[ c ] }
							</option>
						) ) }
					</select>
				</div>
				<div className="uxs-form__row">
					<label htmlFor="uxs-rd-match">{ __( 'Match', 'ux-studio' ) }</label>
					<select id="uxs-rd-match" value={ draft.match_type } onChange={ ( e ) => setDraft( { ...draft, match_type: e.target.value as MatchType } ) }>
						<option value="exact">{ __( 'Exact URL', 'ux-studio' ) }</option>
						<option value="regex">{ __( 'Regular expression', 'ux-studio' ) }</option>
					</select>
				</div>
				<div className="uxs-form__row" style={ { flex: '1 1 160px' } }>
					<label htmlFor="uxs-rd-note">{ __( 'Note', 'ux-studio' ) }</label>
					<input id="uxs-rd-note" type="text" value={ draft.note } onChange={ ( e ) => setDraft( { ...draft, note: e.target.value } ) } />
				</div>
				<button type="button" className="button button-primary" disabled={ ! draft.source || save.isPending } onClick={ () => save.mutate() }>
					{ draft.id ? __( 'Update redirect', 'ux-studio' ) : __( 'Add redirect', 'ux-studio' ) }
				</button>
				{ draft.id && (
					<button type="button" className="button" onClick={ () => setDraft( EMPTY_DRAFT ) }>
						{ __( 'Cancel', 'ux-studio' ) }
					</button>
				) }
			</div>
			{ draft.match_type === 'exact' && (
				<div className="uxs-rd-options">
					<label>
						<input type="checkbox" checked={ draft.case_sensitive } onChange={ ( e ) => setDraft( { ...draft, case_sensitive: e.target.checked } ) } /> { __( 'Case sensitive', 'ux-studio' ) }
					</label>
					<label htmlFor="uxs-rd-query">{ __( 'Query string', 'ux-studio' ) }</label>
					<select id="uxs-rd-query" value={ draft.query_mode } onChange={ ( e ) => setDraft( { ...draft, query_mode: e.target.value as QueryMode } ) }>
						{ ( Object.keys( QUERY_LABELS ) as QueryMode[] ).map( ( m ) => (
							<option key={ m } value={ m }>
								{ QUERY_LABELS[ m ] }
							</option>
						) ) }
					</select>
				</div>
			) }
			<ConditionsEditor conditions={ draft.conditions } onChange={ ( conditions ) => setDraft( { ...draft, conditions } ) } />
			{ draft.conditions.length > 0 && (
				<div className="uxs-rd-cond__order">
					<label htmlFor="uxs-rd-position">{ __( 'Order among redirects of this URL (lower first)', 'ux-studio' ) }</label>
					<input id="uxs-rd-position" type="number" value={ draft.position } onChange={ ( e ) => setDraft( { ...draft, position: Number( e.target.value ) || 0 } ) } />
				</div>
			) }
			{ save.isError && <p className="uxs-badge is-danger">{ ( save.error as Error ).message }</p> }
			{ result && (
				<>
					{ result.status !== 'error' && <span className="uxs-badge is-success">{ __( 'Saved', 'ux-studio' ) }</span> }
					<Issues result={ result } />
				</>
			) }
		</div>
	);
}

function RulesTab( { draft, setDraft }: { draft: Draft; setDraft: ( d: Draft ) => void } ): JSX.Element {
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 0 );

	const rules = useQuery( {
		queryKey: [ 'redirects', 'rules', search, page ],
		queryFn: () => api< Rule[] >( `redirects/rules?search=${ encodeURIComponent( search ) }&limit=${ PAGE_SIZE }&offset=${ page * PAGE_SIZE }` ),
	} );

	// How many rules of the shown page share one old URL (the ones without a condition among them are fallbacks).
	const sharedSources = new Map< string, number >();
	for ( const rule of rules.data ?? [] ) {
		sharedSources.set( rule.source, ( sharedSources.get( rule.source ) ?? 0 ) + 1 );
	}

	const invalidate = () => void queryClient.invalidateQueries( { queryKey: [ 'redirects' ] } );
	const toggle = useMutation( {
		mutationFn: ( rule: Rule ) => api( `redirects/rules/${ rule.id }`, { method: 'POST', body: JSON.stringify( { enabled: ! rule.enabled } ) } ),
		onSuccess: invalidate,
	} );
	const remove = useMutation( {
		mutationFn: ( id: number ) => api( 'redirects/rules', { method: 'DELETE', body: JSON.stringify( { ids: [ id ] } ) } ),
		onSuccess: invalidate,
	} );
	const resetHits = useMutation( {
		mutationFn: ( ids: number[] | null ) => api( 'redirects/rules/reset-hits', { method: 'POST', body: JSON.stringify( ids ? { ids } : { all: true } ) } ),
		onSuccess: invalidate,
	} );

	return (
		<>
			<TestBox />
			<RuleForm draft={ draft } setDraft={ setDraft } />
			<div className="uxs-form uxs-form--stacked" style={ { maxWidth: 320, marginBottom: 'var(--uxs-sp-3)' } }>
				<div className="uxs-form__row">
					<input
						type="search"
						aria-label={ __( 'Search redirects…', 'ux-studio' ) }
						value={ search }
						placeholder={ __( 'Search redirects…', 'ux-studio' ) }
						onChange={ ( e ) => {
							setPage( 0 );
							setSearch( e.target.value );
						} }
					/>
				</div>
			</div>
			{ rules.data && rules.data.some( ( r ) => r.hits > 0 ) && (
				<p style={ { marginBottom: 'var(--uxs-sp-3)' } }>
					<button
						type="button"
						className="button button-small"
						disabled={ resetHits.isPending }
						onClick={ () => {
							// eslint-disable-next-line no-alert
							if ( window.confirm( __( 'Reset the hit counters of ALL redirects to zero?', 'ux-studio' ) ) ) {
								resetHits.mutate( null );
							}
						} }
					>
						<RotateCcw size={ 14 } /> { __( 'Reset all hit counters', 'ux-studio' ) }
					</button>
				</p>
			) }
			{ rules.isLoading && <Loading /> }
			{ rules.data && rules.data.length === 0 && <p>{ __( 'No redirects yet.', 'ux-studio' ) }</p> }
			{ rules.data && rules.data.length > 0 && (
				<>
					<div className="uxs-table-wrap"><table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'Old URL', 'ux-studio' ) }</th>
								<th>{ __( 'New URL', 'ux-studio' ) }</th>
								<th>{ __( 'Code', 'ux-studio' ) }</th>
								<th>{ __( 'Hits', 'ux-studio' ) }</th>
								<th>{ __( 'Last hit', 'ux-studio' ) }</th>
								<th>{ __( 'Origin', 'ux-studio' ) }</th>
								<th>{ __( 'Active', 'ux-studio' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ rules.data.map( ( rule ) => (
												<tr key={ rule.id } style={ rule.enabled ? undefined : { opacity: 0.55 } }>
													<td>
														<code>{ rule.source }</code>
														{ rule.match_type === 'regex' && <span className="uxs-badge is-info"> regex</span> }
														{ rule.case_sensitive && <span className="uxs-badge" style={ { marginLeft: 4 } }>{ __( 'Case sensitive', 'ux-studio' ) }</span> }
														{ rule.query_mode !== 'default' && (
															<span className="uxs-badge" style={ { marginLeft: 4 } } title={ QUERY_LABELS[ rule.query_mode ] }>
																{ /* translators: %s: how the query string is handled (exact, ignore, pass) */ }
																{ sprintf( __( 'Parameters: %s', 'ux-studio' ), rule.query_mode ) }
															</span>
														) }
														{ rule.conditions.length > 0 && (
															<span className="uxs-badge is-warning" style={ { marginLeft: 4 } } title={ describeConditions( rule.conditions ) }>
																<ListFilter size={ 12 } /> { __( 'Condition', 'ux-studio' ) }
															</span>
														) }
														{ rule.conditions.length === 0 && ( sharedSources.get( rule.source ) ?? 0 ) > 1 && (
															<span className="uxs-badge" style={ { marginLeft: 4 } } title={ __( 'This URL has several redirects. This one has no condition, so it is the fallback and is tried last.', 'ux-studio' ) }>
																{ __( 'Fallback', 'ux-studio' ) }
															</span>
														) }
														{ rule.conditions.length > 0 && <div style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>{ describeConditions( rule.conditions ) }</div> }
														{ rule.note && <div style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>{ rule.note }</div> }
													</td>
									<td>
										{ rule.code === 410 ? '-' : rule.target_url }
										{ rule.target_post_id > 0 && <div style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>{ rule.target_post_title }</div> }
									</td>
									<td>{ codeBadge( rule.code ) }</td>
									<td>{ rule.hits }</td>
									<td style={ { whiteSpace: 'nowrap' } }>{ rule.last_hit ? formatDate( rule.last_hit ) : '-' }</td>
									<td>{ rule.origin }</td>
									<td>
										<input type="checkbox" checked={ rule.enabled } onChange={ () => toggle.mutate( rule ) } aria-label={ __( 'Active', 'ux-studio' ) } />
									</td>
									<td style={ { whiteSpace: 'nowrap' } }>
										<button
											type="button"
											className="button button-small"
											aria-label={ __( 'Edit', 'ux-studio' ) }
											onClick={ () =>
												setDraft( {
													id: rule.id,
													source: rule.source,
													target: rule.target_post_id > 0 ? rule.target_url : rule.target,
													code: rule.code,
													match_type: rule.match_type,
													note: rule.note,
													conditions: rule.conditions.map( fromApi ),
													position: rule.position,
													case_sensitive: rule.case_sensitive,
													query_mode: rule.query_mode,
												} )
											}
										>
											<Pencil size={ 14 } />
										</button>{ ' ' }
										{ ( rule.hits > 0 || rule.last_hit ) && (
											<>
												<button type="button" className="button button-small" aria-label={ __( 'Reset hit counter', 'ux-studio' ) } title={ __( 'Reset hit counter', 'ux-studio' ) } disabled={ resetHits.isPending } onClick={ () => resetHits.mutate( [ rule.id ] ) }>
													<RotateCcw size={ 14 } />
												</button>{ ' ' }
											</>
										) }
										<button
											type="button"
											className="button button-small"
											aria-label={ __( 'Delete', 'ux-studio' ) }
											onClick={ () => {
												// eslint-disable-next-line no-alert
												if ( window.confirm( __( 'Delete this redirect?', 'ux-studio' ) ) ) {
													remove.mutate( rule.id );
												}
											} }
										>
											<Trash2 size={ 14 } />
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table></div>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
						<button type="button" className="button" disabled={ page === 0 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							{ __( 'Previous', 'ux-studio' ) }
						</button>
						<button type="button" className="button" disabled={ rules.data.length < PAGE_SIZE } onClick={ () => setPage( ( p ) => p + 1 ) }>
							{ __( 'Next', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }
		</>
	);
}

function NotFoundTab( { onRedirect }: { onRedirect: ( url: string ) => void } ): JSX.Element {
	const [ unresolved, setUnresolved ] = useState( true );
	const [ page, setPage ] = useState( 0 );
	const log = useQuery( {
		queryKey: [ 'redirects', '404', unresolved, page ],
		queryFn: () => api< NotFoundRow[] >( `redirects/404?unresolved_only=${ unresolved ? 1 : 0 }&limit=${ PAGE_SIZE }&offset=${ page * PAGE_SIZE }` ),
	} );
	const clear = useMutation( {
		mutationFn: ( ids: number[] | null ) =>
			api( 'redirects/404', { method: 'DELETE', body: JSON.stringify( ids ? { ids } : { all: true } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'redirects', '404' ] } ),
	} );

	return (
		<>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-4)', alignItems: 'center', marginBottom: 'var(--uxs-sp-4)' } }>
				<label>
					<input type="checkbox" checked={ unresolved } onChange={ ( e ) => setUnresolved( e.target.checked ) } />{ ' ' }
					{ __( 'Only URLs without a redirect', 'ux-studio' ) }
				</label>
				<button
					type="button"
					className="button"
					disabled={ clear.isPending }
					onClick={ () => {
						// eslint-disable-next-line no-alert
						if ( window.confirm( __( 'Clear the whole 404 log?', 'ux-studio' ) ) ) {
							clear.mutate( null );
						}
					} }
				>
					<Trash2 size={ 14 } /> { __( 'Clear log', 'ux-studio' ) }
				</button>
			</div>
			{ log.isLoading && <Loading /> }
			{ log.data && log.data.length === 0 && <p>{ __( 'No 404 errors logged.', 'ux-studio' ) }</p> }
			{ log.data && log.data.length > 0 && (
				<>
					<div className="uxs-table-wrap"><table className="uxs-table">
						<thead>
							<tr>
								<th>{ __( 'URL', 'ux-studio' ) }</th>
								<th>{ __( 'Hits', 'ux-studio' ) }</th>
								<th>{ __( 'Last seen', 'ux-studio' ) }</th>
								<th>{ __( 'Referrer', 'ux-studio' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ log.data.map( ( row ) => (
								<tr key={ row.id }>
									<td>
										<code>{ row.url }</code>
										{ row.resolved_by && <span className="uxs-badge is-success"> #{ row.resolved_by }</span> }
									</td>
									<td>{ row.hits }</td>
									<td>{ formatDate( row.last_seen ) }</td>
									<td style={ { maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }>{ row.referrer }</td>
									<td style={ { whiteSpace: 'nowrap' } }>
										{ ! row.resolved_by && (
											<button type="button" className="button button-small" onClick={ () => onRedirect( row.url ) }>
												{ __( 'Redirect…', 'ux-studio' ) }
											</button>
										) }{ ' ' }
										<button type="button" className="button button-small" aria-label={ __( 'Delete', 'ux-studio' ) } onClick={ () => clear.mutate( [ row.id ] ) }>
											<Trash2 size={ 14 } />
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table></div>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-4)' } }>
						<button type="button" className="button" disabled={ page === 0 } onClick={ () => setPage( ( p ) => p - 1 ) }>
							{ __( 'Previous', 'ux-studio' ) }
						</button>
						<button type="button" className="button" disabled={ log.data.length < PAGE_SIZE } onClick={ () => setPage( ( p ) => p + 1 ) }>
							{ __( 'Next', 'ux-studio' ) }
						</button>
					</div>
				</>
			) }
		</>
	);
}

function AuditTab(): JSX.Element {
	const audit = useMutation( {
		mutationFn: ( opts: { live?: boolean; fix_chains?: boolean } ) => api< AuditResult >( 'redirects/audit', { method: 'POST', body: JSON.stringify( opts ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'redirects', 'rules' ] } ),
	} );
	const data = audit.data;

	return (
		<>
			<p style={ { color: 'var(--uxs-text-soft)' } }>
				{ __( 'Checks loops, redirect chains, removed target pages, long-running temporary redirects, mass redirects to the homepage (soft 404) and old URLs that have live content again.', 'ux-studio' ) }
			</p>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" disabled={ audit.isPending } onClick={ () => audit.mutate( {} ) }>
					{ __( 'Run audit', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ audit.isPending } onClick={ () => audit.mutate( { live: true } ) }>
					{ __( 'Audit + check targets over HTTP', 'ux-studio' ) }
				</button>
				<button type="button" className="button" disabled={ audit.isPending } onClick={ () => audit.mutate( { fix_chains: true } ) }>
					{ __( 'Fix chains', 'ux-studio' ) }
				</button>
			</div>
			{ audit.isPending && <Loading /> }
			{ audit.isError && <p className="uxs-badge is-danger">{ ( audit.error as Error ).message }</p> }
			{ data && (
				<>
					<div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 'var(--uxs-sp-3)', marginBottom: 'var(--uxs-sp-4)' } }>
						<div className="uxs-card">
							<div className="uxs-card__num">{ data.summary.rules }</div>
							<div className="uxs-card__label">{ __( 'Active rules', 'ux-studio' ) }</div>
						</div>
						<div className="uxs-card">
							<div className="uxs-card__num">{ data.summary.errors }</div>
							<div className="uxs-card__label">{ __( 'Errors', 'ux-studio' ) }</div>
						</div>
						<div className="uxs-card">
							<div className="uxs-card__num">{ data.summary.warnings }</div>
							<div className="uxs-card__label">{ __( 'Warnings', 'ux-studio' ) }</div>
						</div>
						<div className="uxs-card">
							<div className="uxs-card__num">{ data.summary.fixed }</div>
							<div className="uxs-card__label">{ __( 'Chains fixed', 'ux-studio' ) }</div>
						</div>
					</div>
					{ data.issues.length === 0 ? (
						<p>
							<span className="uxs-badge is-success">{ __( 'No problems found.', 'ux-studio' ) }</span>
						</p>
					) : (
						<div className="uxs-table-wrap"><table className="uxs-table">
							<tbody>
								{ data.issues.map( ( issue, i ) => (
									<tr key={ i }>
										<td>
											<span className={ `uxs-badge ${ issue.severity === 'error' ? 'is-danger' : 'is-warning' }` }>{ issue.code }</span>
										</td>
										<td>
											<code>{ issue.source }</code>
										</td>
										<td>{ issue.message }</td>
									</tr>
								) ) }
							</tbody>
						</table></div>
					) }
				</>
			) }
		</>
	);
}

function BulkSummary( { result }: { result: BulkResult } ): JSX.Element {
	return (
		<div style={ { marginTop: 'var(--uxs-sp-3)' } }>
			<p>
				{ Object.entries( result.summary ).map( ( [ status, count ] ) => (
					<span key={ status } className={ `uxs-badge ${ status === 'error' ? 'is-danger' : 'is-info' }` } style={ { marginRight: 4 } }>
						{ status }: { count }
					</span>
				) ) }
			</p>
			{ result.results.slice( 0, 100 ).map( ( r, i ) => (
				<div key={ i } style={ { marginBottom: 'var(--uxs-sp-2)' } }>
					<code>{ r.source }</code>
					<Issues result={ r } />
				</div>
			) ) }
		</div>
	);
}

function ImportTab(): JSX.Element {
	const [ csv, setCsv ] = useState( '' );
	const status = useQuery( {
		queryKey: [ 'redirects', 'redirection-status' ],
		queryFn: () => api< { available: boolean } >( 'redirects/import/redirection' ),
	} );
	const importCsv = useMutation( {
		mutationFn: ( dry: boolean ) => api< BulkResult >( 'redirects/import/csv', { method: 'POST', body: JSON.stringify( { csv, dry_run: dry } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'redirects', 'rules' ] } ),
	} );
	const importRedirection = useMutation( {
		mutationFn: ( dry: boolean ) => api< BulkResult >( 'redirects/import/redirection', { method: 'POST', body: JSON.stringify( { dry_run: dry } ) } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: [ 'redirects', 'rules' ] } ),
	} );
	const exportCsv = useMutation( {
		mutationFn: () => api< { csv: string } >( 'redirects/export' ),
		onSuccess: ( data ) => {
			const link = document.createElement( 'a' );
			link.href = URL.createObjectURL( new Blob( [ data.csv ], { type: 'text/csv;charset=utf-8' } ) );
			link.download = 'redirects.csv';
			link.click();
			URL.revokeObjectURL( link.href );
		},
	} );

	return (
		<>
			<h3>{ __( 'CSV import', 'ux-studio' ) }</h3>
			<p style={ { color: 'var(--uxs-text-soft)' } }>
				{ __( 'One redirect per line: source, target, code, note. Header row optional, comma or semicolon separated. Empty target = 410 Gone. The export also has "conditions" (JSON), "position", "case_sensitive", "query_mode", "hits" and "last_hit" columns; the import reads them (counters only for new redirects).', 'ux-studio' ) }
			</p>
			<textarea
				rows={ 8 }
				style={ { width: '100%', fontFamily: 'monospace' } }
				value={ csv }
				placeholder={ 'source,target,code\n/old-page.html,/new-page/,301\n/removed.html,,410' }
				onChange={ ( e ) => setCsv( e.target.value ) }
			/>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginTop: 'var(--uxs-sp-2)' } }>
				<button type="button" className="button" disabled={ ! csv || importCsv.isPending } onClick={ () => importCsv.mutate( true ) }>
					{ __( 'Check (dry run)', 'ux-studio' ) }
				</button>
				<button type="button" className="button button-primary" disabled={ ! csv || importCsv.isPending } onClick={ () => importCsv.mutate( false ) }>
					<Upload size={ 14 } /> { __( 'Import', 'ux-studio' ) }
				</button>
			</div>
			{ importCsv.isError && <p className="uxs-badge is-danger">{ ( importCsv.error as Error ).message }</p> }
			{ importCsv.data && <BulkSummary result={ importCsv.data } /> }

			{ status.data?.available && (
				<>
					<h3>{ __( 'Import from the Redirection plugin', 'ux-studio' ) }</h3>
					<p style={ { color: 'var(--uxs-text-soft)' } }>
						{ __( 'Copies URL redirects, 410 rules and rules that depend on login, role, referrer, browser, cookie, IP address, domain or HTTP header (they become redirects with conditions). Matches on language or a custom filter are skipped. Deactivate Redirection afterwards so the two do not compete.', 'ux-studio' ) }
					</p>
					<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)' } }>
						<button type="button" className="button" disabled={ importRedirection.isPending } onClick={ () => importRedirection.mutate( true ) }>
							{ __( 'Check (dry run)', 'ux-studio' ) }
						</button>
						<button type="button" className="button button-primary" disabled={ importRedirection.isPending } onClick={ () => importRedirection.mutate( false ) }>
							{ __( 'Import', 'ux-studio' ) }
						</button>
					</div>
					{ importRedirection.data && <BulkSummary result={ importRedirection.data } /> }
				</>
			) }

			<h3>{ __( 'Export', 'ux-studio' ) }</h3>
			<button type="button" className="button" disabled={ exportCsv.isPending } onClick={ () => exportCsv.mutate() }>
				<Download size={ 14 } /> { __( 'Download CSV', 'ux-studio' ) }
			</button>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'rules' );
	const [ draft, setDraft ] = useState< Draft >( EMPTY_DRAFT );
	const settings = useModuleSettings( 'redirects' );

	const tabs: [ Tab, string ][] = [
		[ 'rules', __( 'Redirects', 'ux-studio' ) ],
		[ '404', __( '404 log', 'ux-studio' ) ],
		[ 'audit', __( 'SEO audit', 'ux-studio' ) ],
		[ 'import', __( 'Import / export', 'ux-studio' ) ],
		[ 'settings', __( 'Settings', 'ux-studio' ) ],
	];

	return (
		<>
			<header className="uxs-pagehead">
				<h1>
					<button
						type="button"
						onClick={ () => navigate( '' ) }
						aria-label={ __( 'Back to modules', 'ux-studio' ) }
						style={ { background: 'none', border: 'none', cursor: 'pointer', verticalAlign: 'middle' } }
					>
						<ArrowLeft size={ 18 } />
					</button>{ ' ' }
					{ __( 'Redirects', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				{ tabs.map( ( [ id, label ] ) => (
					<button key={ id } className={ tab === id ? 'is-active' : '' } onClick={ () => setTab( id ) }>
						{ label }
					</button>
				) ) }
			</TabsNav>
			{ tab === 'rules' && <RulesTab draft={ draft } setDraft={ setDraft } /> }
			{ tab === '404' && (
				<NotFoundTab
					onRedirect={ ( url ) => {
						setDraft( { ...EMPTY_DRAFT, source: url } );
						setTab( 'rules' );
					} }
				/>
			) }
			{ tab === 'audit' && <AuditTab /> }
			{ tab === 'import' && <ImportTab /> }
			{ tab === 'settings' && ( settings.isLoading || ! settings.data ) && <Loading /> }
			{ tab === 'settings' && settings.data && (
				<>
					<SettingsFields schema={ settings.data.schema } draft={ settings.draft } setDraft={ settings.setDraft } />
					<button type="button" className="button button-primary" onClick={ () => settings.save.mutate() }>
						{ settings.saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
