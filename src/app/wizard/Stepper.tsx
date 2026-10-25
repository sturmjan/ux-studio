/**
 * Step indicator: numbered circles joined by a progress line that fills as the
 * wizard moves on; finished steps show a drawn check.
 */
import { __, sprintf } from '@wordpress/i18n';
import { DrawnCheck } from './parts';

export interface StepperItem {
	id: string;
	title: string;
}

export function Stepper( { steps, current }: { steps: StepperItem[]; current: number } ): JSX.Element {
	return (
		<ol className="uxs-wiz-steps" aria-label={ __( 'Migration steps', 'ux-studio' ) }>
			{ steps.map( ( step, index ) => {
				const state = index < current ? 'done' : index === current ? 'current' : 'todo';
				return (
					<li key={ step.id } className={ `uxs-wiz-step is-${ state }` } aria-current={ 'current' === state ? 'step' : undefined }>
						<span className="uxs-wiz-step__dot">{ 'done' === state ? <DrawnCheck size={ 16 } /> : <span>{ index + 1 }</span> }</span>
						<span className="uxs-wiz-step__title">
							<span className="screen-reader-text">
								{ sprintf(
									/* translators: 1: step number, 2: step name. */
									__( 'Step %1$d: %2$s', 'ux-studio' ),
									index + 1,
									step.title
								) }
							</span>
							<span aria-hidden>{ step.title }</span>
						</span>
					</li>
				);
			} ) }
		</ol>
	);
}
