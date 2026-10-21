/**
 * Typography in real wp-admin (SP1 Task 8 baseline review).
 *
 * - wp-admin's heading rules (`.rtl h1…h6 { font-family: Tahoma, Arial }` in
 *   RTL locales) must never replace the scope's font: every heading the
 *   library renders (Heading, the AppShell title, Dialog and ConfirmDialog
 *   titles) uses `--sup-font-family-sans` as resolved where it sits.
 * - An Arabic run (`lang="ar"`) gets the Arabic stack, no letter spacing and
 *   the taller body line height in every mode, also on an English screen.
 */
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

import { galleryUrl } from './support/env';

const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';
const HEADINGS = ':is(h1, h2, h3, h4, h5, h6)';
const ARABIC_RUN = `${ APP_ROOT } [data-specimen="foundations-arabic"] [lang="ar"]`;

interface FontState {
	text: string;
	family: string;
	expected: string;
}

/**
 * @param target     Elements to check.
 * @param expression The font-family they must compute, e.g. `var(--sup-font-family-sans)`.
 * @return Each element's computed family and the family the expression
 *         resolves to in that element's own context (a probe child).
 */
function fonts( target: Locator, expression: string ): Promise< FontState[] > {
	return target.evaluateAll(
		( nodes, value ) =>
			nodes.map( ( node ) => {
				const probe = document.createElement( 'span' );
				probe.style.fontFamily = value;
				node.appendChild( probe );
				const expected = getComputedStyle( probe ).fontFamily;
				probe.remove();
				return {
					text: ( node.textContent ?? '' ).trim().slice( 0, 40 ),
					family: getComputedStyle( node ).fontFamily,
					expected,
				};
			} ),
		expression
	);
}

function mismatches( states: FontState[] ): FontState[] {
	return states.filter( ( state ) => state.family !== state.expected );
}

async function openGallery(
	page: Page,
	extra: Record< string, string > = {}
): Promise< void > {
	await page.goto( galleryUrl( 'system', extra ) );
	await expect(
		page.locator(
			extra.view === 'overlays'
				? '[data-overlays-ready]'
				: '[data-gallery-ready]'
		)
	).toBeVisible();
}

for ( const view of [ 'components', 'overlays' ] as const ) {
	test( `every heading of the ${ view } view uses the scope font, not the wp-admin heading font`, async ( {
		page,
	} ) => {
		await openGallery( page, view === 'overlays' ? { view } : {} );
		const headings = page.locator( `${ APP_ROOT } ${ HEADINGS }` );
		expect( await headings.count() ).toBeGreaterThan(
			view === 'overlays' ? 1 : 5
		);
		const states = await fonts( headings, 'var(--sup-font-family-sans)' );
		expect( mismatches( states ) ).toEqual( [] );
		for ( const state of states ) {
			expect( state.family ).not.toMatch( /^\s*(Tahoma|Arial)\b/ );
		}
	} );
}

test( 'the AppShell title and the dialog titles use the scope font', async ( {
	page,
} ) => {
	await openGallery( page, { view: 'overlays' } );
	const title = page.locator( `${ APP_ROOT } .sup-app-shell__title h1` );
	await expect( title ).toHaveCount( 1 );
	expect(
		mismatches( await fonts( title, 'var(--sup-font-family-sans)' ) )
	).toEqual( [] );

	const triggers = [
		page.locator( `${ APP_ROOT } [data-specimen="dialog"] button` ).first(),
		page
			.locator( `${ APP_ROOT } [data-specimen="confirm-dialog"] button` )
			.first(),
	];
	for ( const trigger of triggers ) {
		await trigger.focus();
		await trigger.press( 'Enter' );
		const heading = page.locator(
			`${ PORTAL_LAYER } :is([role="dialog"], [role="alertdialog"]) ${ HEADINGS }`
		);
		await expect( heading ).toHaveCount( 1 );
		const states = await fonts( heading, 'var(--sup-font-family-sans)' );
		expect( mismatches( states ) ).toEqual( [] );
		expect( states[ 0 ].family ).not.toMatch( /^\s*(Tahoma|Arial)\b/ );
		await page.keyboard.press( 'Escape' );
		await expect( heading ).toHaveCount( 0 );
	}
} );

test( 'an Arabic run gets the Arabic stack, no letter spacing and line height 1.7 in every mode', async ( {
	page,
} ) => {
	await openGallery( page );
	const run = page.locator( ARABIC_RUN );
	await expect( run ).toHaveCount( 1 );
	const elements = run.locator( '.sup-heading, .sup-text' );
	expect( await elements.count() ).toBeGreaterThanOrEqual( 5 );
	expect(
		mismatches( await fonts( elements, 'var(--sup-font-family-arabic)' ) )
	).toEqual( [] );
	const metrics = await elements.evaluateAll( ( nodes ) =>
		nodes.map( ( node ) => {
			const style = getComputedStyle( node );
			return {
				text: node.classList.contains( 'sup-text' ),
				letterSpacing: style.letterSpacing,
				ratio:
					parseFloat( style.lineHeight ) /
					parseFloat( style.fontSize ),
			};
		} )
	);
	for ( const metric of metrics ) {
		expect( [ 'normal', '0px' ] ).toContain( metric.letterSpacing );
		if ( metric.text ) {
			expect( metric.ratio ).toBeCloseTo( 1.7, 2 );
		}
	}
} );
