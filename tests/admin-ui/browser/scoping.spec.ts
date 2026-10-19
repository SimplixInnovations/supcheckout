/**
 * Scoping: the shipped admin app loads only on SUPCheckout screens, mounts one
 * scoped root inside wp-admin and resolves the generated tokens there.
 */
import { expect, test } from '@playwright/test';

import { galleryUrl, SHELL } from './support/env';
import { computedColor, expectedRgb, projectMode } from './support/modes';

const APP_ASSETS =
	'script[src*="assets/admin/build/app.js"], link[href*="assets/admin/build/app"]';
const APP_ROOT = '#wpbody .supcheckout-admin';

test( 'admin assets load only on SUPCheckout screens', async ( { page } ) => {
	for ( const url of [
		'/wp-admin/index.php',
		'/wp-admin/plugins.php',
		'/wp-admin/admin.php?page=wc-settings&tab=checkout',
	] ) {
		await page.goto( url );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		await expect( page.locator( APP_ASSETS ) ).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toHaveClass(
			/(^|\s)supcheckout-admin-page(\s|$)/
		);
		await expect( page.locator( '.supcheckout-admin' ) ).toHaveCount( 0 );
	}

	await page.goto( SHELL );
	await expect(
		page.locator( 'script[src*="assets/admin/build/app.js"]' )
	).toHaveCount( 1 );
	await expect(
		page.locator( 'link[href*="assets/admin/build/app"]' )
	).toHaveCount( 1 );
	await expect( page.locator( 'body' ) ).toHaveClass(
		/(^|\s)supcheckout-admin-page(\s|$)/
	);
} );

test( 'the shell mounts exactly one scoped root inside wp-admin', async ( {
	page,
} ) => {
	const failures: string[] = [];
	page.on( 'pageerror', ( error ) => failures.push( String( error ) ) );
	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			failures.push( message.text() );
		}
	} );
	page.on( 'response', ( response ) => {
		if (
			response.url().includes( '/wp-content/plugins/supcheckout/' ) &&
			response.status() >= 400
		) {
			failures.push( `HTTP ${ response.status() } ${ response.url() }` );
		}
	} );

	await page.goto( SHELL );
	await expect( page.locator( '.sup-app-placeholder' ) ).toBeVisible();

	// The PHP mount point is bare; SupRoot is the only scope root inside it.
	const mount = page.locator( '#supcheckout-admin-root' );
	await expect( mount ).toHaveCount( 1 );
	expect( await mount.getAttribute( 'class' ) ).toBeNull();
	await expect( page.locator( APP_ROOT ) ).toHaveCount( 1 );
	await expect(
		page.locator( '#supcheckout-admin-root > .supcheckout-admin' )
	).toHaveCount( 1 );

	// The portal layer is the other scope root, a direct child of body.
	await expect(
		page.locator( 'body > .supcheckout-admin.sup-portal-layer' )
	).toHaveCount( 1 );
	await expect( page.locator( '.supcheckout-admin' ) ).toHaveCount( 2 );

	// No duplicate main landmark: WordPress already provides #wpbody-content.
	await expect( page.locator( 'main, [role="main"]' ) ).toHaveCount( 1 );

	expect( failures ).toEqual( [] );
} );

test( 'the gallery renders inside exactly one scoped root', async ( {
	page,
} ) => {
	await page.goto( galleryUrl() );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	await expect( page.locator( APP_ROOT ) ).toHaveCount( 1 );
	await expect( page.locator( 'body' ) ).toHaveClass(
		/(^|\s)supcheckout-admin-page(\s|$)/
	);
} );

test( 'tokens resolve inside the app root in all four modes', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	await page.goto( SHELL );
	await expect( page.locator( '.sup-app-placeholder' ) ).toBeVisible();

	for ( const token of [
		'color-bg-canvas',
		'color-bg-surface',
		'color-fg-default',
		'color-fg-muted',
		'color-border-default',
	] ) {
		expect(
			await computedColor( page, APP_ROOT, `var(--sup-${ token })` ),
			token
		).toBe( await expectedRgb( mode, token ) );
	}

	// The app root paints the canvas, so dark mode never sits on light wp-admin.
	expect(
		await page
			.locator( APP_ROOT )
			.evaluate( ( root ) => getComputedStyle( root ).backgroundColor )
	).toBe( await expectedRgb( mode, 'color-bg-canvas' ) );
} );
