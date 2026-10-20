/**
 * Button and IconButton: native buttons with variants, sizes, icons and a
 * focus-keeping busy state (SP1 Task 5).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from '@wordpress/element';

import { Button, IconButton } from '../../../../assets/admin/src/ui';

test( 'a busy button keeps focus, announces the busy label and ignores clicks', async () => {
	const onClick = jest.fn();
	render(
		<Button busy busyLabel="Saving" onClick={ onClick }>
			Save
		</Button>
	);
	const b = screen.getByRole( 'button', { name: 'Save' } );
	b.focus();
	await userEvent.click( b );
	await userEvent.keyboard( '{Enter}' );
	expect( onClick ).not.toHaveBeenCalled();
	expect( b ).toHaveAttribute( 'aria-disabled', 'true' );
	expect( b ).toHaveFocus();
	expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'Saving' );
} );

test( 'the busy status region is mounted empty before the button turns busy', () => {
	const { rerender } = render(
		<Button busyLabel="Saving" onClick={ jest.fn() }>
			Save
		</Button>
	);
	const status = screen.getByRole( 'status' );
	expect( status ).toHaveTextContent( '' );
	expect( status ).toHaveClass( 'sup-visually-hidden' );
	rerender(
		<Button busy busyLabel="Saving" onClick={ jest.fn() }>
			Save
		</Button>
	);
	// Same live region, now with text: assistive technology announces it.
	expect( screen.getByRole( 'status' ) ).toBe( status );
	expect( status ).toHaveTextContent( 'Saving' );
	// The status sits outside the button, so the name stays the visible label.
	expect( screen.getByRole( 'button' ) ).toHaveAccessibleName( 'Save' );
} );

test( 'a busy submit button does not submit its form', async () => {
	const onSubmit = jest.fn( ( event: { preventDefault: () => void } ) =>
		event.preventDefault()
	);
	render(
		<form onSubmit={ onSubmit }>
			<Button type="submit" busy>
				Save
			</Button>
		</form>
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
	expect( onSubmit ).not.toHaveBeenCalled();
} );

test( 'a busy button shows a decorative spinner in place of its start icon', () => {
	const { rerender } = render( <Button iconStart="refresh">Sync</Button> );
	const button = screen.getByRole( 'button', { name: 'Sync' } );
	expect( button.querySelector( '.sup-spinner' ) ).toBeNull();
	expect( button.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	rerender(
		<Button iconStart="refresh" busy>
			Sync
		</Button>
	);
	const spinner = button.querySelector( '.sup-spinner' );
	expect( spinner ).toHaveAttribute( 'aria-hidden', 'true' );
	expect( button.querySelector( 'svg' ) ).toBeNull();
	expect( button ).toHaveTextContent( 'Sync' );
	expect( button ).toHaveClass( 'sup-button--busy' );
} );

test( 'type defaults to button and stays overridable', () => {
	render(
		<>
			<Button>One</Button>
			<Button type="submit">Two</Button>
		</>
	);
	expect( screen.getByRole( 'button', { name: 'One' } ) ).toHaveAttribute(
		'type',
		'button'
	);
	expect( screen.getByRole( 'button', { name: 'Two' } ) ).toHaveAttribute(
		'type',
		'submit'
	);
} );

test( 'variant and size map to classes; the defaults are secondary and md', () => {
	render(
		<>
			<Button>Default</Button>
			<Button variant="primary" size="sm">
				Primary
			</Button>
			<Button variant="danger">Delete</Button>
		</>
	);
	expect( screen.getByRole( 'button', { name: 'Default' } ) ).toHaveClass(
		'sup-button',
		'sup-button--secondary',
		'sup-button--md'
	);
	expect( screen.getByRole( 'button', { name: 'Primary' } ) ).toHaveClass(
		'sup-button--primary',
		'sup-button--sm'
	);
	expect( screen.getByRole( 'button', { name: 'Delete' } ) ).toHaveClass(
		'sup-button--danger'
	);
} );

test( 'an end icon is decorative and a caller class is kept', () => {
	render(
		<Button iconEnd="external" className="sp2-extra">
			Open dashboard
		</Button>
	);
	const button = screen.getByRole( 'button', { name: 'Open dashboard' } );
	expect( button ).toHaveClass( 'sup-button', 'sp2-extra' );
	const icons = button.querySelectorAll( 'svg' );
	expect( icons ).toHaveLength( 1 );
	expect( icons[ 0 ] ).toHaveAttribute( 'aria-hidden', 'true' );
} );

test( 'a disabled button is natively disabled and not busy', async () => {
	const onClick = jest.fn();
	render(
		<Button disabled onClick={ onClick }>
			Save
		</Button>
	);
	const button = screen.getByRole( 'button', { name: 'Save' } );
	expect( button ).toBeDisabled();
	expect( button ).not.toHaveAttribute( 'aria-disabled' );
	await userEvent.click( button );
	expect( onClick ).not.toHaveBeenCalled();
} );

test( 'a button that is not busy calls onClick', async () => {
	const onClick = jest.fn();
	render( <Button onClick={ onClick }>Save</Button> );
	await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
	expect( onClick ).toHaveBeenCalledTimes( 1 );
} );

test( 'Button and IconButton forward their ref to the native button', () => {
	const buttonRef = createRef< HTMLButtonElement >();
	const iconRef = createRef< HTMLButtonElement >();
	render(
		<>
			<Button ref={ buttonRef }>Save</Button>
			<IconButton ref={ iconRef } icon="close" label="Close" />
		</>
	);
	expect( buttonRef.current ).toBe(
		screen.getByRole( 'button', { name: 'Save' } )
	);
	expect( iconRef.current ).toBe(
		screen.getByRole( 'button', { name: 'Close' } )
	);
} );

test( 'IconButton is named by its label and its icon is decorative', async () => {
	const onClick = jest.fn();
	render( <IconButton icon="close" label="Close" onClick={ onClick } /> );
	const button = screen.getByRole( 'button', { name: 'Close' } );
	expect( button ).toHaveAttribute( 'aria-label', 'Close' );
	expect( button ).toHaveAttribute( 'type', 'button' );
	expect( button ).toHaveClass(
		'sup-button',
		'sup-button--icon-only',
		'sup-button--tertiary',
		'sup-button--md'
	);
	expect( button.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	await userEvent.click( button );
	expect( onClick ).toHaveBeenCalledTimes( 1 );
} );
