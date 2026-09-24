<?php
/**
 * Multi-process CycleClaim worker.
 *
 * Usage:
 *   php cycleclaim-worker.php <mode> <owner_token> [cycle_key] [parent_id] [amount] [currency]
 *
 * Modes:
 *   acquire     — acquire_with_snapshot
 *   reclaim     — reclaim_stale_claimed
 *   dispatch    — mark_dispatching
 *   release     — release_claimed
 *   hold        — mark_held
 *   sentinel    — acquire/reclaim then record a fake provider dispatch if claimed
 *
 * Emits one JSON line on stdout. Never prints credentials.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(64);
}

$mode = $argv[1] ?? '';
$owner = $argv[2] ?? '';
$cycle_key = $argv[3] ?? '';
$parent_id = (int) ($argv[4] ?? 0);
$amount = $argv[5] ?? '10.000';
$currency = $argv[6] ?? 'KWD';

if ($mode === '' || $owner === '' || $cycle_key === '') {
    fwrite(STDERR, "Usage: cycleclaim-worker.php <mode> <owner_token> <cycle_key> [parent_id] [amount] [currency]\n");
    exit(64);
}

$wp_load = getenv('SUPCHECKOUT_WP_LOAD');
if (!is_string($wp_load) || $wp_load === '' || !is_file($wp_load)) {
    fwrite(STDERR, "SUPCHECKOUT_WP_LOAD must point at wp-load.php\n");
    exit(65);
}

require_once $wp_load;
require_once dirname(__DIR__, 2) . '/includes/Subscription/Cron/CycleClaim.php';

use UPayments\Subscription\Cron\CycleClaim;

if (!CycleClaim::maybe_install()) {
    echo json_encode(array('ok' => false, 'mode' => $mode, 'owner' => $owner, 'error' => 'schema_not_ready')) . "\n";
    exit(1);
}

$cycle_due_gmt = gmdate('Y-m-d H:i:s', time() + 3600);
if ($parent_id <= 0) {
    $parent_id = 1;
}

$result = array(
    'ok' => false,
    'mode' => $mode,
    'owner' => $owner,
    'cycle_key' => $cycle_key,
    'parent_id' => $parent_id,
    'acquired' => false,
    'reclaimed' => false,
    'dispatching' => false,
    'released' => false,
    'held' => false,
    'sentinel' => false,
    'snapshot_amount' => null,
    'snapshot_currency' => null,
);

switch ($mode) {
    case 'acquire':
        $result['acquired'] = CycleClaim::acquire_with_snapshot(
            $cycle_key,
            $parent_id,
            $owner,
            $cycle_due_gmt,
            $amount,
            $currency
        );
        $result['ok'] = $result['acquired'];
        break;

    case 'reclaim':
        $result['reclaimed'] = CycleClaim::reclaim_stale_claimed($cycle_key, $parent_id, $owner);
        $result['ok'] = $result['reclaimed'];
        break;

    case 'dispatch':
        $result['dispatching'] = CycleClaim::mark_dispatching($cycle_key, $owner);
        $result['ok'] = $result['dispatching'];
        break;

    case 'release':
        $result['released'] = CycleClaim::release_claimed($cycle_key, $owner);
        $result['ok'] = $result['released'];
        break;

    case 'hold':
        $result['held'] = CycleClaim::mark_held($cycle_key, $owner, null, null);
        $result['ok'] = $result['held'];
        break;

    case 'sentinel':
        // Attempt acquire; if lost, attempt reclaim. Only the durable
        // claimed→dispatching winner may record a provider-dispatch sentinel.
        $result['acquired'] = CycleClaim::acquire_with_snapshot(
            $cycle_key,
            $parent_id,
            $owner,
            $cycle_due_gmt,
            $amount,
            $currency
        );
        if (!$result['acquired']) {
            $result['reclaimed'] = CycleClaim::reclaim_stale_claimed($cycle_key, $parent_id, $owner);
        }
        $won = $result['acquired'] || $result['reclaimed'];
        if ($won) {
            $row = CycleClaim::get($cycle_key);
            $result['snapshot_amount'] = is_array($row) && isset($row['expected_amount']) ? (string) $row['expected_amount'] : null;
            $result['snapshot_currency'] = is_array($row) && isset($row['expected_currency']) ? (string) $row['expected_currency'] : null;
            if (is_array($row) && CycleClaim::has_dispatchable_snapshot($row)) {
                $result['dispatching'] = CycleClaim::mark_dispatching($cycle_key, $owner);
                if ($result['dispatching']) {
                    // Deterministic fake-provider dispatch sentinel.
                    $sentinel_file = getenv('SUPCHECKOUT_DISPATCH_SENTINEL');
                    if (is_string($sentinel_file) && $sentinel_file !== '') {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations -- certification sentinel only
                        file_put_contents(
                            $sentinel_file,
                            json_encode(array(
                                'owner' => $owner,
                                'cycle_key' => $cycle_key,
                                'amount' => $result['snapshot_amount'],
                                'currency' => $result['snapshot_currency'],
                            )) . "\n",
                            FILE_APPEND | LOCK_EX
                        );
                    }
                    $result['sentinel'] = true;
                }
            }
        }
        $result['ok'] = $result['sentinel'];
        break;

    default:
        fwrite(STDERR, "Unknown mode: {$mode}\n");
        exit(64);
}

echo json_encode($result) . "\n";
exit($result['ok'] ? 0 : 2);
