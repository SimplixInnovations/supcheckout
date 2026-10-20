/**
 * Textarea: a native multi-line text control with the TextInput look.
 */
import type { TextareaHTMLAttributes } from 'react';
import { forwardRef } from '@wordpress/element';

import { classNames } from './classNames';
import './TextInput.css';
import './Textarea.css';

export interface TextareaProps extends TextareaHTMLAttributes< HTMLTextAreaElement > {
	invalid?: boolean;
}

export const Textarea = forwardRef< HTMLTextAreaElement, TextareaProps >(
	function SupTextarea(
		{
			invalid = false,
			rows = 4,
			className,
			'aria-invalid': ariaInvalid,
			...rest
		},
		ref
	) {
		return (
			<textarea
				{ ...rest }
				ref={ ref }
				rows={ rows }
				className={ classNames(
					'sup-input',
					'sup-textarea',
					className
				) }
				aria-invalid={ invalid ? true : ariaInvalid }
			/>
		);
	}
);
