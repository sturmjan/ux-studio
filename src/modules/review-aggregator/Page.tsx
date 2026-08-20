import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { ArrowLeft, Eye, EyeOff, LoaderCircle, RefreshCw, Star } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { navigate } from '../../app/route';
import { SettingsFields, useModuleSettings } from '../../app/SettingsForm';

type Tab = 'reviews' | 'stats' | 'settings';

interface Review {
	id: number;
	source: string;
	author: string;
	rating: number;
	text: string | null;
	review_date: string | null;
	imported_at: string;
	visible: boolean;
}

interface FetchResult {
	fetched: number;
}

interface Stats {
	total: number;
	average: number;
	by_source: Array< { source: string; count: number; average: number } >;
}

function ReviewsTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'review-aggregator', 'reviews' ],
		queryFn: () => api< Review[] >( 'review-aggregator/reviews' ),
	} );

	const fetchReviews = useMutation( {
		mutationFn: () => api< FetchResult >( 'review-aggregator/fetch', { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator', 'reviews' ] } );
			void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator', 'stats' ] } );
		},
	} );

	const toggleVisibility = useMutation( {
		mutationFn: ( id: number ) => api( `review-aggregator/reviews/${ id }/toggle-visibility`, { method: 'POST', body: JSON.stringify( {} ) } ),
		onSuccess: () => {
			void queryClient.invalidateQueries( { queryKey: [ 'review-aggregator', 'reviews' ] } );
		},
	} );

	return (
		<>
			<div style={ { marginBottom: 'var(--uxs-sp-4)' } }>
				<button type="button" className="button button-primary" disabled={ fetchReviews.isPending } onClick={ () => fetchReviews.mutate() }>
					{ fetchReviews.isPending ? <LoaderCircle size={ 14 } /> : <RefreshCw size={ 14 } /> } { __( 'Fetch reviews', 'ux-studio' ) }
				</button>
				{ fetchReviews.isError ? <p className="uxs-form__help">{ ( fetchReviews.error as Error ).message }</p> : null }
				{ fetchReviews.data ? (
					<p>
						<span className="uxs-badge is-success">
							{ __( 'Fetched', 'ux-studio' ) } { fetchReviews.data.fetched } { __( 'reviews', 'ux-studio' ) }
						</span>
					</p>
				) : null }
			</div>
			{ isLoading ? (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) : null }
			{ ! isLoading && ( ! data || data.length === 0 ) ? <p>{ __( 'No reviews yet.', 'ux-studio' ) }</p> : null }
			{ ! isLoading && data && data.length > 0 ? (
				<table className="uxs-table">
					<thead>
						<tr>
							<th>{ __( 'Source', 'ux-studio' ) }</th>
							<th>{ __( 'Author', 'ux-studio' ) }</th>
							<th>{ __( 'Rating', 'ux-studio' ) }</th>
							<th>{ __( 'Text', 'ux-studio' ) }</th>
							<th>{ __( 'Date', 'ux-studio' ) }</th>
							<th>{ __( 'Visible', 'ux-studio' ) }</th>
							<th>{ __( 'Actions', 'ux-studio' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ data.map( ( review ) => (
							<tr key={ review.id }>
								<td>{ review.source }</td>
								<td>{ review.author }</td>
								<td>
									{ Array.from( { length: review.rating } ).map( ( _, i ) => (
										<Star key={ i } size={ 12 } fill="currentColor" />
									) ) }
								</td>
								<td>{ review.text }</td>
								<td>{ review.review_date ?? '' }</td>
								<td>
									<span className={ `uxs-badge ${ review.visible ? 'is-success' : '' }` }>
										{ review.visible ? __( 'Yes', 'ux-studio' ) : __( 'No', 'ux-studio' ) }
									</span>
								</td>
								<td>
									<button
										type="button"
										className="button"
										disabled={ toggleVisibility.isPending }
										onClick={ () => toggleVisibility.mutate( review.id ) }
									>
										{ review.visible ? <EyeOff size={ 14 } /> : <Eye size={ 14 } /> }{ ' ' }
										{ review.visible ? __( 'Hide', 'ux-studio' ) : __( 'Show', 'ux-studio' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : null }
		</>
	);
}

function StatsTab(): JSX.Element {
	const { data, isLoading } = useQuery( {
		queryKey: [ 'review-aggregator', 'stats' ],
		queryFn: () => api< Stats >( 'review-aggregator/stats' ),
	} );

	if ( isLoading ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	if ( ! data ) {
		return <p>{ __( 'No data.', 'ux-studio' ) }</p>;
	}

	return (
		<>
			<p>
				{ __( 'Total reviews:', 'ux-studio' ) } <strong>{ data.total }</strong>
				{ ' · ' }
				{ __( 'Average rating:', 'ux-studio' ) } <strong>{ data.average }</strong>
			</p>
			<table className="uxs-table">
				<thead>
					<tr>
						<th>{ __( 'Source', 'ux-studio' ) }</th>
						<th>{ __( 'Count', 'ux-studio' ) }</th>
						<th>{ __( 'Average', 'ux-studio' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ data.by_source.map( ( row ) => (
						<tr key={ row.source }>
							<td>{ row.source }</td>
							<td>{ row.count }</td>
							<td>{ row.average }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</>
	);
}

export default function Page(): JSX.Element {
	const [ tab, setTab ] = useState< Tab >( 'reviews' );
	const { data, isLoading, draft, setDraft, save, saved } = useModuleSettings( 'review-aggregator' );

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
					{ __( 'Review Aggregator', 'ux-studio' ) }
				</h1>
			</header>
			<div className="uxs-tabs">
				<button className={ tab === 'reviews' ? 'is-active' : '' } onClick={ () => setTab( 'reviews' ) }>
					{ __( 'Reviews', 'ux-studio' ) }
				</button>
				<button className={ tab === 'stats' ? 'is-active' : '' } onClick={ () => setTab( 'stats' ) }>
					{ __( 'Stats', 'ux-studio' ) }
				</button>
				<button className={ tab === 'settings' ? 'is-active' : '' } onClick={ () => setTab( 'settings' ) }>
					{ __( 'Settings', 'ux-studio' ) }
				</button>
			</div>
			{ tab === 'reviews' && <ReviewsTab /> }
			{ tab === 'stats' && <StatsTab /> }
			{ tab === 'settings' && ( isLoading || ! data ) && (
				<div className="uxs-loading">
					<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
				</div>
			) }
			{ tab === 'settings' && data && (
				<>
					<SettingsFields schema={ data.schema } draft={ draft } setDraft={ setDraft } />
					<p className="uxs-form__help">
						{ __( 'Shortcode: [uxstudio_reviews]', 'ux-studio' ) }
					</p>
					<button type="button" className="button button-primary" onClick={ () => save.mutate() }>
						{ saved ? __( 'Saved', 'ux-studio' ) : __( 'Save changes', 'ux-studio' ) }
					</button>
				</>
			) }
		</>
	);
}
