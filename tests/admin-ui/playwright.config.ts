/**
 * Playwright configuration for the real-WordPress admin UI lanes.
 *
 * Browser lane (`npm run test:browser`): four projects, light/dark x LTR/RTL.
 * Dark projects emulate `prefers-color-scheme: dark` with the preference left
 * at "Match system". RTL projects sign in as `sup-ar`, whose user locale is
 * `ar`; the test mu-plugin forces RTL without a network language pack.
 *
 * Visual lane (`npm run test:visual`, Task 8): the `visual-*` projects use the
 * same four modes under the deterministic rendering settings.
 *
 * The WordPress site comes from CI (scripts/install-wp-test-environment.sh);
 * credentials are the test-only values that script and the workflow create.
 */
import { defineConfig, devices } from '@playwright/test';
import type { PlaywrightTestOptions } from '@playwright/test';

import { AUTH_FILES, BASE_URL } from './browser/support/env';

const ARTIFACTS = 'artifacts';

type Mode = {
	name: string;
	colorScheme: PlaywrightTestOptions[ 'colorScheme' ];
	storageState: string;
};

const MODES: readonly Mode[] = [
	{ name: 'light-ltr', colorScheme: 'light', storageState: AUTH_FILES.admin },
	{ name: 'dark-ltr', colorScheme: 'dark', storageState: AUTH_FILES.admin },
	{
		name: 'light-rtl',
		colorScheme: 'light',
		storageState: AUTH_FILES.arabic,
	},
	{ name: 'dark-rtl', colorScheme: 'dark', storageState: AUTH_FILES.arabic },
];

const VIEWPORT = { width: 1280, height: 900 };

export default defineConfig( {
	testDir: '.',
	outputDir: `${ ARTIFACTS }/test-results`,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: true,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: `${ ARTIFACTS }/report`, open: 'never' } ],
	],
	use: {
		...devices[ 'Desktop Chrome' ],
		baseURL: BASE_URL,
		viewport: VIEWPORT,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'setup',
			testMatch: /browser\/auth\.setup\.ts$/,
		},
		...MODES.map( ( mode ) => ( {
			name: mode.name,
			testMatch: /browser\/.+\.spec\.ts$/,
			dependencies: [ 'setup' ],
			use: {
				colorScheme: mode.colorScheme,
				storageState: mode.storageState,
			},
		} ) ),
		...MODES.map( ( mode ) => ( {
			name: `visual-${ mode.name }`,
			testMatch: /visual\/.+\.visual\.spec\.ts$/,
			dependencies: [ 'setup' ],
			use: {
				colorScheme: mode.colorScheme,
				storageState: mode.storageState,
				deviceScaleFactor: 1,
				reducedMotion: 'reduce' as const,
				launchOptions: {
					args: [
						'--font-render-hinting=none',
						'--force-color-profile=srgb',
					],
				},
			},
		} ) ),
	],
} );
