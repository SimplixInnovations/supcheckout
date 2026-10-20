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
 *   while the window is in the background. A toast that arrives while the
 *   window is already in the background, with no other timed toast on the
 *   stack, waits for the window to regain focus before its timer starts
 *   (works around mui/base-ui#5955, where such a toast starts timing at
 *   once; see createBackgroundHold). Errors never close on their own.
 * - At most TOAST_LIMIT toasts show; older ones are hidden and inert.
 *   Known limitation: when a visible toast closes and a hidden one becomes
 *   visible again, whether a screen reader re-announces it depends on the
 *   screen reader; it is not verifiable in CI and is on the owner's manual
 *   NVDA / VoiceOver check list.
 * - The stack sits at the block end and inline end of the window. When it
 *   would cover the focused element, it moves to the inline start (or the
 *   block start on narrow windows), so focus is never obscured (WCAG 2.4.11).
 *   It re-checks on focus changes, scrolling (nested scrollers included),
 *   window resizes and its own size changes, and listens only while toasts
 *   exist.
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
	/**
	 * `success` and `info` close after TOAST_TIMEOUT_MS. `error` stays until
	 * dismissed and, like every toast, is announced politely: an error the
	 * user must act on now belongs in a `live` Notice next to its cause,
	 * which is an alert.
	 */
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
 * A toast shown from a screen's own mount effect is queued until Base UI
 * listens, so it is not lost. Under React's StrictMode (development only)
 * that effect runs twice and shows the toast twice: guard one-shot mount
 * toasts, for example with a ref.
 *
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
 * overlapping one). While toasts exist it re-runs when focus moves, when the
 * page or any nested scroller scrolls (once per frame), when the window
 * resizes and when the stack's own size changes (ResizeObserver);
 * with no toast it resets to the default corner and listens to nothing.
 *
 * @param viewportRef The toast viewport.
 * @param stackKey    The ids of the toasts that are not leaving; empty
 *                    when there is none.
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
		if ( stackKey === '' ) {
			viewport.dataset.supPlacement = 'end';
			return undefined;
		}
		const doc = viewport.ownerDocument;
		const win = doc.defaultView;
		const place = () => {
			const active = doc.activeElement;
			if (
				! active ||
				active === doc.body ||
				viewport.contains( active )
			) {
				viewport.dataset.supPlacement ??= 'end';
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
		// Scroll fires often and does not bubble: listen in the capture phase
		// (so nested scrollers count), passively, at most once per frame.
		let frame: number | null = null;
		const onScroll = () => {
			if ( frame !== null || ! win ) {
				return;
			}
			frame = win.requestAnimationFrame( () => {
				frame = null;
				place();
			} );
		};
		const scrollOptions = { capture: true, passive: true };
		place();
		doc.addEventListener( 'focusin', place );
		doc.addEventListener( 'scroll', onScroll, scrollOptions );
		win?.addEventListener( 'resize', place );
		const observer =
			typeof ResizeObserver === 'undefined'
				? null
				: new ResizeObserver( place );
		observer?.observe( viewport );
		return () => {
			doc.removeEventListener( 'focusin', place );
			doc.removeEventListener( 'scroll', onScroll, scrollOptions );
			win?.removeEventListener( 'resize', place );
			if ( frame !== null ) {
				win?.cancelAnimationFrame( frame );
			}
			observer?.disconnect();
		};
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

type ToastAddOptions = Parameters<
	ReturnType< typeof BaseToast.createToastManager< ToastData > >[ 'add' ]
>[ 0 ];

interface BackgroundHold {
	/**
	 * Records a new toast on the stack and decides whether it is held.
	 *
	 * @param id    The toast's id.
	 * @param timed Whether the toast would auto-dismiss (success, info).
	 * @return Whether to create it untimed until the window regains focus.
	 */
	admit: ( id: string, timed: boolean ) => boolean;
	/** The toast left the stack (removed by Base UI, or dismissed queued). */
	removed: ( id: string ) => void;
	/** Drops a held toast that was dismissed. */
	forget: ( id: string ) => void;
	/** Re-attaches after a (StrictMode) remount; releases if focused. */
	resume: () => void;
	/** Removes the focus listener and any pending release (unmount). */
	dispose: () => void;
}

/**
 * Works around mui/base-ui#5955 (Base UI 1.8.0): a toast created while the
 * window is unfocused and no toast exists starts its auto-dismiss timer at
 * once, so it can vanish before anyone sees it. Base UI attaches its window
 * blur and focus listeners only while toasts exist, so it misses a blur
 * that happened while the stack was empty.
 *
 * - A toast created while the window is in the background and every toast
 *   on the stack is "unseen" (Base UI cannot have seen the blur: the stack
 *   was empty, or holds only toasts that also arrived unseen) joins the
 *   unseen set, whatever its tone. Such a success or info toast is held
 *   (created untimed); an error never times out anyway.
 * - When other toasts were already on the stack, Base UI saw the blur and
 *   pauses every timer itself, so a new toast is created timed and paused
 *   with them.
 * - On the window `focus` event the unseen set is cleared (Base UI now
 *   tracks the window) and the held toasts get TOAST_TIMEOUT_MS in a 0 ms
 *   timeout: Base UI resumes its timers on focus but marks the window
 *   focused only after its own 0 ms timeout, and an update before that
 *   would pause every timer again.
 * - The `focus` listener exists only while an unseen toast is on the stack.
 *
 * Known upstream limitation in the other direction: when Base UI saw a blur
 * and the stack empties while the window is still in the background, it
 * keeps the window marked unfocused, so the next toast shown in the
 * foreground stays paused until the pointer passes over the stack or the
 * window blurs and refocuses. That errs on the safe side (the toast stays).
 *
 * @param manager The provider's toast manager.
 * @return The hold for that manager.
 */
function createBackgroundHold(
	manager: ReturnType< typeof BaseToast.createToastManager< ToastData > >
): BackgroundHold {
	let active: readonly string[] = [];
	let unseen: readonly string[] = [];
	let held: readonly string[] = [];
	let target: Window | null = null;
	let pending: ReturnType< typeof setTimeout > | null = null;

	const windowInBackground = () =>
		typeof document !== 'undefined' && ! document.hasFocus();
	const without = ( ids: readonly string[], id: string ) =>
		ids.filter( ( other ) => other !== id );

	const flush = () => {
		pending = null;
		const ids = held;
		held = [];
		for ( const id of ids ) {
			manager.update( id, { timeout: TOAST_TIMEOUT_MS } );
		}
	};
	const detach = () => {
		target?.removeEventListener( 'focus', release );
		target = null;
	};
	const scheduleFlush = () => {
		if ( pending === null ) {
			pending = setTimeout( flush, 0 );
		}
	};
	const release = () => {
		// From here on Base UI's own listeners are attached and see blurs.
		unseen = [];
		detach();
		scheduleFlush();
	};
	const attach = () => {
		if ( target || unseen.length === 0 || typeof window === 'undefined' ) {
			return;
		}
		target = window;
		target.addEventListener( 'focus', release );
	};
	const settle = () => {
		if ( unseen.length === 0 && held.length === 0 ) {
			detach();
		}
	};

	return {
		admit( id, timed ) {
			const blurUnseen =
				windowInBackground() &&
				active.every( ( other ) => unseen.includes( other ) );
			active = [ ...active, id ];
			if ( ! blurUnseen ) {
				return false;
			}
			unseen = [ ...unseen, id ];
			if ( timed ) {
				held = [ ...held, id ];
			}
			attach();
			return timed;
		},
		removed( id ) {
			active = without( active, id );
			unseen = without( unseen, id );
			held = without( held, id );
			settle();
		},
		forget( id ) {
			held = without( held, id );
			settle();
		},
		resume() {
			if ( unseen.length === 0 && held.length === 0 ) {
				return;
			}
			if ( windowInBackground() ) {
				attach();
			} else {
				release();
			}
		},
		dispose() {
			detach();
			if ( pending !== null ) {
				clearTimeout( pending );
				pending = null;
			}
		},
	};
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
	const [ backgroundHold ] = useState( () =>
		createBackgroundHold( manager )
	);
	// Base UI's provider subscribes to the manager in an effect, which runs
	// after its children's effects: a toast shown from a screen's mount
	// effect is queued until then. This effect is the parent's, so it runs
	// after that subscription; it flushes the queue, and resume() re-attaches
	// the background hold after StrictMode's simulated unmount and remount.
	const subscribed = useRef( false );
	const queued = useRef< readonly ToastAddOptions[] >( [] );
	useEffect( () => {
		subscribed.current = true;
		const pending = queued.current;
		queued.current = [];
		for ( const options of pending ) {
			manager.add( options );
		}
		backgroundHold.resume();
		return () => {
			subscribed.current = false;
			backgroundHold.dispose();
		};
	}, [ manager, backgroundHold ] );
	// Per-provider toast ids, known before add() so onRemove can name them.
	const nextId = useRef( 0 );
	// Ids whose action already ran: a double click runs the action once.
	const actedOn = useRef< ReadonlySet< string > >( new Set() );

	const controller = useMemo< ToastController >(
		() => ( {
			show( { tone, title, description, action } ) {
				const timed = tone !== 'error';
				nextId.current += 1;
				const id = `sup-toast-${ nextId.current }`;
				const hold = backgroundHold.admit( id, timed );
				const options: ToastAddOptions = {
					id,
					type: tone,
					title,
					description,
					timeout: timed && ! hold ? TOAST_TIMEOUT_MS : 0,
					priority: 'low',
					data: action ? { action } : undefined,
					onRemove: () => backgroundHold.removed( id ),
				};
				if ( subscribed.current ) {
					manager.add( options );
				} else {
					queued.current = [ ...queued.current, options ];
				}
				return id;
			},
			dismiss( id ) {
				if ( id === '' ) {
					return;
				}
				const wasQueued = queued.current.some(
					( options ) => options.id === id
				);
				if ( wasQueued ) {
					// Never reached Base UI, so onRemove will never run.
					queued.current = queued.current.filter(
						( options ) => options.id !== id
					);
					backgroundHold.removed( id );
					return;
				}
				backgroundHold.forget( id );
				manager.close( id );
			},
		} ),
		[ manager, backgroundHold ]
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
