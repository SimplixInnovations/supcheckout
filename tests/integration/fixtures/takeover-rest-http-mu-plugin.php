<?php
/**
 * Certification-only must-use plugin for the real-HTTP REST header checks
 * (tests/integration/takeover-rest-http.sh, TakeoverRestTest.php phase rest_http). Never
 * shipped; installed only for that step and removed by it.
 *
 * - WP-Cron never spawns while the step's nginx + PHP-FPM stack is up: a spawned wp-cron.php
 *   would run Action Scheduler's queue inside PHP-FPM's 12-second limit. Every onboarding
 *   tick in this certification runs in a fresh `wp action-scheduler run` process instead.
 * - Application Passwords are available over this plain-HTTP loopback host and every
 *   request is an API request, so the step can prove the owner decision that an Application
 *   Password alone, without the `wp_rest` nonce, can never start a switch. On a production
 *   host WordPress makes them available over HTTPS.
 */

defined('ABSPATH') || exit;

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

add_filter('wp_is_application_passwords_available', '__return_true', PHP_INT_MAX);
add_filter('application_password_is_api_request', '__return_true', PHP_INT_MAX);
