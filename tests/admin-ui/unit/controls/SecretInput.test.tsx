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

const STAYS_COPY = 'The saved key stays in use until you save the new one.';

test( 'while replacing, the input says the saved key stays in use', async () => {
	render(
		<SecretInput
			label="API key"
			hasSavedValue
			description="From your UPayments dashboard"
			onChange={ jest.fn() }
		/>
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	expect( screen.getByText( STAYS_COPY ) ).toBeVisible();
	expect( screen.getByLabelText( 'API key' ) ).toHaveAccessibleDescription(
		`From your UPayments dashboard ${ STAYS_COPY }`
	);
} );

test( 'with nothing saved there is no saved key to keep in use', () => {
	render(
		<SecretInput
			label="API key"
			hasSavedValue={ false }
			onChange={ jest.fn() }
		/>
	);
	expect( screen.queryByText( STAYS_COPY ) ).toBeNull();
} );

test( 'a new savedRevision returns to the saved state, clears the draft and moves focus to Replace key', async () => {
	const onChange = jest.fn();
	const { container, rerender } = render(
		<SecretInput
			label="API key"
			hasSavedValue
			savedRevision={ 1 }
			onChange={ onChange }
		/>
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	await userEvent.type( screen.getByLabelText( 'API key' ), SENTINEL );
	expect( screen.getByLabelText( 'API key' ) ).toHaveFocus();

	// The screen saved the replacement and bumps the revision.
	rerender(
		<SecretInput
			label="API key"
			hasSavedValue
			savedRevision={ 2 }
			onChange={ onChange }
		/>
	);
	expect( container.querySelector( 'input' ) ).toBeNull();
	expect( screen.getByText( SAVED_COPY ) ).toBeVisible();
	expect( onChange ).toHaveBeenLastCalledWith( '' );
	expect(
		screen.getByRole( 'button', { name: 'Replace key' } )
	).toHaveFocus();
	expect( container.innerHTML ).not.toContain( SENTINEL );

	// Replacing again starts from an empty input.
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	expect(
		( screen.getByLabelText( 'API key' ) as HTMLInputElement ).value
	).toBe( '' );
} );

test( 'a new savedRevision does not steal focus from elsewhere on the screen', async () => {
	const { rerender } = render(
		<>
			<SecretInput
				label="API key"
				hasSavedValue
				savedRevision={ 1 }
				onChange={ jest.fn() }
			/>
			<button type="button">Save settings</button>
		</>
	);
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Replace key' } )
	);
	await userEvent.type( screen.getByLabelText( 'API key' ), SENTINEL );
	const save = screen.getByRole( 'button', { name: 'Save settings' } );
	save.focus();
	rerender(
		<>
			<SecretInput
				label="API key"
				hasSavedValue
				savedRevision={ 2 }
				onChange={ jest.fn() }
			/>
			<button type="button">Save settings</button>
		</>
	);
	expect( document.querySelector( 'input[type="password"]' ) ).toBeNull();
	expect( save ).toHaveFocus();
} );

test( 'a new savedRevision while not replacing changes nothing', () => {
	const onChange = jest.fn();
	const { rerender } = render(
		<SecretInput
			label="API key"
			hasSavedValue
			savedRevision={ 1 }
			onChange={ onChange }
		/>
	);
	rerender(
		<SecretInput
			label="API key"
			hasSavedValue
			savedRevision={ 2 }
			onChange={ onChange }
		/>
	);
	expect( onChange ).not.toHaveBeenCalled();
	expect(
		screen.getByRole( 'button', { name: 'Replace key' } )
	).not.toHaveFocus();
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
