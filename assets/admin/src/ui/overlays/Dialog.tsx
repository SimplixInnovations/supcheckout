/**
 * Dialog: a modal on Base UI Dialog.
 *
 * - Portaled into the SupRoot portal layer (`usePortalContainer()`), so it
 *   keeps the tokens, theme, direction and Arabic stack, and sits above the
 *   WordPress admin bar and menu.
 * - Focus moves inside on open (the first tabbable element, or
 *   `initialFocus`), Tab stays inside, the page behind is inert, and focus
 *   returns to the element that opened it on close.
 * - A dismissible dialog (the default) closes on Escape, on a backdrop
 *   click and with its close button. `dismissible={ false }` keeps it open
 *   until the caller closes it, e.g. from a footer action.
 * - An open Tooltip inside closes on Escape first; the dialog stays open
 *   until the next Escape. Tooltips inside render in the dialog's own
 *   layer, so the modal never hides them from assistive technology.
 *
 * Copy (ux-copy): the title names the task ("Edit webhook"), the optional
 * description says what it changes, and footer buttons name their action.
 * Reach for a dialog only when the task needs protected focus; most
 * settings belong inline.
 */
import type { ReactNode, RefObject } from 'react';
import { AlertDialog as BaseAlertDialog } from '@base-ui/react/alert-dialog';
import { Dialog as BaseDialog } from '@base-ui/react/dialog';
import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { classNames } from '../controls/classNames';
import { buttonClassName } from '../controls/Button';
import { Icon } from '../foundations/Icon';
import { PortalContext, usePortalContainer } from '../foundations/portal';
import '../controls/IconButton.css';
import './Dialog.css';

export type DialogSize = 'sm' | 'md' | 'lg';

export interface DialogProps {
	open: boolean;
	/** Called with `false` when the user dismisses the dialog. */
	onOpenChange: ( open: boolean ) => void;
	/** Translated title; it names the dialog. */
	title: string;
	/** Translated one-line description; it describes the dialog. */
	description?: string;
	/** Inline size; defaults to `md`. */
	size?: DialogSize;
	/** Escape, backdrop click and the close button close it; defaults to true. */
	dismissible?: boolean;
	/** Element that receives focus on open, instead of the first tabbable one. */
	initialFocus?: RefObject< HTMLElement | null >;
	/** Actions, e.g. Cancel and a primary Button, at the block end. */
	footer?: ReactNode;
	children: ReactNode;
}

/** Why Base UI wants to close: dismissals the frame may refuse. */
type CloseReason =
	| 'trigger-press'
	| 'outside-press'
	| 'escape-key'
	| 'close-press'
	| 'focus-out'
	| 'imperative-action'
	| 'none';

interface ChangeDetails {
	reason: CloseReason;
	cancel: () => void;
}

/**
 * @param layer The portal layer.
 * @return Whether a tooltip is open in the layer: Escape then belongs to it.
 */
function tooltipOpen( layer: HTMLElement | null ): boolean {
	return Boolean( layer?.querySelector( '[role="tooltip"][data-open]' ) );
}

function createNestedLayer(): HTMLElement | null {
	if ( typeof document === 'undefined' ) {
		return null;
	}
	const element = document.createElement( 'div' );
	element.className = 'sup-dialog-layer';
	return element;
}

interface NestedLayerProps {
	layer: HTMLElement | null;
}

/**
 * Attaches the dialog's own portal layer as the popup's first child. Base
 * UI never hides or inerts the popup's subtree, so a tooltip opened inside
 * the dialog stays available to assistive technology and the pointer. It
 * renders before the dialog content, so (like PortalLayerAttacher) the
 * layer is attached before any layout effect of that content runs.
 *
 * @param props       The layer.
 * @param props.layer The element from `createNestedLayer()`.
 */
function NestedLayer( { layer }: NestedLayerProps ) {
	const hostRef = useRef< HTMLDivElement >( null );
	useLayoutEffect( () => {
		const host = hostRef.current;
		if ( ! host || ! layer ) {
			return undefined;
		}
		host.appendChild( layer );
		return () => {
			layer.remove();
		};
	}, [ layer ] );
	return <div ref={ hostRef } className="sup-dialog-layer-host" />;
}

/** The attribute Base UI puts on everything outside an open popup. */
const OUTSIDE_MARKER = '[data-base-ui-inert]';

/**
 * Makes the page behind an open modal `inert`, on top of the `aria-hidden`
 * Base UI sets: nothing behind the dialog stays focusable or clickable,
 * which is what assistive technology and axe (`aria-hidden-focus`) expect
 * of a modal. Base UI marks the outside nodes in its own effect, so they
 * are read one frame after opening. Nodes inside the portal layer (the
 * toast region, other overlays' portals) are left alone. Runs as a layout
 * effect, so `inert` is gone before Base UI returns focus to the trigger.
 *
 * @param open  Whether the dialog is open.
 * @param layer The SupRoot portal layer.
 */
function useInertBackground( open: boolean, layer: HTMLElement | null ) {
	useLayoutEffect( () => {
		if ( ! open || typeof document === 'undefined' ) {
			return undefined;
		}
		const made: Element[] = [];
		const frame = window.requestAnimationFrame( () => {
			for ( const node of document.querySelectorAll( OUTSIDE_MARKER ) ) {
				if (
					node.hasAttribute( 'inert' ) ||
					node.closest( '.sup-portal-layer' ) ||
					( layer && node.contains( layer ) )
				) {
					continue;
				}
				node.setAttribute( 'inert', '' );
				made.push( node );
			}
		} );
		return () => {
			window.cancelAnimationFrame( frame );
			for ( const node of made ) {
				node.removeAttribute( 'inert' );
			}
		};
	}, [ open, layer ] );
}

export interface DialogFrameProps {
	/** `alertdialog` semantics (Base UI AlertDialog): no backdrop dismissal. */
	alert?: boolean;
	open: boolean;
	/**
	 * Receives every change Base UI proposes. Return `false` to refuse a
	 * close (the frame then cancels it).
	 */
	onOpenChange: ( open: boolean, reason: CloseReason ) => boolean | void;
	size: DialogSize;
	className?: string;
	initialFocus?: RefObject< HTMLElement | null >;
	children: ReactNode;
}

/**
 * The shared modal frame of Dialog, ConfirmDialog and CommandPalette:
 * backdrop, viewport and popup in the portal layer. Not exported from the
 * library barrel.
 *
 * @param props              The frame props.
 * @param props.alert        `alertdialog` semantics.
 * @param props.open         Whether the dialog is open.
 * @param props.onOpenChange Receives proposed changes; `false` refuses one.
 * @param props.size         Inline size.
 * @param props.className    Extra popup classes.
 * @param props.initialFocus Element focused on open.
 * @param props.children     The popup content.
 */
export function DialogFrame( {
	alert = false,
	open,
	onOpenChange,
	size,
	className,
	initialFocus,
	children,
}: DialogFrameProps ) {
	const container = usePortalContainer();
	// Tooltips (and any other portal) opened inside the dialog render in
	// this layer, inside the popup and the scope (see NestedLayer).
	const [ nestedLayer ] = useState( createNestedLayer );
	const handleOpenChange = ( next: boolean, details: ChangeDetails ) => {
		const tooltipFirst =
			! next &&
			details.reason === 'escape-key' &&
			tooltipOpen( container );
		if ( tooltipFirst || onOpenChange( next, details.reason ) === false ) {
			details.cancel();
		}
	};
	const parts = (
		<BaseDialog.Portal container={ container }>
			<BaseDialog.Backdrop className="sup-dialog-backdrop" />
			<BaseDialog.Viewport className="sup-dialog-viewport">
				<BaseDialog.Popup
					className={ classNames(
						'sup-dialog',
						`sup-dialog--${ size }`,
						className
					) }
					initialFocus={ initialFocus }
				>
					<NestedLayer layer={ nestedLayer } />
					<PortalContext.Provider value={ nestedLayer ?? container }>
						{ children }
					</PortalContext.Provider>
				</BaseDialog.Popup>
			</BaseDialog.Viewport>
		</BaseDialog.Portal>
	);
	if ( alert ) {
		return (
			<BaseAlertDialog.Root
				open={ open }
				onOpenChange={ handleOpenChange }
			>
				{ parts }
			</BaseAlertDialog.Root>
		);
	}
	return (
		<BaseDialog.Root open={ open } onOpenChange={ handleOpenChange }>
			{ parts }
		</BaseDialog.Root>
	);
}

export function Dialog( {
	open,
	onOpenChange,
	title,
	description,
	size = 'md',
	dismissible = true,
	initialFocus,
	footer,
	children,
}: DialogProps ) {
	return (
		<DialogFrame
			open={ open }
			size={ size }
			initialFocus={ initialFocus }
			onOpenChange={ ( next ) => {
				if ( ! next && ! dismissible ) {
					return false;
				}
				onOpenChange( next );
				return undefined;
			} }
		>
			<div className="sup-dialog__header">
				<div className="sup-dialog__heading">
					<BaseDialog.Title className="sup-dialog__title">
						{ title }
					</BaseDialog.Title>
					{ description !== undefined && (
						<BaseDialog.Description className="sup-dialog__description">
							{ description }
						</BaseDialog.Description>
					) }
				</div>
				{ dismissible && (
					<BaseDialog.Close
						className={ buttonClassName(
							'tertiary',
							'sm',
							'sup-button--icon-only',
							'sup-dialog__close'
						) }
						aria-label={ __( 'Close dialog', 'supcheckout' ) }
					>
						<Icon name="close" size={ 16 } />
					</BaseDialog.Close>
				) }
			</div>
			<div className="sup-dialog__body">{ children }</div>
			{ footer !== undefined && (
				<div className="sup-dialog__footer">{ footer }</div>
			) }
		</DialogFrame>
	);
}
