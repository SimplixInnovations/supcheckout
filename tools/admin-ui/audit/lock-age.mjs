/**
 * Lockfile release-age check (the 14-day `min-release-age` floor, enforced on
 * package-lock.json itself).
 *
 * Diffs package-lock.json against the merge-base with origin/main (against
 * HEAD^ when the head is already on main, as on a push to main), looks up the
 * registry publish time of every added `name@version` with `npm view`, and
 * applies `lock-age-policy.mjs` with the reviewed `lock-age-exceptions.json`.
 *
 * Needs full history (CI checks out with `fetch-depth: 0`) and registry
 * access. Run through npm (`npm run audit:lock-age`) so `npm_execpath` names
 * the npm CLI.
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

import {
	baseRevision,
	changedPackages,
	evaluateLockAge,
	lockedKeys,
} from './lock-age-policy.mjs';

const LOCK_URL = new URL( '../../../package-lock.json', import.meta.url );
const EXCEPTIONS_URL = new URL( './lock-age-exceptions.json', import.meta.url );
const LOCK_PATH = 'package-lock.json';

const readJson = ( url ) => JSON.parse( readFileSync( url, 'utf8' ) );

function git( args ) {
	return execFileSync( 'git', args, {
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

function mergeBaseWithMain() {
	try {
		return git( [ 'merge-base', 'HEAD', 'origin/main' ] );
	} catch ( error ) {
		throw new Error(
			`cannot find the merge-base with origin/main (fetch full history, e.g. fetch-depth: 0): ${ error.stderr || error.message }`
		);
	}
}

function lockAt( revision ) {
	try {
		git( [ 'cat-file', '-e', `${ revision }:${ LOCK_PATH }` ] );
	} catch {
		return null;
	}
	return JSON.parse( git( [ 'show', `${ revision }:${ LOCK_PATH }` ] ) );
}

function npmView( spec ) {
	const npmCli = process.env.npm_execpath;
	if ( ! npmCli ) {
		throw new Error( 'Run this check through npm: npm run audit:lock-age' );
	}
	// The child npm reads the .npmrc files itself; the parent's flattened
	// `npm_config_*` variables would otherwise look like CLI flags to it.
	const env = Object.fromEntries(
		Object.entries( process.env ).filter(
			( [ name ] ) => ! name.toLowerCase().startsWith( 'npm_config_' )
		)
	);
	try {
		return JSON.parse(
			execFileSync(
				process.execPath,
				[ npmCli, 'view', spec, 'time', '--json' ],
				{
					encoding: 'utf8',
					env,
					maxBuffer: 64 * 1024 * 1024,
					stdio: [ 'ignore', 'pipe', 'pipe' ],
				}
			)
		);
	} catch ( error ) {
		process.stderr.write(
			`npm view ${ spec } time failed: ${ error.stderr || error.message }\n`
		);
		return null;
	}
}

/**
 * Registry publish times by `name@version`, one `npm view` per package name.
 *
 * @param {{name: string, version: string}[]} packages Pairs to look up.
 * @return {Object<string, string|undefined>} ISO publish time by `name@version`.
 */
function publishTimesOf( packages ) {
	const byName = new Map();
	const times = {};
	for ( const { name, version } of packages ) {
		if ( ! byName.has( name ) ) {
			byName.set( name, npmView( `${ name }@${ version }` ) );
		}
		const time = byName.get( name );
		times[ `${ name }@${ version }` ] =
			time && typeof time[ version ] === 'string'
				? time[ version ]
				: undefined;
	}
	return times;
}

const head = git( [ 'rev-parse', 'HEAD' ] );
const base = baseRevision( { head, mergeBase: mergeBaseWithMain() } );
const headLock = readJson( LOCK_URL );
const exceptions = readJson( EXCEPTIONS_URL );
const changed = changedPackages( lockAt( base ), headLock );
const excepted = new Set(
	Array.isArray( exceptions )
		? exceptions.map( ( entry ) => `${ entry?.name }@${ entry?.version }` )
		: []
);
const toLookUp = changed.filter(
	( entry ) => ! excepted.has( `${ entry.name }@${ entry.version }` )
);

const failures = evaluateLockAge( {
	changed,
	publishTimes: publishTimesOf( toLookUp ),
	exceptions,
	locked: lockedKeys( headLock ),
	now: Date.now(),
} );

const resolvedBase = git( [ 'rev-parse', base ] );
const summary = `base ${
	resolvedBase === base ? base : `${ base } (${ resolvedBase })`
}, ${ changed.length } added name@version, ${
	changed.length - toLookUp.length
} excepted`;
if ( failures.length > 0 ) {
	process.stderr.write(
		`lockfile release-age check: FAIL (${ summary })\n- ${ failures.join(
			'\n- '
		) }\n`
	);
	process.exit( 1 );
}
process.stdout.write( `lockfile release-age check: PASS (${ summary })\n` );
