import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, LoaderCircle, ShieldAlert, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface CspViolation {
	id: number;
	directive: string;
	blocked_host: string;
	blocked_uri: string;
	document_uri: string;
	source_file: string;
	hit_count: number;
	status: 'open' | 'resolved';
	first_seen: string;
	last_seen: string;
}

const QUERY_KEY = [ 'security-optimization', 'csp-violations' ];

/**
 * CSP violation reports tab. Lists violations reported by visitor browsers
 * (via the public POST /security-optimization/csp-report endpoint) so an
 * admin can review and resolve legitimate CSP tightening work.
 *
 * Named export (not default) - composed into the module's shared Page.tsx
 * as one tab among others.
 */
export function CspTab(): JSX.Element {
	const [ statusFilter, setStatusFilter ] = useState< '' | 'open' | 'resolved' >( 'open' );

	const query = useQuery( {
		queryKey: [ ...QUERY_KEY, statusFilter ],
		queryFn: () =>
			api< CspViolation[] >(
				`security-optimization/csp-violations${ statusFilter ? `?status=${ statusFilter }` : '' }`
			),
	} );

	const resolve = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/csp-violations/${ id }/resolve`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QUERY_KEY } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/csp-violations/${ id }`, { method: 'DELETE' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: QUERY_KEY } ),
	} );

	return (
		<>
			<div style={ { display: 'flex', gap: 'var(--uxs-sp-3)', marginBottom: 'var(--uxs-sp-4)', alignItems: 'center' } }>
				<ShieldAlert size={ 16 } />
				<p style={ { margin: 0, color: 'var(--uxs-text-soft)' } }>
					{ __( 'Real CSP violations reported by visitor browsers. Resolve entries once you have allowed or fixed the source.', 'ux-studio' ) }
				</p>
			</div>

			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<select value={ statusFilter } onChange={ ( e ) => setStatusFilter( e.target.value as typeof statusFilter ) }>
					<option value="open">{ __( 'Open', 'ux-studio' ) }</option>
					<option value="resolved">{ __( 'Resolved', 'ux-studio' ) }</option>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
				</select>
			</div>

			{ query.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! query.data || query.data.length === 0 ? (
				<p>{ __( 'No CSP violations reported.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Blocked source', 'ux-studio' ) }</th>
							<th>{ __( 'Directive', 'ux-studio' ) }</th>
							<th>{ __( 'Hits', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Last seen', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ query.data.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<strong>{ row.blocked_host || __( '(unknown)', 'ux-studio' ) }</strong>
									<div style={ { fontSize: 'var(--uxs-fs-s)', color: 'var(--uxs-text-soft)' } }>{ row.blocked_uri }</div>
								</td>
								<td>
									<code>{ row.directive }</code>
								</td>
								<td>{ row.hit_count }</td>
								<td>
									<span className={ `uxs-badge ${ row.status === 'open' ? 'is-danger' : 'is-success' }` }>
										{ row.status === 'open' ? __( 'Open', 'ux-studio' ) : __( 'Resolved', 'ux-studio' ) }
									</span>
								</td>
								<td>{ row.last_seen }</td>
								<td style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
									{ row.status !== 'resolved' && (
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Mark resolved', 'ux-studio' ) }
											disabled={ resolve.isPending }
											onClick={ () => resolve.mutate( row.id ) }
										>
											<Check size={ 14 } />
										</button>
									) }
									<button
										type="button"
										className="button-link"
										aria-label={ __( 'Delete', 'ux-studio' ) }
										disabled={ remove.isPending }
										onClick={ () => remove.mutate( row.id ) }
									>
										<Trash2 size={ 14 } />
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}
