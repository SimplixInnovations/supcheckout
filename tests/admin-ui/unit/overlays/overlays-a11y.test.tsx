/**
 * The overlay specimens have no axe violation (WCAG 2.2 AA) in light-ltr,
 * dark-ltr, light-rtl and dark-rtl, closed and with each overlay open (SP1
 * Task 7).
 *
 * Closed, the specimens go through the shared `renderInModes`. Open, a
 * modal adds Base UI's focus guards: visually hidden, `aria-hidden` spans
 * with `tabindex="0"` on each side of the popup, which hand focus back into
 * the dialog when Tab reaches them (the focus trap). axe cannot decide in
 * jsdom whether such an element is reachable and leaves `aria-hidden-focus`
 * incomplete for exactly those spans. axe also always asks for a manual
 * review of `aria-controls` on an element with `aria-haspopup`, which is
 * every combobox (the palette input). The open check allows those two
 * incomplete results only when every node they list is a Base UI focus
 * guard or the palette combobox; everything else is held to the same rules
 * as renderInModes, and the page behind the modal is proven inert.
 */
import { act, cleanup, render } from '@testing-library/react';
import { configureAxe } from 'jest-axe';
import type { ReactElement } from 'react';

import { LIVE_REGION_DELAY_MS, SupRoot } from '../../../../assets/admin/src/ui';
import type { OverlayName } from '../../gallery/specimens/overlays';
import { OverlaysSpecimens } from '../../gallery/specimens/overlays';
import {
	AXE_TAGS,
	mockIsRTL,
	mockMatchMedia,
	renderInModes,
} from '../support/render';

const axe = configureAxe( { runOnly: { type: 'tag', values: AXE_TAGS } } );

const MODES = [
	{ theme: 'light', rtl: false },
	{ theme: 'dark', rtl: false },
	{ theme: 'light', rtl: true },
	{ theme: 'dark', rtl: true },
] as const;

/** Base UI's focus guards: `<span data-type="inside|outside" … tabindex="0">`. */
const FOCUS_GUARD = /^<span data-type="(inside|outside)"[^>]*tabindex="0"/;

/** The palette's combobox input. */
const PALETTE_COMBOBOX =
	/^<input [^>]*role="combobox"[^>]*aria-haspopup="listbox"/;

async function renderOpenInModes( ui: ReactElement ): Promise< void > {
	for ( const { theme, rtl } of MODES ) {
		mockIsRTL( rtl );
		document.documentElement.lang = rtl ? 'ar' : 'en';
		const { container } = render(
			<SupRoot preference={ theme }>{ ui }</SupRoot>
		);
		// The lazy palette panel, the inert frame and delayed live-region
		// text all land inside act.
		await act( async () => {
			await new Promise( ( resolve ) =>
				setTimeout( resolve, LIVE_REGION_DELAY_MS * 2 )
			);
		} );
		expect(
			document.querySelector( '[role=dialog], [role=alertdialog]' )
		).not.toBeNull();
		// The page behind the modal is inert, not only aria-hidden: every
		// control there (live regions and their ancestors stay usable).
		const behind = Array.from(
			container.querySelectorAll(
				'button, input, a[href], [tabindex]:not([tabindex="-1"])'
			)
		);
		expect( behind.length ).toBeGreaterThan( 0 );
		expect(
			behind.filter( ( el ) => el.closest( '[inert]' ) === null )
		).toEqual( [] );

		let results: Awaited< ReturnType< typeof axe > > | undefined;
		await act( async () => {
			results = await axe( document.body );
		} );
		if ( ! results ) {
			throw new Error( 'axe returned no results' );
		}
		expect( results ).toHaveNoViolations();
		for ( const result of results.incomplete ) {
			if ( result.id === 'color-contrast' ) {
				continue;
			}
			expect( [
				'aria-hidden-focus',
				'aria-valid-attr-value',
			] ).toContain( result.id );
			for ( const node of result.nodes ) {
				expect( node.html ).toMatch(
					result.id === 'aria-hidden-focus'
						? FOCUS_GUARD
						: PALETTE_COMBOBOX
				);
				if ( result.id === 'aria-valid-attr-value' ) {
					expect(
						node.all.map( ( check ) => check.data?.messageKey )
					).toEqual( [ 'controlsWithinPopup' ] );
				}
			}
		}
		act( () => cleanup() );
	}
}

// axe over the whole AppShell in four modes is slow on a loaded CI runner.
const AXE_TIMEOUT_MS = 60_000;
jest.setTimeout( AXE_TIMEOUT_MS );

beforeEach( () => {
	mockMatchMedia( false );
} );

test( 'overlay specimens have no axe violations in four modes', async () => {
	await renderInModes( <OverlaysSpecimens /> );
} );

test.each< OverlayName >( [ 'dialog', 'confirm', 'palette' ] )(
	'overlays have no axe violations in four modes when open: %s',
	async ( open ) => {
		expect.hasAssertions();
		await renderOpenInModes( <OverlaysSpecimens open={ open } /> );
	}
);
