<?php
namespace Simplixi\SUPCheckout\Migration;

use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

defined('ABSPATH') || exit;

/**
 * Phase 9I explicit historical token-identity migration executor.
 *
 * Mutates only H12 identity root/provenance state and a redacted Simplix
 * per-user execution ledger. Historical order metadata is deliberately left
 * untouched: subscriptions/renewals consume the historical customer/card
 * tokens directly, while H12 runtime token establishment becomes authoritative
 * through the immutable provenance record.
 */
final class MigrationExecutor {
    const LEDGER_KEY = 'simplixpay_upayments_migration_v1';
    const LEDGER_VERSION = 1;

    /**
     * Execute or dry-run one user's migration.
     *
     * @param mixed $user_id
     * @param mixed $api_key
     * @param mixed $is_test_mode
     * @param mixed $dry_run
     * @return array
     */
    public static function execute($user_id, $api_key, $is_test_mode, $dry_run = false) {
        $result = self::baseResult($dry_run);

        if (!is_int($user_id) || $user_id <= 0) {
            return self::finish($result, false, 'invalid_user_id');
        }
        if (!is_string($api_key) || $api_key === '') {
            return self::finish($result, false, 'invalid_api_key');
        }
        if (!is_bool($is_test_mode) || !is_bool($dry_run)) {
            return self::finish($result, false, 'invalid_input');
        }

        $result['user_id'] = $user_id;

        $initial = MigrationPreflight::inspect($user_id, $api_key, $is_test_mode);
        $result['preflight'] = self::redactPreflight($initial);
        if (!isset($initial['classification'])) {
            return self::finish($result, false, 'preflight_malformed');
        }

        if ($initial['classification'] === MigrationPreflight::CLEAN) {
            $result['classification'] = MigrationPreflight::CLEAN;
            $result['idempotent'] = true;
            return self::finish($result, true, 'already_clean');
        }

        if ($initial['classification'] !== MigrationPreflight::MIGRATABLE) {
            $result['classification'] = $initial['classification'];
            return self::finish($result, false, 'preflight_' . strtolower($initial['classification']));
        }

        $result['classification'] = MigrationPreflight::MIGRATABLE;
        if ($dry_run) {
            return self::finish($result, true, 'dry_run_migratable');
        }

        $lock_name = self::lockNameFor($initial, $user_id);
        if ($lock_name === null) {
            return self::finish($result, false, 'lock_name_invalid');
        }
        if (!self::acquireLock($lock_name)) {
            return self::finish($result, false, 'lock_contention');
        }

        $result['lock_acquired'] = true;
        try {
            // Evidence must still be MIGRATABLE while holding the lock. If a
            // concurrent worker already completed it, CLEAN is an idempotent
            // success. Any other transition fails closed before mutation.
            $locked = MigrationPreflight::inspect($user_id, $api_key, $is_test_mode);
            $result['locked_preflight'] = self::redactPreflight($locked);
            if (!isset($locked['classification'])) {
                return self::finish($result, false, 'locked_preflight_malformed');
            }
            if ($locked['classification'] === MigrationPreflight::CLEAN) {
                $result['classification'] = MigrationPreflight::CLEAN;
                $result['idempotent'] = true;
                return self::finish($result, true, 'completed_by_concurrent_worker');
            }
            if ($locked['classification'] !== MigrationPreflight::MIGRATABLE) {
                $result['classification'] = $locked['classification'];
                return self::finish($result, false, 'locked_preflight_' . strtolower($locked['classification']));
            }

            if (!isset($locked['migration']) || !is_array($locked['migration'])) {
                return self::finish($result, false, 'migration_payload_missing');
            }
            $migration = $locked['migration'];
            if (!isset($migration['token']) || !is_string($migration['token'])
                || !CustomerTokenIdentity::is_valid_legacy_token($migration['token'])
            ) {
                return self::finish($result, false, 'migration_token_invalid');
            }
            if (!isset($migration['kind']) || $migration['kind'] !== CustomerTokenIdentity::KIND_LEGACY_COMPAT
                || !isset($migration['source']) || $migration['source'] !== CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE
            ) {
                return self::finish($result, false, 'migration_provenance_contract_invalid');
            }
            $token = $migration['token'];
            $result['token_digest'] = hash('sha256', $token);

            $needs_secret = !empty($migration['requires_secret_creation']);
            if ($needs_secret) {
                $created = self::ensureMigrationSecret();
                if (!$created['ok']) {
                    return self::finish($result, false, $created['reason']);
                }
                $result['secret_created'] = $created['created'];
            }

            $context = CustomerTokenIdentity::read_existing_identity_context($api_key, $is_test_mode);
            if (!is_array($context)
                || !isset($context['state'])
                || $context['state'] !== CustomerTokenIdentity::SECRET_VALID
                || !isset($context['scope']) || !CustomerTokenIdentity::is_valid_scope($context['scope'])
                || !isset($context['generation_id']) || !self::isGeneration($context['generation_id'])
            ) {
                return self::finish($result, false, 'identity_context_unavailable');
            }
            $scope = $context['scope'];
            $generation = $context['generation_id'];
            $result['scope'] = $scope;
            $result['generation_id'] = $generation;

            // Secret creation changes the scope from null -> current. Re-run
            // preflight after the root exists but before provenance mutation.
            // The same unscoped candidate must remain uniquely attributable.
            if ($needs_secret) {
                $after_secret = MigrationPreflight::inspect($user_id, $api_key, $is_test_mode);
                $result['post_secret_preflight'] = self::redactPreflight($after_secret);
                if (!isset($after_secret['classification'])
                    || $after_secret['classification'] !== MigrationPreflight::MIGRATABLE
                    || !isset($after_secret['migration']['token'])
                    || !is_string($after_secret['migration']['token'])
                    || !hash_equals($token, $after_secret['migration']['token'])
                ) {
                    return self::finish($result, false, 'post_secret_preflight_changed');
                }
            }

            $before = CustomerTokenIdentity::read_provenance($user_id, $scope, $generation);
            if (!is_array($before) || !isset($before['state'])) {
                return self::finish($result, false, 'provenance_read_malformed');
            }
            if ($before['state'] === CustomerTokenIdentity::STATE_VALID) {
                if (!isset($before['record']['token']) || !is_string($before['record']['token']) || !hash_equals($token, $before['record']['token'])) {
                    return self::finish($result, false, 'existing_provenance_conflict');
                }
                $result['classification'] = MigrationPreflight::CLEAN;
                $result['idempotent'] = true;
                return self::finish($result, true, 'already_migrated_under_lock');
            }
            if ($before['state'] === CustomerTokenIdentity::STATE_INVALID) {
                return self::finish($result, false, 'existing_provenance_invalid');
            }

            $persisted = CustomerTokenIdentity::create_provenance(
                $user_id,
                $api_key,
                $is_test_mode,
                $scope,
                $generation,
                CustomerTokenIdentity::KIND_LEGACY_COMPAT,
                $token,
                CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE
            );
            if (!$persisted) {
                return self::finish($result, false, 'provenance_persist_failed');
            }
            $result['provenance_created'] = true;

            $verified = CustomerTokenIdentity::read_provenance($user_id, $scope, $generation);
            if (!self::isExactLegacyProvenance($verified, $token, $scope, $generation)) {
                return self::finish($result, false, 'provenance_verify_failed');
            }

            $final = MigrationPreflight::inspect($user_id, $api_key, $is_test_mode);
            $result['final_preflight'] = self::redactPreflight($final);
            if (!isset($final['classification'])
                || $final['classification'] !== MigrationPreflight::CLEAN
            ) {
                return self::finish($result, false, 'final_preflight_not_clean');
            }

            $result['classification'] = MigrationPreflight::CLEAN;
            $result['migrated'] = true;

            $ledger = self::writeLedger($user_id, array(
                'version' => self::LEDGER_VERSION,
                'status' => 'migrated',
                'token_digest' => $result['token_digest'],
                'scope' => $scope,
                'generation_id' => $generation,
                'completed_at_gmt' => time(),
            ));
            $result['ledger_written'] = $ledger;

            if (!$ledger) {
                // The identity migration itself is already durably verified.
                // Do not delete valid provenance merely because the auxiliary
                // redacted ledger failed; report the observability failure.
                return self::finish($result, true, 'migrated_ledger_write_failed');
            }

            return self::finish($result, true, 'migrated');
        } finally {
            self::releaseLock($lock_name);
        }
    }

    private static function ensureMigrationSecret() {
        $existing = CustomerTokenIdentity::read_existing_secret_record();
        if (!is_array($existing) || !isset($existing['state'])) {
            return array('ok' => false, 'created' => false, 'reason' => 'secret_read_malformed');
        }
        if ($existing['state'] === CustomerTokenIdentity::SECRET_VALID) {
            return array('ok' => true, 'created' => false, 'reason' => 'secret_already_valid');
        }
        if ($existing['state'] === CustomerTokenIdentity::SECRET_INVALID) {
            return array('ok' => false, 'created' => false, 'reason' => 'malformed_secret');
        }

        try {
            $secret = bin2hex(random_bytes(CustomerTokenIdentity::SECRET_BYTES));
            $generation = bin2hex(random_bytes(CustomerTokenIdentity::GENERATION_ID_BYTES));
            $verifier = hash_hmac(
                'sha256',
                CustomerTokenIdentity::VERIFIER_DOMAIN . '|1|' . $generation,
                $secret
            );
        } catch (\Throwable $e) {
            return array('ok' => false, 'created' => false, 'reason' => 'secret_random_failure');
        }

        $record = array(
            'version' => 1,
            'secret' => $secret,
            'generation_id' => $generation,
            'verifier' => $verifier,
        );
        if (!CustomerTokenIdentity::is_valid_secret_record($record)) {
            return array('ok' => false, 'created' => false, 'reason' => 'generated_secret_invalid');
        }

        $created = add_option(Keys::write_key(CustomerTokenIdentity::SECRET_OPTION), $record, '', 'no');
        if (!$created) {
            $readback = CustomerTokenIdentity::read_existing_secret_record();
            if (is_array($readback)
                && isset($readback['state'])
                && $readback['state'] === CustomerTokenIdentity::SECRET_VALID
            ) {
                return array('ok' => true, 'created' => false, 'reason' => 'secret_created_concurrently');
            }
            return array('ok' => false, 'created' => false, 'reason' => 'secret_create_failed');
        }

        $readback = CustomerTokenIdentity::read_existing_secret_record();
        if (!is_array($readback)
            || !isset($readback['state'])
            || $readback['state'] !== CustomerTokenIdentity::SECRET_VALID
            || !isset($readback['record'])
            || !is_array($readback['record'])
            || $readback['record'] !== $record
        ) {
            return array('ok' => false, 'created' => true, 'reason' => 'secret_verify_failed');
        }

        return array('ok' => true, 'created' => true, 'reason' => 'secret_created');
    }

    private static function writeLedger($user_id, $record) {
        if (!is_array($record)
            || !isset($record['version']) || $record['version'] !== self::LEDGER_VERSION
            || !isset($record['status']) || $record['status'] !== 'migrated'
            || !isset($record['token_digest']) || !is_string($record['token_digest']) || preg_match('/^[0-9a-f]{64}\z/', $record['token_digest']) !== 1
            || !isset($record['scope']) || !CustomerTokenIdentity::is_valid_scope($record['scope'])
            || !isset($record['generation_id']) || !self::isGeneration($record['generation_id'])
            || !isset($record['completed_at_gmt']) || !is_int($record['completed_at_gmt']) || $record['completed_at_gmt'] <= 0
        ) {
            return false;
        }

        // One ledger value per user. We intentionally overwrite only this new
        // Simplix-owned key; protected UPayments identity keys are never used.
        $ledger_key = Keys::write_key(self::LEDGER_KEY);
        $written = update_user_meta($user_id, $ledger_key, $record);
        if ($written === false) {
            return false;
        }

        if (!CustomerTokenIdentity::force_refresh_user_meta($user_id)) {
            return false;
        }
        $all = get_user_meta($user_id, $ledger_key, false);
        if (!is_array($all) || count($all) !== 1 || !is_array($all[0])) {
            return false;
        }
        return $all[0] === $record;
    }

    private static function isExactLegacyProvenance($result, $token, $scope, $generation) {
        if (!is_array($result)
            || !isset($result['state'])
            || $result['state'] !== CustomerTokenIdentity::STATE_VALID
            || !isset($result['record'])
            || !is_array($result['record'])
        ) {
            return false;
        }
        $record = $result['record'];
        return isset($record['kind']) && $record['kind'] === CustomerTokenIdentity::KIND_LEGACY_COMPAT
            && isset($record['source']) && $record['source'] === CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE
            && isset($record['token']) && is_string($record['token']) && hash_equals($token, $record['token'])
            && isset($record['scope']) && is_string($record['scope']) && hash_equals($scope, $record['scope'])
            && isset($record['secret_generation_id']) && is_string($record['secret_generation_id']) && hash_equals($generation, $record['secret_generation_id']);
    }

    private static function lockNameFor($preflight, $user_id) {
        if (!is_array($preflight) || !isset($preflight['migration']) || !is_array($preflight['migration'])) {
            return null;
        }
        if (!empty($preflight['migration']['requires_secret_creation'])) {
            return CustomerTokenIdentity::get_bootstrap_lock_name();
        }
        if (!isset($preflight['scope']) || !is_string($preflight['scope'])) {
            return null;
        }
        return CustomerTokenIdentity::get_lock_name($preflight['scope'], $user_id);
    }

    private static function acquireLock($lock_name) {
        global $wpdb;
        if (!is_string($lock_name) || $lock_name === ''
            || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')
        ) {
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration advisory lock must observe the live database connection and cannot be cached.
        $value = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock_name));
        return $value === 1 || $value === '1';
    }

    private static function releaseLock($lock_name) {
        global $wpdb;
        if (!is_string($lock_name) || $lock_name === ''
            || !is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_var')
        ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration advisory-lock release must execute against the live connection and cannot be cached.
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
    }

    private static function redactPreflight($preflight) {
        if (!is_array($preflight)) {
            return null;
        }
        $copy = $preflight;
        if (isset($copy['migration']) && is_array($copy['migration'])) {
            unset($copy['migration']['token']);
        }
        return $copy;
    }

    private static function isGeneration($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{32}\z/', $value) === 1;
    }

    private static function baseResult($dry_run) {
        return array(
            'success' => false,
            'reason' => 'unknown',
            'classification' => null,
            'dry_run' => $dry_run,
            'user_id' => null,
            'migrated' => false,
            'idempotent' => false,
            'lock_acquired' => false,
            'secret_created' => false,
            'provenance_created' => false,
            'ledger_written' => false,
            'token_digest' => null,
            'scope' => null,
            'generation_id' => null,
            'preflight' => null,
            'locked_preflight' => null,
            'post_secret_preflight' => null,
            'final_preflight' => null,
        );
    }

    private static function finish($result, $success, $reason) {
        $result['success'] = $success;
        $result['reason'] = $reason;
        return $result;
    }

    private function __construct() {
    }
}
