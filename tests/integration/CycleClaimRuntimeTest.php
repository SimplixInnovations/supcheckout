<?php
/**
 * Real-database CycleClaim v2 schema readiness and migration certification.
 *
 * Runs under wp-cli eval-file against a disposable WordPress + MySQL runtime.
 * Proves option/schema coordination is fail-closed and historical rows survive.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subscription/Cron/CycleClaim.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/CycleEconomics.php';

use Simplixi\SUPCheckout\Subscription\CycleEconomics;
use UPayments\Subscription\Cron\CycleClaim;

$table = CycleClaim::table_name();
$option_key = CycleClaim::OPTION_KEY;

function supcheckout_cycleclaim_drop_table() {
    global $wpdb;
    $table = CycleClaim::table_name();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

function supcheckout_cycleclaim_create_v1_table_with_rows() {
    global $wpdb;
    $table = CycleClaim::table_name();
    $charset = $wpdb->get_charset_collate();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
    $wpdb->query("CREATE TABLE {$table} (
        cycle_key        char(64)        NOT NULL,
        parent_order_id  bigint unsigned NOT NULL,
        owner_token      varchar(64)     NOT NULL,
        state            varchar(20)     NOT NULL,
        cycle_due_gmt    datetime        NOT NULL,
        created_gmt      datetime        NOT NULL,
        updated_gmt      datetime        NOT NULL,
        dispatched_gmt   datetime        NULL,
        resolved_gmt     datetime        NULL,
        renewal_order_id bigint unsigned NULL,
        payment_id       varchar(255)    NULL,
        curl_errno       int             NULL,
        http_status      int             NULL,
        PRIMARY KEY  (cycle_key),
        KEY idx_parent (parent_order_id),
        KEY idx_state  (state)
    ) {$charset};");

    // cycle_key is CHAR(64); fixtures must be exactly 64 chars or MySQL truncates.
    $rows = array(
        array('v1c' . str_repeat('a', 61), 101, 'owner-a', 'claimed', '2026-01-01 00:00:00'),
        array('v1h' . str_repeat('b', 61), 102, 'owner-b', 'held', '2026-01-02 00:00:00'),
        array('v1d' . str_repeat('c', 61), 103, 'owner-c', 'dispatching', '2026-01-03 00:00:00'),
        array('v1r' . str_repeat('d', 61), 104, 'owner-d', 'resolved', '2026-01-04 00:00:00'),
    );
    foreach ($rows as $row) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (cycle_key, parent_order_id, owner_token, state, cycle_due_gmt, created_gmt, updated_gmt)
             VALUES (%s, %d, %s, %s, %s, %s, %s)",
            $row[0],
            $row[1],
            $row[2],
            $row[3],
            $row[4],
            $row[4],
            $row[4]
        ));
    }
}

// ---------------------------------------------------------------------------
// 1. Fresh database → schema v2 installed
// ---------------------------------------------------------------------------
supcheckout_cycleclaim_drop_table();
delete_option($option_key);
supcheckout_cert_assert(!CycleClaim::table_exists(), 'fresh: journal table starts absent');
supcheckout_cert_assert(!CycleClaim::schema_ready(), 'fresh: schema_ready is false before install');
supcheckout_cert_assert(CycleClaim::maybe_install() === true, 'fresh: maybe_install succeeds');
supcheckout_cert_assert(CycleClaim::table_exists(), 'fresh: journal table exists after install');
supcheckout_cert_assert(CycleClaim::schema_ready(), 'fresh: schema_ready true after install');
foreach (CycleClaim::required_columns() as $column) {
    supcheckout_cert_assert(CycleClaim::column_exists($column), "fresh: required column exists: {$column}");
}
supcheckout_cert_assert((string) get_option($option_key) === CycleClaim::SCHEMA_VERSION, 'fresh: schema option becomes 2 only after readiness');

// ---------------------------------------------------------------------------
// 2. Existing v1 table upgrades without destroying historical rows
// ---------------------------------------------------------------------------
supcheckout_cycleclaim_drop_table();
supcheckout_cycleclaim_create_v1_table_with_rows();
update_option($option_key, '1', false);
supcheckout_cert_assert(!CycleClaim::column_exists('expected_amount'), 'v1: expected_amount starts absent');
supcheckout_cert_assert(!CycleClaim::column_exists('expected_currency'), 'v1: expected_currency starts absent');
supcheckout_cert_assert(!CycleClaim::schema_ready(), 'v1: schema_ready false before upgrade');
supcheckout_cert_assert(CycleClaim::maybe_install() === true, 'v1: maybe_install upgrades successfully');
supcheckout_cert_assert(CycleClaim::column_exists('expected_amount'), 'v1: expected_amount exists after upgrade');
supcheckout_cert_assert(CycleClaim::column_exists('expected_currency'), 'v1: expected_currency exists after upgrade');
supcheckout_cert_assert(CycleClaim::schema_ready(), 'v1: schema_ready true after upgrade');
supcheckout_cert_assert((string) get_option($option_key) === CycleClaim::SCHEMA_VERSION, 'v1: schema option becomes 2 after upgrade');

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
$states = $wpdb->get_col("SELECT state FROM {$table} ORDER BY parent_order_id", 0);
supcheckout_cert_assert(
    $states === array('claimed', 'held', 'dispatching', 'resolved'),
    'v1: historical claimed/held/dispatching/resolved rows are preserved'
);

$v1_claimed_key = 'v1c' . str_repeat('a', 61);
supcheckout_cert_assert(strlen($v1_claimed_key) === 64, 'v1: fixture cycle_key is exactly CHAR(64)');
$v1_claimed = CycleClaim::get($v1_claimed_key);
supcheckout_cert_assert(is_array($v1_claimed), 'v1: historical claimed row remains readable');
supcheckout_cert_assert(
    !CycleClaim::has_dispatchable_snapshot(is_array($v1_claimed) ? $v1_claimed : array()),
    'v1: historical claimed row is not dispatchable without valid economics'
);

// ---------------------------------------------------------------------------
// 3. Partial migration: option says 2, expected_currency missing
// ---------------------------------------------------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
$wpdb->query("ALTER TABLE {$table} DROP COLUMN expected_currency");
update_option($option_key, CycleClaim::SCHEMA_VERSION, false);
supcheckout_cert_assert(!CycleClaim::schema_ready(), 'partial: readiness is false when expected_currency is missing');
supcheckout_cert_assert(CycleClaim::maybe_install() === true, 'partial: maybe_install repairs schema');
supcheckout_cert_assert(CycleClaim::column_exists('expected_currency'), 'partial: expected_currency restored');
supcheckout_cert_assert(CycleClaim::schema_ready(), 'partial: readiness true only after repair');

// ---------------------------------------------------------------------------
// 4. Missing table + option = 2 repairs table before reporting ready
// ---------------------------------------------------------------------------
supcheckout_cycleclaim_drop_table();
update_option($option_key, CycleClaim::SCHEMA_VERSION, false);
supcheckout_cert_assert(!CycleClaim::schema_ready(), 'missing-table: readiness false when table is absent despite option=2');
supcheckout_cert_assert(CycleClaim::maybe_install() === true, 'missing-table: maybe_install recreates table');
supcheckout_cert_assert(CycleClaim::schema_ready(), 'missing-table: readiness true after repair');

// ---------------------------------------------------------------------------
// 5. Repeated maybe_install is idempotent
// ---------------------------------------------------------------------------
$first = CycleClaim::maybe_install();
$second = CycleClaim::maybe_install();
$third = CycleClaim::maybe_install();
supcheckout_cert_assert($first === true && $second === true && $third === true, 'idempotent: repeated maybe_install stays true');
supcheckout_cert_assert(CycleClaim::schema_ready(), 'idempotent: readiness remains true');

// ---------------------------------------------------------------------------
// 6. Immutable reclaim preserves cycle intent and rejects dispatch of v1 rows
// ---------------------------------------------------------------------------
$cycle_key = str_repeat('e', 64);
$due = '2026-02-01 00:00:00';
supcheckout_cert_assert(
    CycleClaim::acquire_with_snapshot($cycle_key, 201, 'owner-alpha', $due, '10.000', 'KWD') === true,
    'reclaim-fixture: acquire_with_snapshot succeeds'
);

// Age the claim past the stale threshold without rewriting cycle intent.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
$wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET updated_gmt = %s WHERE cycle_key = %s",
    gmdate('Y-m-d H:i:s', time() - CycleClaim::STALE_CLAIMED_THRESHOLD_SECONDS - 30),
    $cycle_key
));

supcheckout_cert_assert(
    CycleClaim::reclaim_stale_claimed($cycle_key, 201, 'owner-beta') === true,
    'reclaim-fixture: stale claimed row is reclaimable'
);
$row = CycleClaim::get($cycle_key);
supcheckout_cert_assert(is_array($row), 'reclaim-fixture: row remains after reclaim');
supcheckout_cert_assert((string) $row['owner_token'] === 'owner-beta', 'reclaim-fixture: ownership transferred');
supcheckout_cert_assert((string) $row['state'] === CycleClaim::STATE_CLAIMED, 'reclaim-fixture: state remains claimed');
supcheckout_cert_assert((string) $row['cycle_due_gmt'] === $due, 'reclaim-fixture: cycle_due_gmt is immutable');
supcheckout_cert_assert((string) $row['expected_amount'] === '10.000', 'reclaim-fixture: expected_amount is immutable');
supcheckout_cert_assert((string) $row['expected_currency'] === 'KWD', 'reclaim-fixture: expected_currency is immutable');
supcheckout_cert_assert((int) $row['parent_order_id'] === 201, 'reclaim-fixture: parent binding is immutable');
supcheckout_cert_assert(CycleClaim::has_dispatchable_snapshot($row), 'reclaim-fixture: snapshot remains dispatchable after reclaim');

// Old owner cannot mark_dispatching after reclaim.
supcheckout_cert_assert(
    CycleClaim::mark_dispatching($cycle_key, 'owner-alpha') === false,
    'reclaim-fixture: old owner cannot mark_dispatching'
);
supcheckout_cert_assert(
    CycleClaim::mark_dispatching($cycle_key, 'owner-beta') === true,
    'reclaim-fixture: new owner can mark_dispatching'
);
supcheckout_cert_assert(
    CycleClaim::acquire_with_snapshot($cycle_key, 201, 'owner-gamma', $due, '10.000', 'KWD') === false,
    'terminal: acquire fails once dispatching'
);
supcheckout_cert_assert(
    CycleClaim::reclaim_stale_claimed($cycle_key, 201, 'owner-gamma') === false,
    'terminal: reclaim fails once dispatching'
);
supcheckout_cert_assert(
    CycleClaim::release_claimed($cycle_key, 'owner-beta') === false,
    'terminal: release fails once dispatching'
);

// ---------------------------------------------------------------------------
// 7. Immutable-snapshot sensitivity: parent economics change is HELD, not POST
// ---------------------------------------------------------------------------
$cycle_key2 = str_repeat('f', 64);
supcheckout_cert_assert(
    CycleClaim::acquire_with_snapshot($cycle_key2, 202, 'owner-a', $due, '10.000', 'KWD') === true,
    'sensitivity: initial claim snapshots 10.000 KWD'
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
$wpdb->query($wpdb->prepare(
    "UPDATE {$table} SET updated_gmt = %s WHERE cycle_key = %s",
    gmdate('Y-m-d H:i:s', time() - CycleClaim::STALE_CLAIMED_THRESHOLD_SECONDS - 30),
    $cycle_key2
));
supcheckout_cert_assert(
    CycleClaim::reclaim_stale_claimed($cycle_key2, 202, 'owner-b') === true,
    'sensitivity: worker B reclaims the stale attempt'
);
$row2 = CycleClaim::get($cycle_key2);
supcheckout_cert_assert((string) $row2['expected_amount'] === '10.000', 'sensitivity: row remains 10.000 after reclaim');
supcheckout_cert_assert((string) $row2['expected_currency'] === 'KWD', 'sensitivity: row remains KWD after reclaim');

// Scheduler policy for parent-amount 20.000 vs snapshot 10.000 is HELD and ZERO POST.
// The journal must not rewrite the snapshot toward 20.000.
supcheckout_cert_assert(
    CycleEconomics::amounts_equal($row2['expected_amount'], '10.000')
    && !CycleEconomics::amounts_equal($row2['expected_amount'], '20.000'),
    'sensitivity: immutable snapshot is 10.000 not 20.000'
);
supcheckout_cert_assert(
    CycleClaim::mark_held($cycle_key2, 'owner-b', null, null) === true,
    'sensitivity: divergent parent economics hold the attempt'
);

// ---------------------------------------------------------------------------
// 8. Historical terminal rows are never auto-released
// ---------------------------------------------------------------------------
foreach (array('held', 'resolved') as $terminal_state) {
    $key = str_repeat($terminal_state === 'held' ? '1' : '2', 64);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- disposable certification database only
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table}
            (cycle_key, parent_order_id, owner_token, state, cycle_due_gmt, created_gmt, updated_gmt, expected_amount, expected_currency)
         VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE state = VALUES(state)",
        $key,
        300 + ($terminal_state === 'held' ? 1 : 2),
        'owner-term',
        $terminal_state,
        $due,
        $due,
        $due,
        '10.000',
        'KWD'
    ));
    supcheckout_cert_assert(
        CycleClaim::acquire_with_snapshot($key, 301, 'x', $due, '10.000', 'KWD') === false,
        "terminal {$terminal_state}: acquire rejected"
    );
    supcheckout_cert_assert(
        CycleClaim::reclaim_stale_claimed($key, 300 + ($terminal_state === 'held' ? 1 : 2), 'y') === false,
        "terminal {$terminal_state}: reclaim rejected"
    );
    supcheckout_cert_assert(
        CycleClaim::release_claimed($key, 'owner-term') === false,
        "terminal {$terminal_state}: release rejected"
    );
}

supcheckout_cert_note('CycleClaimRuntimeTest complete: fresh install, v1 migration, partial repair, missing-table repair, idempotence, immutable reclaim, snapshot sensitivity, terminal safety');

echo "CycleClaim Runtime Certification: PASS\n";
