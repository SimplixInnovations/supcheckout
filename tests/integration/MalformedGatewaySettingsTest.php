<?php
/**
 * Real-runtime regression for the malformed gateway-settings fault-tolerance boundary (B-X1).
 *
 * Reproduces the B-X1 defect class against the live `woocommerce_available_payment_gateways`
 * filter callback `GatewayRegistration::filter_available()` and PROVES the filter does not mutate
 * persisted storage in any malformed scenario.
 *
 *   Case 1 — stdClass settings (the PluginActivationTest sentinel shape):
 *            the filter MUST remove 'upayments' from the available set, MUST keep
 *            'cod' available, MUST NOT crash, and the canonical
 *            `woocommerce_upayments_settings` row MUST remain byte-for-byte
 *            identical (compared by raw SQL read) before and after the filter
 *            executes.
 *
 *   Case 2 — scalar/otherwise malformed option (string, int, bool, missing):
 *            same invariants as Case 1, with the addition that for the
 *            `string`/`int`/`bool` cases the raw DB row remains present and
 *            unchanged; for the missing-option case the row must remain absent.
 *
 *   Case 3 — valid array settings regression: a fully configured array with
 *            api_key and make_default_gateway=yes keeps the gateway available
 *            and preserves the chosen payment method. The pre-filter raw row
 *            must equal the post-filter raw row (the filter must not silently
 *            rewrite a valid array either).
 *
 * Storage-mutation contract:
 *   The assertion that the pre-filter raw row equals the post-filter raw
 *   row (compared with hash_equals() against maybe_serialize(get_option(...))
 *   OR against a direct wp_options SQL read) is the only proof the filter
 *   itself did not mutate storage. Restoring the option afterwards is purely
 *   defensive cleanup for downstream tests and is NOT the preservation
 *   evidence.
 *
 * Note on Case 3 cod-removal: the enable_autodeduction branch is gated by
 * is_checkout(), which is true only on a real /checkout request and is not
 * satisfiable inside wp eval-file. The cod-removal behaviour is covered by
 * the existing checkout / B6 acceptance evidence and the legacy
 * GatewayRegistration::filter_available() filter's static-analysis harness; this test
 * exercises the make_default_gateway branch (which is NOT gated by
 * is_checkout) and the api_key preservation (which is unconditional).
 */

require_once __DIR__ . '/bootstrap.php';

if (!defined('ABSPATH')) {
    throw new RuntimeException('Integration assertions must run inside WordPress.');
}

if (false === has_filter('woocommerce_available_payment_gateways', array(\Simplixi\SUPCheckout\Gateway\GatewayRegistration::class, 'filter_available'))) {
    throw new RuntimeException('FAIL: GatewayRegistration::filter_available is not registered on woocommerce_available_payment_gateways in the real runtime.');
}

$phase = getenv('SUPCHECKOUT_CERT_PHASE');
if (!in_array($phase, array('seed', 'verify'), true)) {
    throw new RuntimeException('Unknown SUPCHECKOUT_CERT_PHASE.');
}

/**
 * Build the available-gateways payload the WooCommerce front-end would pass
 * to the GatewayRegistration::filter_available filter. We inject inert stub objects so the
 * filter receives a real "upayments"/"cod" pair without requiring the full
 * gateway bootstrap to register them first.
 *
 * @return array<string,object>
 */
function supcheckout_bx1_test_gateways() {
    return array(
        'upayments' => (object) array(
            'id'           => 'upayments',
            'enabled'      => 'yes',
            'title'        => 'UPayments',
            'method_title' => 'UPayments',
        ),
        'cod' => (object) array(
            'id'           => 'cod',
            'enabled'      => 'yes',
            'title'        => 'Cash on delivery',
            'method_title' => 'Cash on delivery',
        ),
    );
}

/**
 * Run the available-gateways filter exactly once and capture any PHP error
 * emitted. Errors are converted into RuntimeException so a TypeError,
 * Warning or Notice surfaces as a hard fail rather than being swallowed.
 *
 * @param array<string,object> $gateways Input gateways payload.
 * @return array<string,object>
 */
function supcheckout_bx1_test_apply_filter($gateways) {
    set_error_handler(static function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new RuntimeException(
            sprintf('PHP error during filter application: [%d] %s in %s:%d', $severity, $message, $file, $line)
        );
    });
    try {
        $result = apply_filters('woocommerce_available_payment_gateways', $gateways);
    } finally {
        restore_error_handler();
    }
    if (!is_array($result)) {
        throw new RuntimeException('FAIL: filter did not return an array.');
    }
    return $result;
}

/**
 * Read the canonical raw bytes for an option straight from wp_options,
 * bypassing the WP options cache. Returns null when the row does not exist.
 *
 * @param string $name Option name.
 * @return string|null
 */
function supcheckout_bx1_test_raw_option($name) {
    global $wpdb;
    return $wpdb->get_var(
        $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $name
        )
    );
}

/**
 * Delete an option row directly and clear the WP options caches so the next
 * get_option() returns the legacy default `false` (NOT NULL, since
 * wp_options.option_value is NOT NULL).
 *
 * @param string $name Option name to delete.
 * @return void
 */
function supcheckout_bx1_test_delete_option_raw($name) {
    global $wpdb;
    $deleted = $wpdb->delete($wpdb->options, array('option_name' => $name), array('%s'));
    supcheckout_cert_assert(false !== $deleted, 'raw certification option delete succeeds: ' . $name);
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    $notoptions = wp_cache_get('notoptions', 'options');
    if (is_array($notoptions) && isset($notoptions[$name])) {
        unset($notoptions[$name]);
        wp_cache_set('notoptions', $notoptions, 'options');
    }
}

/**
 * Reset the canonical settings option to a clean valid array. Used as
 * defensive cleanup so the verify phase does not leave a malformed state
 * for any downstream test that shares the same wp_options table.
 *
 * @return void
 */
function supcheckout_bx1_test_restore_canonical() {
    supcheckout_cert_store_option_raw(
        'woocommerce_upayments_settings',
        array(
            'enabled'              => 'yes',
            'api_key'              => '',
            'enable_autodeduction' => 'no',
            'make_default_gateway' => 'no',
        )
    );
}

if ('seed' === $phase) {
    // The seed phase intentionally leaves the canonical option in a clean
    // valid state. Malformed fixtures are persisted only during the verify
    // phase, which is responsible for both seeding and resetting.
    supcheckout_bx1_test_restore_canonical();
    supcheckout_cert_note('malformed-gateway-settings regression seed complete (canonical option left in clean valid state)');
    return;
}

// Verify phase.

/**
 * Apply the live filter to a malformed persisted state and assert the
 * full B-X1 invariant set:
 *   - filter returns an array;
 *   - 'upayments' is removed from the available set;
 *   - 'cod' remains available (no auto-deduction/COD side effect is
 *     derived from malformed settings);
 *   - the canonical raw row read straight from wp_options is byte-for-byte
 *     identical BEFORE and AFTER the filter executes (the filter itself
 *     did not mutate storage).
 *
 * For the stdClass case (where WordPress's options API round-trips objects
 * faithfully), the helper also asserts the persisted type and the
 * maybe_serialize() return value are unchanged — those assertions are
 * equivalent to the raw-bytes comparison for stdClass, but they make the
 * invariant explicit from both the wp_options API surface (get_option) and
 * the underlying SQL surface (raw row). They are deliberately NOT applied
 * to scalar cases because WordPress coerces scalar options through its
 * own serialization envelope on the way out (a `42` is stored as the text
 * `42` and returned as a string by get_option), so the `maybe_serialize()`
 * round-trip would measure WordPress machinery rather than the filter's
 * own behaviour.
 *
 * @param string $name        Sub-test label.
 * @param mixed  $payload     Settings payload to persist at the canonical key.
 * @param bool   $delete      If true, delete the canonical row rather than store.
 * @param array<string,bool> $wp_api_invariants
 *                            Optional `assert_wp_api_invariants` flag — when
 *                            true, also asserts gettype(get_option(...))
 *                            and maybe_serialize(get_option(...)) round-trip
 *                            identically across the filter invocation.
 *                            Only meaningful for shapes where WordPress's
 *                            options API returns the original type
 *                            (stdClass + array).
 * @return void
 */
function supcheckout_bx1_test_assert_invariants($name, $payload, $delete, $wp_api_invariants = false) {
    if ($delete) {
        supcheckout_bx1_test_delete_option_raw('woocommerce_upayments_settings');
        $pre_filter_raw = null;
    } else {
        supcheckout_cert_store_option_raw('woocommerce_upayments_settings', $payload);
        $pre_filter_raw = supcheckout_bx1_test_raw_option('woocommerce_upayments_settings');
    }

    if ($wp_api_invariants) {
        $pre_filter_type = gettype(get_option('woocommerce_upayments_settings'));
        $pre_filter_serialized = maybe_serialize(get_option('woocommerce_upayments_settings'));
    } else {
        $pre_filter_type = null;
        $pre_filter_serialized = null;
    }

    supcheckout_cert_assert(
        is_string($pre_filter_raw) || null === $pre_filter_raw,
        sprintf(
            '%s: pre-filter raw row is readable (type=%s, len=%s)',
            $name,
            gettype($pre_filter_raw),
            is_string($pre_filter_raw) ? (string) strlen($pre_filter_raw) : 'null'
        )
    );

    $result = supcheckout_bx1_test_apply_filter(supcheckout_bx1_test_gateways());

    supcheckout_cert_assert(is_array($result), $name . ': filter result is an array');
    supcheckout_cert_assert(
        !array_key_exists('upayments', $result),
        $name . ": filter removes 'upayments' from the available set on malformed persisted state"
    );
    supcheckout_cert_assert(
        array_key_exists('cod', $result),
        $name . ": filter preserves 'cod' availability on the malformed early-return path"
    );

    if ($delete) {
        $post_filter_raw = supcheckout_bx1_test_raw_option('woocommerce_upayments_settings');
        supcheckout_cert_assert(
            null === $post_filter_raw,
            $name . ': filter does not resurrect a deleted canonical option row'
        );
        return;
    }

    $post_filter_raw = supcheckout_bx1_test_raw_option('woocommerce_upayments_settings');
    supcheckout_cert_assert(
        hash_equals((string) $pre_filter_raw, (string) $post_filter_raw),
        $name . ': filter does not mutate the canonical persisted option (raw bytes identical pre vs post filter)'
    );

    if (!$wp_api_invariants) {
        return;
    }

    $post_filter_type = gettype(get_option('woocommerce_upayments_settings'));
    $post_filter_serialized = maybe_serialize(get_option('woocommerce_upayments_settings'));

    supcheckout_cert_assert(
        $pre_filter_type === $post_filter_type,
        sprintf(
            '%s: filter does not change the canonical persisted type via get_option (pre=%s, post=%s)',
            $name,
            $pre_filter_type,
            $post_filter_type
        )
    );
    supcheckout_cert_assert(
        hash_equals((string) $pre_filter_serialized, (string) $post_filter_serialized),
        $name . ': filter does not change maybe_serialize() of the canonical persisted option'
    );
}

// Case 1 — stdClass settings (the PluginActivationTest sentinel shape).
// Pre-fix the filter would throw "Cannot use object of type stdClass as array"
// here, on any non-admin `woocommerce_available_payment_gateways` invocation
// where `upayments` is being registered, leaving no chance to assert anything
// about the available set. The fix MUST remove 'upayments', preserve 'cod',
// NOT crash, and NOT rewrite the persisted option to an array.
supcheckout_bx1_test_assert_invariants(
    'stdClass settings (PluginActivationTest sentinel shape)',
    (object) array(
        'enabled'               => 'yes',
        'api_key'               => 'certification-sentinel-object',
        'enable_block_checkout' => 'no',
    ),
    false,
    true
);

// Case 2 — scalar string. Raw bytes is the storage-mutation proof; WP
// round-trip mechanics are not asserted (WP double-serializes incoming
// non-marker strings, so get_option / maybe_serialize differ across
// read boundaries for non-marker scalars in ways independent of the
// filter).
supcheckout_bx1_test_assert_invariants(
    'scalar string settings',
    'not-an-array-sentinel',
    false,
    false
);

// Case 2b — integer.
supcheckout_bx1_test_assert_invariants(
    'integer settings',
    42,
    false,
    false
);

// Case 2c — boolean.
supcheckout_bx1_test_assert_invariants(
    'boolean settings',
    true,
    false,
    false
);

// Case 2d — missing option (legacy default `false`).
// The option row is absent; the filter must not silently create one.
supcheckout_bx1_test_assert_invariants(
    'missing-option default-false state',
    null,
    true,
    false
);

// Case 3 — valid array settings regression.
// A fully configured array with api_key set must keep 'upayments' available.
// The make_default_gateway=yes branch is NOT gated by is_checkout(), so it
// is exercisable inside wp eval-file. The pre-filter raw row must equal the
// post-filter raw row (the filter must not silently rewrite a valid array).
supcheckout_cert_store_option_raw(
    'woocommerce_upayments_settings',
    array(
        'enabled'              => 'yes',
        'api_key'              => 'certification-valid-array',
        'enable_autodeduction' => 'yes',
        'make_default_gateway' => 'yes',
    )
);

$pre_filter_raw = supcheckout_bx1_test_raw_option('woocommerce_upayments_settings');

if (!WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}
WC()->session->set('chosen_payment_method', 'upayments');

$valid_result = supcheckout_bx1_test_apply_filter(supcheckout_bx1_test_gateways());

supcheckout_cert_assert(is_array($valid_result), 'valid array settings: filter result is an array');
supcheckout_cert_assert(
    array_key_exists('upayments', $valid_result),
    'valid array settings: filter keeps upayments available'
);
supcheckout_cert_assert(
    'upayments' === WC()->session->get('chosen_payment_method'),
    'valid array settings: make_default_gateway=yes still preserves the chosen payment method'
);

$post_filter_raw = supcheckout_bx1_test_raw_option('woocommerce_upayments_settings');
supcheckout_cert_assert(
    hash_equals($pre_filter_raw, (string) $post_filter_raw),
    'valid array settings: filter does not mutate the canonical persisted option (raw bytes identical pre vs post filter)'
);

// Defensive cleanup so downstream tests sharing wp_options see a known
// clean valid state. This is NOT the preservation evidence — the raw
// byte-for-byte assertion above is.
supcheckout_bx1_test_restore_canonical();
supcheckout_cert_note('malformed-gateway-settings regression certification complete');
