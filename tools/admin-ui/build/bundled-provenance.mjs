/**
 * Cross-checks the bundled packages reported by the notices plugin against
 * the lockfile: each must be a locked production (non-dev) dependency at the
 * same version, so nothing reaches the bundle outside `dependencies`.
 */

/**
 * @param {{ name: string, version: string }[]} notices third-party-notices.json.
 * @param {{ packages: Object<string, Object> }}  lock    package-lock.json (v3).
 * @return {string[]} `name@version` of every bundled package that is not a
 *                    locked production dependency, in notices order.
 */
export function unlockedBundledPackages( notices, lock ) {
	const production = new Set();
	for ( const [ path, entry ] of Object.entries( lock.packages || {} ) ) {
		const index = path.lastIndexOf( 'node_modules/' );
		if ( index === -1 || entry.dev || entry.devOptional ) {
			continue;
		}
		const name = path.slice( index + 'node_modules/'.length );
		production.add( `${ name }@${ entry.version }` );
	}
	return notices
		.map( ( { name, version } ) => `${ name }@${ version }` )
		.filter( ( id ) => ! production.has( id ) );
}
