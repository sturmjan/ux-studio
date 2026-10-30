import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, LoaderCircle, Wrench } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { TabsNav } from '../../app/SubNav';
import { formatDate } from '../../app/date';
import { HelpText } from '../../components/GlossaryTerm';

type Tab = 'analyze' | 'history';

interface AnalyzeResult {
	score: number;
	metrics: {
		revisions: number;
		spam_comments: number;
		auto_drafts: number;
		transients_count: number;
		transients_bytes: number;
	};
}

interface HistoryRow {
	id: number;
	created_at: string;
	score: number;
	metrics: Record< string, number >;
}

function formatBytes( bytes: number ): string {
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	if ( bytes < 1024 * 1024 ) {
		return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
	}
	return `${ ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
}

function AnalyzeTab(): JSX.Element {
	const query = useQuery( {
		queryKey: [ 'performance-optimization', 'analyze' ],
		queryFn: () => api< AnalyzeResult >( 'performance-optimization/analyze' ),
	} );

	const fix = useMutation( {
		mutationFn: ( fixId: string ) => api( `performance-optimization/fix/${ fixId }`, { method: 'POST' } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'performance-optimization', 'analyze' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'performance-optimization', 'history' ] } );
		},
	} );

	if ( query.isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( ! query.data ) {
		return <p role="alert">{ __( 'Failed to analyze.', 'ux-studio' ) }</p>;
	}

	const { score, metrics } = query.data;

	return (
		<>
			<div className="uxs-form" style={ { marginBottom: 'var(--uxs-sp-5)' } }>
				<p>
					{ __( 'Score', 'ux-studio' ) }: <strong style={ { fontSize: '1.5em' } }>{ score }</strong> / 100
				</p>
				<button type="button" className="button" disabled={ query.isFetching } onClick={ () => query.refetch() }>
					{ query.isFetching ? <LoaderCircle size={ 14 } /> : null } { __( 'Re-run analysis', 'ux-studio' ) }
				</button>
			</div>

			<div className="uxs-table-wrap"><table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Check', 'ux-studio' ) }</th>
						<th>{ __( 'Value', 'ux-studio' ) }</th>
						<th>{ __( 'Fix', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>{ __( 'Post revisions', 'ux-studio' ) }</td>
						<td>{ metrics.revisions }</td>
						<td>
							<button
								type="button"
								className="button"
								disabled={ metrics.revisions === 0 || fix.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete all saved revisions of posts and pages? The current content stays, but you can no longer go back to older versions. This cannot be undone.', 'ux-studio' ) ) ) {
										fix.mutate( 'revisions' );
									}
								} }
							>
								<Wrench size={ 14 } /> { __( 'Delete revisions', 'ux-studio' ) }
							</button>
						</td>
					</tr>
					<tr>
						<td>{ __( 'Spam comments', 'ux-studio' ) }</td>
						<td>{ metrics.spam_comments }</td>
						<td>
							<button
								type="button"
								className="button"
								disabled={ metrics.spam_comments === 0 || fix.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete all comments marked as spam? They are removed from the database for good and cannot be restored.', 'ux-studio' ) ) ) {
										fix.mutate( 'spam_comments' );
									}
								} }
							>
								<Wrench size={ 14 } /> { __( 'Delete spam comments', 'ux-studio' ) }
							</button>
						</td>
					</tr>
					<tr>
						<td>{ __( 'Auto-draft posts', 'ux-studio' ) }</td>
						<td>{ metrics.auto_drafts }</td>
						<td>—</td>
					</tr>
					<tr>
						<td>{ __( 'Transients', 'ux-studio' ) }</td>
						<td>
							{ metrics.transients_count } ({ formatBytes( metrics.transients_bytes ) })
						</td>
						<td>
							<button
								type="button"
								className="button"
								disabled={ metrics.transients_count === 0 || fix.isPending }
								onClick={ () => {
									if ( window.confirm( __( 'Delete expired temporary data (transients)? Only entries that have already expired are removed, WordPress would delete them anyway.', 'ux-studio' ) ) ) {
										fix.mutate( 'expired_transients' );
									}
								} }
							>
								<Wrench size={ 14 } /> { __( 'Delete expired transients', 'ux-studio' ) }
							</button>
						</td>
					</tr>
				</tbody>
			</table></div>
			<div className="uxs-form" style={ { marginTop: 'var(--uxs-sp-5)' } }>
				<p className="uxs-form__help">
					<strong>{ __( 'Post revisions', 'ux-studio' ) }</strong>: { __( 'WordPress saves a copy of a post every time you update it, so you can go back. Old copies pile up in the database. Deleting them keeps the current content and removes only the history.', 'ux-studio' ) }
				</p>
				<p className="uxs-form__help">
					<strong>{ __( 'Spam comments', 'ux-studio' ) }</strong>: { __( 'Comments marked as spam stay in the database until someone deletes them. Deleting them is final.', 'ux-studio' ) }
				</p>
				<p className="uxs-form__help">
					<strong>{ __( 'Transients', 'ux-studio' ) }</strong>: <HelpText text={ __( 'Temporary values that plugins keep for a while, so they do not have to compute them again ([[transient|transients]]). Expired ones are useless and safe to delete.', 'ux-studio' ) } />
				</p>
			</div>
			{ fix.isSuccess ? <p className="uxs-badge is-success">{ __( 'Fix applied.', 'ux-studio' ) }</p> : null }
		</>
	);
}

function HistoryTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'performance-optimization', 'history' ],
		queryFn: () => api< HistoryRow[] >( 'performance-optimization/history' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}
	if ( ! data || data.length === 0 ) {
		return <p>{ __( 'No history yet - run an analysis first.', 'ux-studio' ) }</p>;
	}

	return (
		<div className="uxs-table-wrap"><table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th>{ __( 'Score', 'ux-studio' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ data.map( ( row ) => (
					<tr key={ row.id }>
						<td>{ formatDate( row.created_at ) }</td>
						<td>{ row.score }</td>
					</tr>
				) ) }
			</tbody>
		</table></div>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'analyze' );

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
					{ __( 'Performance Optimization', 'ux-studio' ) }
				</h1>
			</header>
			<TabsNav>
				<button className={ tab === 'analyze' ? 'is-active' : '' } onClick={ () => setTab( 'analyze' ) }>
					{ __( 'Analyze', 'ux-studio' ) }
				</button>
				<button className={ tab === 'history' ? 'is-active' : '' } onClick={ () => setTab( 'history' ) }>
					{ __( 'History', 'ux-studio' ) }
				</button>
			</TabsNav>
			{ tab === 'analyze' && <AnalyzeTab /> }
			{ tab === 'history' && <HistoryTab /> }
		</>
	);
}
