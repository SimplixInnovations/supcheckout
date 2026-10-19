/**
 * Token system contract: the JSON source, the resolved token set and the
 * generated `--sup-*` stylesheet (spec section 4.1, SP1 Task 2).
 */
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import {
	loadTokens,
	renderCss,
	resolveColor,
	SCHEME_NAMES,
} from '../../../tools/admin-ui/tokens/resolve.mjs';
import {
	BUILD_TOKENS_CLI,
	GENERATED_CSS,
	ROOT,
	blockAfter,
	read,
	readJson,
} from './support.mjs';

const SEMANTIC_COLOR_NAMES = [
	'color-bg-canvas',
	'color-bg-surface',
	'color-bg-surface-raised',
	'color-bg-surface-sunken',
	'color-bg-overlay-backdrop',
	'color-fg-default',
	'color-fg-muted',
	'color-fg-subtle',
	'color-fg-on-accent',
	'color-fg-link',
	'color-border-default',
	'color-border-strong',
	'color-accent-bg',
	'color-accent-bg-hover',
	'color-accent-bg-active',
	'color-accent-fg',
	'color-accent-subtle-bg',
	...[ 'success', 'warning', 'danger', 'info', 'neutral' ].flatMap(
		( status ) =>
			[ 'bg', 'fg', 'border', 'solid' ].map(
				( part ) => `color-status-${ status }-${ part }`
			)
	),
	'color-focus-ring',
	'color-skeleton-base',
	'color-skeleton-highlight',
].sort();

const DARK_MEDIA = '@media (prefers-color-scheme: dark)';
const DARK_SYSTEM = '.supcheckout-admin:not([data-sup-theme="light"])';
const DARK_EXPLICIT = '.supcheckout-admin[data-sup-theme="dark"]';
const ARABIC =
	'.supcheckout-admin:lang(ar),\n.supcheckout-admin:lang(fa),\n.supcheckout-admin:lang(ur)';
const COMPACT =
	'.supcheckout-admin[data-sup-density="compact"],\n.supcheckout-admin [data-sup-density="compact"]';
const REDUCED_MOTION = '@media (prefers-reduced-motion: reduce)';

const generatedCss = () => renderCss( loadTokens() );

function declarations( block ) {
	return Object.fromEntries(
		[ ...block.matchAll( /(--sup-[a-z0-9-]+):\s*([^;]+);/g ) ].map(
			( match ) => [ match[ 1 ], match[ 2 ].trim() ]
		)
	);
}

describe( 'token source', () => {
	test( 'the semantic color names are exactly the published contract', () => {
		const set = loadTokens();
		const names = ( mode ) =>
			Object.keys( set.semantic[ mode ] )
				.filter( ( key ) => key.startsWith( 'color-' ) )
				.sort();
		expect( names( 'light' ) ).toEqual( SEMANTIC_COLOR_NAMES );
		expect( names( 'dark' ) ).toEqual( SEMANTIC_COLOR_NAMES );
	} );

	test( 'light and dark define the same semantic keys', () => {
		const set = loadTokens();
		expect( Object.keys( set.semantic.dark ).sort() ).toEqual(
			Object.keys( set.semantic.light ).sort()
		);
	} );

	test( 'the neutral anchors resolve to the design values', () => {
		const set = loadTokens();
		const anchors = {
			light: {
				'color-bg-canvas': '#f5f6f8',
				'color-bg-surface': '#ffffff',
				'color-bg-surface-raised': '#ffffff',
				'color-fg-default': '#16191d',
				'color-fg-muted': '#59606b',
				'color-border-default': '#d9dce1',
				'color-border-strong': '#858b96',
			},
			dark: {
				'color-bg-canvas': '#121519',
				'color-bg-surface': '#1a1e23',
				'color-bg-surface-raised': '#22272d',
				'color-fg-default': '#e9ecef',
				'color-fg-muted': '#a4acb6',
				'color-border-default': '#323840',
				'color-border-strong': '#6b737e',
			},
		};
		for ( const [ mode, tokens ] of Object.entries( anchors ) ) {
			for ( const [ token, hex ] of Object.entries( tokens ) ) {
				expect( [
					mode,
					token,
					resolveColor( set, mode, token ),
				] ).toEqual( [ mode, token, hex ] );
			}
		}
	} );

	test( 'the light raised surface carries a shadow and dark elevation uses no shadow', () => {
		const set = loadTokens();
		expect( set.semantic.light[ 'shadow-raised' ] ).not.toBe( 'none' );
		expect( set.semantic.dark[ 'shadow-raised' ] ).toBe( 'none' );
	} );

	test( 'type, space, radius and motion scales match the design decisions', () => {
		const { light } = loadTokens().semantic;
		const pick = ( prefix, steps ) =>
			steps.map( ( step ) => light[ `${ prefix }-${ step }` ] );
		expect(
			pick( 'font-size', [ 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl' ] )
		).toEqual( [ '12px', '13px', '14px', '16px', '20px', '24px', '32px' ] );
		expect(
			pick( 'space', [ 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 ] )
		).toEqual( [
			'0',
			'2px',
			'4px',
			'8px',
			'12px',
			'16px',
			'20px',
			'24px',
			'32px',
			'40px',
			'48px',
			'56px',
			'64px',
		] );
		expect(
			pick( 'radius', [ 'control', 'card', 'overlay', 'pill' ] )
		).toEqual( [ '6px', '10px', '12px', '999px' ] );
		expect( pick( 'motion-duration', [ 'fast', 'base', 'slow' ] ) ).toEqual(
			[ '120ms', '200ms', '320ms' ]
		);
		expect( light[ 'motion-ease-standard' ] ).toBe(
			'cubic-bezier(0.2, 0, 0, 1)'
		);
		expect( light[ 'motion-ease-exit' ] ).toBe(
			'cubic-bezier(0.3, 0, 1, 1)'
		);
		expect( light[ 'motion-distance-sm' ] ).toBe( '4px' );
		expect( light[ 'line-height-body' ] ).toBe( '1.5' );
		expect( light[ 'size-control-md' ] ).toBe( '36px' );
		expect( light[ 'size-table-row' ] ).toBe( '48px' );
		expect( light[ 'size-target-min' ] ).toBe( '24px' );
		expect( light[ 'border-width-focus' ] ).toBe( '2px' );
		expect( light[ 'size-focus-offset' ] ).toBe( '2px' );
	} );

	test( 'the default font stacks are system stacks with no web font', () => {
		const { light } = loadTokens().semantic;
		expect( light[ 'font-family-sans' ] ).toBe(
			'-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif'
		);
		expect( light[ 'font-family-arabic' ] ).toBe(
			'system-ui, "Segoe UI", "Noto Sans Arabic", "Geeza Pro", Tahoma, sans-serif'
		);
		expect( light[ 'font-family-mono' ] ).toMatch( /monospace$/ );
		expect( generatedCss() ).not.toMatch( /@font-face|url\(/ );
	} );

	test( 'overlay layers sit above the WordPress admin bar (z-index 99999)', () => {
		const { light } = loadTokens().semantic;
		for ( const layer of [
			'z-portal',
			'z-overlay',
			'z-popover',
			'z-toast',
			'z-tooltip',
		] ) {
			expect( Number( light[ layer ] ) ).toBeGreaterThan( 99999 );
		}
		// The portal layer is the body-level scoped root (SP1 Task 3); it
		// isolates its own stack, so overlays order inside it.
		expect( light[ 'z-portal' ] ).toBe( '100000' );
	} );

	test( 'icon sizes cover every Icon size (16, 20 and 24)', () => {
		const { light } = loadTokens().semantic;
		expect( [
			light[ 'size-icon-sm' ],
			light[ 'size-icon-md' ],
			light[ 'size-icon-lg' ],
		] ).toEqual( [ '16px', '20px', '24px' ] );
	} );

	test( 'component tokens reference semantic tokens only', () => {
		const { component } = loadTokens();
		expect( Object.keys( component ).length ).toBeGreaterThan( 0 );
		for ( const v of Object.values( component ) ) {
			expect( v ).toMatch(
				/^var\(--sup-(color|space|radius|font|line-height|letter-spacing|shadow|motion|size|z|border-width)-/
			);
		}
	} );

	test( 'semantic tokens never reference component tokens', () => {
		const set = loadTokens();
		const componentNames = Object.keys( set.component );
		for ( const mode of [ 'light', 'dark' ] ) {
			for ( const value of Object.values( set.semantic[ mode ] ) ) {
				for ( const name of componentNames ) {
					expect( value ).not.toContain( `var(--sup-${ name })` );
				}
			}
		}
	} );

	test( 'every core admin scheme has light and dark accent ramps and records its WordPress source', () => {
		const set = loadTokens();
		const source = readJson(
			'assets/admin/src/tokens/admin-schemes.tokens.json'
		);
		expect( SCHEME_NAMES ).toEqual( [
			'fresh',
			'light',
			'modern',
			'blue',
			'coffee',
			'ectoplasm',
			'midnight',
			'ocean',
			'sunrise',
		] );
		expect( Object.keys( set.schemes ).sort() ).toEqual(
			[ ...SCHEME_NAMES ].sort()
		);
		const steps = Object.keys( set.schemes.fresh.light ).sort();
		expect( steps.length ).toBeGreaterThanOrEqual( 8 );
		for ( const scheme of SCHEME_NAMES ) {
			expect( Object.keys( set.schemes[ scheme ].light ).sort() ).toEqual(
				steps
			);
			expect( Object.keys( set.schemes[ scheme ].dark ).sort() ).toEqual(
				steps
			);
			for ( const value of [
				...Object.values( set.schemes[ scheme ].light ),
				...Object.values( set.schemes[ scheme ].dark ),
			] ) {
				expect( value ).toMatch( /^#[0-9a-f]{6}$/ );
			}
			const entry = source.schemes[ scheme ];
			expect( entry.$description ).toMatch( /wordpress-develop/ );
			expect( entry.$description ).toMatch( /7\.1\.3/ );
			expect( entry.sourceColor.$value ).toMatch( /^#[0-9a-f]{6}$/ );
			expect( entry.wpAdminThemeColor.$value ).toMatch(
				/^#[0-9a-f]{6}$/
			);
		}
	} );

	test( 'other WordPress versions record only their real --wp-admin-theme-color deltas, as metadata', () => {
		const source = readJson(
			'assets/admin/src/tokens/admin-schemes.tokens.json'
		);
		const deltas = source.$wpAdminThemeColorDeltas;
		expect( deltas ).toEqual( {
			$description: expect.stringMatching( /7\.1\.3/ ),
			// Observed in real wp-admin on WordPress 6.9.10 (Admin UI
			// Certification browser lane): the pre-7.0 scheme highlights.
			'6.9.10': {
				light: '#0085ba',
				blue: '#096484',
				coffee: '#46403c',
				ectoplasm: '#523f6d',
				midnight: '#e14d43',
				ocean: '#627c83',
				sunrise: '#dd823b',
			},
		} );
		for ( const [ version, schemes ] of Object.entries( deltas ) ) {
			if ( version === '$description' ) {
				continue;
			}
			expect( version ).toMatch( /^\d+\.\d+\.\d+$/ );
			expect( version ).not.toBe( '7.1.3' );
			for ( const [ scheme, color ] of Object.entries( schemes ) ) {
				expect( SCHEME_NAMES ).toContain( scheme );
				expect( color ).toMatch( /^#[0-9a-f]{6}$/ );
				expect( color ).not.toBe(
					source.schemes[ scheme ].wpAdminThemeColor.$value
				);
			}
		}
		// Metadata only: it never reaches the token set.
		expect( JSON.stringify( loadTokens() ) ).not.toContain( '#0085ba' );
	} );

	test( 'each scheme records the --wp-admin-theme-color that WordPress 7.1.3 ships', () => {
		const source = readJson(
			'assets/admin/src/tokens/admin-schemes.tokens.json'
		);
		const themeColor = Object.fromEntries(
			Object.entries( source.schemes ).map( ( [ name, entry ] ) => [
				name,
				entry.wpAdminThemeColor.$value,
			] )
		);
		expect( themeColor ).toEqual( {
			fresh: '#007cba',
			light: '#007cba',
			modern: '#3858e9',
			blue: '#437aa8',
			coffee: '#916745',
			ectoplasm: '#646c3e',
			midnight: '#cf4339',
			ocean: '#567958',
			sunrise: '#ad631e',
		} );
	} );

	test( 'resolveColor follows references per mode and per scheme', () => {
		const set = loadTokens();
		expect( resolveColor( set, 'light', 'color-fg-on-accent' ) ).toBe(
			'#ffffff'
		);
		expect( resolveColor( set, 'light', 'color-accent-bg', 'fresh' ) ).toBe(
			set.schemes.fresh.light[ 'palette-accent-600' ]
		);
		expect(
			resolveColor( set, 'dark', 'color-accent-bg', 'midnight' )
		).toBe( set.schemes.midnight.dark[ 'palette-accent-600' ] );
		expect(
			resolveColor( set, 'light', 'color-accent-bg', 'midnight' )
		).not.toBe( resolveColor( set, 'light', 'color-accent-bg', 'ocean' ) );
		expect( () => resolveColor( set, 'light', 'color-nope' ) ).toThrow(
			'unknown token: color-nope'
		);
	} );
} );

describe( 'generated stylesheet', () => {
	test( 'generated CSS is deterministic and committed', () => {
		const css = renderCss( loadTokens() );
		expect( renderCss( loadTokens() ) ).toBe( css );
		expect( read( 'assets/admin/src/tokens/tokens.generated.css' ) ).toBe(
			css
		);
	} );

	test( 'the stylesheet is LF-only and ends with exactly one newline', () => {
		const css = generatedCss();
		expect( css ).not.toContain( '\r' );
		expect( css.endsWith( '\n' ) ).toBe( true );
		expect( css.endsWith( '\n\n' ) ).toBe( false );
	} );

	test( 'blocks appear in the contract order', () => {
		const css = generatedCss();
		const order = [
			'\n.supcheckout-admin {',
			`${ DARK_MEDIA } {\n\n\t${ DARK_SYSTEM } {`,
			`\n${ DARK_EXPLICIT } {`,
			'\nbody.admin-color-light .supcheckout-admin {',
			`\n${ ARABIC } {`,
			`\n${ COMPACT } {`,
			`\n${ REDUCED_MOTION } {`,
		].map( ( marker ) => css.indexOf( marker ) );
		expect( order.every( ( index ) => index >= 0 ) ).toBe( true );
		expect( [ ...order ].sort( ( a, b ) => a - b ) ).toEqual( order );
	} );

	test( 'the root block holds primitives, light semantics, components and color-scheme light', () => {
		const root = blockAfter( generatedCss(), '.supcheckout-admin' );
		expect( root ).toMatch( /--sup-palette-neutral-0:\s*#fff;/ );
		expect( root ).toMatch(
			/--sup-color-bg-canvas:\s*var\(--sup-palette-/
		);
		expect( root ).toMatch(
			/--sup-button-height-md:\s*var\(--sup-size-control-md\);/
		);
		expect( root ).toMatch( /color-scheme:\s*light;/ );
	} );

	test( 'match-system dark needs no JavaScript and an explicit choice wins both ways', () => {
		const css = generatedCss();
		const media = blockAfter( css, DARK_MEDIA );
		expect( media ).toContain( `${ DARK_SYSTEM } {` );
		expect( media ).toMatch( /color-scheme:\s*dark;/ );
		const explicit = blockAfter( css, `\n${ DARK_EXPLICIT }` );
		expect( explicit ).toMatch( /color-scheme:\s*dark;/ );
		expect( declarations( explicit ) ).toEqual(
			declarations( blockAfter( media, DARK_SYSTEM ) )
		);
	} );

	test( 'the root accent defaults to the fresh ramp so an unknown scheme falls back to it', () => {
		const set = loadTokens();
		const css = renderCss( set );
		const root = declarations( blockAfter( css, '.supcheckout-admin' ) );
		const darkRoot = declarations(
			blockAfter( css, `\n${ DARK_EXPLICIT }` )
		);
		for ( const [ step, hex ] of Object.entries(
			set.schemes.fresh.light
		) ) {
			expect( root[ `--sup-${ step }` ] ).toBe( shortHex( hex ) );
		}
		for ( const [ step, hex ] of Object.entries(
			set.schemes.fresh.dark
		) ) {
			expect( darkRoot[ `--sup-${ step }` ] ).toBe( shortHex( hex ) );
		}
		expect( css ).not.toContain( 'admin-color-fresh' );
	} );

	test( 'each non-default scheme overrides the accent ramp in light, system dark and explicit dark', () => {
		const set = loadTokens();
		const css = renderCss( set );
		for ( const scheme of SCHEME_NAMES.filter( ( s ) => s !== 'fresh' ) ) {
			const scope = `body.admin-color-${ scheme } .supcheckout-admin`;
			const light = declarations( blockAfter( css, `\n${ scope }` ) );
			const explicitDark = declarations(
				blockAfter( css, `\n${ scope }[data-sup-theme="dark"]` )
			);
			const systemDark = declarations(
				blockAfter( css, `\t${ scope }:not([data-sup-theme="light"])` )
			);
			expect( css ).toContain(
				`${ DARK_MEDIA } {\n\n\t${ scope }:not([data-sup-theme="light"]) {`
			);
			for ( const [ step, hex ] of Object.entries(
				set.schemes[ scheme ].light
			) ) {
				expect( light[ `--sup-${ step }` ] ).toBe( shortHex( hex ) );
			}
			for ( const [ step, hex ] of Object.entries(
				set.schemes[ scheme ].dark
			) ) {
				expect( explicitDark[ `--sup-${ step }` ] ).toBe(
					shortHex( hex )
				);
				expect( systemDark[ `--sup-${ step }` ] ).toBe(
					shortHex( hex )
				);
			}
		}
	} );

	test( 'reduced motion zeroes every duration and distance', () => {
		const reducedMotionBlock = () =>
			blockAfter( generatedCss(), REDUCED_MOTION );
		expect( reducedMotionBlock() ).toMatch(
			/--sup-motion-duration-base:\s*0ms/
		);
		expect( reducedMotionBlock() ).toMatch(
			/--sup-motion-distance-sm:\s*0/
		);
		const { light } = loadTokens().semantic;
		const block = declarations( reducedMotionBlock() );
		const keys = Object.keys( light );
		const durations = keys.filter( ( key ) =>
			key.startsWith( 'motion-duration-' )
		);
		const distances = keys.filter( ( key ) =>
			key.startsWith( 'motion-distance-' )
		);
		expect( durations.length ).toBeGreaterThan( 0 );
		expect( distances.length ).toBeGreaterThan( 0 );
		expect( durations.map( ( key ) => block[ `--sup-${ key }` ] ) ).toEqual(
			durations.map( () => '0ms' )
		);
		expect( distances.map( ( key ) => block[ `--sup-${ key }` ] ) ).toEqual(
			distances.map( () => '0px' )
		);
	} );

	test( 'Arabic scope switches the font family and removes letter spacing', () => {
		const block = declarations(
			blockAfter( generatedCss(), `\n${ ARABIC }` )
		);
		expect( block[ '--sup-font-family-sans' ] ).toBe(
			'var(--sup-font-family-arabic)'
		);
		expect( block[ '--sup-line-height-body' ] ).toBe( '1.7' );
		const spacing = Object.keys( loadTokens().semantic.light ).filter(
			( key ) => key.startsWith( 'letter-spacing-' )
		);
		expect( spacing.length ).toBeGreaterThan( 0 );
		for ( const key of spacing ) {
			expect( block[ `--sup-${ key }` ] ).toBe( '0' );
		}
	} );

	test( 'compact density lowers control and row sizes but never below the 24 px target', () => {
		const block = declarations(
			blockAfter( generatedCss(), `\n${ COMPACT }` )
		);
		expect( block[ '--sup-size-control-md' ] ).toBe( '32px' );
		expect( block[ '--sup-size-table-row' ] ).toBe( '36px' );
		// Component tokens that depend on a changed size are re-declared, so
		// a compact DataTable below a comfortable root still resolves them.
		expect( block[ '--sup-button-height-md' ] ).toBe(
			'var(--sup-size-control-md)'
		);
		const sizes = Object.values( block )
			.map( ( value ) => /^(\d+)px$/.exec( value ) )
			.filter( Boolean )
			.map( ( px ) => Number( px[ 1 ] ) );
		expect( sizes.length ).toBeGreaterThan( 0 );
		expect( sizes.filter( ( size ) => size < 24 ) ).toEqual( [] );
	} );
} );

describe( 'build-tokens CLI', () => {
	const run = ( args ) =>
		spawnSync( process.execPath, [ BUILD_TOKENS_CLI, ...args ], {
			cwd: ROOT,
			encoding: 'utf8',
		} );

	test( '--check passes on the committed stylesheet', () => {
		const result = run( [ '--check' ] );
		expect( result.stderr ).toBe( '' );
		expect( result.status ).toBe( 0 );
	} );

	test( '--check fails when the output is stale and writing repairs it', () => {
		const out = join(
			mkdtempSync( join( tmpdir(), 'sup-tokens-' ) ),
			'tokens.css'
		);
		writeFileSync( out, '/* stale */\n' );
		const stale = run( [ '--check', '--out', out ] );
		expect( stale.status ).toBe( 1 );
		expect( stale.stderr ).toContain( 'is stale' );
		expect( run( [ '--out', out ] ).status ).toBe( 0 );
		expect( readFileSync( out, 'utf8' ) ).toBe(
			readFileSync( GENERATED_CSS, 'utf8' )
		);
		expect( run( [ '--check', '--out', out ] ).status ).toBe( 0 );
	} );

	test( 'an invalid brand file fails the CLI with its errors', () => {
		const dir = mkdtempSync( join( tmpdir(), 'sup-brand-' ) );
		const brand = join( dir, 'brand.tokens.json' );
		writeFileSync( brand, JSON.stringify( { colour: {} } ) );
		const result = run( [
			'--brand',
			brand,
			'--out',
			join( dir, 'out.css' ),
		] );
		expect( result.status ).toBe( 1 );
		expect( result.stderr ).toContain( 'unknown brand key: colour' );
	} );

	test( 'the build script regenerates tokens before webpack', () => {
		const { scripts } = readJson( 'package.json' );
		expect( scripts.build ).toMatch(
			/^node tools\/admin-ui\/tokens\/build-tokens\.mjs && wp-scripts build/
		);
	} );

	test( 'the app entry imports the generated tokens before the app CSS', () => {
		const entry = read( 'assets/admin/src/app/index.tsx' );
		const tokens = entry.indexOf(
			"import '../tokens/tokens.generated.css';"
		);
		expect( tokens ).toBeGreaterThan( -1 );
		expect( tokens ).toBeLessThan( entry.indexOf( "import './app.css';" ) );
	} );
} );

function shortHex( hex ) {
	const match = /^#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3$/.exec( hex );
	return match ? `#${ match[ 1 ] }${ match[ 2 ] }${ match[ 3 ] }` : hex;
}
