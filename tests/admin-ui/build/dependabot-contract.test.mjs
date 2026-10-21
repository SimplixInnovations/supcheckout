/**
 * Contract for `.github/dependabot.yml`: review routing comes from
 * CODEOWNERS (the deprecated `reviewers` key is gone), npm keeps its release
 * cooldown, and `@types/node` tracks the Node 24 runtime.
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
const read = ( relative ) => readFileSync( join( ROOT, relative ), 'utf8' );
const DEPENDABOT = read( '.github/dependabot.yml' ).replace( /\r\n/g, '\n' );

/** @return {Map<string, string>} Each `updates` entry's text, by ecosystem. */
function ecosystems() {
	const blocks = new Map();
	for ( const block of DEPENDABOT.split( /\n(?=  - package-ecosystem: )/ ).slice(
		1
	) ) {
		const name = block.match( /^ {2}- package-ecosystem: (\S+)/ )[ 1 ];
		blocks.set( name, block );
	}
	return blocks;
}

test( 'dependabot covers github-actions, composer and npm', () => {
	expect( [ ...ecosystems().keys() ] ).toEqual( [
		'github-actions',
		'composer',
		'npm',
	] );
} );

test( 'no ecosystem uses the deprecated reviewers key; CODEOWNERS owns every path', () => {
	for ( const [ name, block ] of ecosystems() ) {
		expect( { name, reviewers: /^\s+reviewers:/m.test( block ) } ).toEqual( {
			name,
			reviewers: false,
		} );
	}
	const owners = read( '.github/CODEOWNERS' );
	expect( owners ).toMatch( /^\*\s+@SimplixInnovationsAdmin\s*$/m );
} );

test( 'npm keeps the 14-day cooldown', () => {
	expect( ecosystems().get( 'npm' ) ).toMatch(
		/\n {4}cooldown:\n {6}default-days: 14\n/
	);
} );

test( '@types/node majors above the Node 24 runtime are ignored', () => {
	const runtimeMajor = read( '.nvmrc' ).trim().split( '.' )[ 0 ];
	expect( runtimeMajor ).toBe( '24' );
	expect( ecosystems().get( 'npm' ) ).toMatch(
		new RegExp(
			`\\n {6}- dependency-name: "@types/node"\\n {8}versions: \\[">= ${
				Number( runtimeMajor ) + 1
			}"\\]\\n`
		)
	);
} );
