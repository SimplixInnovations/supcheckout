/**
 * Build contract for the committed admin bundle under assets/admin/build.
 *
 * The release builder packages Git HEAD blobs only, so this suite is the
 * gate that keeps the committed build honest: WordPress externals, no
 * bundled React, no source maps, SUPCheckout-owned runtime globals,
 * allowed licenses and gzip budgets.
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync } from 'node:zlib';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);
const BUILD = join( ROOT, 'assets', 'admin', 'build' );

const BUDGET = { appJs: 60 * 1024, appCss: 16 * 1024, chunk: 30 * 1024 }; // gzip bytes
const ALLOWED = [
	'MIT',
	'ISC',
	'BSD-2-Clause',
	'BSD-3-Clause',
	'0BSD',
	'CC0-1.0',
];
const TEXT_EXTENSIONS = [ '.js', '.css', '.json', '.txt' ];

function readJson( relativePath ) {
	return JSON.parse( readFileSync( join( ROOT, relativePath ), 'utf8' ) );
}

function walk( directory ) {
	if ( ! existsSync( directory ) ) {
		return [];
	}
	return readdirSync( directory, { withFileTypes: true } ).flatMap(
		( entry ) => {
			const path = join( directory, entry.name );
			return entry.isDirectory() ? walk( path ) : [ path ];
		}
	);
}

/**
 * Lists build files relative to assets/admin/build, with forward slashes.
 *
 * @param {string} [subdirectory] Optional subdirectory of the build to list.
 * @return {string[]} Sorted paths relative to the build root, e.g. `chunks/x.js`.
 */
function listBuild( subdirectory = '' ) {
	return walk( join( BUILD, subdirectory ) )
		.map( ( path ) => relative( BUILD, path ).split( sep ).join( '/' ) )
		.sort();
}

function readBuild( relativePath ) {
	return readFileSync( join( BUILD, relativePath ) );
}

function appJs() {
	return readBuild( 'app.js' ).toString( 'utf8' );
}

function allJs() {
	return listBuild()
		.filter( ( file ) => file.endsWith( '.js' ) )
		.map( ( file ) => readBuild( file ).toString( 'utf8' ) )
		.join( '\n' );
}

function allText() {
	return listBuild()
		.filter( ( file ) =>
			TEXT_EXTENSIONS.some( ( ext ) => file.endsWith( ext ) )
		)
		.map( ( file ) => readBuild( file ).toString( 'utf8' ) )
		.join( '\n' );
}

function gz( relativePath ) {
	return gzipSync( readBuild( relativePath ), { level: 9 } ).length;
}

test( 'app manifest is JSON with WordPress externals and no wp-components', () => {
	const m = readJson( 'assets/admin/build/app.asset.json' );
	expect( Object.keys( m ) ).toEqual( [ 'dependencies', 'version' ] );
	expect( m.dependencies ).toEqual(
		expect.arrayContaining( [
			'react-jsx-runtime',
			'wp-element',
			'wp-i18n',
			'wp-dom-ready',
		] )
	);
	expect( m.dependencies ).not.toContain( 'wp-components' );
} );

test( 'React and the JSX runtime are never bundled', () => {
	for ( const needle of [
		'__SECRET_INTERNALS',
		'react.transitional.element',
		'react.production',
	] ) {
		expect( allJs() ).not.toContain( needle );
	}
} );

test( 'no PHP file is generated into the build', () => {
	expect( listBuild().filter( ( f ) => f.endsWith( '.php' ) ) ).toEqual( [] );
} );

test( 'no source maps ship', () => {
	expect( listBuild().filter( ( f ) => f.endsWith( '.map' ) ) ).toEqual( [] );
	expect( allText() ).not.toMatch( /sourceMappingURL/ );
} );

test( 'translator calls and the supcheckout domain survive minification', () => {
	// Webpack calls an imported binding as `(0,x.__)(`; both that and a bare
	// `__(` call are what `wp i18n make-pot` extracts from built JavaScript.
	expect( appJs() ).toMatch( /\b__\)?\(/ );
	expect( appJs() ).toContain( '"supcheckout"' );
} );

test( 'the webpack runtime uses a SUPCheckout-owned chunk global', () => {
	expect( appJs() ).toContain( 'supCheckoutAdminChunks' );
	expect( appJs() ).not.toMatch( /webpackChunk/ );
} );

test( 'every stylesheet has an RTL twin', () => {
	expect( listBuild() ).toEqual(
		expect.arrayContaining( [ 'app.css', 'app-rtl.css' ] )
	);
	for ( const css of listBuild().filter(
		( f ) => f.endsWith( '.css' ) && ! f.endsWith( '-rtl.css' )
	) ) {
		expect( listBuild() ).toContain( css.replace( /\.css$/, '-rtl.css' ) );
	}
} );

test( 'the chunk manifest lists exactly the emitted chunks', () => {
	expect( readJson( 'assets/admin/build/app.chunks.json' ) ).toEqual(
		listBuild( 'chunks' ).sort()
	);
} );

test( 'bundled packages carry an allowed license and none is WordPress or React', () => {
	for ( const p of readJson(
		'assets/admin/build/third-party-notices.json'
	) ) {
		expect( ALLOWED ).toContain( p.license );
		expect( p.name ).not.toMatch( /^(@wordpress\/|react$|react-dom$)/ );
	}
} );

test( 'gzip sizes stay within budget', () => {
	expect( gz( 'app.js' ) ).toBeLessThanOrEqual( BUDGET.appJs );
	expect( gz( 'app.css' ) ).toBeLessThanOrEqual( BUDGET.appCss );
	for ( const c of listBuild( 'chunks' ) ) {
		expect( gz( c ) ).toBeLessThanOrEqual( BUDGET.chunk );
	}
} );

test( 'the notices JSON is sorted by package name', () => {
	const names = readJson( 'assets/admin/build/third-party-notices.json' ).map(
		( p ) => p.name
	);
	expect( names ).toEqual( [ ...names ].sort() );
} );

test( 'the human-readable notices file ships next to the bundle', () => {
	expect( listBuild() ).toEqual(
		expect.arrayContaining( [
			'THIRD-PARTY-NOTICES.txt',
			'third-party-notices.json',
		] )
	);
} );

test( 'Base UI is bundled, noticed and licensed', () => {
	expect( readJson( 'assets/admin/build/third-party-notices.json' ) ).toEqual(
		expect.arrayContaining( [
			{ name: '@base-ui/react', version: '1.8.0', license: 'MIT' },
		] )
	);
	expect(
		readBuild( 'THIRD-PARTY-NOTICES.txt' ).toString( 'utf8' )
	).toContain( '@base-ui/react' );
} );

test( 'the app mounts SupRoot with its portal layer above the admin bar', () => {
	expect( appJs() ).toContain( 'sup-portal-layer' );
	const css = readBuild( 'app.css' ).toString( 'utf8' );
	expect( css ).toMatch(
		/\.supcheckout-admin\.sup-portal-layer\{[^}]*z-index:var\(--sup-z-portal\)/
	);
	expect( css ).toContain( '--sup-z-portal:100000' );
	expect( css ).toContain( '.sup-icon--mirrored' );
} );
