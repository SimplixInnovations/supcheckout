/**
 * Tabs: a labelled tablist on Base UI Tabs with roving focus; arrow keys
 * follow the reading direction (SP1 Task 7).
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SupRoot, Tabs } from '../../../../assets/admin/src/ui';
import type { TabsProps } from '../../../../assets/admin/src/ui';
import { mockIsRTL } from '../support/render';

const TABS: TabsProps[ 'tabs' ] = [
	{ id: 'general', label: 'General', panel: <p>General settings</p> },
	{
		id: 'payments',
		label: 'Payment methods',
		badge: '3',
		panel: <p>Payment method settings</p>,
	},
	{ id: 'advanced', label: 'Advanced', panel: <p>Advanced settings</p> },
];

function renderTabs( props: Partial< TabsProps > = {} ) {
	return render(
		<SupRoot>
			<Tabs label="Settings sections" tabs={ TABS } { ...props } />
		</SupRoot>
	);
}

test( 'Tabs render a labelled tablist with the first tab selected', () => {
	renderTabs();
	expect(
		screen.getByRole( 'tablist', { name: 'Settings sections' } )
	).toBeInTheDocument();
	const tabs = screen.getAllByRole( 'tab' );
	expect( tabs ).toHaveLength( 3 );
	expect( tabs[ 0 ] ).toHaveAttribute( 'aria-selected', 'true' );
	expect( tabs[ 1 ] ).toHaveAccessibleName( 'Payment methods 3' );
	const panel = screen.getByRole( 'tabpanel' );
	expect( panel ).toHaveTextContent( 'General settings' );
	expect( panel ).toHaveAccessibleName( 'General' );
} );

test( 'clicking a tab selects it and reports the id', async () => {
	const onSelect = jest.fn();
	renderTabs( { onSelect } );
	await userEvent.click( screen.getByRole( 'tab', { name: 'Advanced' } ) );
	expect( onSelect ).toHaveBeenCalledWith( 'advanced' );
	expect( screen.getByRole( 'tab', { name: 'Advanced' } ) ).toHaveAttribute(
		'aria-selected',
		'true'
	);
	expect( screen.getByRole( 'tabpanel' ) ).toHaveTextContent(
		'Advanced settings'
	);
} );

test( 'a controlled selectedId wins until the caller changes it', async () => {
	const onSelect = jest.fn();
	renderTabs( { selectedId: 'payments', onSelect } );
	expect(
		screen.getByRole( 'tab', { name: 'Payment methods 3' } )
	).toHaveAttribute( 'aria-selected', 'true' );
	await userEvent.click( screen.getByRole( 'tab', { name: 'General' } ) );
	expect( onSelect ).toHaveBeenCalledWith( 'general' );
	expect(
		screen.getByRole( 'tab', { name: 'Payment methods 3' } )
	).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'arrow keys move and select in LTR with automatic activation', async () => {
	renderTabs();
	await userEvent.tab();
	expect( screen.getByRole( 'tab', { name: 'General' } ) ).toHaveFocus();
	await userEvent.keyboard( '{ArrowRight}' );
	const next = screen.getByRole( 'tab', { name: 'Payment methods 3' } );
	expect( next ).toHaveFocus();
	expect( next ).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'tab arrow keys are mirrored in RTL', async () => {
	mockIsRTL( true );
	renderTabs();
	await userEvent.tab();
	await userEvent.keyboard( '{ArrowLeft}' );
	const next = screen.getByRole( 'tab', { name: 'Payment methods 3' } );
	expect( next ).toHaveFocus();
	expect( next ).toHaveAttribute( 'aria-selected', 'true' );
} );

test( 'manual activation moves focus with arrows and selects on Enter', async () => {
	renderTabs( { activation: 'manual' } );
	await userEvent.tab();
	await userEvent.keyboard( '{ArrowRight}' );
	const next = screen.getByRole( 'tab', { name: 'Payment methods 3' } );
	expect( next ).toHaveFocus();
	expect( next ).toHaveAttribute( 'aria-selected', 'false' );
	await userEvent.keyboard( '{Enter}' );
	expect( next ).toHaveAttribute( 'aria-selected', 'true' );
} );
