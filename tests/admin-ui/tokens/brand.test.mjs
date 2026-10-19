/**
 * One-file branding: brand.tokens.json may set only the allowlisted keys, a
 * brand accent replaces every admin-scheme accent, and the contrast suite runs
 * on the merged result (spec section 4.1, SP1 Task 2).
 */
import { checkContrast } from '../../../tools/admin-ui/tokens/contrast.mjs';
import {
	loadTokens,
	renderCss,
	resolveColor,
	SCHEME_NAMES,
	validateBrand,
} from '../../../tools/admin-ui/tokens/resolve.mjs';
import { fixture, readJson } from './support.mjs';

test( 'the shipped brand file is empty and keeps the admin-scheme accents', () => {
	expect( readJson( 'assets/admin/src/tokens/brand.tokens.json' ) ).toEqual(
		{}
	);
	const set = loadTokens();
	expect( set.brandAccent ).toBe( false );
	expect( renderCss( set ) ).toContain(
		'body.admin-color-modern .supcheckout-admin'
	);
} );

test( 'a brand accent replaces the admin-scheme accents', () => {
	const s = loadTokens( { brandPath: fixture( 'accessible-brand' ) } );
	expect( s.brandAccent ).toBe( true );
	expect( renderCss( s ) ).not.toContain( 'admin-color-' );
	const brand = readJson(
		'tests/admin-ui/tokens/fixtures/accessible-brand.tokens.json'
	);
	for ( const scheme of SCHEME_NAMES ) {
		expect( resolveColor( s, 'light', 'color-accent-bg', scheme ) ).toBe(
			brand.palette.accent.light[ '600' ].$value
		);
		expect( resolveColor( s, 'dark', 'color-accent-bg', scheme ) ).toBe(
			brand.palette.accent.dark[ '600' ].$value
		);
	}
} );

test( 'an accessible brand passes the contrast check', () => {
	expect(
		checkContrast(
			loadTokens( { brandPath: fixture( 'accessible-brand' ) } )
		)
	).toEqual( [] );
} );

test( 'an unknown brand key is rejected', () => {
	expect( validateBrand( { colour: {} } ) ).toEqual( [
		'unknown brand key: colour',
	] );
} );

test( 'nested keys outside the allowlist are rejected by path', () => {
	expect(
		validateBrand( {
			palette: { neutral: {} },
			font: { family: { serif: { $value: 'Georgia, serif' } } },
			radius: { control: { $value: '4px' } },
		} )
	).toEqual( [
		'unknown brand key: palette.neutral',
		'unknown brand key: font.family.serif',
		'unknown brand key: radius.control',
	] );
} );

test( 'brand values are validated', () => {
	expect(
		validateBrand( {
			palette: { accent: { light: { 600: { $value: 'red' } } } },
			font: {
				family: { sans: { $value: 'Inter; } body { color: red' } },
			},
			radius: { scale: { $value: 5 } },
		} )
	).toEqual( [
		'brand palette.accent.light.600 must be a #rrggbb color',
		'brand accent ramp palette.accent.light is missing steps 50, 100, 200, 300, 400, 500, 700, 800, 900',
		'brand accent ramp palette.accent.dark is missing',
		'brand font.family.sans must be a font stack without ; { } or url(',
		'brand radius.scale must be a number from 0 to 2',
	] );
} );

test( 'a brand that is not an object is rejected', () => {
	expect( validateBrand( [] ) ).toEqual( [
		'brand file must be a JSON object',
	] );
	expect( validateBrand( null ) ).toEqual( [
		'brand file must be a JSON object',
	] );
} );

test( 'loadTokens refuses an invalid brand file', () => {
	expect( () =>
		loadTokens( { brandPath: fixture( 'unknown-key-brand' ) } )
	).toThrow( 'unknown brand key: colour' );
} );

test( 'an inaccessible brand accent fails the contrast check', () => {
	expect(
		checkContrast(
			loadTokens( { brandPath: fixture( 'inaccessible-brand' ) } )
		)
	).toEqual(
		expect.arrayContaining( [
			expect.objectContaining( {
				fg: 'color-fg-on-accent',
				bg: 'color-accent-bg',
			} ),
		] )
	);
} );

test( 'brand fonts and radius scale flow into the semantic tokens', () => {
	const set = loadTokens( { brandPath: fixture( 'accessible-brand' ) } );
	const brand = readJson(
		'tests/admin-ui/tokens/fixtures/accessible-brand.tokens.json'
	);
	expect( set.semantic.light[ 'font-family-sans' ] ).toBe(
		brand.font.family.sans.$value
	);
	expect( set.semantic.light[ 'radius-control' ] ).toBe( '3px' );
	expect( set.semantic.light[ 'radius-card' ] ).toBe( '5px' );
	expect( set.semantic.light[ 'radius-pill' ] ).toBe( '999px' );
} );
