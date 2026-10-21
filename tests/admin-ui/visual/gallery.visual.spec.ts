/**
 * Visual regression of every specimen group (SP1 Task 8).
 *
 * Runs once per `visual-<mode>` project; the mode comes from the project's
 * metadata, never from its name. Each test opens the group's gallery view,
 * waits for the vendored test fonts, brings the group into a stable state
 * and compares one element against its committed Linux baseline,
 * `visual/baselines/<group>--<mode>.png`. Screenshots are taken of the
 * specimen frame or the overlay element, never of WordPress chrome.
 *
 * Determinism: the projects pin the viewport, device scale, reduced motion
 * and Chromium's font hinting and color profile; the expect settings
 * disable animations and hide the caret. Interactions use the keyboard, so
 * the pointer never hovers anything. Toasts use the error tone or carry an
 * action, which never time out; the pending confirmation is held open through the gallery
 * (`confirm=hold`); live-region text is awaited before the capture.
 */
import { expect, test } from '@playwright/test';
import type { Locator, Page, TestInfo } from '@playwright/test';

import config from '../playwright.config';
import { galleryUrl } from '../browser/support/env';
import { EXTRA_GROUPS, SPECIMEN_GROUPS } from '../gallery/specimens/index';
import type { GalleryView, SpecimenGroup } from '../gallery/specimens/index';
import { EXTRA_GROUP_MODE, VISUAL_MODES } from './modes';
import type { VisualMode } from './modes';

/** The vendored faces the visual brand file names (tests/admin-ui/gallery/vr-fonts.css). */
const VENDORED_FACES = [
	'400 16px "SUP VR Noto Sans"',
	'600 16px "SUP VR Noto Sans"',
	'400 16px "SUP VR Noto Sans Arabic"',
	'600 16px "SUP VR Noto Sans Arabic"',
	'400 16px "SUP VR Noto Sans Mono"',
] as const;

/** Sample text that makes the Arabic faces load (they hold Arabic glyphs only). */
const ARABIC_SAMPLE = 'ادفع';

const READY: Readonly< Record< GalleryView, string > > = {
	components: '[data-gallery-ready]',
	overlays: '[data-overlays-ready]',
};

/** Fixed or sticky chrome a frame must never overlap when captured. */
const CHROME = '#wpadminbar, .sup-app-shell__header';

function modeOf( info: TestInfo ): VisualMode {
	const mode = info.project.metadata.mode as unknown;
	const known = VISUAL_MODES.find( ( candidate ) => candidate === mode );
	if ( ! known ) {
		throw new Error(
			`project ${ info.project.name } has no visual mode metadata`
		);
	}
	return known;
}

async function gotoGallery(
	page: Page,
	view: GalleryView,
	extra: Record< string, string > = {}
): Promise< void > {
	await page.goto(
		galleryUrl(
			'system',
			view === 'overlays' ? { view: 'overlays', ...extra } : extra
		)
	);
	await expect( page.locator( READY[ view ] ) ).toBeVisible();
	const loaded = await page.evaluate(
		async ( { faces, sample } ) => {
			await Promise.all(
				faces.map( ( face ) =>
					document.fonts.load(
						face,
						face.includes( 'Arabic' ) ? sample : 'Aa'
					)
				)
			);
			await document.fonts.ready;
			return Array.from( document.fonts )
				.filter(
					( face ) =>
						face.family.includes( 'SUP VR' ) &&
						face.status === 'loaded'
				)
				.map( ( face ) => `${ face.weight } ${ face.family }` )
				.sort();
		},
		{ faces: [ ...VENDORED_FACES ], sample: ARABIC_SAMPLE }
	);
	// The gallery must be the SUP_BRAND build: system fonts differ by runner.
	expect( loaded ).toHaveLength( VENDORED_FACES.length );
}

const specimen = ( page: Page, name: string ): Locator =>
	page.locator( `[data-specimen="${ name }"]` );

const frame = ( page: Page, name: string ): Locator =>
	page.locator( `[data-specimen-group="${ name }"]` );

/**
 * Centers a frame in the viewport and proves no admin bar or sticky header
 * covers it, so the screenshot holds the frame alone.
 *
 * @param page   The page.
 * @param target The frame.
 */
async function isolate( page: Page, target: Locator ): Promise< void > {
	await expect( target ).toBeVisible();
	await target.evaluate( ( node ) =>
		node.scrollIntoView( { block: 'center', inline: 'nearest' } )
	);
	const overlaps = await target.evaluate( ( node, chrome ) => {
		const box = node.getBoundingClientRect();
		return Array.from( document.querySelectorAll( chrome ) )
			.filter( ( element ) => {
				const other = element.getBoundingClientRect();
				return (
					other.width > 0 &&
					other.height > 0 &&
					box.left < other.right &&
					other.left < box.right &&
					box.top < other.bottom &&
					other.top < box.bottom
				);
			} )
			.map( ( element ) => element.id || element.className );
	}, CHROME );
	expect( overlaps ).toEqual( [] );
	const box = await target.boundingBox();
	const viewport = page.viewportSize();
	expect( box && viewport && box.height <= viewport.height ).toBe( true );
}

/**
 * Presses Enter on a control (keyboard activation: no hover state).
 *
 * @param control The control.
 */
async function activate( control: Locator ): Promise< void > {
	await control.focus();
	await control.press( 'Enter' );
}

interface Capture {
	/** Extra gallery query parameters. */
	query?: Record< string, string >;
	/** Brings the group into its captured state; returns the element captured. */
	prepare: ( page: Page ) => Promise< Locator >;
}

async function liveNotices( page: Page ): Promise< Locator > {
	const success = specimen( page, 'notice-live' );
	const failure = specimen( page, 'notice-live-error' );
	await activate( success.getByRole( 'button' ) );
	await activate( failure.getByRole( 'button' ) );
	// Status text arrives LIVE_REGION_DELAY_MS after the empty region.
	await expect( success.getByRole( 'status' ) ).toHaveText( /\S/ );
	await expect( failure.getByRole( 'alert' ) ).toHaveText( /\S/ );
	await failure.getByRole( 'button' ).first().blur();
	return frame( page, 'live-notices' );
}

async function openConfirmation(
	page: Page,
	settle: 'pending' | 'verified'
): Promise< Locator > {
	await activate(
		specimen( page, 'confirm-dialog' ).getByRole( 'button' ).first()
	);
	const dialog = page.getByRole( 'alertdialog' );
	await expect( dialog ).toBeVisible();
	const footer = dialog.locator( '.sup-dialog__footer' );
	await activate( footer.getByRole( 'button' ).nth( 1 ) );
	if ( settle === 'pending' ) {
		await expect( footer.getByRole( 'button' ).first() ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
	} else {
		await expect(
			dialog.locator( '.sup-confirm__result--verified' )
		).toBeVisible();
	}
	return dialog;
}

/** How each `element` group reaches its captured state. */
const ELEMENT_CAPTURES: Readonly< Record< string, Capture > > = {
	'toast-open': {
		async prepare( page ) {
			// The third trigger shows the error tone, which never times out.
			await activate(
				specimen( page, 'toast-trigger' ).getByRole( 'button' ).nth( 2 )
			);
			const viewport = page.locator( '.sup-toast-viewport' );
			await expect( viewport.locator( '.sup-toast' ) ).toHaveCount( 1 );
			await expect(
				viewport.locator( '.sup-toast--error' )
			).toBeVisible();
			return viewport;
		},
	},
	'toast-action-open': {
		// An info toast with an action never times out (it waits for the
		// user); the long German label shows the action under the text,
		// wrapping inside the toast.
		query: { labels: 'long' },
		async prepare( page ) {
			await activate(
				specimen( page, 'toast-trigger' ).getByRole( 'button' ).nth( 1 )
			);
			const viewport = page.locator( '.sup-toast-viewport' );
			await expect( viewport.locator( '.sup-toast' ) ).toHaveCount( 1 );
			await expect(
				viewport.locator( '.sup-toast--info .sup-toast__action' )
			).toBeVisible();
			return viewport;
		},
	},
	'tooltip-open': {
		async prepare( page ) {
			// Focus opens a tooltip at once (hover waits 400 ms).
			await specimen( page, 'tooltip' )
				.getByRole( 'button' )
				.first()
				.focus();
			const tooltip = page.getByRole( 'tooltip' );
			await expect( tooltip ).toBeVisible();
			return tooltip;
		},
	},
	'app-shell-header': {
		async prepare( page ) {
			return page.locator( '.sup-app-shell__header' );
		},
	},
	'app-shell-nav': {
		async prepare( page ) {
			return page.locator( '.sup-app-shell__nav' );
		},
	},
	'dialog-open': {
		async prepare( page ) {
			await activate(
				specimen( page, 'dialog' ).getByRole( 'button' ).first()
			);
			const dialog = page.getByRole( 'dialog' );
			await expect( dialog ).toBeVisible();
			return dialog;
		},
	},
	'confirm-dialog-pending': {
		// The gallery holds the specimen's onConfirm promise open.
		query: { confirm: 'hold' },
		prepare: ( page ) => openConfirmation( page, 'pending' ),
	},
	'confirm-dialog-verified': {
		prepare: ( page ) => openConfirmation( page, 'verified' ),
	},
	'command-palette': {
		// Core's palette is dropped so the page holds no WordPress commands.
		query: { sup_core_commands: 'off' },
		async prepare( page ) {
			await activate( page.locator( '.sup-palette-trigger' ) );
			const palette = page.locator( '.sup-palette' );
			await expect( palette ).toBeVisible();
			// The panel is the lazy chunk: wait until its results render.
			await expect( palette.locator( '.sup-palette__item' ) ).toHaveCount(
				5
			);
			return palette;
		},
	},
};

/** Frames that need a state change before the capture. */
const FRAME_PREPARATIONS: Readonly<
	Record< string, ( page: Page ) => Promise< Locator > >
> = {
	'live-notices': liveNotices,
	'focus-forced-colors': async ( page ) => {
		const target = frame( page, 'focus-forced-colors' );
		const first = target.getByRole( 'button' ).first();
		await first.focus();
		expect(
			await first.evaluate( ( node ) => node.matches( ':focus-visible' ) )
		).toBe( true );
		return target;
	},
};

async function capture(
	page: Page,
	group: SpecimenGroup,
	mode: VisualMode
): Promise< void > {
	const element =
		group.capture === 'element'
			? ELEMENT_CAPTURES[ group.name ]
			: undefined;
	if ( group.capture === 'element' && ! element ) {
		throw new Error( `no element capture for group ${ group.name }` );
	}
	await gotoGallery( page, group.view, element?.query );
	let target: Locator;
	if ( element ) {
		target = await element.prepare( page );
		await expect( target ).toBeVisible();
	} else {
		const prepare = FRAME_PREPARATIONS[ group.name ];
		target = prepare ? await prepare( page ) : frame( page, group.name );
		await isolate( page, target );
	}
	await settleAnimations( page );
	await expect( target ).toHaveScreenshot( `${ group.name }--${ mode }.png` );
}

/**
 * Lets every finite animation and transition finish, then waits two frames,
 * so the capture never races a style change that just happened (the pending
 * Cancel turning disabled). Infinite animations (the spinner) are left to
 * the screenshot's own `animations: 'disabled'`.
 *
 * @param page The page.
 */
async function settleAnimations( page: Page ): Promise< void > {
	await page.evaluate( async () => {
		const finite = document
			.getAnimations()
			.filter(
				( animation ) =>
					animation.effect?.getComputedTiming().iterations !==
					Infinity
			);
		await Promise.all(
			finite.map( ( animation ) =>
				animation.finished.catch( () => undefined )
			)
		);
		await new Promise( ( resolve ) =>
			requestAnimationFrame( () => requestAnimationFrame( resolve ) )
		);
	} );
}

for ( const group of SPECIMEN_GROUPS ) {
	test( `visual ${ group.name }`, async ( { page }, info ) => {
		await capture( page, group, modeOf( info ) );
	} );
}

for ( const group of EXTRA_GROUPS ) {
	test( `visual ${ group.name }`, async ( { page }, info ) => {
		test.skip(
			modeOf( info ) !== EXTRA_GROUP_MODE,
			`${ EXTRA_GROUP_MODE } only`
		);
		if ( group.name === 'focus-forced-colors' ) {
			await page.emulateMedia( { forcedColors: 'active' } );
		}
		await capture( page, group, EXTRA_GROUP_MODE );
	} );
}

test( 'the four visual projects cover VISUAL_MODES exactly', async ( {}, info ) => {
	test.skip( modeOf( info ) !== EXTRA_GROUP_MODE, 'checked once' );
	const visual = config.projects?.filter( ( project ) =>
		project.name?.startsWith( 'visual-' )
	);
	expect(
		( visual ?? [] ).map( ( project ) => project.metadata?.mode ).sort()
	).toEqual( [ ...VISUAL_MODES ].sort() );
	for ( const project of visual ?? [] ) {
		expect( project.name ).toBe( `visual-${ project.metadata?.mode }` );
	}
} );

test( 'every element group has a capture and every capture a group', async ( {}, info ) => {
	test.skip( modeOf( info ) !== EXTRA_GROUP_MODE, 'checked once' );
	const elementGroups = SPECIMEN_GROUPS.filter(
		( group ) => group.capture === 'element'
	).map( ( group ) => group.name );
	expect( Object.keys( ELEMENT_CAPTURES ).sort() ).toEqual(
		[ ...elementGroups ].sort()
	);
	const frames = [ ...SPECIMEN_GROUPS, ...EXTRA_GROUPS ]
		.filter( ( group ) => group.capture === 'frame' )
		.map( ( group ): string => group.name );
	for ( const name of Object.keys( FRAME_PREPARATIONS ) ) {
		expect( frames ).toContain( name );
	}
} );
