<?php
/**
 * Plugin Name: SUPCheckout for UPayments
 * Plugin URI: https://github.com/SimplixInnovations/supcheckout
 * Description: Independently engineered UPayments payment integration for WooCommerce by Simplix Innovations.
 * Version: 0.1.0
 * Author: Simplix Innovations
 * Author URI: https://simplixi.com
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 10.8
 * WC tested up to: 11.1
 * License: MIT
 * Text Domain: supcheckout
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/src/Autoloader.php';
\Simplixi\SUPCheckout\Autoloader::register(__DIR__ . '/src');

use Simplixi\SUPCheckout\Release\Identity;
use Simplixi\SUPCheckout\Subscription\Composition as SubscriptionComposition;
use Simplixi\SUPCheckout\Subscription\Presentation as SubscriptionPresentation;
use UPayments\Subscription\Cron\Scheduler;

require_once __DIR__ . '/src/Gateway/OfficialPluginConflict.php';

// While the official UPayments plugin is active, declare nothing global: both plugins
// share the protected legacy function and class names, so the second to load would
// die with "Cannot redeclare". Top-level functions are bound at compile time, so the
// declarations below must sit inside this condition rather than after a return.
if ( ! \Simplixi\SUPCheckout\Gateway\OfficialPluginConflict::pause_if_active( __FILE__ ) ) :

\Simplixi\SUPCheckout\Plugin::boot(__FILE__);

define("UP_PLUGIN_URL", plugin_dir_url(__FILE__));
define("UP_PLUGIN_PATH", plugin_dir_path(__FILE__));
define('UPAYMENTS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/src/Release/Identity.php';
require_once __DIR__ . '/src/Provider/MultiMerchantContract.php';
require_once __DIR__ . '/src/Admin/GatewaySettings.php';
require_once __DIR__ . '/src/Gateway/Availability.php';
require_once __DIR__ . '/src/Gateway/OrderPresentation.php';
require_once __DIR__ . '/src/Provider/EndpointResolver.php';
require_once __DIR__ . '/src/Provider/PaymentMethodAvailability.php';
require_once __DIR__ . '/src/Payment/CheckoutPayload.php';
require_once __DIR__ . '/src/Payment/CheckoutOrchestrator.php';
require_once __DIR__ . '/src/Payment/SavedCardSelection.php';
require_once __DIR__ . '/src/Payment/SavedCardPresentation.php';
require_once __DIR__ . '/src/Subscription/Presentation.php';
require_once __DIR__ . '/src/Subscription/Composition.php';
require_once __DIR__ . '/src/Subscription/RenewalCardAuthority.php';
require_once __DIR__ . '/src/Subscription/CycleEconomics.php';
require_once __DIR__ . '/src/Subscription/AutoDeductResultVerifier.php';
require_once __DIR__ . '/includes/Token/CustomerTokenIdentity.php';
require_once __DIR__ . '/src/Migration/MigrationBootstrap.php';


define('SUPCHECKOUT_VERSION', Identity::VERSION);
define('SUPCHECKOUT_SLUG', Identity::SLUG);
define('SUPCHECKOUT_PLUGIN_FILE', __FILE__);
define('SUPCHECKOUT_UPDATE_CHANNEL', Identity::UPDATE_CHANNEL);

add_action( 'plugins_loaded', 'woocommerceUpaymentsInit' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce bootstrap callback retained for upgrade/runtime compatibility.
function woocommerceUpaymentsInit() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
}

/**
 * upaymentsMissingWcNotice
 * If Woocommerce Plugin is not active/installed show admin notice to install/activate Woocommerce
 * @return void
 */
function upaymentsMissingWcNotice() {
    ?>
    <div class="error notice">
        <p><strong><?php esc_html_e('UPayments Gateway', 'supcheckout'); ?></strong> <?php esc_html_e('requires WooCommerce to be installed and active!', 'supcheckout'); ?></p>
    </div>
    <?php
}

// Declare compatibility with WooCommerce's Cart & Checkout blocks (WooBlocks)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

// payment method registry
add_action( 'woocommerce_blocks_loaded', function() {
    add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
        require_once __DIR__ . '/includes/class-wc-gateway-upayments-blocks.php';
        $payment_method_registry->register(
            new WCGatewayUPaymentsBlocks( __FILE__ )
        );
    });
});

/* Subscription Product Data Handler from product Data Page - Start */
SubscriptionComposition::register_presentation_hooks();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function addCustomProductType( $types ){
    return SubscriptionPresentation::add_custom_product_type($types);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function mapCustomProductClass( $classname, $product_type ) {
    return SubscriptionPresentation::map_custom_product_class($classname, $product_type);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function customProductTypes() {
    SubscriptionPresentation::custom_product_types();
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function addCustomDataTab( $tabs ) {
    return SubscriptionPresentation::add_custom_data_tab($tabs);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function addCustomDataPanel() {
    SubscriptionPresentation::add_custom_data_panel();
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function saveCustomFieldData( $post_id ) {
    SubscriptionPresentation::save_custom_field_data($post_id);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function displayCustomFieldOnFrontend() {
    SubscriptionPresentation::display_custom_field_on_frontend();
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function displayCustomDataInCart( $item_data, $cart_item ) {
    return SubscriptionPresentation::display_custom_data_in_cart($item_data, $cart_item);
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce subscription presentation callback retained for compatibility.
function saveCustomDataToOrderItems( $item, $cart_item_key, $values, $order ) {
    SubscriptionPresentation::save_custom_data_to_order_items($item, $cart_item_key, $values, $order);
}

/* Subscription Product Data Handler from product Data Page - End */

add_action('woocommerce_init', function () {
    require_once __DIR__ . '/includes/Subscription/Cron/Scheduler.php';
    Scheduler::init();
});

add_action('init', function () {
    $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
        ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
        : '';
    if ($method !== 'POST') {
        return;
    }

    $action = isset($_POST['upay_action']) && is_string($_POST['upay_action'])
        ? sanitize_key(wp_unslash($_POST['upay_action']))
        : '';

    $order_id = isset($_POST['order_id'])
        ? absint(wp_unslash($_POST['order_id']))
        : 0;

    if (empty($action) || empty($order_id)) {
        return;
    }

    $allowed_actions = array('unsubscribe', 'pause', 'resume');
    if (!in_array($action, $allowed_actions, true)) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Authorization: nonce is CSRF protection, never object authorization.
    if (!is_user_logged_in() || get_current_user_id() !== (int) $order->get_user_id()) {
        wc_add_notice(__('Unauthorized request.', 'supcheckout'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    // Object contract: this customer action belongs only to manual UPayments
    // subscription orders. Auto-deduction orders remain scheduler-controlled.
    $plan = $order->get_meta('_upay_subscription_plan');
    $interval = (int) $order->get_meta('_upay_subscription_interval');
    $allowed_intervals = array(
        'daily' => array(1),
        'weekly' => array(1, 2, 3),
        'monthly' => array(1, 2),
        'quarterly' => array(1, 2, 3),
        'yearly' => array(1),
    );
    if ((string) $order->get_payment_method() !== 'upayments'
        || $order->get_meta('UPayments_AutoDeduction') === 'yes'
        || !is_string($plan)
        || !isset($allowed_intervals[$plan])
        || !in_array($interval, $allowed_intervals[$plan], true)
    ) {
        wc_add_notice(__('Invalid subscription request.', 'supcheckout'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    $current_status = $order->get_meta('_upay_subscription_status') ?: 'active';
    $transition_allowed = ($action === 'unsubscribe' && in_array($current_status, array('active', 'paused'), true))
        || ($action === 'pause' && $current_status === 'active')
        || ($action === 'resume' && $current_status === 'paused');
    if (!$transition_allowed) {
        wc_add_notice(__('Invalid subscription state transition.', 'supcheckout'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
        exit;
    }

    // Nonce verification: required for every state-changing action.
    $nonce = isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])
        ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
        : '';

    if ($action === 'unsubscribe') {
        $nonce_action = 'upay_unsubscribe_' . $order_id;
    } else {
        $nonce_action = 'upay_' . $action . '_' . $order_id;
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, $nonce_action)) {
        wc_add_notice(__('Invalid request.', 'supcheckout'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    if ($action === 'unsubscribe') {
        $order->update_meta_data('_upay_subscription_status', 'cancelled');
        wc_add_notice(__('Your subscription has been cancelled.', 'supcheckout'), 'success');
    } elseif ($action === 'pause') {
        $order->update_meta_data('_upay_subscription_status', 'paused');
        wc_add_notice(__('Subscription paused.', 'supcheckout'), 'success');
    } elseif ($action === 'resume') {
        $order->update_meta_data('_upay_subscription_status', 'active');
        wc_add_notice(__('Subscription resumed.', 'supcheckout'), 'success');
    }

    $order->save();

    // R4: explicit customer-transition scheduling contract (best-effort).
    // Payment safety never depends on queue cancellation succeeding.
    if (class_exists('\Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler')) {
        if ($action === 'resume') {
            \Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler::schedule_resume($order);
        } elseif ($action === 'pause' || $action === 'unsubscribe') {
            \Simplixi\SUPCheckout\Subscription\Scheduling\LifecycleScheduler::cancel_after_state_change($order);
        }
    }

    wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
    exit;
});

endif;
