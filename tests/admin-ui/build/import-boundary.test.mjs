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
 * @param {string} [probe]     Source to lint (default: the primitives probe).
 * @return {string[]} Sources rejected by no-restricted-imports, in order.
 */
function restrictedImports( virtualPath, probe = PROBE ) {
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
		{ cwd: ROOT, input: probe, encoding: 'utf8' }
	);
	if ( result.error ) {
		throw result.error;
	}
	const [ report ] = JSON.parse( result.stdout );
	const lines = probe.split( '\n' );
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

const GALLERY_PROBE = [
	"import '../../../assets/admin/src/ui';",
	"import '../../../assets/admin/src/tokens/tokens.generated.css';",
	"import '../../../../assets/admin/src/ui';",
	"import '../../../assets/admin/src/ui/foundations/Heading';",
	"import '../../../assets/admin/src/ui/foundations/foundations.css';",
	"import '../../../../assets/admin/src/ui/foundations/portal';",
	"import '@base-ui/react/dialog';",
	"import '@wordpress/components';",
	'',
].join( '\n' );

test( 'the gallery imports the UI library only through its barrel', () => {
	expect(
		restrictedImports( 'tests/admin-ui/gallery/probe.tsx', GALLERY_PROBE )
	).toEqual( [
		'../../../assets/admin/src/ui/foundations/Heading',
		'../../../assets/admin/src/ui/foundations/foundations.css',
		'../../../../assets/admin/src/ui/foundations/portal',
		'@base-ui/react/dialog',
		'@wordpress/components',
	] );
	expect(
		restrictedImports(
			'tests/admin-ui/gallery/specimens/probe.tsx',
			GALLERY_PROBE
		)
	).toHaveLength( 5 );
}, 60000 );

test( 'component tests may still import UI internals directly', () => {
	expect(
		restrictedImports( 'tests/admin-ui/unit/probe.test.tsx', GALLERY_PROBE )
	).toEqual( [ '@base-ui/react/dialog', '@wordpress/components' ] );
}, 60000 );
