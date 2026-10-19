/**
 * Emits the third-party notices and the lazy-chunk manifest for the admin bundle.
 *
 * - `third-party-notices.json`: `[{ name, version, license }]` for every npm
 *   package with at least one module in the emitted bundle, sorted by name.
 * - `THIRD-PARTY-NOTICES.txt`: the same packages with their LICENSE text.
 * - `<entry>.chunks.json`: the sorted list of emitted `chunks/*.js` files, so
 *   the PHP loader can register translations for every lazy chunk.
 *
 * Output is deterministic: sorted, LF line endings, trailing newline, and no
 * absolute path or timestamp.
 */
const { existsSync, readdirSync, readFileSync } = require( 'node:fs' );
const { dirname, join, sep } = require( 'node:path' );

const PLUGIN_NAME = 'SupCheckoutNoticesPlugin';
const LICENSE_FILE = /^(licen[cs]e|copying)(\.(md|txt|markdown))?$/i;
const NODE_MODULES = `${ sep }node_modules${ sep }`;

/**
 * Yields every module, descending into concatenated modules.
 *
 * @param {Iterable<import('webpack').Module>} modules Compilation modules.
 * @return {Iterable<import('webpack').Module>} Flattened modules.
 */
function* flattenModules( modules ) {
	for ( const module of modules ) {
		if ( module.modules ) {
			yield* flattenModules( module.modules );
		}
		yield module;
	}
}

/**
 * Finds the package root (the nearest `package.json` with a name and version)
 * that owns a module file below `node_modules`.
 *
 * @param {string} resource Absolute module path.
 * @return {string|null} Package directory, or null outside node_modules.
 */
function packageRootOf( resource ) {
	if ( ! resource.includes( NODE_MODULES ) ) {
		return null;
	}
	let directory = dirname( resource );
	while ( directory.includes( NODE_MODULES ) ) {
		const manifest = join( directory, 'package.json' );
		if ( existsSync( manifest ) ) {
			const pkg = JSON.parse( readFileSync( manifest, 'utf8' ) );
			if ( pkg.name && pkg.version ) {
				return directory;
			}
		}
		directory = dirname( directory );
	}
	return null;
}

/**
 * @param {unknown} license The `license` field of a package.json.
 * @return {string} An SPDX expression, or `UNKNOWN`.
 */
function normaliseLicense( license ) {
	if ( typeof license === 'string' && license.trim() !== '' ) {
		return license.trim();
	}
	if (
		license &&
		typeof license === 'object' &&
		typeof license.type === 'string'
	) {
		return license.type.trim();
	}
	return 'UNKNOWN';
}

function readLicenseText( directory ) {
	const file = readdirSync( directory )
		.filter( ( name ) => LICENSE_FILE.test( name ) )
		.sort()[ 0 ];
	if ( ! file ) {
		return '(No license file is included in the package.)';
	}
	return readFileSync( join( directory, file ), 'utf8' )
		.replace( /\r\n?/g, '\n' )
		.split( '\n' )
		.map( ( line ) => line.trimEnd() )
		.join( '\n' )
		.trim();
}

function collectPackages( compilation ) {
	const { chunkGraph } = compilation;
	// Only modules that end up in an emitted chunk count. Orphans, such as the
	// css-loader runtime behind an extracted stylesheet, never ship.
	const emitted = [ ...compilation.modules ].filter(
		( module ) => chunkGraph.getNumberOfModuleChunks( module ) > 0
	);
	const packages = new Map();
	for ( const module of flattenModules( emitted ) ) {
		const directory = module.resource
			? packageRootOf( module.resource )
			: null;
		if ( ! directory || packages.has( directory ) ) {
			continue;
		}
		const pkg = JSON.parse(
			readFileSync( join( directory, 'package.json' ), 'utf8' )
		);
		packages.set( directory, {
			name: pkg.name,
			version: pkg.version,
			license: normaliseLicense( pkg.license ),
			text: readLicenseText( directory ),
		} );
	}
	// Code-point order, independent of the ICU build and the host locale.
	const order = ( left, right ) => {
		if ( left === right ) {
			return 0;
		}
		return left < right ? -1 : 1;
	};
	return [ ...packages.values() ].sort(
		( a, b ) => order( a.name, b.name ) || order( a.version, b.version )
	);
}

function renderJson( packages ) {
	const entries = packages.map( ( { name, version, license } ) => ( {
		name,
		version,
		license,
	} ) );
	return JSON.stringify( entries, null, '\t' ) + '\n';
}

function renderText( packages ) {
	const header = [
		'SUPCheckout for UPayments: third-party notices for the bundled admin code.',
		'React, the JSX runtime and every @wordpress/* package are provided by WordPress at runtime and are not bundled.',
	];
	if ( packages.length === 0 ) {
		return (
			[ ...header, '', 'No third-party package is bundled.' ].join(
				'\n'
			) + '\n'
		);
	}
	const sections = packages.map(
		( { name, version, license, text } ) =>
			`${ name }@${ version } (${ license })\n\n${ text }`
	);
	return (
		[
			...header,
			'',
			sections.join( '\n\n----------------------------------------\n\n' ),
		].join( '\n' ) + '\n'
	);
}

class NoticesPlugin {
	/**
	 * @param {{ entry: string }} options The entry whose chunk manifest is written.
	 */
	constructor( { entry } ) {
		this.entry = entry;
	}

	apply( compiler ) {
		const { Compilation, sources } = compiler.webpack;
		compiler.hooks.thisCompilation.tap( PLUGIN_NAME, ( compilation ) => {
			compilation.hooks.processAssets.tap(
				{
					name: PLUGIN_NAME,
					stage: Compilation.PROCESS_ASSETS_STAGE_REPORT,
				},
				() => {
					const packages = collectPackages( compilation );
					const chunks = Object.keys( compilation.assets )
						.filter( ( name ) =>
							/^chunks\/[^/]+\.js$/.test( name )
						)
						.sort();
					compilation.emitAsset(
						'third-party-notices.json',
						new sources.RawSource( renderJson( packages ) )
					);
					compilation.emitAsset(
						'THIRD-PARTY-NOTICES.txt',
						new sources.RawSource( renderText( packages ) )
					);
					compilation.emitAsset(
						`${ this.entry }.chunks.json`,
						new sources.RawSource(
							JSON.stringify( chunks, null, '\t' ) + '\n'
						)
					);
				}
			);
		} );
	}
}

module.exports = NoticesPlugin;
