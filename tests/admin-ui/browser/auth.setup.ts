/**
 * Signs in the two test users once and saves their storage state.
 *
 * Right after activation, WooCommerce redirects the first wp-admin request to
 * its full-screen setup wizard (a one-time `_wc_activation_redirect`), which
 * hides the admin bar. Sign-in is proven by the logged-in cookie; then the
 * profile screen is loaded until it renders as itself, so no spec meets that
 * one-time redirect.
 */
import { expect, test as setup } from '@playwright/test';

import { AUTH_FILES, USERS } from './support/env';
import type { UserKey } from './support/env';

const PROFILE = '/wp-admin/profile.php';
const PROFILE_ATTEMPTS = 3;

for ( const key of Object.keys( USERS ) as UserKey[] ) {
	setup( `sign in as the ${ key } test user`, async ( { page } ) => {
		const user = USERS[ key ];
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( user.login );
		await page.locator( '#user_pass' ).fill( user.password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /\/wp-admin\// );

		const cookies = await page.context().cookies();
		expect(
			cookies.some( ( cookie ) =>
				cookie.name.startsWith( 'wordpress_logged_in_' )
			)
		).toBe( true );

		for ( let attempt = 1; attempt <= PROFILE_ATTEMPTS; attempt++ ) {
			await page.goto( PROFILE );
			if ( new URL( page.url() ).pathname === PROFILE ) {
				break;
			}
		}
		expect( new URL( page.url() ).pathname ).toBe( PROFILE );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		await expect( page.locator( '#user_login' ) ).toHaveValue( user.login );

		await page.context().storageState( { path: AUTH_FILES[ key ] } );
	} );
}
