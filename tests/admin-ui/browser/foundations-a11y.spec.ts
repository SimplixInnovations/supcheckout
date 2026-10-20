/**
 * Foundations gallery in real wp-admin: axe (WCAG 2.2 AA, every rule, color
 * contrast computed for real), specimen order, theme override, reduced
 * motion and forced colors.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { galleryUrl } from './support/env';
import { projectMode } from './support/modes';

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';
const SPECIMENS = [
	'foundations-headings',
	'foundations-text',
	'foundations-numeric',
	'foundations-icons',
	'foundations-spinner',
	'foundations-visually-hidden',
	'foundations-arabic',
];

async function openGallery(
	page: Page,
	theme: 'light' | 'dark' | 'system'
): Promise< void > {
	await page.goto( galleryUrl( theme ) );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	await page.evaluate( () => document.fonts.ready );
}

test( 'foundations gallery has no axe violations', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	// The system preference follows the project; the explicit opposite proves
	// data-sup-theme overrides prefers-color-scheme.
	const themes = [ 'system', mode === 'dark' ? 'light' : 'dark' ] as const;

	for ( const theme of themes ) {
		await test.step( `theme=${ theme }`, async () => {
			await openGallery( page, theme );
			const results = await new AxeBuilder( { page } )
				.withTags( TAGS )
				.include( '.supcheckout-admin' )
				.analyze();

			expect( results.violations ).toEqual( [] );

			// Contrast was computed, not left undecided, on every specimen.
			const undecided = results.incomplete
				.filter( ( rule ) => rule.id === 'color-contrast' )
				.flatMap( ( rule ) =>
					rule.nodes.map( ( node ) => node.target )
				);
			expect( undecided ).toEqual( [] );
			expect(
				results.passes.some( ( rule ) => rule.id === 'color-contrast' )
			).toBe( true );
		} );
	}
} );

test( 'specimens render in a fixed DOM order', async ( { page } ) => {
	await openGallery( page, 'system' );
	// Later groups (controls.spec.ts) assert their own order after these.
	expect(
		await page
			.locator( `${ APP_ROOT } [data-specimen^="foundations-"]` )
			.evaluateAll( ( nodes ) =>
				nodes.map( ( node ) => node.getAttribute( 'data-specimen' ) )
			)
	).toEqual( SPECIMENS );
} );

test( 'the theme query sets data-sup-theme on the root and the portal layer', async ( {
	page,
} ) => {
	await openGallery( page, 'dark' );
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);
	await expect( page.locator( PORTAL_LAYER ) ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);

	await openGallery( page, 'system' );
	expect(
		await page.locator( APP_ROOT ).getAttribute( 'data-sup-theme' )
	).toBeNull();
	expect(
		await page.locator( PORTAL_LAYER ).getAttribute( 'data-sup-theme' )
	).toBeNull();
} );

test( 'numbers and identifiers stay isolated LTR runs in tabular figures', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await openGallery( page, 'system' );
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute( 'dir', dir );

	const numerics = page.locator(
		'[data-specimen="foundations-numeric"] .sup-numeric'
	);
	await expect( numerics ).toHaveText( [ '12.500 KWD', '#10234', '1,024' ] );
	for ( const numeric of await numerics.all() ) {
		await expect( numeric ).toHaveAttribute( 'dir', 'ltr' );
		expect(
			await numeric.evaluate( ( node ) => {
				const style = getComputedStyle( node );
				return [ style.unicodeBidi, style.fontVariantNumeric ];
			} )
		).toEqual( [ 'isolate', 'tabular-nums' ] );
	}
} );

test( 'reduced motion stops the spinner and zeroes motion tokens', async ( {
	page,
} ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await openGallery( page, 'system' );
	const ring = page.locator(
		'[data-specimen="foundations-spinner"] .sup-spinner__ring'
	);
	await expect( ring.first() ).toHaveCSS( 'animation-name', 'none' );
	expect(
		await page
			.locator( APP_ROOT )
			.evaluate( ( root ) =>
				getComputedStyle( root )
					.getPropertyValue( '--sup-motion-duration-base' )
					.trim()
			)
	).toBe( '0ms' );

	await page.emulateMedia( { reducedMotion: 'no-preference' } );
	await expect( ring.first() ).toHaveCSS( 'animation-name', 'sup-spin' );
} );

test( 'forced colors switch the spinner to system colors', async ( {
	page,
} ) => {
	await page.emulateMedia( { forcedColors: 'active' } );
	await openGallery( page, 'system' );
	const ring = page
		.locator( '[data-specimen="foundations-spinner"] .sup-spinner__ring' )
		.first();
	const [ ringTop, highlight ] = await ring.evaluate( ( node ) => {
		const probe = document.createElement( 'span' );
		probe.style.color = 'highlight';
		node.appendChild( probe );
		const system = getComputedStyle( probe ).color;
		probe.remove();
		return [ getComputedStyle( node ).borderBlockStartColor, system ];
	} );
	expect( ringTop ).toBe( highlight );

	const results = await new AxeBuilder( { page } )
		.withTags( TAGS )
		.include( '.supcheckout-admin' )
		.analyze();
	expect( results.violations ).toEqual( [] );
} );
