import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { useMutation, useQuery } from '@tanstack/react-query';
import { CalendarClock, ChevronLeft, ChevronRight, LoaderCircle } from 'lucide-react';
import { api, queryClient } from '../../app/api';
import { QK_CALENDAR, QK_POSTS, statusBadgeClass, statusLabel, toInputValue } from './blogPilotShared';

interface CalendarItem {
	id: number;
	title: string;
	status: string;
	/** Site time zone, "Y-m-d H:i:s". */
	datetime: string;
	time: string;
	generated: boolean;
	can_reschedule: boolean;
	edit_url: string;
}

interface CalendarDay {
	date: string;
	day: number;
	weekday: number;
	in_month: boolean;
	is_today: boolean;
	items: CalendarItem[];
}

interface CalendarData {
	year: number;
	month: number;
	prev: { year: number; month: number };
	next: { year: number; month: number };
	weeks: CalendarDay[][];
	timezone: string;
	now: string;
}

function locale(): string | undefined {
	const wp = window.uxStudioBoot?.locale;
	return wp ? wp.replace( '_', '-' ) : undefined;
}

/** A date given as "Y-m-d" formatted without any time zone shift. */
function formatDay( date: string, options: Intl.DateTimeFormatOptions ): string {
	const [ y = 1970, m = 1, d = 1 ] = date.split( '-' ).map( Number );
	return new Intl.DateTimeFormat( locale(), { ...options, timeZone: 'UTC' } ).format( new Date( Date.UTC( y, m - 1, d ) ) );
}

/** Small control on a scheduled post: change its publication date and time. */
function RescheduleControl( { item, minValue }: { item: CalendarItem; minValue: string } ): JSX.Element {
	const [ open, setOpen ] = useState( false );
	const [ value, setValue ] = useState( toInputValue( item.datetime ) );

	const save = useMutation( {
		mutationFn: () => api( `ai-assistant/blog-pilot/posts/${ item.id }/schedule`, { method: 'POST', body: JSON.stringify( { datetime: value } ) } ),
		onSuccess: () => {
			setOpen( false );
			void queryClient.invalidateQueries( { queryKey: QK_CALENDAR } );
			void queryClient.invalidateQueries( { queryKey: QK_POSTS } );
		},
	} );

	if ( ! open ) {
		return (
			<button
				type="button"
				className="uxs-bp-cal__icon"
				aria-label={ __( 'Change the publication date', 'ux-studio' ) }
				title={ __( 'Change the publication date', 'ux-studio' ) }
				onClick={ () => {
					setValue( toInputValue( item.datetime ) );
					setOpen( true );
				} }
			>
				<CalendarClock size={ 14 } />
			</button>
		);
	}

	return (
		<div className="uxs-bp-cal__resched">
			<input type="datetime-local" aria-label={ __( 'Publication date and time', 'ux-studio' ) } value={ value } min={ minValue } onChange={ ( e ) => setValue( e.target.value ) } />
			<button type="button" className="button button-small" disabled={ save.isPending || value === '' } onClick={ () => save.mutate() }>
				{ save.isPending ? <LoaderCircle size={ 12 } /> : __( 'Save', 'ux-studio' ) }
			</button>
			<button type="button" className="button button-small" onClick={ () => setOpen( false ) }>
				{ __( 'Cancel', 'ux-studio' ) }
			</button>
			{ save.error ? <span className="uxs-form__help is-error" role="alert">{ ( save.error as Error ).message }</span> : null }
		</div>
	);
}

/**
 * Calendar view: a month grid with the posts Blog Pilot generated and everything that is scheduled. Click a post to open
 * it in the WordPress editor; a scheduled post has a small control to change its date and time.
 */
export function CalendarSection(): JSX.Element {
	const [ cursor, setCursor ] = useState< { year: number; month: number } | null >( null );

	const params = cursor ? `?year=${ cursor.year }&month=${ cursor.month }` : '';
	const { data, isLoading, error } = useQuery( {
		queryKey: [ ...QK_CALENDAR, cursor?.year ?? 'now', cursor?.month ?? 'now' ],
		queryFn: () => api< CalendarData >( `ai-assistant/blog-pilot/calendar${ params }` ),
		staleTime: 15_000,
	} );

	if ( error ) {
		return <p className="uxs-form__help is-error">{ ( error as Error ).message }</p>;
	}
	if ( isLoading || ! data ) {
		return (
			<div className="uxs-loading">
				<LoaderCircle size={ 24 } aria-label={ __( 'Loading…', 'ux-studio' ) } />
			</div>
		);
	}

	const title = formatDay( `${ data.year }-${ String( data.month ).padStart( 2, '0' ) }-01`, { month: 'long', year: 'numeric' } );
	const minValue = data.now.replace( ' ', 'T' );

	return (
		<div>
			<div className="uxs-bp-cal__head">
				<button type="button" className="button" aria-label={ __( 'Previous month', 'ux-studio' ) } onClick={ () => setCursor( data.prev ) }>
					<ChevronLeft size={ 14 } />
				</button>
				<h2 aria-live="polite">{ title }</h2>
				<button type="button" className="button" aria-label={ __( 'Next month', 'ux-studio' ) } onClick={ () => setCursor( data.next ) }>
					<ChevronRight size={ 14 } />
				</button>
				<button type="button" className="button" onClick={ () => setCursor( null ) }>
					{ __( 'Today', 'ux-studio' ) }
				</button>
			</div>
			<p className="uxs-form__help">
				{ __( 'Posts written by Blog Pilot and every scheduled post. Times are in the time zone of the site:', 'ux-studio' ) } { data.timezone }
			</p>
			<div className="uxs-bp-cal" role="grid" aria-label={ title }>
				<div className="uxs-bp-cal__row uxs-bp-cal__row--head" role="row">
					{ ( data.weeks[ 0 ] ?? [] ).map( ( day ) => (
						<div key={ day.weekday } role="columnheader">
							{ formatDay( day.date, { weekday: 'short' } ) }
						</div>
					) ) }
				</div>
				{ data.weeks.map( ( week, index ) => (
					<div className="uxs-bp-cal__row" role="row" key={ week[ 0 ]?.date ?? index }>
						{ week.map( ( day ) => (
							<div
								key={ day.date }
								role="gridcell"
								className={ `uxs-bp-cal__day${ day.in_month ? '' : ' is-outside' }${ day.is_today ? ' is-today' : '' }` }
							>
								<div className="uxs-bp-cal__num">{ day.day }</div>
								{ day.items.map( ( item ) => (
									<div className="uxs-bp-cal__item" key={ item.id }>
										<a href={ item.edit_url } target="_blank" rel="noreferrer" title={ `${ item.title } (${ statusLabel( item.status ) })` }>
											<span className="uxs-bp-cal__time">{ item.time }</span> { item.title }
										</a>
										<span className={ `uxs-badge ${ statusBadgeClass( item.status ) }` }>{ statusLabel( item.status ) }</span>
										{ item.can_reschedule ? <RescheduleControl item={ item } minValue={ minValue } /> : null }
									</div>
								) ) }
							</div>
						) ) }
					</div>
				) ) }
			</div>
		</div>
	);
}
