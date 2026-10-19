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
