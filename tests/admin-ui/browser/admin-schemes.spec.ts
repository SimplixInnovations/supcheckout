/**
 * WordPress admin color schemes: the recorded `--wp-admin-theme-color` of
 * each core scheme (admin-schemes.tokens.json, `wpAdminThemeColor`) still
 * matches WordPress, and each scheme's accent ramp applies inside the scope.
 *
 * The gallery's `sup_admin_color` query (test mu-plugin) picks the scheme for
 * one request, so no user setting is changed.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { galleryUrl } from './support/env';
import {
	computedColor,
	expectedRgb,
	hexToRgb,
	projectMode,
	schemeNames,
} from './support/modes';

interface SchemeEntry {
	wpAdminThemeColor: { $value: string };
}

const SCHEMES: Record< string, SchemeEntry > = JSON.parse(
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
).schemes;

const APP_ROOT = '#wpbody .supcheckout-admin';

test( 'admin color scheme fixture matches WordPress', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	const names = await schemeNames();
	expect( Object.keys( SCHEMES ).sort() ).toEqual( [ ...names ].sort() );

	for ( const scheme of names ) {
		await test.step( scheme, async () => {
			await page.goto(
				galleryUrl( 'system', { sup_admin_color: scheme } )
			);
			await expect(
				page.locator( '[data-gallery-ready]' )
			).toBeVisible();
			await expect( page.locator( 'body' ) ).toHaveClass(
				new RegExp( `(^|\\s)admin-color-${ scheme }(\\s|$)` )
			);

			const wordpress = await page.evaluate( () =>
				getComputedStyle( document.body )
					.getPropertyValue( '--wp-admin-theme-color' )
					.trim()
			);
			expect(
				wordpress,
				`--wp-admin-theme-color is defined for ${ scheme }`
			).not.toBe( '' );
			expect(
				await computedColor( page, 'body', wordpress ),
				`--wp-admin-theme-color drifted for ${ scheme }`
			).toBe( hexToRgb( SCHEMES[ scheme ].wpAdminThemeColor.$value ) );

			expect(
				await computedColor(
					page,
					APP_ROOT,
					'var(--sup-color-accent-bg)'
				),
				`accent of ${ scheme } in ${ mode } mode`
			).toBe( await expectedRgb( mode, 'color-accent-bg', scheme ) );
		} );
	}
} );
