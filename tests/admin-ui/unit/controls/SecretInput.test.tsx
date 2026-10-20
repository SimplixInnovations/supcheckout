/**
 * SecretInput: write-only by type and at runtime. A saved secret never reaches
 * the DOM and a typed one never reaches an attribute (SP1 Task 5, spec §6).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SecretInput } from '../../../../assets/admin/src/ui';

const SAVED_COPY = 'A key is saved. For security it is never shown.';
const SENTINEL = 'sk_test_SENTINEL_9f3c2a';

test( 'SecretInput never renders a saved secret and replacing starts empty', async () => {
	const onChange = jest.fn();
	const { container } = render(
		<SecretInput label="API key" hasSavedValue onChange={ onChange } />
	);
	expect( screen.getByText( SAVED_COPY ) ).toBeVisible();
	expect( container.querySelector( 'input, textarea' ) ).toBeNull();
	expect( screen.queryByRole( 'textbox' ) ).toBeNull();
	expect(
		screen.getByRole( 'group', { name: 'API key' } )
	).toBeInTheDocument();

	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	const input = screen.getByLabelText( 'API key' ) as HTMLInputElement;
	expect( input ).toHaveAttribute( 'type', 'password' );
	expect( input.value ).toBe( '' );
	expect( input ).toHaveAttribute( 'autocomplete', 'new-password' );
	expect( input ).toHaveAttribute( 'spellcheck', 'false' );
	expect( input ).not.toHaveAttribute( 'value' );
	// The button that started the replacement is gone; focus moves to the input.
	expect( input ).toHaveFocus();
	expect( onChange ).not.toHaveBeenCalled();
} );

test( 'a typed secret reaches onChange but never the DOM markup', async () => {
	const onChange = jest.fn();
	const { container } = render(
		<SecretInput
			label="API key"
			hasSavedValue={ false }
			onChange={ onChange }
		/>
	);
	const input = screen.getByLabelText( 'API key' ) as HTMLInputElement;
	await userEvent.type( input, SENTINEL );
	expect( onChange ).toHaveBeenLastCalledWith( SENTINEL );
	expect( onChange ).toHaveBeenCalledTimes( SENTINEL.length );
	// Uncontrolled: the value lives in the DOM property only, never in an
	// attribute, so it is absent from the markup.
	expect( container.innerHTML ).not.toContain( SENTINEL );
	expect( document.body.innerHTML ).not.toContain( SENTINEL );
	// No partial secret from the onChange history either (every prefix long
	// enough to be distinctive).
	const history = onChange.mock.calls
		.map( ( [ value ] ) => value as string )
		.filter( ( value ) => value.length >= 'sk_test_S'.length );
	expect( history.length ).toBeGreaterThan( 0 );
	for ( const value of history ) {
		expect( container.innerHTML ).not.toContain( value );
	}
	expect( input ).not.toHaveAttribute( 'value' );
} );

test( 'a re-render with the typed value in the parent still keeps it out of the markup', async () => {
	let latest = '';
	const { container, rerender } = render(
		<SecretInput
			label="API key"
			hasSavedValue={ false }
			onChange={ ( value ) => {
				latest = value;
			} }
		/>
	);
	await userEvent.type( screen.getByLabelText( 'API key' ), SENTINEL );
	rerender(
		<SecretInput
			label="API key"
			description={ `Characters typed: ${ latest.length }` }
			hasSavedValue={ false }
			onChange={ () => undefined }
		/>
	);
	expect( latest ).toBe( SENTINEL );
	expect( container.innerHTML ).not.toContain( SENTINEL );
} );

test( 'cancelling a replacement restores the saved state, clears the draft and returns focus', async () => {
	const onChange = jest.fn();
	const { container } = render(
		<SecretInput
			label="API key"
			hasSavedValue
			description="From your UPayments dashboard"
			onChange={ onChange }
		/>
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	await userEvent.type( screen.getByLabelText( 'API key' ), SENTINEL );
	await userEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
	expect( onChange ).toHaveBeenLastCalledWith( '' );
	expect( container.querySelector( 'input' ) ).toBeNull();
	expect( screen.getByText( SAVED_COPY ) ).toBeVisible();
	expect(
		screen.getByRole( 'button', { name: 'Replace key' } )
	).toHaveFocus();
	expect( container.innerHTML ).not.toContain( SENTINEL );
} );

test( 'without a saved value the empty password input shows at once with its description', () => {
	render(
		<SecretInput
			label="API key"
			hasSavedValue={ false }
			description="From your UPayments dashboard"
			onChange={ jest.fn() }
		/>
	);
	const input = screen.getByLabelText( 'API key' );
	expect( input ).toHaveAttribute( 'type', 'password' );
	expect( input ).toHaveAccessibleDescription(
		'From your UPayments dashboard'
	);
	expect( screen.queryByText( SAVED_COPY ) ).toBeNull();
	expect(
		screen.queryByRole( 'button', { name: 'Replace key' } )
	).toBeNull();
	expect( screen.queryByRole( 'button', { name: 'Cancel' } ) ).toBeNull();
} );

test( 'the saved state describes the replace button with the saved copy', () => {
	render(
		<SecretInput label="API key" hasSavedValue onChange={ jest.fn() } />
	);
	expect(
		screen.getByRole( 'button', { name: 'Replace key' } )
	).toHaveAccessibleDescription( SAVED_COPY );
} );

test( 'an error is announced as the input description and marks it invalid', () => {
	render(
		<SecretInput
			label="API key"
			hasSavedValue={ false }
			error="Key not accepted"
			onChange={ jest.fn() }
		/>
	);
	const input = screen.getByLabelText( 'API key' );
	expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
	expect( input ).toHaveAccessibleDescription( 'Key not accepted' );
} );

test( 'a value prop smuggled past the type is ignored at runtime', () => {
	const props = {
		label: 'API key',
		hasSavedValue: false,
		onChange: jest.fn(),
		value: SENTINEL,
		defaultValue: SENTINEL,
	} as unknown as Parameters< typeof SecretInput >[ 0 ];
	const { container } = render( <SecretInput { ...props } /> );
	expect(
		( screen.getByLabelText( 'API key' ) as HTMLInputElement ).value
	).toBe( '' );
	expect( container.innerHTML ).not.toContain( SENTINEL );
} );
