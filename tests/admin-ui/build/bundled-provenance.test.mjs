/**
 * Every package the notices plugin reports as bundled must be a locked,
 * production (non-dev) dependency at the same version.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { unlockedBundledPackages } from '../../../tools/admin-ui/build/bundled-provenance.mjs';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);

function readJson( relativePath ) {
	return JSON.parse( readFileSync( join( ROOT, relativePath ), 'utf8' ) );
}

const LOCK = {
	lockfileVersion: 3,
	packages: {
		'': { name: 'supcheckout' },
		'node_modules/@base-ui/react': { version: '1.8.0' },
		'node_modules/@base-ui/react/node_modules/tabbable': {
			version: '6.2.0',
		},
		'node_modules/typescript': { version: '6.0.3', dev: true },
		'node_modules/fsevents': {
			version: '2.3.3',
			dev: true,
			optional: true,
		},
		'node_modules/maybe-dev': { version: '1.0.0', devOptional: true },
	},
};

test( 'a synthetic notices fixture with unlocked, dev-only and mismatched packages fails', () => {
	const notices = [
		{ name: '@base-ui/react', version: '1.9.0', license: 'MIT' },
		{ name: 'typescript', version: '6.0.3', license: 'Apache-2.0' },
		{ name: 'maybe-dev', version: '1.0.0', license: 'MIT' },
		{ name: 'not-installed', version: '0.0.1', license: 'MIT' },
	];
	expect( unlockedBundledPackages( notices, LOCK ) ).toEqual( [
		'@base-ui/react@1.9.0',
		'typescript@6.0.3',
		'maybe-dev@1.0.0',
		'not-installed@0.0.1',
	] );
} );

test( 'a synthetic notices fixture of locked production packages passes, nested included', () => {
	const notices = [
		{ name: '@base-ui/react', version: '1.8.0', license: 'MIT' },
		{ name: 'tabbable', version: '6.2.0', license: 'MIT' },
	];
	expect( unlockedBundledPackages( notices, LOCK ) ).toEqual( [] );
} );

test( 'every committed bundled package is a locked production dependency', () => {
	expect(
		unlockedBundledPackages(
			readJson( 'assets/admin/build/third-party-notices.json' ),
			readJson( 'package-lock.json' )
		)
	).toEqual( [] );
} );
