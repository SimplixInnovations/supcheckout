/**
 * An `aria-disabled="true"` button looks disabled (SP1 Task 8 fix round 1).
 *
 * ConfirmDialog keeps its Cancel focusable while the money action is pending
 * (`aria-disabled`, never `disabled`), so it must look exactly like a
 * natively disabled secondary button. A busy button also carries
 * `aria-disabled`, but keeps its own colors.
 */
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

import { galleryUrl } from './support/env';

const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';

interface Look {
	color: string;
	backgroundColor: string;
	borderColor: string;
	cursor: string;
	opacity: string;
}

function lookOf( button: Locator ): Promise< Look > {
	return button.evaluate( ( node ) => {
		const style = getComputedStyle( node );
		return {
			color: style.color,
			backgroundColor: style.backgroundColor,
			borderColor: style.borderTopColor,
			cursor: style.cursor,
			opacity: style.opacity,
		};
	} );
}

async function nativeDisabledLook( page: Page ): Promise< Look > {
	await page.goto( galleryUrl( 'system' ) );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	const disabled = page.locator(
		`${ APP_ROOT } [data-specimen="button-states"] button:disabled`
	);
	await expect( disabled ).toHaveCount( 1 );
	return lookOf( disabled );
}

test( 'the pending confirmation Cancel looks disabled and stays focusable; the busy confirm keeps its colors', async ( {
	page,
} ) => {
	const expected = await nativeDisabledLook( page );

	await page.goto(
		galleryUrl( 'system', { view: 'overlays', confirm: 'hold' } )
	);
	await expect( page.locator( '[data-overlays-ready]' ) ).toBeVisible();
	const trigger = page
		.locator( `${ APP_ROOT } [data-specimen="confirm-dialog"] button` )
		.first();
	await trigger.focus();
	await trigger.press( 'Enter' );
	const footer = page.locator(
		`${ PORTAL_LAYER } [role="alertdialog"] .sup-dialog__footer`
	);
	const cancel = footer.getByRole( 'button' ).first();
	const confirm = footer.getByRole( 'button' ).nth( 1 );
	await expect( cancel ).toBeVisible();
	const busyBefore = await lookOf( confirm );
	await confirm.focus();
	await confirm.press( 'Enter' );
	await expect( cancel ).toHaveAttribute( 'aria-disabled', 'true' );
	await expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );

	expect( await lookOf( cancel ) ).toEqual( expected );
	expect(
		await cancel.evaluate( ( node ) => node.matches( ':disabled' ) )
	).toBe( false );
	await cancel.focus();
	await expect( cancel ).toBeFocused();

	// Busy is not disabled: the confirm button keeps its colors.
	const busy = await lookOf( confirm );
	expect( busy.backgroundColor ).toBe( busyBefore.backgroundColor );
	expect( busy.backgroundColor ).not.toBe( expected.backgroundColor );
} );
