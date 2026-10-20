/**
 * Select: a native `<select>` (UX-CONTRACT: Select stays native).
 *
 * The WordPress chevron background is removed; a decorative, token-colored
 * chevron icon sits at the inline end instead, so it follows dark mode,
 * forced colors and RTL. `className` goes on the wrapper, which sizes the
 * control. It is a single-choice dropdown: `multiple` and `size` (a list box)
 * are not accepted, since the chevron and height assume a closed select.
 */
import type { SelectHTMLAttributes } from 'react';
import { forwardRef } from '@wordpress/element';

import { Icon } from '../foundations/Icon';
import { classNames } from './classNames';
import './Select.css';

export interface SelectOption {
	value: string;
	/** Translated option text. */
	label: string;
	disabled?: boolean;
}

export interface SelectProps extends Omit<
	SelectHTMLAttributes< HTMLSelectElement >,
	'children' | 'multiple' | 'size'
> {
	options: readonly SelectOption[];
	invalid?: boolean;
}

export const Select = forwardRef< HTMLSelectElement, SelectProps >(
	function SupSelect(
		{
			options,
			invalid = false,
			className,
			'aria-invalid': ariaInvalid,
			...rest
		},
		ref
	) {
		return (
			<span className={ classNames( 'sup-select', className ) }>
				<select
					{ ...rest }
					ref={ ref }
					className="sup-select__input"
					aria-invalid={ invalid ? true : ariaInvalid }
				>
					{ options.map( ( option ) => (
						<option
							key={ option.value }
							value={ option.value }
							disabled={ option.disabled }
						>
							{ option.label }
						</option>
					) ) }
				</select>
				<Icon name="chevron-down" size={ 16 } />
			</span>
		);
	}
);
