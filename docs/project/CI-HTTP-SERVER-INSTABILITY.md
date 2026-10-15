# PHP built-in development-server certification instability

## Historical defect

During request-context HTTP certification on GitHub Actions:

- PHP 8.2.34 / 8.4.x `php -S` (with and without `PHP_CLI_SERVER_WORKERS`)
- WordPress 7.1 / WooCommerce 11.1.0 / legacy + HPOS
- Failure: `curl 52 Empty reply from server`
- Server log: `Segmentation fault (core dumped)`
- Reproduced on pass 4 after passes 1–3 succeeded; also seen earlier on PHP 8.4

## Isolation attempted (not sufficient alone)

- `action_scheduler_allow_async_request_runner` = false (test-only mu-plugin)
- `action_scheduler_queue_runner_interval` stretched
- `action_scheduler_run_queue` unscheduled
- `DISABLE_WP_CRON=1`
- `PHP_CLI_SERVER_WORKERS=4` + process-group cleanup

Action Scheduler / WP-Cron background activity was isolated as a potential
contributor, but PHP's built-in development server still segfaulted after
isolation.

## Permanent classification

**PHP built-in development-server certification instability** — not a
SUPCheckout production defect.

## Permanent fix

Request-context certification now uses **nginx + PHP-FPM** (production-style
concurrent web runtime) as the authoritative gating HTTP server.

php -S is no longer used on the permanent gate path.

## Root cause: opcache tracing JIT under PHP-FPM (2026-10-15)

nginx + PHP-FPM still failed intermittently: 10 of 416 request-context
stability jobs between 2026-09-26 and 2026-10-15, always in pass 1 or 2 of a
freshly started stack, and every one passed on rerun. Two signatures:

- PHP 8.2: worker `SIGSEGV`, nginx `recv() failed (104)` and a 502.
- PHP 8.4: worker stuck for 12 s or more, then `max_execution_time` and a 502
  (or a 504 when nginx timed out first). The slow log placed it on the first
  line of `rest_sanitize_value_from_schema()`, which can't take seconds to run.

**Configuration.** `shivammathur/setup-php` appends `opcache.enable=1`,
`opcache.jit=1235` (tracing) and `opcache.jit_buffer_size=256M` to
`99-pecl.ini`, then symlinks that file into every SAPI's `conf.d`. So PHP-FPM
ran WordPress under the tracing JIT. WP-CLI never did, because the CLI keeps
`opcache.enable_cli=0`. The `php -S` server (`cli-server` SAPI) honours
`opcache.enable`, so the historical `php -S` segfaults above match this cause,
but that was not re-tested.

**Symbolized cores** (setup-php `debug: true`, same binaries plus
`/usr/lib/debug/.build-id` symbols):

- PHP 8.2.34: `ZEND_INIT_NS_FCALL_BY_NAME_SPEC_CONST_HANDLER`
  (`Zend/zend_vm_execute.h:3757`, `fbc = CACHED_PTR(...)`). The worker read
  an invalid run-time cache while running the WooCommerce trait method
  `ImportScheduler::get_actions()` on `plugins_loaded`.
- PHP 8.4.26: inside the JIT compiler. Live samples of every stuck worker were
  running (`State: R`) in `ir_schedule_topsort` (`ext/opcache/jit/ir/ir_gcm.c`),
  reached from `zend_jit_hot_func` → `zend_real_jit_func` while compiling the
  newly hot `rest_sanitize_value_from_schema`. With low hot thresholds the
  same compile segfaulted in `ir_fix_bb_order` on every cold start.

Native stacks were about 10 frames deep, so stack exhaustion is ruled out.

**A/B.** Run on a throwaway branch, using the same pinned WordPress 7.1.3,
WooCommerce 11.1.2 and setup-php as the gate. Each cold start is a fresh
nginx + PHP-FPM stack running the full request-context test once. 8 jobs per
cell for A, B and C, 4 for D, 2 for the amplifier; 20 cold starts per job.

| Arm | Cold starts | Failures |
| --- | --- | --- |
| A: runner defaults (82 extensions, tracing JIT) | 640 | 9 (7 SIGSEGV, 2 JIT hangs) |
| B: runner defaults, JIT disabled | 640 | 0 |
| C: `-n` + opcache + 18 required extensions, tracing JIT | 640 | 15 (7 SIGSEGV, 8 JIT hangs) |
| D: `-n` + opcache + 18 required extensions, JIT disabled | 320 | 0 |
| JIT with hot thresholds = 1, PHP 8.4 | 80 | 80 SIGSEGV in the JIT compiler |

JIT on against JIT off (A+C against B+D): 24/1,280 against 0/960, one-sided
Fisher exact p ≈ 1.3×10⁻⁶. The minimal extension set did not help (C failed as
often as A), so the PECL extensions the runner preinstalls are not the cause.

**Fix.** `tests/integration/lib/start-ci-http-stack.sh` starts PHP-FPM with
`-d opcache.jit=disable -d opcache.jit_buffer_size=0`. Opcache stays on, as on
a production host. The JIT stays off, which is also PHP's own default. The
readiness probe reports the worker's JIT state, and the stack fails closed
(exit 75) when a worker reports the JIT enabled.
`tests/harness/http-server-diagnostics-harness.sh` guards both. Assertions,
pass counts and hard-fail semantics are unchanged.

Dedicated Action Scheduler certification is unchanged
(`ActionSchedulerCompatibilityRuntimeTest`, R3/R4/large-store).
