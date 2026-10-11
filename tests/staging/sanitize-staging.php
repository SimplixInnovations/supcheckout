<?php
/**
 * Sanitize a STAGING copy of a production store. WP-CLI only.
 *
 * Dry run (changes nothing, prints what would change):
 *   SUPCHECKOUT_PRODUCTION_HOST=myorganicworld.net \
 *     wp eval-file sanitize-staging.php
 *
 * Apply (requires the staging host typed back exactly):
 *   SUPCHECKOUT_PRODUCTION_HOST=myorganicworld.net \
 *   SUPCHECKOUT_SANITIZE=apply SUPCHECKOUT_SANITIZE_CONFIRM=<staging host> \
 *     wp eval-file sanitize-staging.php
 *
 * Refuses unless WP_ENVIRONMENT_TYPE is staging, SUPCHECKOUT_STAGING_GUARD is true and the
 * site's home host differs from SUPCHECKOUT_PRODUCTION_HOST. Take a backup of the staging
 * copy first: the changes cannot be undone.
 */

defined('ABSPATH') || exit;

if (!defined('WP_CLI') || !WP_CLI) {
    exit('Run with WP-CLI: wp eval-file sanitize-staging.php' . PHP_EOL);
}

require_once __DIR__ . '/lib/staging-sanitizer.php';

$home_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
$preflight = supcheckout_sanitizer_preflight(
    wp_get_environment_type(),
    defined('SUPCHECKOUT_STAGING_GUARD') ? SUPCHECKOUT_STAGING_GUARD : null,
    $home_host,
    (string) getenv('SUPCHECKOUT_PRODUCTION_HOST')
);
if (!$preflight['ok']) {
    WP_CLI::error('REFUSED: ' . $preflight['reason']);
}

$apply = 'apply' === getenv('SUPCHECKOUT_SANITIZE');
if ($apply && strtolower((string) getenv('SUPCHECKOUT_SANITIZE_CONFIRM')) !== strtolower($home_host)) {
    WP_CLI::error('REFUSED: set SUPCHECKOUT_SANITIZE_CONFIRM=' . $home_host . ' to confirm this exact site');
}

WP_CLI::log(($apply ? 'APPLYING' : 'DRY RUN') . ' on ' . $preflight['reason']);
foreach (supcheckout_sanitizer_run($apply) as $step => $count) {
    WP_CLI::log(sprintf('%-28s %s', $step, $count));
}
WP_CLI::success($apply ? 'Sanitized. Re-run without SUPCHECKOUT_SANITIZE to confirm nothing personal remains.' : 'Dry run only; nothing changed.');
