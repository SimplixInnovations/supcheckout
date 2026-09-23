<?php
/**
 * Real WooCommerce E2 regression for mixed tax-class order economics.
 *
 * Standard, reduced and zero-rate line tax identities are persisted through Woo
 * CRUD. SUPCheckout must still treat the finalized Woo order total/currency as
 * payment truth; provider products[] remains descriptive line data only.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;

if (! WC()->session) {
    WC()->session = new WC_Session_Handler();
    WC()->session->init();
}

$created_classes = array();
$rate_ids = array();
$products = array();
$order = null;

$ensure_tax_class = static function ($name, $slug) use (&$created_classes) {
    if (in_array($slug, WC_Tax::get_tax_class_slugs(), true)) {
        return;
    }

    $created = WC_Tax::create_tax_class($name, $slug);
    supcheckout_cert_assert(is_array($created) && isset($created['slug']) && $slug === $created['slug'], 'mixed-tax fixture creates tax class: ' . $slug);
    $created_classes[] = $slug;
};

$create_rate = static function ($name, $rate, $class) use (&$rate_ids) {
    $rate_id = WC_Tax::_insert_tax_rate(
        array(
            'tax_rate_country'  => 'KW',
            'tax_rate_state'    => '',
            'tax_rate'          => $rate,
            'tax_rate_name'     => $name,
            'tax_rate_priority' => '1',
            'tax_rate_compound' => '0',
            'tax_rate_shipping' => '0',
            'tax_rate_order'    => '1',
            'tax_rate_class'    => $class,
        )
    );
    supcheckout_cert_assert(is_int($rate_id) && $rate_id > 0, 'mixed-tax fixture persists tax rate: ' . $name);
    $rate_ids[] = $rate_id;
    return $rate_id;
};

$create_product = static function ($name, $price, $tax_class) use (&$products) {
    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_regular_price($price);
    $product->set_price($price);
    $product->set_tax_status('taxable');
    $product->set_tax_class($tax_class);
    $product_id = $product->save();
    supcheckout_cert_assert(is_int($product_id) && $product_id > 0, 'mixed-tax fixture persists product: ' . $name);
    $products[] = $product;
    return $product;
};

$add_tax_item = static function ($order, $rate_id, $label, $tax_total) {
    $tax = new WC_Order_Item_Tax();
    $tax->set_rate_id($rate_id);
    $tax->set_label($label);
    $tax->set_tax_total($tax_total);
    $tax->set_shipping_tax_total('0');
    $order->add_item($tax);
};

try {
    $ensure_tax_class('Reduced rate', 'reduced-rate');
    $ensure_tax_class('Zero rate', 'zero-rate');

    supcheckout_cert_assert(in_array('reduced-rate', WC_Tax::get_tax_class_slugs(), true), 'mixed-tax fixture exposes reduced-rate class');
    supcheckout_cert_assert(in_array('zero-rate', WC_Tax::get_tax_class_slugs(), true), 'mixed-tax fixture exposes zero-rate class');

    $standard_rate = $create_rate('E2 Standard VAT', '5.0000', '');
    $reduced_rate  = $create_rate('E2 Reduced VAT', '2.5000', 'reduced-rate');
    $zero_rate     = $create_rate('E2 Zero VAT', '0.0000', 'zero-rate');

    $standard = $create_product('E2 Standard Tax Product', '10.000', '');
    $reduced  = $create_product('E2 Reduced Tax Product', '20.000', 'reduced-rate');
    $zero     = $create_product('E2 Zero Tax Product', '5.000', 'zero-rate');

    $order = wc_create_order();
    supcheckout_cert_assert($order instanceof WC_Order, 'mixed-tax Woo order is created');
    $order->add_product($standard, 1);
    $order->add_product($reduced, 1);
    $order->add_product($zero, 1);
    $order->set_payment_method('upayments');
    $order->set_billing_first_name('Mixed');
    $order->set_billing_last_name('Tax');
    $order->set_billing_email('mixed-tax@example.invalid');
    $order->set_billing_phone('50000000');
    $order->set_billing_country('KW');
    $order->set_currency('KWD');

    $lines = array_values($order->get_items('line_item'));
    supcheckout_cert_assert(3 === count($lines), 'mixed-tax order has three real Woo line items');

    $line_specs = array(
        array('', $standard_rate, '10.000', '0.500'),
        array('reduced-rate', $reduced_rate, '20.000', '0.500'),
        array('zero-rate', $zero_rate, '5.000', '0'),
    );
    foreach ($lines as $index => $line) {
        supcheckout_cert_assert($line instanceof WC_Order_Item_Product, 'mixed-tax line is a Woo product item: ' . $index);
        $spec = $line_specs[$index];
        $line->set_tax_class($spec[0]);
        $line->set_subtotal($spec[2]);
        $line->set_total($spec[2]);
        $line->set_taxes(
            array(
                'subtotal' => array($spec[1] => $spec[3]),
                'total'    => array($spec[1] => $spec[3]),
            )
        );
        $line->save();
    }

    $add_tax_item($order, $standard_rate, 'E2 Standard VAT', '0.500');
    $add_tax_item($order, $reduced_rate, 'E2 Reduced VAT', '0.500');
    $add_tax_item($order, $zero_rate, 'E2 Zero VAT', '0');
    $order->set_cart_tax('1.000');
    $order->set_total('36.000');
    $order->save();

    $order = wc_get_order($order->get_id());
    supcheckout_cert_assert($order instanceof WC_Order, 'mixed-tax order reloads through Woo CRUD');
    supcheckout_cert_assert('KWD' === $order->get_currency(), 'mixed-tax order preserves finalized Woo currency');
    supcheckout_cert_assert(36.0 === (float) $order->get_total(), 'mixed-tax order preserves finalized Woo grand total');

    $reloaded_lines = array_values($order->get_items('line_item'));
    supcheckout_cert_assert(3 === count($reloaded_lines), 'mixed-tax order reloads all three product lines');
    supcheckout_cert_assert('' === $reloaded_lines[0]->get_tax_class(), 'standard-tax line identity persists');
    supcheckout_cert_assert('reduced-rate' === $reloaded_lines[1]->get_tax_class(), 'reduced-tax line identity persists');
    supcheckout_cert_assert('zero-rate' === $reloaded_lines[2]->get_tax_class(), 'zero-rate line identity persists');
    supcheckout_cert_assert(1.0 === (float) $order->get_total_tax(), 'mixed-tax order reloads aggregate Woo tax independently of payment authority');
    supcheckout_cert_assert(3 === count($order->get_items('tax')), 'mixed-tax order persists standard/reduced/zero tax items');

    $gateway = new WC_Upayments();
    $gateway->domain = 'upayments';
    $gateway->apiKey = 'certification-api-key';
    $gateway->testMode = 'yes';
    $gateway->autoDeduction = 'no';
    $gateway->saveCardEnabled = 'no';
    $gateway->paymentData = array('whitelabled' => false, 'payment' => array());
    $gateway->multiMerchant = 'no';

    $_POST = array();
    wc_clear_notices();
    $calls = array();
    $orchestrator = new CheckoutOrchestrator(
        $gateway,
        static function () {
            return '';
        },
        static function ($route, $method, $body = null) use (&$calls) {
            $calls[] = array('route' => $route, 'method' => $method, 'body' => $body);
            return array(
                'transport_ok' => false,
                'http_status'  => 0,
                'curl_errno'   => 7,
                'body'         => '',
            );
        }
    );
    $orchestrator->process($order->get_id());

    supcheckout_cert_assert(1 === count($calls), 'mixed-tax order reaches exactly one provider request');
    supcheckout_cert_assert('charge' === $calls[0]['route'] && 'POST' === $calls[0]['method'], 'mixed-tax order reaches Charge POST');
    supcheckout_cert_assert(is_string($calls[0]['body']) && '' !== $calls[0]['body'], 'mixed-tax Charge body is captured');

    $payload = json_decode($calls[0]['body'], true);
    supcheckout_cert_assert(is_array($payload), 'mixed-tax Charge body is valid JSON');
    supcheckout_cert_assert(isset($payload['order']) && is_array($payload['order']), 'mixed-tax Charge contains structured order economics');
    supcheckout_cert_assert('KWD' === ($payload['order']['currency'] ?? null), 'mixed-tax Charge structurally preserves finalized Woo currency');
    supcheckout_cert_assert(36.0 === (float) ($payload['order']['amount'] ?? 0), 'mixed-tax Charge structurally preserves finalized Woo amount');
    supcheckout_cert_assert(isset($payload['products']) && 3 === count($payload['products']), 'mixed-tax products[] remains three descriptive purchased lines');
    supcheckout_cert_assert(! isset($payload['taxes']) && ! isset($payload['order']['tax']), 'SUPCheckout does not invent a parallel provider tax ledger');

    supcheckout_cert_note('mixed standard/reduced/zero tax-class economics certification complete');
} finally {
    if ($order instanceof WC_Order) {
        $order->delete(true);
    }
    foreach ($products as $product) {
        if ($product instanceof WC_Product && $product->get_id() > 0) {
            wp_delete_post($product->get_id(), true);
        }
    }
    foreach ($rate_ids as $rate_id) {
        WC_Tax::_delete_tax_rate($rate_id);
    }
    foreach (array_reverse($created_classes) as $slug) {
        WC_Tax::delete_tax_class_by('slug', $slug);
    }
    $_POST = array();
    wc_clear_notices();
}
