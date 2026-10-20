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

test( 'the command palette panel is the lazy chunk and the Task 1 placeholder is gone', () => {
	const chunks = readJson( 'assets/admin/build/app.chunks.json' );
	expect(
		chunks.some( ( c ) =>
			/^chunks\/command-palette\.[a-f0-9]{8}\.js$/.test( c )
		)
	).toBe( true );
	expect(
		existsSync( join( ROOT, 'assets/admin/src/app/Placeholder.tsx' ) )
	).toBe( false );
	expect( allText() ).not.toContain( 'sup-app-placeholder' );
	// The panel (Base UI Autocomplete and the search) loads only on open.
	const palette = chunks.find( ( c ) =>
		c.startsWith( 'chunks/command-palette.' )
	);
	const panel = readBuild( palette ).toString( 'utf8' );
	expect( panel ).toContain( 'No matching commands' );
	expect( panel ).toContain( 'sup-palette__list' );
	expect( appJs() ).not.toContain( 'No matching commands' );
	expect( appJs() ).not.toContain( 'sup-palette__list' );
	// The shell, the palette trigger and its dialog ship in app.js.
	expect( appJs() ).toContain( 'sup-app-shell' );
	expect( appJs() ).toContain( 'sup-dialog' );
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

// The icon set ships whole once anything renders an Icon (see the next
// test), so every new glyph costs every screen. Raise this only on purpose.
const EXPECTED_ICON_COUNT = 24;

test( 'the shipped glyph map holds exactly the published icon set', () => {
	const source = readFileSync(
		join( ROOT, 'assets/admin/src/ui/foundations/icons.ts' ),
		'utf8'
	);
	const map = /export const ICONS[^{]*\{([^}]*)\}/.exec( source );
	expect( map ).not.toBeNull();
	const entries = map[ 1 ]
		.split( '\n' )
		.filter( ( line ) =>
			/^\s*'?[a-z][a-z0-9-]*'?:\s*[A-Z]\w*,\s*$/.test( line )
		);
	expect( entries ).toHaveLength( EXPECTED_ICON_COUNT );
	// lucide's minified glyph definitions: `{name:"circle-x",size:24,node:`.
	const glyphs = appJs().match( /\{name:"[a-z0-9-]+",size:24,node:/g ) ?? [];
	expect( glyphs ).toHaveLength( EXPECTED_ICON_COUNT );
} );

test( 'library modules and glyphs the app does not render are tree-shaken', () => {
	// package.json `sideEffects: ["*.css"]` lets webpack drop barrel modules
	// that nothing uses. Since Task 6 the app renders SupRoot's Toaster, which
	// draws icons, so Icon's name map (and with it every glyph of our set,
	// `eye-off` included) ships: a map lookup cannot be tree-shaken. What must
	// still never ship: library modules the app does not render (SecretInput,
	// Tooltip, Stepper) and lucide glyphs outside our set.
	const js = appJs();
	expect( js ).toContain( 'sup-toast' );
	expect( js ).toContain( '"circle-x"' );
	for ( const unrendered of [
		'sup-secret-input',
		'sup-tooltip',
		'sup-stepper',
	] ) {
		expect( js ).not.toContain( unrendered );
	}
	for ( const glyph of [ '"rocket"', '"circle-dot"', '"trash-2"' ] ) {
		expect( js ).not.toContain( glyph );
	}
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
