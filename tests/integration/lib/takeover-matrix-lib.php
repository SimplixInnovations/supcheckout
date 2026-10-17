<?php
/**
 * Helpers for tests/integration/TakeoverMatrixTest.php (Sub-project 0b Task 12): launched
 * WP-CLI commands, raw-storage snapshots for byte-for-byte comparisons, provider fixtures
 * for the matrix must-use plugin, and order-meta equality. Never shipped.
 *
 * Works in a pre-0b SUPCheckout runtime too (the pre-0b lane seeds before the 0b package is
 * installed): Keys is loaded from this checkout when the active plugin does not provide it.
 * Keys' maps are pure static data.
 */

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

if (!class_exists(Keys::class)) {
    require_once dirname(__DIR__, 3) . '/src/Takeover/Keys.php';
}

const SUPCHECKOUT_MATRIX_FIXTURES = 'supcheckout_matrix_provider_fixtures';
const SUPCHECKOUT_MATRIX_CALLS = 'supcheckout_matrix_provider_calls';
const SUPCHECKOUT_MATRIX_DENIED = 'supcheckout_matrix_provider_denied';
const SUPCHECKOUT_MATRIX_PAYMENT_COMPLETE = 'supcheckout_matrix_payment_complete';
const SUPCHECKOUT_MATRIX_OFFICIAL = 'upayments/UPayments.php';

/**
 * Run `wp <command>` in a fresh process, exactly as an operator would (a plugin's
 * `supcheckout takeover` command is deferred by WP-CLI and cannot run in this process).
 *
 * @param string $command Command after `wp`.
 * @return object{stdout:string, stderr:string, return_code:int}
 */
function supcheckout_matrix_wp($command) {
    $result = WP_CLI::runcommand($command, array('return' => 'all', 'launch' => true, 'exit_error' => false));
    supcheckout_cert_note('wp ' . $command . ' -> exit ' . $result->return_code);
    return $result;
}

/**
 * @param string $command Command after `wp`.
 * @return array<string, mixed>
 */
function supcheckout_matrix_wp_json($command) {
    $result = supcheckout_matrix_wp($command);
    $decoded = json_decode(trim($result->stdout), true);
    supcheckout_cert_assert(is_array($decoded), '`wp ' . $command . '` prints one JSON document: ' . $result->stdout . $result->stderr);
    return $decoded;
}

/** @return string Directory for the cell's evidence files. */
function supcheckout_matrix_dir() {
    $dir = (string) getenv('SUPCHECKOUT_MATRIX_DIR');
    supcheckout_cert_assert($dir !== '' && is_dir($dir) && is_writable($dir), 'SUPCHECKOUT_MATRIX_DIR is a writable directory');
    return rtrim($dir, '/');
}

/**
 * @param string $name File name.
 * @param mixed  $data JSON-encodable data.
 * @return void
 */
function supcheckout_matrix_save($name, $data) {
    $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    supcheckout_cert_assert(is_string($json) && file_put_contents(supcheckout_matrix_dir() . '/' . $name, $json) !== false, 'evidence file written: ' . $name);
}

/**
 * @param string $name File name.
 * @return array<string, mixed>
 */
function supcheckout_matrix_load($name) {
    $raw = file_get_contents(supcheckout_matrix_dir() . '/' . $name);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    supcheckout_cert_assert(is_array($data), 'evidence file readable: ' . $name);
    return $data;
}

/** @return bool HPOS is the authoritative order store. */
function supcheckout_matrix_hpos() {
    return class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/** @return void Drop every cached object so the next read comes from the database. */
function supcheckout_matrix_flush() {
    wp_cache_flush();
    if (class_exists(\Simplixi\SUPCheckout\Takeover\TakeoverState::class, false)) {
        \Simplixi\SUPCheckout\Takeover\TakeoverState::refresh();
    }
}

/**
 * Raw rows of one order's meta in the authoritative store, in row order.
 *
 * @param int               $order_id Order.
 * @param list<string>|null $keys     Only these keys (null: all).
 * @return list<array{0:string,1:string}> [meta_key, raw meta_value]
 */
function supcheckout_matrix_raw_order_meta($order_id, $keys = null) {
    global $wpdb;
    if (supcheckout_matrix_hpos()) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE order_id = %d ORDER BY id', $wpdb->prefix . 'wc_orders_meta', $order_id), ARRAY_N);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE post_id = %d ORDER BY meta_id', $wpdb->postmeta, $order_id), ARRAY_N);
    }
    $out = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        if ($keys === null || in_array($row[0], $keys, true)) {
            $out[] = array((string) $row[0], (string) $row[1]);
        }
    }
    return $out;
}

/**
 * Raw legacy-name user meta of one user.
 *
 * @param int $user_id User.
 * @return list<array{0:string,1:string}>
 */
function supcheckout_matrix_raw_user_meta($user_id) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE user_id = %d ORDER BY umeta_id', $wpdb->usermeta, $user_id), ARRAY_N);
    $out = array();
    foreach (is_array($rows) ? $rows : array() as $row) {
        $legacy = array_key_exists($row[0], Keys::user_meta());
        foreach (array_keys(Keys::user_meta_prefixes()) as $prefix) {
            $legacy = $legacy || strpos((string) $row[0], $prefix) === 0;
        }
        if ($legacy) {
            $out[] = array((string) $row[0], (string) $row[1]);
        }
    }
    return $out;
}

/**
 * @param string $name Option.
 * @return string|null The raw stored value, or null when absent.
 */
function supcheckout_matrix_raw_option($name) {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name));
    return $value === null ? null : (string) $value;
}

/**
 * @param string $table Table name without prefix.
 * @return list<array<string, mixed>>|null Rows by cycle key, or null when the table is absent.
 */
function supcheckout_matrix_billing_rows($table) {
    global $wpdb;
    $name = $wpdb->prefix . $table;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) !== $name) {
        return null;
    }
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY cycle_key', $name), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/**
 * Everything the takeover may write under a legacy name, read raw: the values Rollback
 * must restore byte for byte.
 *
 * @param list<int>    $order_ids Orders.
 * @param list<int>    $user_ids  Users.
 * @param list<string> $extra     More option names (third-party shapes).
 * @return array<string, mixed>
 */
function supcheckout_matrix_snapshot(array $order_ids, array $user_ids, array $extra) {
    supcheckout_matrix_flush();
    $options = array();
    $names = array_merge(Keys::settings_sources(), array_keys(Keys::options()),
        array('woocommerce_gateway_order', 'woocommerce_default_gateway', 'woocommerce_currency'), $extra);
    foreach (array_unique($names) as $name) {
        $options[$name] = supcheckout_matrix_raw_option($name);
    }
    $orders = array();
    foreach ($order_ids as $order_id) {
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof WC_Order) {
            throw new RuntimeException('FAIL: snapshot: order ' . $order_id . ' does not load');
        }
        $orders[(string) $order_id] = array(
            'payment_method' => $order->get_payment_method(),
            'status' => $order->get_status(),
            'transaction_id' => $order->get_transaction_id(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'meta' => supcheckout_matrix_raw_order_meta((int) $order_id, array_keys(Keys::order_meta())),
        );
    }
    $users = array();
    foreach ($user_ids as $user_id) {
        $users[(string) $user_id] = supcheckout_matrix_raw_user_meta((int) $user_id);
    }
    return array(
        'options' => $options,
        'orders' => $orders,
        'users' => $users,
        'billing_legacy' => supcheckout_matrix_billing_rows('upayments_billing_attempts'),
    );
}

/**
 * Byte-for-byte comparison of two snapshots, except the orders a post-cutover runtime
 * write legitimately changed (asserted separately by the caller).
 *
 * @param array<string, mixed> $before  Pre-switch snapshot.
 * @param array<string, mixed> $after   Snapshot now.
 * @param list<int>            $touched Orders written after the cutover.
 * @return void
 */
function supcheckout_matrix_assert_restored(array $before, array $after, array $touched) {
    foreach ($before['options'] as $name => $value) {
        if ($value === null && $after['options'][$name] !== null && array_key_exists($name, Keys::options())) {
            // SUPCheckout-owned state the runtime first created after the cutover (for
            // example the billing schema version, with the new billing table) is back-synced
            // to its legacy name, so the legacy runtime sees the same state. Not a pre-switch value.
            supcheckout_cert_note('option ' . $name . ' did not exist before the switch; created after the cutover and back-synced');
            if ($name === 'upay_billing_cycle_schema_version') {
                supcheckout_cert_assert(is_array($after['billing_legacy']), 'the back-synced billing schema version comes with the legacy billing table');
            }
            continue;
        }
        supcheckout_cert_assert($after['options'][$name] === $value, 'option ' . $name . ' restored byte for byte');
    }
    $checked = 0;
    foreach ($before['orders'] as $order_id => $order) {
        if (in_array((int) $order_id, $touched, true)) {
            continue;
        }
        if ($after['orders'][$order_id] !== $order) {
            throw new RuntimeException('FAIL: order ' . $order_id . ' differs from its pre-switch values: ' . wp_json_encode(array('before' => $order, 'after' => $after['orders'][$order_id])));
        }
        ++$checked;
    }
    supcheckout_cert_assert($checked > 0, $checked . ' untouched orders hold exactly their pre-switch payment method, status, transaction, totals and legacy meta rows');
    foreach ($before['users'] as $user_id => $rows) {
        supcheckout_cert_assert($after['users'][$user_id] === $rows, 'user ' . $user_id . ': legacy user meta restored byte for byte');
    }
    if (is_array($before['billing_legacy'])) {
        $now = array();
        foreach ((array) $after['billing_legacy'] as $row) {
            $now[$row['cycle_key']] = $row;
        }
        foreach ($before['billing_legacy'] as $row) {
            supcheckout_cert_assert(isset($now[$row['cycle_key']]) && $now[$row['cycle_key']] === $row, 'legacy billing row ' . substr($row['cycle_key'], 0, 12) . ' restored byte for byte');
        }
    }
}

/**
 * Every legacy order-meta key's value list equals its new-name list, in order (no row
 * dropped, none added twice).
 *
 * @param WC_Order $order Order.
 * @return array<string, list<string>> New-name lists, for a later same-result comparison.
 */
function supcheckout_matrix_assert_copied(WC_Order $order) {
    $lists = array();
    foreach (Keys::order_meta() as $legacy => $new) {
        $legacy_values = array_map('maybe_serialize', \Simplixi\SUPCheckout\Takeover\OrderCopier::values($order, $legacy));
        $new_values = array_map('maybe_serialize', \Simplixi\SUPCheckout\Takeover\OrderCopier::values($order, $new));
        if ($legacy_values !== $new_values) {
            throw new RuntimeException('FAIL: order ' . $order->get_id() . ' key ' . $legacy . ': legacy rows ' . wp_json_encode($legacy_values) . ' != new rows ' . wp_json_encode($new_values));
        }
        if ($new_values !== array()) {
            $lists[$new] = $new_values;
        }
    }
    return $lists;
}

/**
 * Replace (or add) provider fixtures served by the matrix must-use plugin.
 *
 * @param array<string, array{code:int, body:string}> $fixtures Route => response.
 * @return void
 */
function supcheckout_matrix_fixtures(array $fixtures) {
    wp_cache_delete(SUPCHECKOUT_MATRIX_FIXTURES, 'options');
    $current = get_option(SUPCHECKOUT_MATRIX_FIXTURES, array());
    update_option(SUPCHECKOUT_MATRIX_FIXTURES, array_merge(is_array($current) ? $current : array(), $fixtures), false);
}

/**
 * @param string $option Counter option.
 * @param string $name   Counter.
 * @return int
 */
function supcheckout_matrix_counter($option, $name) {
    wp_cache_delete($option, 'options');
    $counts = get_option($option, array());
    return is_array($counts) && isset($counts[$name]) ? (int) $counts[$name] : 0;
}

/** @return void */
function supcheckout_matrix_assert_no_denied_provider_call() {
    wp_cache_delete(SUPCHECKOUT_MATRIX_DENIED, 'options');
    $denied = get_option(SUPCHECKOUT_MATRIX_DENIED, array());
    supcheckout_cert_assert($denied === array(), 'no provider request without a fixture was attempted: ' . wp_json_encode($denied));
}

/**
 * @param array<string, mixed> $report Preflight report.
 * @param string               $list   blocking, warnings or facts.
 * @param string               $code   Finding code.
 * @return array<string, mixed>|null
 */
function supcheckout_matrix_finding(array $report, $list, $code) {
    foreach ($report[$list] as $finding) {
        if ($finding['code'] === $code) {
            return $finding;
        }
    }
    return null;
}

/**
 * Rows of a billing table for one parent order.
 *
 * @param string $table  Table without prefix.
 * @param int    $parent Parent order id.
 * @return list<array<string, mixed>>
 */
function supcheckout_matrix_claims($table, $parent) {
    $rows = supcheckout_matrix_billing_rows($table);
    return array_values(array_filter(is_array($rows) ? $rows : array(), static function ($row) use ($parent) {
        return (int) $row['parent_order_id'] === (int) $parent;
    }));
}

/**
 * Run the renewal of one parent through SUPCheckout's scheduler, as its Action Scheduler
 * worker does, against the stubbed provider.
 *
 * @param int $parent_id Parent order.
 * @return string Scheduler outcome.
 */
function supcheckout_matrix_renew($parent_id) {
    supcheckout_matrix_flush();
    $parent = wc_get_order($parent_id);
    supcheckout_cert_assert($parent instanceof WC_Order, 'the subscription parent loads');
    return \Simplixi\SUPCheckout\Subscription\Cron\Scheduler::process_parent_order($parent);
}

/**
 * The provider fixtures a renewal needs: the explicit card is one of the customer's saved
 * cards, and the auto-deduct answer is success-shaped and bound to the cycle economics.
 *
 * @param string $customer_token Customer token.
 * @param string $card_token     Card token.
 * @param string $amount         Parent total.
 * @param string $reference      Parent UPayments_Ref.
 * @return void
 */
function supcheckout_matrix_renewal_fixtures($customer_token, $card_token, $amount, $reference) {
    supcheckout_matrix_fixtures(array(
        'retrieve-customer-cards' => array('code' => 201, 'body' => (string) wp_json_encode(array(
            'status' => true,
            'data' => array('customerUniqueToken' => $customer_token, 'customerCards' => array(array('token' => $card_token, 'number' => '4111 11** **** 4242'))),
        ))),
        'auto-deduct' => array('code' => 201, 'body' => (string) wp_json_encode(array(
            'status' => true,
            'data' => array('transaction' => array(
                'paymentId' => 'matrix-renewal-pay-1',
                'paid_amount' => $amount,
                'paid_currency' => 'KWD',
                'reference' => $reference,
                'trackId' => 'matrix-renewal-track-1',
                'invoiceId' => 'matrix-renewal-invoice-1',
            )),
        ))),
    ));
}

/**
 * The renewal contract after the switch: exactly one claim in the new billing table,
 * exactly one auto-deduct request, never a second one, and no paid renewal order (automatic
 * recurring VERIFIED_SUCCESS is FAIL-CLOSED: the cycle is held for reconciliation).
 *
 * @param int $parent_id         Parent order.
 * @param int $renewals_before   Renewal orders of the parent before.
 * @return void
 */
function supcheckout_matrix_assert_renewal_once($parent_id, $renewals_before) {
    $calls_before = supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct');
    $outcome = supcheckout_matrix_renew($parent_id);
    supcheckout_cert_note('renewal outcome: ' . $outcome);
    supcheckout_cert_assert($outcome === 'held', 'the pre-switch parent renewed through SUPCheckout: claimed, dispatched once and held (auto-deduct capture stays fail-closed)');
    supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === $calls_before + 1, 'exactly one auto-deduct request was sent');
    $claims = supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id);
    supcheckout_cert_assert(count($claims) === 1 && $claims[0]['state'] === 'held', 'exactly one claim for the cycle, in supcheckout_billing_attempts: ' . wp_json_encode($claims));
    supcheckout_cert_assert(supcheckout_matrix_claims('upayments_billing_attempts', $parent_id) === array(), 'the legacy billing table holds no claim for it (single-written new table while cut over)');

    $again = supcheckout_matrix_renew($parent_id);
    supcheckout_cert_assert($again !== 'held' && $again !== 'dispatching_or_ambiguous', 'a second run for the same cycle claims nothing: ' . $again);
    supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === $calls_before + 1, 'the second run sent no auto-deduct request');
    supcheckout_cert_assert(count(supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id)) === 1, 'still exactly one claim for the cycle');
    supcheckout_cert_assert(supcheckout_matrix_renewal_count($parent_id) === $renewals_before, 'no renewal order was created');
}

/**
 * @param int $parent_id Parent order.
 * @return int Renewal orders that name this parent.
 */
function supcheckout_matrix_renewal_count($parent_id) {
    $count = 0;
    foreach (wc_get_orders(array('limit' => -1, 'return' => 'objects', 'payment_method' => array('upayments', 'supcheckout'))) as $order) {
        if ((int) Keys::read_order_meta($order, 'UPayments_ParentOrderID') === (int) $parent_id) {
            ++$count;
        }
    }
    return $count;
}
