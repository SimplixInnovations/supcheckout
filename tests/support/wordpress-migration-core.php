<?php

namespace UPayments\Token {
    if (!class_exists(CustomerTokenIdentity::class, false)) {
        final class CustomerTokenIdentity {
            const SECRET_INVALID = 'invalid';
            const SECRET_ABSENT = 'absent';
            const SECRET_VALID = 'valid';

            const STATE_INVALID = 'invalid';
            const STATE_ABSENT = 'absent';
            const STATE_VALID = 'valid';

            const META_NONE = 'none';
            const META_EXACTLY_ONE = 'exactly_one';
            const META_DUPLICATE_OR_INVALID = 'duplicate_or_invalid';

            const KIND_CANONICAL = 'canonical';
            const KIND_LEGACY_COMPAT = 'legacy_compat';
            const SOURCE_CREATE_201 = 'create_201';
            const SOURCE_LEGACY_VERIFIED_CAPTURE = 'legacy_verified_capture';

            const SECRET_OPTION = 'upayments_token_identity_secret_v2';
            const SECRET_BYTES = 32;
            const GENERATION_ID_BYTES = 16;
            const VERIFIER_DOMAIN = 'upayments-token-identity';

            public static function read_existing_secret_record() {
                $record = \get_option(self::SECRET_OPTION, null);
                if ($record === null) {
                    return array('state' => self::SECRET_ABSENT, 'record' => null);
                }
                if (!self::is_valid_secret_record($record)) {
                    return array('state' => self::SECRET_INVALID, 'record' => $record);
                }
                return array('state' => self::SECRET_VALID, 'record' => $record);
            }

            public static function read_existing_identity_context($api_key, $is_test_mode) {
                if ($GLOBALS['supcheckout_test_migration_core']['context_override'] !== null) {
                    return $GLOBALS['supcheckout_test_migration_core']['context_override'];
                }

                $secret = self::read_existing_secret_record();
                if (!is_array($secret) || !isset($secret['state']) || $secret['state'] !== self::SECRET_VALID) {
                    return array('state' => isset($secret['state']) ? $secret['state'] : self::SECRET_INVALID);
                }

                $record = $secret['record'];
                $mode = $is_test_mode ? 'test' : 'live';
                return array(
                    'state' => self::SECRET_VALID,
                    'scope' => substr(hash_hmac('sha256', '1|' . $mode . '|' . $api_key, $record['secret']), 0, 32),
                    'generation_id' => $record['generation_id'],
                );
            }

            public static function read_provenance($user_id, $scope, $generation) {
                if (isset($GLOBALS['supcheckout_test_migration_core']['provenance'][$user_id][$scope][$generation])) {
                    return array(
                        'state' => self::STATE_VALID,
                        'record' => $GLOBALS['supcheckout_test_migration_core']['provenance'][$user_id][$scope][$generation],
                    );
                }
                return array('state' => self::STATE_ABSENT, 'record' => null);
            }

            public static function inspect_current_user_prior_provenance($user_id, $generation) {
                if ($GLOBALS['supcheckout_test_migration_core']['prior_provenance_override'] !== null) {
                    return $GLOBALS['supcheckout_test_migration_core']['prior_provenance_override'];
                }
                return array('state' => 'none');
            }

            public static function is_valid_scope($scope) {
                return is_string($scope) && preg_match('/^[0-9a-f]{32}\z/', $scope) === 1;
            }

            public static function is_valid_legacy_token($token) {
                return is_string($token) && preg_match('/^[0-9]{8,18}\z/', $token) === 1;
            }

            public static function is_valid_token_for_kind($token, $kind) {
                return ($kind === self::KIND_CANONICAL || $kind === self::KIND_LEGACY_COMPAT)
                    && self::is_valid_legacy_token($token);
            }

            public static function force_refresh_order_meta($order) {
                return is_object($order) && (!property_exists($order, 'refresh_ok') || $order->refresh_ok === true);
            }
            public static function clear_stale_attempt_metadata($order) {
                return true;
            }


            public static function get_historical_meta_cardinality($order, $key) {
                if (!is_object($order) || !property_exists($order, 'meta') || !is_array($order->meta)) {
                    return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
                }
                if (!isset($order->meta[$key]) || !is_array($order->meta[$key]) || count($order->meta[$key]) === 0) {
                    return array('status' => self::META_NONE, 'value' => null);
                }
                if (count($order->meta[$key]) !== 1 || !is_scalar($order->meta[$key][0])) {
                    return array('status' => self::META_DUPLICATE_OR_INVALID, 'value' => null);
                }
                return array('status' => self::META_EXACTLY_ONE, 'value' => $order->meta[$key][0]);
            }

            public static function get_bootstrap_lock_name() {
                return 'simplixpay-migration-bootstrap';
            }

            public static function get_lock_name($scope, $user_id) {
                return 'simplixpay-migration-' . $scope . '-' . $user_id;
            }

            public static function is_valid_secret_record($record) {
                if (!is_array($record)
                    || !isset($record['version']) || $record['version'] !== 1
                    || !isset($record['secret']) || !is_string($record['secret']) || preg_match('/^[0-9a-f]{64}\z/', $record['secret']) !== 1
                    || !isset($record['generation_id']) || !is_string($record['generation_id']) || preg_match('/^[0-9a-f]{32}\z/', $record['generation_id']) !== 1
                    || !isset($record['verifier']) || !is_string($record['verifier']) || preg_match('/^[0-9a-f]{64}\z/', $record['verifier']) !== 1
                ) {
                    return false;
                }
                $expected = hash_hmac(
                    'sha256',
                    self::VERIFIER_DOMAIN . '|1|' . $record['generation_id'],
                    $record['secret']
                );
                return hash_equals($expected, $record['verifier']);
            }

            public static function create_provenance($user_id, $api_key, $is_test_mode, $scope, $generation, $kind, $token, $source) {
                if ($GLOBALS['supcheckout_test_migration_core']['create_provenance_result'] !== true) {
                    return false;
                }
                $GLOBALS['supcheckout_test_migration_core']['provenance'][$user_id][$scope][$generation] = array(
                    'version' => 3,
                    'kind' => $kind,
                    'token' => $token,
                    'source' => $source,
                    'scope' => $scope,
                    'secret_generation_id' => $generation,
                    'established_at_gmt' => 1700000000,
                );
                return true;
            }

            public static function force_refresh_user_meta($user_id) {
                return $GLOBALS['supcheckout_test_migration_core']['user_refresh_result'] === true;
            }

            private function __construct() {
            }
        }
    }
}

namespace {
    final class SUPCheckout_Test_Migration_Core_Order {
        public $id;
        public $customer_id;
        public $meta = array();
        public $refresh_ok = true;

        public function __construct($id, $customer_id, $token = null) {
            $this->id = $id;
            $this->customer_id = $customer_id;
            if ($token !== null) {
                $this->meta['_upay_customer_unique_token'] = array($token);
            }
        }

        public function get_customer_id() {
            return $this->customer_id;
        }
    }

    final class SUPCheckout_Test_Migration_Core_WPDB {
        public $usermeta = 'wp_usermeta';

        public function esc_like($value) {
            return addcslashes((string) $value, '_%\\');
        }

        public function prepare($query) {
            $args = func_get_args();
            array_shift($args);
            return array('query' => (string) $query, 'args' => $args);
        }

        public function get_var($prepared) {
            if ($GLOBALS['supcheckout_test_migration_core']['db_failure']) {
                return false;
            }

            $query = is_array($prepared) && isset($prepared['query']) ? $prepared['query'] : (string) $prepared;
            if (strpos($query, 'GET_LOCK(') !== false || strpos($query, 'RELEASE_LOCK(') !== false) {
                return 1;
            }
            if (strpos($query, 'SELECT meta_key FROM') !== false) {
                return $GLOBALS['supcheckout_test_migration_core']['global_provenance_exists']
                    ? '_upay_customer_token_v2_b1_fixture'
                    : null;
            }
            return null;
        }

        public function get_results($prepared) {
            if ($GLOBALS['supcheckout_test_migration_core']['db_failure']) {
                return null;
            }
            return $GLOBALS['supcheckout_test_migration_core']['global_provenance_rows'];
        }
    }

    function supcheckout_test_reset_migration_core() {
        if (function_exists('supcheckout_test_reset_wp_options')) {
            supcheckout_test_reset_wp_options();
        }

        $GLOBALS['supcheckout_test_migration_core'] = array(
            'context_override' => null,
            'prior_provenance_override' => null,
            'provenance' => array(),
            'create_provenance_result' => true,
            'user_refresh_result' => true,
            'order_ids_override' => null,
            'query_exception' => false,
            'query_total_override' => null,
            'query_max_pages_override' => null,
            'global_provenance_exists' => false,
            'global_provenance_rows' => array(),
            'db_failure' => false,
            'user_meta' => array(),
            'user_meta_fail' => array(),
        );
        $GLOBALS['supcheckout_test_status_orders'] = array();
        $GLOBALS['wpdb'] = new SUPCheckout_Test_Migration_Core_WPDB();
    }

    function supcheckout_test_migration_secret($generation = null) {
        if ($generation === null) {
            $generation = str_repeat('b', 32);
        }
        $secret = str_repeat('a', 64);
        return array(
            'version' => 1,
            'secret' => $secret,
            'generation_id' => $generation,
            'verifier' => hash_hmac(
                'sha256',
                \UPayments\Token\CustomerTokenIdentity::VERIFIER_DOMAIN . '|1|' . $generation,
                $secret
            ),
        );
    }

    function supcheckout_test_migration_scope($api_key, $is_test_mode, $secret_record) {
        $mode = $is_test_mode ? 'test' : 'live';
        return substr(hash_hmac('sha256', '1|' . $mode . '|' . $api_key, $secret_record['secret']), 0, 32);
    }

    if (!function_exists('get_current_blog_id')) {
        function get_current_blog_id() {
            return 1;
        }
    }

    if (!function_exists('wc_get_orders')) {
        function wc_get_orders($args) {
            if ($GLOBALS['supcheckout_test_migration_core']['query_exception']) {
                throw new \RuntimeException('synthetic migration query exception');
            }

            // R4 enrollment tests request objects + offset against WC_Order fixtures.
            if (isset($args['return']) && $args['return'] === 'objects') {
                $out = array();
                foreach ($GLOBALS['supcheckout_test_status_orders'] as $order) {
                    if ($order instanceof WC_Order) {
                        $out[] = $order;
                    }
                }
                usort($out, static function ($a, $b) {
                    return (int) $a->get_id() <=> (int) $b->get_id();
                });
                $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
                $offset = isset($args['offset']) ? (int) $args['offset'] : 0;
                return array_slice($out, $offset, $limit > 0 ? $limit : count($out));
            }

            if ($GLOBALS['supcheckout_test_migration_core']['order_ids_override'] !== null
                && !isset($args['meta_query'])
            ) {
                $ids = $GLOBALS['supcheckout_test_migration_core']['order_ids_override'];
            } else {
                $ids = array();
                foreach ($GLOBALS['supcheckout_test_status_orders'] as $id => $order) {
                    if (!$order instanceof SUPCheckout_Test_Migration_Core_Order) {
                        continue;
                    }
                    if (isset($args['meta_query'])) {
                        $token = $args['meta_query'][0]['value'];
                        $values = isset($order->meta['_upay_customer_unique_token'])
                            ? $order->meta['_upay_customer_unique_token']
                            : array();
                        if (in_array($token, $values, true)) {
                            $ids[] = $id;
                        }
                    } elseif (isset($args['customer_id']) && (int) $order->customer_id === (int) $args['customer_id']) {
                        $ids[] = $id;
                    }
                }
                rsort($ids, SORT_NUMERIC);
            }

            $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
            $page = isset($args['paged']) ? (int) $args['paged'] : 1;
            $total = $GLOBALS['supcheckout_test_migration_core']['query_total_override'];
            if ($total === null) {
                $total = count($ids);
            }
            $max_pages = $GLOBALS['supcheckout_test_migration_core']['query_max_pages_override'];
            if ($max_pages === null) {
                $max_pages = $total === 0 ? 0 : (int) ceil($total / $limit);
            }

            return (object) array(
                'orders' => array_slice($ids, max(0, ($page - 1) * $limit), $limit),
                'total' => $total,
                'max_num_pages' => $max_pages,
            );
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta($user_id, $key, $single = false) {
            if (!isset($GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id])
                || !array_key_exists($key, $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id])
            ) {
                return $single ? '' : array();
            }
            $value = $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id][$key];
            return $single ? $value : array($value);
        }
    }

    if (!function_exists('update_user_meta')) {
        function update_user_meta($user_id, $key, $value, $prev_value = '') {
            if (!empty($GLOBALS['supcheckout_test_migration_core']['user_meta_fail'][$user_id])) {
                return false;
            }
            if (!isset($GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id])) {
                $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id] = array();
            }
            if (array_key_exists($key, $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id])
                && $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id][$key] === $value
            ) {
                return false;
            }
            $GLOBALS['supcheckout_test_migration_core']['user_meta'][$user_id][$key] = $value;
            return true;
        }
    }

    if (!function_exists('maybe_unserialize')) {
        function maybe_unserialize($value) {
            if (!is_string($value)) {
                return $value;
            }
            $decoded = @unserialize($value);
            return ($decoded === false && $value !== 'b:0;') ? $value : $decoded;
        }
    }

    supcheckout_test_reset_migration_core();
}
