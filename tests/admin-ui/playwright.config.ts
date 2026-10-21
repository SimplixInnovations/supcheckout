/**
 * Playwright configuration for the real-WordPress admin UI lanes.
 *
 * Browser lane (`npm run test:browser`): four projects, light/dark x LTR/RTL.
 * Dark projects emulate `prefers-color-scheme: dark` with the preference left
 * at "Match system". RTL projects sign in as `sup-ar`, whose user locale is
 * `ar`; the test mu-plugin forces RTL without a network language pack.
 *
 * Visual lane (`npm run test:visual`, Task 8): one `visual-<mode>` project per
 * VISUAL_MODES entry, with the mode in `metadata.mode`, under deterministic
 * rendering settings: a fixed 1280x900 viewport at device scale 1, reduced
 * motion, locale en-US and time zone UTC, Chromium's font hinting off and the
 * sRGB color profile forced, and screenshots with animations disabled and the
 * caret hidden. Baselines are Linux files of the ubuntu-24.04 runner at
 * `visual/baselines/<group>--<mode>.png`, compared exactly. A missing or
 * different baseline fails (`updateSnapshots: 'none'`); only the workflow's
 * `update_visual_baselines` dispatch sets SUP_UPDATE_BASELINES to rewrite
 * them, and that run's gate fails until a human commits them.
 *
 * The WordPress site comes from CI (scripts/install-wp-test-environment.sh);
 * credentials are the test-only values that script and the workflow create.
 */
import { defineConfig, devices } from '@playwright/test';
import type { PlaywrightTestOptions } from '@playwright/test';

import { AUTH_FILES, BASE_URL } from './browser/support/env';
import { VISUAL_MODES } from './visual/modes';

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

/**
 * Exact comparison (controller ruling, SP1 Task 8 fix round 1): no per-pixel
 * colour tolerance and no differing pixel. Playwright's default threshold
 * (0.2) let a 1px control-radius change pass, and a 0.0005 pixel ratio still
 * let large frames absorb it. A red visual run is investigated, never retried
 * or loosened blindly; any tolerance is a reviewed per-group exception.
 */
const PIXEL_THRESHOLD = 0;
const MAX_DIFF_PIXELS = 0;

function modeNamed( name: string ): Mode {
	const mode = MODES.find( ( candidate ) => candidate.name === name );
	if ( ! mode ) {
		throw new Error( `no browser mode named ${ name }` );
	}
	return mode;
}

export default defineConfig( {
	testDir: '.',
	outputDir: `${ ARTIFACTS }/test-results`,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: true,
	updateSnapshots: process.env.SUP_UPDATE_BASELINES ? 'all' : 'none',
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
		...VISUAL_MODES.map( ( name ) => modeNamed( name ) ).map(
			( mode ) => ( {
				name: `visual-${ mode.name }`,
				testMatch: /visual\/.+\.visual\.spec\.ts$/,
				dependencies: [ 'setup' ],
				metadata: { mode: mode.name },
				snapshotPathTemplate: '{testDir}/visual/baselines/{arg}{ext}',
				expect: {
					toHaveScreenshot: {
						threshold: PIXEL_THRESHOLD,
						maxDiffPixels: MAX_DIFF_PIXELS,
						animations: 'disabled' as const,
						caret: 'hide' as const,
						scale: 'css' as const,
					},
				},
				use: {
					colorScheme: mode.colorScheme,
					storageState: mode.storageState,
					deviceScaleFactor: 1,
					reducedMotion: 'reduce' as const,
					locale: 'en-US',
					timezoneId: 'UTC',
					launchOptions: {
						args: [
							'--font-render-hinting=none',
							'--force-color-profile=srgb',
						],
					},
				},
			} )
		),
	],
} );
