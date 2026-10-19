/**
 * Contract for the permanent Admin UI Certification workflow: the drift and
 * toolchain checks it must keep.
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
const WORKFLOW = readFileSync(
	join( ROOT, '.github/workflows/admin-ui-certification.yml' ),
	'utf8'
);

function job( name ) {
	const match = WORKFLOW.match(
		new RegExp( `\\n  ${ name }:\\n([\\s\\S]*?)(?=\\n  [a-z-]+:\\n|$)` )
	);
	return match ? match[ 1 ] : '';
}

test( 'the static second build starts from an empty build directory and cache', () => {
	expect( job( 'static' ) ).toMatch(
		/rm -rf assets\/admin\/build node_modules\/\.cache && npm run build && npm run check:drift/
	);
} );

test( 'both lanes assert the pinned npm version', () => {
	const assertion =
		'test "$(npm --version)" = "$(node -p \'require("./package.json").engines.npm\')"';
	expect( job( 'static' ) ).toContain( assertion );
	expect( job( 'build-windows' ) ).toContain( assertion );
} );

test( 'the Windows lane rejects CRLF and mixed endings in the working-tree build', () => {
	const windows = job( 'build-windows' );
	expect( windows ).toContain( 'git ls-files --eol -- assets/admin/build' );
	expect( windows ).toContain( "grep -E 'w/(crlf|mixed)'" );
} );

test( 'the gate needs both lanes and treats skipped or cancelled as failure', () => {
	const gate = job( 'gate' );
	expect( gate ).toMatch( /- static\n\s+- build-windows/ );
	expect( gate ).toContain( 'if: ${{ always() }}' );
	expect( gate ).toMatch( /!= success/ );
} );
