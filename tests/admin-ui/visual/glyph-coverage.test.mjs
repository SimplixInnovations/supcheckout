/**
 * Glyph coverage of the vendored visual-lane fonts (SP1 Task 8 fix round 1).
 *
 * Every character the gallery or the shipped admin source can render must
 * fall inside a vendored `@fontsource` subset, or Chromium would fall back to a
 * runner font and the baselines would depend on the OS image: Arabic-script
 * characters inside the Arabic subset of Noto Sans Arabic, everything else
 * inside the Latin subset of Noto Sans (and of Noto Sans Mono, which uses the
 * same Latin subset). The subset ranges come from each package's
 * `unicode.json`, the source of the `unicode-range` its CSS declares.
 *
 * Text is collected with the TypeScript parser: string literals, template
 * text and JSX text in `tests/admin-ui/gallery/**` and `assets/admin/src/**`.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);
const SOURCES = [
	join( ROOT, 'tests', 'admin-ui', 'gallery' ),
	join( ROOT, 'assets', 'admin', 'src' ),
];
const SKIPPED_DIRECTORIES = new Set( [ 'build', 'node_modules' ] );

/**
 * Characters the Linux visual lane can never render, by file and character,
 * each with its reason.
 */
const NOT_RENDERED_ON_LINUX = {
	'assets/admin/src/ui/overlays/CommandPalette.tsx': {
		'⌘': 'the macOS Command key glyph, shown only when the shortcut modifier is meta; the Linux runner shows Ctrl',
	},
};

/** Unicode blocks of the Arabic script (Arabic, Supplement, Extended-A/B, Presentation Forms-A/B). */
const ARABIC_BLOCKS = [
	[ 0x0600, 0x06ff ],
	[ 0x0750, 0x077f ],
	[ 0x0870, 0x08ff ],
	[ 0xfb50, 0xfdff ],
	[ 0xfe70, 0xfeff ],
];

function ranges( packageName, subset ) {
	const map = JSON.parse(
		readFileSync(
			join( ROOT, 'node_modules', packageName, 'unicode.json' ),
			'utf8'
		)
	);
	const spec = map[ subset ];
	if ( typeof spec !== 'string' ) {
		throw new Error( `${ packageName } has no ${ subset } subset` );
	}
	return spec.split( ',' ).map( ( part ) => {
		const [ from, to ] = part.trim().replace( /^U\+/i, '' ).split( '-' );
		return [ parseInt( from, 16 ), parseInt( to ?? from, 16 ) ];
	} );
}

const inRanges = ( code, list ) =>
	list.some( ( [ from, to ] ) => code >= from && code <= to );

function walk( directory ) {
	return readdirSync( directory, { withFileTypes: true } ).flatMap(
		( entry ) => {
			const path = join( directory, entry.name );
			if ( entry.isDirectory() ) {
				return SKIPPED_DIRECTORIES.has( entry.name )
					? []
					: walk( path );
			}
			return /\.tsx?$/.test( entry.name ) ? [ path ] : [];
		}
	);
}

function textsOf( path ) {
	const source = ts.createSourceFile(
		path,
		readFileSync( path, 'utf8' ),
		ts.ScriptTarget.Latest,
		true,
		path.endsWith( '.tsx' ) ? ts.ScriptKind.TSX : ts.ScriptKind.TS
	);
	const texts = [];
	const visit = ( node ) => {
		const isModuleSpecifier =
			ts.isStringLiteral( node ) &&
			( ts.isImportDeclaration( node.parent ) ||
				ts.isExportDeclaration( node.parent ) ||
				ts.isExternalModuleReference( node.parent ) );
		if (
			! isModuleSpecifier &&
			( ts.isStringLiteral( node ) ||
				ts.isNoSubstitutionTemplateLiteral( node ) ||
				ts.isTemplateHead( node ) ||
				ts.isTemplateMiddle( node ) ||
				ts.isTemplateTail( node ) ||
				ts.isJsxText( node ) )
		) {
			texts.push( node.text );
		}
		ts.forEachChild( node, visit );
	};
	visit( source );
	return texts;
}

function uncovered() {
	const latin = ranges( '@fontsource/noto-sans', 'latin' );
	const mono = ranges( '@fontsource/noto-sans-mono', 'latin' );
	const arabic = ranges( '@fontsource/noto-sans-arabic', 'arabic' );
	const misses = new Map();
	for ( const path of SOURCES.flatMap( walk ) ) {
		const file = relative( ROOT, path ).split( sep ).join( '/' );
		const exempt = NOT_RENDERED_ON_LINUX[ file ] ?? {};
		for ( const text of textsOf( path ) ) {
			for ( const char of text ) {
				if ( char in exempt ) {
					continue;
				}
				const code = char.codePointAt( 0 );
				const covered = inRanges( code, ARABIC_BLOCKS )
					? inRanges( code, arabic )
					: inRanges( code, latin ) && inRanges( code, mono );
				if ( ! covered ) {
					const key = `U+${ code
						.toString( 16 )
						.toUpperCase()
						.padStart( 4, '0' ) } ${ char }`;
					misses.set( key, [
						...new Set( [ ...( misses.get( key ) ?? [] ), file ] ),
					] );
				}
			}
		}
	}
	return Object.fromEntries( misses );
}

test( 'the Latin and Arabic subsets are read from the vendored packages', () => {
	expect(
		inRanges(
			'A'.codePointAt( 0 ),
			ranges( '@fontsource/noto-sans', 'latin' )
		)
	).toBe( true );
	expect(
		inRanges(
			'ع'.codePointAt( 0 ),
			ranges( '@fontsource/noto-sans-arabic', 'arabic' )
		)
	).toBe( true );
	expect(
		inRanges(
			'ع'.codePointAt( 0 ),
			ranges( '@fontsource/noto-sans', 'latin' )
		)
	).toBe( false );
} );

test( 'every rendered character falls inside a vendored subset', () => {
	expect( uncovered() ).toEqual( {} );
} );

test( 'every exception is still in its file and names its reason', () => {
	for ( const [ file, chars ] of Object.entries( NOT_RENDERED_ON_LINUX ) ) {
		const text = textsOf( join( ROOT, file ) ).join( '' );
		for ( const [ char, reason ] of Object.entries( chars ) ) {
			expect( text ).toContain( char );
			expect( reason.length ).toBeGreaterThan( 20 );
		}
	}
} );
