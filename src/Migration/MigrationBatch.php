<?php
namespace Simplixi\SUPCheckout\Migration;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Bounded, resumable Phase 9I batch orchestration.
 *
 * Every processed user receives a separate Simplix-owned, redacted operations
 * result ledger. This is deliberately distinct from MigrationExecutor's
 * successful-migration identity ledger. No API key, raw customer token, raw
 * preflight payload, or provider response is persisted by this class.
 */
final class MigrationBatch {
    const MAX_INPUT_USERS = 500;
    const DEFAULT_LIMIT = 20;
    const MAX_LIMIT = 50;
    const RESULT_LEDGER_KEY = '_simplixpay_upayments_migration_result_v1';
    const RESULT_LEDGER_VERSION = 1;

    /**
     * Strictly parse a comma/whitespace separated user-id list.
     *
     * @param mixed $raw
     * @return array{ok:bool,reason:string,user_ids:array}
     */
    public static function parseUserIds($raw) {
        if (!is_string($raw) || $raw === '') {
            return array('ok' => false, 'reason' => 'user_ids_missing', 'user_ids' => array());
        }

        if (preg_match('/^[\s,]+\z/', $raw) === 1) {
            return array('ok' => false, 'reason' => 'user_ids_missing', 'user_ids' => array());
        }

        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return array('ok' => false, 'reason' => 'user_ids_missing', 'user_ids' => array());
        }
        if (count($parts) > self::MAX_INPUT_USERS) {
            return array('ok' => false, 'reason' => 'user_ids_over_cap', 'user_ids' => array());
        }

        $ids = array();
        $seen = array();
        foreach ($parts as $part) {
            if (preg_match('/^[1-9][0-9]*\z/', $part) !== 1) {
                return array('ok' => false, 'reason' => 'user_id_invalid', 'user_ids' => array());
            }
            if (strlen($part) > strlen((string) PHP_INT_MAX)
                || (strlen($part) === strlen((string) PHP_INT_MAX) && strcmp($part, (string) PHP_INT_MAX) > 0)
            ) {
                return array('ok' => false, 'reason' => 'user_id_invalid', 'user_ids' => array());
            }
            $id = (int) $part;
            if ($id <= 0) {
                return array('ok' => false, 'reason' => 'user_id_invalid', 'user_ids' => array());
            }
            if (isset($seen[$id])) {
                return array('ok' => false, 'reason' => 'duplicate_user_id', 'user_ids' => array());
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return array('ok' => true, 'reason' => 'user_ids_parsed', 'user_ids' => $ids);
    }

    /**
     * Recover the first not-durably-evaluated position for an exact batch.
     *
     * The fingerprint is HMAC-scoped to the current API key and therefore does
     * not persist the credential itself. Failed/BLOCKED/INDETERMINATE results
     * count as evaluated; an operator may deliberately re-evaluate them by
     * supplying an explicit offset instead of choosing resume mode.
     *
     * @param mixed $user_ids
     * @param mixed $api_key
     * @param mixed $is_test_mode
     * @param mixed $dry_run
     * @return array{ok:bool,reason:string,offset:int}
     */
    public static function resumeOffset($user_ids, $api_key, $is_test_mode, $dry_run) {
        $validated = self::validateResumeInputs($user_ids, $api_key, $is_test_mode, $dry_run);
        if (!$validated['ok']) {
            return array('ok' => false, 'reason' => $validated['reason'], 'offset' => 0);
        }
        $user_ids = $validated['user_ids'];
        $digest = self::batchDigest($user_ids, $api_key, $is_test_mode, $dry_run);
        if ($digest === null) {
            return array('ok' => false, 'reason' => 'batch_digest_failed', 'offset' => 0);
        }

        $total = count($user_ids);
        for ($position = 0; $position < $total; $position++) {
            $record = Keys::read_user_meta($user_ids[$position], self::RESULT_LEDGER_KEY);
            if (!self::isResultLedgerRecord($record)
                || !hash_equals($digest, $record['batch_digest'])
                || $record['position'] !== $position
                || $record['next_offset'] !== ($position + 1)
                || $record['input_count'] !== $total
                || $record['dry_run'] !== $dry_run
                || $record['mode'] !== ($is_test_mode ? 'test' : 'live')
            ) {
                return array(
                    'ok' => true,
                    'reason' => $position === 0 ? 'no_durable_checkpoint' : 'durable_checkpoint_recovered',
                    'offset' => $position,
                );
            }
        }

        return array('ok' => true, 'reason' => 'batch_already_evaluated', 'offset' => $total);
    }

    /**
     * Run one bounded page of explicit user IDs.
     *
     * @param mixed $user_ids
     * @param mixed $api_key
     * @param mixed $is_test_mode
     * @param mixed $dry_run
     * @param mixed $offset
     * @param mixed $limit
     * @return array
     */
    public static function run($user_ids, $api_key, $is_test_mode, $dry_run = true, $offset = 0, $limit = self::DEFAULT_LIMIT) {
        $base = self::baseResult($dry_run, $offset, $limit);

        $validated = self::validateInputs($user_ids, $api_key, $is_test_mode, $dry_run, $offset, $limit);
        if (!$validated['ok']) {
            $base['reason'] = $validated['reason'];
            return $base;
        }
        $user_ids = $validated['user_ids'];
        $total = count($user_ids);
        $base['input_count'] = $total;

        $batch_digest = self::batchDigest($user_ids, $api_key, $is_test_mode, $dry_run);
        if ($batch_digest === null) {
            $base['reason'] = 'batch_digest_failed';
            return $base;
        }

        if ($offset >= $total) {
            $base['success'] = true;
            $base['reason'] = 'batch_complete';
            $base['done'] = true;
            $base['next_offset'] = null;
            $base['checkpoint_offset'] = $total;
            return $base;
        }

        $slice = array_slice($user_ids, $offset, $limit);
        $checkpoint_failed = false;
        $checkpoint_offset = $offset;

        foreach ($slice as $index => $user_id) {
            $position = $offset + $index;
            try {
                $execution = MigrationExecutor::execute($user_id, $api_key, $is_test_mode, $dry_run);
            } catch (\Throwable $e) {
                $execution = array(
                    'success' => false,
                    'reason' => 'executor_exception',
                    'classification' => null,
                    'migrated' => false,
                    'idempotent' => false,
                    'ledger_written' => false,
                    'token_digest' => null,
                );
            }

            $safe = self::redactExecution($user_id, $execution);
            $record = self::buildResultLedgerRecord(
                $batch_digest,
                $position,
                $total,
                $is_test_mode,
                $dry_run,
                $safe
            );
            $ledger_written = self::writeResultLedger($user_id, $record);
            $safe['operations_ledger_written'] = $ledger_written;

            if (!$ledger_written) {
                // The executor may already have completed an idempotent identity
                // mutation. Do not roll it back. Stop this page and make the
                // durable resume point the current user so the next invocation
                // safely re-evaluates it rather than skipping uncertain state.
                $safe['success'] = false;
                $safe['reason'] = 'operations_result_ledger_write_failed';
                $checkpoint_failed = true;
                $checkpoint_offset = $position;
            } else {
                $checkpoint_offset = $position + 1;
            }

            $base['results'][] = $safe;
            $base['processed']++;
            self::accumulate($base['summary'], $safe);

            if ($checkpoint_failed) {
                break;
            }
        }

        $base['checkpoint_offset'] = $checkpoint_offset;
        if ($checkpoint_failed) {
            $base['done'] = false;
            $base['next_offset'] = $checkpoint_offset;
            $base['success'] = false;
            $base['reason'] = 'batch_checkpoint_failed';
            return $base;
        }

        $next = $offset + $base['processed'];
        $base['done'] = ($next >= $total);
        $base['next_offset'] = $base['done'] ? null : $next;
        $base['success'] = ($base['summary']['failed'] === 0);
        $base['reason'] = $base['success'] ? ($base['done'] ? 'batch_complete' : 'batch_page_complete') : 'batch_completed_with_failures';
        return $base;
    }

    private static function validateInputs($user_ids, $api_key, $is_test_mode, $dry_run, $offset, $limit) {
        $validated = self::validateResumeInputs($user_ids, $api_key, $is_test_mode, $dry_run);
        if (!$validated['ok']) {
            return $validated;
        }
        if (!is_int($offset) || $offset < 0) {
            return array('ok' => false, 'reason' => 'invalid_offset', 'user_ids' => array());
        }
        if (!is_int($limit) || $limit <= 0 || $limit > self::MAX_LIMIT) {
            return array('ok' => false, 'reason' => 'invalid_limit', 'user_ids' => array());
        }
        return $validated;
    }

    private static function validateResumeInputs($user_ids, $api_key, $is_test_mode, $dry_run) {
        if (!is_array($user_ids) || count($user_ids) === 0) {
            return array('ok' => false, 'reason' => 'user_ids_missing', 'user_ids' => array());
        }
        if (count($user_ids) > self::MAX_INPUT_USERS) {
            return array('ok' => false, 'reason' => 'user_ids_over_cap', 'user_ids' => array());
        }
        if (!is_string($api_key) || $api_key === '') {
            return array('ok' => false, 'reason' => 'invalid_api_key', 'user_ids' => array());
        }
        if (!is_bool($is_test_mode) || !is_bool($dry_run)) {
            return array('ok' => false, 'reason' => 'invalid_mode', 'user_ids' => array());
        }

        $clean = array();
        $seen = array();
        foreach ($user_ids as $id) {
            if (!is_int($id) || $id <= 0) {
                return array('ok' => false, 'reason' => 'user_id_invalid', 'user_ids' => array());
            }
            if (isset($seen[$id])) {
                return array('ok' => false, 'reason' => 'duplicate_user_id', 'user_ids' => array());
            }
            $seen[$id] = true;
            $clean[] = $id;
        }
        return array('ok' => true, 'reason' => 'valid', 'user_ids' => $clean);
    }

    private static function batchDigest($user_ids, $api_key, $is_test_mode, $dry_run) {
        if (!is_array($user_ids) || !is_string($api_key) || $api_key === '') {
            return null;
        }
        $message = 'phase9i-operations-v1|'
            . ($is_test_mode ? 'test' : 'live') . '|'
            . ($dry_run ? 'dry-run' : 'execute') . '|'
            . implode(',', $user_ids);
        return hash_hmac('sha256', $message, $api_key);
    }

    private static function buildResultLedgerRecord($batch_digest, $position, $input_count, $is_test_mode, $dry_run, $safe) {
        return array(
            'version' => self::RESULT_LEDGER_VERSION,
            'batch_digest' => $batch_digest,
            'position' => $position,
            'next_offset' => $position + 1,
            'input_count' => $input_count,
            'dry_run' => $dry_run,
            'mode' => $is_test_mode ? 'test' : 'live',
            'success' => !empty($safe['success']),
            'reason' => isset($safe['reason']) ? $safe['reason'] : 'unknown',
            'classification' => isset($safe['classification']) ? $safe['classification'] : null,
            'migrated' => !empty($safe['migrated']),
            'idempotent' => !empty($safe['idempotent']),
            'executor_ledger_written' => !empty($safe['ledger_written']),
            'token_digest' => isset($safe['token_digest']) ? $safe['token_digest'] : null,
            'processed_at_gmt' => time(),
        );
    }

    private static function writeResultLedger($user_id, $record) {
        if (!is_int($user_id) || $user_id <= 0 || !self::isResultLedgerRecord($record)) {
            return false;
        }

        // Every name of the ledger (Keys::write_keys()), each read back exactly.
        foreach (Keys::write_keys(self::RESULT_LEDGER_KEY) as $ledger_key) {
            $written = update_user_meta($user_id, $ledger_key, $record);
            $readback = get_user_meta($user_id, $ledger_key, true);

            // update_user_meta() returns false when the exact value already exists;
            // exact durable readback therefore remains the authoritative result.
            if ($written === false && $readback !== $record) {
                return false;
            }
            if (!is_array($readback) || $readback !== $record) {
                return false;
            }
        }
        return true;
    }

    private static function isResultLedgerRecord($record) {
        if (!is_array($record)
            || !isset($record['version']) || $record['version'] !== self::RESULT_LEDGER_VERSION
            || !isset($record['batch_digest']) || !is_string($record['batch_digest']) || preg_match('/^[0-9a-f]{64}\z/', $record['batch_digest']) !== 1
            || !isset($record['position']) || !is_int($record['position']) || $record['position'] < 0
            || !isset($record['next_offset']) || !is_int($record['next_offset']) || $record['next_offset'] !== ($record['position'] + 1)
            || !isset($record['input_count']) || !is_int($record['input_count']) || $record['input_count'] <= 0 || $record['input_count'] > self::MAX_INPUT_USERS
            || !isset($record['dry_run']) || !is_bool($record['dry_run'])
            || !isset($record['mode']) || ($record['mode'] !== 'test' && $record['mode'] !== 'live')
            || !isset($record['success']) || !is_bool($record['success'])
            || !isset($record['reason']) || !self::isSafeReason($record['reason'])
            || !array_key_exists('classification', $record) || !self::isSafeClassification($record['classification'])
            || !isset($record['migrated']) || !is_bool($record['migrated'])
            || !isset($record['idempotent']) || !is_bool($record['idempotent'])
            || !isset($record['executor_ledger_written']) || !is_bool($record['executor_ledger_written'])
            || !array_key_exists('token_digest', $record)
            || ($record['token_digest'] !== null && (!is_string($record['token_digest']) || preg_match('/^[0-9a-f]{64}\z/', $record['token_digest']) !== 1))
            || !isset($record['processed_at_gmt']) || !is_int($record['processed_at_gmt']) || $record['processed_at_gmt'] <= 0
        ) {
            return false;
        }
        return true;
    }

    private static function isSafeReason($reason) {
        return is_string($reason) && strlen($reason) <= 96 && preg_match('/^[a-z0-9_]+\z/', $reason) === 1;
    }

    private static function isSafeClassification($classification) {
        return $classification === null
            || $classification === 'CLEAN'
            || $classification === 'MIGRATABLE'
            || $classification === 'BLOCKED'
            || $classification === 'INDETERMINATE';
    }

    private static function redactExecution($user_id, $execution) {
        $safe = array(
            'user_id' => $user_id,
            'success' => false,
            'reason' => 'executor_malformed',
            'classification' => null,
            'migrated' => false,
            'idempotent' => false,
            'ledger_written' => false,
            'token_digest' => null,
            'operations_ledger_written' => false,
        );
        if (!is_array($execution)) {
            return $safe;
        }

        $safe['success'] = !empty($execution['success']);
        if (isset($execution['reason']) && self::isSafeReason($execution['reason'])) {
            $safe['reason'] = $execution['reason'];
        }
        if (isset($execution['classification']) && self::isSafeClassification($execution['classification'])) {
            $safe['classification'] = $execution['classification'];
        }
        $safe['migrated'] = !empty($execution['migrated']);
        $safe['idempotent'] = !empty($execution['idempotent']);
        $safe['ledger_written'] = !empty($execution['ledger_written']);
        if (isset($execution['token_digest']) && is_string($execution['token_digest'])
            && preg_match('/^[0-9a-f]{64}\z/', $execution['token_digest']) === 1
        ) {
            $safe['token_digest'] = $execution['token_digest'];
        }
        return $safe;
    }

    private static function accumulate(&$summary, $safe) {
        $summary['processed']++;
        if (!empty($safe['success'])) {
            $summary['succeeded']++;
        } else {
            $summary['failed']++;
        }
        if (!empty($safe['migrated'])) {
            $summary['migrated']++;
        }
        if (!empty($safe['idempotent'])) {
            $summary['idempotent']++;
        }

        $classification = isset($safe['classification']) && is_string($safe['classification']) ? $safe['classification'] : 'UNKNOWN';
        if (!isset($summary['classifications'][$classification])) {
            $summary['classifications'][$classification] = 0;
        }
        $summary['classifications'][$classification]++;

        $reason = isset($safe['reason']) && is_string($safe['reason']) ? $safe['reason'] : 'unknown';
        if (!isset($summary['reasons'][$reason])) {
            $summary['reasons'][$reason] = 0;
        }
        $summary['reasons'][$reason]++;
    }

    private static function baseResult($dry_run, $offset, $limit) {
        return array(
            'success' => false,
            'reason' => 'invalid_batch',
            'dry_run' => $dry_run,
            'offset' => $offset,
            'limit' => $limit,
            'input_count' => 0,
            'processed' => 0,
            'next_offset' => null,
            'checkpoint_offset' => $offset,
            'done' => false,
            'summary' => array(
                'processed' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'migrated' => 0,
                'idempotent' => 0,
                'classifications' => array(),
                'reasons' => array(),
            ),
            'results' => array(),
        );
    }

    private function __construct() {
    }
}
