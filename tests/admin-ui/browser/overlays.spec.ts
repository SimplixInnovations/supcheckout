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
	theme: 'light' | 'dark' | 'system' = 'system',
	extra: Record< string, string > = {}
): Promise< void > {
	await page.goto( galleryUrl( theme, { view: 'overlays', ...extra } ) );
	await expect( page.locator( '[data-overlays-ready]' ) ).toBeVisible();
	await page.evaluate( () => document.fonts.ready );
}

/** The test mu-plugin drops core's command palette from the gallery page. */
const WITHOUT_CORE_PALETTE = { sup_core_commands: 'off' };

/**
 * @param page The page.
 * @return Whether WordPress core's command palette is on the page.
 */
function hasCorePalette( page: Page ): Promise< boolean > {
	return page.evaluate( () => {
		const wp = ( window as unknown as { wp?: Record< string, unknown > } )
			.wp;
		const data = wp?.data as
			{ select?: ( store: string ) => unknown } | undefined;
		let registered = 0;
		try {
			const store = data?.select?.( 'core/commands' ) as
				{ getCommands?: () => unknown[] } | undefined;
			registered = store?.getCommands?.()?.length ?? 0;
		} catch {
			registered = 0;
		}
		return Boolean( wp?.coreCommands ) || registered > 0;
	} );
}

async function openPaletteWithTrigger( page: Page ): Promise< Locator > {
	await page.getByRole( 'button', { name: /^Search commands/ } ).click();
	const palette = page.getByRole( 'dialog', { name: 'Command palette' } );
	await expect( palette ).toBeVisible();
	return palette;
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
	// The page behind the modal is inert while the dialog is open…
	const inertBehind = () =>
		page.evaluate( () =>
			[
				'#wpadminbar a',
				'#adminmenu a',
				'[data-specimen="dialog"] button',
			].map(
				( selector ) =>
					document.querySelector( selector )?.closest( '[inert]' ) !==
						null && document.querySelector( selector ) !== null
			)
		);
	await expect.poll( inertBehind ).toEqual( [ true, true, true ] );
	// …but wp.a11y.speak()'s live regions keep speaking.
	for ( const id of [ 'a11y-speak-polite', 'a11y-speak-assertive' ] ) {
		const region = page.locator( `#${ id }` );
		await expect( region ).toHaveCount( 1 );
		expect(
			await region.evaluate( ( el ) => el.closest( '[inert]' ) === null ),
			id
		).toBe( true );
	}
	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect.poll( inertBehind ).toEqual( [ false, false, false ] );
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
	// An unsorted sortable header shows a quiet, decorative sort hint.
	const amountHint = table
		.getByRole( 'columnheader', { name: 'Amount' } )
		.locator( '.sup-table__sort-icon' );
	await expect( amountHint ).toHaveAttribute( 'aria-hidden', 'true' );
	const resting = Number(
		await amountHint.evaluate( ( el ) => getComputedStyle( el ).opacity )
	);
	expect( resting ).toBeGreaterThan( 0 );
	expect( resting ).toBeLessThan( 1 );
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

	// WordPress core's command palette owns Mod+K on both versions: ours
	// announces no shortcut and shows no key hint, and opens from the trigger.
	expect( await hasCorePalette( page ) ).toBe( true );
	const trigger = page.getByRole( 'button', { name: /^Search commands/ } );
	await expect( trigger ).not.toHaveAttribute( 'aria-keyshortcuts' );
	await expect( trigger.locator( 'kbd' ) ).toHaveCount( 0 );
	await trigger.focus();
	await trigger.press( 'Enter' );
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
	await expect( palette.getByText( 'No commands yet' ) ).toBeVisible();

	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'dialog' ) ).toHaveCount( 0 );
	await expect( trigger ).toBeFocused();
} );

test( 'the palette filters, runs a command once and returns focus', async ( {
	page,
} ) => {
	await openOverlays( page );
	const palette = await openPaletteWithTrigger( page );
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
					target: String( node.target[ 0 ] ),
					html: node.html.slice( 0, 160 ),
					why: node.any.map( ( check ) => check.data?.messageKey ),
				} ) )
			);
		// axe compares the element stack under every line of a text, page
		// included. Under a modal, a wrapping line sits over different page
		// areas behind the backdrop, so axe leaves it undecided
		// (elmPartiallyObscuring), as Task 6 found for the wrapping
		// tooltip. Only such nodes inside the open modal may stay
		// undecided, and each is measured here instead: its text color
		// against the nearest opaque background must reach 4.5:1.
		for ( const node of undecided ) {
			expect( node.why, node.html ).toEqual( [
				'elmPartiallyObscuring',
			] );
			const measured = await page.evaluate( ( selector ) => {
				const el = document.querySelector( selector );
				if ( ! el ) {
					throw new Error( `no element matches ${ selector }` );
				}
				const rgb = ( value: string ) =>
					( value.match( /[\d.]+/g ) ?? [] ).map( Number );
				const luminance = ( [ r, g, b ]: number[] ) => {
					const channel = ( c: number ) => {
						const v = c / 255;
						return v <= 0.04045
							? v / 12.92
							: ( ( v + 0.055 ) / 1.055 ) ** 2.4;
					};
					return (
						0.2126 * channel( r ) +
						0.7152 * channel( g ) +
						0.0722 * channel( b )
					);
				};
				const COLOR = /^rgba?\(/;
				let bg: number[] | null = null;
				let translucent = false;
				let formats = COLOR.test( getComputedStyle( el ).color );
				const textAlpha = rgb( getComputedStyle( el ).color )[ 3 ] ?? 1;
				for (
					let at: Element | null = el;
					at && ! bg;
					at = at.parentElement
				) {
					const css = getComputedStyle( at ).backgroundColor;
					formats = formats && COLOR.test( css );
					const value = rgb( css );
					const alpha = value.length === 3 ? 1 : value[ 3 ];
					if ( alpha === 1 ) {
						bg = value.slice( 0, 3 );
					} else if ( alpha > 0 ) {
						// A tint over the background: the measured pair
						// would not be the painted one.
						translucent = true;
					}
				}
				const fg = rgb( getComputedStyle( el ).color ).slice( 0, 3 );
				const [ hi, lo ] = [
					luminance( fg ),
					luminance( bg ?? [ 0, 0, 0 ] ),
				].sort( ( a, b ) => b - a );
				return {
					inModal:
						el.closest(
							'[role="dialog"], [role="alertdialog"]'
						) !== null,
					opaque: bg !== null,
					translucent: translucent || textAlpha < 1,
					formats,
					ratio: ( hi + 0.05 ) / ( lo + 0.05 ),
				};
			}, node.target );
			expect( measured.inModal, node.html ).toBe( true );
			expect( measured.opaque, node.html ).toBe( true );
			expect( measured.translucent, node.html ).toBe( false );
			expect( measured.formats, node.html ).toBe( true );
			expect( measured.ratio, node.html ).toBeGreaterThanOrEqual( 4.5 );
		}
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

			const palette = await openPaletteWithTrigger( page );
			await expect( palette.getByRole( 'option' ).first() ).toBeVisible();
			await analyze( PORTAL_LAYER );
			await page.keyboard.press( 'Escape' );
		} );
	}
} );

test( "without core's command palette Mod+K opens ours, never over a pending confirmation", async ( {
	page,
} ) => {
	await openOverlays( page, 'system', WITHOUT_CORE_PALETTE );
	expect( await hasCorePalette( page ) ).toBe( false );
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
	await page.locator( `${ APP_ROOT } #supcheckout-content` ).focus();
	await page.keyboard.press( 'Control+K' );
	const palette = page.getByRole( 'dialog', { name: 'Command palette' } );
	await expect( palette ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( palette ).toHaveCount( 0 );

	// A pending money confirmation: Mod+K and Enter change nothing.
	await specimen( page, 'confirm-dialog' )
		.getByRole( 'button', { name: 'Refund order #10234' } )
		.click();
	const confirm = page.getByRole( 'alertdialog' );
	await confirm.getByRole( 'button', { name: 'Refund 12.500 KWD' } ).click();
	await expect(
		confirm.getByRole( 'button', { name: 'Refund 12.500 KWD' } )
	).toHaveAttribute( 'aria-disabled', 'true' );
	await page.keyboard.press( 'Control+K' );
	await page.keyboard.press( 'Enter' );
	await expect( palette ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'dialog', { name: 'Edit webhook' } )
	).toHaveCount( 0 );
	await expect(
		confirm.getByText( 'UPayments confirmed the refund of 12.500 KWD.' )
	).toBeVisible();
	await expect( palette ).toHaveCount( 0 );
} );

test( 'a header that wraps onto more rows still never covers the focused element', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 900, height: 700 } );
	await openOverlays( page );
	const header = page.locator( HEADER );
	const before = ( await header.boundingBox() )?.height ?? 0;
	// A long translated title wraps the header onto more rows.
	await page.locator( `${ HEADER } h1` ).evaluate( ( el ) => {
		el.textContent =
			'Payments, refunds and pay links for every UPayments account of this store';
	} );
	await expect
		.poll( async () => ( await header.boundingBox() )?.height ?? 0 )
		.toBeGreaterThan( before + 20 );
	// The ResizeObserver reports after layout, and the wrapped title may
	// settle over more than one frame (font fallback in Arabic): wait for the
	// token to catch up with the rendered height.
	await expect
		.poll(
			() =>
				header.evaluate( ( el ) =>
					Math.abs(
						parseFloat(
							getComputedStyle( el ).getPropertyValue(
								'--sup-app-shell-header-height'
							)
						) - el.getBoundingClientRect().height
					)
				),
			{ message: 'measured header height token' }
		)
		.toBeLessThan( 1.5 );

	const buttons = specimen( page, 'focus-scroll' ).getByRole( 'button' );
	const count = await buttons.count();
	await buttons.last().focus();
	await page.keyboard.press( 'Tab' );
	await page.keyboard.press( 'Shift+Tab' );
	for ( let i = 1; i < count; i++ ) {
		await page.keyboard.press( 'Shift+Tab' );
		const { label, focused, header: box } = await focusBoxes( page );
		expect(
			{ step: i, label, header: overlap( focused, box ) },
			'covered by the wrapped sticky header'
		).toEqual( { step: i, label, header: 0 } );
	}
} );

test( 'a dialog title that starts with a Latin name keeps the page direction', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await openOverlays( page );
	const dialog = await openDialog( page );
	const title = dialog.locator( '.sup-dialog__title' );
	await expect( title ).toHaveCSS( 'direction', dir );
	await expect( title ).not.toHaveCSS( 'unicode-bidi', 'plaintext' );
	// "UPayments" first, then Arabic. The paragraph keeps the page
	// direction: in RTL the Latin name is the start of the line (on the
	// right), in LTR it is on the left.
	const order = await title.evaluate( ( el ) => {
		el.textContent =
			'UPayments \u0639\u0646\u0648\u0627\u0646 \u0627\u0644\u0625\u0634\u0639\u0627\u0631\u0627\u062a';
		const text = el.firstChild as Text;
		const latin = document.createRange();
		latin.setStart( text, 0 );
		latin.setEnd( text, 9 );
		const arabic = document.createRange();
		arabic.setStart( text, 10 );
		arabic.setEnd( text, text.length );
		return {
			latin: latin.getBoundingClientRect().left,
			arabic: arabic.getBoundingClientRect().left,
		};
	} );
	if ( dir === 'rtl' ) {
		expect( order.latin ).toBeGreaterThan( order.arabic );
	} else {
		expect( order.latin ).toBeLessThan( order.arabic );
	}
} );

test( "while a SUPCheckout modal is open Mod+K never reaches WordPress core's command palette", async ( {
	page,
} ) => {
	await openOverlays( page );
	const coreMenu = page.locator( '.commands-command-menu' );
	// Core's palette is mounted and owns Mod+K on this page.
	await page.locator( `${ APP_ROOT } #supcheckout-content` ).focus();
	await page.keyboard.press( 'Control+K' );
	await expect( coreMenu ).toHaveCount( 1 );
	await page.keyboard.press( 'Escape' );
	await expect( coreMenu ).toHaveCount( 0 );

	// A refund is pending: Mod+K must not open core's palette over it.
	await specimen( page, 'confirm-dialog' )
		.getByRole( 'button', { name: 'Refund order #10234' } )
		.click();
	const confirm = page.getByRole( 'alertdialog' );
	await expect( confirm ).toBeVisible();
	await confirm.getByRole( 'button', { name: 'Refund 12.500 KWD' } ).click();
	await expect(
		confirm.getByRole( 'button', { name: 'Refund 12.500 KWD' } )
	).toHaveAttribute( 'aria-disabled', 'true' );
	await page.keyboard.press( 'Control+K' );
	await page.waitForTimeout( 200 );
	await expect( coreMenu ).toHaveCount( 0 );
	expect(
		await page.evaluate(
			() =>
				// eslint-disable-next-line @wordpress/no-global-active-element -- runs inside the page.
				document.activeElement?.closest( '[role="alertdialog"]' ) !==
				null
		)
	).toBe( true );
	const close = confirm.getByRole( 'button', { name: 'Close' } );
	await expect( close ).toBeFocused();
	await close.click();
	await expect( page.getByRole( 'alertdialog' ) ).toHaveCount( 0 );

	// With every SUPCheckout modal closed, Mod+K is core's again.
	await page.keyboard.press( 'Control+K' );
	await expect( coreMenu ).toHaveCount( 1 );
	await page.keyboard.press( 'Escape' );
} );

/**
 * @param target An element.
 * @return Whether it, or an ancestor, is aria-hidden, and whether inert.
 */
function hiddenState(
	target: Locator
): Promise< { ariaHidden: boolean; inert: boolean } > {
	return target.evaluate( ( node ) => ( {
		ariaHidden: node.closest( '[aria-hidden="true"]' ) !== null,
		inert: node.closest( '[inert]' ) !== null,
	} ) );
}

test( 'live Notices behind an open ConfirmDialog stay audible: never aria-hidden, never inert', async ( {
	page,
} ) => {
	await openOverlays( page, 'system', { notice: 'live' } );
	const confirm = specimen( page, 'confirm-dialog' );
	const status = confirm.locator( '.sup-notice__content[role="status"]' );
	const alert = confirm.locator( '.sup-notice__content[role="alert"]' );
	await expect( status ).toHaveText( /\S/ );
	await expect( alert ).toHaveText( /\S/ );
	// A CSS locator: role queries skip the trigger once it is aria-hidden.
	const trigger = specimen( page, 'dialog' ).locator( 'button' ).first();
	await confirm
		.getByRole( 'button', { name: 'Refund order #10234' } )
		.click();
	await expect(
		page.getByRole( 'alertdialog', { name: 'Refund 12.500 KWD?' } )
	).toBeVisible();
	// Base UI hides the page one frame after opening; the inert hold follows.
	await expect
		.poll( () => hiddenState( trigger ) )
		.toEqual( { ariaHidden: true, inert: true } );
	for ( const region of [ status, alert ] ) {
		expect( await hiddenState( region ) ).toEqual( {
			ariaHidden: false,
			inert: false,
		} );
	}
	// Still in the accessibility tree: role queries skip hidden nodes.
	await expect(
		page.getByRole( 'status' ).filter( {
			hasText: 'Refunds sync with UPayments every minute.',
		} )
	).toHaveCount( 1 );
	await expect(
		page.getByRole( 'alert' ).filter( {
			hasText: 'The last refund sync failed.',
		} )
	).toHaveCount( 1 );
} );

/*
 * WordPress's admin-bar.css gives `html` a scroll-padding-top of the admin
 * bar height, which already clears the bar for focus scrolling; a theme,
 * plugin or browser setting can drop it. The shell's own scroll-margin must
 * clear the bar either way, so the test runs with and without it.
 */
for ( const pagePadding of [ 'kept', 'removed' ] as const ) {
	test( `between 601 and 782 px, Shift+Tab into the header and the skip link never lands under the admin bar (page scroll-padding ${ pagePadding })`, async ( {
		page,
	} ) => {
		await page.setViewportSize( { width: 700, height: 600 } );
		await openOverlays( page );
		// WordPress keeps its 46 px admin bar fixed in this band.
		expect(
			await page
				.locator( '#wpadminbar' )
				.evaluate( ( node ) => getComputedStyle( node ).position )
		).toBe( 'fixed' );
		if ( pagePadding === 'removed' ) {
			await page.evaluate( () => {
				document.documentElement.style.scrollPaddingBlockStart = '0px';
			} );
		}
		const header = page.locator( HEADER );
		// Tab order, backwards: the first nav link, the checked Appearance
		// radio, the palette trigger, the screen action, the skip link.
		const order: Locator[] = [
			page.locator( `${ APP_ROOT } .sup-app-shell__nav-link` ).first(),
			header.locator( 'input[type="radio"]:checked' ),
			header.getByRole( 'button', { name: /^Search commands/ } ),
			header.getByRole( 'button', { name: 'Export CSV' } ),
			page.locator( `${ APP_ROOT } .sup-skip-link` ),
		];
		const results: Array< {
			target: string;
			top: number;
			bar: number;
		} > = [];
		for ( let index = 1; index < order.length; index += 1 ) {
			const target = order[ index ];
			// The target sits in the viewport but under the admin bar (8 px
			// from the top).
			await target.evaluate( ( node ) => {
				window.scrollBy( 0, node.getBoundingClientRect().top - 8 );
			} );
			await order[ index - 1 ].evaluate( ( node ) =>
				( node as HTMLElement ).focus( { preventScroll: true } )
			);
			await page.keyboard.press( 'Shift+Tab' );
			await expect( target ).toBeFocused();
			results.push(
				await target.evaluate( ( node ) => ( {
					target:
						node.getAttribute( 'aria-label' ) ??
						node.textContent?.trim() ??
						node.tagName,
					top: node.getBoundingClientRect().top,
					bar:
						document
							.getElementById( 'wpadminbar' )
							?.getBoundingClientRect().bottom ?? 0,
				} ) )
			);
		}
		expect( results ).toHaveLength( 4 );
		expect( results.filter( ( entry ) => entry.top < entry.bar ) ).toEqual(
			[]
		);
	} );
}

for ( const size of [
	{ width: 320, height: 256 },
	{ width: 683, height: 330 },
] ) {
	test( `at ${ size.width }x${ size.height } a long dialog's close and footer buttons and the palette's Close can be scrolled into view`, async ( {
		page,
	} ) => {
		await page.setViewportSize( size );
		await openOverlays( page, 'system', {
			labels: 'long',
			...WITHOUT_CORE_PALETTE,
		} );
		await specimen( page, 'dialog' )
			.getByRole( 'button', { name: 'Edit webhook' } )
			.click();
		const dialog = page.getByRole( 'dialog', { name: 'Edit webhook' } );
		await expect( dialog ).toBeVisible();
		await layerSettled( page );
		const targets = [
			dialog.getByRole( 'button', { name: 'Close dialog' } ),
			...( await dialog
				.locator( '.sup-dialog__footer' )
				.getByRole( 'button' )
				.all() ),
		];
		expect( targets ).toHaveLength( 3 );
		for ( const target of targets ) {
			await target.scrollIntoViewIfNeeded();
			await expect( target ).toBeInViewport( { ratio: 1 } );
		}
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toHaveCount( 0 );

		const palette = await openPaletteWithTrigger( page );
		await expect( palette.locator( '.sup-palette__item' ) ).toHaveCount(
			5
		);
		await layerSettled( page );
		const close = palette.getByRole( 'button', { name: /^Close/ } );
		await close.scrollIntoViewIfNeeded();
		await expect( close ).toBeInViewport( { ratio: 1 } );
		await expect(
			palette.getByRole( 'combobox', { name: 'Search commands' } )
		).toBeInViewport( { ratio: 1 } );
	} );
}

test( 'long dialog footer labels wrap inside the dialog at 320 px', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 320, height: 640 } );
	await openOverlays( page, 'system', { labels: 'long' } );
	await specimen( page, 'dialog' )
		.getByRole( 'button', { name: 'Edit webhook' } )
		.click();
	const dialog = page.getByRole( 'dialog', { name: 'Edit webhook' } );
	await expect( dialog ).toBeVisible();
	await layerSettled( page );
	const report = await dialog.evaluate( ( node ) => {
		const box = node.getBoundingClientRect();
		return Array.from(
			node.querySelectorAll( '.sup-dialog__footer .sup-button' )
		).map( ( button ) => {
			const rect = button.getBoundingClientRect();
			return {
				label: button.textContent?.trim() ?? '',
				inside:
					rect.left >= box.left - 0.5 &&
					rect.right <= box.right + 0.5,
				clipped: button.scrollWidth > button.clientWidth + 1,
				wraps: rect.height > 40,
			};
		} );
	} );
	expect( report ).toHaveLength( 2 );
	for ( const entry of report ) {
		expect( entry ).toEqual( {
			label: entry.label,
			inside: true,
			clipped: false,
			wraps: true,
		} );
	}
	expect(
		await dialog.evaluate(
			( node ) => node.scrollWidth <= node.clientWidth + 1
		)
	).toBe( true );
} );

test( 'under forced colors the highlighted palette row keeps a visible inline-start bar in a system color', async ( {
	page,
} ) => {
	await page.emulateMedia( { forcedColors: 'active' } );
	await openOverlays( page, 'system', WITHOUT_CORE_PALETTE );
	const palette = await openPaletteWithTrigger( page );
	const highlighted = palette.locator(
		'.sup-palette__item[data-highlighted]'
	);
	await expect( highlighted ).toHaveCount( 1 );
	const bar = await highlighted.evaluate( ( item ) => {
		// System colors resolve to the forced palette's real values.
		const probe = item.ownerDocument.createElement( 'span' );
		probe.style.color = 'HighlightText';
		probe.style.backgroundColor = 'Highlight';
		item.append( probe );
		const system = getComputedStyle( probe );
		const expected = {
			highlightText: system.color,
			highlight: system.backgroundColor,
		};
		probe.remove();
		const before = getComputedStyle( item, '::before' );
		return {
			content: before.content,
			color: before.backgroundColor,
			width: parseFloat( before.width ),
			height: parseFloat( before.height ),
			rowBackground: getComputedStyle( item ).backgroundColor,
			...expected,
		};
	} );
	expect( bar.content ).not.toBe( 'none' );
	expect( bar.width ).toBeGreaterThanOrEqual( 2 );
	expect( bar.height ).toBeGreaterThan( 0 );
	expect( bar.color ).toBe( bar.highlightText );
	expect( bar.rowBackground ).toBe( bar.highlight );
	expect( bar.color ).not.toBe( bar.rowBackground );
} );
