/**
 * Test-only build of the UI gallery (never shipped).
 *
 * Reuses the production config, so the gallery gets the same WordPress
 * externals (React, ReactDOM and the JSX runtime come from `wp-element`, never
 * the bundle), CSS extraction and rtlcss `-rtl.css` output. Components are
 * imported from source through the UI barrel. Output goes to the git-ignored
 * tests/admin-ui/gallery/build/.
 *
 * `SUP_BRAND=<brand file>` (the visual-regression lane, SP1 Task 8) proves
 * the one-file branding path on the gallery alone:
 * - before each compile, tools/admin-ui/tokens/build-tokens.mjs renders the
 *   token stylesheet with that brand file merged (and contrast-checked) into
 *   build/brand/tokens.generated.css, and the gallery imports that file in
 *   place of assets/admin/src/tokens/tokens.generated.css;
 * - vr-fonts.css joins the entry: `@font-face` rules for the four vendored
 *   `@fontsource` files the brand file names, which webpack copies into
 *   build/fonts/.
 * Nothing is written under assets/, and without SUP_BRAND the build is the
 * unbranded gallery the browser lane uses.
 */
const { execFileSync } = require( 'node:child_process' );
const path = require( 'node:path' );
const production = require( '../../../webpack.config.js' );

const ROOT = path.resolve( __dirname, '..', '..', '..' );
const BUILD = path.resolve( __dirname, 'build' );
const BUILD_TOKENS = path.join(
	ROOT,
	'tools/admin-ui/tokens/build-tokens.mjs'
);
const ENTRY = path.resolve( __dirname, 'index.tsx' );
const FONTS = path.resolve( __dirname, 'vr-fonts.css' );
const BRAND = process.env.SUP_BRAND
	? path.resolve( ROOT, process.env.SUP_BRAND )
	: null;

/** Renders the branded token stylesheet before every (watch) compile. */
class GalleryBrandPlugin {
	constructor( brand ) {
		this.brand = brand;
		this.out = path.join( BUILD, 'brand', 'tokens.generated.css' );
	}

	apply( compiler ) {
		const render = () => {
			execFileSync(
				process.execPath,
				[ BUILD_TOKENS, '--brand', this.brand, '--out', this.out ],
				{ stdio: 'inherit' }
			);
		};
		compiler.hooks.beforeRun.tap( 'GalleryBrandPlugin', render );
		compiler.hooks.watchRun.tap( 'GalleryBrandPlugin', render );
		// compiler.webpack: the webpack that runs this build (no direct dependency).
		new compiler.webpack.NormalModuleReplacementPlugin(
			/[\\/]assets[\\/]admin[\\/]src[\\/]tokens[\\/]tokens\.generated\.css$/,
			this.out
		).apply( compiler );
	}
}

module.exports = {
	...production,
	entry: {
		index: BRAND ? [ FONTS, ENTRY ] : ENTRY,
	},
	output: {
		...production.output,
		path: BUILD,
		uniqueName: 'supcheckout-ui-gallery',
		chunkLoadingGlobal: 'supCheckoutUiGalleryChunks',
		// Keep the branded token stylesheet the plugin renders before emit.
		clean: { keep: /^(fonts|images|brand)\// },
	},
	// The notices and chunk-manifest plugin describes the shipped bundle only.
	plugins: [
		...production.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'NoticesPlugin'
		),
		...( BRAND ? [ new GalleryBrandPlugin( BRAND ) ] : [] ),
	],
};
