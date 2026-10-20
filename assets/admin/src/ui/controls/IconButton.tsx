/**
 * IconButton: a square native button that shows only an icon.
 *
 * `label` is required: it becomes the `aria-label`, so the button always has
 * an accessible name, and the icon stays decorative. It takes no children.
 */
import type { ButtonHTMLAttributes } from 'react';
import { forwardRef } from '@wordpress/element';

import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { buttonClassName } from './Button';
import type { ButtonSize, ButtonVariant } from './Button';
import './IconButton.css';

export interface IconButtonProps extends Omit<
	ButtonHTMLAttributes< HTMLButtonElement >,
	'children' | 'aria-label'
> {
	icon: IconName;
	/** Translated accessible name. */
	label: string;
	/** Visual emphasis; defaults to `tertiary`. */
	variant?: ButtonVariant;
	/** Square size; defaults to `md`. */
	size?: ButtonSize;
	children?: never;
}

export const IconButton = forwardRef< HTMLButtonElement, IconButtonProps >(
	function SupIconButton(
		{
			icon,
			label,
			variant = 'tertiary',
			size = 'md',
			type = 'button',
			className,
			...rest
		},
		ref
	) {
		return (
			<button
				{ ...rest }
				ref={ ref }
				type={ type }
				aria-label={ label }
				className={ buttonClassName(
					variant,
					size,
					'sup-button--icon-only',
					className
				) }
			>
				<Icon name={ icon } size={ size === 'sm' ? 16 : 20 } />
			</button>
		);
	}
);
