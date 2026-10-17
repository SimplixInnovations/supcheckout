<?php

namespace Simplixi\SUPCheckout\Token {
    final class CustomerTokenIdentity {
        const SECRET_INVALID = 'invalid';
        const SECRET_ABSENT = 'absent';
        const SECRET_VALID = 'valid';
        const STATE_INVALID = 'invalid';
        const STATE_VALID = 'valid';
        const META_EXACTLY_ONE = 'exactly_one';
        const META_DUPLICATE_OR_INVALID = 'duplicate_or_invalid';
        const KIND_CANONICAL = 'canonical';
        const KIND_LEGACY_COMPAT = 'legacy_compat';
        const SOURCE_LEGACY_VERIFIED_CAPTURE = 'legacy_verified_capture';
        const SECRET_OPTION = 'upayments_token_identity_secret_v2';
        const SECRET_BYTES = 32;
        const GENERATION_ID_BYTES = 16;
        const VERIFIER_DOMAIN = 'upayments-token-identity';

        /** @return mixed */
        public static function read_existing_secret_record() {}
        /** @return mixed */
        public static function read_existing_identity_context($api_key, $is_test_mode) {}
        /** @return mixed */
        public static function read_provenance($user_id, $scope, $generation) {}
        /** @return mixed */
        public static function inspect_current_user_prior_provenance($user_id, $generation) {}
        /** @return bool */
        public static function is_valid_scope($scope) {}
        /** @return bool */
        public static function is_valid_legacy_token($token) {}
        /** @return bool */
        public static function is_valid_token_for_kind($token, $kind) {}
        /** @return bool */
        public static function force_refresh_order_meta($order) {}
        /** @return mixed */
        public static function get_historical_meta_cardinality($order, $key) {}
        /** @return string */
        public static function get_bootstrap_lock_name() {}
        /** @return string */
        public static function get_lock_name($scope, $user_id) {}
        /** @return bool */
        public static function acquire_lock($lock_name) {}
        /** @return void */
        public static function release_lock($lock_name) {}
        /** @return bool */
        public static function is_valid_secret_record($record) {}
        /** @return bool */
        public static function create_provenance($user_id, $api_key, $is_test_mode, $scope, $generation, $kind, $token, $source) {}
        /** @return bool */
        public static function force_refresh_user_meta($user_id) {}
        /** @return mixed */
        public static function get_or_establish_token($user_id, $api_key, $is_test_mode, callable $create_token_caller) {}
        /** @return mixed */
        public static function validate_token_runtime_context($token, $kind, $scope, $generation_id, $expected_scope, $expected_generation) {}
        /** @return mixed */
        public static function verify_card_membership($card_token, $customer_token, callable $get_saved_cards_caller) {}
        /** @return bool */
        public static function clear_stale_attempt_metadata($order) {}
    }
}
