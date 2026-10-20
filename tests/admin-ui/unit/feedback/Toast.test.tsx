/**
 * Toaster and useToast: a pre-mounted polite viewport in the scoped portal
 * layer, timed success and info toasts that pause while hovered, persistent
 * errors and at most three visible toasts (SP1 Task 6).
 */
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useEffect } from '@wordpress/element';

import {
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
 * @return The controller and an unmount function.
 */
function renderToaster(): {
	toast: () => ToastController;
	unmount: () => void;
} {
	let controller: ToastController | undefined;
	const { unmount } = render(
		<SupRoot>
			<Harness
				onReady={ ( next ) => {
					controller = next;
				} }
			/>
		</SupRoot>
	);
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

const layer = () =>
	document.body.querySelector( ':scope > .sup-portal-layer' ) as HTMLElement;

const advance = ( ms: number ) =>
	act( () => {
		jest.advanceTimersByTime( ms );
	} );

afterEach( () => {
	jest.useRealTimers();
} );

test( 'the toast viewport is a polite region mounted empty before any toast', () => {
	const { toast } = renderToaster();
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

test( 'toasts render inside the scoped portal layer', () => {
	const { toast } = renderToaster();
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

test( 'at most three toasts are visible', () => {
	const { toast } = renderToaster();
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
