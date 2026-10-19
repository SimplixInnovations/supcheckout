/**
 * WordPress admin color schemes in real wp-admin:
 * - each core scheme's accent ramp applies inside the scope, on every
 *   WordPress version in the matrix;
 * - the recorded `--wp-admin-theme-color` of each scheme
 *   (admin-schemes.tokens.json `wpAdminThemeColor`, recorded at WordPress
 *   7.1.3) still matches WordPress 7.1.3. Other versions report what they
 *   define as annotations: WordPress 6.9.10, for one, defines `#0085ba` for
 *   the Light scheme where 7.1.3 defines `#007cba`.
 *
 * The gallery's `sup_admin_color` query (test mu-plugin) picks the scheme for
 * one request, so no user setting is changed.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { galleryUrl, WP_VERSION } from './support/env';
import {
	computedColor,
	expectedRgb,
	hexToRgb,
	projectMode,
	schemeNames,
} from './support/modes';

/** The WordPress version `wpAdminThemeColor` was recorded at. */
const FIXTURE_WP_VERSION = '7.1.3';

interface SchemeEntry {
	wpAdminThemeColor: { $value: string };
}

const TOKENS: { $description: string; schemes: Record< string, SchemeEntry > } =
	JSON.parse(
		readFileSync(
			resolve(
				__dirname,
				'..',
				'..',
				'..',
				'assets',
				'admin',
				'src',
				'tokens',
				'admin-schemes.tokens.json'
			),
			'utf8'
		)
	);
const SCHEMES = TOKENS.schemes;

const APP_ROOT = '#wpbody .supcheckout-admin';

async function openScheme( page: Page, scheme: string ): Promise< void > {
	await page.goto( galleryUrl( 'system', { sup_admin_color: scheme } ) );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	await expect( page.locator( 'body' ) ).toHaveClass(
		new RegExp( `(^|\\s)admin-color-${ scheme }(\\s|$)` )
	);
}

test( 'each admin color scheme applies its accent ramp inside the scope', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	const names = await schemeNames();
	expect( Object.keys( SCHEMES ).sort() ).toEqual( [ ...names ].sort() );

	for ( const scheme of names ) {
		await test.step( scheme, async () => {
			await openScheme( page, scheme );
			for ( const token of [ 'color-accent-bg', 'color-focus-ring' ] ) {
				expect(
					await computedColor(
						page,
						APP_ROOT,
						`var(--sup-${ token })`
					),
					`${ token } of ${ scheme } in ${ mode } mode`
				).toBe( await expectedRgb( mode, token, scheme ) );
			}
		} );
	}
} );

test( 'admin color scheme fixture matches WordPress', async ( {
	page,
}, testInfo ) => {
	expect( TOKENS.$description ).toContain(
		`at WordPress ${ FIXTURE_WP_VERSION }`
	);
	const compare = WP_VERSION === '' || WP_VERSION === FIXTURE_WP_VERSION;
	const mismatches: string[] = [];

	for ( const scheme of await schemeNames() ) {
		await openScheme( page, scheme );
		const defined = await page.evaluate( () =>
			getComputedStyle( document.body )
				.getPropertyValue( '--wp-admin-theme-color' )
				.trim()
		);
		expect(
			defined,
			`--wp-admin-theme-color is defined for ${ scheme }`
		).not.toBe( '' );
		const actual = await computedColor( page, 'body', defined );
		const recorded = hexToRgb( SCHEMES[ scheme ].wpAdminThemeColor.$value );
		if ( actual !== recorded ) {
			mismatches.push(
				`${ scheme }: WordPress ${ actual }, recorded ${ recorded }`
			);
		}
	}

	if ( ! compare ) {
		testInfo.annotations.push( {
			type: 'wp-admin-theme-color',
			description: `WordPress ${ WP_VERSION } differs from the ${ FIXTURE_WP_VERSION } record: ${
				mismatches.join( '; ' ) || 'none'
			}`,
		} );
		return;
	}
	expect( mismatches, '--wp-admin-theme-color drifted' ).toEqual( [] );
} );
