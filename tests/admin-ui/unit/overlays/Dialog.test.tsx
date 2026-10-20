/**
 * Dialog: a modal on Base UI Dialog, portaled into the scoped layer, that
 * traps focus, closes on Escape and returns focus to its trigger (SP1
 * Task 7). An open Tooltip inside it closes on Escape first.
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useRef, useState } from '@wordpress/element';

import {
	Button,
	Dialog,
	SupRoot,
	TextInput,
	Tooltip,
} from '../../../../assets/admin/src/ui';
import type { DialogProps } from '../../../../assets/admin/src/ui';

type Overrides = Partial< Omit< DialogProps, 'open' | 'onOpenChange' > >;

function Host( {
	overrides = {},
	onOpenChange,
	withTooltip = false,
}: {
	overrides?: Overrides;
	onOpenChange?: ( open: boolean ) => void;
	withTooltip?: boolean;
} ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<Button onClick={ () => setOpen( true ) }>Edit webhook</Button>
			<Dialog
				title="Edit webhook"
				description="UPayments sends payment updates to this address."
				footer={
					<>
						<Button onClick={ () => setOpen( false ) }>
							Cancel
						</Button>
						<Button variant="primary">Save webhook</Button>
					</>
				}
				{ ...overrides }
				open={ open }
				onOpenChange={ ( next ) => {
					onOpenChange?.( next );
					setOpen( next );
				} }
			>
				{ withTooltip ? (
					<Tooltip content="Copies the address">
						<Button>Copy address</Button>
					</Tooltip>
				) : (
					<p>Only HTTPS addresses work.</p>
				) }
			</Dialog>
		</>
	);
}

const openDialog = async () => {
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Edit webhook' } )
	);
	return screen.findByRole( 'dialog' );
};

test( 'Dialog moves focus inside, makes the page behind inert, closes on Escape and returns focus to the trigger', async () => {
	const { container } = render(
		<SupRoot>
			<Host />
		</SupRoot>
	);
	const trigger = screen.getByRole( 'button', { name: 'Edit webhook' } );
	const dialog = await openDialog();
	expect( dialog ).toHaveAccessibleName( 'Edit webhook' );
	expect( dialog ).toHaveAccessibleDescription(
		'UPayments sends payment updates to this address.'
	);
	expect( dialog.closest( '.sup-portal-layer' ) ).not.toBeNull();
	expect( dialog ).toHaveClass( 'sup-dialog--md' );

	const close = within( dialog ).getByRole( 'button', {
		name: 'Close dialog',
	} );
	await waitFor( () => expect( close ).toHaveFocus() );
	// Nothing behind the modal can take focus: it is inert, not only
	// aria-hidden (jsdom's Tab cannot model Base UI's focus guards, so the
	// Tab cycle itself is proven in the real-browser suite).
	await waitFor( () =>
		expect( container.closest( '[inert]' ) ).not.toBeNull()
	);
	expect( dialog.closest( '[inert]' ) ).toBeNull();

	await userEvent.keyboard( '{Escape}' );
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
	expect( container.closest( '[inert]' ) ).toBeNull();
	await waitFor( () => expect( trigger ).toHaveFocus() );
} );

test( 'the close button closes a dismissible dialog', async () => {
	const onOpenChange = jest.fn();
	render(
		<SupRoot>
			<Host onOpenChange={ onOpenChange } />
		</SupRoot>
	);
	const dialog = await openDialog();
	await userEvent.click(
		within( dialog ).getByRole( 'button', { name: 'Close dialog' } )
	);
	expect( onOpenChange ).toHaveBeenLastCalledWith( false );
} );

test( 'a dialog that is not dismissible ignores Escape and has no close button', async () => {
	const onOpenChange = jest.fn();
	render(
		<SupRoot>
			<Host
				overrides={ { dismissible: false, size: 'sm' } }
				onOpenChange={ onOpenChange }
			/>
		</SupRoot>
	);
	const dialog = await openDialog();
	expect( dialog ).toHaveClass( 'sup-dialog--sm' );
	expect(
		within( dialog ).queryByRole( 'button', { name: 'Close dialog' } )
	).toBeNull();
	await userEvent.keyboard( '{Escape}' );
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
} );

test( 'initialFocus moves focus to the given element', async () => {
	function FocusHost() {
		const [ open, setOpen ] = useState( false );
		const inputRef = useRef< HTMLInputElement >( null );
		return (
			<>
				<Button onClick={ () => setOpen( true ) }>Rename</Button>
				<Dialog
					open={ open }
					onOpenChange={ setOpen }
					title="Rename the store"
					size="lg"
					initialFocus={ inputRef }
				>
					<Button>Help</Button>
					<TextInput ref={ inputRef } aria-label="Store name" />
				</Dialog>
			</>
		);
	}
	render(
		<SupRoot>
			<FocusHost />
		</SupRoot>
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Rename' } ) );
	const input = await screen.findByRole( 'textbox', { name: 'Store name' } );
	await waitFor( () => expect( input ).toHaveFocus() );
	expect( screen.getByRole( 'dialog' ) ).toHaveClass( 'sup-dialog--lg' );
} );

test( 'Escape closes an open tooltip inside a dialog first and the dialog stays open', async () => {
	const onOpenChange = jest.fn();
	render(
		<SupRoot>
			<Host withTooltip onOpenChange={ onOpenChange } />
		</SupRoot>
	);
	const dialog = await openDialog();
	const copy = within( dialog ).getByRole( 'button', {
		name: 'Copy address',
	} );
	const doc = dialog.ownerDocument;
	await waitFor( () =>
		expect( dialog.contains( doc.activeElement ) ).toBe( true )
	);
	// Reach the trigger with the keyboard so the tooltip opens on focus.
	while ( doc.activeElement !== copy ) {
		await userEvent.tab();
	}
	expect( await screen.findByRole( 'tooltip' ) ).toHaveTextContent(
		'Copies the address'
	);

	await userEvent.keyboard( '{Escape}' );
	await waitFor( () => expect( screen.queryByRole( 'tooltip' ) ).toBeNull() );
	expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	expect( onOpenChange ).not.toHaveBeenCalledWith( false );
	expect( copy ).toHaveFocus();

	await userEvent.keyboard( '{Escape}' );
	expect( onOpenChange ).toHaveBeenLastCalledWith( false );
} );

test( 'the inert background never silences live regions and keeps their siblings inert', async () => {
	// Outside the app, as wp.a11y.speak() and admin notices place them.
	const wrapper = document.createElement( 'div' );
	const speak = document.createElement( 'div' );
	speak.setAttribute( 'aria-live', 'polite' );
	speak.id = 'a11y-speak-polite';
	const status = document.createElement( 'p' );
	status.setAttribute( 'role', 'status' );
	const sibling = document.createElement( 'button' );
	sibling.textContent = 'Outside';
	wrapper.append( speak, sibling );
	document.body.append( wrapper, status );
	try {
		const { container } = render(
			<SupRoot>
				<Host />
			</SupRoot>
		);
		await openDialog();
		await waitFor( () =>
			expect( container.closest( '[inert]' ) ).not.toBeNull()
		);
		for ( const region of [ speak, status ] ) {
			expect( region.closest( '[inert]' ) ).toBeNull();
		}
		// The live region's ancestor is not inert, but its other children are.
		expect( wrapper ).not.toHaveAttribute( 'inert' );
		expect( sibling.closest( '[inert]' ) ).not.toBeNull();
	} finally {
		wrapper.remove();
		status.remove();
	}
} );

test( 'nested dialogs closing out of order never un-inert the page under one still open', async () => {
	function TwoDialogs() {
		const [ first, setFirst ] = useState( true );
		const [ second, setSecond ] = useState( false );
		return (
			<>
				<Dialog open={ first } onOpenChange={ setFirst } title="First">
					<Button onClick={ () => setSecond( true ) }>
						Open second
					</Button>
					<Button onClick={ () => setFirst( false ) }>
						Close first
					</Button>
				</Dialog>
				<Dialog
					open={ second }
					onOpenChange={ setSecond }
					title="Second"
				>
					<Button onClick={ () => setFirst( false ) }>
						Close the first dialog
					</Button>
					<Button onClick={ () => setSecond( false ) }>Done</Button>
				</Dialog>
			</>
		);
	}
	const { container } = render(
		<SupRoot>
			<Button>Page</Button>
			<TwoDialogs />
		</SupRoot>
	);
	await screen.findByRole( 'dialog', { name: 'First' } );
	await waitFor( () =>
		expect( container.closest( '[inert]' ) ).not.toBeNull()
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Open second' } )
	);
	await screen.findByRole( 'dialog', { name: 'Second' } );
	// Wait for the second dialog's own inert frame to have run.
	await act( async () => {
		await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
	} );
	// The first dialog closes first, out of LIFO order.
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Close the first dialog' } )
	);
	await waitFor( () =>
		expect( screen.queryByRole( 'dialog', { name: 'First' } ) ).toBeNull()
	);
	expect( screen.getByRole( 'dialog', { name: 'Second' } ) ).toBeVisible();
	expect( container.closest( '[inert]' ) ).not.toBeNull();

	await userEvent.click( screen.getByRole( 'button', { name: 'Done' } ) );
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
	expect( container.closest( '[inert]' ) ).toBeNull();
} );
