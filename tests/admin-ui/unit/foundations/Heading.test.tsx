/**
 * Heading, Text and VisuallyHidden (SP1 Task 3).
 */
import { render, screen } from '@testing-library/react';

import { Heading, Text, VisuallyHidden } from '../../../../assets/admin/src/ui';

test( 'the level sets the element and the default size follows the level', () => {
	render(
		<>
			<Heading level={ 1 }>One</Heading>
			<Heading level={ 2 }>Two</Heading>
			<Heading level={ 3 }>Three</Heading>
			<Heading level={ 4 }>Four</Heading>
		</>
	);
	const expected = [
		[ 'One', 1, 'xl' ],
		[ 'Two', 2, 'lg' ],
		[ 'Three', 3, 'md' ],
		[ 'Four', 4, 'sm' ],
	] as const;
	for ( const [ name, level, size ] of expected ) {
		const heading = screen.getByRole( 'heading', { name, level } );
		expect( heading.tagName ).toBe( `H${ level }` );
		expect( heading ).toHaveClass(
			'sup-heading',
			`sup-heading--${ size }`
		);
	}
} );

test( 'size is independent of the level', () => {
	render(
		<Heading level={ 2 } size="sm">
			Small two
		</Heading>
	);
	const heading = screen.getByRole( 'heading', { level: 2 } );
	expect( heading ).toHaveClass( 'sup-heading--sm' );
	expect( heading ).not.toHaveClass( 'sup-heading--lg' );
} );

test( 'Text defaults to a body paragraph and honors variant and element', () => {
	render(
		<>
			<Text>Body</Text>
			<Text variant="muted" as="span">
				Muted
			</Text>
			<Text variant="caption" as="div">
				Caption
			</Text>
			<Text variant="strong">Strong</Text>
		</>
	);
	expect( screen.getByText( 'Body' ).tagName ).toBe( 'P' );
	expect( screen.getByText( 'Body' ) ).toHaveClass(
		'sup-text',
		'sup-text--body'
	);
	expect( screen.getByText( 'Muted' ).tagName ).toBe( 'SPAN' );
	expect( screen.getByText( 'Muted' ) ).toHaveClass( 'sup-text--muted' );
	expect( screen.getByText( 'Caption' ).tagName ).toBe( 'DIV' );
	expect( screen.getByText( 'Caption' ) ).toHaveClass( 'sup-text--caption' );
	expect( screen.getByText( 'Strong' ) ).toHaveClass( 'sup-text--strong' );
} );

test( 'VisuallyHidden keeps text for assistive technology', () => {
	render(
		<>
			<VisuallyHidden>Inline</VisuallyHidden>
			<VisuallyHidden as="div">Block</VisuallyHidden>
		</>
	);
	expect( screen.getByText( 'Inline' ).tagName ).toBe( 'SPAN' );
	expect( screen.getByText( 'Inline' ) ).toHaveClass( 'sup-visually-hidden' );
	expect( screen.getByText( 'Block' ).tagName ).toBe( 'DIV' );
} );
