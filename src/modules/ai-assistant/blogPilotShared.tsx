import { __ } from '@wordpress/i18n';
import { HelpText } from '../../components/GlossaryTerm';

/** Query keys of the Blog Pilot tab (one place so every view can refresh the others). */
export const QK_GENERATORS = [ 'ai-assistant', 'blog-pilot', 'generators' ];
export const QK_POSTS = [ 'ai-assistant', 'blog-pilot', 'posts' ];
export const QK_STATS = [ 'ai-assistant', 'blog-pilot', 'stats' ];
export const QK_CALENDAR = [ 'ai-assistant', 'blog-pilot', 'calendar' ];

/** Publish plan of a generator or a bulk approval (mirrors PHP PublishSlots::DEFAULTS). */
export interface PublishPlan {
	mode: 'period' | 'range';
	start_date: string;
	end_date: string;
	per: number;
	unit: 'day' | 'week';
	window_start: string;
	window_end: string;
	/** ISO weekdays, 1 = Monday ... 7 = Sunday. */
	weekdays: number[];
	min_gap_minutes: number;
}

export const DEFAULT_PLAN: PublishPlan = {
	mode: 'period',
	start_date: '',
	end_date: '',
	per: 1,
	unit: 'day',
	window_start: '08:00',
	window_end: '11:00',
	weekdays: [ 1, 2, 3, 4, 5, 6, 7 ],
	min_gap_minutes: 0,
};

/** Merges a stored (possibly partial or missing) plan with the defaults. */
export function withPlanDefaults( plan: Partial< PublishPlan > | undefined | null ): PublishPlan {
	return { ...DEFAULT_PLAN, ...( plan ?? {} ) };
}

export interface TopicProposal {
	id: string;
	title: string;
	summary: string;
	focus_keyword: string;
	article_type: string;
	variants: string[];
	category: string;
}

export interface ArticleType {
	name: string;
	description: string;
	prompt_hint: string;
}

/** Status of a WordPress post as a badge label. */
export function statusLabel( status: string | null | undefined ): string {
	switch ( status ) {
		case 'draft':
			return __( 'Draft', 'ux-studio' );
		case 'pending':
			return __( 'Pending review', 'ux-studio' );
		case 'future':
			return __( 'Scheduled', 'ux-studio' );
		case 'publish':
			return __( 'Published', 'ux-studio' );
		case 'trash':
			return __( 'Trash', 'ux-studio' );
		default:
			return status ?? '';
	}
}

export function statusBadgeClass( status: string | null | undefined ): string {
	switch ( status ) {
		case 'publish':
			return 'is-success';
		case 'future':
			return 'is-info';
		case 'pending':
			return 'is-warning';
		default:
			return '';
	}
}

/** "2026-11-26 10:30:00" -> "2026-11-26T10:30" for <input type="datetime-local">. */
export function toInputValue( mysql: string ): string {
	return mysql.replace( ' ', 'T' ).slice( 0, 16 );
}

/** Weekday names for the ISO numbers 1..7 (the picker of the publish plan). */
export function weekdayLabels(): { id: number; label: string }[] {
	return [
		{ id: 1, label: __( 'Mon', 'ux-studio' ) },
		{ id: 2, label: __( 'Tue', 'ux-studio' ) },
		{ id: 3, label: __( 'Wed', 'ux-studio' ) },
		{ id: 4, label: __( 'Thu', 'ux-studio' ) },
		{ id: 5, label: __( 'Fri', 'ux-studio' ) },
		{ id: 6, label: __( 'Sat', 'ux-studio' ) },
		{ id: 7, label: __( 'Sun', 'ux-studio' ) },
	];
}

/**
 * Form fields of a publish plan: either "N per day/week from a date" or "spread over a date range", inside a time
 * window on the allowed weekdays. Times are in the time zone of the site.
 */
export function PlanFields( { plan, onChange, idPrefix }: { plan: PublishPlan; onChange: ( plan: PublishPlan ) => void; idPrefix: string } ): JSX.Element {
	const set = ( patch: Partial< PublishPlan > ) => onChange( { ...plan, ...patch } );
	const toggleDay = ( day: number ) => {
		const next = plan.weekdays.includes( day ) ? plan.weekdays.filter( ( d ) => d !== day ) : [ ...plan.weekdays, day ].sort();
		if ( next.length > 0 ) {
			set( { weekdays: next } );
		}
	};

	return (
		<div className="uxs-bp-plan">
			<div className="uxs-form__row">
				<label htmlFor={ `${ idPrefix }-mode` }>{ __( 'Plan type', 'ux-studio' ) }</label>
				<select id={ `${ idPrefix }-mode` } value={ plan.mode } onChange={ ( e ) => set( { mode: e.target.value as PublishPlan[ 'mode' ] } ) }>
					<option value="period">{ __( 'A fixed number of posts per day or week', 'ux-studio' ) }</option>
					<option value="range">{ __( 'Spread over a date range', 'ux-studio' ) }</option>
				</select>
			</div>
			{ plan.mode === 'period' ? (
				<div className="uxs-form__row">
					<label htmlFor={ `${ idPrefix }-per` }>{ __( 'Posts per', 'ux-studio' ) }</label>
					<div className="uxs-bp-inline">
						<input
							id={ `${ idPrefix }-per` }
							type="number"
							min={ 1 }
							max={ 100 }
							value={ plan.per }
							onChange={ ( e ) => set( { per: Math.max( 1, Math.min( 100, Number( e.target.value ) || 1 ) ) } ) }
						/>
						<select aria-label={ __( 'Per day or week', 'ux-studio' ) } value={ plan.unit } onChange={ ( e ) => set( { unit: e.target.value as PublishPlan[ 'unit' ] } ) }>
							<option value="day">{ __( 'day', 'ux-studio' ) }</option>
							<option value="week">{ __( 'week', 'ux-studio' ) }</option>
						</select>
					</div>
				</div>
			) : null }
			<div className="uxs-form__row">
				<label htmlFor={ `${ idPrefix }-start` }>{ plan.mode === 'range' ? __( 'From', 'ux-studio' ) : __( 'Starting on', 'ux-studio' ) }</label>
				<div className="uxs-bp-inline">
					<input id={ `${ idPrefix }-start` } type="date" value={ plan.start_date } onChange={ ( e ) => set( { start_date: e.target.value } ) } />
					{ plan.mode === 'range' ? (
						<>
							<span>{ __( 'to', 'ux-studio' ) }</span>
							<input type="date" aria-label={ __( 'Last day', 'ux-studio' ) } value={ plan.end_date } onChange={ ( e ) => set( { end_date: e.target.value } ) } />
						</>
					) : null }
				</div>
				<p className="uxs-form__help">
					<HelpText
						text={
							plan.mode === 'range'
								? __( 'The posts are spread evenly over the allowed days of this range. If the range is over, the plan carries on daily from today.', 'ux-studio' )
								: __( 'Leave empty to start today. Days that already hold that many scheduled posts are skipped.', 'ux-studio' )
						}
					/>
				</p>
			</div>
			<div className="uxs-form__row">
				<label htmlFor={ `${ idPrefix }-window` }>{ __( 'Time window', 'ux-studio' ) }</label>
				<div className="uxs-bp-inline">
					<input id={ `${ idPrefix }-window` } type="time" value={ plan.window_start } onChange={ ( e ) => set( { window_start: e.target.value } ) } />
					<span>{ __( 'to', 'ux-studio' ) }</span>
					<input type="time" aria-label={ __( 'Window end', 'ux-studio' ) } value={ plan.window_end } onChange={ ( e ) => set( { window_end: e.target.value } ) } />
				</div>
				<p className="uxs-form__help">
					<HelpText text={ __( 'Posts are published at evenly spaced times inside this window, in the time zone of the site. Moments that are already taken by a scheduled post are avoided.', 'ux-studio' ) } />
				</p>
			</div>
			<div className="uxs-form__row">
				<label>{ __( 'Allowed weekdays', 'ux-studio' ) }</label>
				<div className="uxs-bp-inline">
					{ weekdayLabels().map( ( d ) => (
						<label key={ d.id } className="uxs-bp-check">
							<input type="checkbox" checked={ plan.weekdays.includes( d.id ) } onChange={ () => toggleDay( d.id ) } /> { d.label }
						</label>
					) ) }
				</div>
			</div>
		</div>
	);
}
