<?php
/**
 * Real-runtime certification for repeated embedded SUPCheckout rendering.
 *
 * A custom checkout may ask WooCommerce to render the same gateway more than
 * once in one request. First-party asset queues and localized client state must
 * remain singular and deterministic rather than accumulating per render.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Subscription\Presentation;

supcheckout_cert_assert(class_exists('WC_Upayments'), 'SUPCheckout gateway class is available for repeated-render certification');

class SUPCheckout_EmbeddedRenderCartProbe {
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

    $styles->done = array_values(array_diff($styles->done, $style_handles));
    $styles->to_do = array_values(array_diff($styles->to_do, $style_handles));
    $scripts->done = array_values(array_diff($scripts->done, $script_handles));
    $scripts->to_do = array_values(array_diff($scripts->to_do, $script_handles));
};

$count_queue = static function ($queue, $handle) {
    return count(array_keys($queue, $handle, true));
};

$fixture = __DIR__ . '/fixtures/payment-fields-probe.php';
supcheckout_cert_assert(is_file($fixture), 'Repeated-render payment-fields fixture exists');
$template_filter = static function ($template, $template_name) use ($fixture) {
    if ($template_name === 'new-design-form.php' || $template_name === 'old-design-form.php') {
        return $fixture;
    }
    return $template;
};
add_filter('wc_get_template', $template_filter, 999, 2);

$reset_assets();
$gateway = new WC_Upayments();
$gateway->settings['use_new_design'] = 'yes';
$gateway->autoDeduction = 'no';

ob_start();
$gateway->payment_fields();
$gateway->payment_fields();
ob_end_clean();

supcheckout_cert_assert(
    $count_queue(wp_styles()->queue, 'supcheckout-customer') === 1,
    'Repeated embedded modern renders queue shared customer CSS exactly once'
);
supcheckout_cert_assert(
    $count_queue(wp_styles()->queue, 'supcheckout-checkout-new-style') === 1,
    'Repeated embedded modern renders queue modern checkout CSS exactly once'
);
supcheckout_cert_assert(
    $count_queue(wp_scripts()->queue, 'supcheckout-checkout-new-script') === 1,
    'Repeated embedded modern renders queue modern checkout JS exactly once'
);
supcheckout_cert_assert(
    $count_queue(wp_scripts()->queue, 'supcheckout-subscription-checkout') === 0,
    'Repeated non-subscription renders do not acquire subscription JS'
);

$reset_assets();
Presentation::register_product_class();
supcheckout_cert_assert(class_exists('WCProductCustomType'), 'Repeated-render certification can load the real subscription product type');
$previous_cart = WC()->cart;
WC()->cart = new SUPCheckout_EmbeddedRenderCartProbe(new WCProductCustomType());
$gateway->autoDeduction = 'yes';

ob_start();
$gateway->payment_fields();
ob_end_clean();
$localized_once = wp_scripts()->get_data('supcheckout-subscription-checkout', 'data');

ob_start();
$gateway->payment_fields();
ob_end_clean();
$localized_twice = wp_scripts()->get_data('supcheckout-subscription-checkout', 'data');

supcheckout_cert_assert(
    $count_queue(wp_scripts()->queue, 'supcheckout-subscription-checkout') === 1,
    'Repeated embedded subscription renders queue subscription JS exactly once'
);
supcheckout_cert_assert(
    is_string($localized_once) && $localized_once !== '',
    'First eligible embedded subscription render localizes client authentication state'
);
supcheckout_cert_assert(
    $localized_once === $localized_twice,
    'Repeated embedded subscription render does not duplicate or mutate localization payload'
);
supcheckout_cert_assert(
    substr_count((string) $localized_twice, 'var wcUser = ') === 1,
    'Repeated embedded subscription localization owns exactly one wcUser declaration'
);
supcheckout_cert_assert(
    strpos((string) $localized_twice, 'userId') === false,
    'Repeated embedded subscription localization still exposes no numeric WordPress user identity'
);

WC()->cart = $previous_cart;
remove_filter('wc_get_template', $template_filter, 999);
$reset_assets();

fwrite(STDOUT, "CERT: repeated embedded render ownership certification complete\n");
