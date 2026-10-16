# Sub-project 0a — Clean Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the legacy `supcheckout/UPayments.php` monolith with a small `supcheckout/supcheckout.php` bootstrap and namespaced, single-purpose classes. The plugin's observable behavior stays identical, and no global symbol can collide with any official UPayments plugin.

**Architecture:** Use a strangler sequence. First freeze the plugin's public WordPress surface: every hook name, priority and argument count, the gateway identity and form-field keys, and every route. That snapshot becomes a test. Then move responsibilities one at a time into `src/` classes under `Simplixi\SUPCheckout\`, behind a PSR-4 autoloader, until the main file is only a bootstrap. The snapshot, the existing harnesses (retargeted, intent unchanged) and the 20-cell real-WordPress compatibility matrix must stay green after every task. Data identities (gateway ID `upayments`, settings option, meta keys, callback route, cron hook) are **not** renamed here. That is Sub-project 0b.

**Tech Stack:**
- PHP 7.4+ (no 8.x-only syntax);
- WordPress 6.9+ and WooCommerce 10.8+;
- PHPUnit, PHPStan level 5, WPCS (PHPCS);
- Node for the JS harnesses;
- GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md` (Sections 3.5, 5.1, 8, 9).

## Global Constraints

**Prerequisite:** start only when there are zero open PRs and the remaining bug list is closed. Base every branch on `main` at that time.

**Identity and naming**
- Main file: `supcheckout/supcheckout.php`. The plugin header is unchanged except the file name.
- PHP namespace root: `Simplixi\SUPCheckout\`. New global functions or classes are not allowed, except where WordPress or WooCommerce require a string callback, and then they must use the `supcheckout_` prefix.
- New first-party options, meta, hooks and handles use the `supcheckout_` prefix.
- **Unchanged in 0a:**
  - gateway ID `upayments`;
  - `woocommerce_upayments_settings`;
  - `UPayments_*` and `_upay_*` meta;
  - callback route `wc_upayments`;
  - `upay_process_subscriptions`;
  - every `simplixpay_*` lock, cursor and reconcile key;
  - Blocks identity `upayments`;
  - H12 option names.

**Code limits**
- Source files aim for 200–400 lines; 800 is the soft ceiling.
- `supcheckout.php` must stay under 120 lines.
- No Composer runtime dependency in the package. The autoloader is hand-written and ships in `src/`.

**Gates**
- Every task ends with all of these green:
  - PHPUnit;
  - every PHP harness under `tests/harness/*.php` (except `_bootstrap.php` and `store_api_child.php`);
  - every JS harness;
  - PHPStan;
  - PHPCS;
  - `php -l` on all shipped PHP.
- The public-surface snapshot (Task 1) must match after every task, except entries a task explicitly re-records with a stated reason.
- The quality controls (Q1–Q19, the architecture harnesses, H12, Phase 9I, Provider Lifecycle) are retargeted, never removed or weakened. Every retargeted assertion keeps its rule and points at the new file. Each change is listed in that task's commit message.
- Release: `bash scripts/build-release.sh dist && bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip` succeeds. The package contains `supcheckout/supcheckout.php` and no `supcheckout/UPayments.php`.
- Commits use conventional commits with no attribution footers. One PR per task, merged only after exact-head CI is green, with squash merges.

## Review Focus

These are the cases most likely to break real stores without any task's unit tests noticing. Each gets a test in the owning task.

1. **A store that activated the pre-rename build** (staging and test stores only, since the plugin was never released): WordPress keeps `supcheckout/UPayments.php` in `active_plugins`. After the update, that file no longer exists, so WordPress drops the entry and shows the plugin as deactivated. No SUPCheckout code can run to warn, so the safety is procedural. → Task 9 adds a CHANGELOG entry and runbook step, plus a release-artifact assertion that the package contains no `UPayments.php`. Task 10 rehearses the reactivation on staging.
2. **The official UPayments plugin active at the same time:** with no globals left, both plugins load. SUPCheckout must still pause, because gateway ID `upayments` collides until 0b. → Task 8, coexistence harness re-run.
3. **Third-party code calling the public compatibility wrapper `getAPIUrlForRetreiveCards()`, or `WC()->payment_gateways()->payment_gateways()['upayments']`:** the method must still exist on the registered gateway object. → Task 3 adds a test.
4. **Callbacks for in-flight payments arriving during and after the update:** `?wc-api=wc_upayments` must route identically, cache headers included. → Task 3 re-runs `callback-cache-http.sh` and the lifecycle harness.
5. **Subscription cron and Action Scheduler hooks already stored in a store's cron and action tables:** hook names and argument shapes must not change. → Task 1 snapshot entries for those hooks, asserted in Task 6.

---

## File Structure (end state of 0a)

```text
supcheckout/
  supcheckout.php                       Bootstrap: header, guards, autoloader, Plugin::boot()
  src/
    Autoloader.php                      PSR-4 for Simplixi\SUPCheckout\
    Plugin.php                          Single composition root; registers every hook
    Bootstrap/
      Requirements.php                  WooCommerce/PHP checks + admin notice
      WooCommerceDeclarations.php       HPOS + cart/checkout-blocks compatibility declarations
    Gateway/
      Gateway.php                       WC_Payment_Gateway subclass (id 'upayments'), thin
      GatewayRegistration.php           woocommerce_payment_gateways + available-gateways filters
      GatewayAdmin.php                  admin order box, settings screen glue
      GatewayPresentation.php           icons, payment_fields, thank-you, order totals
      ProviderClient.php                execute_upayments_request + endpoint helpers
      (existing) Availability.php, CheckoutAssets.php, OrderPresentation.php, OfficialPluginConflict.php
    Blocks/
      PaymentMethodType.php             (was includes/class-wc-gateway-upayments-blocks.php)
    Subscription/
      Checkout/Fields.php               (was includes/Subscription/Checkout/Fields.php)
      Cron/CycleClaim.php, Cron/Scheduler.php
      Helpers/Utils.php, Manager.php
      (existing) Composition.php, Presentation.php, ... Scheduling/*
    Token/
      CustomerTokenIdentity.php         (was includes/Token/CustomerTokenIdentity.php)
    Account/
      SubscriptionActions.php           (was the anonymous 'init' closure for pause/resume/unsubscribe)
    (existing) Admin/, Migration/, Payment/, Provider/, Release/, Security/
  tests/harness/public-surface-snapshot-harness.php + tests/fixtures/public-surface.json
```

`includes/` is deleted at the end of Task 6.

---

### Task 1: Public-surface snapshot (safety net)

**Files:**
- Create: `tests/harness/public-surface-snapshot-harness.php`
- Create: `tests/fixtures/public-surface.json` (generated, then committed)
- Modify: `.github/workflows/quality-gates.yml` (add the harness to the PHP harness list)

**Interfaces:**
- Produces:
  - `php tests/harness/public-surface-snapshot-harness.php`, which exits 0 when the current surface equals the fixture;
  - `--record`, which rewrites the fixture.
  - Later tasks run it after every change.

- [ ] **Step 1: Write the harness.** It boots the real main file under a recording WordPress stub and records each `add_action`/`add_filter`/`register_*_hook` call as `{type, hook, priority, accepted_args}`. Callables are deliberately excluded, because they move between classes. It also records the gateway's `id`, `has_fields`, `supports`, sorted `form_fields` keys, and `method_title`.

```php
<?php
/**
 * Public WordPress surface of SUPCheckout: hook names, priorities, argument counts,
 * gateway identity and settings keys. Callables are deliberately not recorded so
 * responsibilities can move between classes; the surface itself may not change.
 *
 * php tests/harness/public-surface-snapshot-harness.php           # verify
 * php tests/harness/public-surface-snapshot-harness.php --record  # rewrite fixture
 */
$root = dirname(__DIR__, 2);
$fixture = $root . '/tests/fixtures/public-surface.json';
$main = is_file($root . '/supcheckout.php') ? $root . '/supcheckout.php' : $root . '/UPayments.php';

define('ABSPATH', __DIR__ . '/');
$GLOBALS['surface'] = array();
function supcheckout_surface_record($type, $hook, $priority, $args) {
    $GLOBALS['surface'][] = array('type' => $type, 'hook' => (string) $hook, 'priority' => (int) $priority, 'accepted_args' => (int) $args);
    return true;
}
function add_action($hook, $cb, $priority = 10, $args = 1) { return supcheckout_surface_record('action', $hook, $priority, $args); }
function add_filter($hook, $cb, $priority = 10, $args = 1) { return supcheckout_surface_record('filter', $hook, $priority, $args); }
function register_activation_hook($file, $cb) { return supcheckout_surface_record('activation', basename($file), 0, 0); }
function register_deactivation_hook($file, $cb) { return supcheckout_surface_record('deactivation', basename($file), 0, 0); }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/supcheckout/'; }
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_basename($f) { return 'supcheckout/' . basename($f); }
function get_option($n, $d = false) { return $n === 'active_plugins' ? array('supcheckout/' . basename($GLOBALS['surface_main'])) : $d; }
function is_admin() { return false; }
function __($t, $d = 'default') { return $t; }
$GLOBALS['surface_main'] = $main;
require $main;

$surface = $GLOBALS['surface'];
usort($surface, static function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
$json = json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (in_array('--record', $argv, true)) {
    file_put_contents($fixture, $json);
    echo "Recorded " . count($surface) . " surface entries.\n";
    exit(0);
}
$expected = is_file($fixture) ? (string) file_get_contents($fixture) : '';
if ($expected === $json) {
    echo "Public surface: " . count($surface) . " entries unchanged.\nPublic surface: PASS\n";
    exit(0);
}
echo "FAIL: public surface changed. Diff (expected vs actual):\n";
$e = json_decode($expected, true) ?: array();
foreach (array_udiff($e, $surface, static function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); }) as $gone) { echo "  - " . json_encode($gone) . "\n"; }
foreach (array_udiff($surface, $e, static function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); }) as $new) { echo "  + " . json_encode($new) . "\n"; }
exit(1);
```

- [ ] **Step 2: Run it without a fixture to see it fail.**
  - Run: `php tests/harness/public-surface-snapshot-harness.php`
  - Expected: `FAIL: public surface changed`, listing every entry as `+`.
- [ ] **Step 3: Record the baseline.**
  - Run: `php tests/harness/public-surface-snapshot-harness.php --record`
  - Check: `tests/fixtures/public-surface.json` lists the plugin's top-level hooks, including `plugins_loaded` at priority 10, `woocommerce_payment_gateways`, `woocommerce_available_payment_gateways`, `before_woocommerce_init`, `woocommerce_blocks_loaded`, `woocommerce_init`, `init`, `admin_init` and the `deactivation` entry.
- [ ] **Step 4: Run it again.**
  - Expected: `Public surface: PASS`.
- [ ] **Step 5: Extend the snapshot to the gateway's constructor-time hooks and identity.** Before `$surface = $GLOBALS['surface'];`, append the following. It finds the gateway class by its WooCommerce registration, so it keeps working when the class moves in Task 3.

```php
if (!class_exists('WC_Payment_Gateway')) {
    abstract class WC_Payment_Gateway {
        public $id = ''; public $has_fields = false; public $supports = array('products');
        public $form_fields = array(); public $settings = array(); public $method_title = '';
        public function init_form_fields() {}
        public function init_settings() { $this->settings = array(); }
        public function get_option($key, $empty = null) { return $empty === null ? '' : $empty; }
    }
}
class WooCommerce {}
if (function_exists('woocommerceUpaymentsInit')) { woocommerceUpaymentsInit(); }
// The class WooCommerce receives from the plugin's woocommerce_payment_gateways filter.
$gateway_class = null;
foreach (get_declared_classes() as $declared) {
    if (is_subclass_of($declared, 'WC_Payment_Gateway') && ($declared === 'WC_Upayments' || $declared === 'Simplixi\SUPCheckout\Gateway\Gateway')) { $gateway_class = $declared; }
}
if ($gateway_class !== null) {
    $before = count($GLOBALS['surface']);
    $gateway = new $gateway_class();
    $fields = array_keys((array) $gateway->form_fields);
    sort($fields);
    $GLOBALS['surface'][] = array('type' => 'gateway', 'hook' => (string) $gateway->id, 'priority' => count($fields), 'accepted_args' => $gateway->has_fields ? 1 : 0);
    $GLOBALS['surface'][] = array('type' => 'gateway_fields', 'hook' => implode(',', $fields), 'priority' => 0, 'accepted_args' => 0);
}
```

The include path calls a few more WordPress functions than the stub list in Step 1 defines (for example `is_multisite`, `wp_next_scheduled`, `get_locale`). The harness then stops with `Call to undefined function X`, naming each one. Add a one-line stub returning a neutral value (`false`, `''`, `array()`) for each, until the run completes. Re-record, re-run, and confirm `PASS`.
- [ ] **Step 6: Wire it into Quality Gates and commit.**

```bash
git add tests/harness/public-surface-snapshot-harness.php tests/fixtures/public-surface.json .github/workflows/quality-gates.yml
git commit -m "test(architecture): freeze the plugin's public WordPress surface"
```

### Task 2: Autoloader, `Plugin` composition root, renamed bootstrap

**Files:**
- Create: `src/Autoloader.php`
- Create: `src/Plugin.php`
- Create: `supcheckout.php`, a copy of `UPayments.php` with the plugin header and **all** code. In this task the old file is only renamed. Use `git mv UPayments.php supcheckout.php` so history follows.
- Modify: `src/Release/Identity.php`
  - add `public const MAIN_FILE = 'supcheckout.php';`;
  - keep `LEGACY_MAIN_FILE = 'UPayments.php'` for the Task 9 guard.
- Modify: `scripts/build-release.sh`, `scripts/verify-release.sh`, `scripts/install-wp-test-environment.sh`, `.github/workflows/*.yml` and `.github/CODEOWNERS`. Each reference to `UPayments.php` becomes `supcheckout.php`.
- Modify: every test and harness that requires or reads `UPayments.php` (45 files; find them with `grep -rl "UPayments\.php" tests`). Change the path only; no assertion logic changes.
- Test: `tests/unit/AutoloaderTest.php`

**Interfaces:**
- Produces:
  - `Simplixi\SUPCheckout\Autoloader::register(string $base_dir): void`;
  - `Simplixi\SUPCheckout\Plugin::boot(string $main_file): void`, which is idempotent and is the only place hooks are added from Task 3 on;
  - `Plugin::main_file(): string`.

- [ ] **Step 1: Write the failing autoloader test.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Autoloader;

final class AutoloaderTest extends TestCase {
    public function test_it_maps_the_namespace_root_to_src_and_ignores_foreign_classes(): void {
        require_once dirname(__DIR__, 2) . '/src/Autoloader.php';
        $dir = sys_get_temp_dir() . '/supcheckout-autoload-' . getmypid();
        @mkdir($dir . '/Probe', 0777, true);
        file_put_contents($dir . '/Probe/Sample.php', "<?php\nnamespace Simplixi\\SUPCheckout\\Probe;\nfinal class Sample { const OK = 1; }\n");
        Autoloader::register($dir);

        self::assertSame(1, \Simplixi\SUPCheckout\Probe\Sample::OK);
        self::assertFalse(class_exists('UPayments\\Subscription\\Manager', true));
        self::assertFalse(class_exists('Simplixi\\SUPCheckout\\Probe\\..\\Escape', true));
    }
}
```

- [ ] **Step 2: Run it.**
  - Run: `vendor/bin/phpunit --filter AutoloaderTest`
  - Expected: FAIL, because `src/Autoloader.php` does not exist.
- [ ] **Step 3: Implement the autoloader.**

```php
<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

/**
 * PSR-4 autoloader for Simplixi\SUPCheckout\ only. Hand-written so the package has no
 * Composer runtime dependency; it never loads anything outside the namespace root.
 */
final class Autoloader {
    private const PREFIX = 'Simplixi\\SUPCheckout\\';

    /** @var string|null */
    private static $base_dir = null;

    /**
     * @param string $base_dir Absolute path of src/.
     * @return void
     */
    public static function register($base_dir) {
        if (self::$base_dir !== null) {
            return;
        }
        self::$base_dir = rtrim((string) $base_dir, '/\\') . '/';
        spl_autoload_register(array(self::class, 'load'));
    }

    /**
     * @param string $class Fully qualified class name.
     * @return void
     */
    public static function load($class) {
        if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return;
        }
        $relative = substr($class, strlen(self::PREFIX));
        if (!preg_match('/^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$/', $relative)) {
            return;
        }
        $file = self::$base_dir . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }

    private function __construct() {}
}
```

The test needs `defined('ABSPATH')`. `tests/bootstrap.php` already defines it.

- [ ] **Step 4: Run it.**
  - Run: `vendor/bin/phpunit --filter AutoloaderTest`
  - Expected: PASS.
- [ ] **Step 5: Rename the main file and register the autoloader.**
  - Run `git mv UPayments.php supcheckout.php`.
  - Directly after the `ABSPATH` guard, insert:

```php
require_once __DIR__ . '/src/Autoloader.php';
\Simplixi\SUPCheckout\Autoloader::register(__DIR__ . '/src');
```

  - Create `src/Plugin.php`:

```php
<?php

namespace Simplixi\SUPCheckout;

defined('ABSPATH') || exit;

/**
 * Composition root. From Task 3 on, every WordPress hook SUPCheckout registers is
 * added from here (or from a class this method calls), never from the main file.
 */
final class Plugin {
    /** @var string|null */
    private static $main_file = null;

    /**
     * @param string $main_file Absolute path of supcheckout.php.
     * @return void
     */
    public static function boot($main_file) {
        if (self::$main_file !== null) {
            return;
        }
        self::$main_file = (string) $main_file;
    }

    /** @return string */
    public static function main_file() {
        return (string) self::$main_file;
    }

    private function __construct() {}
}
```

  - Add `\Simplixi\SUPCheckout\Plugin::boot(__FILE__);` after the autoloader lines.
- [ ] **Step 6: Update every path reference.**
  - Run `grep -rln "UPayments\.php" scripts .github tests` and replace `UPayments.php` with `supcheckout.php` in each file.
  - **Exception:** keep the literal in `src/Release/Identity.php::LEGACY_MAIN_FILE`, and in assertions that explicitly test the legacy file is **absent** (add those in Task 9).
  - Set `MAIN_FILE` in `Identity`.
- [ ] **Step 7: Run the full gate set.**
  - Run: `vendor/bin/phpunit && for f in tests/harness/*.php; do …; done && php tests/harness/public-surface-snapshot-harness.php` (the full loop from Global Constraints).
  - Expected: everything green. The surface is unchanged except the `activation`/`deactivation` entries' file field, which changes from `UPayments.php` to `supcheckout.php`. Re-record with `--record` and state that in the commit.
- [ ] **Step 8: Build and verify the package.**
  - Run: `bash scripts/build-release.sh dist && bash scripts/verify-release.sh dist/supcheckout-0.1.0.zip && unzip -l dist/supcheckout-0.1.0.zip | grep -E "supcheckout/(supcheckout|UPayments)\.php"`
  - Expected: `supcheckout/supcheckout.php` only.
- [ ] **Step 9: Commit.**

```bash
git add -A supcheckout.php src/Autoloader.php src/Plugin.php src/Release/Identity.php scripts .github tests
git commit -m "refactor(bootstrap): rename the main file to supcheckout.php behind a PSR-4 autoloader"
```

### Task 3: Move the gateway class out of the main file

**Files:**
- Create:
  - `src/Gateway/Gateway.php`: class `Simplixi\SUPCheckout\Gateway\Gateway extends \WC_Payment_Gateway`. `id`, `method_title`, `has_fields`, `supports`, the constructor, settings init and every method stay byte-for-byte except the namespace qualifiers listed below.
  - `src/Gateway/GatewayRegistration.php`: `public static function register(): void` (adds the two WooCommerce filters).
- Modify: `supcheckout.php`. Remove `woocommerceUpaymentsInit()`, `class WC_Upayments`, `addUpaymentsGatewayClass()` and `enableUpaymentsGateway()`. Add `GatewayRegistration::register()` to `Plugin::boot()`.
- Modify: `src/Plugin.php`.
- Test:
  - `tests/unit/Gateway/GatewayRegistrationTest.php`;
  - re-run `tests/harness/provider-payment-lifecycle-harness.php`, `tests/integration/callback-cache-http.sh` (CI) and every H12 harness.

**Interfaces:**
- Consumes: `Plugin::boot()` from Task 2.
- Produces:
  - `Simplixi\SUPCheckout\Gateway\Gateway` with **all** current public methods under the same names. In particular `getAPIUrlForRetreiveCards()`, `getPaymentIcons()`, `getSavedCardsForCurrentUser()`, `log()`, `getMode()`, `getAPIUrl()` and `execute_upayments_request()` (protected) stay, because `PaymentLifecycle`, `CheckoutOrchestrator`, the Blocks integration and third parties call them.
  - `GatewayRegistration::filter_gateways(array $methods): array` appends `Gateway::class`.
  - `GatewayRegistration::filter_available(array $gateways): array` delegates to `Availability::filter`.

- [ ] **Step 1: Write the failing registration test.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests\Gateway;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Gateway\GatewayRegistration;

final class GatewayRegistrationTest extends TestCase {
    public function test_woocommerce_receives_the_namespaced_gateway_class_once(): void {
        $methods = GatewayRegistration::filter_gateways(array('WC_Gateway_COD'));

        self::assertSame(array('WC_Gateway_COD', 'Simplixi\\SUPCheckout\\Gateway\\Gateway'), $methods);
        self::assertSame($methods, GatewayRegistration::filter_gateways($methods));
    }
}
```

- [ ] **Step 2: Run it.**
  - Run: `vendor/bin/phpunit --filter GatewayRegistrationTest`
  - Expected: FAIL, with the class not found.
- [ ] **Step 3: Move the code.**
  - Cut the body of `class WC_Upayments` into `src/Gateway/Gateway.php` under `namespace Simplixi\SUPCheckout\Gateway;`, with `defined('ABSPATH') || exit;`.
  - Prefix global WordPress/WooCommerce classes with `\` (`\WC_Payment_Gateway`, `\WC_Order`, `\WC_Admin_Settings`).
  - Keep the existing `use` imports, moved to the top of the new file.
  - Write `GatewayRegistration`:

```php
<?php

namespace Simplixi\SUPCheckout\Gateway;

defined('ABSPATH') || exit;

final class GatewayRegistration {
    /** @return void */
    public static function register() {
        add_filter('woocommerce_payment_gateways', array(self::class, 'filter_gateways'));
        add_filter('woocommerce_available_payment_gateways', array(self::class, 'filter_available'));
    }

    /**
     * @param mixed $methods Registered gateway classes.
     * @return array
     */
    public static function filter_gateways($methods) {
        $methods = is_array($methods) ? $methods : array();
        if (!in_array(Gateway::class, $methods, true)) {
            $methods[] = Gateway::class;
        }
        return $methods;
    }

    /**
     * @param mixed $gateways Available gateways.
     * @return mixed
     */
    public static function filter_available($gateways) {
        return Availability::filter($gateways);
    }

    private function __construct() {}
}
```

  - Before moving, confirm the existing `enableUpaymentsGateway()` body is exactly `return Availability::filter($available_gateways);`. If it does more, move that logic verbatim into `filter_available`.
- [ ] **Step 4: Update every `WC_Upayments` reference.**
  - Run `grep -rn "WC_Upayments" src includes supcheckout.php tests`. Change each `instanceof WC_Upayments` or `new WC_Upayments` to `\Simplixi\SUPCheckout\Gateway\Gateway`, including in harness stubs and the `GatewaySingleInstanceTest`.
  - The architecture rule "no second gateway instance" from PR #147 is retargeted to forbid `new\s+\\?(WC_Upayments|Gateway)\s*\(` in shipped code.
- [ ] **Step 5: Run the gates.**
  - The surface snapshot must still PASS: the same hooks are registered, now from the classes.
  - `provider-payment-lifecycle-harness.php` must report 0 FAIL.
  - Expected: all green.
- [ ] **Step 6: Commit.** List every retargeted assertion in the commit body.

```bash
git add -A
git commit -m "refactor(gateway): move WC_Upayments into Simplixi\\SUPCheckout\\Gateway\\Gateway"
```

### Task 4: Move remaining globals and closures into named classes

**Files:**
- Create:
  - `src/Bootstrap/Requirements.php`: `missing_woocommerce_notice()` (was `upaymentsMissingWcNotice`).
  - `src/Bootstrap/WooCommerceDeclarations.php`: the `before_woocommerce_init` closure body.
  - `src/Account/SubscriptionActions.php`: the `init` closure handling `upay_action` POSTs, moved verbatim into `public static function handle(): void`.
  - `src/Subscription/ProductTypeHooks.php`: the eight product-data functions (`addCustomProductType` … `saveCustomDataToOrderItems`), each a `public static` method delegating exactly as now.
- Modify: `supcheckout.php` (remove them), `src/Plugin.php` (register them).
- Test: `tests/harness/public-surface-snapshot-harness.php` (must PASS unchanged), plus `tests/unit/Account/SubscriptionActionsTest.php`.

**Interfaces:**
- Produces:
  - `Requirements::register()`;
  - `WooCommerceDeclarations::register()`;
  - `SubscriptionActions::register()`;
  - `ProductTypeHooks::register()`.
  - Each `register()` adds exactly the hooks the moved code added, with the same priorities and argument counts.

- [ ] **Step 1: Write a failing test for the account action handler's no-op contract.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests\Account;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Account\SubscriptionActions;

final class SubscriptionActionsTest extends TestCase {
    public function test_non_post_and_unrelated_requests_are_ignored_without_side_effects(): void {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = array('upay_action' => 'pause');
        SubscriptionActions::handle();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = array('unrelated' => '1');
        SubscriptionActions::handle();
        self::assertTrue(true, 'no redirect, exit or order lookup happened');
    }
}
```

- [ ] **Step 2: Run it.** Expected: FAIL, with the class not found.
- [ ] **Step 3: Move each closure or function body verbatim into its class method.** Register it from `Plugin::boot()` in the original order, so hook execution order within each hook is unchanged.
- [ ] **Step 4: Run the gates.**
  - The surface snapshot must PASS without re-recording.
  - `grep -nE "^function |^\s*add_(action|filter)\(" supcheckout.php` must show none.
  - Expected: all green.
- [ ] **Step 5: Commit.**

```bash
git add -A
git commit -m "refactor(bootstrap): move every remaining global and closure into named classes"
```

### Task 5: Shrink `supcheckout.php` to the bootstrap

**Files:**
- Modify: `supcheckout.php`. It must be ≤ 120 lines: header, `ABSPATH` guard, `Requirements` PHP check, autoloader, `OfficialPluginConflict::pause_if_active(__FILE__)` early return, and `Plugin::boot(__FILE__)`.
- Modify: `tests/harness/architecture-foundation-harness.php`. Retarget the `UPayments.php` byte ratchet to a **line ceiling** on `supcheckout.php` (≤ 120) and a byte ceiling (≤ 6,000). Intent kept: the bootstrap can never grow responsibilities.
- Modify:
  - `tests/harness/architecture-bootstrap-path-harness.php`;
  - `tests/harness/architecture-runtime-bindings-harness.php`.

  Their token rules move from `UPayments.php` top-level to `Plugin::boot()` and `GatewayRegistration::register()`, with the same rules: direct, unconditional and executable. The PR #146 wrapper exception is deleted, because the main file now declares nothing, so an early `return` is sufficient and honest.

**Interfaces:**
- Produces: the final bootstrap shape that 0b and 0c build on.

- [ ] **Step 1: Write the failing ratchet.** In `architecture-foundation-harness.php`, replace the `$acceptedGatewayBytes` assertion with:

```php
$bootstrap = (string) file_get_contents($root . '/supcheckout.php');
arch_assert(substr_count($bootstrap, "\n") <= 120, 'supcheckout.php stays a bootstrap (<= 120 lines)');
arch_assert(strlen($bootstrap) <= 6000, 'supcheckout.php stays a bootstrap (<= 6,000 bytes)');
arch_assert(!preg_match('/^\s*(function|class|final class|abstract class|interface|trait)\s/m', $bootstrap), 'supcheckout.php declares no functions or classes');
arch_assert(substr_count($bootstrap, 'add_action(') + substr_count($bootstrap, 'add_filter(') === 0, 'supcheckout.php registers no hooks itself');
```

- [ ] **Step 2: Run it.**
  - Run: `php tests/harness/architecture-foundation-harness.php`
  - Expected: FAIL, because the file is still large.
- [ ] **Step 3: Move every remaining responsibility out of `supcheckout.php`** into the Task 3 and Task 4 classes until only the bootstrap remains.

```php
<?php
/**
 * Plugin Name: SUPCheckout for UPayments
 * Plugin URI: https://github.com/SimplixInnovations/supcheckout
 * Description: Independently engineered UPayments payment integration for WooCommerce by Simplix Innovations.
 * Version: 0.1.0
 * Author: Simplix Innovations
 * Author URI: https://simplixi.com
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 10.8
 * WC tested up to: 11.1
 * License: MIT
 * Text Domain: supcheckout
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Simplixi\SUPCheckout\Autoloader::register(__DIR__ . '/src');

// While another plugin owns the 'upayments' gateway identity, stay paused (0b removes
// the shared identity; the guard stays for the official plugin's own globals).
if (\Simplixi\SUPCheckout\Gateway\OfficialPluginConflict::pause_if_active(__FILE__)) {
    return;
}

\Simplixi\SUPCheckout\Plugin::boot(__FILE__);
```

- [ ] **Step 4: Retarget the two token harnesses.**
  - Point their rules at `src/Plugin.php` and `src/Gateway/GatewayRegistration.php`.
  - Delete the coexistence wrapper exception added in PR #146.
  - Add an assertion that `Plugin::boot` directly and unconditionally calls `GatewayRegistration::register();`.
- [ ] **Step 5: Run the gates.** The surface snapshot must PASS. The coexistence harness must report 23/23, retargeted to `supcheckout.php`.
- [ ] **Step 6: Commit** with the list of retargeted assertions.

```bash
git add -A
git commit -m "refactor(bootstrap): supcheckout.php is a ~30-line bootstrap; controls retargeted"
```

### Task 6: Move `includes/` into `src/` under `Simplixi\SUPCheckout\`

**Files:**
- Move, each with `git mv`, then change the `namespace` line and its `use` statements:

| From | To |
|---|---|
| `includes/Token/CustomerTokenIdentity.php` | `src/Token/CustomerTokenIdentity.php` (`Simplixi\SUPCheckout\Token`) |
| `includes/Subscription/Manager.php` | `src/Subscription/Manager.php` |
| `includes/Subscription/Checkout/Fields.php` | `src/Subscription/Checkout/Fields.php` |
| `includes/Subscription/Helpers/Utils.php` | `src/Subscription/Helpers/Utils.php` |
| `includes/Subscription/Cron/CycleClaim.php` | `src/Subscription/Cron/CycleClaim.php` |
| `includes/Subscription/Cron/Scheduler.php` | `src/Subscription/Cron/Scheduler.php` |
| `includes/class-wc-gateway-upayments-blocks.php` | `src/Blocks/PaymentMethodType.php` (class `Simplixi\SUPCheckout\Blocks\PaymentMethodType`) |

- Modify: every `require_once` of these files, which the autoloader now handles. Remove the requires.
- Modify:
  - the protected blob pins of `Scheduler.php` (8 harnesses) and `CustomerTokenIdentity.php`. Move each pin to the new path **and** the new blob, and state in the commit that only the namespace line and imports changed;
  - the H12 harness references.
- Test: the surface snapshot; H12 PHP and Blocks; Phase 9I; Q13–Q15; `CycleClaimRuntimeTest` (CI).

**Interfaces:**
- Produces:
  - `Simplixi\SUPCheckout\Token\CustomerTokenIdentity`, with all public static methods unchanged;
  - `Simplixi\SUPCheckout\Subscription\Cron\Scheduler::CRON_HOOK === 'upay_process_subscriptions'` (unchanged value);
  - `Simplixi\SUPCheckout\Blocks\PaymentMethodType::get_name() === 'upayments'` (unchanged).

- [ ] **Step 1: Write a failing test that pins the identity values that must not move in 0a.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests\Identity;

use PHPUnit\Framework\TestCase;

final class ProtectedIdentityValuesTest extends TestCase {
    public function test_namespaced_classes_keep_every_persisted_identity(): void {
        self::assertSame('upay_process_subscriptions', \Simplixi\SUPCheckout\Subscription\Cron\Scheduler::CRON_HOOK);
        self::assertSame('upayments_token_identity_secret_v2', \Simplixi\SUPCheckout\Token\CustomerTokenIdentity::SECRET_OPTION);
        self::assertFalse(class_exists('UPayments\\Token\\CustomerTokenIdentity', false));
        self::assertFalse(class_exists('UPayments\\Subscription\\Cron\\Scheduler', false));
    }
}
```

- [ ] **Step 2: Run it.** Expected: FAIL, with the class not found.
- [ ] **Step 3: Do the moves and namespace changes.**
  - Run `grep -rln "UPayments\\\\Token\|UPayments\\\\Subscription" src supcheckout.php tests` and update every reference.
- [ ] **Step 4: Run the gates.** The surface snapshot must PASS, because hook names are unchanged. H12 must be 0 FAIL, with category counts unchanged.
- [ ] **Step 5: Delete the now-empty `includes/` directory.** Update `.distignore` if it lists `includes/`.
- [ ] **Step 6: Commit.**

```bash
git add -A
git commit -m "refactor: move includes/ into src/ under Simplixi\\SUPCheckout (no shared namespace with UPayments)"
```

### Task 7: Remove the dead crypto helpers and add the Blocks direct-access guard

**Files:**
- Modify: `src/Gateway/Gateway.php`. Delete `encrypt()`, `decrypt()` and `getApiKey()` (security review F5). They are base64 and bcrypt wrappers that nothing calls.
- Test: `tests/harness/architecture-foundation-harness.php` (new assertions).

**Interfaces:**
- Produces: the public method set of `Gateway` minus those three methods.
- **Unchanged:** `getAPIUrlForRetreiveCards()` stays as the protected compatibility wrapper.

- [ ] **Step 1: Write the failing assertions.**

```php
$gatewayClass = (string) file_get_contents($root . '/src/Gateway/Gateway.php');
foreach (array('function encrypt(', 'function decrypt(', 'function getApiKey(') as $dead) {
    arch_assert(strpos($gatewayClass, $dead) === false, 'misleading pseudo-crypto helper is gone: ' . $dead);
}
arch_assert(strpos($gatewayClass, 'function getAPIUrlForRetreiveCards(') !== false, 'public compatibility wrapper getAPIUrlForRetreiveCards() remains');
$blocks = (string) file_get_contents($root . '/src/Blocks/PaymentMethodType.php');
arch_assert(strpos($blocks, "defined('ABSPATH') || exit;") !== false, 'Blocks integration has a direct-access guard');
```

- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Confirm no callers.**
  - Run: `grep -rnE "(->|::)(encrypt|decrypt|getApiKey)\(" src supcheckout.php tests`
  - Expected: no production callers. If a harness calls them, delete that harness assertion and note it in the commit.
- [ ] **Step 4: Delete the three methods and add the guard.**
- [ ] **Step 5: Run the gates, then commit.**

```bash
git add -A
git commit -m "refactor(gateway): drop unused pseudo-crypto helpers; guard the Blocks file"
```

### Task 8: Coexistence with the official plugin after the restructure

**Files:**
- Modify: `tests/harness/official-plugin-coexistence-harness.php`. Its child loads `supcheckout.php`, and it adds a scenario where the official stub also declares `UPayments\Subscription\Manager`.
- Modify: `src/Gateway/OfficialPluginConflict.php`, only if the harness shows a gap.

**Interfaces:**
- Consumes: `OfficialPluginConflict::pause_if_active(string $plugin_file): bool` (existing).

- [ ] **Step 1: Add the scenarios.** SUPCheckout loads first and then the official plugin, and the reverse order. Both must exit 0, and `SUPCHECKOUT_VERSION` must be undefined while paused.

```php
$both_namespaces = coexistence_run($root, 'official_namespaces', $official_stub);
coexistence_assert($both_namespaces['code'] === 0, 'official UPayments\\Subscription classes never collide with SUPCheckout classes');
```

  In `coexistence_child`, the `official_namespaces` scenario requires a stub file declaring `namespace UPayments\Subscription; class Manager {}`, then loads `supcheckout.php`.
- [ ] **Step 2: Run it.**
  - Expected: PASS for `official_namespaces`, because Task 6 removed the shared namespace.
  - If anything FAILs, fix `OfficialPluginConflict` minimally.
- [ ] **Step 3: Commit.**

```bash
git add -A
git commit -m "test(coexistence): official plugin namespaces and globals never collide after the restructure"
```

### Task 9: Pre-rename installs and release metadata

**Files:**
- Modify:
  - `CHANGELOG.md` (an entry stating that builds activated before the rename must be reactivated once);
  - `docs/project/STAGING-FROM-PRODUCTION.md` (a reactivation step);
  - `tests/harness/release-artifact-harness.php` (the package contains `supcheckout/supcheckout.php` and no `UPayments.php`);
  - `docs/project/NAMING-IDENTITY-STANDARD.md` ("Physical bootstrap decision" replaced by the new decision and its date);
  - `AGENTS.md` (the "First-stable bootstrap exception" section replaced; the protected-identity list unchanged in 0a);
  - `docs/project/RELEASE-ENGINEERING.md`;
  - `docs/COMPATIBILITY.md` (references only).
- Test: `tests/harness/release-artifact-harness.php`.

**Interfaces:**
- Consumes: the package built by `scripts/build-release.sh`.

**Why there is no runtime guard:** a store still listing `supcheckout/UPayments.php` cannot load any SUPCheckout code, because that file no longer exists, so nothing could display a warning. WordPress drops the missing entry and reports the plugin as deactivated. Only staging and test installs ever ran the old name.

- [ ] **Step 1: Write the failing release assertions** in `tests/harness/release-artifact-harness.php`, next to the existing package-content checks:

```php
release_assert(in_array('supcheckout/supcheckout.php', $package_files, true), 'package main file is supcheckout/supcheckout.php');
release_assert(!in_array('supcheckout/UPayments.php', $package_files, true), 'package never ships the pre-rename UPayments.php');
release_assert(strpos((string) file_get_contents($root . '/CHANGELOG.md'), 'reactivate') !== false, 'CHANGELOG tells pre-rename installs to reactivate once');
```

`$package_files` is the list of archive entries the harness already builds. Reuse its existing variable name if it differs.

- [ ] **Step 2: Run it.**
  - Run: `php tests/harness/release-artifact-harness.php`
  - Expected: FAIL on the CHANGELOG assertion. The file assertions already pass after Task 2.
- [ ] **Step 3: Add the CHANGELOG entry and the runbook step.**
  - CHANGELOG, under Unreleased:

    > The main file is now `supcheckout.php`. Builds activated before this change (staging and test installs) appear deactivated after updating; reactivate SUPCheckout once on the Plugins screen. No data changes.

  - `STAGING-FROM-PRODUCTION.md`: add the matching reactivation step after the upload step.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Update the living docs.**
  - In `AGENTS.md`, replace the "First-stable bootstrap exception" section with:

    > `supcheckout/supcheckout.php` is the main file since Sub-project 0a (owner decision 2026-10-16, pre-release). Builds activated as `supcheckout/UPayments.php` must be reactivated once.

  - Apply the matching edits to `NAMING-IDENTITY-STANDARD.md` and `RELEASE-ENGINEERING.md`.
- [ ] **Step 6: Commit.**

```bash
git add -A
git commit -m "docs(release): record the main-file rename and the one-time reactivation"
```

### Task 10: Real-WordPress certification and staging rehearsal

**Files:** none (verification task). Evidence goes in `docs/project/evidence/SUBPROJECT-0A-CLEAN-CORE-<date>.md`.

- [ ] **Step 1: Run exact-head CI.**
  - Required: Quality/H12, the Compatibility Gate (20 cells), Release Artifact (packaged plus migration), WordPress.org Submission Check (Plugin Check strict), Provider Sandbox, CodeQL and Ecosystem Certification.
  - Expected: all green.
- [ ] **Step 2: Rehearse on staging.**
  - Build the zip.
  - Ask the owner to upload it to staging. WordPress will list SUPCheckout as inactive, because of the renamed main file.
  - Reactivate it.
  - Verify the installed-tree fingerprint.
  - Re-run the four sandbox scenarios from `STAGING-REHEARSAL-2026-10-15.md` Step 6 (success, decline, KNET cancel, replay).
  - Record the results.
- [ ] **Step 3: Commit the evidence** and update `PROJECT-STATUS.md`.

```bash
git add docs/project/evidence docs/project/PROJECT-STATUS.md
git commit -m "docs(evidence): Sub-project 0a clean core certified"
```

---

## What 0a deliberately does NOT do (Sub-project 0b / 0c)

- **No data identities are renamed:** the gateway ID, settings option, meta, callback route, cron hook and H12 options stay. That needs the verified copy, switch and rollback engine (0b).
- **No switch or onboarding UI** (0c, built on Sub-projects 1–2).
- **No behavior changes:** every task is a move or a removal of dead code. Behavior fixes go through the normal bug-fix PRs.
