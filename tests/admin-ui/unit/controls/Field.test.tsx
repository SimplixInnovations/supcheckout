/**
 * Field and the text and select controls: label, description and error wiring
 * (SP1 Task 5).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from '@wordpress/element';

import {
	Field,
	Select,
	TextInput,
	Textarea,
} from '../../../../assets/admin/src/ui';

test( 'Field wires label, description and error to its control', () => {
	render(
		<Field
			label="API key"
			description="From your UPayments dashboard"
			error="Key not accepted"
		>
			{ ( c ) => <TextInput { ...c } /> }
		</Field>
	);
	const input = screen.getByLabelText( 'API key' );
	expect( input ).toHaveAccessibleDescription(
		'From your UPayments dashboard Key not accepted'
	);
	expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
} );

test( 'Field without description or error adds no description and no invalid state', () => {
	render(
		<Field label="Store name">{ ( c ) => <TextInput { ...c } /> }</Field>
	);
	const input = screen.getByRole( 'textbox', { name: 'Store name' } );
	expect( input ).not.toHaveAttribute( 'aria-describedby' );
	expect( input ).not.toHaveAttribute( 'aria-invalid' );
	expect( input ).not.toBeRequired();
} );

test( 'Field shows "(required)" as visible text and marks the control required', () => {
	render(
		<Field label="Merchant ID" required>
			{ ( c ) => <TextInput { ...c } /> }
		</Field>
	);
	expect( screen.getByText( '(required)' ) ).toBeVisible();
	const input = screen.getByRole( 'textbox', {
		name: 'Merchant ID (required)',
	} );
	expect( input ).toBeRequired();
} );

test( 'Field error carries an icon and text, never color alone', () => {
	render(
		<Field label="API key" error="Key not accepted">
			{ ( c ) => <TextInput { ...c } /> }
		</Field>
	);
	const error = screen.getByText( 'Key not accepted' ).closest( 'div' );
	expect( error ).toHaveClass( 'sup-field__error' );
	expect( error?.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	expect( screen.getByRole( 'textbox' ) ).toHaveAccessibleDescription(
		'Key not accepted'
	);
} );

test( 'two fields get distinct control ids', () => {
	render(
		<>
			<Field label="First">{ ( c ) => <TextInput { ...c } /> }</Field>
			<Field label="Second">{ ( c ) => <TextInput { ...c } /> }</Field>
		</>
	);
	const first = screen.getByRole( 'textbox', { name: 'First' } );
	const second = screen.getByRole( 'textbox', { name: 'Second' } );
	expect( first.id ).not.toBe( '' );
	expect( first.id ).not.toBe( second.id );
} );

test( 'TextInput defaults to type text, forwards its ref and marks invalid', async () => {
	const ref = createRef< HTMLInputElement >();
	const onChange = jest.fn();
	render(
		<TextInput
			ref={ ref }
			aria-label="Store name"
			invalid
			onChange={ onChange }
		/>
	);
	const input = screen.getByRole( 'textbox', { name: 'Store name' } );
	expect( ref.current ).toBe( input );
	expect( input ).toHaveAttribute( 'type', 'text' );
	expect( input ).toHaveAttribute( 'aria-invalid', 'true' );
	expect( input ).toHaveClass( 'sup-input' );
	await userEvent.type( input, 'Shop' );
	expect( onChange ).toHaveBeenCalledTimes( 4 );
} );

test( 'Textarea is a multi-line text control with the same invalid state', () => {
	const ref = createRef< HTMLTextAreaElement >();
	render(
		<Field label="Notes" error="Too long">
			{ ( c ) => <Textarea ref={ ref } { ...c } /> }
		</Field>
	);
	const textarea = screen.getByRole( 'textbox', { name: 'Notes' } );
	expect( textarea.tagName ).toBe( 'TEXTAREA' );
	expect( ref.current ).toBe( textarea );
	expect( textarea ).toHaveAttribute( 'aria-invalid', 'true' );
	expect( textarea ).toHaveClass( 'sup-input', 'sup-textarea' );
} );

test( 'Select is a native select with options, a disabled option and a decorative chevron', async () => {
	const ref = createRef< HTMLSelectElement >();
	const onChange = jest.fn();
	render(
		<Field label="Environment">
			{ ( c ) => (
				<Select
					ref={ ref }
					{ ...c }
					defaultValue="sandbox"
					onChange={ ( event ) => onChange( event.target.value ) }
					options={ [
						{ value: 'sandbox', label: 'Sandbox' },
						{ value: 'live', label: 'Live' },
						{ value: 'legacy', label: 'Legacy', disabled: true },
					] }
				/>
			) }
		</Field>
	);
	const select = screen.getByRole( 'combobox', { name: 'Environment' } );
	expect( ref.current ).toBe( select );
	expect( select.tagName ).toBe( 'SELECT' );
	expect( select ).toHaveValue( 'sandbox' );
	expect( screen.getByRole( 'option', { name: 'Legacy' } ) ).toBeDisabled();
	await userEvent.selectOptions( select, 'live' );
	expect( onChange ).toHaveBeenCalledWith( 'live' );
	const chevron = select.parentElement?.querySelector( 'svg' );
	expect( chevron ).toHaveAttribute( 'aria-hidden', 'true' );
} );

test( 'Select marks invalid', () => {
	render(
		<Select
			aria-label="Currency"
			invalid
			options={ [ { value: 'kwd', label: 'KWD' } ] }
		/>
	);
	expect(
		screen.getByRole( 'combobox', { name: 'Currency' } )
	).toHaveAttribute( 'aria-invalid', 'true' );
} );
