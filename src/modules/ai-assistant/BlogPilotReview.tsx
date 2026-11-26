import { useState } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Check, ExternalLink, LoaderCircle, RefreshCw, Trash2 } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { formatDate } from '../../app/date';
import {
	DEFAULT_PLAN,
	PlanFields,
	QK_CALENDAR,
	QK_GENERATORS,
	QK_POSTS,
	QK_STATS,
	statusBadgeClass,
	statusLabel,
	type PublishPlan,
} from './blogPilotShared';

interface ReviewRow {
	id: number;
	generator_id: number;
	post_id: number;
	topic: string;
	article_type: string;
	tokens_used: number;
	created_at: string;
	wp_title: string | null;
	wp_status: string | null;
	wp_date: string | null;
	generator_title: string | null;
	edit_url: string;
}

interface ReviewResponse {
	items: ReviewRow[];
	total: number;
}

interface Preview {
	id: number;
	title: string;
	status: string;
	datetime: string;
	excerpt: string;
	content: string;
	edit_url: string;
	warnings?: string[];
}

interface BulkResult {
	scheduled: { id: number; datetime: string }[];
	skipped: { id: number; reason: string }[];
}

type ApproveMode = 'now' | 'schedule' | 'plan';

const QK_REVIEW = [ ...QK_POSTS, 'review' ];

function refreshAll(): void {
	void queryClient.invalidateQueries( { queryKey: QK_POSTS } );
	void queryClient.invalidateQueries( { queryKey: QK_CALENDAR } );
	void queryClient.invalidateQueries( { queryKey: QK_STATS } );
	void queryClient.invalidateQueries( { queryKey: QK_GENERATORS } );
}

/** The article in a sandboxed frame: the HTML is sanitised on the server, and the frame allows no scripts at all. */
function PreviewFrame( { html, title }: { html: string; title: string } ): JSX.Element {
	const doc = `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>body{font:15px/1.6 system-ui,sans-serif;margin:12px;color:#1d2327}img{max-width:100%;height:auto}h2,h3{margin:1.2em 0 .4em}</style></head><body><h1 style="font-size:20px">${ escapeHtml( title ) }</h1>${ html }</body></html>`;
	return <iframe className="uxs-bp-preview" sandbox="" srcDoc={ doc } title={ __( 'Article preview', 'ux-studio' ) } />;
}

function escapeHtml( text: string ): string {
	return text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
}

function ReviewItem( { row, selected, onSelect }: { row: ReviewRow; selected: boolean; onSelect: ( checked: boolean ) => void } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ mode, setMode ] = useState< ApproveMode >( 'plan' );
	const [ when, setWhen ] = useState( '' );
	const [ message, setMessage ] = useState( '' );
	const [ error, setError ] = useState( '' );

	const preview = useQuery( {
		queryKey: [ ...QK_POSTS, 'preview', row.post_id ],
		queryFn: () => api< Preview >( `ai-assistant/blog-pilot/posts/${ row.post_id }/preview` ),
		enabled: open,
		staleTime: 0,
	} );

	const approve = useMutation( {
		mutationFn: () =>
			api< { status: string; datetime: string } >( `ai-assistant/blog-pilot/posts/${ row.post_id }/approve`, {
				method: 'POST',
				body: JSON.stringify( { mode, ...( mode === 'schedule' ? { datetime: when } : {} ) } ),
			} ),
		onSuccess: ( result ) => {
			setError( '' );
			setMessage(
				result.status === 'publish'
					? __( 'Published.', 'ux-studio' )
					: sprintf(
							/* translators: %s: date and time */
							__( 'Scheduled for %s.', 'ux-studio' ),
							result.datetime.slice( 0, 16 )
					  )
			);
			refreshAll();
		},
		onError: ( e: Error ) => setError( e.message ),
	} );

	const regenerate = useMutation( {
		mutationFn: () => api< Preview >( `ai-assistant/blog-pilot/posts/${ row.post_id }/regenerate`, { method: 'POST' } ),
		onSuccess: ( result ) => {
			setError( '' );
			setMessage( result.warnings?.includes( 'too_short' ) ? __( 'Written again (shorter than requested).', 'ux-studio' ) : __( 'Written again.', 'ux-studio' ) );
			queryClient.setQueryData( [ ...QK_POSTS, 'preview', row.post_id ], result );
			refreshAll();
		},
		onError: ( e: Error ) => setError( e.message ),
	} );

	const reject = useMutation( {
		mutationFn: () => api( `ai-assistant/blog-pilot/posts/${ row.post_id }/reject`, { method: 'POST' } ),
		onSuccess: () => refreshAll(),
		onError: ( e: Error ) => setError( e.message ),
	} );

	const busy = approve.isPending || regenerate.isPending || reject.isPending;

	return (
		<>
			<tr>
				<td>
					<input type="checkbox" aria-label={ __( 'Select this post', 'ux-studio' ) } checked={ selected } onChange={ ( e ) => onSelect( e.target.checked ) } />
				</td>
				<td>
					{ row.wp_title ?? row.topic }
					{ message ? <div className="uxs-form__help">{ message }</div> : null }
					{ error ? <div className="uxs-form__help is-error" role="alert">{ error }</div> : null }
				</td>
				<td>{ row.generator_title }</td>
				<td>
					<span className={ `uxs-badge ${ statusBadgeClass( row.wp_status ) }` }>{ statusLabel( row.wp_status ) }</span>
				</td>
				<td>{ formatDate( row.created_at ) }</td>
				<td>
					<button type="button" className="button" aria-expanded={ open } onClick={ () => setOpen( ! open ) }>
						{ open ? __( 'Hide preview', 'ux-studio' ) : __( 'Preview', 'ux-studio' ) }
					</button>
				</td>
			</tr>
			{ open ? (
				<tr>
					<td></td>
					<td colSpan={ 5 }>
						{ preview.isLoading || ! preview.data ? (
							<div className="uxs-loading">
								<LoaderCircle size={ 20 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
							</div>
						) : (
							<PreviewFrame html={ preview.data.content } title={ preview.data.title } />
						) }
						<div className="uxs-bp-actions">
							<fieldset className="uxs-bp-approve">
								<legend>{ __( 'Approve', 'ux-studio' ) }</legend>
								<label>
									<input type="radio" name={ `bp-mode-${ row.post_id }` } checked={ mode === 'plan' } onChange={ () => setMode( 'plan' ) } /> { __( 'Next planned slot', 'ux-studio' ) }
								</label>
								<label>
									<input type="radio" name={ `bp-mode-${ row.post_id }` } checked={ mode === 'now' } onChange={ () => setMode( 'now' ) } /> { __( 'Publish now', 'ux-studio' ) }
								</label>
								<label>
									<input type="radio" name={ `bp-mode-${ row.post_id }` } checked={ mode === 'schedule' } onChange={ () => setMode( 'schedule' ) } /> { __( 'Schedule for', 'ux-studio' ) }{ ' ' }
									<input
										type="datetime-local"
										aria-label={ __( 'Publication date and time', 'ux-studio' ) }
										value={ when }
										onChange={ ( e ) => {
											setWhen( e.target.value );
											setMode( 'schedule' );
										} }
									/>
								</label>
							</fieldset>
							<div className="uxs-bp-inline">
								<button type="button" className="button button-primary" disabled={ busy || ( mode === 'schedule' && when === '' ) } onClick={ () => approve.mutate() }>
									{ approve.isPending ? <LoaderCircle size={ 14 } /> : <Check size={ 14 } /> } { __( 'Approve', 'ux-studio' ) }
								</button>
								<a className="button" href={ row.edit_url } target="_blank" rel="noreferrer">
									<ExternalLink size={ 14 } /> { __( 'Edit in WordPress', 'ux-studio' ) }
								</a>
								<button
									type="button"
									className="button"
									disabled={ busy }
									onClick={ () => {
										if ( window.confirm( __( 'Write this article again? The current text will be replaced and AI tokens are used.', 'ux-studio' ) ) ) {
											regenerate.mutate();
										}
									} }
								>
									{ regenerate.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Regenerate', 'ux-studio' ) }
								</button>
								<button
									type="button"
									className="button"
									disabled={ busy }
									onClick={ () => {
										if ( window.confirm( __( 'Reject this article and move it to the trash?', 'ux-studio' ) ) ) {
											reject.mutate();
										}
									} }
								>
									<Trash2 size={ 14 } /> { __( 'Reject', 'ux-studio' ) }
								</button>
							</div>
						</div>
					</td>
				</tr>
			) : null }
		</>
	);
}

/**
 * Review step: generated drafts and pending posts, with a preview and the decision per post (approve now, at a chosen
 * time or in the next planned slot, write again, reject), plus a bulk approval that plans the dates automatically.
 */
export function ReviewSection(): JSX.Element {
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ plan, setPlan ] = useState< PublishPlan >( DEFAULT_PLAN );
	const [ showPlan, setShowPlan ] = useState( false );
	const [ bulkMessage, setBulkMessage ] = useState( '' );
	const [ bulkError, setBulkError ] = useState( '' );

	const { data, isLoading } = useQuery( {
		queryKey: QK_REVIEW,
		queryFn: () => api< ReviewResponse >( 'ai-assistant/blog-pilot/posts?status=review&per_page=50' ),
	} );

	const bulkApprove = useMutation( {
		mutationFn: () => api< BulkResult >( 'ai-assistant/blog-pilot/posts/bulk-approve', { method: 'POST', body: JSON.stringify( { post_ids: selected, plan } ) } ),
		onSuccess: ( result ) => {
			setBulkError( '' );
			setBulkMessage(
				sprintf(
					/* translators: 1: number of scheduled posts, 2: number of skipped posts */
					__( '%1$d post(s) scheduled, %2$d skipped.', 'ux-studio' ),
					result.scheduled.length,
					result.skipped.length
				)
			);
			setSelected( [] );
			refreshAll();
		},
		onError: ( e: Error ) => setBulkError( e.message ),
	} );

	const bulkReject = useMutation( {
		mutationFn: () => api( 'ai-assistant/blog-pilot/posts/bulk-action', { method: 'POST', body: JSON.stringify( { action: 'trash', post_ids: selected } ) } ),
		onSuccess: () => {
			setBulkMessage( '' );
			setSelected( [] );
			refreshAll();
		},
		onError: ( e: Error ) => setBulkError( e.message ),
	} );

	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	return (
		<div>
			<h2>{ __( 'Approval', 'ux-studio' ) }</h2>
			<p className="uxs-form__help">{ __( 'Articles the generators wrote as a draft or pending review wait here until you approve them.', 'ux-studio' ) }</p>
			{ data.items.length === 0 ? (
				<p>{ __( 'Nothing to review.', 'ux-studio' ) }</p>
			) : (
				<>
					<div className="uxs-bp-bulk">
						<label className="uxs-bp-check">
							<input
								type="checkbox"
								checked={ selected.length === data.items.length }
								onChange={ ( e ) => setSelected( e.target.checked ? data.items.map( ( r ) => r.post_id ) : [] ) }
							/>{ ' ' }
							{ __( 'Select all', 'ux-studio' ) }
						</label>
						<button type="button" className="button button-primary" disabled={ selected.length === 0 || bulkApprove.isPending } onClick={ () => bulkApprove.mutate() }>
							{ bulkApprove.isPending ? <LoaderCircle size={ 14 } /> : <Check size={ 14 } /> } { __( 'Approve selected and plan dates', 'ux-studio' ) } ({ selected.length })
						</button>
						<button type="button" className="button" onClick={ () => setShowPlan( ! showPlan ) } aria-expanded={ showPlan }>
							{ __( 'Plan settings', 'ux-studio' ) }
						</button>
						<button
							type="button"
							className="button"
							disabled={ selected.length === 0 || bulkReject.isPending }
							onClick={ () => {
								if ( window.confirm( __( 'Move the selected posts to the trash?', 'ux-studio' ) ) ) {
									bulkReject.mutate();
								}
							} }
						>
							<Trash2 size={ 14 } /> { __( 'Reject selected', 'ux-studio' ) }
						</button>
					</div>
					{ showPlan ? <PlanFields plan={ plan } onChange={ setPlan } idPrefix="uxs-bp-bulk-plan" /> : null }
					{ bulkMessage ? <p role="status">{ bulkMessage }</p> : null }
					{ bulkError ? <p className="uxs-form__help is-error" role="alert">{ bulkError }</p> : null }
					<div className="uxs-table-wrap">
						<table className="uxs-table">
							<thead>
								<tr>
									<th></th>
									<th>{ __( 'Title', 'ux-studio' ) }</th>
									<th>{ __( 'Generator', 'ux-studio' ) }</th>
									<th>{ __( 'Status', 'ux-studio' ) }</th>
									<th>{ __( 'Created', 'ux-studio' ) }</th>
									<th>{ __( 'Actions', 'ux-studio' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ data.items.map( ( row ) => (
									<ReviewItem
										key={ row.id }
										row={ row }
										selected={ selected.includes( row.post_id ) }
										onSelect={ ( checked ) => setSelected( ( s ) => ( checked ? [ ...s, row.post_id ] : s.filter( ( id ) => id !== row.post_id ) ) ) }
									/>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }
		</div>
	);
}
