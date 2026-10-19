/**
 * Direction: `useDirection()` follows `isRTL()` of `@wordpress/i18n`, and
 * SupRoot hands it to Base UI through `DirectionProvider` (SP1 Task 3).
 */
import { render, screen } from '@testing-library/react';
// The UI library is the only place that imports Base UI; this test reads Base
// UI's own direction context to prove SupRoot provides it.
// eslint-disable-next-line no-restricted-imports
import { useDirection as useBaseUiDirection } from '@base-ui/react/direction-provider';

import { SupRoot, useDirection } from '../../../../assets/admin/src/ui';
import { mockIsRTL, mockMatchMedia } from '../support/render';

function DirectionProbe() {
	return (
		<>
			<output data-testid="ours">{ useDirection() }</output>
			<output data-testid="base-ui">{ useBaseUiDirection() }</output>
		</>
	);
}

beforeEach( () => {
	mockMatchMedia( false );
} );

test( 'useDirection is ltr unless the locale is RTL', () => {
	render( <DirectionProbe /> );
	expect( screen.getByTestId( 'ours' ) ).toHaveTextContent( 'ltr' );
} );

test( 'useDirection is rtl for an RTL locale', () => {
	mockIsRTL( true );
	render( <DirectionProbe /> );
	expect( screen.getByTestId( 'ours' ) ).toHaveTextContent( 'rtl' );
} );

test( 'SupRoot sets dir and gives Base UI the same direction', () => {
	mockIsRTL( true );
	const { container } = render(
		<SupRoot>
			<DirectionProbe />
		</SupRoot>
	);
	expect( container.firstElementChild ).toHaveAttribute( 'dir', 'rtl' );
	expect( screen.getByTestId( 'base-ui' ) ).toHaveTextContent( 'rtl' );
} );

test( 'SupRoot gives Base UI ltr for an LTR locale', () => {
	const { container } = render(
		<SupRoot>
			<DirectionProbe />
		</SupRoot>
	);
	expect( container.firstElementChild ).toHaveAttribute( 'dir', 'ltr' );
	expect( screen.getByTestId( 'base-ui' ) ).toHaveTextContent( 'ltr' );
} );
