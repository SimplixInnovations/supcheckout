/**
 * Render helpers for the admin UI component tests.
 */
import type { ReactElement } from 'react';
import { act, cleanup, render } from '@testing-library/react';
import { configureAxe } from 'jest-axe';
import { isRTL } from '@wordpress/i18n';

import { LIVE_REGION_DELAY_MS, SupRoot } from '../../../../assets/admin/src/ui';
import type { ThemePreference } from '../../../../assets/admin/src/ui';

/** WCAG 2.2 AA, as in the browser suite (spec section 4.1). */
export const AXE_TAGS = [
	'wcag2a',
	'wcag2aa',
	'wcag21a',
	'wcag21aa',
	'wcag22aa',
];

// No rule is disabled here. With `runOnly` tags, color-contrast still runs,
// but jsdom has no layout, computed colors or canvas, so it ends
// `incomplete`, which toHaveNoViolations ignores. renderInModes therefore
// asserts that color-contrast is the only incomplete rule; contrast itself is
// proven by the token suite and the browser suite.
const ALLOWED_INCOMPLETE = new Set( [ 'color-contrast' ] );

const axe = configureAxe( {
	runOnly: { type: 'tag', values: AXE_TAGS },
} );

const MODES: ReadonlyArray< {
	theme: Exclude< ThemePreference, 'system' >;
	rtl: boolean;
} > = [
	{ theme: 'light', rtl: false },
	{ theme: 'dark', rtl: false },
	{ theme: 'light', rtl: true },
	{ theme: 'dark', rtl: true },
];

type ChangeListener = ( event: { matches: boolean } ) => void;

/**
 * Makes `isRTL()` of `@wordpress/i18n` return `rtl` (see setup.ts).
 *
 * @param rtl Whether the locale is right-to-left.
 */
export function mockIsRTL( rtl: boolean ): void {
	( isRTL as jest.Mock ).mockReturnValue( rtl );
}

/**
 * Stubs `window.matchMedia` with a controllable `prefers-color-scheme: dark`
 * query (jsdom has no matchMedia).
 *
 * @param prefersDark Initial system preference.
 * @return `change( next )` dispatches a system change to every listener;
 *         `listenerCount()` counts the change listeners still attached.
 */
export function mockMatchMedia( prefersDark: boolean ): {
	change: ( next: boolean ) => void;
	listenerCount: () => number;
} {
	let matches = prefersDark;
	const listeners = new Set< ChangeListener >();
	window.matchMedia = jest.fn( ( query: string ) => ( {
		get matches() {
			return query.includes( 'dark' ) ? matches : false;
		},
		media: query,
		onchange: null,
		addEventListener: ( _type: string, listener: ChangeListener ) =>
			listeners.add( listener ),
		removeEventListener: ( _type: string, listener: ChangeListener ) =>
			listeners.delete( listener ),
		addListener: () => undefined,
		removeListener: () => undefined,
		dispatchEvent: () => true,
	} ) ) as unknown as typeof window.matchMedia;
	return {
		change( next: boolean ) {
			matches = next;
			for ( const listener of [ ...listeners ] ) {
				listener( { matches: next } );
			}
		},
		listenerCount: () => listeners.size,
	};
}

/**
 * @return Whether Jest's fake timers replace `setTimeout`: legacy timers
 *         install a jest.fn, modern (@sinonjs/fake-timers) ones a function
 *         carrying its `clock`.
 */
function fakeTimersActive(): boolean {
	return (
		jest.isMockFunction( setTimeout ) ||
		Object.prototype.hasOwnProperty.call( setTimeout, 'clock' )
	);
}

/**
 * Lets polite live-region text, inserted LIVE_REGION_DELAY_MS after its
 * region, land inside act(): by advancing fake timers when a test uses
 * them (a real wait would never end), otherwise by waiting.
 */
async function settleLiveRegions(): Promise< void > {
	if ( fakeTimersActive() ) {
		act( () => {
			jest.advanceTimersByTime( LIVE_REGION_DELAY_MS );
		} );
		return;
	}
	await act( async () => {
		await new Promise( ( resolve ) =>
			setTimeout( resolve, LIVE_REGION_DELAY_MS )
		);
	} );
}

/** Fake-time step and cap while axe runs under fake timers. */
const AXE_FAKE_STEP_MS = 10;
const AXE_FAKE_MAX_STEPS = 500;

/**
 * Runs axe on the document. axe schedules its own work with timers, so under
 * fake timers the clock is advanced, inside act(), until axe settles.
 *
 * @return axe's results.
 */
async function runAxe(): Promise< Awaited< ReturnType< typeof axe > > > {
	const run = axe( document.body );
	if ( ! fakeTimersActive() ) {
		return run;
	}
	let settled = false;
	run.then(
		() => ( settled = true ),
		() => ( settled = true )
	);
	for ( let step = 0; ! settled && step < AXE_FAKE_MAX_STEPS; step++ ) {
		await act( async () => {
			await jest.advanceTimersByTimeAsync( AXE_FAKE_STEP_MS );
		} );
	}
	return run;
}

/**
 * Renders `ui` inside `SupRoot` in light-ltr, dark-ltr, light-rtl and
 * dark-rtl and asserts that axe finds no WCAG 2.2 AA violation in each,
 * including anything portaled into the body-level layer, after delayed
 * live-region text has landed. Works with real and with fake timers.
 *
 * @param ui Element to check.
 */
export async function renderInModes( ui: ReactElement ): Promise< void > {
	for ( const { theme, rtl } of MODES ) {
		mockIsRTL( rtl );
		document.documentElement.lang = rtl ? 'ar' : 'en';
		render( <SupRoot preference={ theme }>{ ui }</SupRoot> );
		// Let delayed live-region text land inside act, so axe checks the
		// regions as users meet them and no update escapes act().
		await settleLiveRegions();
		const results = await runAxe();
		expect( results ).toHaveNoViolations();
		expect(
			results.incomplete
				.map( ( result ) => result.id )
				.filter( ( id ) => ! ALLOWED_INCOMPLETE.has( id ) )
		).toEqual( [] );
		cleanup();
	}
}
