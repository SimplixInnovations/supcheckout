/**
 * Tooltip: opens after TOOLTIP_DELAY_MS on hover and at once on keyboard
 * focus, closes on Escape without moving focus, describes its trigger and
 * renders in the scoped portal layer (SP1 Task 6, WCAG 1.4.13).
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';

import {
	Button,
	SupRoot,
	TOOLTIP_DELAY_MS,
	Tooltip,
} from '../../../../assets/admin/src/ui';

const renderInRoot = ( ui: ReactElement ) =>
	render( <SupRoot>{ ui }</SupRoot> );

const advance = ( ms: number ) =>
	act( () => {
		jest.advanceTimersByTime( ms );
	} );

afterEach( () => {
	jest.useRealTimers();
	globalThis.SCRIPT_DEBUG = undefined;
} );

test( 'Tooltip opens on focus, closes on Escape and describes its trigger', async () => {
	renderInRoot(
		<Tooltip content="Copies the merchant ID">
			<Button>Copy</Button>
		</Tooltip>
	);
	const trigger = screen.getByRole( 'button', { name: 'Copy' } );
	// The description exists before focus lands, so it is read with the name.
	expect( trigger ).toHaveAccessibleDescription( 'Copies the merchant ID' );
	expect( screen.queryByRole( 'tooltip' ) ).toBeNull();

	await userEvent.tab();
	expect( trigger ).toHaveFocus();
	const tooltip = await screen.findByRole( 'tooltip' );
	expect( tooltip ).toHaveTextContent( 'Copies the merchant ID' );
	expect( trigger.getAttribute( 'aria-describedby' ) ).toContain(
		tooltip.id
	);
	expect( tooltip.closest( '.sup-portal-layer' ) ).not.toBeNull();

	await userEvent.keyboard( '{Escape}' );
	expect( screen.queryByRole( 'tooltip' ) ).toBeNull();
	expect( trigger ).toHaveFocus();
} );

test( 'Tooltip opens at once on keyboard focus, with no timer advanced', async () => {
	jest.useFakeTimers();
	// No advanceTimers option: user-event's own steps must not move the clock.
	const user = userEvent.setup( { delay: null } );
	renderInRoot(
		<Tooltip content="Copies the merchant ID">
			<Button>Copy</Button>
		</Tooltip>
	);
	await user.tab();
	expect( screen.getByRole( 'button', { name: 'Copy' } ) ).toHaveFocus();
	// getByRole, not findByRole: the tooltip is there synchronously.
	expect( screen.getByRole( 'tooltip' ) ).toHaveTextContent(
		'Copies the merchant ID'
	);
} );

test( 'Tooltip waits TOOLTIP_DELAY_MS before opening on hover', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	renderInRoot(
		<Tooltip content="Opens the UPayments dashboard" side="bottom">
			<Button>Dashboard</Button>
		</Tooltip>
	);
	expect( TOOLTIP_DELAY_MS ).toBe( 400 );
	await user.hover( screen.getByRole( 'button', { name: 'Dashboard' } ) );
	advance( TOOLTIP_DELAY_MS - 100 );
	expect( screen.queryByRole( 'tooltip' ) ).toBeNull();
	advance( 200 );
	expect( screen.getByRole( 'tooltip' ) ).toHaveTextContent(
		'Opens the UPayments dashboard'
	);

	await user.unhover( screen.getByRole( 'button', { name: 'Dashboard' } ) );
	advance( 1000 );
	expect( screen.queryByRole( 'tooltip' ) ).toBeNull();
} );

test( 'Tooltip keeps an existing description on its trigger', async () => {
	renderInRoot(
		<>
			<p id="hint">Needs a saved key.</p>
			<Tooltip content="Sends a test request">
				<Button aria-describedby="hint">Test</Button>
			</Tooltip>
		</>
	);
	const trigger = screen.getByRole( 'button', { name: 'Test' } );
	expect( trigger ).toHaveAccessibleDescription(
		'Needs a saved key. Sends a test request'
	);
} );

test( 'Tooltip renders no tooltip for a disabled child and warns in development', async () => {
	// A development build (wp-scripts defines SCRIPT_DEBUG; production is false).
	globalThis.SCRIPT_DEBUG = true;
	const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	try {
		renderInRoot(
			<Tooltip content="Refunds are not supported">
				<Button disabled>Refund</Button>
			</Tooltip>
		);
		const trigger = screen.getByRole( 'button', { name: 'Refund' } );
		expect( trigger ).not.toHaveAttribute( 'aria-describedby' );
		await userEvent.hover( trigger );
		await userEvent.tab();
		expect( screen.queryByRole( 'tooltip' ) ).toBeNull();
		expect( document.body ).not.toHaveTextContent(
			'Refunds are not supported'
		);
		expect( warn ).toHaveBeenCalledTimes( 1 );
		expect( String( warn.mock.calls[ 0 ][ 0 ] ) ).toMatch( /disabled/ );
	} finally {
		warn.mockRestore();
	}
} );

test( 'a production build never warns about a disabled child', () => {
	globalThis.SCRIPT_DEBUG = false;
	const warn = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
	try {
		renderInRoot(
			<Tooltip content="Refunds are not supported">
				<Button disabled>Refund</Button>
			</Tooltip>
		);
		expect( warn ).not.toHaveBeenCalled();
	} finally {
		warn.mockRestore();
	}
} );

test( 'unmounting an open Tooltip leaves no timer behind', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	const { unmount } = renderInRoot(
		<Tooltip content="Copies the merchant ID">
			<Button>Copy</Button>
		</Tooltip>
	);
	await user.hover( screen.getByRole( 'button', { name: 'Copy' } ) );
	unmount();
	advance( TOOLTIP_DELAY_MS * 2 );
	expect( jest.getTimerCount() ).toBe( 0 );
} );
