<?php
/**
 * Real-runtime H12-META certification: CustomerTokenIdentity::get_historical_meta_cardinality()
 * against real WooCommerce order metadata.
 *
 * WC_Data::get_meta($key, false) returns array_intersect_key() over the order's whole
 * re-indexed meta list, so a single row of a key keeps its position in that list (index 0
 * only when it is the order's first meta row), and WC_Meta_Data exposes the row through
 * __get/get_data() with no get_value() method. The helper must report exactly one row as
 * META_EXACTLY_ONE with its scalar value wherever the row sits, and must still report two
 * rows as META_DUPLICATE_OR_INVALID.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

/**
 * Reload an order from storage exactly as the production inspectors do.
 *
 * @param int $order_id Order ID.
 * @return WC_Order
 */
function supcheckout_cert_meta_reload($order_id) {
    $order = wc_get_order($order_id);
    supcheckout_cert_assert($order instanceof WC_Order, 'order ' . $order_id . ' reloads from storage');
    supcheckout_cert_assert(CustomerTokenIdentity::force_refresh_order_meta($order), 'order ' . $order_id . ' meta force-refreshes');
    return $order;
}

supcheckout_cert_assert(function_exists('wc_create_order'), 'WooCommerce order API is available');
supcheckout_cert_assert(class_exists('WC_Meta_Data'), 'WooCommerce WC_Meta_Data is loaded');
supcheckout_cert_assert(!method_exists('WC_Meta_Data', 'get_value'), 'real WC_Meta_Data has no get_value() method');

$token_key = '_upay_customer_unique_token';
$created_orders = array();

try {
    // Case 1: one row of the key written after other meta rows (array index is not 0).
    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'single-row certification order is created');
    $created_orders[] = $order->get_id();
    $order->add_meta_data('_supcheckout_cert_filler_a', 'a');
    $order->add_meta_data('_supcheckout_cert_filler_b', 'b');
    $order->save();
    $order->add_meta_data($token_key, '1234567890');
    $order->save();

    $order = supcheckout_cert_meta_reload($order->get_id());
    $rows = $order->get_meta($token_key, false, 'edit');
    supcheckout_cert_assert(is_array($rows) && count($rows) === 1, 'real get_meta(false) returns exactly one row for the key');
    $row_index = key($rows);
    supcheckout_cert_assert($row_index !== 0, 'real get_meta(false) keeps the row position in the order meta list (index ' . var_export($row_index, true) . ', not 0)');

    $result = CustomerTokenIdentity::get_historical_meta_cardinality($order, $token_key);
    supcheckout_cert_assert(CustomerTokenIdentity::META_EXACTLY_ONE === $result['status'], 'one row at a non-zero index is META_EXACTLY_ONE');
    supcheckout_cert_assert('1234567890' === $result['value'], 'one row at a non-zero index returns its scalar value');

    // Case 2: two rows of the key stay duplicate.
    $order->add_meta_data($token_key, '1234567890');
    $order->save();
    $order = supcheckout_cert_meta_reload($order->get_id());
    $rows = $order->get_meta($token_key, false, 'edit');
    supcheckout_cert_assert(is_array($rows) && count($rows) === 2, 'real get_meta(false) returns both rows for the key');
    $result = CustomerTokenIdentity::get_historical_meta_cardinality($order, $token_key);
    supcheckout_cert_assert(CustomerTokenIdentity::META_DUPLICATE_OR_INVALID === $result['status'], 'two rows of the key stay META_DUPLICATE_OR_INVALID');
    supcheckout_cert_assert(null === $result['value'], 'two rows of the key return no value');

    // Case 3: the key as the order's first meta row (array index 0). Remove every visible
    // meta row a fresh order may carry first, so the key's row is guaranteed to come first.
    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'first-row certification order is created');
    $created_orders[] = $order->get_id();
    foreach ($order->get_meta_data() as $existing_row) {
        $order->delete_meta_data_by_mid($existing_row->id);
    }
    $order->save();
    $order = supcheckout_cert_meta_reload($order->get_id());
    // HPOS storage re-adds WooCommerce's own meta rows on save, so a fresh HPOS order may not
    // start empty. Prove the index-0 path wherever the storage allows it; elsewhere the
    // harness case META-2 proves it and this case still proves the single-row read.
    $starts_empty = count($order->get_meta_data()) === 0;
    $order->add_meta_data($token_key, '9876543210');
    $order->save();
    $order->add_meta_data('_supcheckout_cert_filler_c', 'c');
    $order->save();
    $order = supcheckout_cert_meta_reload($order->get_id());
    $rows = $order->get_meta($token_key, false, 'edit');
    supcheckout_cert_assert(is_array($rows) && count($rows) === 1, 'first-row order returns exactly one row for the key');
    if ($starts_empty) {
        supcheckout_cert_assert(key($rows) === 0, 'the key is the order\'s first meta row (index 0)');
    } else {
        echo 'NOTE: this storage keeps WooCommerce meta rows on a fresh order; the key sits at index ' . (int) key($rows) . " (index 0 is proven by harness case META-2)\n";
    }
    $result = CustomerTokenIdentity::get_historical_meta_cardinality($order, $token_key);
    supcheckout_cert_assert(CustomerTokenIdentity::META_EXACTLY_ONE === $result['status'], 'one row of the key is META_EXACTLY_ONE and does not throw');
    supcheckout_cert_assert('9876543210' === $result['value'], 'one row of the key returns its scalar value');

    // Case 4: an absent key stays absent.
    $result = CustomerTokenIdentity::get_historical_meta_cardinality($order, '_upay_customer_token_kind_v1');
    supcheckout_cert_assert(CustomerTokenIdentity::META_ABSENT === $result['status'], 'an absent key stays META_ABSENT');
} finally {
    foreach ($created_orders as $created_order_id) {
        $created_order = wc_get_order($created_order_id);
        if ($created_order) {
            $created_order->delete(true);
        }
    }
}

supcheckout_cert_note('historical order meta cardinality runtime certification complete');
