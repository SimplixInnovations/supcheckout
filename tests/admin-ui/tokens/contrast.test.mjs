/**
 * WCAG 2.2 AA contrast for every semantic pair, in light and dark, for every
 * core WordPress admin color scheme (spec section 4.1, SP1 Task 2).
 */
import { wcagContrast } from 'culori';

import {
	checkContrast,
	REQUIRED_RATIO,
} from '../../../tools/admin-ui/tokens/contrast.mjs';
import {
	loadTokens,
	resolveColor,
	SCHEME_NAMES,
} from '../../../tools/admin-ui/tokens/resolve.mjs';
import { readJson } from './support.mjs';

const pairs = readJson( 'assets/admin/src/tokens/contrast-pairs.json' ).pairs;

function semanticColorNames() {
	return Object.keys( loadTokens().semantic.light ).filter( ( key ) =>
		key.startsWith( 'color-' )
	);
}

test( 'every contrast pair meets WCAG 2.2 AA in both modes for every admin scheme', () => {
	expect( checkContrast( loadTokens() ) ).toEqual( [] );
} );

test( 'every semantic color token is covered by a contrast pair', () => {
	const covered = new Set( pairs.flatMap( ( p ) => [ p.fg, ...p.bg ] ) );
	expect(
		semanticColorNames().filter(
			( n ) =>
				! covered.has( n ) &&
				! n.includes( 'backdrop' ) &&
				! n.includes( 'skeleton' )
		)
	).toEqual( [] );
} );

test( 'every pair names known tokens and a known kind', () => {
	const names = new Set( semanticColorNames() );
	for ( const pair of pairs ) {
		expect( names.has( pair.fg ) ).toBe( true );
		expect( pair.bg.length ).toBeGreaterThan( 0 );
		for ( const bg of pair.bg ) {
			expect( names.has( bg ) ).toBe( true );
		}
		expect( [ 'text', 'large-text', 'non-text' ] ).toContain( pair.kind );
	}
} );

test( 'the required ratios are 4.5 for text and 3 for large text and non-text', () => {
	expect( REQUIRED_RATIO ).toEqual( {
		text: 4.5,
		'large-text': 3,
		'non-text': 3,
	} );
} );

test( 'the focus ring has 3:1 against canvas, surface and raised surface', () => {
	const set = loadTokens();
	const surfaces = [
		'color-bg-canvas',
		'color-bg-surface',
		'color-bg-surface-raised',
	];
	const ring = pairs.find( ( p ) => p.fg === 'color-focus-ring' );
	expect( ring.kind ).toBe( 'non-text' );
	expect( ring.bg ).toEqual( expect.arrayContaining( surfaces ) );
	for ( const mode of [ 'light', 'dark' ] ) {
		for ( const scheme of SCHEME_NAMES ) {
			for ( const bg of surfaces ) {
				const ratio = wcagContrast(
					resolveColor( set, mode, 'color-focus-ring', scheme ),
					resolveColor( set, mode, bg, scheme )
				);
				expect( [ mode, scheme, bg, ratio >= 3 ] ).toEqual( [
					mode,
					scheme,
					bg,
					true,
				] );
			}
		}
	}
} );

test( 'body text, muted text and links meet 4.5:1 on every background they sit on', () => {
	const kinds = Object.fromEntries( pairs.map( ( p ) => [ p.fg, p.kind ] ) );
	expect( kinds[ 'color-fg-default' ] ).toBe( 'text' );
	expect( kinds[ 'color-fg-muted' ] ).toBe( 'text' );
	expect( kinds[ 'color-fg-link' ] ).toBe( 'text' );
	expect( kinds[ 'color-fg-on-accent' ] ).toBe( 'text' );
	expect( kinds[ 'color-accent-fg' ] ).toBe( 'text' );
	for ( const status of [
		'success',
		'warning',
		'danger',
		'info',
		'neutral',
	] ) {
		expect( kinds[ `color-status-${ status }-fg` ] ).toBe( 'text' );
	}
} );

test( 'checkContrast reports a failure with its mode, scheme, pair, ratio and requirement', () => {
	const set = loadTokens();
	const broken = {
		...set,
		semantic: {
			...set.semantic,
			light: {
				...set.semantic.light,
				'color-fg-muted': 'var(--sup-palette-neutral-0)',
			},
		},
	};
	const failures = checkContrast( broken );
	expect( failures ).toEqual(
		expect.arrayContaining( [
			{
				mode: 'light',
				scheme: 'fresh',
				fg: 'color-fg-muted',
				bg: 'color-bg-surface',
				ratio: 1,
				required: 4.5,
			},
		] )
	);
	expect( failures.every( ( f ) => f.mode === 'light' ) ).toBe( true );
} );
