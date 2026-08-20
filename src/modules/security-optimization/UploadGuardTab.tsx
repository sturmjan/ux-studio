import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, LoaderCircle, ScanSearch, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';

interface Finding {
	id: number;
	created_at: string;
	file_path: string;
	finding_type: string;
	severity: 'low' | 'medium' | 'high' | 'critical' | '';
	status: 'queued' | 'scanned' | 'quarantined' | 'approved' | 'deleted';
	hash: string;
	details: { score?: number; matches?: Array< { label: string } >; message?: string } | null;
	scanned_at: string | null;
}

interface ScanStatus {
	state: 'idle' | 'running';
	total: number;
	remaining: number;
	scanned: number;
	detections: number;
}

interface ScanStatusResponse {
	status: ScanStatus;
	stats: { last_scan_at?: string; files_scanned?: number; detections_found?: number };
}

const FINDINGS_KEY = [ 'security-optimization', 'findings' ];
const STATUS_KEY = [ 'security-optimization', 'scan-status' ];

const SEVERITY_BADGE: Record< string, string > = {
	low: 'is-info',
	medium: 'is-warning',
	high: 'is-warning',
	critical: 'is-danger',
};

/**
 * Upload Guard tab: suspicious file findings (malware/injection detection
 * on uploads + wp-content full scans + core key-file integrity changes),
 * plus manual "Scan now" trigger and progress.
 *
 * Named export (not default) - composed into the module's shared Page.tsx
 * as one tab among others.
 */
export function UploadGuardTab(): JSX.Element {
	const [ statusFilter, setStatusFilter ] = useState< string >( 'scanned' );

	const findings = useQuery( {
		queryKey: [ ...FINDINGS_KEY, statusFilter ],
		queryFn: () =>
			api< Finding[] >(
				`security-optimization/findings${ statusFilter ? `?status=${ statusFilter }` : '' }`
			),
	} );

	const scanStatus = useQuery( {
		queryKey: STATUS_KEY,
		queryFn: () => api< ScanStatusResponse >( 'security-optimization/scan-status' ),
		refetchInterval: ( query ) => ( query.state.data?.status.state === 'running' ? 3000 : false ),
	} );

	const triggerScan = useMutation( {
		mutationFn: () => api( 'security-optimization/scan', { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: STATUS_KEY } ),
	} );

	const approve = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/findings/${ id }/approve`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: FINDINGS_KEY } ),
	} );

	const remove = useMutation( {
		mutationFn: ( id: number ) => api( `security-optimization/findings/${ id }/delete`, { method: 'POST' } ),
		onSuccess: () => void queryClient.invalidateQueries( { queryKey: FINDINGS_KEY } ),
	} );

	const status = scanStatus.data?.status;
	const isRunning = status?.state === 'running';

	return (
		<>
			<div
				style={ {
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'space-between',
					marginBottom: 'var(--uxs-sp-4)',
					gap: 'var(--uxs-sp-3)',
				} }
			>
				<p style={ { margin: 0, color: 'var(--uxs-text-soft)' } }>
					{ __( 'Uploaded files and wp-content are scanned for injected/malicious code. Critical findings are automatically moved to a protected quarantine folder.', 'ux-studio' ) }
				</p>
				<button
					type="button"
					className="button button-primary"
					disabled={ isRunning || triggerScan.isPending }
					onClick={ () => triggerScan.mutate() }
				>
					<ScanSearch size={ 14 } />{ ' ' }
					{ isRunning ? __( 'Scan running…', 'ux-studio' ) : __( 'Scan now', 'ux-studio' ) }
				</button>
			</div>

			{ status && (
				<p style={ { color: 'var(--uxs-text-soft)', fontSize: 'var(--uxs-fs-s)' } }>
					{ isRunning
						? __( 'Scanning in batches…', 'ux-studio' ) + ` ${ status.scanned }/${ status.total }`
						: scanStatus.data?.stats.last_scan_at
						? `${ __( 'Last scan:', 'ux-studio' ) } ${ scanStatus.data.stats.last_scan_at } · ${ __( 'files scanned:', 'ux-studio' ) } ${ scanStatus.data.stats.files_scanned ?? 0 } · ${ __( 'detections:', 'ux-studio' ) } ${ scanStatus.data.stats.detections_found ?? 0 }`
						: __( 'No scan has run yet.', 'ux-studio' ) }
				</p>
			) }

			<div style={ { margin: 'var(--uxs-sp-4) 0' } }>
				<select value={ statusFilter } onChange={ ( e ) => setStatusFilter( e.target.value ) }>
					<option value="scanned">{ __( 'Detected', 'ux-studio' ) }</option>
					<option value="quarantined">{ __( 'Quarantined', 'ux-studio' ) }</option>
					<option value="approved">{ __( 'Approved', 'ux-studio' ) }</option>
					<option value="queued">{ __( 'Queued', 'ux-studio' ) }</option>
					<option value="">{ __( 'All', 'ux-studio' ) }</option>
				</select>
			</div>

			{ findings.isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : ! findings.data || findings.data.length === 0 ? (
				<p>{ __( 'No findings in this category.', 'ux-studio' ) }</p>
			) : (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'File', 'ux-studio' ) }</th>
							<th>{ __( 'Type', 'ux-studio' ) }</th>
							<th>{ __( 'Severity', 'ux-studio' ) }</th>
							<th>{ __( 'Status', 'ux-studio' ) }</th>
							<th>{ __( 'Detected', 'ux-studio' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ findings.data.map( ( row ) => (
							<tr key={ row.id }>
								<td>
									<div>{ row.file_path }</div>
									{ row.details?.matches && row.details.matches.length > 0 && (
										<div style={ { fontSize: 'var(--uxs-fs-s)', color: 'var(--uxs-text-soft)' } }>
											{ row.details.matches.slice( 0, 3 ).map( ( m ) => m.label ).join( ', ' ) }
										</div>
									) }
								</td>
								<td>{ row.finding_type || '—' }</td>
								<td>
									{ row.severity ? (
										<span className={ `uxs-badge ${ SEVERITY_BADGE[ row.severity ] ?? 'is-info' }` }>{ row.severity }</span>
									) : (
										'—'
									) }
								</td>
								<td>{ row.status }</td>
								<td>{ row.scanned_at ?? row.created_at }</td>
								<td style={ { display: 'flex', gap: 'var(--uxs-sp-2)' } }>
									{ row.status !== 'approved' && row.status !== 'deleted' && (
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Approve', 'ux-studio' ) }
											disabled={ approve.isPending }
											onClick={ () => approve.mutate( row.id ) }
										>
											<Check size={ 14 } />
										</button>
									) }
									{ row.status === 'quarantined' && (
										<button
											type="button"
											className="button-link"
											aria-label={ __( 'Delete permanently', 'ux-studio' ) }
											disabled={ remove.isPending }
											onClick={ () => remove.mutate( row.id ) }
										>
											<Trash2 size={ 14 } />
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</>
	);
}
