/**
 * Renders assets/admin/src/tokens/tokens.generated.css from the token JSON.
 *
 * Usage: node tools/admin-ui/tokens/build-tokens.mjs [--check] [--brand <path>] [--out <path>]
 *
 *   --check   Fail (exit 1) when the output file differs from a fresh render.
 *   --brand   Brand file to merge (default: assets/admin/src/tokens/brand.tokens.json).
 *   --out     Output file (default: assets/admin/src/tokens/tokens.generated.css).
 *
 * The token set must pass the WCAG 2.2 AA contrast check, so an inaccessible
 * brand file can never produce a stylesheet.
 */
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';

import { checkContrast } from './contrast.mjs';
import { loadTokens, renderCss, ROOT, TOKENS_DIR } from './resolve.mjs';

const DEFAULT_OUT = join( TOKENS_DIR, 'tokens.generated.css' );

function parseArgs( argv ) {
	const options = { check: false, brand: undefined, out: DEFAULT_OUT };
	for ( let i = 0; i < argv.length; i++ ) {
		const arg = argv[ i ];
		if ( arg === '--check' ) {
			options.check = true;
		} else if ( arg === '--brand' || arg === '--out' ) {
			const value = argv[ ++i ];
			if ( ! value ) {
				throw new Error( `${ arg } needs a path` );
			}
			options[ arg.slice( 2 ) ] = resolve( value );
		} else {
			throw new Error( `unknown argument: ${ arg }` );
		}
	}
	return options;
}

function formatFailure( failure ) {
	return `contrast ${ failure.mode }/${ failure.scheme }: ${ failure.fg } on ${ failure.bg } is ${ failure.ratio }:1, needs ${ failure.required }:1`;
}

function main( argv ) {
	const options = parseArgs( argv );
	const set = loadTokens( { brandPath: options.brand } );
	const failures = checkContrast( set );
	if ( failures.length > 0 ) {
		throw new Error( failures.map( formatFailure ).join( '\n' ) );
	}
	const css = renderCss( set );
	const label = relative( ROOT, options.out ).split( '\\' ).join( '/' );
	if ( options.check ) {
		const current = existsSync( options.out )
			? readFileSync( options.out, 'utf8' )
			: null;
		if ( current !== css ) {
			throw new Error(
				`${ label } is stale; run node tools/admin-ui/tokens/build-tokens.mjs`
			);
		}
		process.stdout.write( `${ label } is up to date\n` );
		return;
	}
	mkdirSync( dirname( options.out ), { recursive: true } );
	writeFileSync( options.out, css );
	process.stdout.write( `wrote ${ label }\n` );
}

try {
	main( process.argv.slice( 2 ) );
} catch ( error ) {
	process.stderr.write( `build-tokens: ${ error.message }\n` );
	process.exitCode = 1;
}
