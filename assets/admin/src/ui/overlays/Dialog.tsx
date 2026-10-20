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
import { matchesPaletteKeys } from './shortcut';
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

/** Regions that must keep speaking while a modal is open. */
const LIVE_REGION =
	'[aria-live], [role="status"], [role="alert"], [role="log"]';

/**
 * How many open modals hold each node inert. Module level, so dialogs that
 * close out of order (not last-in, first-out) never lift `inert` from the
 * page while another dialog still needs it.
 */
const inertHolds = new Map< Element, number >();

/**
 * @param node A node to hold inert.
 * @return Whether this call holds it (false when something else made it
 *         inert first: that `inert` is never ours to lift).
 */
function holdInert( node: Element ): boolean {
	const holds = inertHolds.get( node ) ?? 0;
	if ( holds === 0 ) {
		if ( node.hasAttribute( 'inert' ) ) {
			return false;
		}
		node.setAttribute( 'inert', '' );
	}
	inertHolds.set( node, holds + 1 );
	return true;
}

function releaseInert( node: Element ): void {
	const holds = inertHolds.get( node ) ?? 0;
	if ( holds <= 1 ) {
		inertHolds.delete( node );
		if ( holds === 1 ) {
			node.removeAttribute( 'inert' );
		}
		return;
	}
	inertHolds.set( node, holds - 1 );
}

/**
 * Holds `node` inert, or, when it holds a live region, each of its children
 * that does not (recursively), leaving the region and its ancestors usable:
 * the keep-set of Base UI's markOthers, applied to `inert`.
 *
 * @param node The outside node Base UI marked.
 * @param held Collects the nodes this call holds.
 */
function inertOutside( node: Element, held: Element[] ): void {
	if ( node.matches( LIVE_REGION ) ) {
		return;
	}
	if ( node.querySelector( LIVE_REGION ) ) {
		for ( const child of Array.from( node.children ) ) {
			inertOutside( child, held );
		}
		return;
	}
	if ( holdInert( node ) ) {
		held.push( node );
	}
}

/**
 * Makes the page behind an open modal `inert`, on top of the `aria-hidden`
 * Base UI sets: nothing behind the dialog stays focusable or clickable,
 * which is what assistive technology and axe (`aria-hidden-focus`) expect
 * of a modal. Live regions (wp.a11y.speak(), admin notices) and their
 * ancestors stay usable, so announcements made while the dialog is open are
 * still heard. Base UI marks the outside nodes in its own effect, so they
 * are read one frame after opening. Nodes inside the portal layer (the
 * toast region, other overlays' portals) are left alone. Holds are
 * reference-counted across dialogs. Runs as a layout effect, so `inert` is
 * gone before Base UI returns focus to the trigger.
 *
 * @param open  Whether the dialog is open.
 * @param layer The SupRoot portal layer.
 */
function useInertBackground( open: boolean, layer: HTMLElement | null ) {
	useLayoutEffect( () => {
		if ( ! open || typeof document === 'undefined' ) {
			return undefined;
		}
		const held: Element[] = [];
		const frame = window.requestAnimationFrame( () => {
			for ( const node of document.querySelectorAll( OUTSIDE_MARKER ) ) {
				if (
					node.closest( '.sup-portal-layer' ) ||
					( layer && node.contains( layer ) )
				) {
					continue;
				}
				inertOutside( node, held );
			}
		} );
		return () => {
			window.cancelAnimationFrame( frame );
			for ( const node of held ) {
				releaseInert( node );
			}
		};
	}, [ open, layer ] );
}

/** How many SUPCheckout modals are open, across every DialogFrame. */
let openModals = 0;
let trapTarget: HTMLElement | null = null;

function keepShortcutFromCore( event: KeyboardEvent ): void {
	if ( matchesPaletteKeys( event ) ) {
		event.preventDefault();
	}
}

/**
 * While any SUPCheckout modal is open, marks Mod+K handled on its way to
 * `document`, where WordPress core's command palette listens and bails on a
 * handled key: core's palette can never open over a modal (e.g. take focus
 * and navigate away mid-refund). No stopPropagation; other keys and
 * listeners are untouched. Counted across dialogs, so Mod+K returns to core
 * only when the last SUPCheckout modal closes.
 *
 * @param open Whether this dialog is open.
 */
function useModalShortcutTrap( open: boolean ) {
	useLayoutEffect( () => {
		if ( ! open || typeof document === 'undefined' ) {
			return undefined;
		}
		if ( openModals === 0 ) {
			trapTarget = document.body;
			trapTarget.addEventListener( 'keydown', keepShortcutFromCore );
		}
		openModals += 1;
		return () => {
			openModals -= 1;
			if ( openModals === 0 && trapTarget ) {
				trapTarget.removeEventListener(
					'keydown',
					keepShortcutFromCore
				);
				trapTarget = null;
			}
		};
	}, [ open ] );
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
	/** Called after the open or close transition has finished. */
	onOpenChangeComplete?: ( open: boolean ) => void;
	children: ReactNode;
}

/**
 * The shared modal frame of Dialog, ConfirmDialog and CommandPalette:
 * backdrop, viewport and popup in the portal layer. Not exported from the
 * library barrel.
 *
 * @param props                      The frame props.
 * @param props.alert                `alertdialog` semantics.
 * @param props.open                 Whether the dialog is open.
 * @param props.onOpenChange         Receives proposed changes; `false` refuses one.
 * @param props.size                 Inline size.
 * @param props.className            Extra popup classes.
 * @param props.initialFocus         Element focused on open.
 * @param props.onOpenChangeComplete Called after the transition finished.
 * @param props.children             The popup content.
 */
export function DialogFrame( {
	alert = false,
	open,
	onOpenChange,
	size,
	className,
	initialFocus,
	onOpenChangeComplete,
	children,
}: DialogFrameProps ) {
	const container = usePortalContainer();
	// Tooltips (and any other portal) opened inside the dialog render in
	// this layer, inside the popup and the scope (see NestedLayer).
	const [ nestedLayer ] = useState( createNestedLayer );
	useInertBackground( open, container );
	useModalShortcutTrap( open );
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
				onOpenChangeComplete={ onOpenChangeComplete }
			>
				{ parts }
			</BaseAlertDialog.Root>
		);
	}
	return (
		<BaseDialog.Root
			open={ open }
			onOpenChange={ handleOpenChange }
			onOpenChangeComplete={ onOpenChangeComplete }
		>
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
