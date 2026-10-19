/**
 * Jest configuration for the SUPCheckout admin UI.
 *
 * `@wordpress/scripts` 36 no longer supplies Jest defaults (its `test-unit-js`
 * runs Vitest), so this file owns the whole configuration. Node tests are
 * native ES modules (`*.test.mjs`) and run untransformed, which is why the
 * `test:unit` script starts Node with `--experimental-vm-modules`.
 */
module.exports = {
	rootDir: __dirname,
	roots: [ '<rootDir>/tests/admin-ui' ],
	testEnvironment: 'node',
	// A regex rather than a glob: globs skip dot-directories such as a
	// `.claude/worktrees/*` checkout.
	testRegex: '/tests/admin-ui/.+\\.test\\.(mjs|js|ts|tsx)$',
	testPathIgnorePatterns: [
		'/node_modules/',
		'/tests/admin-ui/gallery/build/',
	],
	moduleFileExtensions: [ 'mjs', 'js', 'cjs', 'ts', 'tsx', 'json' ],
	transform: {
		'^.+\\.[jt]sx?$': [
			'babel-jest',
			{
				presets: [
					require.resolve( '@wordpress/babel-preset-default' ),
				],
			},
		],
	},
};
