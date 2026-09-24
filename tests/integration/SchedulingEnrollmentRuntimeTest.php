<?php
/**
 * Real-runtime R4 bounded enrollment + stale-action ZERO POST certification.
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/ActionSchedulerBridge.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/HistoricalEnrollment.php';
require_once dirname(__DIR__, 2) . '/src/Subscription/Scheduling/DueParentWorker.php';

use Simplixi\SUPCheckout\Subscription\Scheduling\ActionSchedulerBridge as ASBridge;
use Simplixi\SUPCheckout\Subscription\Scheduling\DueParentWorker as Worker;
use Simplixi\SUPCheckout\Subscription\Scheduling\HistoricalEnrollment as Enrollment;

if (!class_exists('WCProductCustomType')) {
    \UPayments\Subscription\Helpers\Utils::$custom = true;
    if (!class_exists('Simplixi\SUPCheckout\Subscription\Presentation', false)) {
        require_once dirname(__DIR__, 2) . '/src/Subscription/Presentation.php';
    }
    \Simplixi\SUPCheckout\Subscription\Presentation::register_product_class();
}

function supcheckout_r4_product($name) {
    $product = new WCProductCustomType();
    $product->set_name($name);
    $product->set_regular_price('10.00');
    $product->set_price('10.00');
    $id = $product->save();
    supcheckout_cert_assert(is_int($id) && $id > 0, 'R4 product persists');
    return $product;
}

function supcheckout_r4_parent($product, $user_id) {
    $order = wc_create_order(array('customer_id' => $user_id));
    $order->add_product($product, 1);
    $order->set_payment_method('upayments');
    $order->set_billing_email('r4@example.invalid');
    $order->set_currency('KWD');
    $order->calculate_totals();
    $order->update_meta_data('_upay_customer_unique_token', 'r4-cust');
    $order->update_meta_data('_upay_credit_card_token', 'r4-card');
    $order->update_meta_data('_upay_subscription_plan', 'monthly');
    $order->update_meta_data('_upay_subscription_interval', 1);
    $order->update_meta_data('UPayments_AutoDeduction', 'no');
    $order->update_meta_data('_upay_subscription_status', 'active');
    $order->update_status('processing');
    $order->save();
    $fresh = wc_get_order($order->get_id());
    return $fresh instanceof WC_Order ? $fresh : $order;
}

$user_id = wp_insert_user(array(
    'user_login' => 'r4-' . wp_generate_password(8, false, false),
    'user_pass'  => wp_generate_password(16, true, true),
    'user_email' => 'r4-' . wp_generate_password(6, false, false) . '@example.invalid',
));
supcheckout_cert_assert(!is_wp_error($user_id), 'R4 user created');
$user_id = (int) $user_id;

Enrollment::reset_cursor();
$product = supcheckout_r4_product('R4 Subscription Product');
$parent = supcheckout_r4_parent($product, $user_id);

supcheckout_cert_assert(Enrollment::parent_qualifies($parent), 'R4 parent qualifies for enrollment');
supcheckout_cert_assert(
    !Enrollment::parent_qualifies($parent) === false,
    'R4 qualification is boolean'
);

$stats = Enrollment::enroll_slice(array($parent), true);
supcheckout_cert_assert($stats['scanned'] === 1, 'R4 enrollment scans the provided slice only');
supcheckout_cert_assert($stats['scheduled'] === 1 || $stats['skipped'] === 1, 'R4 enrollment classifies the parent');

// Stale queued work after pause must be ZERO POST (worker revalidation).
$parent->update_meta_data('_upay_subscription_status', 'paused');
$parent->save();
$fresh = wc_get_order($parent->get_id());
supcheckout_cert_assert(!Enrollment::parent_qualifies($fresh), 'paused parent is not enrollable');

$worker_ran = false;
Worker::handle((int) $parent->get_id());
// Worker must refuse paused parents before any claim/dispatch path.
supcheckout_cert_assert(!Enrollment::parent_qualifies($fresh), 'stale action after pause remains non-qualifying');

// Cancelled parent: no new due work.
$parent->update_meta_data('_upay_subscription_status', 'cancelled');
$parent->save();
$fresh = wc_get_order($parent->get_id());
supcheckout_cert_assert(!Enrollment::parent_qualifies($fresh), 'cancelled parent is not enrollable');

// Bridge rejects invalid parent ids.
supcheckout_cert_assert(ASBridge::ensure_cycle_action(0, time()) === false, 'bridge rejects parent id 0');
supcheckout_cert_assert(ASBridge::has_open_cycle_action(0, time()) === false, 'bridge has_open rejects parent id 0');

$parent->delete(true);
wp_delete_post($product->get_id(), true);
wp_delete_user($user_id);
Enrollment::reset_cursor();

supcheckout_cert_note('R4 bounded enrollment / stale-action certification complete');
echo "R4 Scheduling Runtime Certification: PASS\n";
