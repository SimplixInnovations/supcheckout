<?php

namespace UPayments\Subscription\Cron;

defined('ABSPATH') || exit;

/**
 * Per-billing-cycle atomic attempt journal.
 *
 * This is a durable LOCAL record of dispatch attempts for a single
 * (parent_order_id, billing_cycle) pair. It is NOT a payment lease, NOT a
 * provider idempotency key, and NOT an expiring lock.
 *
 * The journal enforces the rule:
 *
 *   AT MOST ONE automatic POST /auto-deduct may BEGIN until that billing
 *   attempt has been explicitly resolved/reconciled.
 *
 * States (high-level):
 *   claimed       — INSERT-only winner; no network dispatch has begun.
 *   dispatching   — This worker has crossed the point where an auto-deduct
 *                   POST may have reached UPayments. Never auto-released.
 *   held          — Post-dispatch outcome is ambiguous or unsafe for
 *                   automatic retry. Never auto-released.
 *   resolved      — Local renewal persisted and parent last_billed_at
 *                   advanced. Never auto-released.
 *
 * Only `claimed` may be stale-reclaimed after a conservative threshold.
 * `dispatching`, `held`, and `resolved` never auto-expire.
 */
class CycleClaim
{
    const SCHEMA_VERSION = '2';
    const OPTION_KEY     = 'upay_billing_cycle_schema_version';

    const STALE_CLAIMED_THRESHOLD_SECONDS = 600; // 10 minutes — conservative

    const STATE_CLAIMED     = 'claimed';
    const STATE_DISPATCHING = 'dispatching';
    const STATE_HELD        = 'held';
    const STATE_RESOLVED    = 'resolved';

    /**
     * Columns that MUST exist for the currently declared SCHEMA_VERSION.
     *
     * `expected_amount` / `expected_currency` are the v2 immutable economic
     * snapshot. The remaining columns are the durable claim identity and
     * state machine required by every journal mutation.
     *
     * @return string[]
     */
    public static function required_columns(): array
    {
        return array(
            'cycle_key',
            'parent_order_id',
            'owner_token',
            'state',
            'cycle_due_gmt',
            'created_gmt',
            'updated_gmt',
            'expected_amount',
            'expected_currency',
        );
    }

    /**
     * True iff every column required by SCHEMA_VERSION exists on the live table.
     *
     * The schema-version option is an optimization/coordinate only. It is
     * NEVER proof of database reality: a table can exist while one or more
     * v2 columns are absent after a partial restore or failed dbDelta.
     */
    public static function schema_ready(): bool
    {
        if (!self::table_exists()) {
            return false;
        }

        foreach (self::required_columns() as $column) {
            if (!self::column_exists($column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True iff the named column exists on the live journal table.
     */
    public static function column_exists(string $column): bool
    {
        global $wpdb;
        $table = self::table_name();

        if ($column === '' || !self::table_exists()) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-existence probe for the plugin-owned billing-attempt journal; caching would make migration/runtime readiness stale.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND COLUMN_NAME = %s',
                $table,
                $column
            )
        );

        return is_string($found) && $found !== '';
    }

    /**
     * Idempotent schema installer / readiness verifier.
     *
     * Behavior:
     *   - If the schema-version option matches AND schema_ready() proves every
     *     mandatory v2 column exists, return true immediately. No dbDelta.
     *   - Otherwise, (re)load upgrade.php, run dbDelta().
     *   - If schema_ready() is still false after dbDelta(), return false
     *     WITHOUT bumping the version flag. The caller must fail closed.
     *   - Only a proven-ready database advances the option to SCHEMA_VERSION.
     *
     * The option value is never treated as proof of column reality.
     *
     * Self-heals against:
     *   - accidental table deletion;
     *   - partial DB restore;
     *   - partial migration (option says v2 but a required column is missing);
     *   - failed migration state where the version flag is set but the
     *     table or columns are missing.
     */
    public static function maybe_install(): bool
    {
        $current = (string) get_option(self::OPTION_KEY, '');
        if ($current === self::SCHEMA_VERSION && self::schema_ready()) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
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
            expected_amount  varchar(24)     NULL,
            expected_currency char(3)        NULL,
            PRIMARY KEY  (cycle_key),
            KEY idx_parent (parent_order_id),
            KEY idx_state  (state)
        ) {$charset};";

        dbDelta($sql);

        // Confirm the live schema is actually ready before advancing the
        // version coordinate. Missing columns must not produce false readiness.
        if (!self::schema_ready()) {
            return false;
        }

        update_option(self::OPTION_KEY, self::SCHEMA_VERSION, false);
        return true;
    }

    /**
     * True iff the journal table actually exists in the live database.
     * Uses $wpdb->esc_like() so the table prefix (which may contain
     * underscores or other LIKE wildcards) is treated literally.
     */
    public static function table_exists(): bool
    {
        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema-existence probe for the plugin-owned billing-attempt journal; caching would make migration/runtime readiness stale.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );
        return $found === $table;
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'upayments_billing_attempts';
    }

    /**
     * Deterministic LOCAL cycle identity. Computed only from values that
     * exist before any POST is sent. NEVER sent to UPayments.
     */
    public static function make_cycle_key(
        int $parent_order_id,
        int $next_billing_utc_timestamp,
        string $subscription_plan,
        int $subscription_interval
    ): string {
        $material = implode('|', [
            'upay-cycle-v1',
            (string) $parent_order_id,
            (string) $next_billing_utc_timestamp,
            (string) $subscription_plan,
            (string) $subscription_interval,
        ]);
        return hash('sha256', $material);
    }

    /**
     * Atomic INSERT-ONLY acquisition. Uses INSERT IGNORE so the UNIQUE
     * (primary) cycle_key rejects a duplicate.
     *
     * IMPORTANT: this method does NOT use INSERT ... ON DUPLICATE KEY UPDATE.
     * Re-acquisition after a stale `claimed` is performed by reclaim_stale_claimed()
     * under a CAS pattern.
     *
     * Return contract (strict):
     *   true  iff $wpdb->query() returned exactly integer 1.
     *   false for any other return: 0 (duplicate ignored), false (SQL error),
     *         or any non-1 value.
     *
     * The follow-up owner-token re-read is defense in depth, NOT the primary
     * acceptance signal. WordPress wpdb::query() returns the affected-rows
     * count for INSERT/UPDATE/DELETE and false on error.
     */
    public static function acquire(
        string $cycle_key,
        int $parent_order_id,
        string $owner_token,
        string $cycle_due_gmt
    ): bool {
        global $wpdb;
        $table = self::table_name();

        $now_gmt = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic INSERT IGNORE is the concurrency primitive for the plugin-owned billing-attempt journal; result caching is invalid for claim ownership.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO %i (
                    cycle_key, parent_order_id, owner_token, state,
                    cycle_due_gmt, created_gmt, updated_gmt
                ) VALUES (%s, %d, %s, %s, %s, %s, %s)",
                $table,
                $cycle_key,
                $parent_order_id,
                $owner_token,
                self::STATE_CLAIMED,
                $cycle_due_gmt,
                $now_gmt,
                $now_gmt
            )
        );

        // Strict acceptance: exactly one row inserted. Anything else
        // (0 = duplicate, false = SQL error, anything non-1) loses.
        if ($inserted !== 1) {
            return false;
        }

        // Defense in depth: confirm we actually own the row we just wrote.
        $row = self::get($cycle_key);
        return is_array($row)
            && isset($row['owner_token'])
            && hash_equals((string) $row['owner_token'], $owner_token)
            && isset($row['state'])
            && (string) $row['state'] === self::STATE_CLAIMED;
    }

    /**
     * Atomic claim + immutable economic snapshot for charge-authoritative cycles.
     *
     * The snapshot is persisted in the same INSERT as claim ownership so a
     * provider dispatch can never race a later unguarded economics update.
     */
    public static function acquire_with_snapshot(
        string $cycle_key,
        int $parent_order_id,
        string $owner_token,
        string $cycle_due_gmt,
        string $expected_amount,
        string $expected_currency
    ): bool {
        global $wpdb;
        $table = self::table_name();

        if ($parent_order_id <= 0
            || $cycle_key === ''
            || $cycle_due_gmt === ''
            || $expected_amount === ''
            || $expected_currency === ''
        ) {
            return false;
        }

        $now_gmt = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic INSERT IGNORE is the concurrency primitive for the plugin-owned billing-attempt journal.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO %i (
                    cycle_key, parent_order_id, owner_token, state,
                    cycle_due_gmt, created_gmt, updated_gmt,
                    expected_amount, expected_currency
                ) VALUES (%s, %d, %s, %s, %s, %s, %s, %s, %s)",
                $table,
                $cycle_key,
                $parent_order_id,
                $owner_token,
                self::STATE_CLAIMED,
                $cycle_due_gmt,
                $now_gmt,
                $now_gmt,
                $expected_amount,
                $expected_currency
            )
        );

        if ($inserted !== 1) {
            return false;
        }

        $row = self::get($cycle_key);
        return is_array($row)
            && isset($row['owner_token'])
            && hash_equals((string) $row['owner_token'], $owner_token)
            && isset($row['state'])
            && (string) $row['state'] === self::STATE_CLAIMED
            && isset($row['expected_amount'])
            && hash_equals((string) $row['expected_amount'], $expected_amount)
            && isset($row['expected_currency'])
            && hash_equals((string) $row['expected_currency'], $expected_currency);
    }

    /**
     * True iff the claim row carries a complete immutable v2 economic snapshot
     * and durable claim identity required before an automatic provider dispatch.
     *
     * Malformed persisted economics must never authorize a provider POST.
     * Validation is intentionally a narrow journal-local primitive consistent
     * with CycleEconomics, not a dependency on a high-level service.
     */
    public static function has_dispatchable_snapshot(array $row): bool
    {
        if (!is_array($row)
            || !isset(
                $row['expected_amount'],
                $row['expected_currency'],
                $row['cycle_key'],
                $row['parent_order_id'],
                $row['state'],
                $row['owner_token']
            )
        ) {
            return false;
        }

        if (!is_string($row['cycle_key']) || $row['cycle_key'] === '') {
            return false;
        }

        if (!is_numeric($row['parent_order_id']) || (int) $row['parent_order_id'] <= 0) {
            return false;
        }

        if (!is_string($row['state'])
            || !in_array(
                $row['state'],
                array(self::STATE_CLAIMED, self::STATE_DISPATCHING, self::STATE_HELD, self::STATE_RESOLVED),
                true
            )
        ) {
            return false;
        }

        if (!is_string($row['owner_token']) || $row['owner_token'] === '') {
            return false;
        }

        return self::canonical_snapshot_amount($row['expected_amount']) !== null
            && self::canonical_snapshot_currency($row['expected_currency']) !== null;
    }

    /**
     * Journal-local canonical non-negative plain decimal. Rejects floats,
     * exponents, leading-zero malformation, signs, and whitespace. Consistent
     * with CycleEconomics::canonical_decimal() without coupling the journal
     * to a high-level service.
     *
     * @param mixed $value Raw stored amount.
     * @return string|null Canonical amount or null when not dispatchable.
     */
    public static function canonical_snapshot_amount($value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (is_float($value) || !is_string($value)) {
            return null;
        }
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/', $value)) {
            return null;
        }
        if (strlen($value) > 22) {
            return null;
        }
        if (strpos($value, '.') === false) {
            return $value;
        }
        $trimmed = rtrim($value, '0');
        $canonical = rtrim($trimmed, '.');
        return $canonical === '' ? '0' : $canonical;
    }

    /**
     * Journal-local canonical 3-letter currency. Consistent with
     * CycleEconomics::canonical_currency().
     *
     * @param mixed $value Raw stored currency.
     * @return string|null Canonical currency or null when not dispatchable.
     */
    public static function canonical_snapshot_currency($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtoupper(trim($value));
        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    /**
     * Stale-CLAIMED recovery. CAS-protected: only reclaims if the row is
     * still in `claimed` state, owned by a different token, belongs to
     * the same parent_order_id, AND is older than the stale threshold.
     *
     * The old owner, when it later attempts `claimed → dispatching`,
     * will see zero rows affected and MUST abort without POSTing.
     *
     * The `parent_order_id` predicate is critical: it prevents a stale
     * reclaim that would silently re-bind a cycle_key to a different
     * subscription's renewal pipeline.
     *
     * IMMUTABLE CYCLE INTENT: reclaim rewrites ownership/housekeeping only
     * (`owner_token`, `updated_gmt`). It MUST NOT rewrite `cycle_key`,
     * `parent_order_id`, `cycle_due_gmt`, `expected_amount`, or
     * `expected_currency`. The reclaimed row is an already-created attempt
     * identity; economic/cycle intent is frozen at first acquisition.
     */
    public static function reclaim_stale_claimed(
        string $cycle_key,
        int $parent_order_id,
        string $new_owner_token
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        if ($cycle_key === '' || $parent_order_id <= 0 || $new_owner_token === '') {
            return false;
        }

        $threshold_gmt = gmdate(
            'Y-m-d H:i:s',
            time() - self::STALE_CLAIMED_THRESHOLD_SECONDS
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set update on the plugin-owned billing-attempt journal; caching would violate owner-token concurrency semantics.
        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                    SET owner_token = %s,
                        updated_gmt = %s
                    WHERE cycle_key = %s
                      AND parent_order_id = %d
                      AND state = %s
                      AND updated_gmt < %s",
                $table,
                $new_owner_token,
                $now_gmt,
                $cycle_key,
                $parent_order_id,
                self::STATE_CLAIMED,
                $threshold_gmt
            )
        );

        if ($updated !== 1) {
            return false;
        }

        // Fresh-read ownership proof after the CAS update. Callers must also
        // re-read and validate the immutable snapshot before any provider POST.
        $row = self::get($cycle_key);
        return is_array($row)
            && isset($row['owner_token'])
            && hash_equals((string) $row['owner_token'], $new_owner_token)
            && isset($row['parent_order_id'])
            && (int) $row['parent_order_id'] === $parent_order_id
            && isset($row['state'])
            && (string) $row['state'] === self::STATE_CLAIMED;
    }

    /**
     * CAS transition: claimed → dispatching. Requires owner_token.
     * MUST be invoked BEFORE curl_exec().
     *
     * Returns true if THIS worker now owns the dispatching state.
     */
    public static function mark_dispatching(string $cycle_key, string $owner_token): bool
    {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic state transition on the plugin-owned billing-attempt journal; cached state is unsafe.
        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                    SET state = %s,
                        dispatched_gmt = %s,
                        updated_gmt = %s
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state = %s",
                $table,
                self::STATE_DISPATCHING,
                $now_gmt,
                $now_gmt,
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED
            )
        );

        return $updated === 1;
    }

    /**
     * Mark post-dispatch outcome as held. CAS-protected by owner_token.
     *
     * Acceptable from:
     *   - `dispatching` — NORMAL new-flow post-dispatch hold.
     *   - `claimed`     — ONLY for the LEGACY AMBIGUOUS ATTEMPT MIGRATION
     *                     guard: a due parent that already has a non-empty
     *                     historical `_upay_last_attempt_at` but no cycle
     *                     row is HELD before any POST is attempted. The
     *                     current Scheduler never performs this transition
     *                     from `claimed` for any other reason.
     *
     * Never auto-released.
     */
    public static function mark_held(
        string $cycle_key,
        string $owner_token,
        ?int $curl_errno = null,
        ?int $http_status = null
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic held-state transition on the plugin-owned billing-attempt journal; cached state is unsafe.
        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                    SET state = %s,
                        updated_gmt = %s,
                        curl_errno = %d,
                        http_status = %d
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state IN (%s, %s)",
                $table,
                self::STATE_HELD,
                $now_gmt,
                null === $curl_errno ? 0 : $curl_errno,
                null === $http_status ? 0 : $http_status,
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED,
                self::STATE_DISPATCHING
            )
        );

        return $updated === 1;
    }

    /**
     * Mark cycle as resolved. Records the renewal order id and payment id.
     * Owner-token protected.
     *
     * Acceptable from:
     *   - `dispatching` — NORMAL automatic resolution after the renewal is
     *                     saved AND parent `_upay_last_billed_at` is saved.
     *                     This is the ONLY transition the current Scheduler
     *                     performs into `resolved`.
     *   - `held`        — Reserved for FUTURE manual reconciliation tooling
     *                     (Phase 8C or later). The current Scheduler never
     *                     performs this transition.
     *
     * `claimed` is NOT in the source-state set. A normal automatic resolution
     * MUST originate from `dispatching`; resolution from `claimed` would
     * imply a payment event without an attempted POST, which is impossible
     * in the new flow.
     */
    public static function mark_resolved(
        string $cycle_key,
        string $owner_token,
        int $renewal_order_id,
        string $payment_id
    ): bool {
        global $wpdb;
        $table   = self::table_name();
        $now_gmt = current_time('mysql', true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic resolved-state transition on the plugin-owned billing-attempt journal; cached state is unsafe.
        $updated = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i
                    SET state = %s,
                        resolved_gmt = %s,
                        updated_gmt = %s,
                        renewal_order_id = %d,
                        payment_id = %s
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state IN (%s, %s)",
                $table,
                self::STATE_RESOLVED,
                $now_gmt,
                $now_gmt,
                $renewal_order_id,
                $payment_id,
                $cycle_key,
                $owner_token,
                self::STATE_DISPATCHING,
                self::STATE_HELD
            )
        );

        return $updated === 1;
    }

    /**
     * Release a still-claimed row. Only allowed when state is `claimed`
     * AND owner_token matches. Never releases dispatching/held/resolved.
     */
    public static function release_claimed(string $cycle_key, string $owner_token): bool
    {
        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic owner-token-guarded release from the plugin-owned billing-attempt journal; caching is invalid.
        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i
                    WHERE cycle_key = %s
                      AND owner_token = %s
                      AND state = %s",
                $table,
                $cycle_key,
                $owner_token,
                self::STATE_CLAIMED
            )
        );

        return $deleted === 1;
    }

    /**
     * Read the current row for a cycle key. Returns null if absent.
     */
    public static function get(string $cycle_key): ?array
    {
        global $wpdb;
        $table = self::table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh journal ownership/state is required immediately after atomic mutations.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE cycle_key = %s",
                $table,
                $cycle_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Generate a fresh owner token for this worker.
     */
    public static function new_owner_token(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        // Fallback: 32 hex chars from random_bytes.
        try {
            return bin2hex(random_bytes(16));
        } catch (\Exception $e) {
            return sha1(uniqid('upay_', true) . microtime(true));
        }
    }

    /**
     * Format a PHP DateTime as a MySQL DATETIME string in UTC.
     */
    public static function format_gmt_datetime(\DateTimeInterface $dt): string
    {
        $utc = new \DateTime('@' . $dt->getTimestamp());
        return $utc->format('Y-m-d H:i:s');
    }
}
