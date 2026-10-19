/**
 * Runs the jsdom component tests on React 19 (`npm run test:unit:react19`).
 *
 * Sets `SUP_REACT=19`, which makes jest.config.js map `react` and `react-dom`
 * to the `react-19` / `react-dom-19` aliases. A Node wrapper instead of an
 * inline `SUP_REACT=19 jest …` keeps the script portable to cmd.exe without a
 * cross-env dependency. Extra arguments (such as a test path) pass through.
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

const result = spawnSync(
	process.execPath,
	[
		'--experimental-vm-modules',
		join( ROOT, 'node_modules', 'jest', 'bin', 'jest.js' ),
		'--config',
		join( ROOT, 'jest.config.js' ),
		'--selectProjects',
		'dom',
		...process.argv.slice( 2 ),
	],
	{ cwd: ROOT, stdio: 'inherit', env: { ...process.env, SUP_REACT: '19' } }
);

if ( result.error ) {
	throw result.error;
}
process.exit( result.status ?? 1 );
