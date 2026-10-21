/**
 * Toaster and useToast: a pre-mounted polite viewport in the scoped portal
 * layer, timed success and info toasts that pause while hovered, persistent
 * errors and at most three visible toasts (SP1 Task 6).
 */
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { StrictMode, useEffect, useRef } from '@wordpress/element';
import type { ReactNode } from 'react';

import {
	LIVE_REGION_DELAY_MS,
	SupRoot,
	TOAST_TIMEOUT_MS,
	useToast,
} from '../../../../assets/admin/src/ui';
import type { ToastController } from '../../../../assets/admin/src/ui';

function Harness( {
	onReady,
}: {
	onReady: ( toast: ToastController ) => void;
} ) {
	const toast = useToast();
	useEffect( () => {
		onReady( toast );
	}, [ toast, onReady ] );
	return null;
}

/**
 * Renders a SupRoot and hands back its toast controller.
 *
 * @param children Extra content of the root, e.g. a focus target.
 * @return The controller and an unmount function.
 */
function renderToaster( children?: ReactNode ): {
	toast: () => ToastController;
	unmount: () => void;
} {
	let controller: ToastController | undefined;
	const { unmount } = render(
		<SupRoot>
			{ children }
			<Harness
				onReady={ ( next ) => {
					controller = next;
				} }
			/>
		</SupRoot>
	);
	// Toasts land once the viewport region has existed for
	// LIVE_REGION_DELAY_MS; under fake timers, let that time pass now.
	if ( usingFakeTimers() ) {
		act( () => {
			jest.advanceTimersByTime( LIVE_REGION_DELAY_MS );
		} );
	}
	return {
		toast: () => {
			if ( ! controller ) {
				throw new Error( 'useToast did not render' );
			}
			return controller;
		},
		unmount,
	};
}

/** @return Whether Jest's modern fake timers are installed. */
function usingFakeTimers(): boolean {
	return (
		typeof ( setTimeout as unknown as { clock?: unknown } ).clock !==
		'undefined'
	);
}

/** Lets the viewport region become ready under real timers. */
async function regionReady(): Promise< void > {
	await act( async () => {
		await new Promise( ( resolve ) =>
			setTimeout( resolve, LIVE_REGION_DELAY_MS + 10 )
		);
	} );
}

const layer = () =>
	document.body.querySelector( ':scope > .sup-portal-layer' ) as HTMLElement;

const advance = ( ms: number ) =>
	act( () => {
		jest.advanceTimersByTime( ms );
	} );

// jsdom's document.hasFocus() is false; a test window is in the foreground
// unless a test says otherwise.
let hasFocus: jest.SpyInstance;

beforeEach( () => {
	hasFocus = jest.spyOn( document, 'hasFocus' ).mockReturnValue( true );
} );

afterEach( () => {
	hasFocus.mockRestore();
	jest.useRealTimers();
} );

test( 'the toast viewport is a polite region mounted empty before any toast', async () => {
	const { toast } = renderToaster();
	await regionReady();
	const viewport = within( layer() ).getByRole( 'region', {
		name: 'Notifications',
	} );
	expect( viewport ).toHaveAttribute( 'aria-live', 'polite' );
	expect( viewport ).toHaveTextContent( '' );

	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	// The same region, now with text: an announced change, not a new region.
	expect(
		within( layer() ).getByRole( 'region', { name: 'Notifications' } )
	).toBe( viewport );
	expect( viewport ).toHaveTextContent( 'Settings saved' );
} );

test( 'toasts render inside the scoped portal layer', async () => {
	const { toast } = renderToaster();
	await regionReady();
	act( () => {
		toast().show( {
			tone: 'info',
			title: 'Sync started',
			description: 'Orders update in a minute.',
		} );
	} );
	const toastEl = screen.getByText( 'Sync started' ).closest( '.sup-toast' );
	expect( toastEl ).toHaveClass( 'sup-toast--info' );
	expect( toastEl?.closest( '.sup-portal-layer' ) ).toBe( layer() );
	expect( screen.getByText( 'Orders update in a minute.' ) ).toBeVisible();
	expect( toastEl?.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
} );

test( 'success toasts dismiss after 6000 ms but pause while hovered', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	const { toast } = renderToaster();
	expect( TOAST_TIMEOUT_MS ).toBe( 6000 );

	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	await user.hover( screen.getByText( 'Settings saved' ) );
	advance( 7000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();

	await user.unhover( screen.getByText( 'Settings saved' ) );
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Settings saved' ) ).toBeNull();
} );

test( 'an unhovered info toast stays for the timeout, then dismisses', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	act( () => {
		toast().show( { tone: 'info', title: 'Sync started' } );
	} );
	advance( TOAST_TIMEOUT_MS - 500 );
	expect( screen.getByText( 'Sync started' ) ).toBeInTheDocument();
	advance( 1000 );
	expect( screen.queryByText( 'Sync started' ) ).toBeNull();
} );

test( 'error toasts persist until dismissed', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	const { toast } = renderToaster();
	act( () => {
		toast().show( {
			tone: 'error',
			title: 'Connection failed',
			description: 'Check the API key and try again.',
		} );
	} );
	advance( 60000 );
	const toastEl = screen
		.getByText( 'Connection failed' )
		.closest( '.sup-toast' ) as HTMLElement;
	expect( toastEl ).toHaveClass( 'sup-toast--error' );

	await user.click(
		within( toastEl ).getByRole( 'button', {
			name: 'Dismiss notification',
		} )
	);
	advance( 1000 );
	expect( screen.queryByText( 'Connection failed' ) ).toBeNull();
} );

test( 'show returns an id that dismiss closes', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	let first = '';
	act( () => {
		first = toast().show( { tone: 'error', title: 'First' } );
		toast().show( { tone: 'error', title: 'Second' } );
	} );
	expect( typeof first ).toBe( 'string' );
	act( () => {
		toast().dismiss( first );
	} );
	advance( 1000 );
	expect( screen.queryByText( 'First' ) ).toBeNull();
	expect( screen.getByText( 'Second' ) ).toBeInTheDocument();
} );

test( 'at most three toasts are visible', async () => {
	const { toast } = renderToaster();
	await regionReady();
	act( () => {
		for ( const title of [ 'One', 'Two', 'Three', 'Four' ] ) {
			toast().show( { tone: 'error', title } );
		}
	} );
	const shown = Array.from(
		layer().querySelectorAll( '.sup-toast:not([data-limited])' ),
		( el ) => el.querySelector( '.sup-toast__title' )?.textContent
	);
	expect( shown ).toHaveLength( 3 );
	expect( shown ).not.toContain( 'One' );
	const limited = layer().querySelectorAll( '.sup-toast[data-limited]' );
	for ( const el of limited ) {
		expect( el ).toHaveAttribute( 'inert' );
	}
} );

test( 'a toast action runs its callback once and closes the toast', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	const onClick = jest.fn();
	const { toast } = renderToaster();
	act( () => {
		toast().show( {
			tone: 'info',
			title: 'Order archived',
			action: { label: 'Undo', onClick },
		} );
	} );
	await user.click( screen.getByRole( 'button', { name: 'Undo' } ) );
	expect( onClick ).toHaveBeenCalledTimes( 1 );
	advance( 1000 );
	expect( screen.queryByText( 'Order archived' ) ).toBeNull();
} );

test.each( [ 'info', 'success' ] as const )(
	'%s toast with an action never auto-dismisses (WCAG 2.2.1): it stays until acted on or dismissed',
	( tone ) => {
		jest.useFakeTimers();
		const { toast } = renderToaster();
		act( () => {
			toast().show( {
				tone,
				title: 'Order archived',
				action: { label: 'Undo', onClick: () => undefined },
			} );
		} );
		advance( TOAST_TIMEOUT_MS * 3 );
		expect( screen.getByText( 'Order archived' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Undo' } )
		).toBeInTheDocument();
	}
);

test( 'the controller is stable across renders', () => {
	const seen: ToastController[] = [];
	const { rerender } = render(
		<SupRoot>
			<Harness onReady={ ( next ) => seen.push( next ) } />
		</SupRoot>
	);
	rerender(
		<SupRoot>
			<Harness onReady={ ( next ) => seen.push( next ) } />
		</SupRoot>
	);
	expect( new Set( seen ).size ).toBe( 1 );
} );

test( 'unmounting with open toasts leaves no timer behind', () => {
	jest.useFakeTimers();
	const { toast, unmount } = renderToaster();
	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
		toast().show( { tone: 'error', title: 'Connection failed' } );
	} );
	unmount();
	advance( TOAST_TIMEOUT_MS * 2 );
	expect( jest.getTimerCount() ).toBe( 0 );
	expect( document.body.querySelector( '.sup-portal-layer' ) ).toBeNull();
} );

test( 'a success toast holding keyboard focus does not dismiss', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	const { toast } = renderToaster();
	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	// F6 moves focus into the stack (Base UI), Tab onto the toast itself.
	await user.keyboard( '{F6}' );
	await user.tab();
	const toastEl = screen
		.getByText( 'Settings saved' )
		.closest( '.sup-toast' ) as HTMLElement;
	expect( toastEl ).toContainElement(
		toastEl.ownerDocument.activeElement as HTMLElement
	);
	advance( TOAST_TIMEOUT_MS + 1000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
} );

const box = ( left: number, top: number, right: number, bottom: number ) =>
	( {
		left,
		top,
		right,
		bottom,
		x: left,
		y: top,
		width: right - left,
		height: bottom - top,
		toJSON: () => ( {} ),
	} ) as DOMRect;

/**
 * jsdom has no layout: the viewport's box follows its placement.
 *
 * @param viewport The toast viewport.
 */
function stubViewportBoxes( viewport: HTMLElement ): void {
	viewport.getBoundingClientRect = () =>
		viewport.dataset.supPlacement === 'start'
			? box( 24, 600, 384, 760 )
			: box( 896, 600, 1256, 760 );
}

test( 'the stack listens for focus only while toasts exist', () => {
	jest.useFakeTimers();
	const added = jest.spyOn( document, 'addEventListener' );
	const removed = jest.spyOn( document, 'removeEventListener' );
	try {
		const { toast } = renderToaster();
		const focusin = ( spy: jest.SpyInstance ) =>
			spy.mock.calls.filter( ( [ type ] ) => type === 'focusin' ).length;
		expect( focusin( added ) ).toBe( 0 );

		let id = '';
		act( () => {
			id = toast().show( { tone: 'error', title: 'Sync failed' } );
		} );
		expect( focusin( added ) ).toBe( 1 );

		act( () => {
			toast().dismiss( id );
		} );
		advance( 1000 );
		expect( screen.queryByText( 'Sync failed' ) ).toBeNull();
		expect( focusin( removed ) ).toBe( 1 );
		// An empty stack covers nothing, so nothing is re-attached.
		expect( focusin( added ) ).toBe( 1 );
	} finally {
		added.mockRestore();
		removed.mockRestore();
	}
} );

test( 'the stack moves again when the window resizes', async () => {
	const { toast } = renderToaster( <button>Target</button> );
	await regionReady();
	const target = screen.getByRole( 'button', { name: 'Target' } );
	let targetBox = box( 1100, 680, 1240, 720 );
	target.getBoundingClientRect = () => targetBox;
	target.focus();
	const viewport = layer().querySelector(
		'.sup-toast-viewport'
	) as HTMLElement;
	stubViewportBoxes( viewport );

	act( () => {
		toast().show( { tone: 'error', title: 'Sync failed' } );
	} );
	// The default corner would cover the focused button.
	expect( viewport ).toHaveAttribute( 'data-sup-placement', 'start' );

	// A narrower window moves the button out of the default corner.
	targetBox = box( 400, 100, 540, 140 );
	act( () => {
		window.dispatchEvent( new Event( 'resize' ) );
	} );
	expect( viewport ).toHaveAttribute( 'data-sup-placement', 'end' );
} );

test( 'the stack moves again when its own size changes', async () => {
	const observers: Array< {
		callback: ResizeObserverCallback;
		targets: Element[];
		disconnected: boolean;
	} > = [];
	const original = window.ResizeObserver;
	window.ResizeObserver = class {
		private readonly record: ( typeof observers )[ number ];
		constructor( callback: ResizeObserverCallback ) {
			this.record = { callback, targets: [], disconnected: false };
			observers.push( this.record );
		}
		observe( target: Element ) {
			this.record.targets.push( target );
		}
		unobserve() {}
		disconnect() {
			this.record.disconnected = true;
		}
	} as unknown as typeof ResizeObserver;
	try {
		const { toast, unmount } = renderToaster( <button>Target</button> );
		await regionReady();
		const target = screen.getByRole( 'button', { name: 'Target' } );
		let targetBox = box( 400, 100, 540, 140 );
		target.getBoundingClientRect = () => targetBox;
		target.focus();
		const viewport = layer().querySelector(
			'.sup-toast-viewport'
		) as HTMLElement;
		stubViewportBoxes( viewport );

		act( () => {
			toast().show( { tone: 'error', title: 'Sync failed' } );
		} );
		expect( viewport ).toHaveAttribute( 'data-sup-placement', 'end' );
		const watching = observers.filter( ( observer ) =>
			observer.targets.includes( viewport )
		);
		expect( watching ).toHaveLength( 1 );

		// A taller stack (another toast, a wrapped line) now reaches it.
		targetBox = box( 1100, 680, 1240, 720 );
		act( () => {
			watching[ 0 ].callback( [], {} as ResizeObserver );
		} );
		expect( viewport ).toHaveAttribute( 'data-sup-placement', 'start' );

		unmount();
		expect( watching[ 0 ].disconnected ).toBe( true );
	} finally {
		window.ResizeObserver = original;
	}
} );

test( 'a success toast that arrives while the window is in the background waits for focus', () => {
	jest.useFakeTimers();
	hasFocus.mockReturnValue( false );
	const added = jest.spyOn( window, 'addEventListener' );
	const removed = jest.spyOn( window, 'removeEventListener' );
	try {
		const { toast, unmount } = renderToaster();
		act( () => {
			toast().show( { tone: 'success', title: 'Settings saved' } );
		} );
		// Nobody is looking: the timer must not run (mui/base-ui#5955).
		advance( 60000 );
		expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();

		hasFocus.mockReturnValue( true );
		act( () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );
		advance( TOAST_TIMEOUT_MS + 500 );
		expect( screen.queryByText( 'Settings saved' ) ).toBeNull();

		// The focus listener was only there while a toast was held.
		const focusCalls = ( spy: jest.SpyInstance ) =>
			spy.mock.calls.filter( ( [ type ] ) => type === 'focus' ).length;
		expect( focusCalls( removed ) ).toBe( focusCalls( added ) );
		unmount();
	} finally {
		added.mockRestore();
		removed.mockRestore();
	}
} );

test( 'unmounting while a background toast is held removes the focus listener', () => {
	jest.useFakeTimers();
	hasFocus.mockReturnValue( false );
	const added = jest.spyOn( window, 'addEventListener' );
	const removed = jest.spyOn( window, 'removeEventListener' );
	try {
		const { toast, unmount } = renderToaster();
		act( () => {
			toast().show( { tone: 'info', title: 'Sync started' } );
		} );
		unmount();
		const focusCalls = ( spy: jest.SpyInstance ) =>
			spy.mock.calls.filter( ( [ type ] ) => type === 'focus' ).length;
		expect( focusCalls( removed ) ).toBe( focusCalls( added ) );
		expect( jest.getTimerCount() ).toBe( 0 );
	} finally {
		added.mockRestore();
		removed.mockRestore();
	}
} );

test( 'the stack moves again when a nested scroller brings the focus under it', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster(
		<div data-testid="scroller" style={ { overflow: 'auto' } }>
			<button>Target</button>
		</div>
	);
	const target = screen.getByRole( 'button', { name: 'Target' } );
	let targetBox = box( 400, 100, 540, 140 );
	target.getBoundingClientRect = () => targetBox;
	target.focus();
	const viewport = layer().querySelector(
		'.sup-toast-viewport'
	) as HTMLElement;
	stubViewportBoxes( viewport );

	act( () => {
		toast().show( { tone: 'error', title: 'Sync failed' } );
	} );
	expect( viewport ).toHaveAttribute( 'data-sup-placement', 'end' );

	// Scrolling an inner panel (scroll does not bubble) slides the focused
	// button under the default corner.
	targetBox = box( 1100, 680, 1240, 720 );
	act( () => {
		screen.getByTestId( 'scroller' ).dispatchEvent( new Event( 'scroll' ) );
	} );
	// Re-placement is throttled to one animation frame.
	advance( 32 );
	expect( viewport ).toHaveAttribute( 'data-sup-placement', 'start' );
} );

test( 'the scroll listener goes away with the last toast', () => {
	jest.useFakeTimers();
	const added = jest.spyOn( document, 'addEventListener' );
	const removed = jest.spyOn( document, 'removeEventListener' );
	try {
		const { toast } = renderToaster();
		const scrolls = ( spy: jest.SpyInstance ) =>
			spy.mock.calls.filter( ( [ type ] ) => type === 'scroll' ).length;
		let id = '';
		act( () => {
			id = toast().show( { tone: 'error', title: 'Sync failed' } );
		} );
		expect( scrolls( added ) ).toBe( 1 );
		// Capture phase (nested scrollers) and passive.
		const [ , , options ] = added.mock.calls.find(
			( [ type ] ) => type === 'scroll'
		) as [ string, unknown, AddEventListenerOptions ];
		expect( options ).toEqual(
			expect.objectContaining( { capture: true, passive: true } )
		);
		act( () => {
			toast().dismiss( id );
		} );
		advance( 1000 );
		expect( scrolls( removed ) ).toBe( 1 );
		expect( jest.getTimerCount() ).toBe( 0 );
	} finally {
		added.mockRestore();
		removed.mockRestore();
	}
} );

const blurWindow = () =>
	act( () => {
		hasFocus.mockReturnValue( false );
		window.dispatchEvent( new Event( 'blur' ) );
	} );

const focusWindow = () =>
	act( () => {
		hasFocus.mockReturnValue( true );
		window.dispatchEvent( new Event( 'focus' ) );
	} );

test( 'a background toast next to an existing one does not freeze the stack on focus', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	act( () => {
		toast().show( { tone: 'error', title: 'Sync failed' } );
	} );
	// Base UI sees this blur itself: the stack is not empty.
	blurWindow();
	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	focusWindow();
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Settings saved' ) ).toBeNull();
	expect( screen.getByText( 'Sync failed' ) ).toBeInTheDocument();
} );

test( 'a timed toast from before the blur resumes and dismisses after focus', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	advance( 2000 );
	blurWindow();
	act( () => {
		toast().show( { tone: 'info', title: 'Sync started' } );
	} );
	// Paused while in the background.
	advance( 60000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
	focusWindow();
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Settings saved' ) ).toBeNull();
	expect( screen.queryByText( 'Sync started' ) ).toBeNull();
} );

function ShowOnceInBackground( {
	onShown,
}: {
	onShown: ( id: string ) => void;
} ) {
	const toast = useToast();
	const shown = useRef( false );
	useEffect( () => {
		// Guarded, as screens do, so StrictMode's second run shows nothing.
		if ( shown.current ) {
			return;
		}
		shown.current = true;
		onShown(
			toast.show( { tone: 'success', title: 'Imported 12 orders' } )
		);
	}, [ toast, onShown ] );
	return null;
}

test( 'under StrictMode a toast held during mount still starts timing on focus', () => {
	jest.useFakeTimers();
	hasFocus.mockReturnValue( false );
	render(
		<StrictMode>
			<SupRoot>
				<ShowOnceInBackground onShown={ () => undefined } />
			</SupRoot>
		</StrictMode>
	);
	advance( 60000 );
	expect( screen.getByText( 'Imported 12 orders' ) ).toBeInTheDocument();
	focusWindow();
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Imported 12 orders' ) ).toBeNull();
} );

function ShowOnMount() {
	const toast = useToast();
	useEffect( () => {
		toast.show( { tone: 'error', title: 'Shown on mount' } );
	}, [ toast ] );
	return null;
}

test( "a toast shown from a screen's mount effect is not lost", () => {
	// Children's effects run before Base UI's provider subscribes to the
	// manager, so an add() then would vanish unless it is queued.
	jest.useFakeTimers();
	render(
		<SupRoot>
			<ShowOnMount />
		</SupRoot>
	);
	advance( LIVE_REGION_DELAY_MS );
	expect( screen.getByText( 'Shown on mount' ) ).toBeInTheDocument();
} );

test( 'a mount-time toast waits until the viewport region has existed LIVE_REGION_DELAY_MS, so it is announced', () => {
	jest.useFakeTimers();
	render(
		<SupRoot>
			<ShowOnMount />
		</SupRoot>
	);
	const viewport = within( layer() ).getByRole( 'region', {
		name: 'Notifications',
	} );
	// Mounted empty: the toast is held, not inserted with the region.
	expect( viewport ).toHaveTextContent( /^$/ );
	advance( LIVE_REGION_DELAY_MS - 1 );
	expect( viewport ).toHaveTextContent( /^$/ );
	advance( 1 );
	expect( viewport ).toHaveTextContent( 'Shown on mount' );
} );

test( 'an error that arrives in the background does not unhold the next success', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	hasFocus.mockReturnValue( false );
	act( () => {
		toast().show( { tone: 'error', title: 'Sync failed' } );
		toast().show( { tone: 'success', title: 'Settings saved' } );
	} );
	advance( 60000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
	focusWindow();
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Settings saved' ) ).toBeNull();
	expect( screen.getByText( 'Sync failed' ) ).toBeInTheDocument();
} );

test( 'success, error, success in the background all wait for focus', () => {
	jest.useFakeTimers();
	const { toast } = renderToaster();
	hasFocus.mockReturnValue( false );
	act( () => {
		toast().show( { tone: 'success', title: 'Settings saved' } );
		toast().show( { tone: 'error', title: 'Sync failed' } );
		toast().show( { tone: 'success', title: 'Imported 12 orders' } );
	} );
	advance( 60000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
	expect( screen.getByText( 'Imported 12 orders' ) ).toBeInTheDocument();
	focusWindow();
	advance( TOAST_TIMEOUT_MS + 500 );
	expect( screen.queryByText( 'Settings saved' ) ).toBeNull();
	expect( screen.queryByText( 'Imported 12 orders' ) ).toBeNull();
	expect( screen.getByText( 'Sync failed' ) ).toBeInTheDocument();
} );

function ShowAndDismissOnMount() {
	const toast = useToast();
	useEffect( () => {
		// Before Base UI subscribes: queued, then dropped from the queue.
		toast.dismiss( toast.show( { tone: 'info', title: 'Never shown' } ) );
	}, [ toast ] );
	return null;
}

test( 'a toast dismissed while still queued leaves no trace in the hold', () => {
	jest.useFakeTimers();
	let controller: ToastController | undefined;
	render(
		<SupRoot>
			<ShowAndDismissOnMount />
			<Harness
				onReady={ ( next ) => {
					controller = next;
				} }
			/>
		</SupRoot>
	);
	expect( screen.queryByText( 'Never shown' ) ).toBeNull();
	hasFocus.mockReturnValue( false );
	act( () => {
		controller?.show( { tone: 'success', title: 'Settings saved' } );
	} );
	// The dropped toast does not count as one Base UI could time.
	advance( 60000 );
	expect( screen.getByText( 'Settings saved' ) ).toBeInTheDocument();
} );
