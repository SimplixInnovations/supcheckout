# Sub-project 0c — Onboarding Backend (Services + Admin REST API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the smart switch and new-merchant setup a UI-agnostic service layer and an admin-only `supcheckout/v1` REST API that runs every long step in the background through Action Scheduler. The owner decided that 0c ships no screens. The branded wizard comes in Sub-project 2, on top of this API and after Sub-project 1.

**Architecture:** `Takeover\Onboarding\Onboarding` takes over the orchestration and decisions that `CliCommands` holds today and returns immutable `Outcome`s. Three adapters use it:
- the WP-CLI adapter, `CliCommands`, which keeps its output and exit codes;
- an Action Scheduler driver (`AsyncDriver` + `DriverStep`), which advances one persisted, single-flight `Operation` by bounded ticks;
- the REST controllers, which only validate, start an operation and report a bounded status. They never run long work inline.

Two engine changes support this:
- a `CachePurger`, called at the existing `caches_flushed` step;
- a `BudgetedSystem` yield seam, so Rollback and Finalize hand back control at the settle barrier and between removal units instead of sleeping.

`SelfTest` (spec §5.4 step 6) and `SetupChecks` (§5.5) reuse Preflight's probes. While the official plugin is active, `PausedBoot` additionally registers only the takeover routes and the two driver hooks.

**Tech Stack:** PHP 7.4+, WordPress 6.9+, WooCommerce 10.8+ (HPOS and legacy), Action Scheduler (bundled with WooCommerce), WordPress REST API, WP-CLI, PHPUnit, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-10-16-supcheckout-revamp-design.md`: §2, §3.2 Migration and onboarding service, §3.3 Admin REST API, §5.4 (step 6 Self-test), §5.5, §6 "Security in the UI", §8 and §9. Also read:
- the 0b plan `docs/superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md`, in particular "Deferred to 0c" and the Task 9 PausedBoot amendments;
- the rehearsal evidence `docs/project/evidence/TAKEOVER-REHEARSAL-2026-10-18.md`, follow-ups 2 and 3.

**Prerequisite:** `main` at `f228915` or later (0b merged, rehearsal passed).

## Global Constraints

**Identity and scope**
- REST namespace `supcheckout/v1`. PHP namespaces `Simplixi\SUPCheckout\Takeover\Onboarding\` and `Simplixi\SUPCheckout\Rest\`.
- Follow the style of the files in `src/Takeover`: PHP 7.4, types in docblocks, no `declare(strict_types=1)`, `defined('ABSPATH') || exit;`.
- New persisted names use the `supcheckout_onboarding_` prefix, are non-autoloaded and have no legacy twin. Because Preflight ignores options under `supcheckout_` (`OWN_OPTION_PREFIX`), they never appear in its scan.
- No new lowercase `upayments` or `wc_upayments` string literal in shipped PHP. Use `Keys::GATEWAY_LEGACY`, `Keys::GATEWAY`, `Keys::CALLBACK_ROUTE_LEGACY` and `Keys::SETTINGS_OPTION` (`takeover-identity-coverage-harness.php` rule 2).
- No superglobal reads in new files: request input comes only from `WP_REST_Request` (`architecture-dependency-boundaries-harness.php`).
- No new provider or loopback HTTP call site. Every outbound request goes through `Takeover\Environment` (`probe_availability`, `loopback_get`), which the dependency harness already allows.

**Payment and security (AGENTS.md)**
- No route or service in 0c marks, unmarks or re-verifies a payment, creates a Charge, or rewrites an order's `payment_method`. They call only the takeover engines (`Cutover`, `Rollback`, `Finalize`, `StaleClaims`, `CopyRunner`, `Preflight`) and read-only probes.
- The self-test calls UPayments only when the settings have `test_mode === 'yes'`, with exactly one `PaymentMethodAvailability::probe()`. In production mode it makes no provider call.
- **Every route:** `current_user_can('manage_woocommerce')`.
- **Every write:** `wp_verify_nonce(X-WP-Nonce, 'wp_rest')` is checked explicitly, so an Application Password alone cannot start a switch.
- **Input:** JSON-schema args; unknown parameters are rejected; consent flags mirror the CLI flags and default to `false`.
- **Responses:** carry `Cache-Control: no-store, private`, are at most 32768 bytes of JSON, and any list over 20 entries is truncated with a marker.
- **Secrets:** no response, operation record or stored report ever contains the API key, a token, card data or customer PII. Preflight data already holds only names, counts and versions.
- **Multisite:** a network-active official plugin stays blocked (Preflight `official_network_active`), and nothing deactivates or activates network-wide (the `WordPressCutoverSystem` contract).

**Compatibility of what exists**
- `wp supcheckout takeover` keeps every exit code, flag and output line (`CliCommands` docblock). Task 1 leaves `tests/unit/Takeover/CliCommandsTest.php` and `tests/integration/TakeoverCliTest.php` unmodified.
- Later tasks change them only where this plan names the change and its reason: the cache-purge line in Task 2 and the paused own-hooks list in Task 8.
- The Cutover, Rollback and Finalize step name `caches_flushed` stays. It now also means "caches purged".
- The paused surface adds only:
  - `rest_api_init` → `Rest\Routes::register_takeover`;
  - in cron, AJAX and WP-CLI contexts only, `supcheckout_onboarding_drive` and `supcheckout_onboarding_watchdog`.

  There is still no gateway, Blocks method, WC-API route, payment or subscription cron hook, copy-batch hook or global symbol.

**Out of scope**
- Any UI, screen, JavaScript or admin menu (Sub-project 2). The 0b "0c admin entry point" therefore stays unregistered.
- The event journal. The self-test reports `event_journal` as `deferred`.
- Pay links and recovery (Sub-project 4).
- The provider `status: "error"` / `result: null` follow-up (rehearsal follow-up 1). It goes in a separate test-first bug-fix PR.
- The optional sandbox test payment. It is designed in Sub-project 2 through the core checkout lifecycle, and 0c exposes no route for it. `SetupChecks` reports `test_payment` as `deferred`.
- Purge integrations for Breeze and SiteGround Optimizer.
- Cancelling a running copy. The CLI cannot do it either; `abort` from `verified` stays the way out.

**Gates**
- Every task ends green on:
  - `composer test:unit`;
  - every harness listed in `quality-gates.yml`;
  - `composer analyse`;
  - `composer lint:phpcs`;
  - `php tests/harness/public-surface-snapshot-harness.php`. An intended change is re-recorded with `--record`, and the commit body states the reason.
- One PR per task, squash-merged after exact-head CI per AGENTS.md "Merge and release discipline". Conventional commits with no attribution footer.

## Review Focus

1. **Action Scheduler not running** (WP-Cron disabled, as on the staging copy, and the admin only polling REST, which never dispatches the async runner). Expected: the operation stays `running` with nothing half-done, and after 60 s past due `GET /onboarding/status` reports `runner.stalled: true` with a hint. Owned by the Task 6 test `test_runner_health_reports_stalled_after_60_seconds_past_due`.
2. **The UPayments availability rate gate** (one request per minute; checkout traffic and a REST preflight both touch it). It makes the driver's preflight return `credential_unverified` with reason `cooldown`. Expected: up to 3 retries 65 s apart, then a report of `blocked`, never an instant block. Owned by the Task 6 test `test_switch_retries_a_credential_cooldown_three_times_then_blocks`.
3. **The host kills the long cutover tick** (`max_execution_time`, FPM terminate). Expected: the state stays `verified` and the dead holder's lease expires (`Quiesce::TTL` = 900 s). The driver retries `busy` every 60 s, up to 17 times. It re-enters Cutover at most 3 times, then fails `driver_exhausted` with a hint to run the WP-CLI command. Owned by the Task 6 tests `test_a_lost_lease_retries_every_60_seconds_17_times_then_fails_busy` and `test_a_cutover_reentered_after_a_killed_tick_fails_driver_exhausted_after_3`.
4. **A double click, or two admins clicking Switch.** Expected: exactly one operation (compare-and-swap); the second request gets 429 within 10 s, and a different kind gets 409 `supcheckout_operation_in_progress`. Owned by the Task 4 test `test_a_second_start_while_running_returns_null` and the Task 7 test `test_a_second_start_is_429_then_another_kind_is_409`.
5. **A consent flag sent as the string `"false"` or misspelled (`deactivateOfficial`).** Expected: `"false"` is `false`, and an unknown key is a 400 error. It never deactivates the official plugin and is never silently ignored. Owned by the Task 7 tests `test_switch_string_false_consent_never_deactivates` and `test_unknown_parameter_is_rejected_400`.

---

## Design decisions (why)

- **Service extraction, not a rewrite.** `CliCommands` already separates decisions from printing for most paths. Only the decisions move to `Onboarding`: state guards, the preflight gate, retiring the official plugin, error→code maps and the status document. Confirmation prompts, the in-process copy loop with `sleep`, and printing stay in the CLI. Each `Outcome` carries the exact CLI message and informational lines, so the CLI output is unchanged by construction, and the unmodified `CliCommandsTest` proves it.
- **State-driven driver.** Each tick re-reads `TakeoverState` and picks the next step from it, not from a script. A CLI command run at the same time just moves the state, and the next tick follows it. Every engine is already crash-resumable and guarded (Quiesce lease, CopyRunner lock, compare-and-swap transitions), so no new mutual exclusion with the CLI is needed.
- **The official plugin is deactivated in its own tick.** Cutover then runs in the next action, in a fresh process where the official plugin's code is not loaded and SUPCheckout's full runtime is booted in `verified`. This is stricter than the CLI's in-process path and costs one action.
- **Long work that cannot yield runs in one action with a raised time limit.** This covers Cutover, the pre-transition part of Rollback and Finalize, and the read-only Finalize plan. Each phase gets a dead-man watchdog action at `Quiesce::TTL` + 60 s and an attempt budget. Work that can yield gets a 20 s tick budget: the copy (`CopyRunner::ACTION_TIME_BUDGET`), removal units and the settle barrier. The 120 s settle wait becomes a scheduled resume at its deadline, never a sleeping web worker.
- **No per-request database reads for the driver.** The hooks are registered without reading state, and only in contexts that can run Action Scheduler (cron, AJAX, WP-CLI). The watchdog runs from the scheduled watchdog action, from every status poll and from every start call, not from `action_scheduler_init`. This keeps the paused and frontend surface minimal.
- **Operations with outbound HTTP are asynchronous too.** That covers preflight, self-test and setup checks. Preflight alone can take 15 s (provider) plus 2 × 10 s (loopback), plus its database scans, which is too close to common 30 s web limits. Only `stale-claims/release` runs inline: at most 500 rows, under the lease.
- **Cache purge goes through `CutoverSystem::purge_caches()`.** The engines stay testable with fakes. The purge runs after the compare-and-swap, and a failing purge can never fail a switch that already happened.
- **Bounding instead of pagination.** 0c has no collection endpoint: every response is one bounded document (Task 4 `Bounded`). Paginated lists arrive with the journal-backed screens.
- **Cloudflare stays a manual purge.** The official Cloudflare plugin has no public purge function or action. It binds `purgeCacheEverything` to the actions in its `cloudflare_purge_everything_actions` filter while its own file loads, which is before SUPCheckout loads. SUPCheckout therefore detects it and reports `manual`. It never fires foreign actions such as `switch_theme`.

### Verified cache-purge APIs (Task 2)

| Plugin | Call | Guard | Source |
|---|---|---|---|
| LiteSpeed Cache | `do_action('litespeed_purge_all', 'SUPCheckout takeover')` | `has_action('litespeed_purge_all')`, for the report only; the docs say the hook is safe without LSCWP | docs.litespeedtech.com/lscache/lscwp/api/ ("Purges all caches managed by LSCWP for the current site"; `litespeed_purged_all` fires afterwards) |
| WP Rocket | `rocket_clean_domain()` | `function_exists('rocket_clean_domain')` | docs.wp-rocket.me/article/92-rocketcleandomain; `inc/functions/files.php` `function rocket_clean_domain( $lang = '', $filesystem = null )`, where an empty `$lang` purges every language URI (`get_rocket_i18n_uri()`), which covers WPML |
| W3 Total Cache | `w3tc_flush_all()` | `function_exists('w3tc_flush_all')` | `w3-total-cache-api.php` `w3tc_flush_all( $extras = null )` |
| WP Super Cache | `wp_cache_clear_cache(is_multisite() ? get_current_blog_id() : 0)` | `function_exists('wp_cache_clear_cache')` | `wp-cache-phase2.php` `function wp_cache_clear_cache( $blog_id = 0 )`: 0 clears everything, a blog ID only that blog |
| Cloudflare (official plugin) | none, reported `manual` | `class_exists('Cloudflare\APO\WordPress\Hooks', false)` | `cloudflare.loader.php` applies `cloudflare_purge_everything_actions` at load; there is no public purge API |
| Object cache | `wp_cache_flush()` | always | WordPress core |

Action Scheduler: `as_schedule_single_action($timestamp, $hook, $args, $group, $unique)`. With `$unique`, an action "will not be scheduled if another pending or running action has the same hook and group parameters" (`functions.php`). The queue runner raises the time limit only to its 30 s batch limit (`ActionScheduler_QueueRunner::run()`). It dispatches its async runner only on `is_admin()` shutdowns, so REST polls never start it (`maybe_dispatch_async_request()`).

---

## File Structure

```text
src/Takeover/Onboarding/
  Outcome.php                 Immutable result: code (CLI exit-code values), reason, message, lines, data
  Onboarding.php              UI-agnostic orchestration used by CLI, driver and REST
  Operation.php               Persisted single-flight operation record (CAS option)
  RateLimiter.php             Per-site CAS rate buckets
  Bounded.php                 Bounding of lists, strings and whole documents
  SelfTest.php                §5.4 step 6 checks
  SetupChecks.php             §5.5 new-merchant checks
  DriverStep.php              One state-driven tick per operation kind
  AsyncDriver.php             Action Scheduler binding: hooks, enqueue, watchdog, runner health
  BudgetedWordPressSystem.php BudgetedSystem decorator with a wall-clock deadline
src/Takeover/
  CachePurger.php             Documented vendor purges + wp_cache_flush, never fatal
  BudgetedSystem.php          RollbackSystem + budget_spent(): the yield seam
  (modified) CliCommands.php, FinalizeCommand.php, CutoverSystem.php, WordPressCutoverSystem.php,
             Cutover.php, Rollback.php, Finalize.php, Preflight.php, PausedBoot.php
src/Rest/
  Routes.php                  Route table; register() (full boot); register_all() / register_takeover() callbacks
  RequestGuard.php            Capability, nonce, unknown parameters, consent, rate limit
  Responses.php               Envelope, Outcome→HTTP mapping, no-store header
  OnboardingController.php    status, preflight, switch, rollback, abort, finalize, stale claims
  SetupController.php         self-test, setup checks (full boot only)
src/Plugin.php                (modified) Routes::register(); AsyncDriver::register()
tests/unit/Takeover/Onboarding/*Test.php, tests/unit/Rest/*Test.php, tests/support/wordpress-rest-stubs.php
tests/integration/CachePurgeRuntimeTest.php, tests/integration/TakeoverRestTest.php
```

The deterministic package grows by 17 source files, from 114 to 131. Task 10 records it.

---

### Task 1: `Onboarding` service and `Outcome`; the CLI becomes a thin adapter

**Files:**
- Create: `src/Takeover/Onboarding/Outcome.php`, `src/Takeover/Onboarding/Onboarding.php`
- Modify: `src/Takeover/CliCommands.php`. Decisions and maps move out. Kept: the confirmation questions, `drive_copy()` with `WAIT_POLL` / `MAX_WAIT` / `MAX_STOPPED`, and printing. Its exit-code constants become aliases (`public const OK = Outcome::OK;` …).
- Modify: `src/Takeover/FinalizeCommand.php`. Its `EXIT` map moves to `Onboarding::FINALIZE_EXIT`, verbatim.
- Test: `tests/unit/Takeover/Onboarding/OnboardingTest.php`. `tests/unit/Takeover/CliCommandsTest.php` stays unmodified.

**Interfaces:**
- Produces `Outcome`:
  - constants `OK=0, ERROR=1, BLOCKED=2, MISMATCH=3, BUSY=4, STALE_CLAIMS=5, STATE=6, OFFICIAL_STILL_ACTIVE=7, CONFLICTS=8, OFFICIAL_NOT_REACTIVATED=9`;
  - `__construct(int $code, ?string $reason, string $message, array $data = [], array $lines = [])`;
  - accessors `code(): int`, `reason(): ?string`, `message(): string`, `data(): array`, `lines(): list<string>`, `is_ok(): bool`;
  - factories `Outcome::ok(string $message, array $data = [], array $lines = [])` and `Outcome::fail(int $code, string $reason, string $message, array $data = [], array $lines = [])`.
- Produces `Onboarding`:
  - `__construct(callable $preflight, CopyStore $store, CutoverSystem $system, StaleClaimStore $claims)`;
  - `static from_wordpress(): self`, with the same four collaborators as `Cli::commands()`;
  - `preflight(): array{blocking:list, warnings:list, facts:list}`, normalized as `CliCommands::run_preflight()` does today;
  - `status(): array`: exactly the document `status --format=json` prints today;
  - `plan_run(bool $deactivate_official): Outcome`, with `data['next']` one of `'run'`, `'cutover_tail'` or `'done'`;
  - `check_preflight(): Outcome`: BLOCKED with reason `preflight_blocking` and `data['report']`, with `lines()` holding the CLI's `BLOCKING: <code>: <message>` lines;
  - `start_copy(): Outcome`, `copy_step(): string` (a `CopyRunner` status), and `copy_result(): ?Outcome` (null while copying);
  - `retire_official(bool $consent, string $purpose): Outcome`. `$purpose` is `'switch'` or `'rollback'`, and `data['deactivated']` holds the basename or null;
  - `cutover(bool $consent): Outcome`;
  - `plan_rewind(string $mode): Outcome`, with `data['retire_official']` true for a rollback from `cut_over`;
  - `rewind(string $mode, bool $reactivate, ?RollbackSystem $system = null): Outcome`;
  - `plan_finalize(bool $force): Outcome`, a cheap guard with no lease, with `data{due_at, days_left}`;
  - `finalize(bool $force, ?RollbackSystem $system = null): Outcome`;
  - `finalize_plan(): array`, which is `Finalize::plan()`;
  - `stale_claims(): list` and `release_stale_claims(): Outcome`;
  - public constants `CUTOVER_EXIT`, `ROLLBACK_EXIT` and `FINALIZE_EXIT`, keyed by engine error code with the values copied verbatim.
- Every `Outcome::message()` is the text the CLI printed after `Error: ` / `Success: ` for that path, character for character.

- [ ] **Step 1: Write the failing tests** in `OnboardingTest`, using the existing `FakeCopyStore`, `FakeCutoverSystem` and `FakeStaleClaimStore`:

```php
public function test_plan_run_refuses_a_finalized_store_with_the_state_code(): void {
    $this->seed_state('finalized');
    $outcome = $this->onboarding()->plan_run(false);
    self::assertSame(array(Outcome::STATE, 'state', 'state "finalized" does not allow run'), array($outcome->code(), $outcome->reason(), $outcome->message()));
}
public function test_plan_run_on_cut_over_with_official_active_and_no_consent_is_official_still_active(): void {
    $this->seed_state('cut_over'); $this->system->official = 'upayments/UPayments.php';
    $outcome = $this->onboarding()->plan_run(false);
    self::assertSame(Outcome::OFFICIAL_STILL_ACTIVE, $outcome->code());
    self::assertSame('cut over, but the official UPayments plugin (upayments/UPayments.php) is active again; SUPCheckout stays paused. Rerun with --deactivate-official', $outcome->message());
}
public function test_plan_run_on_cut_over_with_consent_is_the_cutover_tail(): void { /* data['next'] === 'cutover_tail', code OK */ }
public function test_check_preflight_blocks_with_the_report_lines(): void {
    $outcome = $this->onboarding(array('blocking' => array(array('code' => 'credential_unverified', 'message' => 'x', 'data' => array()))))->check_preflight();
    self::assertSame(array(Outcome::BLOCKED, 'preflight_blocking', 'blocked by preflight; nothing changed'), array($outcome->code(), $outcome->reason(), $outcome->message()));
    self::assertSame(array('BLOCKING: credential_unverified: x'), $outcome->lines());
}
/** @dataProvider cutover_errors */
public function test_cutover_maps_every_engine_error_like_the_cli(string $error, int $code): void { /* fake Cutover result error => Outcome::code() === $code */ }
public function test_status_document_keeps_the_cli_json_keys_in_order(): void {
    self::assertSame(array('status', 'cut_over_at', 'rolled_back_at', 'rollback', 'finalize', 'phase', 'cursor', 'cursors', 'totals', 'mismatches', 'anomalies', 'journal', 'quiesced', 'official_plugin', 'stale_claims', 'warnings'), array_keys($this->onboarding()->status()));
}
public function test_plan_finalize_before_30_days_without_force_is_too_early(): void { /* code STATE, reason 'too_early', data['days_left'] === 30 */ }
public function test_retire_official_for_a_switch_without_consent_is_blocked_official_active(): void { /* code BLOCKED, reason 'official_active' */ }
public function test_the_cli_exit_code_constants_alias_outcome(): void {
    self::assertSame(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9), array(CliCommands::OK, CliCommands::ERROR, CliCommands::BLOCKED, CliCommands::MISMATCH, CliCommands::BUSY, CliCommands::STALE_CLAIMS, CliCommands::STATE, CliCommands::OFFICIAL_STILL_ACTIVE, CliCommands::CONFLICTS, CliCommands::OFFICIAL_NOT_REACTIVATED));
}
```

- [ ] **Step 2: Run them.** `vendor/bin/phpunit --filter OnboardingTest`. Expected: FAIL, `Class "Simplixi\SUPCheckout\Takeover\Onboarding\Onboarding" not found`.
- [ ] **Step 3: Implement `Outcome` and `Onboarding`** by moving the code out of `CliCommands` (`run`, `retire_official*`, `status`, `rewind*`, `cutover`, `start_copy`, `copy_ended`, `run_preflight`, `print_report` lines, `journal_summary`, `rollback_summary`, `mismatch_count`). Then rewrite `CliCommands` so each public method calls `Onboarding`, prints `lines()`, and returns `code()` through its existing `done()` / `fail()`.
- [ ] **Step 4: Run.** `vendor/bin/phpunit --filter "OnboardingTest|CliCommandsTest|CliConfirmTest|FinalizeTest|RollbackTest|CutoverTest"`. Expected: PASS. Then `git diff --exit-code origin/main -- tests/unit/Takeover/CliCommandsTest.php tests/integration/TakeoverCliTest.php`. Expected: exit 0.
- [ ] **Step 5: Run all gates** (Global Constraints → Gates). The public surface is unchanged (no hook was added).
- [ ] **Step 6: Commit.** `refactor(takeover): extract the UI-agnostic Onboarding service from the CLI`

---

### Task 2: `CachePurger` at `caches_flushed` (rehearsal follow-up 2)

**Files:**
- Create: `src/Takeover/CachePurger.php`
- Modify:
  - `src/Takeover/CutoverSystem.php`: add `purge_caches()`;
  - `src/Takeover/WordPressCutoverSystem.php`: delegate to `CachePurger::purge_all()`;
  - `tests/unit/Takeover/FakeCutoverSystem.php`: new public `$purges` (int), `$log` (list: each `reached()` step plus `'purge'`) and `$purge_throws` (bool);
  - `src/Takeover/Cutover.php:199-201`, `src/Takeover/Rollback.php`: at the transition's `caches_flushed` and after `official_reactivated`;
  - `src/Takeover/Finalize.php:293-295`;
  - `src/Takeover/CliCommands.php` and `FinalizeCommand.php`: print one line, `cache purge: ran=<csv> manual=<csv|-> failed=<csv|->`, after the steps line. This is an intended output addition; any `CliCommandsTest` assertion over a complete output list gains that line, with the reason in the commit;
  - `.github/workflows/ecosystem-certification.yml`, E3 job: a step after "Verify finalized economics under cache plugin" with `env: SUPCHECKOUT_CACHE_PLUGIN: ${{ matrix.slug }}`.
- Test: `tests/unit/Takeover/CachePurgerTest.php`; additions to `CutoverTest`, `RollbackTest` and `FinalizeTest`; `tests/integration/CachePurgeRuntimeTest.php`.

**Interfaces:**
- Produces:
  - `CachePurger::purge_all(): array{ran:list<string>, manual:list<string>, failed:list<string>}`, using the names `litespeed`, `wp_rocket`, `w3tc`, `wp_super_cache`, `cloudflare` and `object_cache`, in that order, with the calls and guards from the "Verified cache-purge APIs" table. Each call is wrapped in `try { } catch (\Throwable $e)` and reported under `failed` by name. It never rethrows and never logs a value;
  - `CachePurger::detected(): list<string>`, the cache layers present (same names, without `object_cache`), used by Task 5;
  - `CutoverSystem::purge_caches(): array`, with the same shape;
  - `report['cache_purge']` in the Cutover, Rollback and Finalize results. Rollback adds `report['cache_purge_after_reactivation']` when it reactivated the official plugin. Each engine calls the purge after `TakeoverState::refresh()` and before `step('caches_flushed')`, inside its own `try/catch`. A throw records `failed: ['system']` and leaves `ok` and the transition intact.

- [ ] **Step 1: Write the failing tests.**

```php
// CachePurgerTest. Vendor functions are declared in #[RunInSeparateProcess] tests.
public function test_only_the_object_cache_is_flushed_when_no_cache_plugin_is_present(): void {
    self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), CachePurger::purge_all());
}
public function test_litespeed_gets_the_documented_action_with_a_reason(): void {
    add_action('litespeed_purge_all', function ($reason) { $GLOBALS['ls'][] = $reason; });
    $report = CachePurger::purge_all();
    self::assertSame(array('SUPCheckout takeover'), $GLOBALS['ls']);
    self::assertSame(array('litespeed', 'object_cache'), $report['ran']);
}
public function test_a_throwing_vendor_purge_is_reported_failed_and_the_rest_still_run(): void { /* w3tc_flush_all throws: failed === ['w3tc'], ran === ['object_cache'] */ }
public function test_the_cloudflare_plugin_is_reported_manual_and_never_called(): void { /* manual === ['cloudflare'] */ }
public function test_super_cache_clears_only_the_current_blog_on_multisite(): void { /* is_multisite true, blog 3: argument 3; single site: argument 0 */ }
public function test_rocket_clean_domain_is_called_without_a_language(): void { /* recorded args === array() */ }
// CutoverTest
public function test_cutover_purges_caches_once_after_the_transition(): void {
    $result = (new Cutover($store, $system))->run(false);
    self::assertSame(1, $system->purges);
    self::assertSame(array('cut_over', 'purge', 'caches_flushed'), array_slice($system->log, -3));
    self::assertSame(array('ran' => array('object_cache'), 'manual' => array(), 'failed' => array()), $result['report']['cache_purge']);
}
public function test_a_throwing_purge_never_fails_a_completed_cutover(): void { /* ok true, status cut_over, report cache_purge failed === ['system'] */ }
public function test_a_refused_cutover_never_purges(): void { /* official_active: purges === 0 */ }
// RollbackTest
public function test_rollback_purges_at_the_transition_and_again_after_reactivation(): void { /* run(true): purges === 2; run(false): purges === 1 */ }
// FinalizeTest
public function test_finalize_purges_once_after_finalized(): void { /* purges === 1, after 'finalized' */ }
```

`CachePurgeRuntimeTest` (real WordPress, E3 cells):
- `litespeed-cache`: `in_array('litespeed', $report['ran'], true)` and `did_action('litespeed_purged_all') >= 1`;
- `w3-total-cache`: `in_array('w3tc', $report['ran'], true)`;
- `breeze` and `sg-cachepress`: `$report['ran'] === array('object_cache')`;
- every cell: `$report['failed'] === array()`.

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "CachePurgerTest|CutoverTest|RollbackTest|FinalizeTest"`. Expected: FAIL, `Class "Simplixi\SUPCheckout\Takeover\CachePurger" not found`.
- [ ] **Step 3: Implement** `CachePurger`, the `CutoverSystem::purge_caches()` method on both systems and the fake, the three engine call sites, the CLI line, and the workflow step.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then run all gates.
- [ ] **Step 5: Commit.** `feat(takeover): purge LiteSpeed, WP Rocket, W3TC, WP Super Cache and the object cache at caches_flushed`

---

### Task 3: `BudgetedSystem`, so Rollback and Finalize yield instead of sleeping

**Files:**
- Create: `src/Takeover/BudgetedSystem.php`
- Modify: `src/Takeover/Rollback.php` (`remove()`, `end_pass()`, `settle()`), `src/Takeover/Finalize.php` (`remove()`, `end_pass()`, `settle()`) and `src/Takeover/Onboarding/Onboarding.php` (map `yielded`)
- Test: `tests/unit/Takeover/FakeBudgetedSystem.php` (extends `FakeCutoverSystem`, with a configurable `budget_spent()`), plus additions to `RollbackTest`, `FinalizeTest` and `OnboardingTest`

**Interfaces:**
- Produces `interface BudgetedSystem extends RollbackSystem { /** @return bool */ public function budget_spent(); }`.
- Rollback and Finalize, only when `$this->system instanceof BudgetedSystem`:
  - `settle()` returns at once with error `yielded` and `report['resume_at'] = transitioned_at + CopyRemoval::SETTLE_SECONDS` (120) while `now < deadline`. It never calls `sleep()`;
  - after each persisted removal unit (`save_progress()` succeeded), `budget_spent()` true returns error `yielded` with `report['resume_at'] = now`;
  - the step `yielded` is appended.

  Progress is persisted before every yield, so a rerun resumes through the existing pending/resume paths. `WordPressCutoverSystem` does not implement the interface, so the CLI never yields.
- `Onboarding::rewind()` / `finalize()` map `yielded` to `Outcome::BUSY` with reason `yielded` and `data['resume_at']`. `ROLLBACK_EXIT` / `FINALIZE_EXIT` gain `'yielded' => BUSY`, a defensive entry the CLI never reaches.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_a_budgeted_rollback_yields_at_the_settle_barrier_instead_of_sleeping(): void {
    $system = new FakeBudgetedSystem(/* budget never spent */); $system->now = 5000;
    $result = (new Rollback($store, $system))->run(false);
    self::assertSame('yielded', $result['error']);
    self::assertSame(5120, $result['report']['resume_at']);
    self::assertSame(0, $system->sleeps);
    self::assertSame('rolled_back', TakeoverState::get()['status']);
    self::assertTrue(Rollback::removal_pending(TakeoverState::get()));
}
public function test_a_yielded_rollback_resumes_after_the_deadline_and_completes(): void {
    /* after the yield above: $system->now = 5120; run(false) again: ok true, last steps contain 'rescanned', 'removal_settled', 'preflight_ok'; status preflight_ok; no new-name copy left in $store */
}
public function test_a_budget_spent_mid_removal_yields_after_one_saved_unit(): void { /* budget_spent true after the first unit: error yielded, resume_at === now, persisted removal cursor advanced by exactly one unit */ }
public function test_a_budgeted_finalize_yields_at_the_settle_barrier_and_resumes_to_legacy_removed(): void { /* error yielded with resume_at = transitioned_at + 120; rerun at the deadline: ok, last step 'legacy_removed' */ }
public function test_the_wordpress_system_never_yields(): void {
    self::assertNotInstanceOf(BudgetedSystem::class, new WordPressCutoverSystem());
}
public function test_rewind_maps_yielded_to_busy_with_resume_at(): void { /* code 4, reason 'yielded', data['resume_at'] === 5120 */ }
```

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "RollbackTest|FinalizeTest|OnboardingTest"`. Expected: FAIL, `Interface "Simplixi\SUPCheckout\Takeover\BudgetedSystem" not found`.
- [ ] **Step 3: Implement** the interface and the guarded yields. `settle()` returns `bool`, with false meaning "yielded", and `end_pass()` propagates `'yielded'`.
- [ ] **Step 4: Run** the same filter plus `CliCommandsTest`. Expected: PASS. Then run all gates.
- [ ] **Step 5: Commit.** `feat(takeover): Rollback and Finalize yield at the settle barrier and between removal units under a budget`

---

### Task 4: `Operation`, `RateLimiter` and `Bounded`

**Files:**
- Create: `src/Takeover/Onboarding/Operation.php`, `RateLimiter.php`, `Bounded.php`
- Test: `tests/unit/Takeover/Onboarding/OperationTest.php`, `RateLimiterTest.php`, `BoundedTest.php`

**Interfaces:**
- Produces `Operation`, stored as one non-autoloaded JSON option, `OPTION = 'supcheckout_onboarding_operation_v1'`. It is created with `OrderLock::insert_if_absent()` and changed only with `OrderLock::replace_if_current()` against the exact stored string. `OrderLock::flush_option_cache()` runs before every read, the same pattern as `TakeoverState`.
  - **Kinds:** `KIND_PREFLIGHT='preflight'`, `KIND_SWITCH='switch'`, `KIND_ROLLBACK='rollback'`, `KIND_ABORT='abort'`, `KIND_FINALIZE='finalize'`, `KIND_FINALIZE_DRY_RUN='finalize_dry_run'`, `KIND_SELF_TEST='self_test'`, `KIND_SETUP_CHECKS='setup_checks'`.
  - **Outcomes:** `RUNNING='running'`, `DONE='done'`, `BLOCKED='blocked'`, `FAILED='failed'`, and `INVALID='invalid'` (read-only, for an unreadable record).
  - **Record:** `{id: uuid4, kind, consent: {deactivate_official: bool, reactivate_official: bool, force_after_review: bool}, user_id: int, started_at, updated_at, outcome, phase, steps: list<string> (last 50), attempts: array<string,int>, busy_retries: int, cooldown_retries: int, error: ?string, hint: ?string, next_at: int, report: array, self_test: ?array}`.
  - **Methods:**
    - `static current(): ?array`;
    - `static start(string $kind, array $consent, int $user_id, int $now): ?array`. It returns null when an operation is `running` or the record is unreadable. Consent keeps only the three keys, and each is `true` only when strictly `true`;
    - `static update(string $id, array $fields, int $now): bool`. It refuses another ID or a finished operation;
    - `static finish(string $id, string $outcome, ?string $error, ?string $hint, array $report, int $now): bool`;
    - `static is_running(?array $op): bool`.
  - Every report and field is stored through `Bounded::document()`.
- Produces `RateLimiter`:
  - `WRITE_WINDOW = 10` and `PROBE_WINDOW = 65`, the latter equal to `PaymentMethodAvailability::RATE_GATE_COOLDOWN`;
  - `static hit(string $bucket, int $window, int $now): int`, which returns 0 when allowed, else the seconds to wait. One option per bucket, `supcheckout_onboarding_rate_<bucket>`, holding `"<until>"`, written by compare-and-swap. An unreadable value fails closed with the full window;
  - buckets: `provider_probe` (shared by preflight, self-test and setup checks), `switch`, `rollback`, `abort`, `finalize`, `stale_claims`.
- Produces `Bounded`:
  - `LIST_LIMIT = 20`, `STRING_LIMIT = 500`, `MAX_BYTES = 32768`;
  - `static value($value)`: lists longer than 20 keep 20 items plus a final `array('truncated' => <dropped count>)`, and strings longer than 500 characters are cut to 500;
  - `static document(array $doc): array`: `value()`, then, while `strlen(wp_json_encode($doc)) > 32768`, replace the largest remaining list with `array(array('truncated' => <count>))`.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_start_creates_one_running_operation(): void {
    $op = Operation::start('switch', array('deactivate_official' => true), 7, 1000);
    self::assertSame(array('switch', 'running', 7, 1000, array('deactivate_official' => true, 'reactivate_official' => false, 'force_after_review' => false)), array($op['kind'], $op['outcome'], $op['user_id'], $op['started_at'], $op['consent']));
    self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $op['id']);
}
public function test_a_second_start_while_running_returns_null(): void { /* Review Focus 4 */ }
public function test_start_replaces_a_finished_operation(): void { /* after finish(..., 'done', ...): start returns a new id */ }
public function test_update_refuses_another_id_and_a_finished_operation(): void { /* false, false */ }
public function test_consent_is_whitelisted_and_strictly_boolean(): void { /* array('deactivate_official' => 'true', 'evil' => true): consent === all three false */ }
public function test_an_unreadable_record_reads_invalid_and_is_never_overwritten(): void { /* raw option '{' : current()['outcome'] === 'invalid'; start(...) === null; raw unchanged */ }
public function test_rate_limit_allows_then_waits_then_allows(): void {
    self::assertSame(array(0, 6, 0), array(RateLimiter::hit('switch', 10, 1000), RateLimiter::hit('switch', 10, 1004), RateLimiter::hit('switch', 10, 1010)));
}
public function test_rate_buckets_are_independent(): void { /* hit('switch') then hit('abort') at the same second: 0 */ }
public function test_bounded_truncates_lists_to_20_with_a_marker(): void {
    $out = Bounded::value(range(1, 100));
    self::assertSame(21, count($out)); self::assertSame(array('truncated' => 80), $out[20]);
}
public function test_bounded_document_never_exceeds_32768_bytes(): void { /* a 40 KB document: strlen(wp_json_encode(Bounded::document($doc))) <= 32768 */ }
```

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "OperationTest|RateLimiterTest|BoundedTest"`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement** the three classes.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then run all gates.
- [ ] **Step 5: Commit.** `feat(onboarding): single-flight operation record, CAS rate buckets and bounded documents`

---

### Task 5: `SelfTest` and `SetupChecks` (§5.4 step 6, §5.5)

**Files:**
- Modify: `src/Takeover/Preflight.php`. Extract four public seams; `run_with()` uses them, and its output is unchanged (the unmodified `PreflightTest` proves it):
  - `credential_findings(Environment $env, array $settings): list<finding>`: the `credential_unverified` entry, if any, then `account_mode`;
  - `callback_probe(Environment $env): list<array{status:int, headers:array<string,string>}|null>`;
  - `callback_findings(array $probe): list<finding>`: `callback_unreachable` and `cache_exclusion_missing`, when present;
  - `reference_findings(Environment $env): list<finding>`: `third_party_references`.
- Create: `src/Takeover/Onboarding/SelfTest.php`, `src/Takeover/Onboarding/SetupChecks.php`
- Test: `tests/unit/Takeover/Onboarding/SelfTestTest.php`, `SetupChecksTest.php`, using the `PreflightFixtures` / `PreflightFakeDb` environments

**Interfaces:**
- **Check shape:** `array{id:string, result:'pass'|'warn'|'fail'|'skipped'|'deferred', code:?string, message:string (translatable), data:array}`, where `data` holds counts, names, modes and currencies, never a value or a key.
- **`SelfTest`:** `__construct(Environment $env, callable $gateway_state /* (): array{registered:bool, enabled:bool} */, callable $takeover_status /* (): string */)`. `run(): array{ok:bool, ran_at:int, checks:list<check>}`, with `ok` true when no check is `fail`. Check IDs, in order:
  1. `takeover_state`: pass for `cut_over` or `finalized`; otherwise fail with code `not_switched`.
  2. `callback_reachable`.
  3. `cache_exclusion`: warn when `cache_exclusion_missing` is present.
  4. `provider_status`. When settings `test_mode === 'yes'`: one `credential_findings()` call. Verified → pass with `data{mode, methods}`. Reason `provider_unavailable` → fail with code `credential_unverified` and `data.reason`. Reason `cooldown` → warn. Otherwise fail. When not in test mode: skipped with code `production_mode`, and no provider call at all.
  5. `gateway_registered`: `Keys::GATEWAY` is in the WooCommerce gateway registry.
  6. `gateway_enabled`: `Keys::SETTINGS_OPTION['enabled'] === 'yes'`.
  7. `currency_supported`: `GatewaySettings::supported_currencies()`.
  8. `multilingual`: as in `SetupChecks`.
  9. `event_journal`: deferred with code `event_journal_not_built`.
- **`SetupChecks`:** `__construct(Environment $env, callable $detected_caches /* CachePurger::detected */)`. `run(): array{ok:bool, ran_at:int, checks:list<check>}`. Settings come from `Keys::SETTINGS_OPTION`. Check IDs, in order:
  1. `credential`: from `credential_findings()`, which includes `provider_unavailable` with `http_status`.
  2. `account_mode`.
  3. `currency`: the store currency must be supported (fail otherwise). When `_wcml_settings['enable_multi_currency'] === 2`, it warns with code `wcml_currency_unsupported`, listing each secondary currency key of `currency_options` that is not supported.
  4. `callback_reachable`.
  5. `cache_exclusion`.
  6. `cache_layers`: pass or warn, with `data{plugins: detected, cloudflare_proxied: (a cf-ray header on the probe response)}`. It warns when `cache_exclusion_missing` is present or `cloudflare` was detected (manual purge).
  7. `multilingual` (rehearsal follow-up 3, report-only): `data{wpml_active: defined('ICL_SITEPRESS_VERSION'), wcml_active: defined('WCML_VERSION'), multi_currency: bool, currencies: list<{code, gateways_enabled, new_id, legacy_id}>, location_rule: {new: bool, legacy: bool}}`. It reads `_wcml_settings['currency_options'][<code>]['gateways_settings']` and `'wcml_payment_gateway_' . Keys::GATEWAY` / `Keys::GATEWAY_LEGACY`, the shapes seeded by `tests/integration/fixtures/official-plugin-seed.php` `supcheckout_matrix_seed_wcml()`. It warns with code `wcml_gateway_settings_legacy_only` when a currency or location rule names only the legacy ID, and with `unknown_shape` when the option is not that shape. It never writes.
  8. `test_payment`: deferred with code `test_payment_ui_driven`.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_self_test_in_production_mode_makes_no_provider_call(): void {
    $calls = 0; $env = PreflightFixtures::environment(array('settings' => array('test_mode' => 'no', 'api_key' => 'k'), 'availability' => function () use (&$calls) { $calls++; return array('result' => 'success'); }));
    $check = self::check((new SelfTest($env, self::gateway(true, true), self::status('cut_over')))->run(), 'provider_status');
    self::assertSame(array('skipped', 'production_mode', 0), array($check['result'], $check['code'], $calls));
}
public function test_self_test_in_test_mode_makes_exactly_one_sandbox_call(): void { /* $calls === 1, result pass, data mode 'hosted' */ }
public function test_self_test_reports_a_provider_outage_as_provider_unavailable(): void { /* availability array('result'=>'failure','provider_unavailable'=>true,'http_status'=>502): fail, code credential_unverified, data reason provider_unavailable, http_status 502 */ }
public function test_self_test_defers_the_event_journal(): void { /* event_journal: deferred, code event_journal_not_built */ }
public function test_self_test_lists_its_checks_in_order(): void {
    self::assertSame(array('takeover_state', 'callback_reachable', 'cache_exclusion', 'provider_status', 'gateway_registered', 'gateway_enabled', 'currency_supported', 'multilingual', 'event_journal'), array_column($report['checks'], 'id'));
}
public function test_setup_checks_list_their_checks_in_order(): void {
    self::assertSame(array('credential', 'account_mode', 'currency', 'callback_reachable', 'cache_exclusion', 'cache_layers', 'multilingual', 'test_payment'), array_column($report['checks'], 'id'));
}
public function test_wcml_per_currency_settings_naming_only_the_legacy_id_warn(): void { /* the seed shape: multilingual warn, code wcml_gateway_settings_legacy_only, currencies KWD and SAR legacy_id true new_id false, location_rule legacy true */ }
public function test_wcml_secondary_currency_outside_the_supported_list_warns(): void { /* currency_options XYZ: currency warn, code wcml_currency_unsupported, data unsupported === ['XYZ'] */ }
public function test_no_check_ever_contains_the_api_key(): void { /* settings api_key 'sk-test-SECRET-0c': strpos(wp_json_encode($report), 'sk-test-SECRET-0c') === false, for both classes */ }
public function test_preflight_output_is_unchanged_by_the_seams(): void { /* existing PreflightTest unmodified: git diff --exit-code */ }
```

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "SelfTestTest|SetupChecksTest|PreflightTest"`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement** the seams and both classes. The WordPress `$gateway_state` binding, used by Task 6, reads `WC()->payment_gateways()->payment_gateways()` and `get_option(Keys::SETTINGS_OPTION)`.
- [ ] **Step 4: Run** the same filter, plus `php tests/harness/architecture-dependency-boundaries-harness.php` (no new egress). Expected: PASS. Then run all gates.
- [ ] **Step 5: Commit.** `feat(onboarding): post-switch self-test and new-merchant setup checks`

---

### Task 6: Asynchronous driver (`DriverStep`, `AsyncDriver`, `BudgetedWordPressSystem`)

**Files:**
- Create: `src/Takeover/Onboarding/DriverStep.php`, `AsyncDriver.php`, `BudgetedWordPressSystem.php`
- Modify: `src/Plugin.php`. Add `AsyncDriver::register();` after `CopyRunner::register();`. Re-record `tests/fixtures/public-surface.json` with the reason "0c Task 6: async onboarding driver hooks (cron/AJAX/CLI contexts only)".
- Test: `tests/unit/Takeover/Onboarding/DriverStepTest.php`, `AsyncDriverTest.php`

**Interfaces:**
- Consumes: `Onboarding` (Task 1), `BudgetedSystem` (Task 3), `Operation` / `Bounded` (Task 4), `SelfTest` / `SetupChecks` (Task 5).
- Produces `BudgetedWordPressSystem implements BudgetedSystem`:
  - `__construct(RollbackSystem $inner, int $deadline, callable $clock)`;
  - `budget_spent()` is `clock() >= $deadline`;
  - `sleep($s)` sleeps through `$inner` only while `clock() + $s <= $deadline`;
  - `reached($step)` collects steps (`steps(): list<string>`);
  - everything else delegates.
- Produces `DriverStep`:
  - `__construct(Onboarding $onboarding, BudgetedSystem $system, callable $self_test /* (): array */, callable $setup_checks /* (): array */, callable $clock)`;
  - `tick(array $op): array{outcome:string, phase:string, steps:list<string>, error:?string, hint:?string, report:array, next_delay:?int, long:bool, attempts:array<string,int>, busy_retries:int, cooldown_retries:int}`. A `next_delay` of null means terminal.
  - Constants:

    | Constant | Value | Why |
    |---|---|---|
    | `TICK_BUDGET` | 20 | equals `CopyRunner::ACTION_TIME_BUDGET` |
    | `MAX_LONG_ATTEMPTS` | 3 | |
    | `BUSY_RETRY_DELAY` | 60 | |
    | `MAX_BUSY_RETRIES` | 17 | 900 / 60 + 2 |
    | `COOLDOWN_RETRY_DELAY` | 65 | |
    | `MAX_COOLDOWN_RETRIES` | 3 | |

- Produces `AsyncDriver`:
  - constants `HOOK = 'supcheckout_onboarding_drive'`, `WATCHDOG_HOOK = 'supcheckout_onboarding_watchdog'`, `GROUP = 'supcheckout-onboarding'`, `WATCHDOG_DELAY = 300`, `LONG_WATCHDOG_DELAY = Quiesce::TTL + 60` (960), `RUNNER_STALL_SECONDS = 60`;
  - `static register(): void` adds `HOOK` → `handle` (1 arg) and `WATCHDOG_HOOK` → `watchdog` (0 args), only when `wp_doing_cron() || wp_doing_ajax() || (defined('WP_CLI') && WP_CLI)`;
  - `static enqueue(string $op_id, int $delay = 0): bool`, through `as_schedule_single_action(time() + $delay, HOOK, array($op_id), GROUP, true)`, behind `ActionSchedulerBridge::is_ready()`;
  - `static handle(string $op_id): void`:
    - it returns at once unless `Operation::current()` is running with that ID;
    - it builds a `BudgetedWordPressSystem` with deadline now + 20;
    - it schedules `WATCHDOG_HOOK` (unique) at `LONG_WATCHDOG_DELAY` before a long phase, else at `WATCHDOG_DELAY`;
    - it calls `wc_set_time_limit(0)` (if the function exists) before a long phase;
    - it persists the tick through `Operation::update()` / `finish()`, and queues the continuation with `register_shutdown_function(enqueue, $op_id, next_delay)`, because the running action still counts as unique, as in `CopyRunner::handle_action()`;
    - a `\Throwable` finishes the operation `failed` with error `error`, `report.exception` set to the class name only, and the hint `restart the operation; every step resumes from the saved state`;
  - `static watchdog(): bool` re-enqueues a running operation whose `next_at <= now` when no `HOOK` action is pending or running;
  - `static runner_health(?array $op, int $now): array{stalled:bool, past_due_seconds:int}`, where `stalled` means running and `now - next_at > 60`.

**Decision table:** `DriverStep::tick` re-reads `TakeoverState` first.

| Kind / phase | State | Action | Result |
|---|---|---|---|
| switch / `preflight` | any | `plan_run(consent)` | STATE / OFFICIAL_STILL_ACTIVE → `failed` / `blocked` with that reason. `done` → `done`. `cutover_tail` → `cutover(true)` → `done`. `run` → `check_preflight()`: a blocking `credential_unverified` with `data.reason === 'cooldown'` → retry in 65 s (at most 3), then `blocked`. Other blocking → `blocked` with `preflight_blocking` and the bounded report. OK → `start_copy()`, phase `copy`, next 0. |
| switch / `copy` | copying | `copy_step()` until it is not `continue` or 20 s pass | `continue` → next 0. `busy` / `retry` → next 60. |
| switch / `copy` | not copying | `copy_result()` | verified → phase `retire`, next 0. Mismatch → `failed` `verify_mismatch` with the mismatches. |
| switch / `retire` | verified | `retire_official(consent, 'switch')` | Deactivated → phase `cutover`, **next 0 (fresh process)**. Already inactive → continue to `cutover` in the same tick. No consent → `blocked` `official_active` (state stays `verified`). Failure → `failed` `deactivation_failed`. |
| switch / `cutover` (long) | verified | `attempts.cutover++`; above 3 → `failed` `driver_exhausted`, hint `run "wp supcheckout takeover run --deactivate-official" on the server`. Then `cutover(consent)`. | OK → phase `self_test`, next 0. `busy` / `lease_lost` / `claims_in_flight` / `transition_refused` → undo the attempt increment for `busy`, `busy_retries++` (above 17 → `failed` `busy`), next 60. `stale_claims` → `failed`, hint `release stale claims (POST /onboarding/stale-claims/release), then start the switch again`. `official_active` → phase `retire`, next 0. Mismatch → `failed`. |
| switch / any | cut_over (moved by the CLI, or a crash after the swap) | | phase `self_test` |
| switch / `self_test` | cut_over | `$self_test()`; a `provider_status` warn with `cooldown` → retry once in 65 s | `done`, `self_test` stored, warning `self_test_failed` when `ok` is false |
| rollback / `guard` | | `plan_rewind('rollback')`, then, when `data.retire_official`, `retire_official(consent, 'rollback')`; a deactivation → next 0 (fresh process) | refusals → `failed` / `blocked` |
| rollback, abort / `rewind` (long only while the state is still `cut_over` / `verified`) | | `rewind(mode, consent.reactivate_official, $system)` | `yielded` → next `max(0, resume_at - now)`. BUSY reasons → busy retry as above. OK → `done`. CONFLICTS → `done` + warning `conflicts`, with the conflicts in the report. OFFICIAL_NOT_REACTIVATED → `done` + warning `official_not_reactivated`. Other → `failed` with the reason. |
| finalize | | `plan_finalize(force)`, then `finalize(force, $system)` (long until `finalized`) | as for rewind; CONFLICTS → `done` + warning `kept` |
| finalize_dry_run (long) | | `finalize_plan()` | `done` with the bounded plan |
| preflight / self_test / setup_checks | | run it once; a cooldown retries as above (at most 3) | `done`; the report is also stored in `supcheckout_onboarding_last_<kind>_v1` (non-autoloaded, bounded) |
| any | `invalid`, or a state the kind cannot continue from | | `failed` `state_changed` |

- [ ] **Step 1: Write the failing tests.**

```php
public function test_switch_preflight_blocking_finishes_blocked_with_the_report(): void { /* outcome 'blocked', error 'preflight_blocking', next_delay null, report blocking[0].code 'callback_unreachable' */ }
public function test_switch_retries_a_credential_cooldown_three_times_then_blocks(): void {
    // Review Focus 2
    self::assertSame(array(65, 65, 65, null), array_column($this->ticks(4), 'next_delay'));
    self::assertSame('blocked', $this->last['outcome']);
}
public function test_switch_copies_within_the_tick_budget_then_continues_immediately(): void { /* clock passes 20 s mid-copy: next_delay 0, phase 'copy' */ }
public function test_switch_deactivates_the_official_plugin_then_yields_before_cutover(): void {
    /* consent true, official active: system deactivated it, phase 'cutover', next_delay 0, TakeoverState still 'verified' (Cutover did not run in this tick) */
}
public function test_switch_without_consent_is_blocked_official_active_and_stays_verified(): void { /* outcome blocked, error official_active, state verified */ }
public function test_a_lost_lease_retries_every_60_seconds_17_times_then_fails_busy(): void { /* Review Focus 3: 17 × next_delay 60, then failed 'busy' */ }
public function test_a_cutover_reentered_after_a_killed_tick_fails_driver_exhausted_after_3(): void {
    /* op attempts['cutover'] === 3 and state verified: outcome failed, error driver_exhausted, hint contains 'wp supcheckout takeover run' */
}
public function test_stale_claims_fail_with_the_release_hint(): void { /* hint contains 'stale-claims/release' */ }
public function test_a_cut_over_store_runs_the_self_test_and_finishes_done(): void { /* phase self_test then outcome done, report self_test checks[8].result 'deferred' */ }
public function test_a_state_moved_by_the_cli_is_followed(): void { /* phase 'copy', state set to cut_over externally: next phase self_test */ }
public function test_rollback_yields_until_the_settle_deadline_then_completes(): void { /* next_delay === 120, then done */ }
public function test_rollback_conflicts_finish_done_with_the_conflicts_warning(): void { /* outcome done, report warnings ['conflicts'] */ }
public function test_finalize_too_early_without_force_fails_state_without_a_lease(): void { /* outcome failed, error too_early; Quiesce never acquired */ }
public function test_network_active_official_blocks_the_switch(): void { /* preflight official_network_active: blocked */ }
// AsyncDriverTest
public function test_register_adds_both_hooks_only_in_cron_ajax_or_cli_contexts(): void { /* web: no calls; DOING_CRON: [HOOK=>handle, WATCHDOG_HOOK=>watchdog] */ }
public function test_enqueue_is_unique_in_the_onboarding_group(): void { /* recorded as_schedule_single_action: hook, array($id), 'supcheckout-onboarding', unique true */ }
public function test_a_stale_action_for_another_operation_does_nothing(): void {}
public function test_watchdog_requeues_only_a_running_operation_without_a_pending_action(): void {}
public function test_a_long_phase_schedules_the_dead_mans_watchdog_at_960_seconds(): void {}
public function test_runner_health_reports_stalled_after_60_seconds_past_due(): void {
    // Review Focus 1
    self::assertSame(array('stalled' => true, 'past_due_seconds' => 61), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 1000), 1061));
    self::assertSame(array('stalled' => false, 'past_due_seconds' => 60), AsyncDriver::runner_health(array('outcome' => 'running', 'next_at' => 1000), 1060));
}
```

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "DriverStepTest|AsyncDriverTest"`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement** the three classes per the decision table, and wire `Plugin.php`.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then `php tests/harness/public-surface-snapshot-harness.php --record` (reason in the commit) and run all gates.
- [ ] **Step 5: Commit.** `feat(onboarding): Action Scheduler driver for switch, rollback, abort, finalize and checks`

---

### Task 7: Admin REST API `supcheckout/v1`

**Files:**
- Create: `src/Rest/Routes.php`, `RequestGuard.php`, `Responses.php`, `OnboardingController.php`, `SetupController.php`
- Modify: `src/Plugin.php`. Add `Routes::register();` and re-record the public surface with the reason "0c Task 7: rest_api_init for the supcheckout/v1 admin routes".
- Create: `tests/support/wordpress-rest-stubs.php`: minimal `WP_REST_Request` (`get_param`, `get_params`, `get_json_params`, `get_body_params`, `get_query_params`, `get_header`), `WP_REST_Response` (`header`, `get_headers`, `get_status`, `get_data`), `WP_Error` if absent, a `register_rest_route` recorder, `rest_authorization_required_code`, and `rest_sanitize_boolean`.
- Test: `tests/unit/Rest/RoutesTest.php`, `RequestGuardTest.php`, `ResponsesTest.php`, `OnboardingControllerTest.php`

**Routes** (`Routes::NAMESPACE = 'supcheckout/v1'`):

| Method | Route | Paused | Args (JSON schema, all `default` false unless shown) | Handler result | Rate bucket |
|---|---|---|---|---|---|
| GET | `/onboarding/status` | yes | none | 200 `{takeover: Bounded(Onboarding::status()), operation: Bounded(op)\|null, runner: AsyncDriver::runner_health(), last: {preflight_at, self_test_at, setup_checks_at}}`; runs `AsyncDriver::watchdog()` | none |
| GET | `/onboarding/preflight` | yes | none | 200 last stored report or `null` | none |
| POST | `/onboarding/preflight` | yes | none | 202 operation `preflight` | `provider_probe` 65 s |
| POST | `/onboarding/switch` | yes | `confirm` boolean **required**, must be true; `deactivate_official` boolean | 202 operation `switch` / 200 `already_cut_over` / Outcome error | `switch` 10 s |
| POST | `/onboarding/rollback` | yes | `confirm` (required true), `deactivate_official`, `reactivate_official` | 202 `rollback` | `rollback` 10 s |
| POST | `/onboarding/abort` | yes | `confirm` (required true), `reactivate_official` | 202 `abort` | `abort` 10 s |
| POST | `/onboarding/finalize` | yes | `dry_run` boolean; `force_after_review` boolean; `confirm` string `enum: ["FINALIZE"]`, required unless `dry_run` | 202 `finalize` / `finalize_dry_run` | `finalize` 10 s |
| POST | `/onboarding/stale-claims/release` | yes | `confirm` (required true) | 200 inline (`Onboarding::release_stale_claims()`, at most 500 rows, under the lease) | `stale_claims` 10 s |
| GET | `/onboarding/self-test` | no | none | 200 last report or `null` | none |
| POST | `/onboarding/self-test` | no | none | 202 `self_test`; 409 `supcheckout_state` unless `cut_over` / `finalized` | `provider_probe` 65 s |
| GET | `/setup/checks` | no | none | 200 last report or `null` | none |
| POST | `/setup/checks` | no | none | 202 `setup_checks` | `provider_probe` 65 s |

**Request guard order** (a rejected request never spends a rate slot or creates an operation):
1. capability `manage_woocommerce`: `permission_callback`, a WP_Error with status `rest_authorization_required_code()` (401 logged out, 403 logged in);
2. writes: nonce `wp_verify_nonce($request->get_header('x_wp_nonce'), 'wp_rest')` → 403 `supcheckout_nonce_invalid`;
3. unknown parameters. The keys of JSON, body and query parameters, minus the route's args, minus WordPress reserved keys (`_wpnonce`, `_locale`, `rest_route`, `_method`, `_envelope`, `_fields`, `_embed`) → 400 `supcheckout_unknown_parameter` with `data.params`;
4. schema validation (WordPress core) → 400 `rest_invalid_param`;
5. consent: `confirm` must be strictly true, or `"FINALIZE"` → 400 `supcheckout_confirmation_required`;
6. rate: `RateLimiter::hit()` > 0 → 429 `supcheckout_rate_limited`, with `data.retry_after` and the `Retry-After` header;
7. the cheap synchronous plan (`plan_run` / `plan_rewind` / `plan_finalize`) → its `Outcome`, mapped below;
8. `Operation::start()` null → 409 `supcheckout_operation_in_progress` with `data.operation_id`;
9. `AsyncDriver::enqueue()` → 202 `{ok: true, data: {operation}}` with `Location: /wp-json/supcheckout/v1/onboarding/status`.

**`Responses` mapping** of `Outcome::code()`:

| Code | HTTP response |
|---|---|
| 0 | 200, or 202 for a started operation |
| 1 | 500 `supcheckout_error` |
| 2 | 409 `supcheckout_blocked` |
| 3 | 409 `supcheckout_mismatch` |
| 4 | 409 `supcheckout_busy` |
| 5 | 409 `supcheckout_stale_claims` |
| 6 | 409 `supcheckout_state` |
| 7 | 409 `supcheckout_official_still_active` |
| 8 | 200 with `warnings: ['conflicts']` |
| 9 | 200 with `warnings: ['official_not_reactivated']` |

- An error's `data` is `{status, exit_code, reason, hint?, days_left?}`.
- **Success envelope:** `{ok: true, data, warnings: []}`.
- Every response, error or success, carries `Cache-Control: no-store, private`.

**Interfaces:**
- Produces:
  - `Routes::register(): void`, which adds `rest_api_init` → `[Routes::class, 'register_all']`;
  - `Routes::register_all(): void` and `Routes::register_takeover(): void`, the `rest_api_init` callbacks. Task 8's PausedBoot adds `register_takeover` itself, so the paused boot loads no extra class;
  - `Routes::table(bool $paused): list<array{method:string, route:string, write:bool, bucket:?string, args:array}>`;
  - `RequestGuard::can_manage(): true|WP_Error`;
  - `RequestGuard::check_write(WP_REST_Request $request, list<string> $allowed): true|WP_Error`, for steps 2 and 3;
  - `RequestGuard::check_consent(WP_REST_Request $request, string $route): true|WP_Error`;
  - `RequestGuard::check_rate(string $bucket, int $window): true|WP_Error`;
  - `Responses::ok(array $data, int $status = 200, array $warnings = []): WP_REST_Response`;
  - `Responses::error(string $code, string $message, int $status, array $data = []): WP_Error`;
  - `Responses::from_outcome(Outcome $outcome, array $extra = []): WP_REST_Response|WP_Error`.

- [ ] **Step 1: Write the failing tests.**

```php
public function test_paused_registers_exactly_the_takeover_routes(): void {
    self::assertSame(array('GET /onboarding/status', 'GET /onboarding/preflight', 'POST /onboarding/preflight', 'POST /onboarding/switch', 'POST /onboarding/rollback', 'POST /onboarding/abort', 'POST /onboarding/finalize', 'POST /onboarding/stale-claims/release'), self::keys(Routes::table(true)));
}
public function test_full_boot_adds_self_test_and_setup_checks(): void { /* table(false) keys === paused keys + ['GET /onboarding/self-test', 'POST /onboarding/self-test', 'GET /setup/checks', 'POST /setup/checks'] */ }
public function test_every_route_requires_manage_woocommerce(): void { /* each registered permission_callback with the capability denied: WP_Error status 403 (logged in) and 401 (logged out) */ }
public function test_write_without_a_valid_nonce_is_403_nonce_invalid(): void {}
public function test_capability_is_checked_before_the_nonce(): void { /* no capability and no nonce: 403 rest-authorization code, not nonce_invalid */ }
public function test_unknown_parameter_is_rejected_400(): void { /* Review Focus 5: {"confirm":true,"deactivateOfficial":true} → supcheckout_unknown_parameter, data params ['deactivateOfficial'] */ }
public function test_switch_requires_confirm_true(): void { /* confirm omitted or false → 400 supcheckout_confirmation_required; no operation created */ }
public function test_switch_string_false_consent_never_deactivates(): void { /* Review Focus 5: deactivate_official "false" → operation consent deactivate_official false */ }
public function test_a_second_start_is_429_then_another_kind_is_409(): void { /* Review Focus 4: switch twice within 10 s → 429 retry_after 10; abort → 409 supcheckout_operation_in_progress with operation_id */ }
public function test_finalize_requires_the_typed_FINALIZE_unless_dry_run(): void { /* {} → 400; {"dry_run":true} → 202 finalize_dry_run */ }
public function test_finalize_before_30_days_without_force_is_409_state_with_days_left(): void { /* code supcheckout_state, data reason too_early, days_left 30, exit_code 6 */ }
/** @dataProvider outcome_codes */
public function test_outcomes_map_to_http(int $code, int $status, string $error_code): void {}
public function test_every_response_is_no_store(): void { /* Cache-Control === 'no-store, private' on 200, 202, 409 and 429 */ }
public function test_status_never_contains_the_api_key(): void { /* settings api_key 'sk-test-SECRET-0c' seeded: strpos(wp_json_encode(response data), 'sk-test-SECRET-0c') === false */ }
public function test_status_runs_the_watchdog(): void { /* running op with next_at in the past, no pending action → one as_schedule_single_action recorded */ }
public function test_status_body_is_at_most_32768_bytes(): void {}
```

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter "RoutesTest|RequestGuardTest|ResponsesTest|OnboardingControllerTest"`. Expected: FAIL, classes not found.
- [ ] **Step 3: Implement** the five classes. The controllers construct `Onboarding::from_wordpress()`; no controller method calls an engine except through `Onboarding`, `Operation` and `AsyncDriver`.
- [ ] **Step 4: Run** the same filter. Expected: PASS. Then `php tests/harness/public-surface-snapshot-harness.php --record`, `php tests/harness/security-threat-model-harness.php`, `php tests/harness/architecture-dependency-boundaries-harness.php` and all gates.
- [ ] **Step 5: Commit.** `feat(rest): admin-only supcheckout/v1 onboarding and setup routes`

---

### Task 8: Paused boot exposes only the takeover routes and the driver hooks

**Files:**
- Modify: `src/Takeover/PausedBoot.php` (and its docblock). Add `add_action('rest_api_init', array(Routes::class, 'register_takeover'))`. The `::class` constant loads nothing, so a plain paused web request loads no extra file. Then, only when `wp_doing_cron() || wp_doing_ajax() || WP_CLI`, add the two `AsyncDriver` hooks, as `AsyncDriver::register()` does. Replace the "0c onboarding/preflight admin entry point" bullet with "the takeover REST routes; the admin screens come in Sub-project 2".
- Modify: `supcheckout.php`. Comment only: "Paused, only the takeover entry points boot (CLI, the takeover REST routes and the onboarding driver): never a gateway, payment route or payment cron hook."
- Modify: `tests/unit/Takeover/PausedBootTest.php`, `tests/harness/official-plugin-coexistence-harness.php`, `tests/fixtures/public-surface.json` (re-record, reason "0c Task 8: paused takeover REST and driver surface") and `tests/integration/TakeoverCliTest.php` (paused own-hooks assertion, line 142)

**Interfaces:**
- Consumes: `Routes::register_takeover` (Task 7), `AsyncDriver::HOOK` / `WATCHDOG_HOOK` / `handle` / `watchdog` (Task 6).

- [ ] **Step 1: Write the failing tests.**

```php
// PausedBootTest
public function test_a_web_request_registers_the_declarations_and_the_takeover_routes_only(): void {
    PausedBoot::boot(ABSPATH . 'supcheckout.php');
    self::assertSame(array(self::feature_declarations(), array('action', 'rest_api_init', array(Routes::class, 'register_takeover'), 10, 1)), $GLOBALS['supcheckout_test_hook_calls']);
}
public function test_a_cron_request_also_registers_the_two_driver_hooks(): void {
    /* DOING_CRON: + array('action', 'supcheckout_onboarding_drive', array(AsyncDriver::class, 'handle'), 10, 1), array('action', 'supcheckout_onboarding_watchdog', array(AsyncDriver::class, 'watchdog'), 10, 0) */
}
public function test_paused_rest_routes_never_include_self_test_or_setup_checks(): void { /* register_takeover(): recorded routes === Routes::table(true) */ }
```

Coexistence harness:
- web paused scenarios: `OTHER_HOOKS:[<declarations>,"rest_api_init=Simplixi\\SUPCheckout\\Rest\\Routes::register_takeover"]`, with `SRC_FILES` unchanged;
- `paused_cli`: also `supcheckout_onboarding_drive=…AsyncDriver::handle` and `supcheckout_onboarding_watchdog=…AsyncDriver::watchdog`, with `SRC_FILES` adding `Takeover/Onboarding/AsyncDriver.php`;
- a new `paused_cron` scenario (DOING_CRON): the same hooks as `paused_cli` and `CLI_COMMANDS:[]`;
- a new `paused_rest_init` scenario. It fires `rest_api_init` in the child and asserts:
  - exactly the 8 takeover routes are registered;
  - `GATEWAY_HOOKS:[]`;
  - no `woocommerce_api_*` hook;
  - `COPY_RUNNER_HOOKS:0`;
  - `GLOBALS_ADDED:[]`;
  - `DIAGNOSTICS:[]`.

`TakeoverCliTest` paused phase (WP-CLI process): own hooks `=== array('admin_notices', 'before_woocommerce_init', 'rest_api_init', 'supcheckout_onboarding_drive', 'supcheckout_onboarding_watchdog')`.

- [ ] **Step 2: Run.** `vendor/bin/phpunit --filter PausedBootTest && php tests/harness/official-plugin-coexistence-harness.php`. Expected: FAIL (no `rest_api_init` registration).
- [ ] **Step 3: Implement** the PausedBoot change and the comment.
- [ ] **Step 4: Run** the same commands plus `php tests/harness/public-surface-snapshot-harness.php --record`. Expected: PASS, then all gates. The integration assertion is proven by Task 9's CI run.
- [ ] **Step 5: Commit.** `feat(takeover): paused boot registers only the takeover REST routes and the onboarding driver`

---

### Task 9: Real-WordPress REST and async-driver certification

**Files:**
- Create: `tests/integration/TakeoverRestTest.php`, with phases selected by `SUPCHECKOUT_CERT_PHASE`. REST calls use `rest_do_request()` in-process with `wp_set_current_user(<administrator>)` and the header `X-WP-Nonce: wp_create_nonce('wp_rest')`. Action Scheduler is driven in **fresh processes**, so each tick boots exactly as a real request would (paused or full). That reuses `supcheckout_takeover_cert_wp()`, moved to `tests/integration/lib/takeover-cert.php` and required by both tests: `action-scheduler run --group=supcheckout-onboarding --batch-size=1 --batches=1 --force`, looped at most 60 times with a 3 s pause, until the operation is not `running`.
- Modify: `.github/workflows/compatibility-certification.yml`. Insert the steps `rest_paused`, `rest_switched` and `rest_rollback` after "Abort a verified copy, then roll back with a later edit (exit 8)" and before "Cut over again and finalize". Add `rest_sweep` after "Verify the finalized store". All 20 cells run them.

**Phases and assertions:**

`rest_paused`. The official stand-in is active, SUPCheckout is paused, and the state is `preflight_ok`.
- **Routes and access:**
  - the `/supcheckout/v1` routes are exactly the 8 takeover routes;
  - logged out, status → 401; a `customer` user → 403;
  - an administrator without the nonce, POST switch → 403 `supcheckout_nonce_invalid`;
  - `{"confirm":true,"deactivateOfficial":true}` → 400 `supcheckout_unknown_parameter`.
- **Preflight:** POST preflight → 202; driven to `done`; `GET /onboarding/preflight` `blocking === []` (the takeover HTTP mu-plugin answers both probes).
- **Switch:**
  - order statuses are snapshotted first;
  - POST switch `{"confirm":true,"deactivate_official":true}` → 202;
  - POST abort `{"confirm":true}` → 409 `supcheckout_operation_in_progress`;
  - driven to `done`;
  - `TakeoverState` is `cut_over`, the stand-in is inactive, and `op.steps` contains `cut_over` and `caches_flushed`.
- **Self-test results:** `op.self_test.checks` has `provider_status` = `pass` (test mode on, the mu-plugin answers), `event_journal` = `deferred` and `gateway_registered` = `pass`.
- **Invariants:**
  - order statuses equal the snapshot (no payment state touched);
  - `wp_json_encode` of every response body never contains the stored API key value (`takeover-cli-certification-key-…`).

`rest_switched`. A fresh process, so the full boot runs.
- The routes add self-test and setup checks.
- POST self-test → `done`.
- POST setup/checks → `done`, with `test_payment` = `deferred` and `multilingual` present.
- **Finalize:**
  - POST finalize `{"dry_run":true}` → `done`, with `report.would_refuse === ['too_early']`;
  - POST finalize `{"confirm":"FINALIZE"}` → 409 `supcheckout_state` with `days_left` 30.

`rest_rollback`.
- POST rollback `{"confirm":true,"reactivate_official":true}` → 202.
- Driven to `done`. The settle barrier is a scheduled resume, about 120 s, inside the 60 × 3 s loop plus the run time. `op.steps` contains `yielded` and `preflight_ok`.
- The stand-in is active again and the state is `preflight_ok`, which is exactly what the existing "Cut over again and finalize" step requires (its first assertion).

`rest_sweep` (finalized store).
- POST finalize `{"confirm":"FINALIZE"}` → driven to `done`. `op.steps` contains `sweep` and `legacy_removed`.
- POST rollback `{"confirm":true}` → 409 `supcheckout_state`.

- [ ] **Step 1: Write `TakeoverRestTest.php` and the workflow steps.** Run locally against a disposable WordPress if one is available. Otherwise push the branch and run the Compatibility workflow. Expected before Tasks 6-8 are merged: FAIL on the first route assertion. After them: PASS.
- [ ] **Step 2: Verify on CI.** All 20 Compatibility cells and the Compatibility Gate are green at the exact head. The new steps appear in every cell's log, each ending with the test's completion line.
- [ ] **Step 3: Commit.** `test(onboarding): real-WordPress REST switch, rollback, checks and finalize sweep through Action Scheduler`

---

### Task 10: Living documents, package record and the independent security review

**Files:**
- Modify: `docs/COMPATIBILITY.md`. Add a new "Onboarding API boundary (Sub-project 0c)" section covering:
  - the admin-only `supcheckout/v1` routes and their capability and nonce rules;
  - the async execution through Action Scheduler, which needs WP-Cron, a system cron or admin page loads; otherwise status reports `runner.stalled`;
  - the purge evidence: LiteSpeed Cache 7.9.1 and W3TC 2.10.6 runtime-verified in E3; WP Rocket and WP Super Cache unit-verified only; the Cloudflare plugin manual;
  - WCML per-currency settings, report-only.
- Modify:
  - `docs/project/START-HERE.md` and `docs/project/PROJECT-STATUS.md`: 0c is "onboarding backend (services + REST)" and the wizard UI moves to Sub-project 2, matching the owner decision of 2026-10-18;
  - `docs/project/RELEASE-ENGINEERING.md`: the file count goes from 114 to 131, with the new SHA-256 from `scripts/build-release.sh` at the final head;
  - `docs/superpowers/plans/2026-10-16-subproject-0b-takeover-engine.md`: append one line under "Deferred to 0c" pointing to this plan, without rewriting history.
- No AGENTS.md change: no protected identity, permanent control or main-file rule changes.

- [ ] **Step 1: Update the documents.**
- [ ] **Step 2: Build and verify the package.** `bash scripts/build-release.sh && bash scripts/verify-release.sh`. Expected: verification passes with 131 files. Record the SHA-256.
- [ ] **Step 3: Independent security review** of the whole branch diff, by the `ecc:security-reviewer` agent (AGENTS.md: an independent review closes each sub-project). Its focus:
  - REST auth, nonce and capability on every route, including the 401/403 split;
  - CSRF on every write;
  - unknown-parameter and consent handling;
  - rate-limit bypass, such as the bucket per site rather than per user, and races;
  - no secrets in responses, operation records or stored reports;
  - the multisite network-active block;
  - the minimal paused surface;
  - the driver's crash, retry and attempt bounds;
  - `CachePurger` never fatal.

  Every CRITICAL or HIGH finding is fixed test-first before merge, and the findings and fixes go in the PR body.
- [ ] **Step 4: Run the exact-head gates.**
  - Quality/H12;
  - Compatibility, all 20 cells plus the Gate;
  - Ecosystem Certification, including the E3 purge step;
  - Takeover Migration Matrix;
  - Release Artifact, packaged and migration;
  - WordPress.org Submission Check: the packaged Plugin Check covers the new REST files (no `__return_true` permission callback, no missing `permission_callback`);
  - CodeQL;
  - the locked dependency audit.

  All must be green. Otherwise: NOT APPROVED, DO NOT MERGE.
- [ ] **Step 5: Commit.** `docs(status): record Sub-project 0c onboarding backend and its API boundary`

**0c exit gate:**
- All gates green on `main`.
- Owner staging review on the My Organic World copy:
  - start a switch and a rollback through the REST API (for example from the browser console with the REST nonce), and see the automatic LiteSpeed purge take effect (rehearsal follow-up 2);
  - confirm WCML per-currency checkout visibility against the `multilingual` check (follow-up 3).

## Open questions for the owner

1. **Writes require the cookie REST nonce,** so Application-Password automation cannot start a switch, rollback or finalize over REST. Automation uses WP-CLI. Is that acceptable?
2. **Paused-mode exception:** in cron, AJAX and WP-CLI contexts, the paused boot registers the two `supcheckout_onboarding_*` Action Scheduler hooks. Without them, a switch cannot run in the background while the official plugin is active. Do you accept this addition to the paused surface?
3. **The self-test makes no provider call in production mode.** Preflight already verified the live key before cutover. Confirm?
4. **The Cloudflare plugin purge is reported as manual,** because it has no public purge API. Breeze and SiteGround Optimizer get no purge in 0c. Is that acceptable?
