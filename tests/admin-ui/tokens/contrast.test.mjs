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

const contrastSource = readJson(
	'assets/admin/src/tokens/contrast-pairs.json'
);
const { pairs, decorative, exclusions } = contrastSource;

const STATUSES = [ 'success', 'warning', 'danger', 'info', 'neutral' ];
const SURFACES = [
	'color-bg-canvas',
	'color-bg-surface',
	'color-bg-surface-raised',
	'color-bg-surface-sunken',
];

function semanticColorNames() {
	return Object.keys( loadTokens().semantic.light ).filter( ( key ) =>
		key.startsWith( 'color-' )
	);
}

/**
 * Every background a control or focus indicator can sit on.
 *
 * @return {string[]} Token names.
 */
function controlBackgrounds() {
	return [
		...SURFACES,
		'color-accent-subtle-bg',
		...STATUSES.map( ( status ) => `color-status-${ status }-bg` ),
	];
}

function pairFor( fg, kind ) {
	return pairs.filter( ( p ) => p.fg === fg && p.kind === kind );
}

function backgroundsFor( fg, kind ) {
	return pairFor( fg, kind ).flatMap( ( p ) => p.bg );
}

test( 'every contrast pair meets WCAG 2.2 AA in both modes for every admin scheme', () => {
	expect( checkContrast( loadTokens() ) ).toEqual( [] );
} );

test( 'every semantic color token is covered by a contrast pair or listed as decorative', () => {
	const covered = new Set( pairs.flatMap( ( p ) => [ p.fg, ...p.bg ] ) );
	const exempt = new Set( decorative.map( ( entry ) => entry.token ) );
	expect(
		semanticColorNames().filter(
			( n ) => ! covered.has( n ) && ! exempt.has( n )
		)
	).toEqual( [] );
} );

test( 'decorative tokens are known, give a reason and never appear in a pair', () => {
	const names = new Set( semanticColorNames() );
	const paired = new Set( pairs.flatMap( ( p ) => [ p.fg, ...p.bg ] ) );
	expect( decorative.length ).toBeGreaterThan( 0 );
	for ( const entry of decorative ) {
		expect( [ entry.token, names.has( entry.token ) ] ).toEqual( [
			entry.token,
			true,
		] );
		expect( [ entry.token, paired.has( entry.token ) ] ).toEqual( [
			entry.token,
			false,
		] );
		expect( entry.reason ).toMatch( /\w{3,}.*\w{3,}/ );
	}
	expect( decorative.map( ( entry ) => entry.token ).sort() ).toEqual( [
		'color-bg-overlay-backdrop',
		'color-border-default',
		'color-skeleton-base',
		'color-skeleton-highlight',
	] );
} );

test( 'control boundaries and focus rings cover every background or exclude it with a reason', () => {
	const names = new Set( semanticColorNames() );
	for ( const fg of [ 'color-border-strong', 'color-focus-ring' ] ) {
		const paired = new Set( backgroundsFor( fg, 'non-text' ) );
		const excluded = new Set(
			exclusions
				.filter( ( entry ) => entry.fg === fg )
				.flatMap( ( entry ) => entry.bg )
		);
		expect(
			controlBackgrounds().filter(
				( bg ) => ! paired.has( bg ) && ! excluded.has( bg )
			)
		).toEqual( [] );
		expect( [ ...excluded ].filter( ( bg ) => paired.has( bg ) ) ).toEqual(
			[]
		);
	}
	for ( const entry of exclusions ) {
		expect( names.has( entry.fg ) ).toBe( true );
		expect( entry.bg.every( ( bg ) => names.has( bg ) ) ).toBe( true );
		expect( entry.reason ).toMatch( /\w{3,}.*\w{3,}/ );
	}
} );

test( 'R1: the strong border reaches 3:1 on every surface and the accent tint', () => {
	expect( backgroundsFor( 'color-border-strong', 'non-text' ) ).toEqual(
		expect.arrayContaining( [ ...SURFACES, 'color-accent-subtle-bg' ] )
	);
	expect( exclusions ).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( {
				fg: 'color-border-strong',
				bg: STATUSES.map( ( status ) => `color-status-${ status }-bg` ),
			} ),
		] )
	);
} );

test( 'R2: subtle text reaches 4.5:1 on every surface', () => {
	expect( backgroundsFor( 'color-fg-subtle', 'text' ) ).toEqual(
		expect.arrayContaining( SURFACES )
	);
	expect(
		pairs.filter( ( p ) => p.fg === 'color-fg-subtle' && p.kind !== 'text' )
	).toEqual( [] );
} );

test( 'subtle text also reaches 4.5:1 on the accent tint and every status background', () => {
	expect( backgroundsFor( 'color-fg-subtle', 'text' ) ).toEqual(
		expect.arrayContaining( [
			'color-accent-subtle-bg',
			...STATUSES.map( ( status ) => `color-status-${ status }-bg` ),
		] )
	);
} );

test( 'every text foreground is paired wherever default text is, or excluded with a reason', () => {
	const textForegrounds = [
		'color-fg-default',
		'color-fg-muted',
		'color-fg-subtle',
		'color-fg-link',
		'color-fg-on-accent',
		'color-accent-fg',
		...STATUSES.map( ( status ) => `color-status-${ status }-fg` ),
	];
	// Every semantic text foreground is in the list, so a new one cannot be
	// added without joining this check.
	const pairedText = new Set(
		pairs.filter( ( p ) => p.kind === 'text' ).map( ( p ) => p.fg )
	);
	expect(
		[ ...pairedText ].filter( ( fg ) => ! textForegrounds.includes( fg ) )
	).toEqual( [] );
	const required = backgroundsFor( 'color-fg-default', 'text' );
	expect( required ).toEqual(
		expect.arrayContaining( controlBackgrounds() )
	);
	const gaps = [];
	for ( const fg of textForegrounds ) {
		const paired = new Set( backgroundsFor( fg, 'text' ) );
		const excluded = new Set(
			exclusions
				.filter( ( entry ) => entry.fg === fg )
				.flatMap( ( entry ) => entry.bg )
		);
		for ( const bg of required ) {
			if ( ! paired.has( bg ) && ! excluded.has( bg ) ) {
				gaps.push( `${ fg } on ${ bg }` );
			}
		}
		expect( [
			fg,
			[ ...excluded ].filter( ( bg ) => paired.has( bg ) ),
		] ).toEqual( [ fg, [] ] );
	}
	expect( gaps ).toEqual( [] );
} );

test( 'R3: on-accent text reaches 4.5:1 on every status solid', () => {
	expect( backgroundsFor( 'color-fg-on-accent', 'text' ) ).toEqual(
		expect.arrayContaining(
			STATUSES.map( ( status ) => `color-status-${ status }-solid` )
		)
	);
	for ( const status of STATUSES ) {
		expect(
			backgroundsFor( `color-status-${ status }-solid`, 'non-text' )
		).toEqual(
			expect.arrayContaining( [
				'color-bg-canvas',
				'color-bg-surface',
				'color-bg-surface-raised',
			] )
		);
	}
} );

test( 'the focus ring reaches 3:1 on the accent tint and every status background', () => {
	expect( backgroundsFor( 'color-focus-ring', 'non-text' ) ).toEqual(
		expect.arrayContaining( controlBackgrounds() )
	);
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
