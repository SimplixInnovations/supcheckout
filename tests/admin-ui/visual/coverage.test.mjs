/**
 * Coverage contract of the visual-regression lane (SP1 Task 8).
 *
 * - Exactly one committed Linux baseline exists per specimen group and mode,
 *   plus the light-ltr-only extras: a missing baseline fails here before CI
 *   reports "A snapshot doesn't exist", and a stale one (a renamed or removed
 *   group) fails too.
 * - The gallery stays inert unless the test-only constant is set.
 * - The visual lane's brand file only swaps the font families, and the
 *   vendored test fonts reach the gallery build only, never assets/.
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	EXTRA_GROUPS,
	GALLERY_VIEWS,
	SPECIMEN_GROUPS,
	UNGROUPED_SPECIMENS,
} from '../gallery/specimens/index.ts';
import { EXTRA_GROUP_MODE, VISUAL_MODES } from './modes.ts';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);
const BASELINES = join( ROOT, 'tests', 'admin-ui', 'visual', 'baselines' );
const GALLERY = join( ROOT, 'tests', 'admin-ui', 'gallery' );
const VR_BRAND = join( GALLERY, 'vr-brand.tokens.json' );
const VR_FONTS = join( GALLERY, 'vr-fonts.css' );

function read( relativePath ) {
	return readFileSync( join( ROOT, relativePath ), 'utf8' );
}

function names( groups ) {
	return groups.map( ( group ) => group.name );
}

test( 'a committed baseline exists for every specimen group and mode', () => {
	const expected = SPECIMEN_GROUPS.flatMap( ( group ) =>
		VISUAL_MODES.map( ( mode ) => `${ group.name }--${ mode }.png` )
	)
		.concat(
			EXTRA_GROUPS.map(
				( group ) => `${ group.name }--${ EXTRA_GROUP_MODE }.png`
			)
		)
		.sort();
	expect( readdirSync( BASELINES ).sort() ).toEqual( expected );
} );

test( 'group names are unique, kebab-case and safe as file names', () => {
	const all = names( [ ...SPECIMEN_GROUPS, ...EXTRA_GROUPS ] );
	expect( new Set( all ).size ).toBe( all.length );
	for ( const name of all ) {
		expect( name ).toMatch( /^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/ );
	}
} );

test( 'every group names a gallery view, frames list specimens once and element captures none', () => {
	const groups = [ ...SPECIMEN_GROUPS, ...EXTRA_GROUPS ];
	for ( const group of groups ) {
		expect( GALLERY_VIEWS ).toContain( group.view );
		expect( [ 'frame', 'element' ] ).toContain( group.capture );
	}
	expect(
		groups
			.filter( ( group ) => group.capture === 'element' )
			.flatMap( ( group ) => group.specimens )
	).toEqual( [] );
	const framed = groups.flatMap( ( group ) => group.specimens );
	expect( new Set( framed ).size ).toBe( framed.length );
	for ( const name of Object.values( UNGROUPED_SPECIMENS ).flat() ) {
		expect( framed ).not.toContain( name );
	}
} );

test( 'the visual modes are the four color mode and direction pairs', () => {
	expect( [ ...VISUAL_MODES ].sort() ).toEqual(
		[ 'dark-ltr', 'dark-rtl', 'light-ltr', 'light-rtl' ].sort()
	);
	expect( VISUAL_MODES ).toContain( EXTRA_GROUP_MODE );
} );

test( 'the gallery mu-plugin returns early unless SUPCHECKOUT_UI_GALLERY is true', () => {
	expect( read( 'tests/admin-ui/wp/supcheckout-ui-gallery.php' ) ).toContain(
		"if (!defined('SUPCHECKOUT_UI_GALLERY') || true !== SUPCHECKOUT_UI_GALLERY) {\n    return;\n}"
	);
} );

test( 'the visual brand file only sets the sans and Arabic families to the vendored faces', () => {
	const brand = JSON.parse( readFileSync( VR_BRAND, 'utf8' ) );
	const keys = Object.keys( brand ).filter(
		( key ) => ! key.startsWith( '$' )
	);
	expect( keys ).toEqual( [ 'font' ] );
	expect( Object.keys( brand.font ) ).toEqual( [ 'family' ] );
	expect( Object.keys( brand.font.family ).sort() ).toEqual( [
		'arabic',
		'sans',
	] );
	expect( brand.font.family.sans.$value ).toMatch( /^"SUP VR Noto Sans",/ );
	expect( brand.font.family.arabic.$value ).toMatch(
		/^"SUP VR Noto Sans Arabic",/
	);
} );

test( 'the vendored faces are the four 400 and 600 latin and arabic files from @fontsource', () => {
	const css = readFileSync( VR_FONTS, 'utf8' );
	const urls = [ ...css.matchAll( /url\(\s*"([^"]+)"\s*\)/g ) ].map(
		( match ) => match[ 1 ]
	);
	expect( urls.sort() ).toEqual(
		[
			'../../../node_modules/@fontsource/noto-sans/files/noto-sans-latin-400-normal.woff2',
			'../../../node_modules/@fontsource/noto-sans/files/noto-sans-latin-600-normal.woff2',
			'../../../node_modules/@fontsource/noto-sans-arabic/files/noto-sans-arabic-arabic-400-normal.woff2',
			'../../../node_modules/@fontsource/noto-sans-arabic/files/noto-sans-arabic-arabic-600-normal.woff2',
		].sort()
	);
	for ( const url of urls ) {
		expect( existsSync( join( GALLERY, url ) ) ).toBe( true );
	}
	const pkg = JSON.parse( read( 'package.json' ) );
	for ( const name of [
		'@fontsource/noto-sans',
		'@fontsource/noto-sans-arabic',
	] ) {
		expect( pkg.devDependencies[ name ] ).toMatch( /^\d+\.\d+\.\d+$/ );
		expect( pkg.dependencies?.[ name ] ).toBeUndefined();
		// The OFL-1.1 license ships inside the package, next to the files.
		expect(
			readFileSync(
				join( ROOT, 'node_modules', name, 'LICENSE' ),
				'utf8'
			)
		).toContain( 'SIL Open Font License, Version 1.1' );
	}
} );

test( 'SUP_BRAND brands the gallery build only: tokens and fonts land under gallery/build', () => {
	const describe = ( env ) =>
		JSON.parse(
			execFileSync(
				process.execPath,
				[
					'-e',
					`const c = require( ${ JSON.stringify(
						join( GALLERY, 'webpack.gallery.config.cjs' )
					) } );
					const brand = c.plugins.find( ( p ) => p.constructor.name === 'GalleryBrandPlugin' );
					process.stdout.write( JSON.stringify( {
						entry: c.entry.index,
						output: c.output.path,
						brandOut: brand ? brand.out : null,
					} ) );`,
				],
				{ cwd: ROOT, env: { ...process.env, ...env } }
			).toString( 'utf8' )
		);
	const toPosix = ( path ) => relative( ROOT, path ).split( sep ).join( '/' );

	const plain = describe( { SUP_BRAND: '' } );
	expect( plain.brandOut ).toBeNull();
	expect( [ plain.entry ].flat().map( toPosix ) ).toEqual( [
		'tests/admin-ui/gallery/index.tsx',
	] );

	const branded = describe( {
		SUP_BRAND: 'tests/admin-ui/gallery/vr-brand.tokens.json',
	} );
	expect( toPosix( branded.output ) ).toBe( 'tests/admin-ui/gallery/build' );
	expect( toPosix( branded.brandOut ) ).toBe(
		'tests/admin-ui/gallery/build/brand/tokens.generated.css'
	);
	expect( [ branded.entry ].flat().map( toPosix ) ).toEqual( [
		'tests/admin-ui/gallery/vr-fonts.css',
		'tests/admin-ui/gallery/index.tsx',
	] );
} );
