/**
 * Numeric: amounts, order numbers and IDs keep their order inside RTL text
 * (SP1 Task 3, Review Focus 4).
 */
import { render, screen } from '@testing-library/react';

import { Numeric } from '../../../../assets/admin/src/ui';
import { mockIsRTL } from '../support/render';

test( 'Numeric isolates bidi and uses tabular figures', () => {
	mockIsRTL( true );
	render(
		<p dir="rtl">
			المبلغ <Numeric kind="amount">12.500 KWD</Numeric>
		</p>
	);
	const el = screen.getByText( '12.500 KWD' );
	expect( el.tagName ).toBe( 'BDI' );
	expect( el ).toHaveAttribute( 'dir', 'ltr' );
	expect( el ).toHaveClass( 'sup-numeric' );
	expect( el ).toHaveClass( 'sup-numeric--amount' );
} );

test( 'each kind gets its modifier and the default is a count', () => {
	render(
		<>
			<Numeric kind="id">#10234</Numeric>
			<Numeric>42</Numeric>
		</>
	);
	expect( screen.getByText( '#10234' ) ).toHaveClass( 'sup-numeric--id' );
	expect( screen.getByText( '42' ) ).toHaveClass( 'sup-numeric--count' );
	expect( screen.getByText( '42' ) ).toHaveAttribute( 'dir', 'ltr' );
} );
