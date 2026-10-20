/**
 * TextInput: a native single-line `<input>` (type `text` by default).
 *
 * `invalid` sets `aria-invalid="true"`, which also draws the danger boundary.
 * Use it inside a Field, or give it an accessible name yourself.
 */
import type { InputHTMLAttributes } from 'react';
import { forwardRef } from '@wordpress/element';

import { classNames } from './classNames';
import './TextInput.css';

export interface TextInputProps extends InputHTMLAttributes< HTMLInputElement > {
	invalid?: boolean;
}

export const TextInput = forwardRef< HTMLInputElement, TextInputProps >(
	function SupTextInput(
		{
			invalid = false,
			type = 'text',
			className,
			'aria-invalid': ariaInvalid,
			...rest
		},
		ref
	) {
		return (
			<input
				{ ...rest }
				ref={ ref }
				type={ type }
				className={ classNames( 'sup-input', className ) }
				aria-invalid={ invalid ? true : ariaInvalid }
			/>
		);
	}
);
