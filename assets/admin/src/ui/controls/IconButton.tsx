/**
 * IconButton: a square native button that shows only an icon.
 *
 * `label` is required: it becomes the `aria-label`, so the button always has
 * an accessible name, and the icon stays decorative. It takes no children,
 * and no `aria-labelledby`, which would silently replace that name.
 *
 * `showTooltip` also shows the label as a tooltip on hover and keyboard
 * focus, for icons whose meaning is not obvious. The tooltip repeats the
 * name visually only: it is not wired as `aria-describedby`, so screen
 * readers do not hear the name twice. A disabled IconButton shows no
 * tooltip (see Tooltip).
 */
import type { ButtonHTMLAttributes } from 'react';
import { forwardRef } from '@wordpress/element';

import { LabelTooltip } from '../feedback/Tooltip';
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { buttonClassName } from './Button';
import type { ButtonSize, ButtonVariant } from './Button';
import './IconButton.css';

export interface IconButtonProps extends Omit<
	ButtonHTMLAttributes< HTMLButtonElement >,
	'children' | 'aria-label' | 'aria-labelledby'
> {
	icon: IconName;
	/** Translated accessible name. */
	label: string;
	/** Visual emphasis; defaults to `tertiary`. */
	variant?: ButtonVariant;
	/** Square size; defaults to `md`. */
	size?: ButtonSize;
	/** Also show `label` as a tooltip; defaults to `false`. */
	showTooltip?: boolean;
	children?: never;
	/** Never accepted: it would replace `label` as the accessible name. */
	'aria-labelledby'?: never;
}

export const IconButton = forwardRef< HTMLButtonElement, IconButtonProps >(
	function SupIconButton(
		{
			icon,
			label,
			variant = 'tertiary',
			size = 'md',
			showTooltip = false,
			type = 'button',
			className,
			...rest
		},
		ref
	) {
		const button = (
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
		if ( ! showTooltip ) {
			return button;
		}
		return <LabelTooltip content={ label }>{ button }</LabelTooltip>;
	}
);
