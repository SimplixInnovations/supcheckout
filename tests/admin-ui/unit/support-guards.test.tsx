/**
 * The per-test console.error guard fails the test, but only after the shared
 * state is reset, so the next test starts LTR with no `<html lang>`.
 */
import { isRTL } from '@wordpress/i18n';

import { finishTestGuards, startTestGuards } from './support/guards';
import { mockIsRTL } from './support/render';

test( 'a failing console guard still resets direction and language', () => {
	mockIsRTL( true );
	document.documentElement.lang = 'ar';
	// eslint-disable-next-line no-console
	console.error( 'deliberate %s', 'probe' );

	expect( () => finishTestGuards() ).toThrow(
		'Unexpected console.error:\ndeliberate probe'
	);
	expect( isRTL() ).toBe( false );
	expect( document.documentElement ).not.toHaveAttribute( 'lang' );

	// Re-arm the guard for this test's own afterEach.
	startTestGuards();
} );

test( 'the next test starts clean', () => {
	expect( isRTL() ).toBe( false );
	expect( document.documentElement ).not.toHaveAttribute( 'lang' );
} );
