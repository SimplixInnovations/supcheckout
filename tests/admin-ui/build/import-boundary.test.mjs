/**
 * The UI library is the only import surface: outside assets/admin/src/ui,
 * ESLint forbids Base UI (every `@base-ui/*` package), Floating UI,
 * lucide-react and `@wordpress/components`; inside it, only
 * `@wordpress/components` stays forbidden.
 *
 * Lints an in-memory probe through the real eslint.config.mjs (stdin with a
 * virtual file name), so no probe file is ever written to the tree.
 */
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);

const PROBE = [
	"import '@base-ui/react';",
	"import '@base-ui/react/dialog';",
	"import '@base-ui/utils';",
	"import '@floating-ui/react-dom';",
	"import '@floating-ui/utils/dom';",
	"import 'lucide-react';",
	"import 'lucide-react/dynamic';",
	"import '@wordpress/components';",
	"import '@wordpress/element';",
	'',
].join( '\n' );

/**
 * @param {string} virtualPath Repository-relative file name for the probe.
 * @return {string[]} Sources rejected by no-restricted-imports, in order.
 */
function restrictedImports( virtualPath ) {
	const result = spawnSync(
		process.execPath,
		[
			join( ROOT, 'node_modules', 'eslint', 'bin', 'eslint.js' ),
			'--format',
			'json',
			'--stdin',
			'--stdin-filename',
			virtualPath,
		],
		{ cwd: ROOT, input: PROBE, encoding: 'utf8' }
	);
	if ( result.error ) {
		throw result.error;
	}
	const [ report ] = JSON.parse( result.stdout );
	const lines = PROBE.split( '\n' );
	return report.messages
		.filter( ( message ) => message.ruleId === 'no-restricted-imports' )
		.map(
			( message ) => lines[ message.line - 1 ].match( /'([^']+)'/ )[ 1 ]
		);
}

test( 'screens may not import Base UI, Floating UI, lucide-react or @wordpress/components', () => {
	expect( restrictedImports( 'assets/admin/src/app/probe.tsx' ) ).toEqual( [
		'@base-ui/react',
		'@base-ui/react/dialog',
		'@base-ui/utils',
		'@floating-ui/react-dom',
		'@floating-ui/utils/dom',
		'lucide-react',
		'lucide-react/dynamic',
		'@wordpress/components',
	] );
}, 60000 );

test( 'tests are held to the same boundary', () => {
	expect(
		restrictedImports( 'tests/admin-ui/unit/probe.test.tsx' )
	).toHaveLength( 8 );
}, 60000 );

test( 'the UI library may wrap the primitives but never @wordpress/components', () => {
	expect(
		restrictedImports( 'assets/admin/src/ui/foundations/probe.tsx' )
	).toEqual( [ '@wordpress/components' ] );
}, 60000 );
