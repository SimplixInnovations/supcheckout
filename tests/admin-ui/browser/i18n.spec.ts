/**
 * Direction and translations: RTL users get the rtlcss stylesheet and an RTL
 * scoped root; app and lazy-chunk strings come from the supcheckout JSON
 * fixtures (tests/admin-ui/wp/fixtures/ar-strings.json). The chunk string is
 * the command palette placeholder, which only the lazy command-palette chunk
 * contains (overlays.spec.ts checks it on screen after the chunk loads).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { expect, test } from '@playwright/test';

import { SHELL } from './support/env';
import { projectMode } from './support/modes';

interface Fixture {
	app: Record< string, string >;
	chunks: Record< string, string >;
}

const FIXTURE: Fixture = JSON.parse(
	readFileSync(
		resolve( __dirname, '..', 'wp', 'fixtures', 'ar-strings.json' ),
		'utf8'
	)
);
const CHUNK_SOURCE = Object.keys( FIXTURE.chunks )[ 0 ];
const APP_ROOT = '#wpbody .supcheckout-admin';
const SHELL_TITLE = `${ APP_ROOT } .sup-app-shell h1`;

declare global {
	interface Window {
		wp: { i18n: { __: ( text: string, domain: string ) => string } };
	}
}

test( 'RTL users get the generated -rtl stylesheet', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await page.goto( SHELL );
	await expect( page.locator( SHELL_TITLE ) ).toBeVisible();

	const rtl = page.locator( 'link[href*="assets/admin/build/app-rtl.css"]' );
	const ltr = page.locator( 'link[href*="assets/admin/build/app.css"]' );
	// WordPress prints dir="rtl" on <html> for RTL locales and no dir for LTR.
	if ( dir === 'rtl' ) {
		await expect( page.locator( 'html' ) ).toHaveAttribute( 'dir', 'rtl' );
	} else {
		expect( await page.locator( 'html' ).getAttribute( 'dir' ) ).toBeNull();
	}
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute( 'dir', dir );
	await expect(
		page.locator( 'body > .supcheckout-admin.sup-portal-layer' )
	).toHaveAttribute( 'dir', dir );
	if ( dir === 'rtl' ) {
		await expect( rtl ).toHaveCount( 1 );
		await expect( ltr ).toHaveCount( 0 );
		await expect( page.locator( APP_ROOT ) ).toHaveAttribute(
			'lang',
			/^ar\b/
		);
	} else {
		await expect( ltr ).toHaveCount( 1 );
		await expect( rtl ).toHaveCount( 0 );
	}
} );

test( 'app strings are translated from the supcheckout JSON', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await page.goto( SHELL );
	const title = page.locator( SHELL_TITLE );
	await expect( title ).toHaveText(
		dir === 'rtl' ? FIXTURE.app.SUPCheckout : 'SUPCheckout'
	);
} );

test( 'lazy chunk translations reach wp.i18n before the app runs', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await page.goto( SHELL );
	await expect( page.locator( SHELL_TITLE ) ).toBeVisible();

	const before = page.locator( 'script#supcheckout-admin-js-before' );
	if ( dir === 'rtl' ) {
		await expect( before ).toHaveCount( 1 );
		expect( await before.textContent() ).toContain(
			'wp.i18n.setLocaleData(l,"supcheckout")'
		);
	} else {
		// No en_US translation file exists, so nothing is inlined.
		await expect( before ).toHaveCount( 0 );
	}

	// The chunk's strings come only from the chunk's JSON: the app's own
	// translations do not carry them.
	const appTranslations = page.locator(
		'script#supcheckout-admin-js-translations'
	);
	if ( dir === 'rtl' ) {
		// The ASCII part of the source string (the rest is JSON-escaped).
		const key = CHUNK_SOURCE.replace( /[^ -~].*$/, '' );
		expect( key.length ).toBeGreaterThan( 10 );
		expect( await before.textContent() ).toContain( key );
		await expect( appTranslations ).toHaveCount( 1 );
		expect( await appTranslations.textContent() ).not.toContain( key );
	}

	const translated = await page.evaluate(
		// The source string is data here, not a string to extract.
		( source ) => {
			const { __: translate } = window.wp.i18n;
			return translate( source, 'supcheckout' );
		},
		CHUNK_SOURCE
	);
	expect( translated ).toBe(
		dir === 'rtl' ? FIXTURE.chunks[ CHUNK_SOURCE ] : CHUNK_SOURCE
	);
} );
