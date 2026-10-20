/**
 * ConfirmDialog: the money-safety confirmation (SP1 Task 7, Review Focus 1).
 *
 * `onConfirm` runs at most once per opening, whatever the input (double
 * click, held Enter, Enter then click). While it is pending the dialog
 * cannot be dismissed. It then shows the caller's result, never infers
 * success and never closes on its own. A rejected promise, a thrown error
 * and a malformed result all become the failed result.
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';

import {
	Button,
	ConfirmDialog,
	SupRoot,
} from '../../../../assets/admin/src/ui';
import type {
	ConfirmDialogProps,
	ConfirmResult,
} from '../../../../assets/admin/src/ui';

const GENERIC_FAILURE =
	'The request did not complete. Refresh to see the current state before trying again.';

interface Deferred< T > {
	promise: Promise< T >;
	resolve: ( value: T ) => void;
	reject: ( reason: unknown ) => void;
}

function deferred< T >(): Deferred< T > {
	let resolve: ( value: T ) => void = () => undefined;
	let reject: ( reason: unknown ) => void = () => undefined;
	const promise = new Promise< T >( ( res, rej ) => {
		resolve = res;
		reject = rej;
	} );
	return { promise, resolve, reject };
}

type Overrides = Partial< ConfirmDialogProps >;

function Host( { overrides }: { overrides: Overrides } ) {
	const [ open, setOpen ] = useState( true );
	return (
		<>
			<Button onClick={ () => setOpen( true ) }>Refund order</Button>
			<ConfirmDialog
				title="Refund 12.500 KWD?"
				consequence="UPayments returns the money to the customer's card. This cannot be undone."
				confirmLabel="Refund 12.500 KWD"
				tone="money"
				onConfirm={ () => new Promise< ConfirmResult >( () => {} ) }
				{ ...overrides }
				open={ open }
				onOpenChange={ ( next ) => {
					overrides.onOpenChange?.( next );
					setOpen( next );
				} }
			/>
		</>
	);
}

function renderConfirm( overrides: Overrides = {} ) {
	return render(
		<SupRoot>
			<Host overrides={ overrides } />
		</SupRoot>
	);
}

const dialog = () => screen.getByRole( 'alertdialog' );
const confirmBtn = ( name = 'Refund 12.500 KWD' ) =>
	within( dialog() ).getByRole( 'button', { name } );
const cancelBtn = () =>
	within( dialog() ).getByRole( 'button', { name: 'Cancel' } );

test( 'ConfirmDialog for money focuses Cancel first', async () => {
	renderConfirm( { tone: 'money' } );
	expect( dialog() ).toHaveAccessibleName( 'Refund 12.500 KWD?' );
	expect( dialog() ).toHaveAccessibleDescription(
		"UPayments returns the money to the customer's card. This cannot be undone."
	);
	await waitFor( () => expect( cancelBtn() ).toHaveFocus() );
	expect( dialog().closest( '.sup-portal-layer' ) ).not.toBeNull();
} );

test( 'ConfirmDialog calls onConfirm exactly once under double click and held Enter', async () => {
	const onConfirm = jest.fn( () => new Promise< ConfirmResult >( () => {} ) );
	renderConfirm( { onConfirm, confirmLabel: 'Send pay link' } );
	await waitFor( () => expect( cancelBtn() ).toHaveFocus() );
	const btn = confirmBtn( 'Send pay link' );
	await userEvent.dblClick( btn );
	btn.focus();
	await userEvent.keyboard( '{Enter>5}{/Enter}' );
	await userEvent.click( btn );
	await userEvent.keyboard( ' ' );
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	// Busy keeps focus on the button and its visible label.
	expect( btn ).toHaveFocus();
	expect( btn ).toHaveAttribute( 'aria-disabled', 'true' );
} );

test( 'Enter then a click on the confirm button still runs onConfirm once', async () => {
	const onConfirm = jest.fn( () => new Promise< ConfirmResult >( () => {} ) );
	renderConfirm( { onConfirm } );
	// Let the dialog's initial focus (Cancel) land before moving it.
	await waitFor( () => expect( cancelBtn() ).toHaveFocus() );
	confirmBtn().focus();
	await userEvent.keyboard( '{Enter}' );
	await userEvent.click( confirmBtn() );
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
} );

test( 'ConfirmDialog cannot be dismissed while pending and then shows the verified result', async () => {
	const d = deferred< ConfirmResult >();
	const onOpenChange = jest.fn();
	renderConfirm( { onConfirm: () => d.promise, onOpenChange } );
	await userEvent.click( confirmBtn() );
	await userEvent.keyboard( '{Escape}' );
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );
	expect( cancelBtn() ).toHaveAttribute( 'aria-disabled', 'true' );
	await userEvent.click( cancelBtn() );
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );
	// Nothing claims success before the caller's result.
	expect( within( dialog() ).queryByText( 'Verified' ) ).toBeNull();

	await act( async () =>
		d.resolve( {
			status: 'verified',
			message: 'UPayments confirmed the pay link.',
		} )
	);
	expect(
		screen.getByText( 'UPayments confirmed the pay link.' )
	).toBeVisible();
	expect( within( dialog() ).getByText( 'Verified' ) ).toBeVisible();
	const close = screen.getByRole( 'button', { name: 'Close' } );
	await waitFor( () => expect( close ).toHaveFocus() );
	expect( close ).toHaveAccessibleDescription(
		'Verified UPayments confirmed the pay link.'
	);
	// The confirm and cancel actions are gone: no second run is possible.
	expect(
		within( dialog() ).queryByRole( 'button', {
			name: 'Refund 12.500 KWD',
		} )
	).toBeNull();
	expect(
		within( dialog() ).queryByRole( 'button', { name: 'Cancel' } )
	).toBeNull();
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );
} );

test( 'a rejected confirmation shows a failed result and stays open', async () => {
	const onOpenChange = jest.fn();
	renderConfirm( {
		onConfirm: () => Promise.reject( new Error( 'HTTP 502' ) ),
		onOpenChange,
	} );
	await userEvent.click( confirmBtn() );
	expect( await screen.findByText( GENERIC_FAILURE ) ).toBeVisible();
	const pill = within( dialog() ).getByText( 'Not completed' );
	expect( pill.closest( '.sup-status-pill' ) ).toHaveClass(
		'sup-status-pill--danger'
	);
	// The raw error never reaches the merchant.
	expect( screen.queryByText( /HTTP 502/ ) ).toBeNull();
	expect( dialog() ).toBeInTheDocument();
	expect( onOpenChange ).not.toHaveBeenCalled();
} );

test( 'a synchronous throw becomes the failed result', async () => {
	renderConfirm( {
		onConfirm: () => {
			throw new Error( 'boom' );
		},
	} );
	await userEvent.click( confirmBtn() );
	expect( await screen.findByText( GENERIC_FAILURE ) ).toBeVisible();
	expect( within( dialog() ).getByText( 'Not completed' ) ).toBeVisible();
} );

test( 'a malformed result fails closed and never reads as verified', async () => {
	renderConfirm( {
		onConfirm: () =>
			Promise.resolve( {
				status: 'paid',
				message: 'Captured',
			} as unknown as ConfirmResult ),
	} );
	await userEvent.click( confirmBtn() );
	expect( await screen.findByText( GENERIC_FAILURE ) ).toBeVisible();
	expect( within( dialog() ).queryByText( 'Verified' ) ).toBeNull();
	expect( screen.queryByText( 'Captured' ) ).toBeNull();
} );

test( 'a pending result shows a warning pill and the caller message', async () => {
	renderConfirm( {
		onConfirm: async () => ( {
			status: 'pending',
			message: 'UPayments has not confirmed the refund yet.',
		} ),
	} );
	await userEvent.click( confirmBtn() );
	expect(
		await screen.findByText( 'UPayments has not confirmed the refund yet.' )
	).toBeVisible();
	const pill = within( dialog() ).getByText( 'Awaiting confirmation' );
	expect( pill.closest( '.sup-status-pill' ) ).toHaveClass(
		'sup-status-pill--warning'
	);
} );

test( 'Close ends the opening and a new opening may confirm again', async () => {
	const onConfirm = jest.fn( async (): Promise< ConfirmResult > => ( {
		status: 'failed',
		message: 'UPayments declined the refund.',
	} ) );
	renderConfirm( { onConfirm } );
	await userEvent.click( confirmBtn() );
	await userEvent.click(
		await screen.findByRole( 'button', { name: 'Close' } )
	);
	await waitFor( () =>
		expect( screen.queryByRole( 'alertdialog' ) ).toBeNull()
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Refund order' } )
	);
	await userEvent.click( confirmBtn() );
	expect( onConfirm ).toHaveBeenCalledTimes( 2 );
} );

interface ControlledHandle {
	onConfirm: jest.Mock< Promise< ConfirmResult >, [] >;
	onOpenChange: jest.Mock;
}

function renderControlled( first: Promise< ConfirmResult > ): ControlledHandle {
	const onConfirm = jest
		.fn< Promise< ConfirmResult >, [] >()
		.mockReturnValueOnce( first )
		.mockReturnValue( new Promise< ConfirmResult >( () => {} ) );
	const onOpenChange = jest.fn();
	function Controlled() {
		const [ open, setOpen ] = useState( true );
		return (
			<>
				<Button onClick={ () => setOpen( true ) }>Reopen</Button>
				<Button onClick={ () => setOpen( false ) }>Force close</Button>
				<ConfirmDialog
					open={ open }
					onOpenChange={ onOpenChange }
					title="Capture 4.000 KWD?"
					consequence="UPayments captures the authorized amount."
					confirmLabel="Capture"
					tone="money"
					onConfirm={ onConfirm }
				/>
			</>
		);
	}
	render(
		<SupRoot>
			<Controlled />
		</SupRoot>
	);
	return { onConfirm, onOpenChange };
}

async function forceCloseAndReopen() {
	// The screen closes the dialog itself while the request is in flight…
	act( () => {
		screen
			.getByRole( 'button', { name: 'Force close', hidden: true } )
			.click();
	} );
	await waitFor( () =>
		expect( screen.queryByRole( 'alertdialog' ) ).toBeNull()
	);
	// …and opens it again.
	await userEvent.click( screen.getByRole( 'button', { name: 'Reopen' } ) );
	await screen.findByRole( 'alertdialog' );
}

test( 'closing and reopening while a request is in flight never re-arms onConfirm', async () => {
	const first = deferred< ConfirmResult >();
	const { onConfirm, onOpenChange } = renderControlled( first.promise );
	await userEvent.click( confirmBtn( 'Capture' ) );
	await forceCloseAndReopen();

	// The new opening shows the request that is still in flight.
	const confirm = confirmBtn( 'Capture' );
	expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );
	expect( cancelBtn() ).toHaveAttribute( 'aria-disabled', 'true' );
	await userEvent.dblClick( confirm );
	confirm.focus();
	await userEvent.keyboard( '{Enter>3}{/Enter}' );
	await userEvent.keyboard( '{Escape}' );
	await userEvent.click( cancelBtn() );
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );

	// That request's result appears when it settles.
	await act( async () =>
		first.resolve( {
			status: 'verified',
			message: 'UPayments confirmed the capture.',
		} )
	);
	expect(
		screen.getByText( 'UPayments confirmed the capture.' )
	).toBeVisible();
	await waitFor( () =>
		expect( screen.getByRole( 'button', { name: 'Close' } ) ).toHaveFocus()
	);
	expect( onConfirm ).toHaveBeenCalledTimes( 1 );
} );

test( 'a result that settles while the dialog is closed never swaps the panel or moves focus', async () => {
	const first = deferred< ConfirmResult >();
	const { onConfirm } = renderControlled( first.promise );
	await userEvent.click( confirmBtn( 'Capture' ) );
	act( () => {
		screen
			.getByRole( 'button', { name: 'Force close', hidden: true } )
			.click();
	} );
	await waitFor( () =>
		expect( screen.queryByRole( 'alertdialog' ) ).toBeNull()
	);
	const reopen = screen.getByRole( 'button', { name: 'Reopen' } );
	reopen.focus();
	await act( async () =>
		first.resolve( { status: 'verified', message: 'Late result.' } )
	);
	expect( screen.queryByText( 'Late result.' ) ).toBeNull();
	expect( reopen ).toHaveFocus();

	// Once nothing is in flight, a new opening starts fresh.
	await userEvent.click( reopen );
	await screen.findByRole( 'alertdialog' );
	expect( screen.queryByText( 'Late result.' ) ).toBeNull();
	expect( confirmBtn( 'Capture' ) ).not.toHaveAttribute( 'aria-disabled' );
	await userEvent.click( confirmBtn( 'Capture' ) );
	expect( onConfirm ).toHaveBeenCalledTimes( 2 );
} );

test( 'renderResult replaces the default result body', async () => {
	renderConfirm( {
		onConfirm: async () => ( {
			status: 'verified',
			message: 'Refunded.',
		} ),
		renderResult: ( result ) => (
			<p data-testid="custom">{ `${ result.status }: ${ result.message }` }</p>
		),
	} );
	await userEvent.click( confirmBtn() );
	expect( await screen.findByTestId( 'custom' ) ).toHaveTextContent(
		'verified: Refunded.'
	);
} );

test( 'danger and neutral tones style the confirm action', () => {
	renderConfirm( { tone: 'danger', confirmLabel: 'Disconnect' } );
	expect( confirmBtn( 'Disconnect' ) ).toHaveClass( 'sup-button--danger' );
	expect( dialog() ).toHaveClass( 'sup-confirm--danger' );
} );

test( 'Cancel closes an idle confirmation and returns focus to the trigger', async () => {
	const onOpenChange = jest.fn();
	const onConfirm = jest.fn();
	render(
		<SupRoot>
			<ClosedHost onOpenChange={ onOpenChange } onConfirm={ onConfirm } />
		</SupRoot>
	);
	const trigger = screen.getByRole( 'button', { name: 'Refund order' } );
	await userEvent.click( trigger );
	await waitFor( () => expect( cancelBtn() ).toHaveFocus() );
	await userEvent.click( cancelBtn() );
	expect( onOpenChange ).toHaveBeenLastCalledWith( false );
	await waitFor( () => expect( trigger ).toHaveFocus() );
	expect( onConfirm ).not.toHaveBeenCalled();
} );

function ClosedHost( {
	onOpenChange,
	onConfirm,
}: {
	onOpenChange: ( open: boolean ) => void;
	onConfirm: () => Promise< ConfirmResult >;
} ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<Button onClick={ () => setOpen( true ) }>Refund order</Button>
			<ConfirmDialog
				open={ open }
				onOpenChange={ ( next ) => {
					onOpenChange( next );
					setOpen( next );
				} }
				title="Refund 12.500 KWD?"
				consequence="This cannot be undone."
				confirmLabel="Refund 12.500 KWD"
				cancelLabel="Cancel"
				tone="money"
				onConfirm={ onConfirm }
			/>
		</>
	);
}
