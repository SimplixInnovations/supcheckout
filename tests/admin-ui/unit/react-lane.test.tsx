/**
 * The component tests run on the React that each lane names: React 18.3.1
 * (WordPress 6.9 and 7.1 `wp-element`) by default, and React 19.3.0 under
 * `npm run test:unit:react19` (`SUP_REACT=19`).
 */
import { isValidElement, version as reactVersion } from 'react';
import { version as reactDomVersion } from 'react-dom';
import { createElement } from '@wordpress/element';

const expected = process.env.SUP_REACT === '19' ? '19.3.0' : '18.3.1';

test( 'react and react-dom are the lane React', () => {
	expect( reactVersion ).toBe( expected );
	expect( reactDomVersion ).toBe( expected );
} );

test( '@wordpress/element and the JSX runtime use the same React', () => {
	expect( isValidElement( createElement( 'div' ) ) ).toBe( true );
	expect( isValidElement( <div /> ) ).toBe( true );
} );
