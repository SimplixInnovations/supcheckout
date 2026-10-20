/**
 * Jest configuration for the SUPCheckout admin UI.
 *
 * `@wordpress/scripts` 36 no longer supplies Jest defaults (its `test-unit-js`
 * runs Vitest), so this file owns the whole configuration. Node tests are
 * native ES modules (`*.test.mjs`) and run untransformed, which is why the
 * `test:unit` script starts Node with `--experimental-vm-modules`.
 *
 * Two projects share one transform:
 * - `node`: token, build-contract and tooling tests (`*.test.mjs`);
 * - `dom`: component tests (`*.test.{js,ts,tsx}`) in jsdom, with jest-dom and
 *   jest-axe matchers.
 *
 * `SUP_REACT=19` (set by `npm run test:unit:react19`) maps `react`,
 * `react-dom` and their subpaths to the `react-19` / `react-dom-19` aliases,
 * for every importer including `@testing-library/react` and `@wordpress/element`.
 * Production never bundles React: WordPress's `wp-element` provides it.
 */
const REACT_19 = process.env.SUP_REACT === '19';

const WP_BABEL_PRESET = require.resolve( '@wordpress/babel-preset-default' );

// Dynamic `import()` becomes a `require()` promise, as webpack resolves it in
// the build. Left native, Jest's VM-module loader would hand `React.lazy` a
// namespace whose `default` is the whole CommonJS exports object. The plugin
// is the one the WordPress preset already ships (through @babel/preset-env),
// resolved from the preset so no new dependency is added.
const DYNAMIC_IMPORT_PLUGIN = require.resolve(
	'@babel/plugin-transform-dynamic-import',
	{ paths: [ require( 'node:path' ).dirname( WP_BABEL_PRESET ) ] }
);

const transform = {
	'^.+\\.[jt]sx?$': [
		'babel-jest',
		{
			presets: [ WP_BABEL_PRESET ],
			plugins: [ DYNAMIC_IMPORT_PLUGIN ],
		},
	],
};

const testPathIgnorePatterns = [
	'/node_modules/',
	'/tests/admin-ui/gallery/build/',
];

const moduleFileExtensions = [ 'mjs', 'js', 'cjs', 'ts', 'tsx', 'json' ];

const reactMapper = REACT_19
	? {
			'^react$': 'react-19',
			'^react/(.*)$': 'react-19/$1',
			'^react-dom$': 'react-dom-19',
			'^react-dom/(.*)$': 'react-dom-19/$1',
		}
	: {};

module.exports = {
	rootDir: __dirname,
	projects: [
		{
			displayName: 'node',
			rootDir: __dirname,
			roots: [ '<rootDir>/tests/admin-ui' ],
			testEnvironment: 'node',
			// A regex rather than a glob: globs skip dot-directories such as a
			// `.claude/worktrees/*` checkout.
			testRegex: '/tests/admin-ui/.+\\.test\\.mjs$',
			testPathIgnorePatterns,
			moduleFileExtensions,
			transform,
		},
		{
			displayName: 'dom',
			rootDir: __dirname,
			roots: [ '<rootDir>/tests/admin-ui' ],
			testEnvironment: 'jsdom',
			testRegex: '/tests/admin-ui/.+\\.test\\.(js|ts|tsx)$',
			testPathIgnorePatterns,
			moduleFileExtensions,
			transform,
			// axe over whole specimens in four modes is slow when every
			// suite runs in parallel on a loaded machine.
			testTimeout: 30_000,
			setupFilesAfterEnv: [
				'<rootDir>/tests/admin-ui/unit/support/setup.ts',
			],
			moduleNameMapper: {
				...reactMapper,
				'\\.css$':
					'<rootDir>/tests/admin-ui/unit/support/style-stub.js',
			},
		},
	],
};
