/**
 * Small list of finished real runs with a rollback button while the snapshot is
 * still kept (30 days).
 */
import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowRight, History as HistoryIcon, Undo2 } from 'lucide-react';
import { api, queryClient } from '../api';
import { formatDate } from '../date';
import { refreshOverlaps } from '../Overlaps';
import { ConfirmModal } from './parts';
import type { HistoryRun } from './types';

const KEY = [ 'migration', 'runs' ];

function statusBadge( run: HistoryRun ): JSX.Element {
	if ( run.rolled_back ) {
		return <span className="uxs-badge is-info">{ __( 'Undone', 'ux-studio' ) }</span>;
	}
	if ( run.switch_active ) {
		return <span className="uxs-badge is-success">{ __( 'Switched', 'ux-studio' ) }</span>;
	}
	if ( run.switch_reverted ) {
		return <span className="uxs-badge is-info">{ __( 'Switch undone', 'ux-studio' ) }</span>;
	}
	if ( run.errors > 0 ) {
		return <span className="uxs-badge is-warning">{ __( 'With errors', 'ux-studio' ) }</span>;
	}
	return <span className="uxs-badge is-success">{ __( 'Finished', 'ux-studio' ) }</span>;
}

export function RunHistory( {
	onRolledBack,
	onContinue,
	onReverted,
}: {
	onRolledBack?: ( runId: string ) => void;
	/** Continue a migrated run with the safe preview and the switch. */
	onContinue?: ( run: HistoryRun ) => void;
	onReverted?: ( runId: string ) => void;
} ): JSX.Element | null {
	const { data } = useQuery( {
		queryKey: KEY,
		queryFn: () => api< { runs: HistoryRun[] } >( 'migration/runs' ),
		staleTime: 0,
	} );
	const [ target, setTarget ] = useState< HistoryRun | null >( null );
	const [ revertTarget, setRevertTarget ] = useState< HistoryRun | null >( null );

	const revert = useMutation( {
		mutationFn: ( runId: string ) =>
			api< { runs: HistoryRun[] } >( 'migration/revert-switch', { method: 'POST', body: JSON.stringify( { run_id: runId } ) } ),
		onSuccess: ( res, runId ) => {
			queryClient.setQueryData( KEY, { runs: res.runs } );
			void queryClient.invalidateQueries( { queryKey: [ 'migration' ] } );
			void refreshOverlaps();
			setRevertTarget( null );
			onReverted?.( runId );
		},
	} );

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
								{ run.staging && ! run.applied && run.staging.settings + run.staging.secrets + run.staging.activations > 0 && (
									<span className="uxs-badge is-info">
										{ sprintf(
											/* translators: %d: number of prepared values and objects. */
											__( '%d prepared for the switch', 'ux-studio' ),
											run.staging.settings + run.staging.secrets + run.staging.activations
										) }
									</span>
								) }
							</div>
							<div className="uxs-wiz-runs__side">
								{ run.can_revert_switch && (
									<>
										<span className="uxs-wiz-muted">
											{ sprintf(
												/* translators: %s: date until which the switch can be undone. */
												__( 'The switch can be undone until %s', 'ux-studio' ),
												formatDate( run.revert_until, { dateOnly: true } )
											) }
										</span>
										<button type="button" className="button button-small" onClick={ () => setRevertTarget( run ) }>
											<Undo2 size={ 14 } aria-hidden /> { __( 'Undo the switch', 'ux-studio' ) }
										</button>
									</>
								) }
								{ onContinue && ! run.rolled_back && ! run.switch_active && ! run.snapshot_purged && 0 === run.errors && (
									<button type="button" className="button button-small button-primary" onClick={ () => onContinue( run ) }>
										{ __( 'Preview and switch', 'ux-studio' ) } <ArrowRight size={ 14 } aria-hidden />
									</button>
								) }
								{ run.can_rollback ? (
									<>
										{ ! run.can_revert_switch && (
											<span className="uxs-wiz-muted">
												{ sprintf(
													/* translators: %s: date until which the migration can be undone. */
													__( 'Can be undone until %s', 'ux-studio' ),
													formatDate( run.rollback_until, { dateOnly: true } )
												) }
											</span>
										) }
										<button type="button" className="button button-small" onClick={ () => setTarget( run ) }>
											<Undo2 size={ 14 } aria-hidden /> { __( 'Undo', 'ux-studio' ) }
										</button>
									</>
								) : run.rolled_back ? (
									<span className="uxs-wiz-muted">{ __( 'Settings restored', 'ux-studio' ) }</span>
								) : run.switch_active ? null : (
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
								__( 'UX Studio goes back exactly to how it was before the run from %s: settings, and everything the run created. Changes made in these modules since then are lost.', 'ux-studio' ),
								formatDate( target.started_at )
							) }
						</p>
						{ target.switch_active && <p>{ __( 'The run is switched on, so the switch is undone first: the old plugins are activated again and the modules it turned on go off.', 'ux-studio' ) }</p> }
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

			{ revertTarget && (
				<ConfirmModal
					id="uxs-wiz-hist-revert"
					title={ __( 'Undo the switch?', 'ux-studio' ) }
					confirmLabel={ revert.isPending ? __( 'Restoring…', 'ux-studio' ) : __( 'Undo the switch', 'ux-studio' ) }
					busy={ revert.isPending }
					error={ revert.isError ? ( revert.error as Error ).message : null }
					onCancel={ () => setRevertTarget( null ) }
					onConfirm={ () => revert.mutate( revertTarget.id ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: date and time of the run. */
							__( 'The old plugins of the run from %s are activated again, the modules the switch turned on go off, and settings and data return to how they were before the switch. Changes made in these modules since then are lost.', 'ux-studio' ),
							formatDate( revertTarget.started_at )
						) }
					</p>
				</ConfirmModal>
			) }
		</section>
	);
}
