/**
 * Button: a native `<button>` in four variants and two sizes.
 *
 * `type` defaults to `button`, so a Button inside a form never submits it by
 * accident. A busy button stays focusable and keeps its visible label: it sets
 * `aria-disabled="true"`, swallows clicks (and so Enter, Space and form
 * submission) and shows a decorative spinner. With `busyLabel`, a visually
 * hidden polite status next to the button announces it; that region is
 * mounted before the button turns busy so the change is announced, and it
 * sits outside the button so the accessible name stays the visible label.
 *
 * Contract for callers:
 * - Pass `busyLabel` whenever the button may become busy, not only while it
 *   is busy: the status region must exist before its text changes, or the
 *   announcement may be silent.
 * - With `busyLabel`, Button renders that visually hidden `<span>` as its
 *   next sibling, so `:last-child` and `+` selectors around buttons see it.
 * - Busy suppresses activation of this button only (click, Enter, Space and
 *   implicit submission through it as the default button). A form can still
 *   be submitted another way (another submit button, `requestSubmit()`), so
 *   the caller's `onSubmit` must also ignore submissions while pending.
 */
import type { ButtonHTMLAttributes, MouseEvent } from 'react';
import { forwardRef } from '@wordpress/element';

import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { Spinner } from '../foundations/Spinner';
import { classNames } from './classNames';
import './Button.css';

export type ButtonVariant = 'primary' | 'secondary' | 'tertiary' | 'danger';
export type ButtonSize = 'sm' | 'md';

export interface ButtonProps extends ButtonHTMLAttributes< HTMLButtonElement > {
	/** Visual emphasis; defaults to `secondary`. */
	variant?: ButtonVariant;
	/** Control height; defaults to `md`. */
	size?: ButtonSize;
	/** Pending action: focusable, inert to activation, shows a spinner. */
	busy?: boolean;
	/** Translated text announced politely while busy. */
	busyLabel?: string;
	/** Decorative icon before the label; replaced by the spinner while busy. */
	iconStart?: IconName;
	/** Decorative icon after the label. */
	iconEnd?: IconName;
}

/**
 * @param variant Visual emphasis.
 * @param size    Control height.
 * @param extra   Further classes.
 * @return The button class list shared by Button and IconButton.
 */
export function buttonClassName(
	variant: ButtonVariant,
	size: ButtonSize,
	...extra: Array< string | false | undefined >
): string {
	return classNames(
		'sup-button',
		`sup-button--${ variant }`,
		`sup-button--${ size }`,
		...extra
	);
}

export const Button = forwardRef< HTMLButtonElement, ButtonProps >(
	function SupButton(
		{
			variant = 'secondary',
			size = 'md',
			busy = false,
			busyLabel,
			iconStart,
			iconEnd,
			type = 'button',
			className,
			onClick,
			children,
			'aria-disabled': ariaDisabled,
			...rest
		},
		ref
	) {
		const iconSize = size === 'sm' ? 16 : 20;
		const handleClick = ( event: MouseEvent< HTMLButtonElement > ) => {
			if ( busy ) {
				// Also stops a submit button from submitting its form.
				event.preventDefault();
				return;
			}
			onClick?.( event );
		};

		const button = (
			<button
				{ ...rest }
				ref={ ref }
				type={ type }
				className={ buttonClassName(
					variant,
					size,
					busy && 'sup-button--busy',
					className
				) }
				aria-disabled={ busy ? true : ariaDisabled }
				onClick={ handleClick }
			>
				{ busy && <Spinner size={ iconSize } /> }
				{ ! busy && iconStart && (
					<Icon name={ iconStart } size={ iconSize } />
				) }
				<span className="sup-button__label">{ children }</span>
				{ iconEnd && <Icon name={ iconEnd } size={ iconSize } /> }
			</button>
		);

		if ( busyLabel === undefined ) {
			return button;
		}
		return (
			<>
				{ button }
				<span role="status" className="sup-visually-hidden">
					{ busy ? busyLabel : '' }
				</span>
			</>
		);
	}
);
