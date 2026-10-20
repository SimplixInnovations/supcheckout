/**
 * Choice controls: Checkbox, RadioGroup, Toggle and ThemeToggle (SP1 Task 5).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef, useState } from '@wordpress/element';

import {
	Checkbox,
	RadioGroup,
	SupRoot,
	ThemeToggle,
	Toggle,
} from '../../../../assets/admin/src/ui';
import type { RadioGroupProps } from '../../../../assets/admin/src/ui';
import { mockMatchMedia } from '../support/render';

beforeEach( () => {
	mockMatchMedia( false );
} );

test( 'Checkbox supports indeterminate', () => {
	const { rerender } = render(
		<Checkbox label="Select all orders" indeterminate readOnly />
	);
	const box = screen.getByRole( 'checkbox', {
		name: 'Select all orders',
	} ) as HTMLInputElement;
	expect( box.indeterminate ).toBe( true );
	expect( box ).toBePartiallyChecked();
	rerender(
		<Checkbox label="Select all orders" indeterminate={ false } readOnly />
	);
	expect( box.indeterminate ).toBe( false );
	expect( box ).not.toBePartiallyChecked();
} );

test( 'a controlled indeterminate checkbox stays mixed after a click the parent ignores', async () => {
	const onChange = jest.fn();
	render(
		<Checkbox
			label="All"
			indeterminate
			checked={ false }
			onChange={ onChange }
		/>
	);
	const box = screen.getByRole( 'checkbox', { name: 'All' } );
	await userEvent.click( box );
	expect( onChange ).toHaveBeenCalledTimes( 1 );
	// The browser clears `indeterminate` on activation; the prop is the truth.
	expect( box ).toBePartiallyChecked();
	expect( box ).not.toBeChecked();
} );

test( 'a select-all checkbox follows its parent from mixed to checked and back', async () => {
	function SelectAll() {
		const [ selected, setSelected ] = useState( 1 );
		const total = 3;
		return (
			<Checkbox
				label="Select all orders"
				checked={ selected === total }
				indeterminate={ selected > 0 && selected < total }
				onChange={ ( event ) =>
					setSelected( event.target.checked ? total : 0 )
				}
			/>
		);
	}
	render( <SelectAll /> );
	const box = screen.getByRole( 'checkbox', { name: 'Select all orders' } );
	expect( box ).toBePartiallyChecked();
	await userEvent.click( box );
	expect( box ).toBeChecked();
	expect( box ).not.toBePartiallyChecked();
	await userEvent.click( box );
	expect( box ).not.toBeChecked();
	expect( box ).not.toBePartiallyChecked();
} );

test( 'an uncontrolled indeterminate checkbox keeps the mixed state its prop sets', async () => {
	render( <Checkbox label="Select all orders" indeterminate /> );
	const box = screen.getByRole( 'checkbox', { name: 'Select all orders' } );
	await userEvent.click( box );
	expect( box ).toBePartiallyChecked();
} );

test( 'Checkbox is labelled, described, toggles and forwards its ref', async () => {
	const ref = createRef< HTMLInputElement >();
	const onChange = jest.fn();
	render(
		<Checkbox
			ref={ ref }
			label="Save cards for returning customers"
			description="Customers can pay with a saved card next time."
			onChange={ ( event ) => onChange( event.target.checked ) }
		/>
	);
	const box = screen.getByRole( 'checkbox', {
		name: 'Save cards for returning customers',
	} );
	expect( ref.current ).toBe( box );
	expect( box ).toHaveAccessibleDescription(
		'Customers can pay with a saved card next time.'
	);
	expect( box ).not.toBeChecked();
	await userEvent.click(
		screen.getByText( 'Save cards for returning customers' )
	);
	expect( onChange ).toHaveBeenCalledWith( true );
	expect( box ).toBeChecked();
	// The check mark is drawn by a decorative sibling, never by the input.
	expect(
		box.parentElement?.querySelector( '.sup-checkbox__indicator' )
	).toHaveAttribute( 'aria-hidden', 'true' );
} );

function StatefulRadioGroup(
	props: Omit< RadioGroupProps, 'value' | 'onChange' > & {
		initial: string;
		onValue?: ( value: string ) => void;
	}
) {
	const { initial, onValue, ...rest } = props;
	const [ value, setValue ] = useState( initial );
	return (
		<RadioGroup
			{ ...rest }
			value={ value }
			onChange={ ( next ) => {
				setValue( next );
				onValue?.( next );
			} }
		/>
	);
}

const MODES = [
	{ value: 'redirect', label: 'Redirect to UPayments' },
	{
		value: 'embedded',
		label: 'Embedded card form',
		description: 'Customers stay on your checkout.',
	},
	{ value: 'link', label: 'Payment link' },
];

test( 'RadioGroup is a fieldset with a legend and arrow keys move selection', async () => {
	const onValue = jest.fn();
	render(
		<StatefulRadioGroup
			legend="Checkout mode"
			name="checkout-mode"
			options={ MODES }
			initial="redirect"
			onValue={ onValue }
		/>
	);
	const group = screen.getByRole( 'group', { name: 'Checkout mode' } );
	expect( group.tagName ).toBe( 'FIELDSET' );
	const first = screen.getByRole( 'radio', {
		name: 'Redirect to UPayments',
	} );
	expect( first ).toBeChecked();
	expect( first ).toHaveAttribute( 'name', 'checkout-mode' );
	first.focus();
	await userEvent.keyboard( '{ArrowDown}' );
	const second = screen.getByRole( 'radio', { name: 'Embedded card form' } );
	expect( onValue ).toHaveBeenLastCalledWith( 'embedded' );
	expect( second ).toBeChecked();
	expect( second ).toHaveFocus();
	expect( second ).toHaveAccessibleDescription(
		'Customers stay on your checkout.'
	);
	expect( first ).not.toBeChecked();
} );

test( 'RadioGroup reflects its controlled value and the segmented variant', async () => {
	const onChange = jest.fn();
	const { container } = render(
		<RadioGroup
			legend="Density"
			name="density"
			variant="segmented"
			value="compact"
			onChange={ onChange }
			options={ [
				{ value: 'comfortable', label: 'Comfortable' },
				{ value: 'compact', label: 'Compact' },
			] }
		/>
	);
	expect( screen.getByRole( 'radio', { name: 'Compact' } ) ).toBeChecked();
	expect( container.querySelector( 'fieldset' ) ).toHaveClass(
		'sup-radio-group',
		'sup-radio-group--segmented'
	);
	await userEvent.click( screen.getByText( 'Comfortable' ) );
	expect( onChange ).toHaveBeenCalledWith( 'comfortable' );
	// Controlled: the parent did not accept the change, so it stays.
	expect( screen.getByRole( 'radio', { name: 'Compact' } ) ).toBeChecked();
} );

test( 'RadioGroup defaults to the list variant', () => {
	const { container } = render(
		<RadioGroup
			legend="Checkout mode"
			name="mode"
			value="redirect"
			onChange={ jest.fn() }
			options={ MODES }
		/>
	);
	expect( container.querySelector( 'fieldset' ) ).toHaveClass(
		'sup-radio-group--list'
	);
} );

test( 'Toggle exposes the switch role and its state', async () => {
	function StatefulToggle() {
		const [ checked, setChecked ] = useState( false );
		return (
			<Toggle
				label="Enable test mode"
				description="Payments go to the UPayments sandbox."
				checked={ checked }
				onChange={ setChecked }
			/>
		);
	}
	render( <StatefulToggle /> );
	const toggle = screen.getByRole( 'switch', { name: 'Enable test mode' } );
	expect( toggle ).toHaveAttribute( 'type', 'checkbox' );
	expect( toggle ).not.toBeChecked();
	expect( toggle ).toHaveAccessibleDescription(
		'Payments go to the UPayments sandbox.'
	);
	await userEvent.click( toggle );
	expect( toggle ).toBeChecked();
	toggle.focus();
	await userEvent.keyboard( ' ' );
	expect( toggle ).not.toBeChecked();
} );

test( 'Toggle reports the next state and stays controlled', async () => {
	const onChange = jest.fn();
	render( <Toggle label="Enable test mode" checked onChange={ onChange } /> );
	const toggle = screen.getByRole( 'switch', { name: 'Enable test mode' } );
	expect( toggle ).toBeChecked();
	await userEvent.click( toggle );
	expect( onChange ).toHaveBeenCalledWith( false );
	expect( toggle ).toBeChecked();
} );

test( 'ThemeToggle sets the preference', async () => {
	const onPreferenceChange = jest.fn();
	render(
		<SupRoot onPreferenceChange={ onPreferenceChange }>
			<ThemeToggle />
		</SupRoot>
	);
	const group = screen.getByRole( 'group', { name: 'Appearance' } );
	expect( group.tagName ).toBe( 'FIELDSET' );
	expect(
		screen.getByRole( 'radio', { name: 'Match system' } )
	).toBeChecked();
	await userEvent.click( screen.getByRole( 'radio', { name: 'Dark' } ) );
	expect( onPreferenceChange ).toHaveBeenCalledWith( 'dark' );
	expect( screen.getByRole( 'radio', { name: 'Dark' } ) ).toBeChecked();
	expect( document.querySelector( '.supcheckout-admin' ) ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);
	await userEvent.keyboard( '{ArrowLeft}' );
	expect( onPreferenceChange ).toHaveBeenLastCalledWith( 'light' );
} );

test( 'ThemeToggle reflects a controlled preference', () => {
	render(
		<SupRoot preference="light">
			<ThemeToggle />
		</SupRoot>
	);
	expect( screen.getByRole( 'radio', { name: 'Light' } ) ).toBeChecked();
	expect( screen.getByRole( 'group', { name: 'Appearance' } ) ).toHaveClass(
		'sup-radio-group--segmented'
	);
} );
