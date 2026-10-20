/**
 * Overlays, navigation, AppShell and the command palette in real wp-admin
 * (SP1 Task 7, Review Focus 1, 2 and 5): modal dialogs cover the WordPress
 * admin bar and menu, portaled overlays keep tokens, theme and direction,
 * the money confirmation cannot be dismissed while pending, an open tooltip
 * closes on Escape before its dialog, wp-admin keeps exactly one main
 * landmark, the sticky header never obscures the focused element, the
 * palette chunk loads lazily with its own translations, and the overlay
 * gallery has no axe violations with each overlay open.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

import { galleryUrl, SHELL } from './support/env';
import { computedColor, expectedRgb, projectMode } from './support/modes';

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';
const HEADER = `${ APP_ROOT } .sup-app-shell__header`;

const OVERLAY_SPECIMENS = [
	'dialog',
	'confirm-dialog',
	'tabs',
	'data-table',
	'data-table-loading',
	'data-table-empty',
	'focus-scroll',
];

const INTERACTIVE =
	'button, input, select, textarea, a[href], [tabindex]:not([tabindex="-1"])';

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
const PALETTE_PLACEHOLDER = 'Type a command or search…';

async function openOverlays(
	page: Page,
	theme: 'light' | 'dark' | 'system' = 'system'
): Promise< void > {
	await page.goto( galleryUrl( theme, { view: 'overlays' } ) );
	await expect( page.locator( '[data-overlays-ready]' ) ).toBeVisible();
	await page.evaluate( () => document.fonts.ready );
}

const specimen = ( page: Page, name: string ): Locator =>
	page.locator( `${ APP_ROOT } [data-specimen="${ name }"]` );

/**
 * Waits until no transition or animation runs in the portal layer.
 *
 * @param page The page.
 */
function layerSettled( page: Page ): Promise< unknown > {
	return page.waitForFunction(
		() =>
			document
				.querySelector( '.sup-portal-layer' )
				?.getAnimations( { subtree: true } ).length === 0
	);
}

async function openDialog( page: Page ): Promise< Locator > {
	await specimen( page, 'dialog' )
		.getByRole( 'button', { name: 'Edit webhook' } )
		.click();
	const dialog = page.getByRole( 'dialog', { name: 'Edit webhook' } );
	await expect( dialog ).toBeVisible();
	await layerSettled( page );
	return dialog;
}

interface Box {
	left: number;
	top: number;
	right: number;
	bottom: number;
}

const overlap = ( a: Box, b: Box ): number =>
	Math.max( 0, Math.min( a.right, b.right ) - Math.max( a.left, b.left ) ) *
	Math.max( 0, Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top ) );

/**
 * @param page The page.
 * @return The focused element, the sticky header and the admin bar boxes.
 */
function focusBoxes( page: Page ): Promise< {
	label: string;
	focused: Box;
	header: Box;
	adminBar: Box;
} > {
	return page.evaluate( () => {
		const box = ( el: Element ): Box => {
			const r = el.getBoundingClientRect();
			return {
				left: r.left,
				top: r.top,
				right: r.right,
				bottom: r.bottom,
			};
		};
		// eslint-disable-next-line @wordpress/no-global-active-element -- runs inside the page, with no node ref to start from.
		const active = document.activeElement as HTMLElement;
		const header = document.querySelector( '.sup-app-shell__header' );
		const adminBar = document.getElementById( 'wpadminbar' );
		if ( ! header || ! adminBar ) {
			throw new Error( 'missing header or admin bar' );
		}
		return {
			label: active.textContent?.trim() ?? active.tagName,
			focused: box( active ),
			header: box( header ),
			adminBar: box( adminBar ),
		};
	} );
}

test( 'overlay specimens render in a fixed DOM order inside the shell', async ( {
	page,
} ) => {
	await openOverlays( page );
	const names = await page
		.locator( `${ APP_ROOT } #supcheckout-content [data-specimen]` )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => node.getAttribute( 'data-specimen' ) ?? '' )
		);
	expect( names ).toEqual( OVERLAY_SPECIMENS );
} );

test( 'modal dialog layer covers the WordPress admin bar and menu', async ( {
	page,
} ) => {
	await openOverlays( page );
	await openDialog( page );
	const hits = await page.evaluate( () => {
		const centre = ( id: string ) => {
			const el = document.getElementById( id );
			if ( ! el ) {
				throw new Error( `no #${ id }` );
			}
			const r = el.getBoundingClientRect();
			// The visible part of the element, inside the viewport.
			const top = Math.max( r.top, 0 );
			const bottom = Math.min( r.bottom, window.innerHeight );
			const hit = document.elementFromPoint(
				( r.left + r.right ) / 2,
				( top + bottom ) / 2
			);
			return hit?.closest( '.sup-portal-layer' ) !== null && hit !== null;
		};
		return {
			adminBar: centre( 'wpadminbar' ),
			adminMenu: centre( 'adminmenu' ),
		};
	} );
	expect( hits ).toEqual( { adminBar: true, adminMenu: true } );
	expect(
		await page
			.locator( PORTAL_LAYER )
			.evaluate( ( el ) => getComputedStyle( el ).zIndex )
	).toBe( '100000' );
	// The page behind the modal is inert while the dialog is open.
	await expect( page.locator( '#wpwrap' ) ).toHaveAttribute( 'inert', '' );
	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect( page.locator( '#wpwrap' ) ).not.toHaveAttribute( 'inert' );
	await expect(
		specimen( page, 'dialog' ).getByRole( 'button', {
			name: 'Edit webhook',
		} )
	).toBeFocused();
} );

test( 'portaled overlays keep tokens, theme and direction', async ( {
	page,
}, testInfo ) => {
	const { mode, dir } = projectMode( testInfo );
	await openOverlays( page );
	const dialog = await openDialog( page );
	await expect( dialog ).toHaveCSS(
		'background-color',
		await expectedRgb( mode, 'color-bg-surface-raised' )
	);
	expect(
		await dialog.evaluate(
			( el ) => el.closest( '[dir]' )?.getAttribute( 'dir' ) ?? null
		)
	).toBe( dir );
	expect(
		await dialog.evaluate(
			( el ) => el.closest( '.supcheckout-admin' ) !== null
		)
	).toBe( true );

	// A tooltip inside the dialog renders in the dialog's own layer.
	const copy = dialog.getByRole( 'button', { name: 'Copy address' } );
	await copy.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	const tooltip = page.getByRole( 'tooltip' );
	await expect( tooltip ).toBeVisible();
	expect(
		await tooltip.evaluate( ( el ) => ( {
			inDialog: el.closest( '[role="dialog"]' ) !== null,
			dir: el.closest( '[dir]' )?.getAttribute( 'dir' ) ?? null,
			hiddenFromAt:
				el.closest( '[aria-hidden="true"], [inert]' ) !== null,
		} ) )
	).toEqual( { inDialog: true, dir, hiddenFromAt: false } );
	await expect( tooltip ).toHaveCSS(
		'background-color',
		await computedColor( page, PORTAL_LAYER, 'var(--sup-color-fg-default)' )
	);
} );

test( 'Escape closes a tooltip inside a dialog first and the dialog stays open', async ( {
	page,
} ) => {
	await openOverlays( page );
	const dialog = await openDialog( page );
	const copy = dialog.getByRole( 'button', { name: 'Copy address' } );
	await copy.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	await expect( copy ).toBeFocused();
	await expect( page.getByRole( 'tooltip' ) ).toBeVisible();

	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'tooltip' ) ).toHaveCount( 0 );
	await expect( dialog ).toBeVisible();
	await expect( copy ).toBeFocused();

	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
} );

test( 'Tab stays inside an open dialog', async ( { page } ) => {
	await openOverlays( page );
	const dialog = await openDialog( page );
	const reached = new Set< string >();
	const focusIn = () =>
		page.evaluate( () => {
			// eslint-disable-next-line @wordpress/no-global-active-element -- runs inside the page.
			const active = document.activeElement as HTMLElement;
			return {
				inDialog: active.closest( '[role="dialog"]' ) !== null,
				name:
					active.getAttribute( 'aria-label' ) ??
					active.textContent?.trim() ??
					'',
				where: active.outerHTML.slice( 0, 120 ),
			};
		} );
	for ( let i = 0; i < 10; i++ ) {
		await page.keyboard.press( 'Tab' );
		// Base UI's focus guards hand focus back into the popup on focus.
		await expect
			.poll( async () => ( await focusIn() ).inDialog, {
				message: `Tab ${ i + 1 } left the dialog: ${
					( await focusIn() ).where
				}`,
				timeout: 2000,
			} )
			.toBe( true );
		reached.add( ( await focusIn() ).name );
	}
	expect( [ ...reached ] ).toEqual(
		expect.arrayContaining( [
			'Close dialog',
			'Copy address',
			'Cancel',
			'Save webhook',
		] )
	);
	await expect( dialog ).toBeVisible();
} );

test( 'a money confirmation focuses Cancel, cannot be dismissed while pending and shows the verified result', async ( {
	page,
} ) => {
	await openOverlays( page );
	await specimen( page, 'confirm-dialog' )
		.getByRole( 'button', { name: 'Refund order #10234' } )
		.click();
	const dialog = page.getByRole( 'alertdialog', {
		name: 'Refund 12.500 KWD?',
	} );
	await expect( dialog ).toBeVisible();
	await expect(
		dialog.getByRole( 'button', { name: 'Cancel' } )
	).toBeFocused();

	const confirm = dialog.getByRole( 'button', { name: 'Refund 12.500 KWD' } );
	await confirm.dblclick();
	await expect( confirm ).toHaveAttribute( 'aria-disabled', 'true' );
	await page.keyboard.press( 'Escape' );
	// A press on the backdrop, outside the popup.
	await page.mouse.click( 8, 120 );
	await expect( dialog ).toBeVisible();
	await expect( dialog.getByText( 'Verified' ) ).toHaveCount( 0 );

	await expect(
		dialog.getByText( 'UPayments confirmed the refund of 12.500 KWD.' )
	).toBeVisible();
	await expect( dialog.getByText( 'Verified' ) ).toBeVisible();
	const close = dialog.getByRole( 'button', { name: 'Close' } );
	await expect( close ).toBeFocused();
	await expect( close ).toHaveAccessibleDescription(
		'Verified UPayments confirmed the refund of 12.500 KWD.'
	);
	// It never closes on its own.
	await page.waitForTimeout( 1000 );
	await expect( dialog ).toBeVisible();
	await close.click();
	await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );
} );

test( 'a failed request shows the generic failed result', async ( {
	page,
} ) => {
	await openOverlays( page );
	await specimen( page, 'confirm-dialog' )
		.getByRole( 'button', { name: 'Capture order #10233' } )
		.click();
	const dialog = page.getByRole( 'alertdialog' );
	await dialog.getByRole( 'button', { name: 'Capture 4.000 KWD' } ).click();
	await expect(
		dialog.getByText(
			'The request did not complete. Refresh to see the current state before trying again.'
		)
	).toBeVisible();
	await expect( dialog.getByText( 'Not completed' ) ).toBeVisible();
	await expect( dialog.getByText( 'Verified' ) ).toHaveCount( 0 );
} );

test( 'tab arrow keys follow the reading direction', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await openOverlays( page );
	const tabs = specimen( page, 'tabs' );
	const first = tabs.getByRole( 'tab', { name: 'All payments' } );
	await first.focus();
	await page.keyboard.press( dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight' );
	const next = tabs.getByRole( 'tab', { name: /^Needs attention/ } );
	await expect( next ).toBeFocused();
	await expect( next ).toHaveAttribute( 'aria-selected', 'true' );
	// The tab row itself runs in the reading direction.
	const [ a, b ] = await Promise.all( [
		first.boundingBox(),
		next.boundingBox(),
	] );
	expect( a && b ).toBeTruthy();
	if ( a && b ) {
		expect( dir === 'rtl' ? b.x < a.x : b.x > a.x ).toBe( true );
	}
} );

test( 'numeric table cells are end-aligned and bidi-isolated', async ( {
	page,
} ) => {
	await openOverlays( page );
	const table = specimen( page, 'data-table' ).getByRole( 'table', {
		name: 'Recent payments',
	} );
	const amount = table.getByText( '12.500 KWD' );
	expect(
		await amount.evaluate( ( el ) => ( {
			tag: el.tagName.toLowerCase(),
			dir: el.getAttribute( 'dir' ),
			bidi: getComputedStyle( el ).unicodeBidi,
			nums: getComputedStyle( el ).fontVariantNumeric,
			align: getComputedStyle( el.closest( 'td' ) as Element ).textAlign,
		} ) )
	).toEqual( {
		tag: 'bdi',
		dir: 'ltr',
		bidi: 'isolate',
		nums: 'tabular-nums',
		align: 'end',
	} );
	const region = specimen( page, 'data-table' ).getByRole( 'region', {
		name: 'Recent payments',
	} );
	await expect( region ).toHaveAttribute( 'tabindex', '0' );
	const order = table.getByRole( 'columnheader', { name: 'Order' } );
	await expect( order ).toHaveAttribute( 'aria-sort', 'descending' );
	await order.getByRole( 'button' ).click();
	await expect( order ).toHaveAttribute( 'aria-sort', 'ascending' );
} );

test( 'no duplicate main landmark inside wp-admin', async ( { page } ) => {
	await page.goto( SHELL );
	await expect(
		page.locator( `${ APP_ROOT } .sup-app-shell` )
	).toBeVisible();
	await expect( page.locator( 'main, [role="main"]' ) ).toHaveCount( 1 );
	await openOverlays( page );
	await expect( page.locator( 'main, [role="main"]' ) ).toHaveCount( 1 );
	await expect(
		page.getByRole( 'navigation', { name: 'SUPCheckout sections' } )
	).toBeVisible();
} );

test( 'focused element is never obscured by the sticky header', async ( {
	page,
} ) => {
	await openOverlays( page );
	const buttons = specimen( page, 'focus-scroll' ).getByRole( 'button' );
	const count = await buttons.count();
	expect( count ).toBe( 24 );
	await buttons.first().focus();
	// Re-enter with the keyboard so focus scrolling is the browser's own.
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );

	const check = async ( step: string ) => {
		const { label, focused, header, adminBar } = await focusBoxes( page );
		expect(
			{ step, label, header: overlap( focused, header ) },
			'covered by the sticky header'
		).toEqual( { step, label, header: 0 } );
		expect(
			{ step, label, adminBar: overlap( focused, adminBar ) },
			'covered by the admin bar'
		).toEqual( { step, label, adminBar: 0 } );
	};
	await check( 'first' );
	for ( let i = 1; i < count; i++ ) {
		await page.keyboard.press( 'Tab' );
		await check( `tab ${ i }` );
	}
	// The page is scrolled now: the header is pinned under the admin bar.
	const { header, adminBar } = await focusBoxes( page );
	expect( Math.round( header.top ) ).toBe( Math.round( adminBar.bottom ) );
	expect( await page.evaluate( () => window.scrollY ) ).toBeGreaterThan( 0 );
	// Going back up is where a sticky header covers focus.
	for ( let i = 1; i < count; i++ ) {
		await page.keyboard.press( 'Shift+Tab' );
		await check( `shift+tab ${ i }` );
	}
	// The skip link takes focus to the content, clear of the header.
	await page.evaluate( () =>
		window.scrollTo( 0, document.body.scrollHeight )
	);
	await page.locator( `${ APP_ROOT } .sup-skip-link` ).focus();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#supcheckout-content' ) ).toBeFocused();
	const content = await page
		.locator( '#supcheckout-content' )
		.evaluate( ( el ) => el.getBoundingClientRect().top );
	const pinned = await page
		.locator( HEADER )
		.evaluate( ( el ) => el.getBoundingClientRect().bottom );
	expect( content ).toBeGreaterThanOrEqual( Math.floor( pinned ) );
} );

test( 'the command palette chunk loads and its strings are translated', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	const requests: string[] = [];
	page.on( 'request', ( request ) => requests.push( request.url() ) );
	const chunkResponses: number[] = [];
	page.on( 'response', ( response ) => {
		if (
			/\/chunks\/command-palette\.[a-f0-9]{8}\.js/.test( response.url() )
		) {
			chunkResponses.push( response.status() );
		}
	} );
	await page.goto( SHELL );
	const title = page.locator( `${ APP_ROOT } .sup-app-shell h1` );
	await expect( title ).toBeVisible();
	// Lazy: nothing of the palette panel loads before the palette opens.
	expect( requests.filter( ( url ) => url.includes( '/chunks/' ) ) ).toEqual(
		[]
	);

	const trigger = page.getByRole( 'button', { name: /^Search commands/ } );
	await expect( trigger ).toHaveAttribute( 'aria-keyshortcuts', 'Control+K' );
	// The key hint reads "Ctrl K" in both directions (rtlcss must not flip it).
	const hint = trigger.locator( 'kbd' );
	await expect( hint ).toHaveCSS( 'direction', 'ltr' );
	expect(
		await hint.evaluate( ( el ) => {
			const key = el.querySelector( '.sup-kbd__key' ) as HTMLElement;
			const range = document.createRange();
			range.selectNodeContents( el.firstChild as Node );
			return (
				range.getBoundingClientRect().left <
				key.getBoundingClientRect().left
			);
		} )
	).toBe( true );
	await trigger.focus();
	await page.keyboard.press( 'Control+K' );
	const palette = page.getByRole( 'dialog', { name: 'Command palette' } );
	await expect( palette ).toBeVisible();
	const input = palette.getByRole( 'combobox' );
	await expect( input ).toBeFocused();
	await expect( input ).toHaveAttribute(
		'placeholder',
		dir === 'rtl'
			? FIXTURE.chunks[ PALETTE_PLACEHOLDER ]
			: PALETTE_PLACEHOLDER
	);
	expect( chunkResponses ).toEqual( [ 200 ] );
	// The shell's registry is empty, and the palette says so.
	await expect( palette.getByText( 'No matching commands' ) ).toBeVisible();

	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect( trigger ).toBeFocused();
} );

test( 'the palette filters, runs a command once and returns focus', async ( {
	page,
} ) => {
	await openOverlays( page );
	await page.locator( `${ APP_ROOT } #supcheckout-content` ).focus();
	await page.keyboard.press( 'Control+K' );
	const palette = page.getByRole( 'dialog', { name: 'Command palette' } );
	const input = palette.getByRole( 'combobox', { name: 'Search commands' } );
	await expect( input ).toBeFocused();
	await input.fill( 'webhook' );
	await expect( palette.getByRole( 'option' ) ).toHaveText( [
		'Edit the webhook',
	] );
	await page.keyboard.press( 'Enter' );
	await expect( palette ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'dialog', { name: 'Edit webhook' } )
	).toBeVisible();
} );

test( 'every interactive overlay control is at least 24 by 24 CSS pixels', async ( {
	page,
} ) => {
	await openOverlays( page );
	const shell = `${ APP_ROOT } .sup-app-shell`;
	const sizes = await page
		.locator(
			// A tab panel is focusable so keyboard users reach its content,
			// but it is not a pointer target (2.5.8 covers targets).
			`${ shell } :is(${ INTERACTIVE }):not(.sup-skip-link, #supcheckout-content, .sup-radio-group__input, [role="tabpanel"])`
		)
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => {
				const box = node.getBoundingClientRect();
				return {
					control: `${ node.tagName.toLowerCase() } ${
						node.getAttribute( 'aria-label' ) ??
						node.textContent?.trim() ??
						''
					}`,
					width: box.width,
					height: box.height,
				};
			} )
		);
	expect( sizes.length ).toBeGreaterThan( 30 );
	expect(
		sizes.filter( ( size ) => size.width < 24 || size.height < 24 )
	).toEqual( [] );
} );

test( 'overlay gallery with dialog, confirm and palette open has no axe violations', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	const analyze = async ( scope: string ) => {
		await layerSettled( page );
		const results = await new AxeBuilder( { page } )
			.withTags( TAGS )
			.include( scope )
			.analyze();
		expect( results.violations ).toEqual( [] );
		const undecided = results.incomplete
			.filter( ( rule ) => rule.id === 'color-contrast' )
			.flatMap( ( rule ) =>
				rule.nodes.map( ( node ) => ( {
					target: node.target,
					html: node.html.slice( 0, 160 ),
					why: node.any.map( ( check ) => check.message ),
				} ) )
			);
		expect( undecided ).toEqual( [] );
	};
	for ( const theme of [ 'system', mode === 'dark' ? 'light' : 'dark' ] ) {
		await test.step( `theme=${ theme }`, async () => {
			await openOverlays( page, theme as 'system' | 'light' | 'dark' );
			await analyze( '.supcheckout-admin' );

			await openDialog( page );
			await analyze( PORTAL_LAYER );
			await page.keyboard.press( 'Escape' );
			await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );

			await specimen( page, 'confirm-dialog' )
				.getByRole( 'button', { name: 'Refund order #10234' } )
				.click();
			const confirm = page.getByRole( 'alertdialog' );
			await expect( confirm ).toBeVisible();
			await analyze( PORTAL_LAYER );
			await confirm
				.getByRole( 'button', { name: 'Refund 12.500 KWD' } )
				.click();
			await expect(
				confirm.getByRole( 'button', { name: 'Close' } )
			).toBeFocused();
			await analyze( PORTAL_LAYER );
			await page.keyboard.press( 'Escape' );
			await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );

			await page.keyboard.press( 'Control+K' );
			await expect(
				page
					.getByRole( 'dialog', { name: 'Command palette' } )
					.getByRole( 'option' )
					.first()
			).toBeVisible();
			await analyze( PORTAL_LAYER );
			await page.keyboard.press( 'Escape' );
		} );
	}
} );
