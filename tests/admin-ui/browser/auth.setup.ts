/**
 * Signs in the two test users once and saves their storage state.
 */
import { expect, test as setup } from '@playwright/test';

import { AUTH_FILES, USERS } from './support/env';
import type { UserKey } from './support/env';

for ( const key of Object.keys( USERS ) as UserKey[] ) {
	setup( `sign in as the ${ key } test user`, async ( { page } ) => {
		const user = USERS[ key ];
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( user.login );
		await page.locator( '#user_pass' ).fill( user.password );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		await page.context().storageState( { path: AUTH_FILES[ key ] } );
	} );
}
