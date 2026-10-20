/**
 * Toasts: brief confirmations of something the user just did.
 *
 * SupRoot renders `ToastProvider`, so every screen can call `useToast()`;
 * the viewport renders in SupRoot's scoped portal layer.
 *
 * - The viewport is a polite live region (`aria-live="polite"`) that is
 *   mounted, empty, before any toast exists, so each toast is announced as
 *   a change. Every tone is announced politely: an error the user must act
 *   on belongs in a `live` Notice next to the cause, which is an alert.
 * - Success and info toasts close after TOAST_TIMEOUT_MS; the timer pauses
 *   while the pointer is over the stack, while keyboard focus is in it and
 *   while the window is in the background. Errors never close on their own.
 * - At most TOAST_LIMIT toasts show; older ones are hidden and inert.
 * - The stack sits at the block end and inline end of the window. When it
 *   would cover the focused element, it moves to the inline start (or the
 *   block start on narrow windows), so focus is never obscured (WCAG 2.4.11).
 * - F6 moves focus into the stack (Base UI); Escape closes the focused toast.
 * - A toast never states that a payment is paid or captured: financial
 *   state comes only from the caller's verified result, shown on the page.
 */
import type { ReactNode, RefObject } from 'react';
import { Toast as BaseToast } from '@base-ui/react/toast';
import type { ToastObject } from '@base-ui/react/toast';
import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useDirection } from '../foundations/direction';
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { usePortalContainer } from '../foundations/portal';
import { buttonClassName } from '../controls/Button';
import '../controls/IconButton.css';
import './Toast.css';

/** Auto-dismiss delay of success and info toasts. */
export const TOAST_TIMEOUT_MS = 6000;
/** Toasts visible at once. */
export const TOAST_LIMIT = 3;

export type ToastTone = 'success' | 'info' | 'error';

export interface ToastAction {
	/** Translated button label, e.g. "Undo". */
	label: string;
	/** Runs once; the toast then closes. */
	onClick: () => void;
}

export interface ToastOptions {
	tone: ToastTone;
	/** Translated one-line summary. */
	title: string;
	/** Translated detail or next step. */
	description?: string;
	action?: ToastAction;
}

export interface ToastController {
	/** Shows a toast and returns its id. */
	show: ( toast: ToastOptions ) => string;
	/** Closes the toast with this id; unknown ids are ignored. */
	dismiss: ( id: string ) => void;
}

interface ToastData {
	action?: ToastAction;
}

type Placement = 'end' | 'start' | 'top';

const PLACEMENTS: readonly Placement[] = [ 'end', 'start', 'top' ];

const TONE_ICONS: Readonly< Record< ToastTone, IconName > > = {
	success: 'success',
	info: 'info',
	error: 'error',
};

const ToastContext = createContext< ToastController | null >( null );

/**
 * @return The toast controller of the nearest SupRoot. Stable for the life
 *         of that SupRoot, so it is safe in effect dependencies.
 */
export function useToast(): ToastController {
	const controller = useContext( ToastContext );
	if ( ! controller ) {
		throw new Error( 'useToast() must be called inside SupRoot.' );
	}
	return controller;
}

function overlapArea( a: DOMRect, b: DOMRect ): number {
	const inline = Math.min( a.right, b.right ) - Math.max( a.left, b.left );
	const block = Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top );
	return inline > 0 && block > 0 ? inline * block : 0;
}

/**
 * Moves the viewport off the focused element: tries each placement in
 * order and keeps the first that does not overlap it (or the least
 * overlapping one). Runs when focus moves and when the stack changes.
 *
 * @param viewportRef The toast viewport.
 * @param stackKey    Changes whenever toasts are added or removed.
 */
function useFocusAvoidingPlacement(
	viewportRef: RefObject< HTMLDivElement | null >,
	stackKey: string
): void {
	useEffect( () => {
		const viewport = viewportRef.current;
		if ( ! viewport ) {
			return undefined;
		}
		const doc = viewport.ownerDocument;
		const place = () => {
			const active = doc.activeElement;
			const current = viewport.dataset.supPlacement ?? 'end';
			if (
				stackKey === '' ||
				! active ||
				active === doc.body ||
				viewport.contains( active )
			) {
				viewport.dataset.supPlacement =
					stackKey === '' ? 'end' : current;
				return;
			}
			const target = active.getBoundingClientRect();
			let best: Placement = 'end';
			let bestArea = Infinity;
			for ( const placement of PLACEMENTS ) {
				viewport.dataset.supPlacement = placement;
				const area = overlapArea(
					viewport.getBoundingClientRect(),
					target
				);
				if ( area < bestArea ) {
					best = placement;
					bestArea = area;
				}
				if ( area === 0 ) {
					break;
				}
			}
			viewport.dataset.supPlacement = best;
		};
		place();
		doc.addEventListener( 'focusin', place );
		return () => doc.removeEventListener( 'focusin', place );
	}, [ viewportRef, stackKey ] );
}

interface ToastItemProps {
	toast: ToastObject< ToastData >;
	onAction: ( id: string, action: ToastAction ) => void;
}

function ToastItem( { toast, onAction }: ToastItemProps ) {
	const direction = useDirection();
	const tone = ( toast.type ?? 'info' ) as ToastTone;
	const action = toast.data?.action;
	return (
		<BaseToast.Root
			toast={ toast }
			className={ `sup-toast sup-toast--${ tone }` }
			swipeDirection={
				direction === 'rtl' ? [ 'down', 'left' ] : [ 'down', 'right' ]
			}
		>
			<BaseToast.Content className="sup-toast__content">
				<span className="sup-toast__icon">
					<Icon name={ TONE_ICONS[ tone ] } size={ 20 } />
				</span>
				<div className="sup-toast__text">
					<BaseToast.Title
						className="sup-toast__title"
						render={ <p /> }
					/>
					{ toast.description !== undefined && (
						<BaseToast.Description className="sup-toast__description" />
					) }
				</div>
				{ action && (
					<BaseToast.Action
						className={ buttonClassName(
							'secondary',
							'sm',
							'sup-toast__action'
						) }
						onClick={ () => onAction( toast.id, action ) }
					>
						{ action.label }
					</BaseToast.Action>
				) }
				<BaseToast.Close
					className={ buttonClassName(
						'tertiary',
						'sm',
						'sup-button--icon-only',
						'sup-toast__close'
					) }
					aria-label={ __( 'Dismiss notification', 'supcheckout' ) }
					// Base UI hides Close from AT while a collapsed stack is not
					// hovered; ours never collapses, and a focusable control
					// must never be aria-hidden.
					aria-hidden={ undefined }
				>
					<Icon name="close" size={ 16 } />
				</BaseToast.Close>
			</BaseToast.Content>
		</BaseToast.Root>
	);
}

interface ToasterProps {
	onAction: ( id: string, action: ToastAction ) => void;
}

/**
 * The toast viewport, portaled into SupRoot's layer. Rendered by
 * ToastProvider; screens never render it.
 *
 * @param props          Toaster props.
 * @param props.onAction Runs a toast's action and closes the toast.
 */
function Toaster( { onAction }: ToasterProps ) {
	const container = usePortalContainer();
	const { toasts } = BaseToast.useToastManager< ToastData >();
	const viewportRef = useRef< HTMLDivElement >( null );
	const stackKey = toasts
		.filter( ( toast ) => toast.transitionStatus !== 'ending' )
		.map( ( toast ) => toast.id )
		.join( ' ' );
	useFocusAvoidingPlacement( viewportRef, stackKey );
	return (
		<BaseToast.Portal container={ container }>
			<BaseToast.Viewport
				ref={ viewportRef }
				className="sup-toast-viewport"
				aria-label={ __( 'Notifications', 'supcheckout' ) }
			>
				{ toasts.map( ( toast ) => (
					<ToastItem
						key={ toast.id }
						toast={ toast }
						onAction={ onAction }
					/>
				) ) }
			</BaseToast.Viewport>
		</BaseToast.Portal>
	);
}

export interface ToastProviderProps {
	children: ReactNode;
}

/**
 * Owns one toast manager and renders the Toaster. SupRoot renders it inside
 * its portal context.
 *
 * @param props          Provider props.
 * @param props.children The app.
 */
export function ToastProvider( { children }: ToastProviderProps ) {
	const [ manager ] = useState( () =>
		BaseToast.createToastManager< ToastData >()
	);
	// Ids whose action already ran: a double click runs the action once.
	const actedOn = useRef< ReadonlySet< string > >( new Set() );

	const controller = useMemo< ToastController >(
		() => ( {
			show( { tone, title, description, action } ) {
				return manager.add( {
					type: tone,
					title,
					description,
					timeout: tone === 'error' ? 0 : TOAST_TIMEOUT_MS,
					priority: 'low',
					data: action ? { action } : undefined,
				} );
			},
			dismiss( id ) {
				if ( id !== '' ) {
					manager.close( id );
				}
			},
		} ),
		[ manager ]
	);

	const onAction = useCallback(
		( id: string, action: ToastAction ) => {
			if ( actedOn.current.has( id ) ) {
				return;
			}
			actedOn.current = new Set( actedOn.current ).add( id );
			action.onClick();
			manager.close( id );
		},
		[ manager ]
	);

	return (
		<BaseToast.Provider
			toastManager={ manager }
			timeout={ TOAST_TIMEOUT_MS }
			limit={ TOAST_LIMIT }
		>
			<ToastContext.Provider value={ controller }>
				{ children }
				<Toaster onAction={ onAction } />
			</ToastContext.Provider>
		</BaseToast.Provider>
	);
}
