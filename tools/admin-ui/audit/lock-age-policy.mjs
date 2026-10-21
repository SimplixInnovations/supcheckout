/**
 * Pure policy for the lockfile release-age check (`npm run audit:lock-age`).
 *
 * `.npmrc` sets `min-release-age=14`, but npm applies it only when it resolves
 * versions; a lockfile written elsewhere (or by a bot) can still pin a younger
 * release. This policy closes that gap: every `name@version` the change adds
 * to package-lock.json must have a registry publish time at least 14 days old,
 * unless a reviewed, expiring exception covers that exact pair.
 *
 * Exceptions follow the audit allow-list rules: exact name and version, a
 * non-empty reason, and an expiry that is real, not past and at most 90 days
 * away. An exception for a pair that is no longer locked is stale and fails.
 */
import { expiryProblem, isNonEmptyString } from './audit-policy.mjs';

export const MIN_RELEASE_AGE_DAYS = 14;

const DAY_MS = 24 * 60 * 60 * 1000;
const EXACT_VERSION =
	/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/;
const EXCEPTION_KEYS = new Set( [ 'name', 'version', 'reason', 'expires' ] );

const keyOf = ( { name, version } ) => `${ name }@${ version }`;

/**
 * @param {unknown} lock Parsed package-lock.json (v2/v3 `packages`), or null.
 * @return {Map<string, {name: string, version: string}>} Registry packages by `name@version`.
 */
function lockedPackages( lock ) {
	const packages = new Map();
	for ( const [ path, meta ] of Object.entries( lock?.packages ?? {} ) ) {
		if ( path === '' || ! meta || meta.link || ! meta.version ) {
			continue;
		}
		// An npm alias (`react-19` -> `npm:react@19.3.0`) is installed under
		// its alias path; `name` holds the real registry package.
		const name = meta.name || path.split( 'node_modules/' ).pop();
		const entry = { name, version: meta.version };
		packages.set( keyOf( entry ), entry );
	}
	return packages;
}

/**
 * @param {unknown} lock Parsed package-lock.json.
 * @return {string[]} Sorted, unique `name@version` keys of its registry packages.
 */
export function lockedKeys( lock ) {
	return [ ...lockedPackages( lock ).keys() ].sort();
}

/**
 * @param {unknown} baseLock Lockfile at the base revision, or null when it had none.
 * @param {unknown} headLock Lockfile under review.
 * @return {{name: string, version: string}[]} Pairs the head adds, sorted by `name@version`.
 */
export function changedPackages( baseLock, headLock ) {
	const base = lockedPackages( baseLock );
	return [ ...lockedPackages( headLock ) ]
		.filter( ( [ key ] ) => ! base.has( key ) )
		.sort( ( [ a ], [ b ] ) => ( a < b ? -1 : 1 ) )
		.map( ( [ , entry ] ) => entry );
}

/**
 * The revision to diff against: the merge-base with origin/main, or `HEAD^`
 * when the head is already on main (a push to main).
 *
 * @param {{head: string, mergeBase: string}} revisions Full commit ids.
 * @return {string} A git revision.
 */
export function baseRevision( { head, mergeBase } ) {
	return mergeBase === head ? 'HEAD^' : mergeBase;
}

function exceptionProblems( entry, today ) {
	const label = `lock-age exception ${ JSON.stringify(
		`${ entry?.name ?? '' }@${ entry?.version ?? '' }`
	) }`;
	const problems = [];
	if ( ! isNonEmptyString( entry?.name ) ) {
		problems.push( 'name must be a non-empty string' );
	}
	if (
		typeof entry?.version !== 'string' ||
		! EXACT_VERSION.test( entry.version )
	) {
		problems.push( 'version must be an exact version' );
	}
	if ( ! isNonEmptyString( entry?.reason ) ) {
		problems.push( 'reason must be a non-empty string' );
	}
	const expiry = expiryProblem( entry?.expires, today );
	if ( expiry ) {
		problems.push( expiry );
	}
	for ( const key of Object.keys( entry ?? {} ) ) {
		if ( ! EXCEPTION_KEYS.has( key ) ) {
			problems.push( `unexpected key ${ key }` );
		}
	}
	return problems.map( ( problem ) => `${ label }: ${ problem }` );
}

/**
 * @param {unknown} exceptions Parsed lock-age-exceptions.json.
 * @param {string}  today      Today as `YYYY-MM-DD` (UTC).
 * @return {string[]} One message per violation; empty when valid.
 */
export function validateExceptions( exceptions, today ) {
	if ( ! Array.isArray( exceptions ) ) {
		return [ 'lock-age exceptions must be a JSON array' ];
	}
	const problems = exceptions.flatMap( ( entry ) =>
		exceptionProblems( entry, today )
	);
	const seen = new Set();
	for ( const entry of exceptions ) {
		const key = keyOf( entry ?? {} );
		if ( seen.has( key ) ) {
			problems.push( `duplicate lock-age exception ${ key }` );
		}
		seen.add( key );
	}
	return problems;
}

function ageProblem( key, published, now ) {
	const time = typeof published === 'string' ? Date.parse( published ) : NaN;
	if ( Number.isNaN( time ) ) {
		return `${ key } has no registry publish time`;
	}
	const days = ( now - time ) / DAY_MS;
	if ( days >= MIN_RELEASE_AGE_DAYS ) {
		return null;
	}
	return `${ key } was published ${ new Date( time )
		.toISOString()
		.slice( 0, 10 ) } (${ Math.floor(
		days
	) } days ago), younger than the ${ MIN_RELEASE_AGE_DAYS }-day release-age floor`;
}

/**
 * @param {Object}                            input
 * @param {{name: string, version: string}[]} input.changed      Pairs the change adds.
 * @param {Object<string, string|undefined>}  input.publishTimes Registry publish time by `name@version`.
 * @param {unknown}                           input.exceptions   Parsed lock-age-exceptions.json.
 * @param {string[]}                          input.locked       Every `name@version` in the head lockfile.
 * @param {number}                            input.now          Current time in ms.
 * @return {string[]} Check failures; empty when the check passes.
 */
export function evaluateLockAge( {
	changed,
	publishTimes,
	exceptions,
	locked,
	now,
} ) {
	const today = new Date( now ).toISOString().slice( 0, 10 );
	const invalid = validateExceptions( exceptions, today );
	if ( invalid.length > 0 ) {
		return invalid;
	}
	const excepted = new Set( exceptions.map( keyOf ) );
	const failures = [];
	for ( const entry of changed ) {
		const key = keyOf( entry );
		if ( excepted.has( key ) ) {
			continue;
		}
		const problem = ageProblem( key, publishTimes[ key ], now );
		if ( problem ) {
			failures.push( problem );
		}
	}
	const lockedSet = new Set( locked );
	for ( const key of excepted ) {
		if ( ! lockedSet.has( key ) ) {
			failures.push(
				`stale lock-age exception ${ key }: not in package-lock.json, remove it`
			);
		}
	}
	return failures;
}
