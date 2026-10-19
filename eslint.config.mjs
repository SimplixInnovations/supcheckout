/**
 * ESLint flat config for the SUPCheckout admin UI.
 *
 * Starts from the `@wordpress/scripts` default (WordPress coding standards,
 * TypeScript, React and Jest rules) and adds Node globals for the build
 * tooling and the Node tests.
 */
import globals from 'globals';
import wordpressConfig from '@wordpress/scripts/config/eslint.config.cjs';

export default [
	{
		ignores: [
			'assets/admin/build/**',
			'tests/admin-ui/gallery/build/**',
			'tests/admin-ui/artifacts/**',
		],
	},
	...wordpressConfig,
	{
		// The WordPress default ignores every `build/` directory; the build
		// contract tests live in one and must be linted.
		ignores: [ '!tests/admin-ui/build/', '!tests/admin-ui/build/**' ],
	},
	{
		files: [
			'webpack.config.js',
			'jest.config.js',
			'eslint.config.mjs',
			'tools/**/*.{js,cjs,mjs}',
			'tests/admin-ui/**/*.mjs',
		],
		languageOptions: {
			globals: { ...globals.node },
		},
	},
	{
		files: [ 'tests/admin-ui/**/*.test.{mjs,js,ts,tsx}' ],
		languageOptions: {
			globals: { ...globals.jest },
		},
	},
];
