/**
 * Production build of the SUPCheckout admin app.
 *
 * Extends the `@wordpress/scripts` webpack config. The output is committed under
 * assets/admin/build and CI proves it equals a clean rebuild (check:drift).
 */
const path = require( 'node:path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const NoticesPlugin = require( './tools/admin-ui/webpack/notices-plugin.cjs' );

// Replaced: the default DependencyExtractionWebpackPlugin writes a PHP asset
// file. Dropped: the block-only copy plugins, which would scan the PHP `src/`
// directory for block.json and render templates.
const REMOVED_PLUGINS = new Set( [
	'DependencyExtractionWebpackPlugin',
	'CopyPlugin',
	'PhpFilePathsPlugin',
] );

module.exports = {
	...defaultConfig,
	entry: {
		app: path.resolve( __dirname, 'assets/admin/src/app/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/admin/build' ),
		filename: '[name].js',
		chunkFilename: 'chunks/[name].[contenthash:8].js',
		publicPath: 'auto',
		uniqueName: 'supcheckout-admin',
		chunkLoadingGlobal: 'supCheckoutAdminChunks',
	},
	optimization: {
		...defaultConfig.optimization,
		moduleIds: 'deterministic',
		chunkIds: 'deterministic',
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => ! REMOVED_PLUGINS.has( plugin.constructor.name )
		),
		new DependencyExtractionWebpackPlugin( { outputFormat: 'json' } ),
		new NoticesPlugin( { entry: 'app' } ),
	],
};
