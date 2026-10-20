/**
 * Stepper: where the user is in a fixed sequence, such as the setup wizard.
 *
 * An ordered list named by `label`; the list gives "step 2 of 4". The current
 * step carries `aria-current="step"`, and every step ends with a visually
 * hidden state ("Completed", "Current step" or "Not started"), so the state
 * is spoken and not only drawn. The marker (a check or the step number) is
 * decorative. The current step wins over completed: a finished step the user
 * returned to is announced as the current one.
 *
 * Steps are not links: navigation between steps belongs to the screen.
 */
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import { VisuallyHidden } from '../foundations/VisuallyHidden';
import './Stepper.css';

export interface StepperStep {
	id: string;
	/** Translated step name. */
	label: string;
}

export interface StepperProps {
	/** Translated accessible name of the list, e.g. "Setup steps". */
	label: string;
	steps: readonly StepperStep[];
	currentId: string;
	completedIds: readonly string[];
}

type StepState = 'complete' | 'current' | 'upcoming';

function stateText( state: StepState ): string {
	if ( state === 'complete' ) {
		return __( 'Completed', 'supcheckout' );
	}
	if ( state === 'current' ) {
		return __( 'Current step', 'supcheckout' );
	}
	return __( 'Not started', 'supcheckout' );
}

export function Stepper( {
	label,
	steps,
	currentId,
	completedIds,
}: StepperProps ) {
	const completed = new Set( completedIds );
	return (
		<div className="sup-stepper">
			<ol className="sup-stepper__list" aria-label={ label }>
				{ steps.map( ( step, index ) => {
					let state: StepState = 'upcoming';
					if ( step.id === currentId ) {
						state = 'current';
					} else if ( completed.has( step.id ) ) {
						state = 'complete';
					}
					return (
						<li
							key={ step.id }
							className={ `sup-stepper__step sup-stepper__step--${ state }` }
							aria-current={
								state === 'current' ? 'step' : undefined
							}
						>
							<span
								className="sup-stepper__marker"
								aria-hidden="true"
							>
								{ state === 'complete' ? (
									<Icon name="check" size={ 16 } />
								) : (
									<span className="sup-stepper__number">
										{ index + 1 }
									</span>
								) }
							</span>
							<span className="sup-stepper__label">
								{ step.label }
								<VisuallyHidden>
									{ ' ' }
									{ stateText( state ) }
								</VisuallyHidden>
							</span>
						</li>
					);
				} ) }
			</ol>
		</div>
	);
}
