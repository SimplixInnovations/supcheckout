/**
 * IconButton's `showTooltip` (SP1 Task 6): the label also shows as a visual
 * tooltip, while the accessible name stays the `aria-label` and no
 * `aria-describedby` repeats it.
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createRef } from '@wordpress/element';
import type { ReactElement } from 'react';

import {
	IconButton,
	SupRoot,
	TOOLTIP_DELAY_MS,
} from '../../../../assets/admin/src/ui';

// Tooltips portal into SupRoot's layer and never fall back to document.body.
const renderInRoot = ( ui: ReactElement ) =>
	render( <SupRoot>{ ui }</SupRoot> );

afterEach( () => {
	jest.useRealTimers();
} );

test( 'IconButton with showTooltip shows its label as a tooltip on focus', async () => {
	renderInRoot( <IconButton icon="close" label="Dismiss" showTooltip /> );
	await userEvent.tab();
	expect( await screen.findByRole( 'tooltip' ) ).toHaveTextContent(
		'Dismiss'
	);
	const button = screen.getByRole( 'button', { name: 'Dismiss' } );
	expect( button ).toBeInTheDocument();
	expect( button ).toHaveFocus();
} );

test( 'the tooltip does not repeat the accessible name as a description', async () => {
	renderInRoot(
		<IconButton icon="copy" label="Copy merchant ID" showTooltip />
	);
	const button = screen.getByRole( 'button', { name: 'Copy merchant ID' } );
	await userEvent.tab();
	await screen.findByRole( 'tooltip' );
	expect( button ).toHaveAttribute( 'aria-label', 'Copy merchant ID' );
	expect( button ).not.toHaveAttribute( 'aria-describedby' );
	expect( button ).toHaveAccessibleDescription( '' );
} );

test( 'IconButton without showTooltip never opens a tooltip', async () => {
	jest.useFakeTimers();
	const user = userEvent.setup( { advanceTimers: jest.advanceTimersByTime } );
	renderInRoot( <IconButton icon="close" label="Dismiss" /> );
	await user.tab();
	await user.hover( screen.getByRole( 'button', { name: 'Dismiss' } ) );
	act( () => {
		jest.advanceTimersByTime( TOOLTIP_DELAY_MS * 2 );
	} );
	expect( screen.queryByRole( 'tooltip' ) ).toBeNull();
} );

test( 'IconButton with showTooltip still forwards its ref and clicks', async () => {
	const ref = createRef< HTMLButtonElement >();
	const onClick = jest.fn();
	renderInRoot(
		<IconButton
			ref={ ref }
			icon="more"
			label="More actions"
			onClick={ onClick }
			showTooltip
		/>
	);
	const button = screen.getByRole( 'button', { name: 'More actions' } );
	expect( ref.current ).toBe( button );
	await userEvent.click( button );
	expect( onClick ).toHaveBeenCalledTimes( 1 );
} );
