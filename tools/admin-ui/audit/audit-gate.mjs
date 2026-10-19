/**
 * Full-tree npm audit gate (the `--audit-level=high` contract).
 *
 * Fails on every high or critical advisory in the locked tree, except one that
 * `audit-allowlist.json` names by GHSA id and package, that has not expired,
 * that is still reported (stale entries fail so the list cannot rot) and that
 * does not reach a production (bundled) dependency. The bundled tree is gated
 * separately by `npm audit --omit=dev`, which must report nothing at all.
 *
 * Run through npm (`npm run audit:full`) so `npm_execpath` names the npm CLI.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const BLOCKING = new Set( [ 'high', 'critical' ] );
const ALLOWLIST_URL = new URL( './audit-allowlist.json', import.meta.url );

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

function advisoriesOf( report ) {
	const advisories = new Map();
	for ( const vulnerability of Object.values(
		report.vulnerabilities || {}
	) ) {
		for ( const via of vulnerability.via ) {
			if ( typeof via === 'object' && via.url ) {
				const id = via.url.split( '/' ).pop();
				advisories.set( `${ id } ${ via.name }`, {
					id,
					package: via.name,
					severity: via.severity,
					title: via.title,
				} );
			}
		}
	}
	return [ ...advisories.values() ];
}

const allowlist = JSON.parse( readFileSync( ALLOWLIST_URL, 'utf8' ) );
const today = new Date().toISOString().slice( 0, 10 );
const full = advisoriesOf( runAudit( [] ) );
const production = advisoriesOf( runAudit( [ '--omit=dev' ] ) );
const failures = [];
const key = ( entry ) => `${ entry.id } ${ entry.package }`;
const allowed = new Map(
	allowlist.map( ( entry ) => [ key( entry ), entry ] )
);

for ( const advisory of full.filter( ( a ) => BLOCKING.has( a.severity ) ) ) {
	const entry = allowed.get( key( advisory ) );
	if ( ! entry ) {
		failures.push(
			`${ advisory.severity } ${ advisory.id } in ${ advisory.package }: ${ advisory.title }`
		);
	} else if ( entry.expires < today ) {
		failures.push(
			`allow-list entry ${ key( entry ) } expired on ${ entry.expires }`
		);
	}
}
for ( const entry of allowlist ) {
	if ( ! full.some( ( advisory ) => key( advisory ) === key( entry ) ) ) {
		failures.push(
			`stale allow-list entry ${ key( entry ) }: no longer reported, remove it`
		);
	}
	if ( production.some( ( advisory ) => advisory.id === entry.id ) ) {
		failures.push(
			`allow-listed ${ entry.id } reaches a production (bundled) dependency`
		);
	}
}

if ( failures.length > 0 ) {
	process.stderr.write(
		`npm audit gate: FAIL\n- ${ failures.join( '\n- ' ) }\n`
	);
	process.exit( 1 );
}
process.stdout.write(
	`npm audit gate: PASS (${ full.length } advisories, ${ allowlist.length } allow-listed development-only)\n`
);
