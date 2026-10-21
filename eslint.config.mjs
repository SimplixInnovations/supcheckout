/**
 * ESLint flat config for the SUPCheckout admin UI.
 *
 * Starts from the `@wordpress/scripts` default (WordPress coding standards,
 * TypeScript, React and Jest rules) and adds Node globals for the build
 * tooling and the Node tests, plus the UI library boundary: screens import
 * primitives, and the library itself, only through the `assets/admin/src/ui`
 * barrel.
 */
import globals from 'globals';
import wordpressConfig from '@wordpress/scripts/config/eslint.config.cjs';

const WP_COMPONENTS = {
	group: [ '@wordpress/components', '@wordpress/components/*' ],
	message:
		'@wordpress/components is not a dependency: its markup and CSS change between WordPress versions. Use the SUPCheckout UI library.',
};

const PRIMITIVES = {
	// Every Base UI and Floating UI package (and any subpath), plus lucide.
	group: [
		'@base-ui/*',
		'@base-ui/*/**',
		'@floating-ui/*',
		'@floating-ui/*/**',
		'lucide-react',
		'lucide-react/**',
	],
	message:
		'Import UI primitives through assets/admin/src/ui (the only import surface), never directly.',
};

const UI_INTERNALS = {
	group: [ '**/assets/admin/src/ui/*/**' ],
	message:
		'Import the UI library through its barrel (assets/admin/src/ui), never a module inside it.',
};

const SCREEN_UI_INTERNALS = {
	// Relative (`../ui/controls/Button`) or absolute, at any depth.
	group: [ '**/ui/*/**' ],
	message:
		'Screens import the UI library through its barrel (../ui), never a module inside it.',
};

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
		files: [
			'tests/admin-ui/**/*.test.{mjs,js,ts,tsx}',
			'tests/admin-ui/unit/support/**',
		],
		languageOptions: {
			globals: { ...globals.jest },
		},
	},
	{
		// renderInModes() asserts axe has no violations in every mode.
		files: [ 'tests/admin-ui/**/*.test.{js,ts,tsx}' ],
		rules: {
			'vitest/expect-expect': [
				'error',
				{ assertFunctionNames: [ 'expect', 'renderInModes' ] },
			],
		},
	},
	{
		files: [
			'assets/admin/src/**/*.{js,ts,tsx}',
			'tests/admin-ui/**/*.{js,ts,tsx}',
		],
		rules: {
			'react/no-unknown-property': 'error',
			'no-restricted-imports': [
				'error',
				{ patterns: [ WP_COMPONENTS, PRIMITIVES ] },
			],
		},
	},
	{
		// The gallery shows the library as SP2 screens consume it: through the
		// barrel only (tokens.generated.css stays importable).
		files: [ 'tests/admin-ui/gallery/**/*.{js,ts,tsx}' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{ patterns: [ WP_COMPONENTS, PRIMITIVES, UI_INTERNALS ] },
			],
		},
	},
	{
		// Screens consume the library as its public API: the barrel only
		// (tokens.generated.css and their own CSS stay importable).
		files: [ 'assets/admin/src/app/**/*.{js,ts,tsx}' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{
					patterns: [
						WP_COMPONENTS,
						PRIMITIVES,
						SCREEN_UI_INTERNALS,
					],
				},
			],
		},
	},
	{
		// The library itself wraps the primitives; @wordpress/components
		// stays forbidden here too.
		files: [ 'assets/admin/src/ui/**/*.{js,ts,tsx}' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{ patterns: [ WP_COMPONENTS ] },
			],
		},
	},
];
