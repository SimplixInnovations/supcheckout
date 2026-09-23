<?php

namespace Simplixi\SUPCheckout\Gateway;

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
        if (!$order instanceof \WC_Order
            || (string) $order->get_payment_method() !== (string) $gateway_id
        ) {
            return $total_rows;
        }

        $payment_status = $order->get_meta('UPayments_Result');
        $upayment_id = $order->get_meta('UPayments_PaymentID');
        $new_total_rows = array();

        foreach ($total_rows as $key => $total) {
            $new_total_rows[$key] = $total;
            if ('payment_method' === $key) {
                $new_total_rows['payment_status'] = array(
                    'label' => 'Payment Status:',
                    'value' => $payment_status,
                );
                if (!empty($upayment_id)) {
                    $new_total_rows['upayment_id'] = array(
                        'label' => 'UPayment ID:',
                        'value' => $upayment_id,
                    );
                }
            }
        }

        return $new_total_rows;
    }

    private function __construct() {}
}
