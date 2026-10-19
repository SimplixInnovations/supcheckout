/**
 * Setup for the jsdom component tests: jest-dom and jest-axe matchers, a
 * controllable `isRTL()`, a console.error guard and a clean document between
 * tests.
 *
 * `@wordpress/i18n` is a runtime external (`wp.i18n`); in Jest it resolves to
 * the devDependency, with only `isRTL` replaced so tests can switch direction.
 */
import '@testing-library/jest-dom';
import { toHaveNoViolations } from 'jest-axe';

import { finishTestGuards, startTestGuards } from './guards';

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
// test that caused it, after the shared state is reset (see guards.ts).
beforeEach( startTestGuards );
afterEach( finishTestGuards );
