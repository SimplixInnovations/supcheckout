/**
 * Icon: lucide glyphs behind our own `IconName` union (SP1 Task 3).
 */
import { render, screen } from '@testing-library/react';

import {
	DIRECTIONAL_ICONS,
	ICON_NAMES,
	Icon,
} from '../../../../assets/admin/src/ui';
import type { IconName } from '../../../../assets/admin/src/ui';
import { mockIsRTL } from '../support/render';

const icon = ( name: IconName ) => {
	const { container } = render( <Icon name={ name } /> );
	return container.querySelector( 'svg' );
};

test( 'the icon set is exactly the published names', () => {
	expect( [ ...ICON_NAMES ].sort() ).toEqual(
		[
			'check',
			'close',
			'info',
			'warning',
			'error',
			'success',
			'search',
			'chevron-start',
			'chevron-end',
			'chevron-down',
			'arrow-start',
			'arrow-end',
			'external',
			'copy',
			'more',
			'sun',
			'moon',
			'monitor',
			'command',
			'refresh',
			'lock',
			'eye-off',
		].sort()
	);
	expect( [ ...DIRECTIONAL_ICONS ].sort() ).toEqual(
		[ 'arrow-end', 'arrow-start', 'chevron-end', 'chevron-start' ].sort()
	);
} );

test( 'every icon renders an SVG', () => {
	for ( const name of ICON_NAMES ) {
		expect( [ name, icon( name )?.tagName.toLowerCase() ] ).toEqual( [
			name,
			'svg',
		] );
	}
} );

test( 'directional icons mirror in RTL only', () => {
	mockIsRTL( true );
	expect( icon( 'chevron-end' ) ).toHaveClass( 'sup-icon--mirrored' );
	expect( icon( 'arrow-start' ) ).toHaveClass( 'sup-icon--mirrored' );
	expect( icon( 'check' ) ).not.toHaveClass( 'sup-icon--mirrored' );
	mockIsRTL( false );
	expect( icon( 'chevron-start' ) ).not.toHaveClass( 'sup-icon--mirrored' );
} );

test( 'start and end resolve to opposite glyphs', () => {
	const start = icon( 'chevron-start' )?.innerHTML;
	const end = icon( 'chevron-end' )?.innerHTML;
	expect( start ).not.toBe( end );
} );

test( 'decorative icons are hidden and labelled icons are images', () => {
	const decorative = icon( 'info' );
	expect( decorative ).toHaveAttribute( 'aria-hidden', 'true' );
	expect( decorative ).toHaveAttribute( 'focusable', 'false' );
	expect( decorative ).not.toHaveAttribute( 'role' );

	render( <Icon name="success" label="Verified" /> );
	const labelled = screen.getByRole( 'img', { name: 'Verified' } );
	expect( labelled ).not.toHaveAttribute( 'aria-hidden' );
	expect( labelled ).toHaveClass( 'sup-icon' );
} );

test( 'size maps to a size class and the SVG box', () => {
	const { container } = render(
		<>
			<Icon name="check" />
			<Icon name="check" size={ 16 } />
			<Icon name="check" size={ 24 } />
		</>
	);
	const svgs = container.querySelectorAll( 'svg' );
	expect( svgs[ 0 ] ).toHaveClass( 'sup-icon--20' );
	expect( svgs[ 0 ] ).toHaveAttribute( 'width', '20' );
	expect( svgs[ 1 ] ).toHaveClass( 'sup-icon--16' );
	expect( svgs[ 2 ] ).toHaveClass( 'sup-icon--24' );
	expect( svgs[ 2 ] ).toHaveAttribute( 'height', '24' );
} );
