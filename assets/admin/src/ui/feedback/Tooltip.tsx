/**
 * Tooltip: a short supplementary description of one control, on Base UI
 * Tooltip, portaled into the scoped layer (`usePortalContainer()`).
 *
 * - Opens after TOOLTIP_DELAY_MS of hover and at once on keyboard focus.
 * - WCAG 1.4.13: Escape closes it without moving focus (dismissible), the
 *   pointer can move onto it without closing it (hoverable), and it stays
 *   until the pointer or focus leaves (persistent).
 * - The trigger's `aria-describedby` names the tooltip. The popup stays
 *   mounted (hidden) while closed, so the description exists before focus
 *   lands and is read together with the control's name.
 * - Nothing essential may live only in a tooltip: touch and screen-reader
 *   users may never see it open.
 * - A disabled control cannot be focused or reliably hovered, so a tooltip
 *   on one would be unreachable: Tooltip then renders the child alone and
 *   warns in development (SCRIPT_DEBUG).
 *
 * IconButton's `showTooltip` uses the label variant: the tooltip repeats the
 * accessible name visually and is not wired as a description, which would
 * make screen readers say the name twice.
 */
import type { ReactElement } from 'react';
import { Tooltip as BaseTooltip } from '@base-ui/react/tooltip';
import { cloneElement, useEffect, useId } from '@wordpress/element';

import { usePortalContainer } from '../foundations/portal';
import { describedBy } from '../controls/classNames';
import './Tooltip.css';

declare global {
	/**
	 * `@wordpress/scripts` defines `globalThis.SCRIPT_DEBUG` with webpack's
	 * DefinePlugin: `false` in production builds, which drops the
	 * development warning below from the shipped bundle (as in
	 * `@wordpress/warning`). Undefined outside a build unless a test sets it.
	 * A `var`, because only a var is readable through `globalThis`.
	 */
	var SCRIPT_DEBUG: boolean | undefined;
}

/** Hover delay before a tooltip opens; keyboard focus opens it at once. */
export const TOOLTIP_DELAY_MS = 400;

/** Gap between the trigger and the tooltip, in CSS px (--sup-space-3). */
const SIDE_OFFSET_PX = 8;
/** Minimum distance from the viewport edge, in CSS px (--sup-space-3). */
const COLLISION_PADDING_PX = 8;

export type TooltipSide = 'top' | 'bottom' | 'inline-start' | 'inline-end';

interface TriggerProps {
	disabled?: boolean;
	'aria-describedby'?: string;
}

export interface TooltipProps {
	/** Translated tooltip text. */
	content: string;
	/** One focusable element that forwards its ref, e.g. a Button. */
	children: ReactElement;
	/** Preferred side; it flips to stay in view. Defaults to `top`. */
	side?: TooltipSide;
}

interface TooltipBaseProps extends TooltipProps {
	/** Wire the tooltip as the trigger's description. */
	describe: boolean;
}

function TooltipBase( {
	content,
	children,
	side = 'top',
	describe,
}: TooltipBaseProps ) {
	const container = usePortalContainer();
	const popupId = useId();
	const triggerProps = children.props as TriggerProps;
	const disabled = triggerProps.disabled === true;

	useEffect( () => {
		if ( disabled && globalThis.SCRIPT_DEBUG ) {
			// eslint-disable-next-line no-console -- development-only guidance, dropped from production builds.
			console.warn(
				'SUPCheckout Tooltip: the child is disabled, so no tooltip is rendered. A disabled control cannot be focused, which would hide the tooltip from keyboard users.'
			);
		}
	}, [ disabled ] );

	if ( disabled ) {
		return children;
	}

	// The child's own props win over the Trigger's when Base UI merges them,
	// so the description is joined onto the child itself.
	const trigger = describe
		? cloneElement( children, {
				'aria-describedby': describedBy(
					triggerProps[ 'aria-describedby' ],
					popupId
				),
			} )
		: children;
	return (
		<BaseTooltip.Root>
			<BaseTooltip.Trigger
				render={ trigger }
				delay={ TOOLTIP_DELAY_MS }
			/>
			<BaseTooltip.Portal
				container={ container }
				keepMounted={ describe }
			>
				<BaseTooltip.Positioner
					className="sup-tooltip-positioner"
					side={ side }
					sideOffset={ SIDE_OFFSET_PX }
					collisionPadding={ COLLISION_PADDING_PX }
				>
					<BaseTooltip.Popup
						id={ popupId }
						role="tooltip"
						className="sup-tooltip"
					>
						{ content }
					</BaseTooltip.Popup>
				</BaseTooltip.Positioner>
			</BaseTooltip.Portal>
		</BaseTooltip.Root>
	);
}

export function Tooltip( props: TooltipProps ) {
	return <TooltipBase { ...props } describe />;
}

/**
 * The label variant for icon-only controls: shows the accessible name as a
 * tooltip without describing the control with it.
 *
 * @param props The tooltip props; `content` repeats the control's name.
 */
export function LabelTooltip( props: TooltipProps ) {
	return <TooltipBase { ...props } describe={ false } />;
}
