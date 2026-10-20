/**
 * Display and feedback in real wp-admin (SP1 Task 6, Review Focus 2 and 5):
 * the toast stack never obscures the focused element (WCAG 2.4.11), toasts
 * and tooltips render in the scoped portal layer above the admin bar with
 * the page's tokens, tooltips meet 1.4.13, live regions exist before their
 * text, reduced motion stops the skeleton, progress and toast motion, and
 * the gallery has no axe violations with a toast and a tooltip open.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

import { cssVar } from './support/css';
import { galleryUrl } from './support/env';
import { computedColor, projectMode } from './support/modes';

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';
const VIEWPORT = `${ PORTAL_LAYER } .sup-toast-viewport`;

const FEEDBACK_SPECIMENS = [
	'card-default',
	'badge-tones',
	'status-pill-statuses',
	'notice-tones',
	'notice-live',
	'empty-state',
	'skeleton-group',
	'progress-bar',
	'stepper',
	'toast-trigger',
	'tooltip',
	'focus-target-block-end',
];

const INTERACTIVE = 'button, input, select, textarea, a[href], [tabindex]';

async function openGallery(
	page: Page,
	extra: Record< string, string > = {}
): Promise< void > {
	await page.goto( galleryUrl( 'system', extra ) );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	await page.evaluate( () => document.fonts.ready );
}

const specimen = ( page: Page, name: string ): Locator =>
	page.locator( `${ APP_ROOT } [data-specimen="${ name }"]` );

/**
 * Focuses `target` with the keyboard (re-entering with Shift+Tab, Tab), so
 * `:focus-visible` follows keyboard rules and a tooltip opens on focus.
 *
 * @param page   The page.
 * @param target The element to focus.
 */
async function keyboardFocus( page: Page, target: Locator ): Promise< void > {
	await target.focus();
	await page.keyboard.press( 'Shift+Tab' );
	await page.keyboard.press( 'Tab' );
	await expect( target ).toBeFocused();
}

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

interface Box {
	left: number;
	top: number;
	right: number;
	bottom: number;
}

/**
 * @param page The page.
 * @return The focused element's box and the boxes of every visible toast.
 */
function focusAndToastBoxes(
	page: Page
): Promise< { focused: Box | null; toasts: Box[] } > {
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
		const active = document.activeElement;
		const toasts = Array.from(
			document.querySelectorAll( '.sup-portal-layer .sup-toast' )
		).filter( ( el ) => getComputedStyle( el ).display !== 'none' );
		return {
			focused: active && active !== document.body ? box( active ) : null,
			toasts: toasts.map( box ),
		};
	} );
}

const overlap = ( a: Box, b: Box ): number =>
	Math.max( 0, Math.min( a.right, b.right ) - Math.max( a.left, b.left ) ) *
	Math.max( 0, Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top ) );

const area = ( a: Box ): number => ( a.right - a.left ) * ( a.bottom - a.top );

const SKELETON_SPECIMEN = `${ APP_ROOT } [data-specimen="skeleton-group"]`;
const PROGRESS_SPECIMEN = `${ APP_ROOT } [data-specimen="progress-bar"]`;

/**
 * @param page     The page.
 * @param selector Element whose subtree (pseudo-elements included) is read.
 * @return Each running CSS animation as `name: property, property`, sorted.
 */
function animatedProperties(
	page: Page,
	selector: string
): Promise< string[] > {
	return page.evaluate( ( target ) => {
		const host = document.querySelector( target );
		if ( ! host ) {
			throw new Error( `no element matches ${ target }` );
		}
		const meta = new Set( [
			'offset',
			'computedOffset',
			'easing',
			'composite',
		] );
		return host
			.getAnimations( { subtree: true } )
			.map( ( animation ) => {
				const name =
					( animation as CSSAnimation ).animationName ?? 'transition';
				const properties = new Set< string >();
				const effect = animation.effect as KeyframeEffect | null;
				for ( const frame of effect?.getKeyframes() ?? [] ) {
					for ( const key of Object.keys( frame ) ) {
						if ( ! meta.has( key ) ) {
							properties.add( key );
						}
					}
				}
				return `${ name }: ${ [ ...properties ].sort().join( ', ' ) }`;
			} )
			.sort();
	}, selector );
}

test( 'feedback specimens render in a fixed DOM order after the controls', async ( {
	page,
} ) => {
	await openGallery( page );
	const names = await page
		.locator( `${ APP_ROOT } [data-specimen]` )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => node.getAttribute( 'data-specimen' ) ?? '' )
		);
	expect( names.slice( names.length - FEEDBACK_SPECIMENS.length ) ).toEqual(
		FEEDBACK_SPECIMENS
	);
	expect( names.indexOf( 'theme-toggle' ) ).toBe(
		names.length - FEEDBACK_SPECIMENS.length - 1
	);
} );

test( 'toast viewport never obscures the focused element', async ( {
	page,
} ) => {
	await openGallery( page, { pin: 'focus-target-block-end' } );
	const target = specimen( page, 'focus-target-block-end' ).getByRole(
		'button',
		{ name: 'Show a toast here' }
	);
	await target.focus();
	// The pinned target sits where the stack appears by default.
	for ( let shown = 1; shown <= 3; shown++ ) {
		await page.keyboard.press( 'Enter' );
		await expect(
			page.locator( `${ PORTAL_LAYER } .sup-toast` )
		).toHaveCount( shown );
		await expect( target ).toBeFocused();
		await layerSettled( page );
		const { focused, toasts } = await focusAndToastBoxes( page );
		expect( focused ).not.toBeNull();
		const own = focused as Box;
		const covered = toasts.reduce(
			( sum, toast ) => sum + overlap( own, toast ),
			0
		);
		// Not a pixel of the focused button is under a toast (2.4.11 and the
		// stricter 2.4.12), and it is visibly on screen.
		expect( { shown, covered } ).toEqual( { shown, covered: 0 } );
		expect( area( own ) - covered ).toBeGreaterThan( 0 );
	}
	await expect( page.locator( VIEWPORT ) ).not.toHaveAttribute(
		'data-sup-placement',
		'end'
	);
} );

test( 'the toast stack sits at the block end and the inline end', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await openGallery( page );
	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show error toast' } )
		.click();
	const toast = page.locator( `${ PORTAL_LAYER } .sup-toast` );
	await expect( toast ).toBeVisible();
	await expect( page.locator( VIEWPORT ) ).toHaveAttribute(
		'data-sup-placement',
		'end'
	);
	const { box, width, height } = await toast.evaluate( ( el ) => {
		const r = el.getBoundingClientRect();
		return {
			box: { left: r.left, right: r.right, bottom: r.bottom },
			width: window.innerWidth,
			height: window.innerHeight,
		};
	} );
	expect( height - box.bottom ).toBeLessThan( 64 );
	if ( dir === 'ltr' ) {
		expect( width - box.right ).toBeLessThan( 64 );
	} else {
		expect( box.left ).toBeLessThan( 64 );
	}
} );

test( 'toasts and tooltips render in the scoped portal layer above the admin bar', async ( {
	page,
} ) => {
	await openGallery( page );
	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show error toast' } )
		.click();
	const toast = page.locator( `${ PORTAL_LAYER } .sup-toast` );
	await expect( toast ).toBeVisible();
	await expect( toast ).toHaveCSS(
		'background-color',
		await computedColor(
			page,
			PORTAL_LAYER,
			'var(--sup-color-bg-surface-raised)'
		)
	);
	expect( await cssVar( page, PORTAL_LAYER, 'z-index' ) ).toBe( '100000' );

	const trigger = specimen( page, 'tooltip' ).getByRole( 'button', {
		name: 'Webhook URL',
		exact: true,
	} );
	await keyboardFocus( page, trigger );
	const tooltip = page.getByRole( 'tooltip' );
	await expect( tooltip ).toBeVisible();
	await layerSettled( page );
	expect(
		await tooltip.evaluate(
			( el ) => el.closest( '.sup-portal-layer' ) !== null
		)
	).toBe( true );
	// The inverted chip is the contrast-checked text pair, reversed.
	await expect( tooltip ).toHaveCSS(
		'color',
		await computedColor( page, PORTAL_LAYER, 'var(--sup-color-bg-surface)' )
	);
	await expect( tooltip ).toHaveCSS(
		'background-color',
		await computedColor( page, PORTAL_LAYER, 'var(--sup-color-fg-default)' )
	);
	// The admin bar never covers a tooltip near the top of the window.
	expect(
		await tooltip.evaluate( ( el ) => {
			const r = el.getBoundingClientRect();
			const hit = document.elementFromPoint(
				r.left + r.width / 2,
				r.top + r.height / 2
			);
			return hit !== null && el.contains( hit );
		} )
	).toBe( true );
} );

test( 'tooltips are hoverable, persistent and dismissible (WCAG 1.4.13)', async ( {
	page,
} ) => {
	await openGallery( page );
	const trigger = specimen( page, 'tooltip' ).getByRole( 'button', {
		name: 'Webhook URL',
		exact: true,
	} );
	await expect( trigger ).toHaveAccessibleDescription(
		'UPayments sends payment updates to this address.'
	);
	await trigger.hover();
	const tooltip = page.getByRole( 'tooltip' );
	await expect( tooltip ).toBeVisible();

	// Hoverable: the pointer moves onto the tooltip and it stays.
	const box = await tooltip.boundingBox();
	expect( box ).not.toBeNull();
	if ( box ) {
		await page.mouse.move( box.x + box.width / 2, box.y + box.height / 2, {
			steps: 8,
		} );
	}
	await page.waitForTimeout( 800 );
	await expect( tooltip ).toBeVisible();

	// Dismissible: Escape closes it without moving focus.
	await trigger.focus();
	await expect( tooltip ).toBeVisible();
	await page.keyboard.press( 'Escape' );
	await expect( page.getByRole( 'tooltip' ) ).toHaveCount( 0 );
	await expect( trigger ).toBeFocused();

	// The icon button's tooltip shows its name and does not repeat it.
	const copy = specimen( page, 'tooltip' ).getByRole( 'button', {
		name: 'Copy webhook URL',
	} );
	await keyboardFocus( page, copy );
	await expect(
		page.getByRole( 'tooltip', { name: 'Copy webhook URL' } )
	).toBeVisible();
	await expect( copy ).not.toHaveAttribute( 'aria-describedby' );
} );

test( 'a live status notice stays empty for frames before its message', async ( {
	page,
} ) => {
	await openGallery( page );
	// Sample the region once per animation frame from the click on, so the
	// test sees what the accessibility tree had time to see.
	await page.evaluate( () => {
		const samples: Array< { at: number; state: string } > = [];
		( window as unknown as { supSamples: typeof samples } ).supSamples =
			samples;
		const tick = ( at: number ) => {
			const region = document.querySelector(
				'[data-specimen="notice-live"] [role="status"]'
			);
			let state = 'none';
			if ( region ) {
				state = region.textContent ? 'text' : 'empty';
			}
			samples.push( { at, state } );
			if ( state !== 'text' ) {
				requestAnimationFrame( tick );
			}
		};
		requestAnimationFrame( tick );
	} );
	await specimen( page, 'notice-live' )
		.getByRole( 'button', { name: 'Test connection' } )
		.click();
	const status = specimen( page, 'notice-live' ).getByRole( 'status' );
	await expect( status ).toContainText( 'Connection works' );
	const samples = await page.evaluate(
		() =>
			(
				window as unknown as {
					supSamples: Array< { at: number; state: string } >;
				}
			 ).supSamples
	);
	const states = samples.map( ( sample ) => sample.state );
	const firstEmpty = states.indexOf( 'empty' );
	const firstText = states.indexOf( 'text' );
	// The region appeared empty, was painted empty for at least one frame,
	// and only then gained its text: never region and text together.
	expect( firstEmpty ).toBeGreaterThanOrEqual( 0 );
	expect( firstText ).toBeGreaterThan( firstEmpty + 1 );
	expect( states.slice( 0, firstEmpty ) ).not.toContain( 'text' );
	// …and it stayed empty for about LIVE_REGION_DELAY_MS (100 ms), not a
	// single React commit.
	expect(
		samples[ firstText ].at - samples[ firstEmpty ].at
	).toBeGreaterThanOrEqual( 80 );
	await expect(
		specimen( page, 'notice-live' ).getByRole( 'alert' )
	).toHaveCount( 0 );
} );

test( 'the skeleton group and the toast viewport are pre-mounted live regions', async ( {
	page,
} ) => {
	await openGallery( page );
	await expect(
		specimen( page, 'skeleton-group' ).getByRole( 'status' )
	).toHaveText( 'Loading payments…' );
	const viewport = page.locator( VIEWPORT );
	await expect( viewport ).toHaveAttribute( 'aria-live', 'polite' );
	await expect( viewport ).toHaveText( '' );
	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show success toast' } )
		.click();
	await expect( viewport ).toContainText( 'Settings saved' );
} );

test( 'reduced motion removes toast and skeleton animation', async ( {
	page,
} ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await openGallery( page );
	const skeleton = `${ APP_ROOT } [data-specimen="skeleton-group"] .sup-skeleton`;
	expect( await cssVar( page, skeleton, 'animation-name' ) ).toBe( 'none' );
	expect( await cssVar( page, skeleton, 'animation-duration' ) ).toMatch(
		/^0s(, 0s)*$/
	);
	const indeterminate = `${ APP_ROOT } [data-specimen="progress-bar"] [data-indeterminate] .sup-progress__indicator`;
	expect( await cssVar( page, indeterminate, 'animation-name' ) ).toBe(
		'none'
	);
	// Nothing moves in either specimen, pseudo-elements included.
	expect( await animatedProperties( page, SKELETON_SPECIMEN ) ).toEqual( [] );
	expect( await animatedProperties( page, PROGRESS_SPECIMEN ) ).toEqual( [] );

	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show success toast' } )
		.click();
	await expect(
		page.locator( `${ PORTAL_LAYER } .sup-toast` )
	).toBeVisible();
	expect(
		await cssVar(
			page,
			`${ PORTAL_LAYER } .sup-toast`,
			'transition-duration'
		)
	).toMatch( /^0s(, 0s)*$/ );
} );

test( 'skeleton and progress motion animates only opacity and translate', async ( {
	page,
} ) => {
	await page.emulateMedia( { reducedMotion: 'no-preference' } );
	await openGallery( page );
	// Compositor-friendly: no background-color, inset or size keyframes.
	const skeleton = await animatedProperties( page, SKELETON_SPECIMEN );
	expect( skeleton.length ).toBeGreaterThan( 0 );
	expect( new Set( skeleton ) ).toEqual(
		new Set( [ 'sup-skeleton-pulse: opacity' ] )
	);
	expect( await animatedProperties( page, PROGRESS_SPECIMEN ) ).toEqual( [
		'sup-progress-slide: translate',
	] );
} );

test( 'without reduced motion toasts transition', async ( { page } ) => {
	await page.emulateMedia( { reducedMotion: 'no-preference' } );
	await openGallery( page );
	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show success toast' } )
		.click();
	await expect(
		page.locator( `${ PORTAL_LAYER } .sup-toast` )
	).toBeVisible();
	expect(
		await cssVar(
			page,
			`${ PORTAL_LAYER } .sup-toast`,
			'transition-duration'
		)
	).not.toMatch( /^0s(, 0s)*$/ );
} );

test( 'every interactive feedback control is at least 24 by 24 CSS pixels', async ( {
	page,
} ) => {
	await openGallery( page );
	await specimen( page, 'toast-trigger' )
		.getByRole( 'button', { name: 'Show info toast' } )
		.click();
	await expect(
		page.locator( `${ PORTAL_LAYER } .sup-toast` )
	).toBeVisible();
	const selector = [
		...FEEDBACK_SPECIMENS.map(
			( name ) =>
				`${ APP_ROOT } [data-specimen="${ name }"] :is(${ INTERACTIVE })`
		),
		`${ PORTAL_LAYER } .sup-toast button`,
	].join( ', ' );
	const sizes = await page.locator( selector ).evaluateAll( ( nodes ) =>
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
	expect( sizes.length ).toBeGreaterThan( 8 );
	expect(
		sizes.filter( ( size ) => size.width < 24 || size.height < 24 )
	).toEqual( [] );
} );

test( 'feedback gallery with a toast and a tooltip open has no axe violations', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	for ( const theme of [ 'system', mode === 'dark' ? 'light' : 'dark' ] ) {
		await test.step( `theme=${ theme }`, async () => {
			await page.goto(
				galleryUrl( theme as 'system' | 'light' | 'dark' )
			);
			await expect(
				page.locator( '[data-gallery-ready]' )
			).toBeVisible();
			await page.evaluate( () => document.fonts.ready );
			await specimen( page, 'toast-trigger' )
				.getByRole( 'button', { name: 'Show error toast' } )
				.click();
			await specimen( page, 'toast-trigger' )
				.getByRole( 'button', { name: 'Show info toast' } )
				.click();
			await specimen( page, 'notice-live' )
				.getByRole( 'button', { name: 'Test connection' } )
				.click();
			// A one-line tooltip: axe cannot decide the contrast of floating
			// text whose lines sit over different page backgrounds (it reports
			// elmPartiallyObscuring), so the wrapping Webhook URL tooltip's
			// colors are asserted as tokens in the portal-layer test instead.
			await keyboardFocus(
				page,
				specimen( page, 'tooltip' ).getByRole( 'button', {
					name: 'Copy webhook URL',
				} )
			);
			await expect(
				page.getByRole( 'tooltip', { name: 'Copy webhook URL' } )
			).toBeVisible();
			await expect(
				page.locator( `${ PORTAL_LAYER } .sup-toast` )
			).toHaveCount( 2 );
			await layerSettled( page );

			const results = await new AxeBuilder( { page } )
				.withTags( TAGS )
				.include( '.supcheckout-admin' )
				.analyze();
			expect( results.violations ).toEqual( [] );
			const undecided = results.incomplete
				.filter( ( rule ) => rule.id === 'color-contrast' )
				.flatMap( ( rule ) =>
					rule.nodes.map( ( node ) => node.target )
				);
			expect( undecided ).toEqual( [] );
		} );
	}
} );
