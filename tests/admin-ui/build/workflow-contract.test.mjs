/**
 * Contract for the permanent Admin UI Certification workflow: the drift and
 * toolchain checks it must keep.
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);
const WORKFLOW = readFileSync(
	join( ROOT, '.github/workflows/admin-ui-certification.yml' ),
	'utf8'
);

function job( name ) {
	const match = WORKFLOW.match(
		new RegExp( `\\n  ${ name }:\\n([\\s\\S]*?)(?=\\n  [a-z-]+:\\n|$)` )
	);
	return match ? match[ 1 ] : '';
}

test( 'the static second build starts from an empty build directory and cache', () => {
	expect( job( 'static' ) ).toMatch(
		/rm -rf assets\/admin\/build node_modules\/\.cache && npm run build && npm run check:drift/
	);
} );

test( 'both lanes assert the pinned npm version', () => {
	const assertion =
		'test "$(npm --version)" = "$(node -p \'require("./package.json").engines.npm\')"';
	expect( job( 'static' ) ).toContain( assertion );
	expect( job( 'build-windows' ) ).toContain( assertion );
} );

test( 'the Windows lane rejects CRLF and mixed endings in the working-tree build', () => {
	const windows = job( 'build-windows' );
	expect( windows ).toContain( 'git ls-files --eol -- assets/admin/build' );
	expect( windows ).toContain( "grep -E 'w/(crlf|mixed)'" );
} );

test( 'the gate needs both lanes and treats skipped or cancelled as failure', () => {
	const gate = job( 'gate' );
	expect( gate ).toMatch( /- static\n\s+- build-windows/ );
	expect( gate ).toContain( 'if: ${{ always() }}' );
	expect( gate ).toMatch( /!= success/ );
} );

test( 'the static lane also runs the React 19 component lane', () => {
	expect( job( 'static' ) ).toContain( 'run: npm run test:unit:react19' );
} );

test( 'the gate also needs the browser lane and fails on its skip or cancel', () => {
	const gate = job( 'gate' );
	expect( gate ).toMatch( /- build-windows\n\s+- browser\n/ );
	expect( gate ).toContain( 'BROWSER_RESULT: ${{ needs.browser.result }}' );
	expect( gate ).toContain( '"$BROWSER_RESULT" != success' );
} );

test( 'the browser lane runs real WordPress 6.9.10/WC 10.8.1 and 7.1.3/WC 11.1.2', () => {
	const browser = job( 'browser' );
	expect( browser ).toMatch(
		/- wordpress: '6\.9\.10'\n\s+woocommerce: '10\.8\.1'/
	);
	expect( browser ).toMatch(
		/- wordpress: '7\.1\.3'\n\s+woocommerce: '11\.1\.2'/
	);
	expect( browser ).toContain( 'fail-fast: false' );
	expect( browser ).toContain( "php-version: '8.3'" );
	expect( browser ).toContain(
		'bash scripts/install-wp-test-environment.sh'
	);
	expect( browser ).toContain(
		"echo 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  /tmp/wp-cli.phar' | sha256sum -c -"
	);
} );

test( 'the browser lane builds the gallery, extracts strings and runs four modes', () => {
	const browser = job( 'browser' );
	expect( browser ).toContain( 'run: npm ci --ignore-scripts' );
	expect( browser ).toContain(
		'php tests/admin-ui/wp/make-fixture-translations.php "$WP"'
	);
	expect( browser ).toContain(
		'cp tests/admin-ui/wp/supcheckout-ui-gallery.php "$WP/wp-content/mu-plugins/"'
	);
	expect( browser ).toContain(
		'config set SUPCHECKOUT_UI_GALLERY true --raw'
	);
	expect( browser ).toContain(
		'i18n make-pot . "$RUNNER_TEMP/sup.pot" --include=assets/admin/build --domain=supcheckout'
	);
	expect( browser ).toContain(
		`grep -q 'msgid "SUPCheckout"' "$RUNNER_TEMP/sup.pot"`
	);
	expect( browser ).toContain( 'run: npm run build:gallery' );
	expect( browser ).toContain(
		'run: npx playwright install --with-deps chromium'
	);
	expect( browser ).toContain( 'npm run test:browser' );
	expect( browser ).toContain( 'if: ${{ failure() }}' );
} );

test( 'every lane checks out the exact head without credentials and has a timeout', () => {
	for ( const name of [ 'static', 'build-windows', 'browser', 'gate' ] ) {
		expect( job( name ) ).toMatch( /timeout-minutes: \d+/ );
	}
	for ( const name of [ 'static', 'build-windows', 'browser' ] ) {
		expect( job( name ) ).toContain( 'persist-credentials: false' );
		expect( job( name ) ).toContain(
			'test "$(git rev-parse HEAD)" = "$ADMIN_UI_SOURCE_SHA"'
		);
	}
	expect( WORKFLOW ).toMatch( /\npermissions:\n\s+contents: read\n/ );
	expect( WORKFLOW ).not.toMatch( /\|\|\s*true/ );
} );

test( 'test:browser runs exactly the four browser projects', () => {
	const pkg = JSON.parse(
		readFileSync( join( ROOT, 'package.json' ), 'utf8' )
	);
	expect( pkg.scripts[ 'test:browser' ] ).toBe(
		'playwright test --config tests/admin-ui/playwright.config.ts --project=light-ltr --project=dark-ltr --project=light-rtl --project=dark-rtl'
	);
} );

test( 'the visual lane runs real WordPress 7.1.3/WC 11.1.2 on PHP 8.3 at the exact head', () => {
	const visual = job( 'visual' );
	expect( visual ).toContain( 'runs-on: ubuntu-latest' );
	expect( visual ).toMatch( /timeout-minutes: \d+/ );
	expect( visual ).toContain( 'persist-credentials: false' );
	expect( visual ).toContain(
		'test "$(git rev-parse HEAD)" = "$ADMIN_UI_SOURCE_SHA"'
	);
	expect( visual ).toContain( "SUP_UI_WP_VERSION: '7.1.3'" );
	expect( visual ).toContain( "SUP_UI_WC_VERSION: '11.1.2'" );
	expect( visual ).toContain( "php-version: '8.3'" );
	expect( visual ).toContain(
		'bash scripts/install-wp-test-environment.sh "$SUP_UI_WP_VERSION" "$SUP_UI_WC_VERSION"'
	);
	expect( visual ).toContain( 'run: npm ci --ignore-scripts' );
	expect( visual ).toContain(
		'run: npx playwright install --with-deps chromium'
	);
} );

test( 'the visual lane builds the gallery with the test brand file and runs the visual projects', () => {
	const visual = job( 'visual' );
	expect( visual ).toMatch(
		/SUP_BRAND: tests\/admin-ui\/gallery\/vr-brand\.tokens\.json\n\s+run: npm run build:gallery/
	);
	expect( visual ).toContain(
		`test "$(find tests/admin-ui/gallery/build/fonts -name '*.woff2' | wc -l)" = 5`
	);
	expect( visual ).toContain( 'npm run check:drift' );
	expect( visual ).toContain( 'npm run test:visual' );
	expect( visual ).toContain( 'name: admin-ui-visual-diffs' );
	expect( visual ).toContain( 'if: ${{ failure() }}' );
} );

test( 'a baseline update is dispatch-only, uploads the baselines and fails the gate', () => {
	expect( WORKFLOW ).toMatch(
		/workflow_dispatch:\n\s+inputs:\n\s+update_visual_baselines:\n[\s\S]*?type: boolean\n\s+default: false\n/
	);
	const update =
		"UPDATE_VISUAL_BASELINES: ${{ github.event_name == 'workflow_dispatch' && inputs.update_visual_baselines && 'true' || 'false' }}";
	const visual = job( 'visual' );
	expect( visual ).toContain( update );
	expect( visual ).toMatch(
		/if \[\[ "\$UPDATE_VISUAL_BASELINES" == true \]\]; then\n\s+export SUP_UPDATE_BASELINES=1/
	);
	expect( visual ).toMatch(
		/if: \$\{\{ env\.UPDATE_VISUAL_BASELINES == 'true' \}\}\n\s+uses: actions\/upload-artifact@[0-9a-f]{40} # v[\d.]+\n\s+with:\n\s+name: admin-ui-visual-baselines\n\s+path: tests\/admin-ui\/visual\/baselines\n\s+if-no-files-found: error/
	);
	const gate = job( 'gate' );
	expect( gate ).toContain( update );
	expect( gate ).toMatch(
		/if \[\[ "\$UPDATE_VISUAL_BASELINES" != false \]\]; then\n\s+echo "baselines regenerated; commit them and rerun"\n\s+failed=1\n/
	);
	// The message prints whenever update mode ran, even when a lane failed
	// too: the update check runs before the lane check, and only the last
	// line exits.
	expect( gate.indexOf( 'baselines regenerated' ) ).toBeLessThan(
		gate.indexOf( '"$STATIC_RESULT" != success' )
	);
	expect( gate ).toMatch( /failed=0\n/ );
	expect( gate ).toMatch( /exit "\$failed"\n?$/ );
	expect( gate.match( /\bexit 1\b/g ) ).toBeNull();
} );

test( 'the gate also needs the visual lane and fails on its skip or cancel', () => {
	const gate = job( 'gate' );
	expect( gate ).toMatch( /- browser\n\s+- visual\n/ );
	expect( gate ).toContain( 'VISUAL_RESULT: ${{ needs.visual.result }}' );
	expect( gate ).toContain( '"$VISUAL_RESULT" != success' );
} );

test( 'test:visual runs exactly the four visual projects', () => {
	const pkg = JSON.parse(
		readFileSync( join( ROOT, 'package.json' ), 'utf8' )
	);
	expect( pkg.scripts[ 'test:visual' ] ).toBe(
		'playwright test --config tests/admin-ui/playwright.config.ts --project=visual-light-ltr --project=visual-dark-ltr --project=visual-light-rtl --project=visual-dark-rtl'
	);
} );
