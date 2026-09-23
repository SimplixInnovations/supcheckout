<?php
/**
 * Real-runtime regression for SUPCheckout frontend asset scope.
 *
 * Customer-facing gateway assets must not be loaded on unrelated frontend
 * requests or when the gateway is unavailable. Canonical checkout rendering
 * retains its existing assets. A SUPCheckout payment-fields template that is
 * actually rendered by an embedded/custom checkout outside is_checkout() must
 * self-provision only the assets required by that rendered design, including
 * subscription behavior when the exact production eligibility predicate is
 * true. Late-rendered assets must remain printable by WordPress footer passes.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Subscription\Presentation;

supcheckout_cert_assert(class_exists('WC_Upayments'), 'SUPCheckout Classic gateway class is available for asset-scope certification');

class SUPCheckout_FrontendAssetScopeProbe extends WC_Upayments {
    public $availability_checks = 0;
    public $available = true;

    public function is_available() {
        ++$this->availability_checks;
        return $this->available;
    }
}

class SUPCheckout_EmbeddedSubscriptionCartProbe {
    /** @var object */
    private $product;

    public function __construct($product) {
        $this->product = $product;
    }

    public function get_cart() {
        return array(
            array(
                'product_id' => 0,
                'data'       => $this->product,
            ),
        );
    }
}

$style_handles = array(
    'supcheckout-customer',
    'supcheckout-checkout-new-style',
);
$script_handles = array(
    'supcheckout-checkout-new-script',
    'supcheckout-subscription-checkout',
);

$reset_assets = static function () use ($style_handles, $script_handles) {
    $styles = wp_styles();
    $scripts = wp_scripts();

    foreach ($style_handles as $handle) {
        wp_dequeue_style($handle);
        wp_deregister_style($handle);
    }
    foreach ($script_handles as $handle) {
        wp_dequeue_script($handle);
        wp_deregister_script($handle);
    }

    // Each case in this certification file represents a fresh frontend render
    // boundary. Remove only SUPCheckout handles from WordPress' processed lists
    // so one case cannot create a false positive/negative in the next case.
    $styles->done = array_values(array_diff($styles->done, $style_handles));
    $styles->to_do = array_values(array_diff($styles->to_do, $style_handles));
    $scripts->done = array_values(array_diff($scripts->done, $script_handles));
    $scripts->to_do = array_values(array_diff($scripts->to_do, $script_handles));
};

$reset_assets();

supcheckout_cert_assert(!is_checkout(), 'Asset-scope negative fixture executes outside WooCommerce checkout context');

$gateway = new SUPCheckout_FrontendAssetScopeProbe();
$gateway->enqueue_scripts();

supcheckout_cert_assert(
    !wp_style_is('supcheckout-customer', 'enqueued'),
    'Unrelated frontend request does not enqueue SUPCheckout customer CSS'
);
supcheckout_cert_assert(
    $gateway->availability_checks === 0,
    'Unrelated frontend request short-circuits before gateway availability evaluation'
);

$fixture = __DIR__ . '/fixtures/payment-fields-probe.php';
supcheckout_cert_assert(is_file($fixture), 'Embedded checkout payment-fields fixture exists');
$GLOBALS['supcheckout_asset_scope_template_override'] = null;
$template_filter = static function ($template, $template_name) use ($fixture) {
    if ($template_name === 'new-design-form.php' || $template_name === 'old-design-form.php') {
        $GLOBALS['supcheckout_asset_scope_template_override'] = $fixture;
        return $fixture;
    }
    return $template;
};
add_filter('wc_get_template', $template_filter, 999, 2);

// Model a custom/embedded checkout whose payment fields render after the normal
// head-style pass. WordPress must still be able to print these late styles.
ob_start();
wp_print_styles();
ob_end_clean();

$gateway->settings['use_new_design'] = 'yes';
$gateway->autoDeduction = 'no';
ob_start();
$gateway->payment_fields();
ob_end_clean();

supcheckout_cert_assert(
    $GLOBALS['supcheckout_asset_scope_template_override'] === $fixture,
    'Embedded asset observer preserves the later WooCommerce/theme template override path'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-customer', 'enqueued'),
    'Embedded rendered SUPCheckout gateway self-provisions customer CSS outside canonical checkout page'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-checkout-new-style', 'enqueued'),
    'Embedded rendered modern gateway self-provisions new-design CSS outside canonical checkout page'
);
supcheckout_cert_assert(
    wp_script_is('supcheckout-checkout-new-script', 'enqueued'),
    'Embedded rendered modern gateway self-provisions checkout JS outside canonical checkout page'
);
supcheckout_cert_assert(
    !wp_script_is('supcheckout-subscription-checkout', 'enqueued'),
    'Embedded non-subscription gateway does not load subscription checkout JS'
);

ob_start();
wp_styles()->do_footer_items($style_handles);
$late_style_output = ob_get_clean();
supcheckout_cert_assert(
    wp_style_is('supcheckout-customer', 'done') && wp_style_is('supcheckout-checkout-new-style', 'done'),
    'Late-rendered embedded checkout styles remain processable by the WordPress footer style pass'
);
supcheckout_cert_assert(
    strpos($late_style_output, 'supcheckout-customer-css') !== false
        && strpos($late_style_output, 'supcheckout-checkout-new-style-css') !== false,
    'Late WordPress style output contains both rendered SUPCheckout style handles'
);

ob_start();
wp_scripts()->do_footer_items(array('supcheckout-checkout-new-script'));
$late_script_output = ob_get_clean();
supcheckout_cert_assert(
    wp_script_is('supcheckout-checkout-new-script', 'done'),
    'Late-rendered embedded checkout script remains processable by the WordPress footer script pass'
);
supcheckout_cert_assert(
    strpos($late_script_output, 'new-upay.js') !== false,
    'Late WordPress script output contains SUPCheckout modern checkout JS'
);

// The retired old-design stylesheet/script must not leak back into the legacy
// presentation. Only the shared customer presentation CSS is required there.
$reset_assets();
$GLOBALS['supcheckout_asset_scope_template_override'] = null;
$gateway->settings['use_new_design'] = 'no';
$gateway->autoDeduction = 'no';
ob_start();
$gateway->payment_fields();
ob_end_clean();

supcheckout_cert_assert(
    $GLOBALS['supcheckout_asset_scope_template_override'] === $fixture,
    'Legacy embedded render also preserves the WooCommerce/theme template override path'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-customer', 'enqueued'),
    'Embedded legacy gateway self-provisions shared customer CSS'
);
supcheckout_cert_assert(
    !wp_style_is('supcheckout-checkout-new-style', 'enqueued')
        && !wp_script_is('supcheckout-checkout-new-script', 'enqueued'),
    'Embedded legacy gateway does not leak modern checkout CSS or JS'
);

// Exercise the exact production subscription predicate with a real WooCommerce
// custom product object and a deterministic cart wrapper. This intentionally
// proves embedded rendering can provision the subscription client contract too.
$reset_assets();
Presentation::register_product_class();
supcheckout_cert_assert(class_exists('WCProductCustomType'), 'Embedded asset certification can load the real subscription product type');
$previous_cart = WC()->cart;
$subscription_product = new WCProductCustomType();
WC()->cart = new SUPCheckout_EmbeddedSubscriptionCartProbe($subscription_product);

$gateway->settings['use_new_design'] = 'yes';
$gateway->autoDeduction = 'yes';
ob_start();
$gateway->payment_fields();
ob_end_clean();

supcheckout_cert_assert(
    wp_script_is('supcheckout-subscription-checkout', 'enqueued'),
    'Embedded eligible subscription gateway self-provisions subscription checkout JS'
);
$subscription_data = wp_scripts()->get_data('supcheckout-subscription-checkout', 'data');
supcheckout_cert_assert(
    is_string($subscription_data) && strpos($subscription_data, 'isLoggedIn') !== false,
    'Embedded subscription script retains the boolean authentication localization contract'
);
supcheckout_cert_assert(
    !is_string($subscription_data) || strpos($subscription_data, 'userId') === false,
    'Embedded subscription localization does not expose a numeric WordPress user identity'
);

$reset_assets();
$gateway->autoDeduction = 'no';
ob_start();
$gateway->payment_fields();
ob_end_clean();
supcheckout_cert_assert(
    !wp_script_is('supcheckout-subscription-checkout', 'enqueued'),
    'Embedded subscription product does not load subscription JS when auto-deduction is disabled'
);

WC()->cart = $previous_cart;
remove_filter('wc_get_template', $template_filter, 999);
unset($GLOBALS['supcheckout_asset_scope_template_override']);
$reset_assets();

add_filter('woocommerce_is_checkout', '__return_true', PHP_INT_MAX);
supcheckout_cert_assert(is_checkout(), 'Asset-scope checkout fixture forces WooCommerce checkout context');

$gateway->available = false;
$gateway->enqueue_scripts();

supcheckout_cert_assert(
    $gateway->availability_checks === 1,
    'Unavailable checkout evaluates gateway availability exactly once'
);
supcheckout_cert_assert(
    !wp_style_is('supcheckout-customer', 'enqueued'),
    'Unavailable checkout does not enqueue SUPCheckout customer CSS'
);
supcheckout_cert_assert(
    !wp_style_is('supcheckout-checkout-new-style', 'enqueued'),
    'Unavailable checkout does not enqueue SUPCheckout new-design CSS'
);
supcheckout_cert_assert(
    !wp_script_is('supcheckout-checkout-new-script', 'enqueued'),
    'Unavailable checkout does not enqueue SUPCheckout new-design script'
);

$gateway->available = true;
$gateway->autoDeduction = 'no';
$gateway->settings['use_new_design'] = 'yes';
$gateway->enqueue_scripts();

supcheckout_cert_assert(
    $gateway->availability_checks === 2,
    'Available checkout performs one additional gateway availability evaluation'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-customer', 'enqueued'),
    'Renderable checkout enqueues SUPCheckout customer CSS'
);
supcheckout_cert_assert(
    wp_style_is('supcheckout-checkout-new-style', 'enqueued'),
    'Renderable default checkout enqueues SUPCheckout new-design CSS'
);
supcheckout_cert_assert(
    wp_script_is('supcheckout-checkout-new-script', 'enqueued'),
    'Renderable default checkout enqueues SUPCheckout new-design script'
);

remove_filter('woocommerce_is_checkout', '__return_true', PHP_INT_MAX);
$reset_assets();

fwrite(STDOUT, "CERT: frontend asset-scope certification complete\n");
