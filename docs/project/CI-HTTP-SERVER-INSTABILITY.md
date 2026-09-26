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

Dedicated Action Scheduler certification is unchanged
(`ActionSchedulerCompatibilityRuntimeTest`, R3/R4/large-store).
