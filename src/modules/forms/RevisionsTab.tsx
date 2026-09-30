/**
 * Revisions tab: history of a form's definition (title/fields/settings) with
 * one-click restore - PLAN.md 20.11/F3. Every save (Fields/Actions/
 * Appearance/Settings tabs) snapshots the state it is about to replace, so
 * this list is newest-first automatically.
 */
import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { History, LoaderCircle, RotateCcw } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import type { FormDefinition, FormRevision } from './types';

export default function RevisionsTab( { form }: { form: FormDefinition } ): JSX.Element {
	const [ confirmId, setConfirmId ] = useState< number | null >( null );

	const query = useQuery( {
		queryKey: [ 'forms', 'revisions', form.id ],
		queryFn: () => api< FormRevision[] >( `forms/${ form.id }/revisions` ),
	} );

	const restore = useMutation( {
		mutationFn: ( revisionId: number ) =>
			api< FormDefinition >( `forms/${ form.id }/revisions/${ revisionId }/restore`, { method: 'POST' } ),
		onSuccess: () => {
			setConfirmId( null );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'form', form.id ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'revisions', form.id ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'forms', 'list' ] } );
		},
	} );

	if ( query.isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const revisions = query.data ?? [];

	if ( revisions.length === 0 ) {
		return (
			<div className="uxs-fb-empty">
				<History size={ 16 } style={ { verticalAlign: '-3px', marginRight: 6 } } />
				{ __( 'No revisions yet - one is saved automatically every time you change fields, actions, appearance or settings.', 'ux-studio' ) }
			</div>
		);
	}

	return (
		<table className="uxs-table">
			<thead>
				<tr>
					<th>{ __( 'Title at the time', 'ux-studio' ) }</th>
					<th>{ __( 'Fields', 'ux-studio' ) }</th>
					<th>{ __( 'Saved by', 'ux-studio' ) }</th>
					<th>{ __( 'Date', 'ux-studio' ) }</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				{ revisions.map( ( rev ) => (
					<tr key={ rev.id }>
						<td>{ rev.title || __( '(untitled form)', 'ux-studio' ) }</td>
						<td>{ rev.field_count }</td>
						<td>{ rev.created_by_name }</td>
						<td>{ rev.created_at }</td>
						<td>
							{ confirmId === rev.id ? (
								<span style={ { display: 'inline-flex', gap: 'var(--uxs-sp-2)', alignItems: 'center' } }>
									<span className="uxs-form__help">{ __( 'Replace the current definition with this one?', 'ux-studio' ) }</span>
									<button
										type="button"
										className="button button-primary"
										disabled={ restore.isPending }
										onClick={ () => restore.mutate( rev.id ) }
									>
										{ restore.isPending ? <LoaderCircle size={ 14 } /> : __( 'Confirm', 'ux-studio' ) }
									</button>
									<button type="button" className="button" onClick={ () => setConfirmId( null ) }>
										{ __( 'Cancel', 'ux-studio' ) }
									</button>
								</span>
							) : (
								<button type="button" className="button" onClick={ () => setConfirmId( rev.id ) }>
									<RotateCcw size={ 14 } /> { __( 'Restore', 'ux-studio' ) }
								</button>
							) }
						</td>
					</tr>
				) ) }
			</tbody>
			<caption style={ { captionSide: 'bottom', textAlign: 'left', paddingTop: 'var(--uxs-sp-3)' } } className="uxs-form__help">
				{ __( 'The current, live definition is always saved as a new revision first, so restoring is never a one-way action.', 'ux-studio' ) }
			</caption>
		</table>
	);
}
