/**
 * Setup for the jsdom component tests: jest-dom and jest-axe matchers, a
 * controllable `isRTL()` and a clean document between tests.
 *
 * `@wordpress/i18n` is a runtime external (`wp.i18n`); in Jest it resolves to
 * the devDependency, with only `isRTL` replaced so tests can switch direction.
 */
import '@testing-library/jest-dom';
import { cleanup } from '@testing-library/react';
import { toHaveNoViolations } from 'jest-axe';

jest.mock( '@wordpress/i18n', () => ( {
	...jest.requireActual( '@wordpress/i18n' ),
	isRTL: jest.fn( () => false ),
} ) );

expect.extend( toHaveNoViolations );

afterEach( () => {
	cleanup();
	const i18n = jest.requireMock( '@wordpress/i18n' );
	i18n.isRTL.mockReset();
	i18n.isRTL.mockReturnValue( false );
	document.documentElement.removeAttribute( 'lang' );
} );
