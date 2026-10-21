/**
 * Pure policy for the full-tree npm audit gate (`npm run audit:full`).
 *
 * The gate blocks every advisory rated low or above (only `info` is ignored).
 * Development tooling is held to the same bar as shipped code. An advisory passes only
 * through a valid allow-list entry: a GHSA id and package, the severity as
 * recorded (a raised live severity fails), a non-empty reason, and an expiry
 * date that is real, not past and at most 90 days away. A matching entry must
 * still be reported (stale entries fail), must not reach the production tree,
 * and its package must not be bundled into the admin build.
 */

const BLOCKING = new Set( [ 'low', 'moderate', 'high', 'critical' ] );
const SEVERITY_RANK = { info: 0, low: 1, moderate: 2, high: 3, critical: 4 };
const GHSA_ID = /^GHSA(-[23456789cfghjmpqrvwx]{4}){3}$/;
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
const MAX_EXPIRY_DAYS = 90;
const DAY_MS = 24 * 60 * 60 * 1000;

const keyOf = ( entry ) => `${ entry.id } ${ entry.package }`;

/**
 * @param {unknown} value Any value.
 * @return {boolean} True for a string with non-whitespace content.
 */
export function isNonEmptyString( value ) {
	return typeof value === 'string' && value.trim() !== '';
}

/**
 * @param {string} value A `YYYY-MM-DD` string.
 * @return {number|null} UTC midnight in ms, or null when it is not a real date.
 */
function utcDate( value ) {
	if ( typeof value !== 'string' || ! ISO_DATE.test( value ) ) {
		return null;
	}
	const [ year, month, day ] = value.split( '-' ).map( Number );
	const time = Date.UTC( year, month - 1, day );
	const date = new Date( time );
	const real =
		date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day;
	return real ? time : null;
}

/**
 * Shared with the lockfile release-age exceptions (lock-age-policy.mjs).
 *
 * @param {unknown} expires The entry's `expires` value.
 * @param {string}  today   Today as `YYYY-MM-DD` (UTC).
 * @return {string|null} Why the expiry is invalid, or null when it is a real
 *         date, not past and at most 90 days away.
 */
export function expiryProblem( expires, today ) {
	const expiry = utcDate( expires );
	if ( expiry === null ) {
		return `expires must be a real YYYY-MM-DD date, got ${ JSON.stringify(
			expires
		) }`;
	}
	const days = ( expiry - utcDate( today ) ) / DAY_MS;
	if ( days < 0 ) {
		return `expires ${ expires } is in the past`;
	}
	if ( days > MAX_EXPIRY_DAYS ) {
		return `expires ${ expires } is more than ${ MAX_EXPIRY_DAYS } days after ${ today }`;
	}
	return null;
}

function entryProblems( entry, today ) {
	const label = `allow-list entry ${ JSON.stringify( entry?.id ) }`;
	const problems = [];
	if ( typeof entry?.id !== 'string' || ! GHSA_ID.test( entry.id ) ) {
		problems.push( 'id must be a GHSA id' );
	}
	if ( ! isNonEmptyString( entry?.package ) ) {
		problems.push( 'package must be a non-empty string' );
	}
	if ( ! Object.hasOwn( SEVERITY_RANK, entry?.severity ?? '' ) ) {
		problems.push(
			'severity must be one of info, low, moderate, high, critical'
		);
	}
	const expiry = expiryProblem( entry?.expires, today );
	if ( expiry ) {
		problems.push( expiry );
	}
	if ( ! isNonEmptyString( entry?.reason ) ) {
		problems.push( 'reason must be a non-empty string' );
	}
	return problems.map( ( problem ) => `${ label }: ${ problem }` );
}

/**
 * @param {unknown} allowlist Parsed audit-allowlist.json.
 * @param {string}  today     Today as `YYYY-MM-DD` (UTC).
 * @return {string[]} One message per violation; empty when valid.
 */
export function validateAllowlist( allowlist, today ) {
	if ( ! Array.isArray( allowlist ) ) {
		return [ 'allow-list must be a JSON array' ];
	}
	const problems = allowlist.flatMap( ( entry ) =>
		entryProblems( entry, today )
	);
	const seen = new Set();
	for ( const entry of allowlist ) {
		const key = `${ entry?.id } ${ entry?.package }`;
		if ( seen.has( key ) ) {
			problems.push( `duplicate allow-list entry ${ key }` );
		}
		seen.add( key );
	}
	return problems;
}

/**
 * Flattens an `npm audit --json` report into unique advisories.
 *
 * @param {{ vulnerabilities?: Object }} report npm audit JSON.
 * @return {{ id: string, package: string, severity: string, title: string }[]} Advisories.
 */
export function advisoriesOf( report ) {
	const advisories = new Map();
	for ( const vulnerability of Object.values(
		report.vulnerabilities || {}
	) ) {
		for ( const via of vulnerability.via ) {
			if ( typeof via === 'object' && via.url ) {
				const advisory = {
					id: via.url.split( '/' ).pop(),
					package: via.name,
					severity: via.severity,
					title: via.title,
				};
				advisories.set( keyOf( advisory ), advisory );
			}
		}
	}
	return [ ...advisories.values() ];
}

/**
 * @param {Object}   input
 * @param {Object[]} input.full         Advisories of the whole locked tree.
 * @param {Object[]} input.production   Advisories of the production tree.
 * @param {unknown}  input.allowlist    Parsed audit-allowlist.json.
 * @param {string[]} input.bundledNames Package names in third-party-notices.json.
 * @param {string}   input.today        Today as `YYYY-MM-DD` (UTC).
 * @return {string[]} Gate failures; empty when the gate passes.
 */
export function evaluateAudit( {
	full,
	production,
	allowlist,
	bundledNames,
	today,
} ) {
	const invalid = validateAllowlist( allowlist, today );
	if ( invalid.length > 0 ) {
		return invalid;
	}
	const failures = [];
	const allowed = new Map(
		allowlist.map( ( entry ) => [ keyOf( entry ), entry ] )
	);
	for ( const advisory of full.filter( ( a ) =>
		BLOCKING.has( a.severity )
	) ) {
		const entry = allowed.get( keyOf( advisory ) );
		if ( ! entry ) {
			failures.push(
				`${ advisory.severity } ${ advisory.id } in ${ advisory.package }: ${ advisory.title }`
			);
		} else if (
			( SEVERITY_RANK[ advisory.severity ] ?? Infinity ) >
			SEVERITY_RANK[ entry.severity ]
		) {
			failures.push(
				`${ keyOf( entry ) } is now ${ advisory.severity }, recorded ${ entry.severity }: review the entry`
			);
		}
	}
	for ( const entry of allowlist ) {
		if (
			! full.some( ( advisory ) => keyOf( advisory ) === keyOf( entry ) )
		) {
			failures.push(
				`stale allow-list entry ${ keyOf( entry ) }: no longer reported, remove it`
			);
		}
		if ( production.some( ( advisory ) => advisory.id === entry.id ) ) {
			failures.push(
				`allow-listed ${ entry.id } reaches a production dependency`
			);
		}
		if ( bundledNames.includes( entry.package ) ) {
			failures.push(
				`allow-listed package ${ entry.package } is bundled into the admin build`
			);
		}
	}
	return failures;
}
