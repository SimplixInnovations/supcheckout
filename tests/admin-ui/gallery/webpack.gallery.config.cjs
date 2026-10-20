/**
 * Test-only build of the UI gallery (never shipped).
 *
 * Reuses the production config, so the gallery gets the same WordPress
 * externals (React, ReactDOM and the JSX runtime come from `wp-element`, never
 * the bundle), CSS extraction and rtlcss `-rtl.css` output. Components are
 * imported from source through the UI barrel. Output goes to the git-ignored
 * tests/admin-ui/gallery/build/.
 */
const path = require( 'node:path' );
const production = require( '../../../webpack.config.js' );

module.exports = {
	...production,
	entry: {
		index: path.resolve( __dirname, 'index.tsx' ),
	},
	output: {
		...production.output,
		path: path.resolve( __dirname, 'build' ),
		uniqueName: 'supcheckout-ui-gallery',
		chunkLoadingGlobal: 'supCheckoutUiGalleryChunks',
	},
	// The notices and chunk-manifest plugin describes the shipped bundle only.
	plugins: production.plugins.filter(
		( plugin ) => plugin.constructor.name !== 'NoticesPlugin'
	),
};
