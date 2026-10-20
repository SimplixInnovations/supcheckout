/**
 * Toggle: an on/off switch, rendered as `<input type="checkbox" role="switch">`
 * so it keeps native keyboard (Space), form and assistive-technology
 * behavior. Controlled: `checked` plus `onChange( next )`.
 *
 * The state is shown by the thumb position as well as the track color, never
 * by color alone. The thumb moves along the inline axis, so it mirrors in RTL.
 */
import { useId } from '@wordpress/element';

import { describedBy } from './classNames';
import './Toggle.css';

export interface ToggleProps {
	/** Translated visible label. */
	label: string;
	/** Translated help text, linked as the accessible description. */
	description?: string;
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	disabled?: boolean;
	id?: string;
}

export function Toggle( {
	label,
	description,
	checked,
	onChange,
	disabled,
	id,
}: ToggleProps ) {
	const generatedId = useId();
	const inputId = id ?? generatedId;
	const descriptionId = `${ inputId }-description`;

	return (
		<div className="sup-toggle">
			<span className="sup-toggle__control">
				<input
					id={ inputId }
					type="checkbox"
					role="switch"
					className="sup-toggle__input"
					checked={ checked }
					disabled={ disabled }
					onChange={ ( event ) => onChange( event.target.checked ) }
					aria-describedby={ describedBy(
						description && descriptionId
					) }
				/>
				<span className="sup-toggle__track" aria-hidden="true">
					<span className="sup-toggle__thumb" />
				</span>
			</span>
			<span className="sup-toggle__text">
				<label className="sup-toggle__label" htmlFor={ inputId }>
					{ label }
				</label>
				{ description && (
					<span
						id={ descriptionId }
						className="sup-toggle__description"
					>
						{ description }
					</span>
				) }
			</span>
		</div>
	);
}
