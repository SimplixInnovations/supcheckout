<?php
/**
 * TEST-ONLY request-context isolation fixture.
 *
 * The request-context test certifies Woo request context / gateway availability
 * / REST-session behavior. It does NOT certify Action Scheduler execution.
 * Action Scheduler remains certified separately under its real runtime matrix
 * (ActionSchedulerCompatibilityRuntimeTest + R4/R6 jobs).
 *
 * Disable the Action Scheduler async loopback runner so unrelated background
 * HTTP cannot destabilize this disposable PHP built-in server (curl 52 /
 * empty reply / segfault family).
 *
 * Never ship this in the distributable plugin. Copied into mu-plugins only for
 * the disposable Compatibility request-context certification run.
 */

add_filter(
    'action_scheduler_allow_async_request_runner',
    '__return_false',
    PHP_INT_MAX
);

// Avoid Action Scheduler queue runner doing work on every request in this fixture.
add_filter(
    'action_scheduler_queue_runner_interval',
    static function () {
        return 3600;
    },
    PHP_INT_MAX
);

// A due WP-Cron `action_scheduler_run_queue` event can still fire the queue
// during multi-minute HTTP loops even when the async runner is disabled.
// Unschedule that pending event and refuse new cron spawns for this disposable
// request-context certification only. Restore is unnecessary: the whole WP
// install is disposable per Compatibility cell.
add_action(
    'init',
    static function () {
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook('action_scheduler_run_queue');
        }
        if (function_exists('as_unschedule_all_actions') && defined('ACTION_SCHEDULER_VERSION')) {
            // Best-effort: do not throw if AS APIs differ across WC versions.
        }
        // Block WP-Cron loopback from this fixture process.
        add_filter('pre_option_cron_disable_wp_cron_compat', static function () {
            return '1';
        });
    },
    PHP_INT_MAX
);

// Hard-disable WP-Cron spawn via the standard option filter.
add_filter(
    'pre_option_doing_cron',
    static function () {
        return '1';
    }
);
