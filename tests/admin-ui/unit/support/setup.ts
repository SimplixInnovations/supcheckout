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

// jsdom has no canvas. axe's color-contrast probes one and jsdom reports
// "not implemented" through console.error; a null context is what a browser
// without canvas support returns, and axe then marks the rule incomplete.
HTMLCanvasElement.prototype.getContext = ( () =>
	null ) as unknown as typeof HTMLCanvasElement.prototype.getContext;

// Any console.error (a React warning, a jsdom "not implemented") fails the
// test that caused it.
let consoleError: jest.SpyInstance;

beforeEach( () => {
	consoleError = jest.spyOn( console, 'error' );
} );

afterEach( () => {
	cleanup();
	const calls = consoleError.mock.calls;
	consoleError.mockRestore();
	if ( calls.length > 0 ) {
		throw new Error(
			`Unexpected console.error:\n${ calls
				.map( ( args ) => args.map( String ).join( ' ' ) )
				.join( '\n' ) }`
		);
	}
	const i18n = jest.requireMock( '@wordpress/i18n' );
	i18n.isRTL.mockReset();
	i18n.isRTL.mockReturnValue( false );
	document.documentElement.removeAttribute( 'lang' );
} );
