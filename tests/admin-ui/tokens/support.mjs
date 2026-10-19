/**
 * Shared paths and readers for the token test suites.
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);

export const TOKENS_DIR = join( ROOT, 'assets', 'admin', 'src', 'tokens' );
export const GENERATED_CSS = join( TOKENS_DIR, 'tokens.generated.css' );
export const BUILD_TOKENS_CLI = join(
	ROOT,
	'tools',
	'admin-ui',
	'tokens',
	'build-tokens.mjs'
);

/**
 * Reads a repository file as UTF-8.
 *
 * @param {string} relativePath Path relative to the repository root.
 * @return {string} File contents.
 */
export function read( relativePath ) {
	return readFileSync( join( ROOT, relativePath ), 'utf8' );
}

/**
 * Reads a repository JSON file.
 *
 * @param {string} relativePath Path relative to the repository root.
 * @return {unknown} Parsed JSON.
 */
export function readJson( relativePath ) {
	return JSON.parse( read( relativePath ) );
}

/**
 * Absolute path of a token fixture.
 *
 * @param {string} name Fixture name without `.tokens.json`.
 * @return {string} Absolute path.
 */
export function fixture( name ) {
	return join(
		ROOT,
		'tests',
		'admin-ui',
		'tokens',
		'fixtures',
		`${ name }.tokens.json`
	);
}

/**
 * Contents of a CSS fixture.
 *
 * @param {string} name Fixture name without `.css`.
 * @return {string} CSS source.
 */
export function fixtureCss( name ) {
	return read( `tests/admin-ui/tokens/fixtures/${ name }.css` );
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
 * Lists repository files under a directory that end with a suffix.
 *
 * @param {string} relativeDirectory Directory relative to the repository root.
 * @param {string} suffix            File-name suffix, e.g. `.css`.
 * @return {string[]} Sorted repository-relative paths with forward slashes.
 */
export function listFiles( relativeDirectory, suffix ) {
	return walk( join( ROOT, relativeDirectory ) )
		.filter( ( path ) => path.endsWith( suffix ) )
		.map( ( path ) => relative( ROOT, path ).split( sep ).join( '/' ) )
		.sort();
}

/**
 * Body of the first top-level block whose prelude starts with `prelude`.
 *
 * Handles one level of nesting (an at-rule wrapping rules), which is all the
 * generated token CSS uses.
 *
 * @param {string} css     Stylesheet.
 * @param {string} prelude Exact selector or at-rule prelude to find.
 * @return {string} The text between the block's braces, or '' when absent.
 */
export function blockAfter( css, prelude ) {
	const start = css.indexOf( `${ prelude } {` );
	if ( start === -1 ) {
		return '';
	}
	let depth = 0;
	for ( let i = css.indexOf( '{', start ); i < css.length; i++ ) {
		if ( css[ i ] === '{' ) {
			depth++;
		} else if ( css[ i ] === '}' ) {
			depth--;
			if ( depth === 0 ) {
				return css.slice( css.indexOf( '{', start ) + 1, i );
			}
		}
	}
	return '';
}
