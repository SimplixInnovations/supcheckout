/**
 * Mode helpers shared by the browser specs: the project's color mode and
 * direction, token expectations from the token generator, and computed-color
 * probes inside the scoped root.
 */
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

import type { Page, TestInfo } from '@playwright/test';

export type ColorMode = 'light' | 'dark';
export type Direction = 'ltr' | 'rtl';

export interface ProjectMode {
	mode: ColorMode;
	dir: Direction;
}

/**
 * @param testInfo The running test.
 * @return Color mode and direction encoded in the project name (`dark-rtl`).
 */
export function projectMode( testInfo: TestInfo ): ProjectMode {
	const [ mode, dir ] = testInfo.project.name
		.replace( /^visual-/, '' )
		.split( '-' );
	if (
		( mode !== 'light' && mode !== 'dark' ) ||
		( dir !== 'ltr' && dir !== 'rtl' )
	) {
		throw new Error(
			`unexpected project name: ${ testInfo.project.name }`
		);
	}
	return { mode, dir };
}

interface TokenModule {
	loadTokens: () => unknown;
	resolveColor: (
		set: unknown,
		mode: ColorMode,
		token: string,
		scheme?: string
	) => string;
	SCHEME_NAMES: readonly string[];
}

let tokenModule: Promise< TokenModule > | undefined;

function tokens(): Promise< TokenModule > {
	tokenModule ??= import(
		pathToFileURL(
			resolve(
				__dirname,
				'..',
				'..',
				'..',
				'..',
				'tools',
				'admin-ui',
				'tokens',
				'resolve.mjs'
			)
		).href
	) as Promise< TokenModule >;
	return tokenModule;
}

/**
 * @param mode   Color mode.
 * @param token  Token name without `--sup-`, e.g. `color-bg-surface`.
 * @param scheme Admin color scheme (default `fresh`).
 * @return The generator's resolved color as `rgb(r, g, b)`.
 */
export async function expectedRgb(
	mode: ColorMode,
	token: string,
	scheme?: string
): Promise< string > {
	const module = await tokens();
	return hexToRgb(
		module.resolveColor( module.loadTokens(), mode, token, scheme )
	);
}

/** @return The nine core admin color scheme names. */
export async function schemeNames(): Promise< readonly string[] > {
	return ( await tokens() ).SCHEME_NAMES;
}

/**
 * @param hex Color as `#rrggbb`.
 * @return The same color as Chromium serializes a computed opaque color.
 */
export function hexToRgb( hex: string ): string {
	const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec( hex );
	if ( ! match ) {
		throw new Error( `not a #rrggbb color: ${ hex }` );
	}
	const [ r, g, b ] = match
		.slice( 1 )
		.map( ( channel ) => parseInt( channel, 16 ) );
	return `rgb(${ r }, ${ g }, ${ b })`;
}

/**
 * Resolves a CSS color expression to its computed `rgb()` value inside an
 * element, through a temporary probe child.
 *
 * @param page       The page.
 * @param selector   Element the probe is appended to.
 * @param expression A CSS color, e.g. `var(--sup-color-bg-surface)`.
 * @return The computed color.
 */
export function computedColor(
	page: Page,
	selector: string,
	expression: string
): Promise< string > {
	return page.evaluate(
		( [ hostSelector, value ] ) => {
			const host = document.querySelector( hostSelector );
			if ( ! host ) {
				throw new Error( `no element matches ${ hostSelector }` );
			}
			const probe = document.createElement( 'span' );
			probe.style.color = value;
			host.appendChild( probe );
			const color = getComputedStyle( probe ).color;
			probe.remove();
			return color;
		},
		[ selector, expression ] as const
	);
}
