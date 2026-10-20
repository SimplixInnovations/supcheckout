/**
 * Actions and form controls in real wp-admin (SP1 Task 5, Review Focus 3):
 * WordPress admin form CSS never reaches our controls on WP 6.9 or 7.1,
 * targets are at least 24 px, the keyboard reaches every control in order
 * with a visible focus ring (also under forced colors), the controls follow
 * the inline direction in RTL, ThemeToggle drives the scoped roots, and the
 * write-only SecretInput never puts a secret in the page.
 */
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { cssVar, token } from './support/css';
import { galleryUrl } from './support/env';
import { computedColor, projectMode } from './support/modes';

const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];
const APP_ROOT = '#wpbody .supcheckout-admin';
const PORTAL_LAYER = 'body > .supcheckout-admin.sup-portal-layer';

const CONTROL_SPECIMENS = [
	'button-variants',
	'button-states',
	'icon-button',
	'text-input-default',
	'text-input-error',
	'textarea-default',
	'secret-input-saved',
	'secret-input-new',
	'select-default',
	'checkbox-checked',
	'checkbox-states',
	'radio-group-list',
	'radio-group-segmented',
	'toggle-default',
	'theme-toggle',
];

/** Tab order through the control specimens: `specimen | accessible name`. */
const TAB_ORDER = [
	'button-variants | Save changes',
	'button-variants | Test connection',
	'button-variants | View logs',
	'button-variants | Revoke key',
	'button-states | Sync now',
	'button-states | Open UPayments dashboard',
	'button-states | Save settings',
	'icon-button | Dismiss',
	'icon-button | Copy merchant ID',
	'icon-button | More actions',
	'text-input-default | Store name',
	'text-input-error | Merchant ID (required)',
	'textarea-default | Payment page note',
	'secret-input-saved | Replace key',
	'secret-input-new | Sandbox API key',
	'select-default | Environment',
	'checkbox-checked | Save cards for returning customers',
	'checkbox-states | Email me a daily summary',
	'checkbox-states | Select all orders',
	'radio-group-list | Redirect to UPayments',
	'radio-group-segmented | Comfortable',
	'toggle-default | Enable test mode',
	'toggle-default | Send payment receipts',
	'theme-toggle | Match system',
];

const INTERACTIVE =
	'button, input, select, textarea, a[href], [role="switch"], [tabindex]';
const SENTINEL = 'sk_test_BROWSER_SENTINEL_51d7';

async function openGallery(
	page: Page,
	theme: 'light' | 'dark' | 'system' = 'system'
): Promise< void > {
	await page.goto( galleryUrl( theme ) );
	await expect( page.locator( '[data-gallery-ready]' ) ).toBeVisible();
	await page.evaluate( () => document.fonts.ready );
}

const specimen = ( name: string ) =>
	`${ APP_ROOT } [data-specimen="${ name }"]`;

interface FocusState {
	where: string;
	ring: { width: string; style: string; color: string; offset: string };
}

/**
 * @param page The page.
 * @return Where focus is (`specimen | name`) and the focus ring it draws:
 *         on the focused element, or on its next sibling for the transparent
 *         native inputs whose sibling draws the control.
 */
function focusState( page: Page ): Promise< FocusState > {
	return page.evaluate( () => {
		// eslint-disable-next-line @wordpress/no-global-active-element -- runs inside the page, with no node ref to start from.
		const active = document.activeElement as HTMLElement | null;
		const host = active?.closest( '[data-specimen]' );
		if ( ! active || ! host ) {
			return {
				where: `outside | ${ active?.tagName ?? 'none' }`,
				ring: { width: '', style: '', color: '', offset: '' },
			};
		}
		const input = active as HTMLInputElement;
		const name = (
			active.getAttribute( 'aria-label' ) ??
			input.labels?.[ 0 ]?.textContent ??
			active.textContent ??
			''
		)
			.replace( /\s+/g, ' ' )
			.trim();
		const own = getComputedStyle( active );
		const drawn =
			own.outlineStyle !== 'none' || ! active.nextElementSibling
				? active
				: ( active.nextElementSibling as HTMLElement );
		const style = getComputedStyle( drawn );
		return {
			where: `${ host.getAttribute( 'data-specimen' ) } | ${ name }`,
			ring: {
				width: style.outlineWidth,
				style: style.outlineStyle,
				color: style.outlineColor,
				offset: style.outlineOffset,
			},
		};
	} );
}

/**
 * Focuses the first control with the keyboard and tabs through every control.
 *
 * @param page The page.
 * @return The focus state at each stop, in order.
 */
async function tabThroughControls( page: Page ): Promise< FocusState[] > {
	const first = page
		.locator( specimen( 'button-variants' ) )
		.getByRole( 'button', { name: 'Save changes' } );
	await first.focus();
	// Re-enter with the keyboard so :focus-visible follows keyboard rules.
	await page.keyboard.press( 'Shift+Tab' );
	const states: FocusState[] = [];
	for ( let stop = 0; stop < TAB_ORDER.length + 1; stop++ ) {
		await page.keyboard.press( 'Tab' );
		states.push( await focusState( page ) );
	}
	return states;
}

test( 'control specimens render in a fixed DOM order after the foundations', async ( {
	page,
} ) => {
	await openGallery( page );
	const names = await page
		.locator( `${ APP_ROOT } [data-specimen]` )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => node.getAttribute( 'data-specimen' ) ?? '' )
		);
	const isFoundation = ( name: string ) => name.startsWith( 'foundations-' );
	const foundations = names.filter( isFoundation );
	// Every foundations specimen comes first, then exactly the controls;
	// later groups (feedback.spec.ts) assert their own order after these.
	expect( names.slice( 0, foundations.length ) ).toEqual( foundations );
	expect(
		names.slice(
			foundations.length,
			foundations.length + CONTROL_SPECIMENS.length
		)
	).toEqual( CONTROL_SPECIMENS );
} );

test( 'form controls ignore WordPress admin form styles', async ( {
	page,
} ) => {
	await openGallery( page );
	const controlMd = await token( page, '--sup-size-control-md' );
	const radius = await token( page, '--sup-radius-control' );
	expect( controlMd ).toBe( '36px' );

	// forms.css draws its checkmark and radio dot in ::before.
	const checked = page.locator( `${ specimen( 'checkbox-checked' ) } input` );
	await expect( checked ).toBeChecked();
	expect(
		await checked.evaluate( ( el ) => [
			getComputedStyle( el, '::before' ).content,
			getComputedStyle( el, '::after' ).content,
			getComputedStyle( el ).opacity,
		] )
	).toEqual( [ 'none', 'none', '0' ] );
	const radio = page
		.locator( `${ specimen( 'radio-group-list' ) } input:checked` )
		.first();
	expect(
		await radio.evaluate(
			( el ) => getComputedStyle( el, '::before' ).content
		)
	).toBe( 'none' );
	// forms.css raises disabled checkboxes to opacity .7.
	expect(
		await page
			.locator( `${ specimen( 'checkbox-states' ) } input:disabled` )
			.evaluate( ( el ) => getComputedStyle( el ).opacity )
	).toBe( '0' );
	// The visible box is ours: the accent fill, not WordPress's white box.
	expect(
		await checked.evaluate(
			( el ) =>
				getComputedStyle( el.nextElementSibling as Element )
					.backgroundColor
		)
	).toBe(
		await computedColor( page, APP_ROOT, 'var(--sup-color-accent-bg)' )
	);

	// Text input: our height, radius, border, padding and no WP inset shadow.
	const input = `${ specimen( 'text-input-default' ) } input`;
	expect( await cssVar( page, input, 'min-height' ) ).toBe( controlMd );
	expect( await cssVar( page, input, 'height' ) ).toBe( controlMd );
	expect( await cssVar( page, input, 'border-top-left-radius' ) ).toBe(
		radius
	);
	expect( await cssVar( page, input, 'box-shadow' ) ).toBe( 'none' );
	expect( await cssVar( page, input, 'border-top-color' ) ).toBe(
		await computedColor( page, APP_ROOT, 'var(--sup-input-border-color)' )
	);
	expect( await cssVar( page, input, 'padding-inline-start' ) ).toBe(
		await token( page, '--sup-input-padding-inline' )
	);

	// Select: WordPress's chevron background is gone; ours is an icon.
	const select = `${ specimen( 'select-default' ) } select`;
	expect( await cssVar( page, select, 'background-image' ) ).toBe( 'none' );
	expect( await cssVar( page, select, 'min-height' ) ).toBe( controlMd );
	expect( await cssVar( page, select, 'border-top-left-radius' ) ).toBe(
		radius
	);
	expect( await cssVar( page, select, 'max-width' ) ).toBe( '100%' );
	await expect(
		page.locator( `${ specimen( 'select-default' ) } .sup-select > svg` )
	).toBeVisible();

	// Buttons are never .button and keep our height and radius.
	const save = page
		.locator( specimen( 'button-variants' ) )
		.getByRole( 'button', { name: 'Save changes' } );
	await expect( save ).not.toHaveClass( /(^|\s)button(\s|$)/ );
	await expect( save ).toHaveCSS( 'height', controlMd );
	await expect( save ).toHaveCSS( 'border-top-left-radius', radius );
	await expect( save ).toHaveCSS(
		'background-color',
		await computedColor( page, APP_ROOT, 'var(--sup-color-accent-bg)' )
	);
} );

test( 'every interactive control is at least 24 by 24 CSS pixels', async ( {
	page,
} ) => {
	await openGallery( page );
	const sizes = await page
		.locator(
			CONTROL_SPECIMENS.map(
				( name ) => `${ specimen( name ) } :is(${ INTERACTIVE })`
			).join( ', ' )
		)
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => {
				const box = node.getBoundingClientRect();
				return {
					control: `${ node.tagName.toLowerCase() }#${ node.id } ${
						node.getAttribute( 'aria-label' ) ??
						node.textContent?.trim() ??
						''
					}`,
					width: box.width,
					height: box.height,
				};
			} )
		);
	expect( sizes.length ).toBeGreaterThanOrEqual( TAB_ORDER.length );
	const small = sizes.filter(
		( size ) => size.width < 24 || size.height < 24
	);
	expect( small ).toEqual( [] );
} );

test( 'keyboard reaches every control in order with a visible focus ring', async ( {
	page,
} ) => {
	await openGallery( page );
	const ringColor = await computedColor(
		page,
		APP_ROOT,
		'var(--sup-color-focus-ring)'
	);
	const states = await tabThroughControls( page );

	expect(
		states.slice( 0, TAB_ORDER.length ).map( ( s ) => s.where )
	).toEqual( TAB_ORDER );
	// Past the last control, focus leaves the control specimens.
	expect( states[ TAB_ORDER.length ].where ).not.toMatch(
		new RegExp( `^(${ CONTROL_SPECIMENS.join( '|' ) }) \\|` )
	);
	for ( const state of states.slice( 0, TAB_ORDER.length ) ) {
		expect( { where: state.where, ...state.ring } ).toEqual( {
			where: state.where,
			width: '2px',
			style: 'solid',
			color: ringColor,
			offset: '2px',
		} );
	}
} );

test( 'forced colors keep the focus indicator and the checked states', async ( {
	page,
} ) => {
	await page.emulateMedia( { forcedColors: 'active' } );
	await openGallery( page );
	const states = await tabThroughControls( page );
	for ( const state of states.slice( 0, TAB_ORDER.length ) ) {
		expect( { where: state.where, style: state.ring.style } ).toEqual( {
			where: state.where,
			style: 'solid',
		} );
		expect( state.ring.width ).toBe( '2px' );
	}

	const highlight = await computedColor( page, APP_ROOT, 'highlight' );
	expect(
		await page
			.locator(
				`${ specimen( 'checkbox-checked' ) } .sup-checkbox__indicator`
			)
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor )
	).toBe( highlight );
	expect(
		await page
			.locator(
				`${ specimen( 'radio-group-segmented' ) } input:checked + .sup-radio-group__segment`
			)
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor )
	).toBe( highlight );
	expect(
		await page
			.locator(
				`${ specimen( 'toggle-default' ) } input:checked + .sup-toggle__track`
			)
			.evaluate( ( el ) => getComputedStyle( el ).backgroundColor )
	).toBe( highlight );
} );

test( 'the switch thumb and the select chevron follow the inline direction', async ( {
	page,
}, testInfo ) => {
	const { dir } = projectMode( testInfo );
	await openGallery( page );
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute( 'dir', dir );

	const centers = ( selector: string ) =>
		page.locator( selector ).evaluate( ( el ) => {
			const box = el.getBoundingClientRect();
			return box.left + box.width / 2;
		} );
	const toggles = specimen( 'toggle-default' );
	const offTrack = await centers(
		`${ toggles } .sup-toggle:nth-child(1) .sup-toggle__track`
	);
	const offThumb = await centers(
		`${ toggles } .sup-toggle:nth-child(1) .sup-toggle__thumb`
	);
	const onTrack = await centers(
		`${ toggles } .sup-toggle:nth-child(2) .sup-toggle__track`
	);
	const onThumb = await centers(
		`${ toggles } .sup-toggle:nth-child(2) .sup-toggle__thumb`
	);
	const selectBox = await centers(
		`${ specimen( 'select-default' ) } select`
	);
	const chevron = await centers(
		`${ specimen( 'select-default' ) } .sup-select > svg`
	);

	// Off sits at the inline start, on at the inline end.
	const atEnd = ( value: number, middle: number ) =>
		dir === 'ltr' ? value > middle : value < middle;
	expect( atEnd( offThumb, offTrack ) ).toBe( false );
	expect( atEnd( onThumb, onTrack ) ).toBe( true );
	expect( atEnd( chevron, selectBox ) ).toBe( true );
} );

test( 'ThemeToggle drives the scoped root and the portal layer', async ( {
	page,
} ) => {
	await openGallery( page, 'system' );
	const toggle = page.locator( specimen( 'theme-toggle' ) );
	await expect(
		toggle.getByRole( 'group', { name: 'Appearance' } )
	).toBeVisible();
	await expect(
		toggle.getByRole( 'radio', { name: 'Match system' } )
	).toBeChecked();

	await toggle.getByRole( 'radio', { name: 'Dark' } ).check();
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);
	await expect( page.locator( PORTAL_LAYER ) ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);
	expect(
		await page
			.locator( APP_ROOT )
			.evaluate( ( root ) => getComputedStyle( root ).colorScheme )
	).toBe( 'dark' );

	await toggle.getByRole( 'radio', { name: 'Light' } ).check();
	await expect( page.locator( APP_ROOT ) ).toHaveAttribute(
		'data-sup-theme',
		'light'
	);

	await toggle.getByRole( 'radio', { name: 'Match system' } ).check();
	expect(
		await page.locator( APP_ROOT ).getAttribute( 'data-sup-theme' )
	).toBeNull();
	expect(
		await page.locator( PORTAL_LAYER ).getAttribute( 'data-sup-theme' )
	).toBeNull();
} );

test( 'replacing a saved key reveals an empty password input and never the secret', async ( {
	page,
}, testInfo ) => {
	const { mode } = projectMode( testInfo );
	await openGallery( page, mode );
	const saved = page.locator( specimen( 'secret-input-saved' ) );
	await expect(
		saved.getByText( 'A key is saved. For security it is never shown.' )
	).toBeVisible();
	await expect( saved.locator( 'input' ) ).toHaveCount( 0 );

	await saved.getByRole( 'button', { name: 'Replace key' } ).click();
	const secret = saved.getByLabel( 'Live API key' );
	await expect( secret ).toBeFocused();
	await expect( secret ).toHaveAttribute( 'type', 'password' );
	await expect( secret ).toHaveAttribute( 'autocomplete', 'new-password' );
	await expect( secret ).toHaveAttribute( 'spellcheck', 'false' );
	await expect( secret ).toHaveValue( '' );

	await page.keyboard.type( SENTINEL );
	await expect( secret ).toHaveValue( SENTINEL );
	expect( await page.content() ).not.toContain( SENTINEL );

	const results = await new AxeBuilder( { page } )
		.withTags( TAGS )
		.include( '.supcheckout-admin' )
		.analyze();
	expect( results.violations ).toEqual( [] );

	await saved.getByRole( 'button', { name: 'Cancel' } ).click();
	await expect( saved.locator( 'input' ) ).toHaveCount( 0 );
	await expect(
		saved.getByRole( 'button', { name: 'Replace key' } )
	).toBeFocused();
	expect( await page.content() ).not.toContain( SENTINEL );
} );

test( 'a busy button stays focusable, inert and announced', async ( {
	page,
} ) => {
	await openGallery( page );
	const states = page.locator( specimen( 'button-states' ) );
	const busy = states.getByRole( 'button', { name: 'Save settings' } );
	await expect( busy ).toHaveAttribute( 'aria-disabled', 'true' );
	// Not natively disabled, so it keeps focus (Playwright's toBeEnabled
	// treats aria-disabled as disabled, hence the property check).
	expect(
		await busy.evaluate( ( el ) => ( el as HTMLButtonElement ).disabled )
	).toBe( false );
	await expect( busy.locator( '.sup-spinner' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
	await expect( states.getByRole( 'status' ) ).toHaveText(
		'Saving settings'
	);
	await busy.focus();
	await page.keyboard.press( 'Enter' );
	await expect( busy ).toBeFocused();
	await expect(
		states.getByRole( 'button', { name: 'Unavailable' } )
	).toBeDisabled();
} );

test( 'an indeterminate checkbox stays mixed after the user clicks it', async ( {
	page,
} ) => {
	await openGallery( page );
	const states = specimen( 'checkbox-states' );
	const selectAll = page
		.locator( states )
		.getByRole( 'checkbox', { name: 'Select all orders' } );
	const mixed = () =>
		selectAll.evaluate(
			( el ) => ( el as HTMLInputElement ).indeterminate
		);
	const dash = page.locator(
		`${ states } .sup-checkbox:nth-child(2) .sup-checkbox__dash`
	);
	expect( await mixed() ).toBe( true );
	await expect( dash ).toBeVisible();

	await selectAll.click();
	expect( await mixed() ).toBe( true );
	await expect( dash ).toBeVisible();
	await selectAll.press( 'Space' );
	expect( await mixed() ).toBe( true );
	await expect( dash ).toBeVisible();
} );

test( 'reduced motion makes control transitions instant', async ( {
	page,
} ) => {
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	await openGallery( page );
	for ( const selector of [
		`${ specimen( 'button-variants' ) } .sup-button`,
		`${ specimen( 'toggle-default' ) } .sup-toggle__thumb`,
		`${ specimen( 'checkbox-checked' ) } .sup-checkbox__indicator`,
	] ) {
		expect( await cssVar( page, selector, 'transition-duration' ) ).toMatch(
			/^0s(, 0s)*$/
		);
	}
} );
