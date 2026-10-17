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
  - **One exception: the customer-token MySQL `GET_LOCK()` names** use `sup_ctk_` and `sup_ctkboot_b`. `GET_LOCK()` names are limited to 64 characters, and `CustomerTokenIdentity::get_lock_name()` builds prefix + 32-hex scope + `_` + a user ID of up to 20 digits. That leaves at most 11 characters for the prefix, and `supcheckout_ctklock_` would need 73 in total. These names are never stored in a table.
- **Approved migration contract.** The owner approved renaming every protected identity (decision: "option 2, fully rename") on the condition of a non-destructive, verified, reversible switch. The spec §5, this plan and the Task 12 matrix are that contract. AGENTS.md is updated in Task 12, not before, so the protected list keeps guarding the code until the matrix is green.
- **Copy, never move.** No legacy option, meta, cron event or third-party value is deleted by the takeover's own operations before `Finalize`, and `Finalize` is refused until 30 days after `Cutover` unless the owner passes `--force-after-review`. Runtime writes and deletes follow the Task 2 **window model** instead (amended after the review of `401be1d`): a runtime delete inside the window removes both names, so a deleted marker can never come back through a copy.
- **Records:**
  - every write the takeover makes to a value it did not create records `{target, path, before_fingerprint, after_fingerprint, restore_source}` in the takeover journal. That is the option `supcheckout_takeover_journal_v1` plus `_1`, `_2`, … chunk options of at most 250 entries each, append-only and non-autoloaded;
  - `Rollback` replays that journal in reverse, but only entries whose path still holds the value the takeover wrote (`Journal::is_current_after()`). A later legitimate edit by the merchant or another plugin is reported as a conflict and kept;
  - the journal never contains API keys, tokens, card data or customer PII. `Journal::record()` takes the before and after **values** and stores only their SHA-256 fingerprints, plus the restore source (the legacy option is kept intact for exactly this reason).
- **Batches:** at most 50 orders per Action Scheduler action, idempotent, resumable from a persisted cursor. A failure mid-batch never leaves an order half-copied: each order's copy is one `save()`.
- **Payment authority:** paid state still comes only from authenticated UPayments status. The takeover never marks, unmarks or re-verifies payments.

**Out of scope**
- **Saved cards.** The cards themselves stay at UPayments and are never copied. They live under four phone-derived token formulas (inventory hazard 2), and SUPCheckout keeps re-deriving and re-verifying them live, exactly as today. SUPCheckout's own **H12 ownership provenance** for those cards *is* migrated: the `_upay_customer_token_v2_b{blog}_{scope}` user meta and the token-identity secret (Tasks 1, 6 and 7, plus the pre-0b SUPCheckout lane in Task 12). Otherwise upgraded SUPCheckout customers would fail closed on saved-card access.
- **Constant collision with the official plugin (0a Task 8 finding).** `Plugin::load_runtime()` defines `UP_PLUGIN_URL`, `UP_PLUGIN_PATH` and `UPAYMENTS_PLUGIN_FILE`, and the official plugin defines the same three constants (official 3.1.1 `UPayments.php` lines 21-23). Today this is unreachable: SUPCheckout pauses while the official plugin is active, and Cutover deactivates it. Any 0b path that runs both runtimes in one request would hit a PHP 8 "already defined" warning, and the second plugin would get the first plugin's asset URLs. 0b Task 2 therefore renames them to `SUPCHECKOUT_PLUGIN_URL` / `SUPCHECKOUT_PLUGIN_PATH`. Every internal use (Gateway icons and the `templates/*-form.php` images) moves too. SUPCheckout stops defining `UPAYMENTS_PLUGIN_FILE` (the existing `SUPCHECKOUT_PLUGIN_FILE` replaces its internal uses) and never defines the `UP_*` names after that. The coexistence harness's `both_running_*` characterization flips to "no shared constants". The Task 12 matrix confirms all three against real 2.x and 3.x sources.
- **Historical `payment_method` is never rewritten.** Orders created before cutover keep `payment_method=upayments` forever (inventory hazard), so reports, integrations and custom queries that select on it keep working. Runtime code recognises both identities through `Keys::is_our_gateway(string $id): bool` (true for `upayments` and `supcheckout`). Only orders created after cutover carry `supcheckout`.
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

### Task 1: `Keys` registry, complete and machine-checked

The identity audit (2026-10-16) checked the working tree against the inventory. This registry encodes its result, so nothing here is "fill in later". Task 1 also adds a coverage harness, which makes a forgotten name fail CI instead of losing data.

**Files:**
- Create: `src/Takeover/Keys.php`
- Create: `src/Takeover/TakeoverState.php`, as a stub whose `is_cut_over()` returns `false`. Task 3 replaces it.
- Create: `tests/harness/takeover-identity-coverage-harness.php`, wired into `quality-gates.yml` after `official-plugin-coexistence-harness.php`
- Test: `tests/unit/Takeover/KeysTest.php`

**Interfaces:**
- **Constants:** `Keys::GATEWAY_LEGACY = 'upayments'`, `Keys::GATEWAY = 'supcheckout'`, `Keys::CALLBACK_ROUTE_LEGACY = 'wc_upayments'`, `Keys::CALLBACK_ROUTE = 'supcheckout'`, `Keys::EXTENSION_NAMESPACE_LEGACY = 'upayments'`, `Keys::EXTENSION_NAMESPACE = 'supcheckout'` (the Store API checkout `extensions` namespace).
- **Gateway IDs:** `Keys::gateway_ids(): list<string>` returns `['upayments', 'supcheckout']`, for `wc_get_orders(array('payment_method' => Keys::gateway_ids()))`.
- **Copied maps.** Each returns `array<string,string>`, legacy → new, and every legacy name appears exactly once across all maps:
  - `Keys::order_meta()`;
  - `Keys::user_meta()`;
  - `Keys::user_meta_prefixes()`, mapping prefixes;
  - `Keys::product_meta()`;
  - `Keys::options()`;
  - `Keys::cron_hooks()`;
  - `Keys::tables()`, without `$wpdb->prefix`.
- **Settings sources:** `Keys::settings_sources(): list<string>`. These are the legacy settings options in precedence order, first non-empty wins, and the target is `woocommerce_supcheckout_settings`. They are kept out of `options()` because several sources feed one target (inventory hazard 3).
- **Ephemeral prefixes:** `Keys::ephemeral_prefixes(): array<string,string>`. Locks, rate gates, caches and MySQL `GET_LOCK` names. They are **never copied**; the new name is recreated on demand.
  - They are ordered longest match first: no legacy prefix is a prefix of a later one.
  - The customer-token bootstrap lock `upay_ctk_bootstrap_b{blog}` is its own family. It maps to `sup_ctkboot_b`, and `upay_ctk_` maps to `sup_ctk_` (see the `GET_LOCK` exception in Global Constraints).
- **Official-only names:** `Keys::official_only(): array{options, option_prefixes, transients, cron_hooks, cron_hook_prefixes, tables}`, each a `list<string>`. Only the official plugin writes these, except the `upay_subscriptions` table, whose only SUPCheckout writer is the dead `Subscription\Manager` listener. Nothing copies them, and Preflight reports them. Finalize clears the options, transients and cron hooks, but **only reports the tables and never drops them**.
  - The update-checker names are version-agnostic prefixes: `external_updates-upayments` and `puc_cron_check_updates-upayments`.
  - The phantom `upay_subscription_settings` option, referenced by the official 3.1.1 uninstall, is listed too.
- **Unchanged persisted names:** `Keys::unchanged_persisted(): array<string,string>`, mapping name → reason. These are persisted names that deliberately keep their name:
  - `_custom_field_id`, the subscription product meta;
  - the `custom_type` product-type term;
  - the translated `Special Feature` order-item meta key. Historical items keep whatever key string was stored;
  - WooCommerce's `chosen_payment_method` session value and the order `payment_method`. Both may hold `upayments` and are matched with `gateway_ids()` / `is_our_gateway()`.
- **Lookups:**
  - `Keys::legacy_for(string $new): ?string` and `Keys::meta_names(string $legacy): array{0:string,1:string}` are prefix-aware. They resolve the exact maps first, then any full key under a `user_meta_prefixes()` prefix, so `_upay_customer_token_v2_b1_{scope}` ↔ `_supcheckout_customer_token_v2_b1_{scope}`. A bare prefix maps to the other prefix. `meta_names()` returns `[new, legacy]` for `meta_query` OR clauses and hook key checks, and throws on an unclassified name;
  - `Keys::user_meta_key_pair(string $key): ?array{0:string,1:string}`: `[new, legacy]` for a user meta key given in **either** its legacy or its new full form. It returns null for a bare prefix or a non-user-meta name. `user_meta_prefixes()` is the legacy-prefix → new-prefix pair map;
  - `Keys::read_order_meta(\WC_Order $order, string $legacy)`: the new value, else the legacy value, else `''`;
  - `Keys::write_key(string $legacy): string`: new after cutover, else legacy;
  - `Keys::table(string $legacy): string`: the prefixed table in use right now. It throws on an unregistered table;
  - `Keys::is_our_gateway(string $id): bool`: true for `upayments` and `supcheckout`. Every order-ownership comparison uses it, because historical orders are never rewritten.

- [ ] **Step 1: Write the failing test.**

```php
<?php
namespace Simplixi\SUPCheckout\Tests\Takeover;

use PHPUnit\Framework\TestCase;
use Simplixi\SUPCheckout\Takeover\Keys;

final class KeysTest extends TestCase {
    /** @return list<array<string,string>> */
    private static function copied_maps(): array {
        return array(Keys::order_meta(), Keys::user_meta(), Keys::user_meta_prefixes(), Keys::product_meta(),
            Keys::options(), Keys::cron_hooks(), Keys::tables());
    }

    public function test_every_copied_mapping_is_unique_prefixed_and_reversible(): void {
        $all_legacy = array();
        $all_new = array();
        foreach (self::copied_maps() as $map) {
            foreach ($map as $legacy => $new) {
                self::assertMatchesRegularExpression('/^_?supcheckout_/', $new, $new);
                self::assertSame($legacy, Keys::legacy_for($new));
                $all_legacy[] = $legacy;
                $all_new[] = $new;
            }
        }
        self::assertSame(count($all_legacy), count(array_unique($all_legacy)), 'a legacy name is in one map only');
        self::assertSame(count($all_new), count(array_unique($all_new)), 'no two legacy names share a target');
    }

    public function test_audited_names_are_in_the_right_map(): void {
        foreach (array('UPayments_order_id', 'UPayments_PaymentID', 'UPayments_WHS', 'UPayments_TranID',
            '_upay_verified_capture', '_simplixpay_upayments_provider_result_v1',
            '_simplixpay_upayments_reconcile_exhausted_v1', '_simplixpay_upayments_callback_lookups_v1',
            '_simplixpay_upayments_unapplied_captures_v1', '_simplixpay_upayments_secondary_noted_v1') as $legacy) {
            self::assertArrayHasKey($legacy, Keys::order_meta(), $legacy);
        }
        // Written with update_user_meta (MigrationExecutor.php, MigrationBatch.php): user meta, not options.
        self::assertArrayHasKey('simplixpay_upayments_migration_v1', Keys::user_meta());
        self::assertArrayNotHasKey('simplixpay_upayments_migration_v1', Keys::options());
        self::assertArrayHasKey('_simplixpay_upayments_migration_result_v1', Keys::user_meta());
        self::assertArrayHasKey('_upay_customer_token_v2_b', Keys::user_meta_prefixes());
        self::assertArrayHasKey('_upay_disable_subscription', Keys::product_meta());
        self::assertSame('supcheckout_billing_attempts', Keys::tables()['upayments_billing_attempts']);
        self::assertSame(array('woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings'), Keys::settings_sources());
        self::assertArrayNotHasKey('woocommerce_upayments_settings', Keys::options());
    }

    public function test_ephemeral_and_already_canonical_names_are_never_copied(): void {
        $copied = array_merge(...array_map('array_keys', self::copied_maps()));
        foreach (array_keys(Keys::ephemeral_prefixes()) as $prefix) {
            foreach ($copied as $legacy) {
                self::assertStringStartsNotWith($prefix, $legacy, "$legacy is ephemeral");
            }
        }
        foreach (array('_supcheckout_reconcile_schedule_failed_v1', 'supcheckout_reconciliation_sweep',
            'supcheckout_process_due_parent') as $canonical) {
            self::assertNull(Keys::legacy_for($canonical), $canonical);
            self::assertNotContains($canonical, $copied);
        }
    }

    public function test_no_new_prefix_shadows_another_family(): void {
        // A LIKE 'x%' cleanup on one prefix must never match another family (audit: supcheckout_pm_* overlap).
        $prefixes = array_merge(array_values(Keys::ephemeral_prefixes()), array('supcheckout_pm_lkg_v1_'));
        foreach ($prefixes as $a) {
            foreach ($prefixes as $b) {
                if ($a !== $b) {
                    self::assertStringStartsNotWith($a, $b, "$a shadows $b");
                }
            }
        }
    }
}
```

- [ ] **Step 2: Run it.**
  - Run: `vendor/bin/phpunit --filter KeysTest`
  - Expected: FAIL, with the class not found.
- [ ] **Step 3: Implement `Keys`.** Every row below is evidence-backed by the audit or the inventory. Write them all.
  - **Amended after the review of `02bf9fc`.** The block below is the original Task 1 registry, and `src/Takeover/Keys.php` is authoritative. The review added:
    - `gateway_ids()` and the extension-namespace constants;
    - `user_meta_key_pair()`, prefix-aware `legacy_for()` / `meta_names()` and a guarded `table()`;
    - `unchanged_persisted()` and the widened `official_only()` shape;
    - the bootstrap lock family and the `sup_ctk_` lock prefixes.
  - `tests/unit/Takeover/KeysTest.php` pins each of these.

```php
<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * The single registry of legacy → SUPCheckout persisted identity names.
 * Evidence: docs/superpowers/specs/2026-10-16-official-plugin-data-inventory.md and the
 * 2026-10-16 identity audit. tests/harness/takeover-identity-coverage-harness.php fails
 * when shipped code uses a legacy-looking name that is not classified here.
 */
final class Keys {
    public const GATEWAY_LEGACY = 'upayments';
    public const GATEWAY = 'supcheckout';
    public const CALLBACK_ROUTE_LEGACY = 'wc_upayments';
    public const CALLBACK_ROUTE = 'supcheckout';

    /** @return array<string,string> */
    public static function order_meta() {
        return array(
            // Official 2.x/3.x provider fields (UPayments_TranID/Auth/reference: official plugin only).
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
            // Tokens and subscriptions.
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
            // Lifecycle (PaymentLifecycle.php constants).
            '_simplixpay_upayments_status_track_v1' => '_supcheckout_status_track_v1',
            '_simplixpay_upayments_status_requested_v1' => '_supcheckout_status_requested_v1',
            '_simplixpay_upayments_unverified_track_v1' => '_supcheckout_unverified_track_v1',
            '_simplixpay_upayments_unverified_requested_v1' => '_supcheckout_unverified_requested_v1',
            '_simplixpay_upayments_prior_requested_v1' => '_supcheckout_prior_requested_v1',
            '_simplixpay_upayments_provider_result_v1' => '_supcheckout_provider_result_v1',
            '_simplixpay_upayments_reconcile_attempt_v1' => '_supcheckout_reconcile_attempt_v1',
            '_simplixpay_upayments_reconcile_reason_v1' => '_supcheckout_reconcile_reason_v1',
            '_simplixpay_upayments_reconcile_exhausted_v1' => '_supcheckout_reconcile_exhausted_v1',
            '_simplixpay_upayments_callback_lookups_v1' => '_supcheckout_callback_lookups_v1',
            '_simplixpay_upayments_capture_apply_failed_v1' => '_supcheckout_capture_apply_failed_v1',
            '_simplixpay_upayments_unapplied_captures_v1' => '_supcheckout_unapplied_captures_v1',
            '_simplixpay_upayments_secondary_pending_v1' => '_supcheckout_secondary_pending_v1',
            '_simplixpay_upayments_secondary_attempt_v1' => '_supcheckout_secondary_attempt_v1',
            '_simplixpay_upayments_secondary_noted_v1' => '_supcheckout_secondary_noted_v1',
        );
    }

    /** @return array<string,string> */
    public static function user_meta() {
        return array(
            'simplixpay_upayments_migration_v1' => 'supcheckout_migration_v1',
            '_simplixpay_upayments_migration_result_v1' => '_supcheckout_migration_result_v1',
        );
    }

    /** @return array<string,string> H12 provenance: _upay_customer_token_v2_b{blog}_{scope}. */
    public static function user_meta_prefixes() {
        return array('_upay_customer_token_v2_b' => '_supcheckout_customer_token_v2_b');
    }

    /** @return array<string,string> */
    public static function product_meta() {
        return array('_upay_disable_subscription' => '_supcheckout_disable_subscription');
    }

    /** @return array<string,string> */
    public static function options() {
        return array(
            'upayments_token_identity_secret_v2' => 'supcheckout_token_identity_secret_v2',
            'upay_billing_cycle_schema_version' => 'supcheckout_billing_cycle_schema_version',
            'upay_subscription_enrollment_cursor' => 'supcheckout_subscription_enrollment_cursor',
        );
    }

    /** @return list<string> First non-empty wins; target is woocommerce_supcheckout_settings. */
    public static function settings_sources() {
        return array('woocommerce_upayments_settings', 'woocommerce_your_gateway_id_settings');
    }

    /** @return array<string,string> */
    public static function cron_hooks() {
        return array(
            'upay_process_subscriptions' => 'supcheckout_process_subscriptions',
            'simplixpay_upayments_reconcile_order' => 'supcheckout_reconcile_order',
            'simplixpay_upayments_reconcile_secondary' => 'supcheckout_reconcile_secondary',
        );
    }

    /** @return array<string,string> Table names without $wpdb->prefix. */
    public static function tables() {
        return array('upayments_billing_attempts' => 'supcheckout_billing_attempts');
    }

    /**
     * Recreated, never copied. During the switch window lock helpers take BOTH names
     * (legacy first) so a pre-cutover and a post-cutover worker still contend (Task 2).
     *
     * @return array<string,string>
     */
    public static function ephemeral_prefixes() {
        return array(
            'simplixpay_upay_order_lock_v1_' => 'supcheckout_order_lock_v1_',
            'simplixpay_upay_status_v1_' => 'supcheckout_status_gate_v1_',
            'upayments_payment_methods_rate_gate_' => 'supcheckout_pmgate_v1_',
            'upay_pm_v3_' => 'supcheckout_pmcache_v3_',
            'upay_pm_' => 'supcheckout_pmlock_',
            'upay_ctk_bootstrap_b' => 'sup_ctkboot_b', // GET_LOCK: 64-char limit (Global Constraints).
            'upay_ctk_' => 'sup_ctk_',
        );
    }

    /** @return array{options:list<string>,option_prefixes:list<string>,transients:list<string>,cron_hooks:list<string>,cron_hook_prefixes:list<string>,tables:list<string>} */
    public static function official_only() {
        return array(
            'options' => array('your_gateway_version', 'upayments_block_checkout_page_id',
                'woocommerce_upayments_enable_block_checkout', 'upayments_maat', 'upay_subscription_settings'),
            'option_prefixes' => array('external_updates-upayments'),
            'transients' => array('upayments_cron_lock'),
            'cron_hooks' => array('upay_hourly_cron_job'),
            'cron_hook_prefixes' => array('puc_cron_check_updates-upayments'),
            'tables' => array('upay_subscriptions'), // Reported, never dropped.
        );
    }

    /** @return list<array<string,string>> */
    private static function copied_maps() {
        return array(self::order_meta(), self::user_meta(), self::user_meta_prefixes(), self::product_meta(),
            self::options(), self::cron_hooks(), self::tables());
    }

    /**
     * @param string $new_key SUPCheckout name.
     * @return string|null Legacy name.
     */
    public static function legacy_for($new_key) {
        foreach (self::copied_maps() as $map) {
            $legacy = array_search($new_key, $map, true);
            if ($legacy !== false) {
                return (string) $legacy;
            }
        }
        return null;
    }

    /**
     * @param string $legacy Legacy meta key.
     * @return array{0:string,1:string} [new, legacy].
     */
    public static function meta_names($legacy) {
        foreach (self::copied_maps() as $map) {
            if (isset($map[$legacy])) {
                return array($map[$legacy], $legacy);
            }
        }
        throw new \InvalidArgumentException('Unclassified persisted name: ' . $legacy);
    }

    /**
     * @param \WC_Order $order  Order.
     * @param string    $legacy Legacy meta key.
     * @return mixed New value, else the legacy value, else ''.
     */
    public static function read_order_meta($order, $legacy) {
        list($new) = self::meta_names($legacy);
        $value = $order->get_meta($new);
        return ($value !== '' && $value !== null) ? $value : $order->get_meta($legacy);
    }

    /**
     * @param string $legacy Legacy name.
     * @return string The name writes go to right now.
     */
    public static function write_key($legacy) {
        return TakeoverState::is_cut_over() ? self::meta_names($legacy)[0] : $legacy;
    }

    /**
     * @param string $legacy Legacy table name without prefix.
     * @return string Prefixed table name in use right now.
     */
    public static function table($legacy) {
        global $wpdb;
        $tables = self::tables();
        if (!isset($tables[$legacy])) {
            throw new \InvalidArgumentException('Unregistered table: ' . esc_html((string) $legacy));
        }
        return $wpdb->prefix . (TakeoverState::is_cut_over() ? $tables[$legacy] : $legacy);
    }

    /**
     * Historical orders keep payment_method 'upayments' forever; new ones get 'supcheckout'.
     *
     * @param string $id An order's payment_method or a gateway id.
     * @return bool True for either SUPCheckout identity.
     */
    public static function is_our_gateway($id) {
        return in_array($id, array(self::GATEWAY_LEGACY, self::GATEWAY), true);
    }

    private function __construct() {}
}
```

- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Write the coverage harness.** `tests/harness/takeover-identity-coverage-harness.php`:
  - Tokenize (`token_get_all`) every PHP file in `UPayments.php`/`supcheckout.php`, `src/`, `includes/` and `templates/`.
  - Collect every `T_CONSTANT_ENCAPSED_STRING` whose unquoted value matches `/^(_?upay_|_?simplixpay_|UPayments_|upayments_|woocommerce_upayments|woocommerce_your_gateway)/`.
  - Each value must be one of:
    - a key in a copied map;
    - a value starting with a `user_meta_prefixes()` or `ephemeral_prefixes()` key;
    - in `settings_sources()` or `official_only()`;
    - in the harness's own `$not_persisted` allowlist, where every entry carries a one-line reason (script handles, CSS classes, action names).
  - Print every unclassified value with `file:line`, and exit 1 if any exist.
  - Run it on the current tree and classify whatever it reports. **This replaces the earlier `_META = '` grep, which cannot see inline literals.**
  - **Amended after the review of `02bf9fc`.** The harness has two rules.
    - **Rule 1** (the legacy families above) has three tightenings:
      - a user-meta or ephemeral prefix classifies a literal only when the literal **equals** the prefix, because code builds the full name, so `upay_pm_foo` is a new name;
      - the allowlist is **file-keyed with exact occurrence counts**, so there is no global entry and no bare `upay_`;
      - the leading segment of interpolated strings and heredocs is checked too.
    - **Rule 2** inventories every gateway-identity site in shipped PHP (strings and inline HTML) and `assets/js/*.js`: `upayments`, `wc_upayments`, `woocommerce_api_wc_upayments`, `woocommerce_upayments*` and `*_upayments`. The inventory lists one category per occurrence per file and token, and each category names its Task 2 action. A new site, a disappeared site or an unknown category fails.
    - **Rule 3** (added in the PR #173 review) inventories order-ownership reads that rule 2 cannot see because they compare variables:
      - `->get_payment_method()` calls;
      - `->payment_method` and `['payment_method']` reads;
      - `get_meta()` / `get_post_meta()` of `payment_method` / `_payment_method`;
      - `$gateway_id` / `->id` operands of `===`, `!==`, `==`, `!=`, `in_array()` and `array_search()`.

      The inventory is file-keyed and count-exact, with categories `ownership_compare_task2`, `captured_for_check`, `current_id_compare`, `display_only` and `third_party_gateway`. Reads inside `Keys::is_our_gateway(...)`, or inside a call whose own arguments include `Keys::gateway_ids()`, are exempt.
    - Self-tests cover the failing exit path (a child run over a fixture tree), stale and over-count allowlist entries, prefix exactness, new and missing gateway sites, and rule 3's detection and exemptions.
- [ ] **Step 6: Commit.** `feat(takeover): audited identity registry and coverage harness`

### Task 2: Route every runtime read and write through `Keys`

**Files.** Modify every class the audit found touching a persisted name:
- **Payment and provider:** `PaymentLifecycle`, `CheckoutOrchestrator`, `OrderPresentation`, `StatusVerifier`, `OrderLock`, `StatusRateGate`, `PaymentMethodAvailability`, `Gateway/Availability`.
- **Tokens:** `CustomerTokenIdentity`, `RenewalCardAuthority`.
- **Subscriptions:** `CycleEconomics`, `LifecycleScheduler`, `Scheduler`, `CycleClaim`, `HistoricalEnrollment`, `Subscription/Helpers/Utils`, `Subscription/Checkout/Fields`, `Subscription/Presentation`.
- **Everything else:** `PublicOrderStatus`, `Gateway`, `Blocks/PaymentMethodType`, `Migration*`.

**Tests:** the existing suites plus `tests/unit/Takeover/DualReadTest.php`.

**Interfaces:**
- **Consumes:** everything `Keys` produces.
- **Amended after the review of `401be1d`/`c3c73ee` (window model; supersedes the new-first read and write-key rules originally written here):**
  - **Reads:** the legacy name only until `TakeoverState::is_cut_over()` (every write still lands there, so a copy can never shadow it), then the new name first with legacy fallback. Order meta goes through `Keys::read_order_meta()`, user and product meta through `Keys::read_user_meta()` / `read_post_meta()`, copied options through `Keys::get_option()`.
  - **Writes and deletes** go through `Keys::write_keys()` / `Keys::delete_names()` (`update_order_meta()`, `add_order_meta()`, `delete_order_meta()`, `update_copied_option()`, `add_copied_option()`, `delete_copied_option()`), by status: `idle`, `preflight_ok`, `rolled_back` → the legacy name only; `copying`, `verified`, `cut_over` and an unreadable (`invalid`) record → **both** names; `finalized` → the new name only. A value written in `copying` after an order was copied, or a marker deleted in `verified`, is therefore correct after cutover with no final delta pass. Stores that never switch see identical behavior.
  - **The billing-attempts table is not dual-written.** Its claims are compare-and-swap SQL that cannot be mirrored into a second table atomically. `Keys::table()` is the legacy table until cutover and the new table after; the cutover (Task 7) re-syncs it under a quiesce, and Rollback (Task 10) back-syncs it.
  - **Queries:** before cutover a `meta_query` clause is unchanged; after cutover it becomes `OR(new …, AND(legacy …, new NOT EXISTS))`, so a stale legacy copy never matches. Order queries use `Keys::payment_method_query()`: the scalar `'upayments'` before cutover, `Keys::gateway_ids()` after.
  - **Hook checks** accept either name: `Keys::is_meta_key($meta_key, $legacy)`.
  - **Gateway ownership:** `Keys::is_our_gateway()` is `'upayments'` only until cutover (no SUPCheckout order or session can hold `supcheckout` yet, so a request-controlled value is rejected as before), both ids after. `Keys::find_gateway()` / `find_gateway_id()` return exactly today's `'upayments'` registration before cutover; after cutover only an instance of `Simplixi\SUPCheckout\Gateway\Gateway`, never the official plugin's object.
- **Locks and gates** (ephemeral prefixes):
  - from `copying` until `finalized`, acquire the legacy name first, then the new one, and release both (all or nothing);
  - before `copying`, legacy only;
  - after `finalized`, new only.

  So a request that started before cutover and one that started after still contend for the same lock. **Exception (amended): MySQL `GET_LOCK()` names** (`upay_pm_`, `upay_ctk_`, `upay_ctk_bootstrap_b`) use `Keys::advisory_lock_names()`: the legacy name until `finalized`, the new name after, never both. Before MySQL 5.7.5 a connection holds one named lock and a second `GET_LOCK()` silently releases the first, and WordPress 6.9 still accepts MySQL 5.5.5. The Phase 9I migration executor takes these names through `CustomerTokenIdentity::acquire_lock()` / `release_lock()`.
- **Per-request cost:** one extra read of the takeover state option per request (`is_cut_over()` is cached; status reads for writes and locks hit the option cache). The identity maps are memoized.
- **Last-known-good name:** `PaymentMethodAvailability::last_known_good_name()` is recomputed from the fingerprint inputs instead of slicing `transient_name()`, so renaming the transient prefix cannot orphan the stored value.
- **Gateway-identity sites** (added after the review of `02bf9fc`). The coverage harness's rule-2 inventory (`$gateway_sites` in `tests/harness/takeover-identity-coverage-harness.php`) lists every one by file and category. Task 2 retargets each site and updates the inventory in the same commit. Line numbers are as of `02bf9fc`.
  - **Order queries** (`'payment_method' => 'upayments'`) use `Keys::gateway_ids()`: `MigrationPreflight.php:197,373`, `ReconciliationSweep.php:51`, `CustomerTokenIdentity.php:1179,1488`, `Scheduler.php:887`, `HistoricalEnrollment.php:49`.
  - **Gateway lookups** (`$gateways['upayments']`) use the registered gateway object under its current id: `PaymentLifecycle.php:1449`, `PaymentMethodType.php:67-68`, `Scheduler.php:1317`, `Fields.php:204`, `Availability.php:30-37`, `Gateway.php:226,230`.
  - **Ownership and selection comparisons** use `Keys::is_our_gateway()`:
    - `SubscriptionActions.php:66`;
    - `PaymentLifecycle.php:539,997`;
    - `PublicOrderStatus.php:151`;
    - `Scheduler.php:1096,1237`;
    - `HistoricalEnrollment.php:156`;
    - `Fields.php:168`;
    - the icon filter at `Gateway.php:668`;
    - `OrderPresentation::add_order_item_totals()` (`OrderPresentation.php:23`). It compares the order's method with the passed `$gateway_id` and must use `is_our_gateway()`, so historical `upayments` orders keep their payment rows after cutover;
    - `assets/js/new-upay.js:11`, which compares the checked radio with the localized current id.
  - **Renewal identity:** `Scheduler.php:982` (`set_payment_method('upayments')` on a new renewal) writes the current gateway id.
  - **Session choice:** `Availability.php:54` and `Gateway.php:232` compare the WooCommerce session `chosen_payment_method` with `Keys::is_our_gateway()`. A session started before cutover holds `upayments` for up to 48 h and must still select our gateway.
  - **Default gateway:** `Gateway.php:251` returns the current id.
  - **Extension namespace:** `CheckoutOrchestrator.php:315-318,407-413` reads `extensions[Keys::EXTENSION_NAMESPACE]`, then `extensions[Keys::EXTENSION_NAMESPACE_LEGACY]`. An already-open Blocks checkout posts under the old namespace. If both are present, the current namespace wins. **Amended:** before cutover only `extensions[Keys::EXTENSION_NAMESPACE_LEGACY]` is read (a posted `supcheckout` key, including `supcheckout: null`, is ignored); the two-namespace order applies after cutover. `assets/js/upayments-block.js:36` posts the namespace localized by `PaymentMethodType`, and `:10` / `:468` (`getPaymentMethodData` / `registerPaymentMethod` name) use the localized current id.
  - **Settings-API names derived from the gateway id** come from the current id:
    - POST fields `woocommerce_upayments_*` (`GatewaySettings.php`);
    - the hook `woocommerce_settings_api_sanitized_fields_upayments` (`Gateway.php:237`);
    - the admin selectors `#woocommerce_upayments_*` (`assets/js/admin-settings.js:4-6`);
    - the class `payment_method_upayments` (`assets/js/new-upay.js:10`).
  - **Settings option reads** of `woocommerce_upayments_settings` (`PaymentMethodType.php:32`, `Availability.php:35`, `Gateway.php:152`, `MigrationSettings.php:14`, `Scheduler.php:69`) read the target first, then `Keys::settings_sources()`.
  - **Callback route:** `Gateway.php:528` (`api_request_url('wc_upayments')`) uses `Keys::CALLBACK_ROUTE` for new Charges. `PaymentLifecycle.php:20` (`woocommerce_api_wc_upayments`) stays registered permanently (Task 8).
  - **Never changed:**
    - the `Release\Identity` `LEGACY_*` record;
    - the provider hosts (`EndpointResolver.php:12-13`, `StatusVerifier.php:294`);
    - the `upayment_payment_type` sentinel value `upayments` (`templates/new-design-form.php:239` ↔ `CheckoutPayload.php:68`; it means "no white-label method chosen" and is not a gateway id);
    - the vestigial `Gateway::$domain`;
    - the logger source labels (optional, cosmetic).
- **Order-ownership reads through variables** (added in the PR #173 review). Rule 2 sees only literals, so the harness's rule 3 inventory (`$ownership_sites`) also lists every `->get_payment_method()`, `->payment_method`, `['payment_method']` and `get_meta('_payment_method')` read, and every `$gateway_id` / `->id` comparison operand.
  - Task 2 must convert **every `ownership_compare_task2` site** to `Keys::is_our_gateway()`, or to `in_array(..., Keys::gateway_ids(), true)`, and delete it from the inventory in the same commit. The harness exempts reads inside those expressions.
  - The sites are: `SubscriptionActions.php:66`, `Gateway.php:629` (`admin_order_details()` compares with `$this->id`), `Gateway.php:668`, `OrderPresentation.php:23` (compares with the passed `$gateway_id`), `PaymentLifecycle.php:539,997`, `PublicOrderStatus.php:151`, `Scheduler.php:1237` and `HistoricalEnrollment.php:156`.
  - The `captured_for_check` reads (`Scheduler.php:1079`, `Fields.php:165-166`) stay, and their check sites (`Scheduler.php:1096`, `Fields.php:168`) convert under rule 2.
  - `current_id_compare` (`GatewaySettings.php:395`, the settings-screen section) stays, provided its caller passes the current gateway id.
  - Test: after cutover, a historical `payment_method=upayments` order still renders its admin payment details (`admin_order_details()`) and its customer payment rows (`add_order_item_totals()`).
- **H12 provenance under two prefixes** (added after the review of `02bf9fc`). The legacy prefix `_upay_customer_token_v2_b` is 25 characters, and the new `_supcheckout_customer_token_v2_b` is 32. These H12 reads (the secret option, the exact provenance read, the presence probe, the scope scan and the Phase 9I preflight census) check **both** names in every state on purpose: a stale new-name row after a rollback, or a legacy row Finalize removed, always pushes the decision toward fail-closed (never mint; the census reports it). New provenance and a minted secret are written under the window names.
  - **Provenance-presence probe** (`CustomerTokenIdentity.php:219-238`). It decides whether a missing secret may be minted fresh. It must count rows under **both** `{prefix}{blog}_` patterns, and fail closed if either has rows. Otherwise, once Finalize deletes the legacy rows, a legacy-only probe finds nothing and mints a new secret, orphaning every saved-card provenance record.
  - **Scope scan** (`CustomerTokenIdentity.php:1632-1661`). It must select keys under both prefixes and slice each scope by the length of the prefix **that matched** (`Keys::user_meta_key_pair()`), never by one fixed `strlen($meta_prefix)`. When both forms of one scope exist, it treats them as one record and verifies that they agree.
  - **Exact key builder:** `get_user_meta_key()` (`:423`) builds the key through `Keys::write_key()`.

- [ ] **Step 1: Write the failing tests** in `DualReadTest.php`.
  - **Dual read.** An order with only legacy `UPayments_PaymentID=pay-1` shows `pay-1` in `OrderPresentation::add_order_item_totals()`. An order with new `pay-2` and legacy `pay-1` shows `pay-2`.
  - **Dual query.** `Subscription/Presentation`'s `_upay_subscription_status` filter (Presentation.php:401) and `PaymentLifecycle::sweep_cursor_meta_query()` each find both a legacy-only order and a new-only order.
  - **Dual hook.** `LifecycleScheduler` reacts to a status update under either key name.
  - **Lock contention across the window.** In state `copying`, worker A holds the order lock under the legacy name only, and worker B (dual-acquire) must fail to acquire.
  - **LKG stability.** With fixed inputs, `last_known_good_name()` is unchanged when the transient prefix changes.
  - **Historical orders after cutover.** A `payment_method=upayments` order is found by every order query, keeps its payment rows in `OrderPresentation`, and passes every ownership check. A stale session holding `chosen_payment_method=upayments` still selects our gateway.
  - **Both extension namespaces.** A Blocks checkout posting under `upayments` and one posting under `supcheckout` both parse the plan and interval.
  - **H12 after Finalize.** Provenance rows exist only under `_supcheckout_customer_token_v2_b…`, the legacy rows and legacy secret option are deleted, and the new secret option is present. Saved-card provenance still verifies. With the new secret option also absent, the presence probe fails closed and **no new secret is minted**. A mixed legacy/new scan slices every scope correctly.
- [ ] **Step 2: Run them.** Expected: FAIL.
- [ ] **Step 3: Replace reads, queries and hook checks** in each listed class.
- [ ] **Step 4: Replace writes and lock names.**
- [ ] **Step 5: Remove the dead listener.** **Deferred to Task 7 (accepted after the review of `401be1d`):** deleting it removes the recorded hook `upayments_payment_success` from the public-surface snapshot, which Task 7 re-records anyway. The `Keys::cron_hooks()` scheduling/registration moves to Task 7 for the same reason (the new-name listeners change the snapshot). `includes/Subscription/Manager.php` listens on `upayments_payment_success` and writes `{prefix}upay_subscriptions`. First confirm `grep -rn "upayments_payment_success" UPayments.php src includes templates assets` shows no `do_action`, then delete the file and its require. Preflight (Task 4) still reports whether an `upay_subscriptions` table exists, and leaves it alone.
- [ ] **Step 6: Run every suite.** H12, the lifecycle harness and the coverage harness must pass unchanged. The default state is not cut over, so every legacy write is identical.
- [ ] **Step 7: Commit.** `refactor(takeover): all persisted identities go through Keys`

### Task 3: `TakeoverState` and `Journal`

**Files:**
- Replace: the Task 1 stub `src/Takeover/TakeoverState.php`. Its `is_cut_over()` must stay a cheap single read of the state option, because `Keys::write_key()` calls it on every write.
- Create: `src/Takeover/Journal.php`
- Test: `tests/unit/Takeover/TakeoverStateTest.php`, `JournalTest.php`

**Interfaces:**
- Produces:
  - `TakeoverState::get(): array{status:string, cut_over_at:int, cursor:int, totals:array}`;
  - `TakeoverState::transition(string $to, bool $force = false): bool`, allowed transitions only;
  - `TakeoverState::is_cut_over(): bool`, one cached read per request (`TakeoverState::flush_cache()` resets it);
  - `TakeoverState::patch(array $fields, ?string $expected_status = null): bool`, progress fields (cursors, totals, mismatches) under the same compare-and-swap;
  - `Journal::record(string $target, string $path, mixed $before_value, mixed $after_value, string $restore_source): void`. It takes the **values** and stores only `before_fingerprint` and `after_fingerprint` (`sha256(serialize())`), so no caller can journal a raw secret;
  - `Journal::entries(): list<array{seq, recorded_at, target, path, before_fingerprint, after_fingerprint, restore_source}>`. Every entry and the chunk layout are validated, and a malformed journal throws before anything is returned;
  - `Journal::is_current_after(array $entry, mixed $current_value): bool`: true only while the current value is still the one the takeover wrote;
  - `Journal::fingerprint(mixed $value): string`.
- Storage: chunk options of at most 250 entries (`supcheckout_takeover_journal_v1`, then `_1`, `_2`, …), all non-autoloaded. Every change is an insert-if-absent or a bytewise compare-and-swap (`CAST(option_value AS BINARY) = CAST(%s AS BINARY)`, because `option_value`'s collation is case- and padding-insensitive).

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
- [ ] **Step 5: Add the `JournalTest`.** Assert that entries never contain the API key: record a settings change whose value contains `test-api-key-123`, and assert that string is absent from `json_encode(Journal::entries())`. Also assert that an entry's after-fingerprint differs from its before-fingerprint, that a value edited after the change fails `is_current_after()`, and that malformed chunks (`[{}]`, `[{"seq":99}]`, wrong types, a bad fingerprint, non-contiguous `seq`) throw. Implement until it passes.
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
| `settings_source` | fact | which of `Keys::settings_sources()` holds the settings (hazard 3: 3.0.0 may have only `woocommerce_your_gateway_id_settings`) |
| `official_only_names` | fact | which `Keys::official_only()` options, transients and cron hooks exist |
| `upay_subscriptions_table_present` | fact | `{prefix}upay_subscriptions` exists. It is reported, never touched (its only writer was the dead `Manager` listener). |
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
  - `payment_method` is never changed by the copier or by Cutover (see Global Constraints).

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
  - Read all rows per key with `$order->get_meta($legacy, false)`. On a real `WC_Order` this returns `WC_Meta_Data` objects, not values, so unwrap each with `$meta->value`. Never pass the object itself to `add_meta_data()`, because that would persist a serialized object instead of the payment identifier.
  - Add each unwrapped value under the new key with `add_meta_data($new, $value)`, and end with a single `$order->save()`.
  - `OrderCopierFixtures` must return `WC_Meta_Data`-shaped objects from `get_meta($key, false)`, exactly like WooCommerce. Add a test asserting the copied new-key value is the original scalar string (`'pay-1'`), not an object or a serialized blob.
  - Skip when the new key already holds an identical value list.
  - **Amended after the review of `401be1d` (window model):**
    - hold the per-order `OrderLock` while copying an order, so a runtime write and the copy never interleave;
    - be idempotent and never add a second row: check existence first or use a unique add (a second H12 `add_user_meta()` row makes provenance INVALID);
    - pass non-order meta through `wp_slash()`, because `add_metadata()` unslashes;
    - record what was copied (a per-order snapshot hash, as a meta marker or in the journal). The copier may overwrite **its own** prior copy when the legacy value changed, but never a foreign differing value: that is a mismatch to report.
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
  - The cursor is persisted after **each** order, so a crash re-processes at most one order, and `copy()` is idempotent (amended: under the per-order `OrderLock`, never adding a second row; see Task 5).
  - When fewer than 50 remain, transition `copying → verified` only if every verify returned `ok`. Otherwise go back to `preflight_ok` with the mismatches recorded in the state.
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Add the non-order phases.** They run after orders, in the same runner, each with its own cursor in the state (`cursors.users`, `cursors.products`, `cursors.table`).
  - **Users:** batches of users holding any `Keys::user_meta()` key or a `user_meta_prefixes()` key. Find them with one prepared `SELECT DISTINCT user_id … WHERE meta_key IN (…) OR meta_key LIKE %s` using `esc_like`. Copy every row to the new name.
    - H12 provenance stays valid because the token-identity secret is copied verbatim in Task 7.
    - Test: a copied `_upay_customer_token_v2_b1_<scope>` row verifies through `CustomerTokenIdentity` under the new prefix.
  - **Products:** batches of products holding `_upay_disable_subscription`, through `wc_get_products` with a `meta_query`, copied with CRUD.
  - **Billing-attempts table:**
    - Create `{prefix}supcheckout_billing_attempts` from the **same** schema definition `CycleClaim` uses (one `dbDelta` source, never a second copy of the DDL).
    - Copy rows in ID order with `INSERT IGNORE … SELECT … WHERE id > %d ORDER BY id LIMIT 500`.
    - Verify the row count and a per-row checksum (`MD5(CONCAT_WS('|', …))` over every column).
    - Record `max_copied_id` in the state for Rollback.
  - Test: an interrupted users phase resumes without duplicates, and the table phase verifies equal counts and checksums.
- [ ] **Step 6: Commit.** `feat(takeover): resumable Action Scheduler copy runner`

### Task 7: `SettingsCopier`, `ThirdPartyTranslator` and `Cutover`

**Files:**
- Create: `src/Takeover/SettingsCopier.php`, `src/Takeover/ThirdPartyTranslator.php`, `src/Takeover/Cutover.php`
- Modify: `src/Gateway/Gateway.php`. `id` becomes `Keys::GATEWAY` when `TakeoverState::is_cut_over()`, otherwise `Keys::GATEWAY_LEGACY`.
- Test: `tests/unit/Takeover/CutoverTest.php`

**Interfaces:**
- Produces: `Cutover::run(bool $deactivate_official): array{ok:bool, steps:list<string>}`. In order:
  0. **(amended after the review of `401be1d`) `verified → cut_over` runs under a quiesce:** pause the subscription scheduler and the `supcheckout` Action Scheduler group, hold a global takeover lock, and drain in-flight billing-cycle claim holders (no `claimed`/`dispatching` row may be mid-flight when the table switches). Run a final verify pass over every copied order, user and option, and re-sync the billing-attempts table (`REPLACE`, then count and checksum). Any mismatch fails the cutover and leaves the state at `verified`. Options are copied last;
  1. copy settings from the first non-empty `Keys::settings_sources()` entry to `woocommerce_supcheckout_settings`, and the `Keys::options()` values verbatim (including the token-identity secret, so H12 provenance hashes stay valid);
  2. translate `woocommerce_gateway_order`, the `woocommerce_default_gateway` value, WCML gateway country/currency rules and FunnelKit gateway lists. Each is recorded in the `Journal` **before** the write, passing both the current value and the value about to be written (`Journal::record($target, $path, $before_value, $after_value, $restore_source)`). A third-party original is kept in a takeover-owned backup option named as its `restore_source`, because the journal holds fingerprints only;
  3. reschedule the `Keys::cron_hooks()` events to the new names. Old events are kept until Finalize, and their callbacks stay registered as aliases. Already-canonical hooks (`supcheckout_reconciliation_sweep`, `supcheckout_process_due_parent`) are left alone;
  4. register the gateway under `supcheckout` for new orders, and make every runtime check that compares an order's gateway use `Keys::is_our_gateway()`. This covers the callback/lifecycle ownership checks, the subscription parent and renewal selection queries (`payment_method IN ('upayments','supcheckout')`), admin presentation and the Blocks/Store API. Historical orders are **not** rewritten. Test: after cutover, a pre-switch `upayments` subscription parent still renews through SUPCheckout, and a pre-switch pending order is still paid by its callback;
  5. transition to `cut_over`;
  6. if consented, deactivate the official plugin with `deactivate_plugins()`.

  **Stale sessions (added after the review of `02bf9fc`).** WooCommerce sessions, kept for up to 48 h, may still hold `chosen_payment_method=upayments` after cutover. Cutover does not rewrite `wc_sessions` rows. Instead, every runtime session comparison uses `Keys::is_our_gateway()` (Task 2), and on the first checkout request of such a session the stored value is re-pointed to `Keys::GATEWAY`. Test: after cutover, a session holding `upayments` still renders our gateway as selected and places the order under `supcheckout`.

- [ ] **Step 1: Write the failing test.** The WCML gateway restriction stored under `upayments` ends up under `supcheckout` with the same countries, and the journal holds the before- and after-fingerprints.
  - Do the third-party option paths first, on a real WCML/FunnelKit install in the Task 12 matrix.
  - Until a path is evidenced there, the translator only **reports** it and never writes it.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): settings and third-party translation, cutover`

### Task 8: `LegacyCallbackListener` (permanent)

**Files:**
- Create: `src/Takeover/LegacyCallbackListener.php`
- Modify: `src/Payment/PaymentLifecycle.php` and `UPayments.php`. The legacy route has **two** listeners today: `PaymentLifecycle::handle_callback` at priority 5 (PaymentLifecycle.php:20), and the gateway's `check_ipn_response` (UPayments.php:251). `LegacyCallbackListener::register()` attaches the **same pair in the same priority order** to `woocommerce_api_supcheckout` and keeps both on `woocommerce_api_wc_upayments`.
- Test: `tests/harness/provider-payment-lifecycle-harness.php`, new scenario T1.

**Interfaces:**
- Produces: both routes, served by the same verified handler. New Charges build URLs with `Keys::CALLBACK_ROUTE` only after cutover.

- [ ] **Step 1: Write the failing lifecycle scenario T1** (Review Focus 3).
  - A pre-switch order (legacy keys only, `payment_method=upayments`) receives a webhook on `wc_upayments` after `cut_over`.
  - The provider CAPTURED binds to the order, and the order completes once.
  - The payment ID is written under the new key, with the legacy key unchanged.
  - **Route parity:** the same browser return and the same webhook, replayed on `wc_upayments` and on `supcheckout`, produce identical redirects, notes and order state. The parity is pinned by the existing legacy-callback-routing harness extended to both routes.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): permanent legacy callback route`

### Task 9: WP-CLI interface

**Files:**
- Create: `src/Takeover/Cli.php`, registered as `wp supcheckout takeover`.
- Create: `src/Takeover/PausedBoot.php`. The takeover's primary scenario is the official plugin being active, and then SUPCheckout's bootstrap returns from `OfficialPluginConflict::pause_if_active()` before `Plugin::boot()`. So the CLI must not depend on the full runtime. On the paused branch, the main file calls `PausedBoot::boot()`, which registers only:
  - the `wp supcheckout takeover` command, when `WP_CLI` is defined;
  - the 0c onboarding/preflight admin entry point.

  It loads only `Simplixi\SUPCheckout\Takeover\*` classes and the read-only services they need. It registers no gateway, no Blocks method, no `woocommerce_api_*` route, no cron hook and no global symbol, so it can never collide with the official plugin. `run` deactivates the official plugin, and the full runtime boots on the next request.
- Test: `tests/integration/TakeoverCliTest.php` (real WordPress, CI).

**Interfaces:**
- Produces:
  - `preflight [--format=json]`;
  - `run [--yes] [--deactivate-official]`;
  - `status`;
  - `rollback [--yes]`;
  - `finalize [--yes] [--force-after-review]`.
- Exit codes: `0` ok, `2` blocked by preflight, `3` verification mismatch.

- [ ] **Step 1: Write the failing integration test.** With the **official 3.1.1 plugin active**, SUPCheckout is activated and therefore paused. The test:
  - asserts `wp supcheckout takeover preflight` is available and runs;
  - asserts no `upayments` gateway, route or cron registration came from SUPCheckout's paused boot (the official plugin owns them);
  - seeds two orders and the settings option;
  - runs `run --yes --deactivate-official`;
  - asserts state `cut_over`, the new keys present and the legacy keys intact.

  Also extend `official-plugin-coexistence-harness.php`: the paused branch registers exactly the takeover CLI/admin entry point and nothing else.
- [ ] **Step 2: Run it.** Expected: FAIL.
- [ ] **Step 3: Implement it.**
- [ ] **Step 4: Run it.** Expected: PASS.
- [ ] **Step 5: Commit.** `feat(takeover): wp supcheckout takeover CLI`

### Task 10: `Rollback`

**Files:**
- Create: `src/Takeover/Rollback.php`
- Test: `tests/unit/Takeover/RollbackTest.php`

**Interfaces:**
- Produces: `Rollback::run(bool $reactivate_official): array{ok:bool, restored:int, conflicts:list<array{seq:int, target:string, path:string}>}`.
  - It replays the `Journal` in reverse: settings from the intact legacy option, third-party values, gateway order and cron. It also rewrites `payment_method` from `supcheckout` to `upayments` on post-cutover orders only.
  - **Later edits win.** An entry is replayed only when `Journal::is_current_after($entry, $current_value)` holds, i.e. the current value's fingerprint equals the entry's `after_fingerprint`. Otherwise the merchant or another plugin changed the value during the 30 days: Rollback records a conflict `{seq, target, path}` in its result, leaves the newer value in place, and reports `ok: false` with the conflict list so the owner decides.
  - `Journal::entries()` throws on a malformed journal, so Rollback fails before replaying anything.
  - **Back-sync.** After cutover, writes go to both names under the window model (amended), but the billing-attempts table and anything written outside SUPCheckout's runtime may still differ, so new data is treated as the latest. Rollback therefore copies new → legacy wherever the two differ:
    - order meta on every order holding any new key;
    - user meta, including the provenance prefix;
    - product meta;
    - `Keys::options()` (new → legacy when the new option is present);
    - the billing-attempts table, with prepared `REPLACE INTO {legacy} SELECT … FROM {new} WHERE id > %d ORDER BY id LIMIT 500` batches over **all** rows, so rows updated after cutover are restored too.
  - It is batched and resumable through the same runner and cursors as Task 6, and the state stays `cut_over` until back-sync verifies.
  - **(amended after the review of `d4bfad4`) Rollback runs under the same quiesce as Cutover.** The billing-attempts table is single-written (the new table while `cut_over`), so a row written between its back-sync and the transition would be lost from the legacy table, and a cycle claim could become re-claimable: a double auto-deduction. Rollback therefore:
    1. pauses the subscription scheduler and the `supcheckout` Action Scheduler group, takes the takeover lock, and drains in-flight claim holders;
    2. back-syncs meta, users, products and options;
    3. back-syncs the billing-attempts table **last**, verifying count and checksum;
    4. transitions to `rolled_back` while still quiesced, then releases the quiesce.

    `CycleClaim::acquire()` / `acquire_with_snapshot()` re-read the takeover state (`TakeoverState::flush_cache()`) at the start of every claim, so a long-running queue worker never claims in the table it saw before a transition (done in Task 2).
  - **(amended after the review of `d4bfad4`) Transition first, then remove the copies.** Once the state is `rolled_back`, reads are legacy-only and runtime writes stop touching new names, so stale copies are unreachable and cannot be recreated. Only then does the journaled, resumable removal of the new-name copies run. `rolled_back → preflight_ok` requires that removal to be complete, so a later run starts clean.
  - If consented, it reactivates the official plugin after the transition.

- [ ] **Step 1: Write the failing test** (Review Focus 5).
  - After cutover, an order **created** through SUPCheckout (new keys, `payment_method=supcheckout`, status `processing`) gets `payment_method=upayments` on rollback. This is the only `payment_method` write the takeover ever makes; it is journaled, and it is needed so the reactivated official plugin recognises the order. Pre-switch orders were never rewritten, so they need nothing.
  - It stays `processing`, and its payment ID stays readable through `Keys::read_order_meta()`. Because the payment was written under the new key only, `Rollback` **also copies new → legacy** for keys written after cutover.
  - The test asserts the legacy `UPayments_PaymentID` equals the new value.
  - **Table back-sync:** a billing-attempt row inserted after cutover, and a pre-existing row whose status changed after cutover, both match in the legacy table after rollback.
  - **Later edit kept:** the merchant reorders `woocommerce_gateway_order` after cutover. Rollback leaves that order unchanged, reports it in `conflicts`, and returns `ok: false`, while every untouched journaled value is restored.
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
  - the PUC option and cron, matched by the `official_only()` `option_prefixes` / `cron_hook_prefixes` so any update-checker slug is covered;
  - every `Keys::official_only()` option, transient and cron hook. The `tables` entry (`upay_subscriptions`) is **reported, never dropped**;
  - legacy ephemeral rows, deleted by storage type:
    - order locks (`simplixpay_upay_order_lock_v1_*`), status gates (`simplixpay_upay_status_v1_*`) and the payment-methods rate gate (`upayments_payment_methods_rate_gate_*`) are plain options;
    - the payment-methods cache (`upay_pm_v3_*`) is a transient, so both its `_transient_upay_pm_v3_*` and `_transient_timeout_upay_pm_v3_*` rows are deleted (or `delete_transient()` with an object cache);
    - the `upay_pm_` and `upay_ctk_` families are `GET_LOCK()` names with no rows;
  - legacy user and product meta, under the same verified-equal rule;
  - the legacy billing-attempts table, dropped only when the row count and per-row checksums equal the new table's.

  After `finalized`, lock helpers stop dual-acquiring (Task 2).

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
6. assert:
   - every order verifies;
   - historical orders still have `payment_method=upayments`;
   - a new order placed after cutover has `supcheckout`;
   - the WCML restriction is under `supcheckout`;
   - a legacy-route webhook for the pending order pays it once;
   - the pre-switch subscription parent renews through SUPCheckout;
7. interrupt-and-resume: kill the runner mid-batch, re-run, and assert equality;
8. `rollback --yes`, then assert every pre-switch value is restored byte-for-byte (journal-backed) and paid orders are still paid;
9. `run` again, set time +31 days, `finalize --yes`, and assert the legacy keys are removed only where verified.

**Plus a pre-0b SUPCheckout lane** (no official plugin). Install the last pre-0b SUPCheckout package, then:
- seed a customer with an H12-provenanced saved card (`_upay_customer_token_v2_b1_<scope>`, created through the real `CustomerTokenIdentity` path), a subscription parent, the billing-attempts table and the migration ledgers;
- update in place to the 0b package and run the takeover;
- assert that saved-card access for that customer still verifies under the new names (provenance and secret copied);
- assert the renewal still claims its cycle in `supcheckout_billing_attempts`, and that rollback and run again leave the same result.

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
