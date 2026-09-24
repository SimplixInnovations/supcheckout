<?php
/**
 * Reset CycleClaim journal schema for concurrency certification.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/Subscription/Cron/CycleClaim.php';

use UPayments\Subscription\Cron\CycleClaim;

$table = CycleClaim::table_name();
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
$wpdb->query("DROP TABLE IF EXISTS {$table}");
delete_option(CycleClaim::OPTION_KEY);

if (!CycleClaim::maybe_install()) {
    throw new RuntimeException('FAIL: CycleClaim schema reset install failed');
}
if (!CycleClaim::schema_ready()) {
    throw new RuntimeException('FAIL: CycleClaim schema reset not ready');
}

echo "PASS: CycleClaim schema reset ready\n";
