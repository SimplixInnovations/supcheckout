/**
 * Dialog: a modal on Base UI Dialog, portaled into the scoped layer, that
 * traps focus, closes on Escape and returns focus to its trigger (SP1
 * Task 7). An open Tooltip inside it closes on Escape first.
 */
import { render, screen, waitFor, within } from '@testing-library/react';
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

test( 'Dialog traps Tab, closes on Escape and returns focus to the trigger', async () => {
	render(
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
	// Tab cycles through the dialog and never reaches the page behind it.
	// (In jsdom focus may rest on Base UI's focus guards, which sit next to
	// the popup in the portal; the browser suite checks the real cycle.)
	const appRoot = trigger.closest( '.supcheckout-admin' ) as HTMLElement;
	const reached = new Set< string >();
	for ( let i = 0; i < 8; i++ ) {
		await userEvent.tab();
		const active = trigger.ownerDocument.activeElement as HTMLElement;
		expect( appRoot.contains( active ) ).toBe( false );
		expect( active.closest( '.sup-portal-layer' ) ).not.toBeNull();
		if ( dialog.contains( active ) ) {
			reached.add(
				active.getAttribute( 'aria-label' ) ?? active.textContent ?? ''
			);
		}
	}
	// Close had the initial focus; Tab then reaches every other control.
	expect( [ ...reached ] ).toEqual(
		expect.arrayContaining( [ 'Cancel', 'Save webhook' ] )
	);

	await userEvent.keyboard( '{Escape}' );
	await waitFor( () => expect( screen.queryByRole( 'dialog' ) ).toBeNull() );
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
