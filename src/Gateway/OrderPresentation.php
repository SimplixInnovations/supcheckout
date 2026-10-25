<?php

namespace Simplixi\SUPCheckout\Gateway;

use Simplixi\SUPCheckout\Takeover\Keys;

defined('ABSPATH') || exit;

/**
 * Gateway-specific WooCommerce order-total presentation.
 *
 * The global WooCommerce order-total filter invokes every registered
 * callback for every gateway. This boundary therefore treats orders
 * paid through another gateway as immutable foreign presentation data.
 */
final class OrderPresentation {
    /**
     * Append verified UPayments metadata to a UPayments order only.
     *
     * @param array  $total_rows Existing WooCommerce total rows.
     * @param mixed  $order WooCommerce order object.
     * @param string $gateway_id SUPCheckout gateway ID.
     * @return array
     */
    public static function add_order_item_totals($total_rows, $order, $gateway_id) {
        // Historical orders keep payment_method 'upayments' after cutover, so either
        // SUPCheckout identity owns the order (Keys::is_our_gateway()).
        if (!$order instanceof \WC_Order
            || !Keys::is_our_gateway((string) $gateway_id)
            || !Keys::is_our_gateway((string) $order->get_payment_method())
        ) {
            return $total_rows;
        }

        $payment_status = Keys::read_order_meta($order, 'UPayments_Result');
        $upayment_id = Keys::read_order_meta($order, 'UPayments_PaymentID');
        $new_total_rows = array();

        foreach ($total_rows as $key => $total) {
            $new_total_rows[$key] = $total;
            if ('payment_method' === $key) {
                $new_total_rows['payment_status'] = array(
                    'label' => __('Payment Status:', 'supcheckout'),
                    'value' => $payment_status,
                );
                if (!empty($upayment_id)) {
                    $new_total_rows['upayment_id'] = array(
                        'label' => __('UPayments payment ID:', 'supcheckout'),
                        'value' => $upayment_id,
                    );
                }
            }
        }

        return $new_total_rows;
    }

    private function __construct() {}
}
