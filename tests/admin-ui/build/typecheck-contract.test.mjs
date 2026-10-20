/**
 * `npm run typecheck` covers the shipped source and every TypeScript test,
 * gallery and Playwright file, and the tests config never ships.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);

function readJson( relativePath ) {
	return JSON.parse( readFileSync( join( ROOT, relativePath ), 'utf8' ) );
}

test( 'typecheck runs the source project and the tests project', () => {
	expect( readJson( 'package.json' ).scripts.typecheck ).toBe(
		'tsc --noEmit --project tsconfig.json && tsc --noEmit --project tsconfig.tests.json'
	);
} );

test( 'the tests project extends the source config and includes every admin UI test file', () => {
	const config = readJson( 'tsconfig.tests.json' );
	expect( config.extends ).toBe( './tsconfig.json' );
	expect( config.include ).toEqual(
		expect.arrayContaining( [
			'tests/admin-ui/**/*.ts',
			'tests/admin-ui/**/*.tsx',
		] )
	);
	expect( config.compilerOptions.types ).toEqual(
		expect.arrayContaining( [ 'node', 'jest', '@playwright/test' ] )
	);
	expect( config.compilerOptions.noEmit ?? true ).toBe( true );
} );

test( 'the type packages are exact-pinned development dependencies', () => {
	const pkg = readJson( 'package.json' );
	for ( const name of [ '@types/node', '@types/jest' ] ) {
		expect( pkg.devDependencies[ name ] ).toMatch( /^\d+\.\d+\.\d+$/ );
		expect( pkg.dependencies?.[ name ] ).toBeUndefined();
	}
} );

test( 'tsconfig.tests.json never ships', () => {
	const distignore = readFileSync( join( ROOT, '.distignore' ), 'utf8' )
		.split( /\r?\n/ )
		.filter( Boolean );
	expect( distignore ).toContain( '/tsconfig.tests.json' );
	expect(
		readFileSync( join( ROOT, 'scripts', 'verify-release.sh' ), 'utf8' )
	).toContain( '"tsconfig.tests.json"' );
} );
