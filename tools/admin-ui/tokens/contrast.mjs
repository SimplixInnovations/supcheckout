/**
 * WCAG 2.2 AA contrast checker for the SUPCheckout admin tokens.
 *
 * Pairs come from `assets/admin/src/tokens/contrast-pairs.json`. Each pair is
 * checked in light and dark for every core admin color scheme, or once for
 * the brand accent when `brand.tokens.json` replaces the scheme accents.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { wcagContrast } from 'culori';

import { resolveColor, SCHEME_NAMES, TOKENS_DIR } from './resolve.mjs';

/** Minimum ratios: 1.4.3 text, 1.4.3 large text (24 px+), 1.4.11 non-text. */
export const REQUIRED_RATIO = Object.freeze( {
	text: 4.5,
	'large-text': 3,
	'non-text': 3,
} );

const MODES = [ 'light', 'dark' ];

/**
 * Reads the contrast pairs.
 *
 * @return {{ fg: string, bg: string[], kind: 'text'|'large-text'|'non-text' }[]} Pairs.
 */
export function loadPairs() {
	const { pairs } = JSON.parse(
		readFileSync( join( TOKENS_DIR, 'contrast-pairs.json' ), 'utf8' )
	);
	for ( const pair of pairs ) {
		if ( ! Object.hasOwn( REQUIRED_RATIO, pair.kind ) ) {
			throw new Error(
				`contrast pair ${ pair.fg } has unknown kind ${ pair.kind }`
			);
		}
	}
	return pairs;
}

/**
 * @typedef {Object} ContrastFailure
 * @property {'light'|'dark'} mode     Color mode.
 * @property {string}         scheme   Admin scheme, or `brand` for a brand accent.
 * @property {string}         fg       Foreground token.
 * @property {string}         bg       Background token.
 * @property {number}         ratio    Measured ratio, truncated to two decimals.
 * @property {number}         required Required ratio.
 */

/**
 * Lists every pair that misses its WCAG 2.2 AA ratio.
 *
 * @param {import('./resolve.mjs').TokenSet} set     Token set.
 * @param {ReturnType<typeof loadPairs>}     [pairs] Pairs (default: contrast-pairs.json).
 * @return {ContrastFailure[]} Failures; empty when every pair passes.
 */
export function checkContrast( set, pairs = loadPairs() ) {
	const schemes = set.brandAccent ? [ SCHEME_NAMES[ 0 ] ] : SCHEME_NAMES;
	const failures = [];
	for ( const mode of MODES ) {
		for ( const scheme of schemes ) {
			for ( const { fg, bg: backgrounds, kind } of pairs ) {
				const required = REQUIRED_RATIO[ kind ];
				const fgColor = resolveColor( set, mode, fg, scheme );
				for ( const bg of backgrounds ) {
					const ratio = wcagContrast(
						fgColor,
						resolveColor( set, mode, bg, scheme )
					);
					if ( ratio < required ) {
						failures.push( {
							mode,
							scheme: set.brandAccent ? 'brand' : scheme,
							fg,
							bg,
							ratio: Math.floor( ratio * 100 ) / 100,
							required,
						} );
					}
				}
			}
		}
	}
	return failures;
}
