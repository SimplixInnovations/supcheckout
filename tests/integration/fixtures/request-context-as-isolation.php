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
