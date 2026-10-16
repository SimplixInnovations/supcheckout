# Sub-project 0b — Takeover Engine (Smart Switch) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move a store from the official UPayments plugin (2.x or 3.x), or from SUPCheckout's own pre-0b names, to the fully renamed `supcheckout` identities. The switch is non-destructive, verified, resumable and reversible for 30 days, and it never loses a historical order, in-flight payment, webhook, subscription or third-party gateway reference.

**Architecture:** One state machine (`TakeoverState`) drives five services:
- `Preflight`: read-only, produces a report;
- `SettingsCopier` and `ThirdPartyTranslator`: options, each change recorded for rollback;
- `OrderCopier`: batched through Action Scheduler; copy, never move, with field-by-field verification;
- `Cutover`: switches the active gateway identity;
- `Rollback`/`Finalize`.

All code reads identities through one `Keys` registry with legacy fallback, so checkout keeps working at every point of the switch. A permanent `LegacyCallbackListener` serves callbacks that UPayments still holds for pre-switch payments. The UI comes in 0c; 0b ships a WP-CLI interface and a real-WordPress migration matrix.

**Tech Stack:** PHP 7.4+, WordPress 6.9+, WooCommerce 10.8+ (HPOS and legacy), Action Scheduler (bundled with WooCommerce), WP-CLI, PHPUnit, GitHub Actions.

**Spec and evidence:**
- `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md` §5;
- `docs/superpowers/specs/2026-10-16-official-plugin-data-inventory.md`, the source of every legacy name below.

**Prerequisite:** Sub-project 0a merged. Paths below use the post-0a layout (`supcheckout.php` and `src/`).

## Global Constraints

**Namespace and storage**
- Namespace `Simplixi\SUPCheckout\Takeover\`. Not `Switch`: it is a reserved word that PHP 7.4 rejects in namespace names.
- New identities use the `supcheckout` and `_supcheckout_` prefixes. Every legacy → new pair comes from the inventory and lives **only** in `Keys` (Task 1).
- **Copy, never move.** No legacy option, meta, cron event or third-party value is deleted before `Finalize`, and `Finalize` is refused until 30 days after `Cutover` unless the owner passes `--force-after-review`.
- **Records:**
  - every write the takeover makes to a value it did not create records `{target, path, before, after}` in the takeover journal, the option `supcheckout_takeover_journal_v1` (an append-only array, non-autoloaded);
  - `Rollback` replays that journal in reverse;
  - the journal never contains API keys, tokens, card data or customer PII. Settings values are journaled as a SHA-256 fingerprint plus the restore source (the legacy option is kept intact for exactly this reason).
- **Batches:** at most 50 orders per Action Scheduler action, idempotent, resumable from a persisted cursor. A failure mid-batch never leaves an order half-copied: each order's copy is one `save()`.
- **Payment authority:** paid state still comes only from authenticated UPayments status. The takeover never marks, unmarks or re-verifies payments.

**Out of scope**
- **Saved cards** are not copied. They live at UPayments under four phone-derived token formulas (inventory hazard 2). SUPCheckout keeps re-deriving and re-verifying them live, exactly as today.
- **The official plugin's `debug.log`** is never read into, copied or included in reports beyond "present: yes/no plus a path".

**Gates**
- Every task ends with PHPUnit, all PHP and JS harnesses, PHPStan, PHPCS and the 0a public-surface snapshot green. Any intended snapshot change is re-recorded with a reason.
- Task 12's migration matrix must be green before 0c.
- Conventional commits with no attribution footers. One PR per task, squash-merged after exact-head CI.

## Review Focus

1. **A store with HPOS on and compatibility sync off, coming from the official plugin.** It reads with `get_post_meta`, so legacy data may exist only in the HPOS meta table. Expected: `OrderCopier` reads through WC CRUD and reports postmeta/HPOS disagreement instead of copying empty values. → Task 5 test.
2. **`UPayments_WHS` with several rows on one order.** Expected: all values are preserved, verification compares the full value lists, and no row is dropped. → Task 5 test.
3. **A webhook for a pre-switch payment arriving mid-copy, or months after Finalize.** Expected: it routes through the normal verified lifecycle and pays the right order once. → Task 8 test and Task 12 matrix row.
4. **The process killed in the middle of a batch.** Expected: the next run resumes at the cursor, no order is skipped or double-processed, and verification still passes. → Task 6 test.
5. **Rollback after customers paid through SUPCheckout during the 30 days.** Expected: those orders stay paid and keep their payment IDs. Rollback changes identities and settings only, never payment state. → Task 10 test.

---

## File Structure

```text
src/Takeover/
  Keys.php                 Legacy → new identity registry + read-with-fallback helpers
  TakeoverState.php        Persisted state machine (idle → preflight_ok → copying → verified → cut_over → finalized | rolled_back)
  Journal.php              Append-only change journal (option supcheckout_takeover_journal_v1)
  Preflight.php            Read-only report: official plugin/version, credential, pending links, scans, hazards
  SettingsCopier.php       Gateway settings + SUPCheckout-owned options
  ThirdPartyTranslator.php woocommerce_gateway_order, woocommerce_default_gateway, WCML gateway rules, FunnelKit lists, options-table scan
  OrderCopier.php          Per-order copy + verify; Action Scheduler batch runner with cursor
  Cutover.php              Activates the 'supcheckout' gateway identity, reschedules cron, deactivates the official plugin (consent)
  LegacyCallbackListener.php  Permanent woocommerce_api_wc_upayments → lifecycle
  Rollback.php, Finalize.php
  Cli.php                  wp supcheckout takeover preflight|run|status|rollback|finalize
tests/unit/Takeover/*Test.php
tests/integration/Takeover*Test.php + .github/workflows/takeover-migration-matrix.yml
```

---

### Task 1: `Keys` registry with read-with-fallback

**Files:**
- Create: `src/Takeover/Keys.php`
- Test: `tests/unit/Takeover/KeysTest.php`

**Interfaces:**
- Produces:
  - `Keys::order_meta(): array<string,string>`, mapping legacy to new;
  - `Keys::options(): array<string,string>`;
  - `Keys::cron_hooks(): array<string,string>`;
  - `Keys::GATEWAY_LEGACY = 'upayments'`;
  - `Keys::GATEWAY = 'supcheckout'`;
  - `Keys::read_order_meta(\WC_Order $order, string $new_key)`, which returns the new value, else the legacy value, else `''`;
  - `Keys::legacy_for(string $new_key): ?string`.

- [ ] **Step 1: Write the failing test.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;

final class KeysTest extends TestCase {
    public function test_every_mapping_is_unique_prefixed_and_reversible(): void {
        foreach (array(Keys::order_meta(), Keys::options(), Keys::cron_hooks()) as $map) {
            self::assertSame(count($map), count(array_unique($map)), 'no two legacy names map to one new name');
            foreach ($map as $legacy => $new) {
                self::assertMatchesRegularExpression('/^_?supcheckout_/', $new, $new);
                self::assertSame($legacy, Keys::legacy_for($new));
            }
        }
        self::assertSame('upayments', Keys::GATEWAY_LEGACY);
        self::assertSame('supcheckout', Keys::GATEWAY);
    }

    public function test_the_inventory_names_are_all_covered(): void {
        $meta = Keys::order_meta();
        foreach (array('UPayments_order_id', 'UPayments_PaymentID', 'UPayments_TrackID', 'UPayments_Result',
            'UPayments_WHS', 'UPayments_AutoDeduction', '_upay_subscription_plan', '_upay_last_billed_at',
            'UPayments_ParentOrderID', '_simplixpay_upayments_status_track_v1') as $legacy) {
            self::assertArrayHasKey($legacy, $meta, $legacy);
        }
        self::assertSame('woocommerce_supcheckout_settings', Keys::options()['woocommerce_upayments_settings']);
        self::assertSame('supcheckout_process_subscriptions', Keys::cron_hooks()['upay_process_subscriptions']);
    }
}
```

- [ ] **Step 2: Run it.**
  - Run: `vendor/bin/phpunit --filter KeysTest`
  - Expected: FAIL, with the class not found.
- [ ] **Step 3: Implement `Keys`.** Copy every row from the inventory §2–§4 into the three maps. Example rows:

```php
<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * The single registry of legacy → SUPCheckout identity names (evidence:
 * docs/superpowers/specs/2026-10-16-official-plugin-data-inventory.md).
 * Code reads through read_order_meta() so a store works before, during and after
 * the takeover.
 */
final class Keys {
    public const GATEWAY_LEGACY = 'upayments';
    public const GATEWAY = 'supcheckout';
    public const CALLBACK_ROUTE_LEGACY = 'wc_upayments';
    public const CALLBACK_ROUTE = 'supcheckout';

    /** @return array<string,string> */
    public static function order_meta() {
        return array(
            'UPayments_order_id' => '_supcheckout_provider_order_id',
            'UPayments_Result' => '_supcheckout_result',
            'UPayments_PaymentID' => '_supcheckout_payment_id',
            'UPayments_TrackID' => '_supcheckout_track_id',
            'UPayments_payment_type' => '_supcheckout_payment_type',
            'UPayments_PostDate' => '_supcheckout_post_date',
            'UPayments_TranID' => '_supcheckout_tran_id',
            'UPayments_Ref' => '_supcheckout_ref',
            'UPayments_Auth' => '_supcheckout_auth',
            'UPayments_webhook_triggered' => '_supcheckout_webhook_triggered',
            'UPayments_WHS' => '_supcheckout_public_status',
            'UPayments_reference' => '_supcheckout_reference',
            'UPayments_Checkout_Selected' => '_supcheckout_checkout_selected',
            'UPayments_AutoDeduction' => '_supcheckout_auto_deduction',
            'UPayments_ParentOrderID' => '_supcheckout_parent_order_id',
            'UPayments_InvoiceID' => '_supcheckout_invoice_id',
            'UPayments_TransactionDate' => '_supcheckout_transaction_date',
            'UPayments_GatewayStatus' => '_supcheckout_gateway_status',
            '_upay_credit_card_token' => '_supcheckout_card_token',
            '_upay_customer_unique_token' => '_supcheckout_customer_token',
            '_upay_customer_token_kind_v1' => '_supcheckout_customer_token_kind_v1',
            '_upay_customer_token_scope_v1' => '_supcheckout_customer_token_scope_v1',
            '_upay_customer_token_generation_v1' => '_supcheckout_customer_token_generation_v1',
            '_upay_subscription_plan' => '_supcheckout_subscription_plan',
            '_upay_subscription_interval' => '_supcheckout_subscription_interval',
            '_upay_subscription_status' => '_supcheckout_subscription_status',
            '_upay_last_billed_at' => '_supcheckout_last_billed_at',
            '_upay_last_attempt_at' => '_supcheckout_last_attempt_at',
            '_upay_retry_count' => '_supcheckout_retry_count',
            '_upay_last_failed_reason' => '_supcheckout_last_failed_reason',
            '_upay_verified_capture' => '_supcheckout_verified_capture',
            '_simplixpay_upayments_status_track_v1' => '_supcheckout_status_track_v1',
            '_simplixpay_upayments_status_requested_v1' => '_supcheckout_status_requested_v1',
            '_simplixpay_upayments_unverified_track_v1' => '_supcheckout_unverified_track_v1',
            '_simplixpay_upayments_unverified_requested_v1' => '_supcheckout_unverified_requested_v1',
            '_simplixpay_upayments_prior_requested_v1' => '_supcheckout_prior_requested_v1',
            // ... every remaining _simplixpay_upayments_*_v1 constant from PaymentLifecycle.php
        );
    }

    /** @return array<string,string> */
    public static function options() {
        return array(
            'woocommerce_upayments_settings' => 'woocommerce_supcheckout_settings',
            'upayments_token_identity_secret_v2' => 'supcheckout_token_identity_secret_v2',
            'upay_billing_cycle_schema_version' => 'supcheckout_billing_cycle_schema_version',
            'upay_subscription_enrollment_cursor' => 'supcheckout_subscription_enrollment_cursor',
            'simplixpay_upayments_migration_v1' => 'supcheckout_migration_v1',
        );
    }

    /** @return array<string,string> */
    public static function cron_hooks() {
        return array(
            'upay_process_subscriptions' => 'supcheckout_process_subscriptions',
            'simplixpay_upayments_reconcile_order' => 'supcheckout_reconcile_order',
            'simplixpay_upayments_reconcile_secondary' => 'supcheckout_reconcile_secondary',
        );
    }

    /**
     * @param string $new_key SUPCheckout name.
     * @return string|null Legacy name.
     */
    public static function legacy_for($new_key) {
        foreach (array(self::order_meta(), self::options(), self::cron_hooks()) as $map) {
            $legacy = array_search($new_key, $map, true);
            if ($legacy !== false) {
                return (string) $legacy;
            }
        }
        return null;
    }

    /**
     * @param \WC_Order $order   Order.
     * @param string    $new_key SUPCheckout meta key.
     * @return mixed New value, else the legacy value, else ''.
     */
    public static function read_order_meta($order, $new_key) {
        $value = $order->get_meta($new_key);
        if ($value !== '' && $value !== null) {
            return $value;
        }
        $legacy = self::legacy_for($new_key);
        return $legacy === null ? '' : $order->get_meta($legacy);
    }

    private function __construct() {}
}
```

  - The executor **must** replace the `// ...` comment by listing every remaining constant. Get them with:

    `grep -n "_META = '" src/Payment/PaymentLifecycle.php src/Token/CustomerTokenIdentity.php src/Subscription/Cron/*.php`

  - Then extend the second test with each one. **Done means `grep` finds no persisted name that is missing from `Keys`.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): identity registry with legacy read-through`

### Task 2: Route every runtime read and write through `Keys`

**Files:**
- Modify: every class that uses a persisted name: `PaymentLifecycle`, `CheckoutOrchestrator`, `OrderPresentation`, `CustomerTokenIdentity`, `Scheduler`, `CycleClaim`, `HistoricalEnrollment`, `PublicOrderStatus`, `Gateway`, `Presentation`, `Blocks/PaymentMethodType`.
- Test: the existing suites plus `tests/unit/Takeover/DualReadTest.php`.

**Interfaces:**
- Consumes: `Keys::read_order_meta()` and the maps.
- Produces: a runtime that behaves as follows:
  - **Reads** go new-name-first with legacy fallback.
  - **Writes** go to the new name only after `TakeoverState::is_cut_over()` is true (Task 3); before cutover they write the legacy names exactly as today.

  So nothing changes for stores that never run the takeover, and nothing breaks during it.

- [ ] **Step 1: Write the failing dual-read test.** A fake order holds legacy `UPayments_PaymentID=pay-1` and no new key. `OrderPresentation::add_order_item_totals()` shows `pay-1`. A second order holds both, with new `pay-2` and legacy `pay-1`, and shows `pay-2`.
- [ ] **Step 2: Run it.** Expected: FAIL. `OrderPresentation` reads only the legacy key.
- [ ] **Step 3: Replace reads.** Change each `->get_meta('LEGACY')` to `Keys::read_order_meta($order, Keys::order_meta()['LEGACY'])`, through a private `meta($order, $legacy)` helper per class.
- [ ] **Step 4: Replace writes.** Change each `->update_meta_data('LEGACY', $v)` to `->update_meta_data(Keys::write_key('LEGACY'), $v)`. Add `Keys::write_key(string $legacy): string`, which returns the new name when cut over and the legacy name otherwise.
- [ ] **Step 5: Run every suite.** H12 and the lifecycle harness must be unchanged: the default state is not cut over, so every legacy write is identical.
- [ ] **Step 6: Commit.** `refactor(takeover): all persisted identities go through Keys`

### Task 3: `TakeoverState` and `Journal`

**Files:**
- Create: `src/Takeover/TakeoverState.php`, `src/Takeover/Journal.php`
- Test: `tests/unit/Takeover/TakeoverStateTest.php`, `JournalTest.php`

**Interfaces:**
- Produces:
  - `TakeoverState::get(): array{status:string, cut_over_at:int, cursor:int, totals:array}`;
  - `TakeoverState::transition(string $to): bool`, allowed transitions only;
  - `TakeoverState::is_cut_over(): bool`;
  - `Journal::record(string $target, string $path, string $before_fingerprint, string $restore_source): void`;
  - `Journal::entries(): array`.

- [ ] **Step 1: Write the failing transition test.**

```php
public function test_only_the_documented_transitions_are_allowed(): void {
    self::assertSame('idle', TakeoverState::get()['status']);
    self::assertFalse(TakeoverState::transition('cut_over'), 'cannot skip preflight and copy');
    self::assertTrue(TakeoverState::transition('preflight_ok'));
    self::assertTrue(TakeoverState::transition('copying'));
    self::assertTrue(TakeoverState::transition('verified'));
    self::assertTrue(TakeoverState::transition('cut_over'));
    self::assertTrue(TakeoverState::is_cut_over());
    self::assertFalse(TakeoverState::transition('finalized'), 'finalize waits 30 days');
    self::assertTrue(TakeoverState::transition('rolled_back'));
    self::assertFalse(TakeoverState::is_cut_over());
}
```

- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
  - The state lives in a non-autoloaded option `supcheckout_takeover_state_v1`.
  - The allowed map is `idle→preflight_ok`, `preflight_ok→copying`, `copying→verified|preflight_ok`, `verified→cut_over`, `cut_over→rolled_back|finalized`, and `rolled_back→preflight_ok`.
  - `finalized` requires `time() - cut_over_at >= 30 * DAY_IN_SECONDS`, or the `force` flag that the CLI sets only with `--force-after-review`.
  - Write the option with `OrderLock::insert_if_absent` for the first record, then a compare-and-swap update, so two admins can't race a transition.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Add the `JournalTest`.** Assert that entries never contain the API key: record a settings change whose value contains `test-api-key-123`, and assert that string is absent from `json_encode(Journal::entries())`. Implement until it passes.
- [ ] **Step 6: Commit.** `feat(takeover): persisted state machine and change journal`

### Task 4: `Preflight` (read-only report)

**Files:**
- Create: `src/Takeover/Preflight.php`
- Test: `tests/unit/Takeover/PreflightTest.php`

**Interfaces:**
- Produces: `Preflight::run(): array{blocking:list<array{code,message}>, warnings:list<...>, facts:array}`. It writes nothing. Codes are stable strings that 0c's UI uses.

**Checks:** every item comes from inventory §1–§8 and the hazards. Each is a pure method tested with fakes.

| Code | Severity | Condition |
|---|---|---|
| `official_plugin_active` | fact | another active `*/UPayments.php`; its version from the plugin header |
| `credential_unverified` | blocking | the existing availability call fails with the current key |
| `account_mode` | fact | white-label or hosted, plus methods |
| `currency_unsupported` | blocking | store currency not in the gateway's supported list |
| `pending_with_open_link` | warning | count of pending UPayments orders newer than 48 h |
| `hpos_meta_disagreement` | warning | sample of ≤ 200 orders where `get_post_meta` and CRUD differ |
| `whs_multi_valued` | fact | orders with several `UPayments_WHS` rows |
| `duplicate_renewals` | warning | renewal orders sharing parent and billing date (inventory hazard 6) |
| `official_debug_log_present` | warning | `wp-content/plugins/<official>/debug.log` exists. Report **path only**, never content. Advise deleting it and rotating the key. |
| `third_party_references` | fact / warning | options-table scan for `upayments` outside the known names, listing option names only |
| `callback_unreachable` | blocking | a loopback GET of the new callback URL (`?wc-api=supcheckout&supcheckout_probe=1`) is not 200 |
| `cache_exclusion_missing` | warning | the response to that probe carries a cache-hit header (`x-litespeed-cache: hit`, `cf-cache-status: HIT`, …) |
| `leftovers` | fact | the 3.0.0 "Checkout (Block)" page, the forced phone field, the PUC option and cron, `upayments_cron_lock` |

- [ ] **Step 1: Write the failing tests.** One test per code, with a fake environment. Example for the debug log:

```php
public function test_official_debug_log_is_reported_by_path_only(): void {
    $dir = sys_get_temp_dir() . '/sup-preflight-' . getmypid() . '/upayments';
    @mkdir($dir, 0777, true);
    file_put_contents($dir . '/debug.log', "API key: live-secret-123\n");
    $report = Preflight::run_with(array('official_plugin_dir' => $dir) + PreflightFixtures::minimal());

    $warning = PreflightFixtures::find($report['warnings'], 'official_debug_log_present');
    self::assertNotNull($warning);
    self::assertStringNotContainsString('live-secret-123', json_encode($report));
}
```

- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement `Preflight::run()`** as `run_with(Environment::from_wordpress())`, where `Environment` is a small value object whose fields the tests fake.
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): read-only preflight report`

### Task 5: `OrderCopier` (copy and verify one order)

**Files:**
- Create: `src/Takeover/OrderCopier.php`
- Test: `tests/unit/Takeover/OrderCopierTest.php`

**Interfaces:**
- Produces:
  - `OrderCopier::copy(\WC_Order $order): array{copied:int, skipped:int}`;
  - `OrderCopier::verify(\WC_Order $order): array{ok:bool, mismatches:list<string>}`.
- Behavior:
  - legacy keys stay;
  - `payment_method` changes from `upayments` to `supcheckout` **only in `Cutover`** (Task 7), never here.

- [ ] **Step 1: Write the failing tests.**
  - **Multi-valued `UPayments_WHS`** (Review Focus 2): two rows `failed` and `completed` result in two new rows with the same values in the same order, and `verify()` returns `ok`.
  - **HPOS disagreement** (Review Focus 1): when CRUD returns a value, copy it, and the source of truth is CRUD.
  - **Idempotence:** running `copy()` twice equals running it once.
  - **Untouched order data:** a value the takeover did not create is never overwritten. If the new key already holds a different value, report a mismatch and don't overwrite.

```php
public function test_multi_valued_public_status_is_copied_in_full(): void {
    $order = OrderCopierFixtures::order(array('UPayments_WHS' => array('failed', 'completed')));
    OrderCopier::copy($order);
    self::assertSame(array('failed', 'completed'), OrderCopierFixtures::all_values($order, '_supcheckout_public_status'));
    self::assertTrue(OrderCopier::verify($order)['ok']);
    OrderCopier::copy($order);
    self::assertSame(array('failed', 'completed'), OrderCopierFixtures::all_values($order, '_supcheckout_public_status'));
}
```

- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
  - Read all values per key with `$order->get_meta($legacy, false)`, which returns every row.
  - Add each value under the new key with `add_meta_data($new, $value)`, and end with a single `$order->save()`.
  - Skip when the new key already holds an identical value list.
- [ ] **Step 4: Run them.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): per-order copy and verify`

### Task 6: Batched, resumable copy runner

**Files:**
- Modify: `src/Takeover/OrderCopier.php`. Add `run_batch(): void` and `schedule(): void`.
- Test: `tests/unit/Takeover/OrderCopierBatchTest.php`

**Interfaces:**
- Produces:
  - the Action Scheduler action `supcheckout_takeover_copy_batch` in group `supcheckout`;
  - the cursor `TakeoverState::get()['cursor']`, the last order ID processed, ascending.
  - The runner selects orders with `payment_method IN ('upayments','supcheckout')` **or** carrying any legacy key from `Keys::order_meta()`. That second condition catches gateway-switched orders.

- [ ] **Step 1: Write the failing test.** Simulate 120 orders, with a batch that "crashes" after order 37 (Review Focus 4). Run it again, and assert:
  - every order is copied exactly once;
  - the cursor ends at the last ID;
  - `verify()` is `ok` for all;
  - the state is `verified` after the final batch.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
  - Each batch takes ≤ 50 IDs `> cursor`, ordered ascending, and copies and verifies each.
  - The cursor is persisted after **each** order, so a crash re-processes at most one order, and `copy()` is idempotent.
  - When fewer than 50 remain, transition `copying → verified` only if every verify returned `ok`. Otherwise go back to `preflight_ok` with the mismatches recorded in the state.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): resumable Action Scheduler copy runner`

### Task 7: `SettingsCopier`, `ThirdPartyTranslator` and `Cutover`

**Files:**
- Create: `src/Takeover/SettingsCopier.php`, `src/Takeover/ThirdPartyTranslator.php`, `src/Takeover/Cutover.php`
- Modify: `src/Gateway/Gateway.php`. `id` becomes `Keys::GATEWAY` when `TakeoverState::is_cut_over()`, otherwise `Keys::GATEWAY_LEGACY`.
- Test: `tests/unit/Takeover/CutoverTest.php`

**Interfaces:**
- Produces: `Cutover::run(bool $deactivate_official): array{ok:bool, steps:list<string>}`. In order:
  1. copy the settings option;
  2. translate `woocommerce_gateway_order`, the `woocommerce_default_gateway` value, WCML gateway country/currency rules and FunnelKit gateway lists (each recorded in the `Journal`);
  3. reschedule cron hooks to the new names (old events kept until Finalize, and their callbacks stay registered as aliases);
  4. bulk-set `payment_method=supcheckout` on copied orders, journaling the before value;
  5. transition to `cut_over`;
  6. if consented, deactivate the official plugin with `deactivate_plugins()`.

- [ ] **Step 1: Write the failing test.** The WCML gateway restriction stored under `upayments` ends up under `supcheckout` with the same countries, and the journal holds the before-fingerprint.
  - Do the third-party option paths first, on a real WCML/FunnelKit install in the Task 12 matrix.
  - Until a path is evidenced there, the translator only **reports** it and never writes it.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): settings and third-party translation, cutover`

### Task 8: `LegacyCallbackListener` (permanent)

**Files:**
- Create: `src/Takeover/LegacyCallbackListener.php`
- Modify: `src/Payment/PaymentLifecycle.php`. Register `woocommerce_api_supcheckout` and keep `woocommerce_api_wc_upayments`, both into the same `handle_callback`.
- Test: `tests/harness/provider-payment-lifecycle-harness.php`, new scenario T1.

**Interfaces:**
- Produces: both routes, served by the same verified handler. New Charges build URLs with `Keys::CALLBACK_ROUTE` only after cutover.

- [ ] **Step 1: Write the failing lifecycle scenario T1** (Review Focus 3).
  - A pre-switch order (legacy keys only, `payment_method=upayments`) receives a webhook on `wc_upayments` after `cut_over`.
  - The provider CAPTURED binds to the order, and the order completes once.
  - The payment ID is written under the new key, with the legacy key unchanged.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): permanent legacy callback route`

### Task 9: WP-CLI interface

**Files:**
- Create: `src/Takeover/Cli.php`, registered as `wp supcheckout takeover`.
- Test: `tests/integration/TakeoverCliTest.php` (real WordPress, CI).

**Interfaces:**
- Produces:
  - `preflight [--format=json]`;
  - `run [--yes] [--deactivate-official]`;
  - `status`;
  - `rollback [--yes]`;
  - `finalize [--yes] [--force-after-review]`.
- Exit codes: `0` ok, `2` blocked by preflight, `3` verification mismatch.

- [ ] **Step 1: Write the failing integration test.** It seeds the official 3.1.1 data shape with two orders and the settings option, runs `preflight`, then `run --yes`, and asserts state `cut_over`, the new keys present and the legacy keys intact.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): wp supcheckout takeover CLI`

### Task 10: `Rollback`

**Files:**
- Create: `src/Takeover/Rollback.php`
- Test: `tests/unit/Takeover/RollbackTest.php`

**Interfaces:**
- Produces: `Rollback::run(bool $reactivate_official): array{ok:bool, restored:int}`.
  - It replays the `Journal` in reverse: settings from the intact legacy option, third-party values, gateway order, `payment_method`, and cron.
  - It transitions to `rolled_back` and, if consented, reactivates the official plugin.

- [ ] **Step 1: Write the failing test** (Review Focus 5).
  - After cutover, an order paid through SUPCheckout (new keys, `payment_method=supcheckout`, status `processing`) gets `payment_method=upayments` back on rollback.
  - It stays `processing`, and its payment ID stays readable through `Keys::read_order_meta()`. Because the payment was written under the new key only, `Rollback` **also copies new → legacy** for keys written after cutover.
  - The test asserts the legacy `UPayments_PaymentID` equals the new value.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): journal-driven rollback`

### Task 11: `Finalize`

**Files:**
- Create: `src/Takeover/Finalize.php`
- Test: `tests/unit/Takeover/FinalizeTest.php`

**Interfaces:**
- Produces: `Finalize::run(bool $force_after_review): array{ok:bool, removed:array}`. It is allowed only from `cut_over` after 30 days, or with the force flag. It removes:
  - legacy order meta copies created **by the official plugin or SUPCheckout**, only where the new key verifies equal;
  - SUPCheckout-owned legacy options;
  - the official plugin's cron events and the `upayments_cron_lock` transient;
  - the PUC option and cron.

  It **never** removes the legacy callback route, other plugins' data, or the 3.0.0 "Checkout (Block)" page; the page is reported only, and the merchant decides.

- [ ] **Step 1: Write the failing test.**
  - Refused before 30 days.
  - After 30 days, with a verified-equal copy, the legacy key is gone. With an unequal copy it is kept, and the mismatch is reported.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): guarded finalize`

### Task 12: Real-WordPress migration matrix

**Files:**
- Create:
  - `.github/workflows/takeover-migration-matrix.yml`;
  - `tests/integration/TakeoverMatrixTest.php`;
  - `tests/integration/fixtures/official-plugin-seed.php`.
- Modify: `AGENTS.md`. Add the workflow to the permanent quality controls, and change the protected-identity list to "protected until migrated by the approved takeover, with the legacy callback route permanent".

**Matrix:**
- official plugin refs `V2.0.5`, `upayments-V2.2.1`, `3.0.0` and `3.1.1`, downloaded from `github.com/upaymentskwt/woocommerce` at that ref;
- HPOS on (sync off) and legacy;
- WCML on and off.

**Each cell:**
1. install and activate the official ref;
2. seed orders in every status, a 3.1.1 subscription parent and renewal, multi-valued `UPayments_WHS`, a pending order with an open link, and a WCML gateway restriction;
3. activate SUPCheckout (it pauses);
4. `wp supcheckout takeover preflight`, which must not report `blocking`;
5. `run --yes --deactivate-official`;
6. assert every order verifies, `payment_method=supcheckout`, the WCML restriction is under `supcheckout`, and a legacy-route webhook for the pending order pays it once;
7. interrupt-and-resume: kill the runner mid-batch, re-run, and assert equality;
8. `rollback --yes`, then assert every pre-switch value is restored byte-for-byte (journal-backed) and paid orders are still paid;
9. `run` again, set time +31 days, `finalize --yes`, and assert the legacy keys are removed only where verified.

**Plus a 10,000-order cell** (legacy storage, 3.1.1) that asserts the copy completes within the CI budget and no batch exceeds 50 orders.

- [ ] **Step 1: Write the workflow and the test.**
- [ ] **Step 2: Run it.** Expected: FAIL until Tasks 1–11 are merged on the branch.
- [ ] **Step 3: Fix what the matrix finds.** Each fix is its own test-first commit.
- [ ] **Step 4: Green on every cell.**
- [ ] **Step 5: Staging rehearsal** on the production copy (1,000+ real orders), owner-run.
  - Preflight report, run, verify counts, rollback, run again.
  - Evidence goes in `docs/project/evidence/TAKEOVER-REHEARSAL-<date>.md`.
- [ ] **Step 6: Commit.** `test(takeover): real-WordPress migration matrix across official 2.x/3.x`

---

## Deferred to 0c

- The branded wizard and onboarding screens, built on `Preflight::run()` codes, the `TakeoverState` statuses and the CLI-equivalent services.
- Health-screen surfacing of `official_debug_log_present` and the cache-exclusion warnings.
