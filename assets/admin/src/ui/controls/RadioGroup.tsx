/**
 * RadioGroup: native radios in a `<fieldset>` named by its `<legend>`.
 *
 * Arrow keys move the selection natively (all radios share `name`), and the
 * row order follows the document direction in RTL. Two variants:
 * - `list` (default): a radio per row with its label and optional
 *   description, linked as the radio's accessible description;
 * - `segmented`: a compact row of segments with an optional decorative icon;
 *   descriptions are not shown, so keep segment labels self-explanatory.
 *
 * Each radio is transparent over a target of at least 24 px; its next sibling
 * (the dot or the segment) draws the state and the focus ring with tokens.
 */
import { useId } from '@wordpress/element';

import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { classNames } from './classNames';
import './RadioGroup.css';

export interface RadioOption {
	value: string;
	/** Translated visible label. */
	label: string;
	/** Translated help text (list variant). */
	description?: string;
	/** Decorative icon before the label (segmented variant). */
	icon?: IconName;
}

export type RadioGroupVariant = 'list' | 'segmented';

export interface RadioGroupProps {
	/** Translated group name, rendered as the visible legend. */
	legend: string;
	/** Shared `name` of the radios; unique on the page. */
	name: string;
	options: readonly RadioOption[];
	/** The checked option's value (controlled). */
	value: string;
	onChange: ( value: string ) => void;
	variant?: RadioGroupVariant;
}

export function RadioGroup( {
	legend,
	name,
	options,
	value,
	onChange,
	variant = 'list',
}: RadioGroupProps ) {
	const baseId = useId();

	return (
		<fieldset
			className={ classNames(
				'sup-radio-group',
				`sup-radio-group--${ variant }`
			) }
		>
			<legend className="sup-radio-group__legend">{ legend }</legend>
			<div className="sup-radio-group__options">
				{ options.map( ( option, index ) => {
					const inputId = `${ baseId }-${ index }`;
					const descriptionId = `${ inputId }-description`;
					const showDescription =
						variant === 'list' && Boolean( option.description );
					const input = (
						<input
							id={ inputId }
							type="radio"
							className="sup-radio-group__input"
							name={ name }
							value={ option.value }
							checked={ option.value === value }
							onChange={ () => onChange( option.value ) }
							aria-describedby={
								showDescription ? descriptionId : undefined
							}
						/>
					);

					if ( variant === 'segmented' ) {
						return (
							<label
								key={ option.value }
								className="sup-radio-group__option"
								htmlFor={ inputId }
							>
								{ input }
								<span className="sup-radio-group__segment">
									{ option.icon && (
										<Icon
											name={ option.icon }
											size={ 16 }
										/>
									) }
									<span>{ option.label }</span>
								</span>
							</label>
						);
					}

					return (
						<div
							key={ option.value }
							className="sup-radio-group__option"
						>
							<span className="sup-radio-group__control">
								{ input }
								<span
									className="sup-radio-group__indicator"
									aria-hidden="true"
								/>
							</span>
							<span className="sup-radio-group__text">
								<label
									className="sup-radio-group__label"
									htmlFor={ inputId }
								>
									{ option.label }
								</label>
								{ showDescription && (
									<span
										id={ descriptionId }
										className="sup-radio-group__description"
									>
										{ option.description }
									</span>
								) }
							</span>
						</div>
					);
				} ) }
			</div>
		</fieldset>
	);
}
