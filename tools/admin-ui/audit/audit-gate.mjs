/**
 * Full-tree npm audit gate (the `--audit-level=high` contract).
 *
 * Runs `npm audit` on the whole locked tree and on the production tree, then
 * applies `audit-policy.mjs`: every high or critical advisory fails unless a
 * valid, unexpired `audit-allowlist.json` entry covers it, the entry is still
 * reported, it does not reach the production tree and its package is not
 * bundled into the admin build. The bundled tree is also gated separately by
 * `npm audit --omit=dev`, which must report nothing at all.
 *
 * Run through npm (`npm run audit:full`) so `npm_execpath` names the npm CLI.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import { advisoriesOf, evaluateAudit } from './audit-policy.mjs';

const ALLOWLIST_URL = new URL( './audit-allowlist.json', import.meta.url );
const NOTICES_URL = new URL(
	'../../../assets/admin/build/third-party-notices.json',
	import.meta.url
);

function runAudit( extraArgs ) {
	const npmCli = process.env.npm_execpath;
	if ( ! npmCli ) {
		throw new Error( 'Run this gate through npm: npm run audit:full' );
	}
	const args = [ npmCli, 'audit', '--json', ...extraArgs ];
	// The child npm reads the .npmrc files itself; the parent's flattened
	// `npm_config_*` variables would otherwise look like CLI flags to it.
	const env = Object.fromEntries(
		Object.entries( process.env ).filter(
			( [ name ] ) => ! name.toLowerCase().startsWith( 'npm_config_' )
		)
	);
	let stdout;
	try {
		stdout = execFileSync( process.execPath, args, {
			encoding: 'utf8',
			env,
			maxBuffer: 64 * 1024 * 1024,
		} );
	} catch ( error ) {
		// `npm audit` exits non-zero whenever it finds anything; the JSON
		// report is still on stdout. Anything else is a real failure.
		if ( typeof error.stdout !== 'string' || error.stdout === '' ) {
			throw error;
		}
		stdout = error.stdout;
	}
	const report = JSON.parse( stdout );
	if ( report.error || ! report.vulnerabilities ) {
		throw new Error( `npm audit did not produce a report: ${ stdout }` );
	}
	return report;
}

const readJson = ( url ) => JSON.parse( readFileSync( url, 'utf8' ) );

const allowlist = readJson( ALLOWLIST_URL );
const full = advisoriesOf( runAudit( [] ) );
const failures = evaluateAudit( {
	full,
	production: advisoriesOf( runAudit( [ '--omit=dev' ] ) ),
	allowlist,
	bundledNames: readJson( NOTICES_URL ).map( ( pkg ) => pkg.name ),
	today: new Date().toISOString().slice( 0, 10 ),
} );

if ( failures.length > 0 ) {
	process.stderr.write(
		`npm audit gate: FAIL\n- ${ failures.join( '\n- ' ) }\n`
	);
	process.exit( 1 );
}
process.stdout.write(
	`npm audit gate: PASS (${ full.length } advisories, ${ allowlist.length } allow-listed development-only)\n`
);
