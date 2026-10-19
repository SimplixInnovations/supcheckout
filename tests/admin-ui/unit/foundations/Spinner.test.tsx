/**
 * Spinner: decorative without a label, a polite status with one (SP1 Task 3).
 */
import { render, screen } from '@testing-library/react';

import { Spinner } from '../../../../assets/admin/src/ui';

test( 'a labelled spinner is a polite status', () => {
	render( <Spinner label="Loading payments" /> );
	const status = screen.getByRole( 'status' );
	expect( status ).toHaveTextContent( 'Loading payments' );
	expect( status ).toHaveClass( 'sup-spinner' );
	expect( status.querySelector( '.sup-spinner__ring' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	expect( screen.getByText( 'Loading payments' ) ).toHaveClass(
		'sup-visually-hidden'
	);
} );

test( 'an unlabelled spinner is decorative', () => {
	const { container } = render( <Spinner /> );
	expect( screen.queryByRole( 'status' ) ).toBeNull();
	expect( container.firstElementChild ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
} );

test( 'size maps to a size class and defaults to 20', () => {
	const { container } = render(
		<>
			<Spinner />
			<Spinner size={ 16 } />
		</>
	);
	const [ first, second ] = Array.from( container.children );
	expect( first ).toHaveClass( 'sup-spinner--20' );
	expect( second ).toHaveClass( 'sup-spinner--16' );
} );
