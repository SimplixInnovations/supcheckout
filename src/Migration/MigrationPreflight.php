<?php
namespace Simplixi\SUPCheckout\Migration;

use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

defined('ABSPATH') || exit;

/**
 * Phase 9I read-only historical token-identity migration preflight.
 *
 * This class is deliberately side-effect free. It performs no provider calls,
 * does not create/rotate the H12 secret, and never writes order/user/option
 * state. Its only purpose is to turn historical evidence into one deterministic
 * migration decision.
 */
final class MigrationPreflight {
    const CLEAN = 'CLEAN';
    const MIGRATABLE = 'MIGRATABLE';
    const BLOCKED = 'BLOCKED';
    const INDETERMINATE = 'INDETERMINATE';

    const PAGE_SIZE = 20;
    const MAX_ORDERS = 200;
    const GLOBAL_PROVENANCE_LIMIT = 200;

    /**
     * Inspect one customer's complete relevant history.
     *
     * @param mixed $user_id
     * @param mixed $api_key
     * @param mixed $is_test_mode
     * @return array
     */
    public static function inspect($user_id, $api_key, $is_test_mode) {
        $result = self::baseResult();

        if (!is_int($user_id) || $user_id <= 0) {
            return self::finish($result, self::INDETERMINATE, 'invalid_user_id');
        }
        if (!is_string($api_key) || $api_key === '') {
            return self::finish($result, self::INDETERMINATE, 'invalid_api_key');
        }
        if (!is_bool($is_test_mode)) {
            return self::finish($result, self::INDETERMINATE, 'invalid_test_mode');
        }

        $result['user_id'] = $user_id;

        $secret = CustomerTokenIdentity::read_existing_secret_record();
        if (!is_array($secret) || !isset($secret['state'])) {
            return self::finish($result, self::INDETERMINATE, 'secret_read_malformed');
        }
        $result['secret_state'] = $secret['state'];

        $scope = null;
        $generation = null;
        $current_provenance = null;

        if ($secret['state'] === CustomerTokenIdentity::SECRET_INVALID) {
            return self::finish($result, self::BLOCKED, 'malformed_secret');
        }

        if ($secret['state'] === CustomerTokenIdentity::SECRET_ABSENT) {
            $global_provenance = self::hasAnyProvenanceArtifact();
            if ($global_provenance['state'] === self::INDETERMINATE) {
                return self::finish($result, self::INDETERMINATE, $global_provenance['reason']);
            }
            if ($global_provenance['exists']) {
                return self::finish($result, self::BLOCKED, 'missing_secret_with_provenance');
            }
        } elseif ($secret['state'] === CustomerTokenIdentity::SECRET_VALID) {
            $context = CustomerTokenIdentity::read_existing_identity_context($api_key, $is_test_mode);
            if (!is_array($context)
                || !isset($context['state'])
                || $context['state'] !== CustomerTokenIdentity::SECRET_VALID
                || !isset($context['scope'])
                || !isset($context['generation_id'])
                || !CustomerTokenIdentity::is_valid_scope($context['scope'])
                || !self::isGeneration($context['generation_id'])
            ) {
                return self::finish($result, self::INDETERMINATE, 'identity_context_unavailable');
            }

            $scope = $context['scope'];
            $generation = $context['generation_id'];
            $result['scope'] = $scope;
            $result['generation_id'] = $generation;

            $provenance = CustomerTokenIdentity::read_provenance($user_id, $scope, $generation);
            if (!is_array($provenance) || !isset($provenance['state'])) {
                return self::finish($result, self::INDETERMINATE, 'current_provenance_read_malformed');
            }

            if ($provenance['state'] === CustomerTokenIdentity::STATE_INVALID) {
                return self::finish($result, self::BLOCKED, 'invalid_current_provenance');
            }

            if ($provenance['state'] === CustomerTokenIdentity::STATE_VALID) {
                if (!isset($provenance['record']) || !is_array($provenance['record'])) {
                    return self::finish($result, self::BLOCKED, 'invalid_current_provenance');
                }
                $current_provenance = $provenance['record'];
            } else {
                $prior = CustomerTokenIdentity::inspect_current_user_prior_provenance($user_id, $generation);
                if (!is_array($prior) || !isset($prior['state'])) {
                    return self::finish($result, self::INDETERMINATE, 'prior_provenance_read_malformed');
                }
                if ($prior['state'] === 'read_failure') {
                    return self::finish($result, self::INDETERMINATE, isset($prior['reason']) ? $prior['reason'] : 'prior_provenance_read_failure');
                }
                if ($prior['state'] === 'secret_generation_mismatch') {
                    return self::finish($result, self::BLOCKED, 'secret_generation_mismatch');
                }
                if ($prior['state'] === 'invalid') {
                    return self::finish($result, self::BLOCKED, 'malformed_prior_provenance');
                }
                if ($prior['state'] === 'same_generation_only') {
                    return self::finish($result, self::BLOCKED, 'prior_scope_same_generation');
                }
            }
        } else {
            return self::finish($result, self::INDETERMINATE, 'unknown_secret_state');
        }

        $authoritative_token = null;
        if (is_array($current_provenance)) {
            if (!isset($current_provenance['token']) || !is_string($current_provenance['token'])) {
                return self::finish($result, self::BLOCKED, 'invalid_current_provenance_token');
            }
            $authoritative_token = $current_provenance['token'];
            $result['authoritative_kind'] = isset($current_provenance['kind']) && is_string($current_provenance['kind'])
                ? $current_provenance['kind']
                : null;
        }

        $history = self::scanUserHistory($user_id, $scope, $generation, $authoritative_token);
        if ($history['status'] === self::BLOCKED || $history['status'] === self::INDETERMINATE) {
            $result['evidence'] = $history['evidence'];
            return self::finish($result, $history['status'], $history['reason']);
        }
        $result['evidence'] = $history['evidence'];

        if ($authoritative_token !== null) {
            $collision = self::scanCrossUserConflicts($user_id, $authoritative_token);
            if ($collision['status'] !== self::CLEAN) {
                return self::finish($result, $collision['status'], $collision['reason']);
            }
            $result['token_digest'] = hash('sha256', $authoritative_token);
            return self::finish($result, self::CLEAN, 'current_provenance_valid');
        }

        $candidate = isset($history['candidate_token']) ? $history['candidate_token'] : null;
        if ($candidate === null) {
            return self::finish($result, self::CLEAN, 'no_migration_required');
        }

        $collision = self::scanCrossUserConflicts($user_id, $candidate);
        if ($collision['status'] !== self::CLEAN) {
            return self::finish($result, $collision['status'], $collision['reason']);
        }

        $result['token_digest'] = hash('sha256', $candidate);
        // Raw token is intentionally confined to the in-memory migration payload.
        // CLI/admin presentation must redact it and expose token_digest only.
        $result['migration'] = array(
            'token' => $candidate,
            'kind' => CustomerTokenIdentity::KIND_LEGACY_COMPAT,
            'source' => CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE,
            'order_ids' => $history['evidence']['candidate_order_ids'],
            'unscoped_order_ids' => $history['evidence']['unscoped_order_ids'],
            'current_scope_orphan_order_ids' => $history['evidence']['current_scope_orphan_order_ids'],
            'requires_secret_creation' => ($secret['state'] === CustomerTokenIdentity::SECRET_ABSENT),
        );

        return self::finish($result, self::MIGRATABLE, 'attributable_legacy_identity');
    }

    private static function scanUserHistory($user_id, $current_scope, $current_generation, $authoritative_token) {
        $evidence = array(
            'scanned_orders' => 0,
            'candidate_order_ids' => array(),
            'unscoped_order_ids' => array(),
            'current_scope_orphan_order_ids' => array(),
        );

        $tokens = array();
        $seen = array();
        $page = 1;
        $expected_total = null;
        $expected_pages = null;

        while (count($seen) < self::MAX_ORDERS) {
            try {
                $query = wc_get_orders(array(
                    'type' => 'shop_order',
                    'customer_id' => $user_id,
                    'payment_method' => 'upayments',
                    'limit' => self::PAGE_SIZE,
                    'paged' => $page,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'return' => 'ids',
                    'paginate' => true,
                ));
            } catch (\Throwable $e) {
                return self::scanFailure(self::INDETERMINATE, 'history_query_exception', $evidence);
            }

            $pagination = self::validatePaginationResult($query, $page, count($seen), $expected_total, $expected_pages);
            if (!$pagination['ok']) {
                return self::scanFailure(self::INDETERMINATE, $pagination['reason'], $evidence);
            }
            $expected_total = $pagination['total'];
            $expected_pages = $pagination['max_pages'];
            $order_ids = $query->orders;

            if ($expected_total === 0 && empty($order_ids)) {
                break;
            }
            if (empty($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id_raw) {
                $order_id = self::positiveInt($order_id_raw);
                if ($order_id === null) {
                    return self::scanFailure(self::INDETERMINATE, 'invalid_order_id', $evidence);
                }
                if (isset($seen[$order_id])) {
                    return self::scanFailure(self::INDETERMINATE, 'duplicate_order_id', $evidence);
                }
                $seen[$order_id] = true;
                $evidence['scanned_orders']++;

                $order = wc_get_order($order_id);
                if (!$order) {
                    return self::scanFailure(self::INDETERMINATE, 'unloadable_order', $evidence);
                }
                if (!CustomerTokenIdentity::force_refresh_order_meta($order)) {
                    return self::scanFailure(self::INDETERMINATE, 'force_refresh_failed', $evidence);
                }

                $cards = array(
                    'token' => CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_unique_token'),
                    'kind' => CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_token_kind_v1'),
                    'scope' => CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_token_scope_v1'),
                    'generation' => CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_token_generation_v1'),
                    'card' => CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_credit_card_token'),
                );

                foreach ($cards as $cardinality) {
                    if (!is_array($cardinality) || !isset($cardinality['status'])) {
                        return self::scanFailure(self::INDETERMINATE, 'metadata_read_malformed', $evidence);
                    }
                    if ($cardinality['status'] === CustomerTokenIdentity::META_DUPLICATE_OR_INVALID) {
                        return self::scanFailure(self::BLOCKED, 'non_scalar_or_duplicate_metadata', $evidence);
                    }
                }

                $has_token = ($cards['token']['status'] === CustomerTokenIdentity::META_EXACTLY_ONE);
                $has_kind = ($cards['kind']['status'] === CustomerTokenIdentity::META_EXACTLY_ONE);
                $has_scope = ($cards['scope']['status'] === CustomerTokenIdentity::META_EXACTLY_ONE);
                $has_generation = ($cards['generation']['status'] === CustomerTokenIdentity::META_EXACTLY_ONE);
                $has_card = ($cards['card']['status'] === CustomerTokenIdentity::META_EXACTLY_ONE)
                    && ((string) $cards['card']['value'] !== '');

                if (!$has_token || (string) $cards['token']['value'] === '') {
                    if ($has_kind || $has_scope || $has_generation) {
                        return self::scanFailure(self::BLOCKED, 'orphan_snapshot_metadata', $evidence);
                    }
                    if ($has_card) {
                        return self::scanFailure(self::BLOCKED, 'card_without_customer_identity', $evidence);
                    }
                    continue;
                }

                $token = (string) $cards['token']['value'];
                if (!CustomerTokenIdentity::is_valid_legacy_token($token)) {
                    return self::scanFailure(self::BLOCKED, 'malformed_customer_token', $evidence);
                }

                if ($authoritative_token !== null && !hash_equals($authoritative_token, $token)) {
                    return self::scanFailure(self::BLOCKED, 'historical_token_conflicts_with_provenance', $evidence);
                }

                $all_snapshot = $has_kind && $has_scope && $has_generation;
                $no_snapshot = !$has_kind && !$has_scope && !$has_generation;
                if (!$all_snapshot && !$no_snapshot) {
                    return self::scanFailure(self::BLOCKED, 'partial_scoped_history', $evidence);
                }

                if ($no_snapshot) {
                    $tokens[$token] = true;
                    $evidence['candidate_order_ids'][] = $order_id;
                    $evidence['unscoped_order_ids'][] = $order_id;
                    continue;
                }

                $kind = (string) $cards['kind']['value'];
                $scope = (string) $cards['scope']['value'];
                $generation = (string) $cards['generation']['value'];

                if (!in_array($kind, array(CustomerTokenIdentity::KIND_CANONICAL, CustomerTokenIdentity::KIND_LEGACY_COMPAT), true)
                    || !CustomerTokenIdentity::is_valid_token_for_kind($token, $kind)
                    || !CustomerTokenIdentity::is_valid_scope($scope)
                    || !self::isGeneration($generation)
                ) {
                    return self::scanFailure(self::BLOCKED, 'malformed_scoped_history', $evidence);
                }

                if ($current_scope === null || $current_generation === null) {
                    return self::scanFailure(self::BLOCKED, 'scoped_history_without_secret', $evidence);
                }
                if (!hash_equals($current_generation, $generation)) {
                    return self::scanFailure(self::BLOCKED, 'secret_generation_mismatch', $evidence);
                }
                if (!hash_equals($current_scope, $scope)) {
                    return self::scanFailure(self::BLOCKED, 'prior_scope_same_generation', $evidence);
                }

                if ($authoritative_token === null) {
                    if ($kind !== CustomerTokenIdentity::KIND_LEGACY_COMPAT) {
                        return self::scanFailure(self::BLOCKED, 'orphan_canonical_without_provenance', $evidence);
                    }
                    $tokens[$token] = true;
                    $evidence['candidate_order_ids'][] = $order_id;
                    $evidence['current_scope_orphan_order_ids'][] = $order_id;
                }
            }

            if (count($seen) >= $expected_total) {
                break;
            }
            $page++;
        }

        if ($expected_total === null) {
            return self::scanFailure(self::INDETERMINATE, 'history_total_unknown', $evidence);
        }
        if ($expected_total > self::MAX_ORDERS || count($seen) < $expected_total) {
            return self::scanFailure(self::INDETERMINATE, 'incomplete_history_scan', $evidence);
        }
        if (count($tokens) > 1) {
            return self::scanFailure(self::BLOCKED, 'conflicting_customer_tokens', $evidence);
        }

        return array(
            'status' => self::CLEAN,
            'reason' => 'history_scanned',
            'candidate_token' => count($tokens) === 1 ? (string) key($tokens) : null,
            'evidence' => $evidence,
        );
    }

    private static function scanCrossUserConflicts($user_id, $token) {
        $orders = self::scanCrossUserOrders($user_id, $token);
        if ($orders['status'] !== self::CLEAN) {
            return $orders;
        }
        return self::scanCrossUserProvenance($user_id, $token);
    }

    private static function scanCrossUserOrders($user_id, $token) {
        $seen = array();
        $page = 1;
        $expected_total = null;
        $expected_pages = null;

        while (count($seen) < self::MAX_ORDERS) {
            try {
                $query = wc_get_orders(array(
                    'type' => 'shop_order',
                    'payment_method' => 'upayments',
                    'limit' => self::PAGE_SIZE,
                    'paged' => $page,
                    'orderby' => 'ID',
                    'order' => 'DESC',
                    'return' => 'ids',
                    'paginate' => true,
                    'meta_query' => array(array(
                        'key' => '_upay_customer_unique_token',
                        'value' => $token,
                        'compare' => '=',
                    )),
                ));
            } catch (\Throwable $e) {
                return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_order_query_exception');
            }

            $pagination = self::validatePaginationResult($query, $page, count($seen), $expected_total, $expected_pages);
            if (!$pagination['ok']) {
                return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_' . $pagination['reason']);
            }
            $expected_total = $pagination['total'];
            $expected_pages = $pagination['max_pages'];

            if ($expected_total === 0 && empty($query->orders)) {
                break;
            }
            if (empty($query->orders)) {
                break;
            }

            foreach ($query->orders as $raw_id) {
                $order_id = self::positiveInt($raw_id);
                if ($order_id === null || isset($seen[$order_id])) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_order_identity_invalid');
                }
                $seen[$order_id] = true;

                $order = wc_get_order($order_id);
                if (!$order || !method_exists($order, 'get_customer_id')) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_order_unloadable');
                }
                if (!CustomerTokenIdentity::force_refresh_order_meta($order)) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_force_refresh_failed');
                }
                $token_card = CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_unique_token');
                if (!is_array($token_card) || !isset($token_card['status'])) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_token_read_malformed');
                }
                if ($token_card['status'] !== CustomerTokenIdentity::META_EXACTLY_ONE
                    || !is_string($token_card['value'])
                    || !hash_equals($token, $token_card['value'])
                ) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_query_not_exact');
                }

                $owner = self::nonNegativeInt($order->get_customer_id());
                if ($owner === null) {
                    return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_customer_id_invalid');
                }
                if ($owner === 0 || $owner !== $user_id) {
                    return array('status' => self::BLOCKED, 'reason' => 'cross_user_token_conflict');
                }
            }

            if (count($seen) >= $expected_total) {
                break;
            }
            $page++;
        }

        if ($expected_total === null || $expected_total > self::MAX_ORDERS || count($seen) < $expected_total) {
            return array('status' => self::INDETERMINATE, 'reason' => 'cross_user_incomplete_scan');
        }
        return array('status' => self::CLEAN, 'reason' => 'cross_user_orders_clear');
    }

    private static function scanCrossUserProvenance($user_id, $token) {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->usermeta)
            || !method_exists($wpdb, 'esc_like')
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_results')
        ) {
            return array('status' => self::INDETERMINATE, 'reason' => 'provenance_db_unavailable');
        }

        $prefix = '_upay_customer_token_v2_b' . (string) get_current_blog_id() . '_';
        $key_like = $wpdb->esc_like($prefix) . '%';
        $token_like = '%' . $wpdb->esc_like($token) . '%';
        $limit = self::GLOBAL_PROVENANCE_LIMIT + 1;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-user provenance scan is a bounded migration safety probe that must read current usermeta.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND meta_value LIKE %s LIMIT %d",
                $key_like,
                $token_like,
                $limit
            )
        );
        if (!is_array($rows)) {
            return array('status' => self::INDETERMINATE, 'reason' => 'provenance_query_failed');
        }
        if (count($rows) > self::GLOBAL_PROVENANCE_LIMIT) {
            return array('status' => self::INDETERMINATE, 'reason' => 'provenance_scan_incomplete');
        }

        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->user_id) || !property_exists($row, 'meta_value')) {
                return array('status' => self::INDETERMINATE, 'reason' => 'provenance_row_malformed');
            }
            $owner = self::positiveInt($row->user_id);
            if ($owner === null) {
                return array('status' => self::INDETERMINATE, 'reason' => 'provenance_user_id_invalid');
            }
            $record = function_exists('maybe_unserialize') ? maybe_unserialize($row->meta_value) : $row->meta_value;
            if (!is_array($record)) {
                return array('status' => self::BLOCKED, 'reason' => 'malformed_global_provenance_reference');
            }
            if (!isset($record['token']) || !is_string($record['token'])) {
                return array('status' => self::BLOCKED, 'reason' => 'malformed_global_provenance_reference');
            }
            if (!hash_equals($token, $record['token'])) {
                continue;
            }
            if ($owner !== $user_id) {
                return array('status' => self::BLOCKED, 'reason' => 'cross_user_token_conflict');
            }
        }

        return array('status' => self::CLEAN, 'reason' => 'cross_user_provenance_clear');
    }

    private static function hasAnyProvenanceArtifact() {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->usermeta)
            || !method_exists($wpdb, 'esc_like')
            || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_var')
        ) {
            return array('state' => self::INDETERMINATE, 'reason' => 'provenance_db_unavailable', 'exists' => false);
        }
        $prefix = '_upay_customer_token_v2_b' . (string) get_current_blog_id() . '_';
        $like = $wpdb->esc_like($prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Global provenance existence is a migration safety invariant and must not be served from cache.
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1",
                $like
            )
        );
        if ($value === false) {
            return array('state' => self::INDETERMINATE, 'reason' => 'provenance_query_failed', 'exists' => false);
        }
        return array('state' => self::CLEAN, 'reason' => 'provenance_census_complete', 'exists' => ($value !== null));
    }

    private static function validatePaginationResult($query, $page, $already_seen, $expected_total, $expected_pages) {
        if (!is_object($query) || !isset($query->orders) || !is_array($query->orders)) {
            return array('ok' => false, 'reason' => 'malformed_query_result');
        }
        $total = self::nonNegativeInt(isset($query->total) ? $query->total : null);
        $max_pages = self::nonNegativeInt(isset($query->max_num_pages) ? $query->max_num_pages : null);
        if ($total === null) {
            return array('ok' => false, 'reason' => 'missing_total');
        }
        if ($max_pages === null) {
            return array('ok' => false, 'reason' => 'missing_max_pages');
        }
        if ($expected_total !== null && $total !== $expected_total) {
            return array('ok' => false, 'reason' => 'total_changed');
        }
        if ($expected_pages !== null && $max_pages !== $expected_pages) {
            return array('ok' => false, 'reason' => 'max_pages_changed');
        }
        if (count($query->orders) > self::PAGE_SIZE) {
            return array('ok' => false, 'reason' => 'oversized_page');
        }
        if ($page > $max_pages && !empty($query->orders)) {
            return array('ok' => false, 'reason' => 'page_beyond_max');
        }
        if ($already_seen + count($query->orders) > $total) {
            return array('ok' => false, 'reason' => 'scanned_exceeds_total');
        }
        if (empty($query->orders) && $already_seen < $total) {
            return array('ok' => false, 'reason' => 'unexpected_empty_page');
        }
        return array('ok' => true, 'reason' => 'ok', 'total' => $total, 'max_pages' => $max_pages);
    }

    private static function scanFailure($status, $reason, $evidence) {
        return array(
            'status' => $status,
            'reason' => $reason,
            'candidate_token' => null,
            'evidence' => $evidence,
        );
    }

    private static function baseResult() {
        return array(
            'classification' => self::INDETERMINATE,
            'reason' => 'unknown',
            'user_id' => null,
            'secret_state' => null,
            'scope' => null,
            'generation_id' => null,
            'authoritative_kind' => null,
            'token_digest' => null,
            'migration' => null,
            'evidence' => array(),
        );
    }

    private static function finish($result, $classification, $reason) {
        $result['classification'] = $classification;
        $result['reason'] = $reason;
        return $result;
    }

    private static function positiveInt($value) {
        $parsed = self::nonNegativeInt($value);
        return ($parsed !== null && $parsed > 0) ? $parsed : null;
    }

    private static function nonNegativeInt($value) {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)
        ) {
            return null;
        }
        return (int) $value;
    }

    private static function isGeneration($value) {
        return is_string($value) && preg_match('/^[0-9a-f]{32}\z/', $value) === 1;
    }

    private function __construct() {
    }
}
