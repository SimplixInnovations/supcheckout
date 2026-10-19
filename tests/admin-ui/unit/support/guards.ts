/**
 * Per-test guards for the jsdom component tests (wired in setup.ts).
 *
 * `startTestGuards()` records every console.error. `finishTestGuards()`
 * unmounts, resets the shared state (the `isRTL()` mock and `<html lang>`)
 * and only then fails the test if anything was logged, so a failing test
 * never leaks RTL or a language into the next one.
 */
import { format } from 'node:util';
import { cleanup } from '@testing-library/react';

let consoleError: jest.SpyInstance | null = null;

export function startTestGuards(): void {
	// Recorded, not printed: the thrown error carries the messages.
	consoleError = jest
		.spyOn( console, 'error' )
		.mockImplementation( () => undefined );
}

function resetSharedState(): void {
	const i18n = jest.requireMock( '@wordpress/i18n' );
	i18n.isRTL.mockReset();
	i18n.isRTL.mockReturnValue( false );
	document.documentElement.removeAttribute( 'lang' );
}

export function finishTestGuards(): void {
	const calls = consoleError ? [ ...consoleError.mock.calls ] : [];
	consoleError?.mockRestore();
	consoleError = null;
	try {
		cleanup();
	} finally {
		resetSharedState();
	}
	if ( calls.length > 0 ) {
		throw new Error(
			`Unexpected console.error:\n${ calls
				.map( ( args ) => format( ...args ) )
				.join( '\n' ) }`
		);
	}
}
