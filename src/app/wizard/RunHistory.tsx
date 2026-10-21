/**
 * Small list of finished real runs with a rollback button while the snapshot is
 * still kept (30 days).
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { History as HistoryIcon, Undo2 } from 'lucide-react';
import { api, queryClient } from '../api';
import { formatDate } from '../date';
import { refreshOverlaps } from '../Overlaps';
import type { HistoryRun } from './types';

const KEY = [ 'migration', 'runs' ];

function statusBadge( run: HistoryRun ): JSX.Element {
	if ( run.rolled_back ) {
		return <span className="uxs-badge is-info">{ __( 'Undone', 'ux-studio' ) }</span>;
	}
	if ( run.errors > 0 ) {
		return <span className="uxs-badge is-warning">{ __( 'With errors', 'ux-studio' ) }</span>;
	}
	return <span className="uxs-badge is-success">{ __( 'Finished', 'ux-studio' ) }</span>;
}

export function RunHistory( { onRolledBack }: { onRolledBack?: ( runId: string ) => void } ): JSX.Element | null {
	const { data } = useQuery( {
		queryKey: KEY,
		queryFn: () => api< { runs: HistoryRun[] } >( 'migration/runs' ),
		staleTime: 0,
	} );
	const [ target, setTarget ] = useState< HistoryRun | null >( null );

	const rollback = useMutation( {
		mutationFn: ( runId: string ) =>
			api< { runs: HistoryRun[] } >( 'migration/rollback', { method: 'POST', body: JSON.stringify( { run_id: runId } ) } ),
		onSuccess: ( res, runId ) => {
			queryClient.setQueryData( KEY, { runs: res.runs } );
			void queryClient.invalidateQueries( { queryKey: [ 'migration' ] } );
			void refreshOverlaps();
			setTarget( null );
			onRolledBack?.( runId );
		},
	} );

	useEffect( () => {
		if ( ! target ) {
			return undefined;
		}
		const onKey = ( e: KeyboardEvent ): void => {
			if ( 'Escape' === e.key && ! rollback.isPending ) {
				setTarget( null );
			}
		};
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ target, rollback.isPending ] );

	const runs = data?.runs ?? [];

	return (
		<section id="uxs-wiz-history" className="uxs-wiz-history" aria-labelledby="uxs-wiz-history-title">
			<h2 id="uxs-wiz-history-title">
				<HistoryIcon size={ 18 } aria-hidden /> { __( 'Run history', 'ux-studio' ) }
			</h2>
			{ 0 === runs.length ? (
				<p className="uxs-wiz-muted">{ __( 'No migration has been run yet.', 'ux-studio' ) }</p>
			) : (
				<ul className="uxs-wiz-runs">
					{ runs.map( ( run ) => (
						<li key={ run.id }>
							<div className="uxs-wiz-runs__main">
								<strong>{ formatDate( run.started_at ) }</strong>
								{ statusBadge( run ) }
								<span className="uxs-wiz-muted">{ run.sources.map( ( s ) => s.label ).join( ', ' ) }</span>
							</div>
							<div className="uxs-wiz-runs__side">
								{ run.can_rollback ? (
									<>
										<span className="uxs-wiz-muted">
											{ sprintf(
												/* translators: %s: date until which the migration can be undone. */
												__( 'Can be undone until %s', 'ux-studio' ),
												formatDate( run.rollback_until, { dateOnly: true } )
											) }
										</span>
										<button type="button" className="button button-small" onClick={ () => setTarget( run ) }>
											<Undo2 size={ 14 } aria-hidden /> { __( 'Undo', 'ux-studio' ) }
										</button>
									</>
								) : run.rolled_back ? (
									<span className="uxs-wiz-muted">{ __( 'Settings restored', 'ux-studio' ) }</span>
								) : (
									<span className="uxs-wiz-muted">{ __( 'The snapshot is no longer kept', 'ux-studio' ) }</span>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) }

			{ target && (
				<div className="uxs-overlay">
					<div className="uxs-modal" role="dialog" aria-modal="true" aria-labelledby="uxs-wiz-hist-undo">
						<h2 id="uxs-wiz-hist-undo">{ __( 'Undo this migration?', 'ux-studio' ) }</h2>
						<p>
							{ sprintf(
								/* translators: %s: date and time of the run. */
								__( 'UX Studio settings go back exactly to how they were before the run from %s. Changes made in these modules since then are lost.', 'ux-studio' ),
								formatDate( target.started_at )
							) }
						</p>
						{ rollback.isError && <p className="uxs-wiz-error">{ ( rollback.error as Error ).message }</p> }
						<div className="uxs-modal__actions">
							<button type="button" className="button" disabled={ rollback.isPending } onClick={ () => setTarget( null ) }>
								{ __( 'Cancel', 'ux-studio' ) }
							</button>
							<button type="button" className="button button-primary" disabled={ rollback.isPending } onClick={ () => rollback.mutate( target.id ) }>
								{ rollback.isPending ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo the migration', 'ux-studio' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</section>
	);
}
