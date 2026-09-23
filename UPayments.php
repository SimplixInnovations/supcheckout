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
require_once __DIR__ . '/includes/Token/CustomerTokenIdentity.php';
require_once __DIR__ . '/src/Migration/MigrationBootstrap.php';

use Simplixi\SUPCheckout\Release\Identity;
use Simplixi\SUPCheckout\Admin\GatewaySettings;
use Simplixi\SUPCheckout\Gateway\Availability;
use Simplixi\SUPCheckout\Gateway\OrderPresentation;
use Simplixi\SUPCheckout\Provider\EndpointResolver;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;
use Simplixi\SUPCheckout\Payment\CheckoutPayload;
use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;
use Simplixi\SUPCheckout\Subscription\Composition as SubscriptionComposition;
use Simplixi\SUPCheckout\Subscription\Presentation as SubscriptionPresentation;
use UPayments\Subscription\Cron\Scheduler;
use UPayments\Token\CustomerTokenIdentity;

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
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Protected legacy WooCommerce gateway class identity retained for compatibility.
    class WC_Upayments extends WC_Payment_Gateway {
        public $domain = 'upayments';
        public $debug;
        public $apiKey;
        public $testMode;
        public $isOrderComplete;
        public $fromPluginEnabled;
        public $paymentData;

        public $multiMerchant;
        public $ibanNumber;
        public $knetCharge;
        public $knetChargeType;
        public $ccCharge;
        public $ccChargeType;
        public $saveCardEnabled;
        public $charge;
        public $autoDeduction;

        /** Checkout payload compatibility delegates. */
        private static function field_present($source, $key) {
            return CheckoutPayload::field_present($source, $key);
        }

        private static function parse_save_card_strict($value) {
            return CheckoutPayload::parse_save_card_strict($value);
        }

        private static function parse_payment_source_strict($value) {
            return CheckoutPayload::parse_payment_source_strict($value);
        }

        private static function compare_nonnegative_decimal_strings($a, $b) {
            return CheckoutPayload::compare_nonnegative_decimal_strings($a, $b);
        }

        private static function build_amount_json_token($amount_str) {
            return CheckoutPayload::build_amount_json_token($amount_str);
        }

        private static function inject_amount_token_into_payload_json($payload_json, array $token_map, array $extra_sentinels = array()) {
            return CheckoutPayload::inject_amount_token_into_payload_json($payload_json, $token_map, $extra_sentinels);
        }

        private static function get_max_length_for_sentinel($placeholder) {
            return CheckoutPayload::get_max_length_for_sentinel($placeholder);
        }

        private static function is_valid_subscription_plan(string $plan): bool {
            return CheckoutPayload::is_valid_subscription_plan($plan);
        }

        private static function parse_subscription_plan_strict($value) {
            return CheckoutPayload::parse_subscription_plan_strict($value);
        }

        private static function parse_interval($value): int {
            return CheckoutPayload::parse_interval($value);
        }

        private static function is_valid_subscription_interval(string $plan, int $interval): bool {
            return CheckoutPayload::is_valid_subscription_interval($plan, $interval);
        }

        private function normalize_upayments_redirect_url($value) {
            return CheckoutPayload::normalize_upayments_redirect_url($value);
        }

        private static function normalize_store_api_route($uri) {
            return CheckoutPayload::normalize_store_api_route($uri);
        }

        public static function classify_checkout_request_context($is_rest_request, $normalized_route, $method) {
            return CheckoutPayload::classify_checkout_request_context($is_rest_request, $normalized_route, $method);
        }

        /**
         * Single canonical inlet for raw request-body access.
         *
         * Kept protected so existing gateway subclasses can supply deterministic
         * Store API bodies without bypassing the checkout orchestrator.
         */
        protected function get_request_body_raw() {
            $raw = file_get_contents('php://input');
            return (is_string($raw)) ? $raw : '';
        }

        public static function validate_provider_positive_decimal($value, $field_name = '') {
            return CheckoutPayload::validate_provider_positive_decimal($value, $field_name);
        }

        public static function validate_provider_nonnegative_decimal($value, $field_name = '') {
            return CheckoutPayload::validate_provider_nonnegative_decimal($value, $field_name);
        }

        public static function compute_provider_unit_price_decimal($line_total, $qty) {
            return CheckoutPayload::compute_provider_unit_price_decimal($line_total, $qty);
        }

        private static function digit_long_divide($numer_str, $denom) {
            return CheckoutPayload::digit_long_divide($numer_str, $denom);
        }

        private static function digit_long_divide_remainder($numer_str, $denom) {
            return CheckoutPayload::digit_long_divide_remainder($numer_str, $denom);
        }

        public static function canonicalize_provider_decimal_string($value) {
            return CheckoutPayload::canonicalize_provider_decimal_string($value);
        }

        private static function is_store_api_checkout_request() {
            return CheckoutPayload::is_store_api_checkout_request();
        }

        /**
         * Legacy private compatibility seam for the H12 cache validator.
         *
         * @param mixed $cached Cached availability value.
         * @return string|bool
         */
        private function is_valid_cached_availability($cached) {
            return PaymentMethodAvailability::classify_cached($cached);
        }

        public function is_available() {
            return parent::is_available() && GatewaySettings::is_runtime_eligible(get_option('woocommerce_upayments_settings'), get_woocommerce_currency());
        }

        public function __construct() {
            // Define ID, title, description, and settings.
            $this->id                 = 'upayments';
            $this->icon = UP_PLUGIN_URL . "assets/images/upayment.png";
            $this->method_title       = __("UPayments", 'supcheckout');
            $this->method_description = __("UPayments payment integration for WooCommerce. Available payment methods depend on your UPayments account and provider configuration.
            Supports Classic and Block Checkout. Subscription auto-deduction requires separately validated provider setup.", 'supcheckout');
            $this->has_fields         = true; // Required for custom forms like Save Card/Design variations.

            // Load settings and hooks
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->debug = $this->get_option("debug");
            $this->apiKey = $this->get_option("api_key");
            $this->isOrderComplete = array_key_exists('is_order_complete', $this->settings)
              ? $this->settings['is_order_complete']
              : 'yes';
            $this->testMode = $this->get_option("test_mode");
            $this->charge = $this->get_option('charge');
            $this->fromPluginEnabled = false;
            $this->paymentData = array();

            //MultimerchantData
            $this->multiMerchant = $this->get_option("enable_multimerchant");
            $this->ibanNumber = $this->get_option("iban_number");
            $this->ccCharge = $this->get_option("cc_charge");
            $this->ccChargeType = $this->get_option("cc_charge_type");
            $this->knetCharge = $this->get_option("knet_charge");
            $this->knetChargeType = $this->get_option("knet_charge_type");
            $this->saveCardEnabled = $this->get_option("enable_save_card");
            $this->autoDeduction = $this->get_option("enable_subscriptions");

            // Register action hook for saving settings (critical for all new toggles)
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            
            // Custom hooks for front-end rendering, scripts, etc.
            add_filter("woocommerce_get_order_item_totals", [$this, "add_order_item_totals"], 10, 3);
            add_action("woocommerce_api_" . strtolower("WC_UPayments") , [$this, "check_ipn_response", ]);
            add_filter("woocommerce_gateway_icon", [$this, "custom_payment_gateway_icons"], 10, 2);
            add_action("woocommerce_admin_order_data_after_order_details", [$this, "admin_order_details"], 10, 3);
            add_action("admin_enqueue_scripts", [$this, "admin_enqueue_scripts"]);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
            
            // Handlers to Display Thankyou Page after successful payment
            add_action("woocommerce_thankyou_" . $this->id, function ($order_id) {
                $this->thankyou_page($order_id);
            });
            
            // Handlers for Subscription Module
            $this->initializeSubscriptionModule();
            
            // My Account link for Login users to view their orders and saved cards
            add_action('woocommerce_before_checkout_form', function () {

                if (!function_exists('WC') || !WC()->session) {
                    return;
                }

                $account_url = wc_get_page_permalink('myaccount');

                echo '<div class="checkout-my-account-link">';
                echo '<a href="' . esc_url($account_url) . '" target="_blank">';
                esc_html_e('Go to My Account', 'supcheckout');
                echo '</a>';
                echo '</div>';
                
                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                
                if (!isset($gateways['upayments'])) {
                    return;
                }
                
                $upay = $gateways['upayments'];
                
                if (WC()->session->get('chosen_payment_method') === 'upayments' && $upay->get_option('make_default_gateway') === 'no') {
                    WC()->session->set('chosen_payment_method', null);
                }
            }, 5);
            // Save Card & Subscriptions validation
            add_filter('woocommerce_settings_api_sanitized_fields_upayments', function ($settings) {
                $normalized = GatewaySettings::normalize_dependencies($settings);
                if ($normalized['forced_save_card']) {
                    wc_add_notice(
                        __('Save Card must be enabled when Subscriptions are enabled.', 'supcheckout'),
                        'error'
                    );
                }

                return $normalized['settings'];
            });

            add_filter('woocommerce_default_gateway', function ($default) {
                if ($this->get_option('make_default_gateway') === 'yes') {
                    return 'upayments';
                }

                return $default;
            });

            SubscriptionComposition::register_gateway_hooks($this);
        }

        public function init_form_fields() {
            $this->form_fields = GatewaySettings::fields(
                $this->domain,
                $this->method_title,
                $this->method_description
            );
        }

        public function get_logged_in_user_phone_number() {
            
            // Check if the user is logged in
            if (is_user_logged_in()) {
                // Get the current user ID
                $user_id = get_current_user_id();
                $billing_phone = get_user_meta($user_id, 'billing_phone', true);
                
                if ($billing_phone) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    if($phone) {
                        return ['success' => true, 'phone' => $phone];
                    }
                }
                return ['success' => true, 'phone' => ''];
            }
            if (function_exists('WC') && WC()->customer) {
                $billing_phone = WC()->customer->get_billing_phone();
                
                if (!empty($billing_phone)) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    return ['success' => true, 'phone' => $phone];
                }
            }
            return ['success' => false, 'phone' => ''];
        }

        public function add_order_item_totals($total_rows, $order, $tax_display)
        {
            return OrderPresentation::add_order_item_totals($total_rows, $order, $this->id);
        }

        /**
         * Output for the order received page.
         *
         * Display-only. No payment-state mutation is performed here.
         * The verified WooCommerce order status and authoritative UPayments
         * metadata are read from the order; no $_GET parameter is trusted to
         * alter payment state.
         */
        public function thankyou_page($order_id) {
            if (!$order_id) {
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                return;
            }

            $status = (string) $order->get_status();
            $state = 'pending';

            if (method_exists($order, 'is_paid') && $order->is_paid()) {
                $state = 'success';
                $message = __('Your payment is successful with UPayments.', 'supcheckout');
            } elseif ($status === 'failed') {
                $state = 'failed';
                $message = __('Your payment was not completed with UPayments.', 'supcheckout');
            } elseif ($status === 'cancelled') {
                $state = 'cancelled';
                $message = __('Your order was cancelled.', 'supcheckout');
            } elseif ($status === 'refunded') {
                $state = 'refunded';
                $message = __('Your payment has been refunded.', 'supcheckout');
            } elseif ($status === 'wait') {
                $message = __('We are verifying your payment status with UPayments. Please wait.', 'supcheckout');
            } else {
                $message = __('Your payment status is pending. We will update the order when UPayments confirms the final status.', 'supcheckout');
            }
            ?>
            <div class="upayments-thankyou-wrapper" data-order-id="<?php echo esc_attr($order_id); ?>">
                <div
                    class="supcheckout-payment-status supcheckout-payment-status--<?php echo esc_attr($state); ?>"
                    <?php echo $state === 'pending' ? 'aria-live="polite"' : ''; ?>
                >
                    <?php if ($state === 'pending') : ?>
                        <span class="supcheckout-payment-status__spinner" aria-hidden="true"></span>
                    <?php elseif ($state === 'success') : ?>
                        <span class="supcheckout-payment-status__success-mark" aria-hidden="true">✓</span>
                    <?php endif; ?>
                    <p><?php echo esc_html($message); ?></p>
                </div>
            </div>
            <?php
        }

        public function get_payment_staus()
        {
            \Simplixi\SUPCheckout\Security\PublicOrderStatus::handle();
        }

        /**
         * Execute a hardened authenticated UPayments HTTP request.
         *
         * PHASE 8S: Low-level transport helper for the four legacy authenticated
         * UPayments API calls (charge, create-customer-unique-token,
         * check-payment-button-status, retrieve-customer-cards). It is NOT used
         * by verify_payment_status() (PR #7 trust anchor) or the Scheduler
         * auto-deduct dispatcher (PR #8), each of which has its own separately
         * reviewed transport policy.
         *
         * Transport policy:
         *   - explicit TLS verification through the WordPress HTTP API;
         *   - redirects disabled (no redirect requirement is established;
         *     preserve endpoint identity; avoid method/body ambiguity on
         *     cross-host hops);
         *   - finite 15-second request timeout;
         *   - Bearer Authorization applied for the entire call.
         *
         * SECURITY: This helper does NOT log raw request bodies, raw response
         * bodies, raw transport-error text, the Authorization header, or any token.
         * Callers classify the structured outcome and remain responsible for
         * redacting provider messages before showing them to customers.
         *
         * @param string      $route   API route relative to the API base.
         * @param string      $method  Uppercase HTTP method: 'GET' or 'POST'.
         * @param string|null $body    JSON-encoded request body, or null for GET.
         * @return array{transport_ok: bool, body: string|null, http_status: int, curl_errno: int}
         */
        protected function execute_upayments_request($route, $method, $body = null)
        {
            $outcome = array(
                'transport_ok' => false,
                'body'         => null,
                'http_status'  => 0,
                'curl_errno'   => 0,
            );

            $method = is_string($method) ? strtoupper(trim($method)) : 'GET';
            if ($method !== 'GET' && $method !== 'POST') {
                return $outcome;
            }

            $request_args = array(
                'method'      => $method,
                'timeout'             => 15,
                'limit_response_size' => 1048576,
                'redirection'         => 0,
                'sslverify'   => true,
                'user-agent'  => $this->getUserAgent(),
                'headers'     => array(
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ),
            );

            if ($method === 'POST') {
                $request_args['body'] = (string) $body;
            }

            $response = wp_remote_request($this->getAPIUrl($route), $request_args);
            if (is_wp_error($response)) {
                // Keep the historical field for internal compatibility. A
                // nonzero value means transport failure; raw error text is
                // intentionally not persisted or logged.
                $outcome['curl_errno'] = 1;
                return $outcome;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            // WordPress truncates at limit_response_size. A body that reaches
            // the cap is therefore ambiguous and must never be treated as a
            // complete provider response.
            if (!is_string($response_body) || strlen($response_body) >= 1048576) {
                $outcome['http_status'] = $status;
                return $outcome;
            }

            $outcome['http_status']  = $status;
            $outcome['body']         = $response_body;
            $outcome['transport_ok'] = ($status >= 200) && ($status < 300);

            return $outcome;
        }

        /**
         * Verify a UPayments payment status through the Bearer-authenticated
         * Get Payment Status API and bind the response to the given WooCommerce order.
         *
         * SECURITY: This is the authoritative trust path for main checkout
         * browser-return and webhook paid-state transitions. Inbound callback
         * fields (browser return / webhook) are NEVER authoritative.
         * Authentication is the UPayments server-side response, schema-validated
         * and bound to the order. The subscription auto-deduction Scheduler has
         * its own separate payment flow and is out of scope of this helper.
         *
         * @param WC_Order $order
         * @param string   $track_id  Lookup cursor received from the callback.
         * @return array{
         *     verified: bool,
         *     transaction: array|null,
         *     reason: string
         * }
         */
        private function verify_payment_status($order, $track_id)
        {
            $result = array(
                'verified'    => false,
                'transaction' => null,
                'reason'      => '',
            );

            try {
                if (!$order instanceof WC_Order) {
                    $result['reason'] = 'invalid_order';
                    return $result;
                }

                $track_id = is_string($track_id) ? trim($track_id) : '';
                if ($track_id === '') {
                    $result['reason'] = 'missing_track_id';
                    return $result;
                }

                $local_order_id = (string) $order->get_id();
                $local_currency = $this->getCurrencyCode($order->get_currency());
                $local_upay_order_id = $order->get_meta('UPayments_order_id');
                if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                    $result['reason'] = 'missing_local_upay_order_id';
                    return $result;
                }

                $transport = $this->execute_upayments_request(
                    'get-payment-status/' . rawurlencode($track_id),
                    'GET'
                );
                $response_body = $transport['body'];
                $http_code = (int) $transport['http_status'];

                if ($response_body === null || (int) $transport['curl_errno'] !== 0) {
                    $result['reason'] = 'network_error';
                    $this->log('UPayments payment status verification failed (network).', 'warning');
                    return $result;
                }

                if ($http_code !== 201) {
                    $result['reason'] = 'unexpected_http_' . $http_code;
                    $this->log('UPayments payment status verification failed (HTTP status).', 'warning');
                    return $result;
                }

                $decoded = json_decode((string) $response_body, true);
                if (!is_array($decoded) || empty($decoded['status']) || $decoded['status'] !== true) {
                    $result['reason'] = 'invalid_top_level';
                    $this->log('UPayments payment status verification failed (top-level status).', 'warning');
                    return $result;
                }

                $transaction = isset($decoded['data']['transaction']) && is_array($decoded['data']['transaction'])
                    ? $decoded['data']['transaction']
                    : null;
                if ($transaction === null) {
                    $result['reason'] = 'missing_transaction';
                    $this->log('UPayments payment status verification failed (missing transaction).', 'warning');
                    return $result;
                }

                // Required-field gating.
                $required = array('result', 'track_id', 'merchant_requested_order_id', 'total_price', 'currency_type', 'payment_id', 'payment_type', 'reference');
                foreach ($required as $field) {
                    if (!array_key_exists($field, $transaction) || $transaction[$field] === null || $transaction[$field] === '') {
                        $result['reason'] = 'missing_field_' . $field;
                        $this->log('UPayments transaction binding failed.', 'warning');
                        return $result;
                    }
                }

                // B1 — track_id echo.
                if ((string) $transaction['track_id'] !== $track_id) {
                    $result['reason'] = 'binding_track_id';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B2 — merchant_requested_order_id == UPayments_order_id.
                if ((string) $transaction['merchant_requested_order_id'] !== $local_upay_order_id) {
                    $result['reason'] = 'binding_merchant_requested_order_id';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B3 — reference == WooCommerce order id.
                if ((string) $transaction['reference'] !== $local_order_id) {
                    $result['reason'] = 'binding_reference';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B4 — currency.
                $expected_currency = strtoupper(trim($local_currency));
                $verified_currency = strtoupper(trim((string) $transaction['currency_type']));
                if ($expected_currency === '' || $verified_currency !== $expected_currency) {
                    $result['reason'] = 'binding_currency';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B5 — amount (decimal-safe, normalized string comparison).
                $verified_amount = (string) $transaction['total_price'];
                if (!is_numeric($verified_amount)) {
                    $result['reason'] = 'amount_not_numeric';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }
                $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                $expected_amount = wc_format_decimal((string) $order->get_total(), $decimals);
                $normalized_amount = wc_format_decimal($verified_amount, $decimals);
                if ($normalized_amount !== $expected_amount) {
                    $result['reason'] = 'binding_amount';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // CAPTURED-only policy.
                if ((string) $transaction['result'] !== 'CAPTURED') {
                    $result['reason'] = 'not_captured';
                    return $result;
                }

                $result['verified']    = true;
                $result['transaction'] = $transaction;
                $result['reason']      = 'captured';
                return $result;
            } catch (\Throwable $e) {
                // Fail-closed: any unexpected internal exception during verification
                // must not leak transport or data details and must not authorize a paid transition.
                $result['verified']    = false;
                $result['transaction'] = null;
                $result['reason']      = 'verification_exception';
                $this->log('UPayments payment status verification failed (verification exception).', 'warning');
                return $result;
            }
        }

        /**
         * Neutral fallback URL for verification outcomes that must not disclose
         * the WooCommerce order-received URL.
         *
         * The WooCommerce order-received URL contains a privileged `?key=` token
         * that authorizes viewing that order without further authentication. A
         * browser request that has not yet bound authoritatively to a UPayments
         * transaction must NEVER be redirected to such a URL.
         *
         * The fallback:
         *  - contains no WooCommerce order key;
         *  - is not an order-pay URL;
         *  - does not invite immediate repayment;
         *  - carries a static `upayments_verification=pending` marker so the
         *    destination page can render a friendly pending state.
         *
         * @return string
         */
        private function get_payment_verification_fallback_url()
        {
            $base = is_user_logged_in()
                ? wc_get_page_permalink('myaccount')
                : home_url('/');

            return add_query_arg('upayments_verification', 'pending', $base);
        }

        /**
         * Process the customer browser return from UPayments.
         *
         * SECURITY: The inbound $_GET result/payment_id/track_id/post_date/tran_id/
         * ref/auth fields are NEVER authoritative. Only a verified authoritative
         * Get Payment Status response with all bindings satisfied and
         * result === 'CAPTURED' may authorize the WooCommerce paid-state transition.
         * Browser paths that fail local preflight or unauthenticated binding MUST
         * be redirected to the neutral fallback URL — never to the order-received
         * URL, which embeds the WooCommerce order key.
         */
        public function return_from_upayments()
        {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External UPayments browser return cannot carry a WordPress nonce; all inbound identifiers are sanitized and paid-state authority requires authenticated provider status verification.
            if (!isset($_GET["wc_order_id"])) {
                $this->log("Return callback received without wc_order_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $raw_order_id = sanitize_text_field(wp_unslash($_GET["wc_order_id"]));
            $order_id = absint($raw_order_id);
            if ($order_id <= 0) {
                $this->log("Return callback received with invalid wc_order_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                $this->log("Return callback received but order could not be loaded.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            if ($order->get_payment_method() !== $this->id) {
                $this->log("Return callback received for non-UPayments order.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // Order preconditions: require locally generated UPayments_order_id.
            $local_upay_order_id = $order->get_meta('UPayments_order_id');
            if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                $this->log("Return callback received but UPayments_order_id is missing.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $track_id = isset($_GET["track_id"])
                ? sanitize_text_field(wp_unslash($_GET["track_id"]))
                : '';
            if ($track_id === '') {
                $this->log("Return callback received without track_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // A2 — requested_order_id is a cheap local preflight, NOT authentication.
            // Required to be present and strictly equal to local UPayments_order_id
            // BEFORE any authenticated status request is made. Paid-state authority
            // still requires Bearer-authenticated Get Payment Status + B1-B5 +
            // authoritative result === 'CAPTURED'.
            $requested_order_id = isset($_GET["requested_order_id"])
                ? sanitize_text_field(wp_unslash($_GET["requested_order_id"]))
                : '';
            if ($requested_order_id === '' || $requested_order_id !== $local_upay_order_id) {
                $this->log("Return callback requested_order_id preflight failed.", 'warning');
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended

            // A1 — _upay_verified_capture means the original capture has already
            // been authoritatively verified. A later callback/URL replay must
            // NEVER be allowed to overwrite subsequent WooCommerce lifecycle states
            // (refunded, custom fulfillment, merchant/admin status changes, etc.).
            // Short-circuit unconditionally on the flag alone. We still use the
            // neutral fallback so a public replay never receives a fresh
            // order-received URL.
            if ((string) $order->get_meta('_upay_verified_capture') === '1') {
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // A5 — never resurrect a refunded order. The refund status itself
            // prohibits order mutation. Do not disclose an order-received URL
            // for a non-captured path; use the neutral fallback.
            if ($order->has_status('refunded')) {
                $this->log("Return callback received for refunded order; leaving status unchanged.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $this->log("Return callback received; verifying payment status.");

            // Browser-side fail-closed exception containment. The internal
            // verifier already catches Throwable, but the verification success
            // path in this handler performs metadata writes, status transition,
            // verified-flag writes, order save, and cart handling. Any unexpected
            // Throwable in this section must not mark the order failed, must not
            // cancel, must not set verified-success flags, must not log
            // transport/data details, and must not return the customer to
            // checkout.
            try {
                $verification = $this->verify_payment_status($order, $track_id);

                if (!$verification['verified']) {
                    $reason = (string) $verification['reason'];

                    // The remote transaction identity has been authenticated
                    // and bound even though payment is not CAPTURED. However,
                    // Get Payment Status authenticates the UPayments transaction,
                    // NOT the browser requester. There is therefore no reason to
                    // disclose WooCommerce's order-received URL (which embeds the
                    // ?key= order-key bearer token) for a payment that is NOT
                    // CAPTURED. Backend order status remains unchanged.
                    if ($reason === 'not_captured') {
                        $this->log("Return callback: authenticated response not CAPTURED.");
                        wp_safe_redirect($this->get_payment_verification_fallback_url());
                        exit();
                    }

                    // All other reasons are transport / HTTP / schema / binding
                    // failures. They must not disclose the WooCommerce order key.
                    $this->log("Return callback: verification failed (" . $reason . ").");
                    wp_safe_redirect($this->get_payment_verification_fallback_url());
                    exit();
                }

                $transaction = $verification['transaction'];
                $verified_payment_id = (string) $transaction['payment_id'];

                // Write verified metadata from the authenticated response only.
                $order->update_meta_data('UPayments_Result', (string) $transaction['result']);
                $order->update_meta_data('UPayments_PaymentID', $verified_payment_id);
                $order->update_meta_data('UPayments_TrackID', (string) $transaction['track_id']);
                $order->update_meta_data('UPayments_payment_type', (string) $transaction['payment_type']);
                // UPayments_Ref comes from the authenticated transaction.reference field
                // (not the legacy unverified callback 'ref' field). See A8.
                $order->update_meta_data('UPayments_Ref', (string) $transaction['reference']);
                $order->update_meta_data('_payment_method_title', 'UPayments');

                // A4 — capture update_status() return value; only set success flags
                // after a successful WooCommerce state transition (or when the order
                // is already in the exact target paid state).
                $current_status = $order->get_status();
                $paid_order_status = 'processing';
                if ($current_status === 'completed' || $this->getIsOrderComplete()) {
                    $paid_order_status = 'completed';
                }

                $status_transition_ok = true;
                if ($current_status !== $paid_order_status) {
                    $status_transition_ok = $order->update_status(
                        $paid_order_status,
                        __('Payment successful with UPayments. PaymentID: ', 'supcheckout') . $verified_payment_id
                    );
                }

                if (!$status_transition_ok) {
                    $this->log("Return callback: WooCommerce update_status returned false; verified flags not written.", 'warning');
                    wp_safe_redirect($this->get_payment_verification_fallback_url());
                    exit();
                }

                // Set verified flag AFTER successful transition.
                $order->update_meta_data('_upay_verified_capture', 1);
                // Backward-compatibility write for legacy readers (not a security gate).
                $order->update_meta_data('UPayments_webhook_triggered', 1);
                $order->save();

                $this->log("UPayments CAPTURED status verified.");

                if (function_exists('WC') && WC() && WC()->cart) {
                    WC()->cart->empty_cart();
                }

                wp_safe_redirect($this->get_return_url($order));
                exit();
            } catch (\Throwable $e) {
                // A6 — fail-closed exception containment. Never mark failed;
                // never mark cancelled; never deliberately roll the order back
                // (rollback is out of scope and could cause more damage); never
                // set a success flag merely because an exception occurred; do
                // not empty the cart; do not log $e->getMessage() — it could
                // contain transport/data details.
                $this->log("Return callback: unexpected internal error during verified payment processing.", 'warning');
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }
        }

        /**
         * Handle the UPayments server-to-server webhook (notificationUrl).
         *
         * SECURITY: The inbound $_REQUEST result/payment_id/track_id/post_date/
         * tran_id/ref/auth fields are NEVER authoritative. Only a verified
         * authoritative Get Payment Status response with all bindings satisfied
         * and result === 'CAPTURED' may authorize the WooCommerce paid-state
         * transition. Internal exceptions must NOT mark the order failed.
         */
        public function web_hook_handler()
        {
            $this->log("Webhook received; verifying payment status.");

            try {
                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Server-to-server provider webhook cannot use a WordPress nonce; identifiers are sanitized and no paid state is trusted without authenticated provider status verification.
                if (!isset($_REQUEST["wc_order_id"])) {
                    $this->log("Webhook received without wc_order_id.");
                    exit();
                }

                $raw_order_id = sanitize_text_field(wp_unslash($_REQUEST["wc_order_id"]));
                $order_id = absint($raw_order_id);
                if ($order_id <= 0) {
                    $this->log("Webhook received with invalid wc_order_id.");
                    exit();
                }

                $order = wc_get_order($order_id);
                if (!$order instanceof WC_Order) {
                    $this->log("Webhook received but order could not be loaded.");
                    exit();
                }

                if ($order->get_payment_method() !== $this->id) {
                    $this->log("Webhook received for non-UPayments order.");
                    exit();
                }

                // Order preconditions: require locally generated UPayments_order_id.
                $local_upay_order_id = $order->get_meta('UPayments_order_id');
                if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                    $this->log("Webhook received but UPayments_order_id is missing.");
                    exit();
                }

                $track_id = isset($_REQUEST["track_id"])
                    ? sanitize_text_field(wp_unslash($_REQUEST["track_id"]))
                    : '';
                if ($track_id === '') {
                    $this->log("Webhook received without track_id.");
                    exit();
                }

                // A2 — requested_order_id is a cheap local preflight, NOT authentication.
                // Required to be present and strictly equal to local UPayments_order_id
                // BEFORE any authenticated status request is made. Paid-state authority
                // still requires Bearer-authenticated Get Payment Status + B1-B5 +
                // authoritative result === 'CAPTURED'.
                $requested_order_id = isset($_REQUEST["requested_order_id"])
                    ? sanitize_text_field(wp_unslash($_REQUEST["requested_order_id"]))
                    : '';
                if ($requested_order_id === '' || $requested_order_id !== $local_upay_order_id) {
                    $this->log("Webhook requested_order_id preflight failed.", 'warning');
                    exit();
                }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended

                // A1 — _upay_verified_capture means the original capture has already
                // been authoritatively verified. Webhook must never drive lifecycle state
                // again after a verified capture.
                if ((string) $order->get_meta('_upay_verified_capture') === '1') {
                    exit();
                }

                // A5 — never resurrect a refunded order.
                if ($order->has_status('refunded')) {
                    $this->log("Webhook received for refunded order; leaving status unchanged.");
                    exit();
                }

                $verification = $this->verify_payment_status($order, $track_id);

                if (!$verification['verified']) {
                    $this->log("Webhook: verification failed (" . $verification['reason'] . ").");
                    exit();
                }

                $transaction = $verification['transaction'];
                $verified_payment_id = (string) $transaction['payment_id'];

                // Write verified metadata from the authenticated response only.
                $order->update_meta_data('UPayments_Result', (string) $transaction['result']);
                $order->update_meta_data('UPayments_PaymentID', $verified_payment_id);
                $order->update_meta_data('UPayments_TrackID', (string) $transaction['track_id']);
                $order->update_meta_data('UPayments_payment_type', (string) $transaction['payment_type']);
                // UPayments_Ref comes from the authenticated transaction.reference field
                // (not the legacy unverified callback 'ref' field). See A8.
                $order->update_meta_data('UPayments_Ref', (string) $transaction['reference']);
                $order->update_meta_data('_payment_method_title', 'UPayments');

                // A4 — capture update_status() return value; only set success flags
                // after a successful WooCommerce state transition (or when the order
                // is already in the exact target paid state).
                $current_status = $order->get_status();
                $paid_order_status = 'processing';
                if ($current_status === 'completed' || $this->getIsOrderComplete()) {
                    $paid_order_status = 'completed';
                }

                $status_transition_ok = true;
                if ($current_status !== $paid_order_status) {
                    $status_transition_ok = $order->update_status(
                        $paid_order_status,
                        __('Payment successful with UPayments. PaymentID: ', 'supcheckout') . $verified_payment_id
                    );
                }

                if (!$status_transition_ok) {
                    $this->log("Webhook: WooCommerce update_status returned false; verified flags not written.", 'warning');
                    exit();
                }

                // Set verified flag AFTER successful transition.
                $order->update_meta_data('_upay_verified_capture', 1);
                // Backward-compatibility write for legacy readers (not a security gate).
                $order->update_meta_data('UPayments_webhook_triggered', 1);
                $order->save();

                $this->log("UPayments CAPTURED status verified.");

                exit();
            } catch (\Throwable $e) {
                // A6 — fail-closed exception containment. An unexpected internal
                // Throwable must not mark payment failed, must not cancel, must not
                // empty the cart, must not set the verified-success flag, and must
                // not include transport/data details in the logged diagnostic.
                $this->log("Webhook: unexpected internal error during verification.", 'warning');
                exit();
            }
        }

        public function check_ipn_response()
        {
            // Historical priority-10 compatibility fallback. The canonical
            // wc_upayments callback authority is PaymentLifecycle at priority 5;
            // direct/fallback invocation must use the same verified lifecycle.
            \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_callback();
            exit();
        }

        // Process payment (must use feature flags to route API calls)
        public function process_payment( $order_id ) {
            $gateway = $this;
            $request_body_reader = function () use ($gateway) {
                return $gateway->get_request_body_raw();
            };
            $request_executor = function ($route, $method, $body = null) use ($gateway) {
                return $gateway->execute_upayments_request($route, $method, $body);
            };

            return (new CheckoutOrchestrator(
                $gateway,
                $request_body_reader,
                $request_executor
            ))->process($order_id);
        }

        // Frontend payment fields (must use feature flags for design)
        public function payment_fields() {
            $save_card_enabled = ($this->get_option('enable_save_card') === 'yes');
            $template_args = array('gateway' => $this, 'save_card_enabled' => $save_card_enabled);
            // Check setting for design toggle
            $use_new_design = ($this->get_option('use_new_design') === 'yes');
            
            wc_get_template(
                $use_new_design ? 'new-design-form.php' : 'old-design-form.php',
                $template_args,
                '', // Preserve WooCommerce's default theme template override path.
                untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/'
            );
        }
        
        /**
         * enqueue_scripts
         *
         * @return void
         */
        public function enqueue_scripts() {
            $plugin_url = plugin_dir_url( __FILE__ );
            // Check if we are on the checkout page AND the gateway is active
            if ( ! is_checkout() || ! $this->is_available() ) {
                return;
            }
            wp_enqueue_style('supcheckout-customer', $plugin_url . 'assets/css/customer.css', array(), SUPCHECKOUT_VERSION );
            
            // Checkout must not depend on third-party font/icon CDNs.
            // Use site/system typography and plugin-local presentation only.

            if (is_checkout() && !is_wc_endpoint_url()) {
                if ($this->get_option('use_new_design') == 'yes') {
                    wp_enqueue_style('supcheckout-checkout-new-style', $plugin_url . 'assets/css/new-design.css', array(), SUPCHECKOUT_VERSION );
                    wp_enqueue_script('supcheckout-checkout-new-script', $plugin_url . 'assets/js/new-upay.js', array('jquery'), SUPCHECKOUT_VERSION, true );
                }

                if ($this->autoDeduction === 'yes'
                    && \UPayments\Subscription\Helpers\Utils::cartHasCustomType()
                ) {
                    wp_enqueue_script('supcheckout-subscription-checkout', $plugin_url . 'assets/js/subscription-checkout.js', array('jquery'), SUPCHECKOUT_VERSION, true);
                    wp_localize_script('supcheckout-subscription-checkout', 'wcUser', array(
                        'isLoggedIn' => is_user_logged_in(),
                    ));
                }
            }            
        }

        /**
         * Enqueue SUPCheckout gateway settings assets behind the exact admin route.
         */
        public function admin_enqueue_scripts() {
            $screen = get_current_screen();
            $query = array();
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These sanitized values select admin assets only and perform no state change.
            foreach (array('page', 'tab', 'section') as $query_key) {
                if (isset($_GET[$query_key]) && is_string($_GET[$query_key])) {
                    $query[$query_key] = sanitize_key(wp_unslash($_GET[$query_key]));
                }
            }
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            GatewaySettings::enqueue_admin_assets(
                plugin_dir_url(__FILE__),
                $this->id,
                $query,
                $screen ? $screen->id : ''
            );
        }

        public function admin_order_details($order)
        {
            if ($order->get_payment_method() === $this->id)
            {
                $payment_status_raw = $order->get_meta('UPayments_Result', true);
                $payment_status = is_scalar($payment_status_raw) ? (string) $payment_status_raw : '';

                $upayment_id_raw = $order->get_meta('UPayments_PaymentID', true);
                $upayment_id = is_scalar($upayment_id_raw) ? (string) $upayment_id_raw : '';

                if ($payment_status !== '' || $upayment_id !== '') { ?>
                    <table class="wc-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">
            <tbody>
                            <tr>
                                <td class="label"><h3 style="margin:0"><?php esc_html_e('Payment Status', 'supcheckout'); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount"><strong><?php echo esc_html($payment_status); ?></strong></span>
                                </td>
                            </tr>
                            <tr>
                <td class="label"><h3 style="margin:0"><?php esc_html_e('UPayment ID', 'supcheckout'); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount">
                                        <strong>
                                        <?php echo esc_html($upayment_id); ?>
                                        </strong>
                                    </span>
                                </td>
                            </tr>
                            
                        </tbody>
                    </table>
            <?php
                }
            }
        }

        public function custom_payment_gateway_icons($icon, $gateway_id)
        {
            foreach (WC()->payment_gateways->get_available_payment_gateways() as $gateway){
                if ($gateway->id == $gateway_id){
                    $title = $gateway->get_title();
                    break;
                }
            }
            if ($gateway_id == "upayments"){
                $icon = '<span>Pay securely with <img src="'.UP_PLUGIN_URL.'assets/images/upayment.png" alt="UPayments"  title="UPayments" style="height: 24px !important; padding-left:4px;"/></span>';
            }
            return $icon;
        }

        /**
         * Process Gateway Settings Form Fields.
         */
        public function process_admin_options()
        {
            $this->init_settings();
            $prepared = GatewaySettings::prepare_post_data($this->get_post_data());
            if ($prepared['api_key_missing'] || $prepared['multimerchant_missing']) {
                WC_Admin_Settings::add_error($prepared['api_key_missing']
                    ? __('Please enter UPayments API Key', 'supcheckout')
                    : __('Please enter Multimerchant Configuration', 'supcheckout'));
                return false;
            }

            $post_data = $prepared['post_data'];
            foreach ($this->get_form_fields() as $key => $field) {
                $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
            }
            delete_option("upayments_maat");
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce Settings API defines this dynamic core hook name.
            return update_option($this->get_option_key(), apply_filters("woocommerce_settings_api_sanitized_fields_" . $this->id, $this->settings));
        }

        public function get_multimerchant_credentials( $order ) {
            // 1. Check if Multimerchant is enabled at all
            if ($this->get_option( 'enable_multimerchant' ) == 'no') {
                return $this->get_default_credentials();
            }
            
            // 2. Retrieve and parse the stored rules
            $rules_json = $this->get_option( 'multimerchant_accounts', '[]' );
            $rules = json_decode( $rules_json, true );

            if (!is_array($rules) || empty($rules)) {
                // Fallback if rules are enabled but not configured
                $this->log( 'Multimerchant enabled but no rules found. Using default credentials.', 'error' );
                return $this->get_default_credentials();
            }

            // --- Core Routing Logic ---
            
            foreach ( $rules as $rule ) {
                $condition_type  = $rule['condition_type'] ?? '';
                $condition_value = $rule['condition_value'] ?? '';

                // If a rule has no condition, skip it (it won't match anything specific)
                if ( empty( $condition_type ) || empty( $condition_value ) ) {
                    continue;
                }

                $match_found = false;

                switch ( $condition_type ) {
                    case 'fixed':
                        // Check if the order currency matches the rule value (e.g., USD, EUR)
                        if ($condition_value === 'fixed') {
                            $match_found = true;
                        }
                        break;
                    case 'percentage':
                        // Check if the billing country matches the rule value (e.g., US, DE)
                        if ($condition_value === 'percentage') {
                            $match_found = true;
                        }
                        break;
                    default:
                        // Unhandled condition type
                        break;
                }

                if ( $match_found ) {
                    $this->log( "Multimerchant routing rule matched.", 'info' );
                    return [
                        'merchant_id' => $rule['merchant_id'],
                        'api_key'     => $rule['api_key'],
                    ];
                }
            }
            // 3. Fallback: If no custom rule matched, use default credentials
            $this->log( 'No specific routing rule matched. Using default credentials.', 'info' );
            return $this->get_default_credentials();
        }

        public function get_default_credentials() {
            // Assuming your default credentials are stored as standard gateway options
            return [
                'merchant_id' => $this->get_option( 'default_merchant_id' ),
                'api_key'     => $this->get_option( 'default_api_key' ),
            ];
        }

        /**
         * Generate the inherited single additional-merchant settings row.
         *
         * @param string $key Field key.
         * @param array  $data WooCommerce field data.
         * @return string
         */
        public function generate_multimerchant_repeater_html($key, $data) {
            return GatewaySettings::render_multimerchant(
                $key,
                $data,
                function ($option_key, $default = false) {
                    return $this->get_option($option_key, $default);
                },
                $this->id,
                $this->domain
            );
        }

        /**
         * Preserve the public WooCommerce custom-field validation seam.
         *
         * @param string $key Field key.
         * @param mixed  $value Raw field value.
         * @return string
         */
        public function validate_multimerchant_repeater_field($key, $value) {
            return GatewaySettings::sanitize_multimerchant_accounts($value);
        }

        public function getSiteName()
        {
            return __("Woocommerce", 'supcheckout');
        }

        public function getIsOrderComplete() {
            return $this->isOrderComplete === 'yes';
        }

        public function getMode() {
            return $this->testMode === 'yes';
        }
        
        public function getAPIUrl($apiRoute = "")
        {
            return (new EndpointResolver($this->getMode()))->resolve($apiRoute);
        }

        public function getAPIUrlForCreateToken()
        {
            return (new EndpointResolver($this->getMode()))->create_customer_token();
        }

        public function getAPIUrlForCheckPaymentButtonStatus() {
            return (new EndpointResolver($this->getMode()))->check_payment_button_status();
        }

        public function getAPIUrlForRetreiveCards() {
            return (new EndpointResolver($this->getMode()))->retrieve_customer_cards();
        }

        public function getUserAgent(){
            $userAgent = 'UpaymentsWoocommercePlugin/2.2.1';
            if ($this->getMode()) {
                $userAgent = 'SandboxUpaymentsWoocommercePlugin/2.2.1';
            }
            return $userAgent;
        }
        
        public function getCurrencyCode($code)
        {
            return $code;
        }

        public function encrypt($param)
        {
            return base64_encode($param);
        }

        public function decrypt($param)
        {
            return base64_decode($param);
        }

        public function getApiKey()
        {
            return password_hash($this->apiKey, PASSWORD_BCRYPT);
        }

        /**
         * Get customer unique token from phone.
         *
         * @deprecated Retained temporarily to avoid undefined-method breakage for
         *             third-party customizations. New code must use CustomerTokenIdentity.
         *             Future code-quality/public-API phase will decide final removal.
         * @param string $phone Unused. Previously used as customer token.
         * @return string Empty string. Phone is no longer used as token identity.
         */
        public function getCustomerUniqueToken($phone)
        {
            return '';
        }

        public function getUpayPaymentMethods()
        {
            $availability = new PaymentMethodAvailability(
                $this->getMode(),
                $this->apiKey,
                function () {
                    return $this->execute_upayments_request('check-payment-button-status', 'GET');
                }
            );
            $result = $availability->fetch();

            if (is_array($result)
                && isset($result['result'])
                && $result['result'] === 'failure'
            ) {
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", 'supcheckout'), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            return $result;
        }

        public function getSavedCards($customer_token)
        {
            $api_key = $this->apiKey;
            if (empty($api_key) || !is_string($customer_token) || $customer_token === '') {
                return null;
            }

            // Strict request input: must be ASCII numeric, 8-18 digits.
            $token_str = $customer_token;
            if (!preg_match('/^[0-9]{8,18}$/', $token_str)) {
                return null;
            }

            $params = wp_json_encode(array("customerUniqueToken" => $token_str));
            $transport = $this->execute_upayments_request('retrieve-customer-cards', 'POST', $params);

            if (!is_array($transport)) {
                return null;
            }

            if (!isset($transport['transport_ok']) || !$transport['transport_ok']) {
                return null;
            }

            if (!isset($transport['http_status']) || (int) $transport['http_status'] !== 201) {
                return null;
            }

            if (!isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0) {
                return null;
            }

            if (!isset($transport['body']) || !is_scalar($transport['body'])) {
                return null;
            }

            $result = json_decode((string) $transport['body'], true);
            if (!is_array($result)) {
                return null;
            }

            if (!array_key_exists('status', $result) || $result['status'] !== true) {
                return null;
            }

            if (!isset($result['data']) || !is_array($result['data'])) {
                return null;
            }

            if (!array_key_exists('customerCards', $result['data']) || !is_array($result['data']['customerCards'])) {
                return null;
            }

            return array(
                'data' => $result['data']['customerCards'],
                'result' => 'success',
            );
        }

        /**
         * Section AH: Defense in depth — require already-normalized payment state.
         *
         * This helper NEVER makes additional availability calls (e.g. getPaymentIcons()).
         * The caller must supply the exact normalized availability state that has
         * already been validated. When the caller cannot supply valid state, the
         * helper fails closed and returns null.
         *
         * @param array|null $payment_data Already-normalized availability state.
         * @return array|null Provider response on success, null on any failure.
         */
        public function getSavedCardsForCurrentUser($payment_data)
        {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                return null;
            }

            if ($this->saveCardEnabled !== 'yes') {
                return null;
            }

            // Strict structural validation of the supplied normalized state.
            // Must be array; whitelabled MUST be exactly boolean true;
            // payment MUST be array; payment['cc'] MUST be present (CC enabled).
            if (!is_array($payment_data)) {
                return null;
            }
            if (!array_key_exists('whitelabled', $payment_data)
                || $payment_data['whitelabled'] !== true
            ) {
                return null;
            }
            if (!array_key_exists('payment', $payment_data)
                || !is_array($payment_data['payment'])
            ) {
                return null;
            }
            // CC must be EXPLICITLY enabled (key present, scalar non-empty).
            if (!array_key_exists('cc', $payment_data['payment'])) {
                return null;
            }
            $cc_value = $payment_data['payment']['cc'];
            if (!is_string($cc_value) || $cc_value === '') {
                return null;
            }

            $gateway = $this;
            return CustomerTokenIdentity::get_saved_cards_for_current_user(
                $user_id,
                $this->apiKey,
                $this->getMode(),
                function($token) use ($gateway) {
                    return $gateway->getSavedCards($token);
                }
            );
        }

        /**
         * UTF-8 safe provider text truncation.
         * PHP 7.2 compatible, no mandatory mbstring dependency.
         */
        private function truncate_provider_text($value, $max_chars) {
            return CheckoutPayload::truncate_provider_text($value, $max_chars);
        }

        public function getPaymentIcons()
        {
            $data = $this->getUpayPaymentMethods();

            // Fail safely if upstream did not return a usable success payload.
            if (!is_array($data)
                || !isset($data['result'])
                || $data['result'] !== 'success') {
                return;
            }

            // Admin toggle (feature on/off)
            $isSubscriptionFeatureEnabled = ($this->autoDeduction === 'yes');

            // Cart state
            $hasSubscriptionProduct = \UPayments\Subscription\Helpers\Utils::cartHasCustomType();
            $hasNormalProduct      = \UPayments\Subscription\Helpers\Utils::cartHasNormalProduct();

            // Subscription context = feature enabled AND subscription product in cart
            $isSubscriptionContext = $isSubscriptionFeatureEnabled && $hasSubscriptionProduct && !$hasNormalProduct;

            $payment_methods = isset($data['payButtons']) && is_array($data['payButtons'])
                ? $data['payButtons']
                : array();

            $whitelabled = isset($data['isWhiteLabel']) && $data['isWhiteLabel'] === true;
            $methods     = [];

            // Section P: Non-Whitelabel generic checkout must always be available.
            $methods['payment'] = array();

            // If ONLY normal products in cart → allow all methods
            if (!$isSubscriptionContext) {
                if (isset($payment_methods['knet']) && $payment_methods['knet'] === 1) {
                    $methods['payment']['knet'] = __('KNET', 'supcheckout');
                }

                if (isset($payment_methods['apple_pay_knet']) && $payment_methods['apple_pay_knet'] === 1) {
                    $methods['payment']['apple-pay-knet'] = __('Apple Pay KNET', 'supcheckout');
                }

                if (isset($payment_methods['credit_card']) && $payment_methods['credit_card'] === 1) {
                    $methods['payment']['cc'] = __('Credit Card', 'supcheckout');
                }

                if (isset($payment_methods['apple_pay']) && $payment_methods['apple_pay'] === 1) {
                    $methods['payment']['apple-pay'] = __('Apple Pay Credit Card', 'supcheckout');
                }

                if (isset($payment_methods['samsung_pay']) && $payment_methods['samsung_pay'] === 1) {
                    $methods['payment']['samsung-pay'] = __('Samsung Pay', 'supcheckout');
                }

                if (isset($payment_methods['google_pay']) && $payment_methods['google_pay'] === 1) {
                    $methods['payment']['google-pay'] = __('Google Pay', 'supcheckout');
                }
            } else { // If subscription product in cart → ONLY CC allowed (per API requirement)
                if (isset($payment_methods['credit_card']) && $payment_methods['credit_card'] === 1) {
                    $methods['payment']['cc'] = __('Credit Card', 'supcheckout');
                }
            }

            $methods['whitelabled'] = $whitelabled;
            return $methods;
        }

        public function log($content, $level = 'debug')
        {
            // Diagnostic logging is explicitly opt-in.
            // WooCommerce checkbox values resolve to the string 'yes' or 'no';
            // the string 'no' is truthy in PHP, so a loose check enables logging
            // even when the merchant intends Debug = disabled.
            if ($this->debug !== 'yes') {
                return;
            }

            if (!function_exists('wc_get_logger')) {
                return;
            }

            $allowed_levels = array('debug', 'info', 'notice', 'warning', 'error');
            if (!in_array($level, $allowed_levels, true)) {
                $level = 'debug';
            }

            if (is_array($content) || is_object($content)) {
                $content = '[complex diagnostic data omitted]';
            }

            wc_get_logger()->{$level}(
                (string) $content,
                array('source' => 'upayments')
            );
        }
        
        /**
         * initializeSubscriptionModule
         * Handle Subscription Module Initialization If Enabled from Admin Settings
         * @return void
         */
        public function initializeSubscriptionModule()
        {
            SubscriptionComposition::initialize_legacy_modules();
        }

        /**
         * Build API payload for invoice / subscription
         *
         * @param WC_Order $order
         * @return array
         */
        protected function build_api_payload($order)
        {
            $payload = [
                'order_id' => $order->get_id(),
                'amount'   => $order->get_total(),
                'currency' => $order->get_currency(),
                'customer' => [
                    'email' => $order->get_billing_email(),
                    'name'  => $order->get_formatted_billing_full_name(),
                ],
            ];

            $plan = $order->get_meta('_upay_subscription_plan');

            if ($plan && $plan !== 'one_time') {

                $interval = (int) $order->get_meta('_upay_subscription_interval');

                $payload['subscription'] = [
                    'enabled'            => true,
                    'type'               => 'recurring',
                    'plan'               => $plan,
                    'interval'           => $interval,
                    'period'             => $plan === 'yearly' ? 'year' : 'month',
                    'start_immediately'  => true,
                ];
            }

            return $payload;
        }

        /**
         * Render subscription summary in admin order view
         *
         * @param WC_Order $order
         * @return array
         */
        public function render_subscription_summary($order)
        {
            SubscriptionPresentation::render_admin_summary($order);
        }

        /**
         * restrictMixedCartProducts
         * Function to restrict adding subscription products together with normal products in the cart
         * @param  mixed $passed
         * @param  mixed $product_id
         * @param  mixed $quantity
         * @return void
         */
        public function restrictMixedCartProducts($passed, $product_id, $quantity)
        {
            return SubscriptionPresentation::restrict_mixed_cart_products(
                $passed,
                $product_id,
                $quantity,
                $this->domain
            );
        }
        
        /**
         * renderSubscriptionBadgeInProductList
         * Function to render subscription badge in product list if the product is subscription type
         * @return void
         */
        public function renderSubscriptionBadgeInProductList()
        {
            SubscriptionPresentation::render_subscription_badge();
        }
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

add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce callback retained for compatibility.
function addUpaymentsGatewayClass($methods)
{
    $methods[] = "WC_UPayments";
    return $methods;
}

add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy WooCommerce callback retained for compatibility.
function enableUpaymentsGateway($available_gateways)
{
    return Availability::filter($available_gateways);
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

register_activation_hook(__FILE__, 'myPaymentPluginSetupCheckout');
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Legacy activation callback retained for existing plugin lifecycle compatibility.
function myPaymentPluginSetupCheckout() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
    $checkout_page_id = wc_get_page_id('checkout');
    if (!$checkout_page_id) {
        return;
    }

    $post = get_post($checkout_page_id);
    if (!$post) {
        return;
    }

    $has_shortcode = has_shortcode($post->post_content, 'woocommerce_checkout');
    $has_block     = has_block('woocommerce/checkout', $post->post_content);

    $use_blocks = false;
    $settings = get_option("woocommerce_upayments_settings");
    if (is_array($settings) && isset($settings['enable_block_checkout']) && $settings['enable_block_checkout'] === 'yes') {
        $use_blocks = true;
    }

    if (!$has_shortcode && !$has_block && !$use_blocks) {
        wp_update_post([
            'ID'           => $checkout_page_id,
            'post_content' => '[woocommerce_checkout]', // default: classic
        ]);
    }
}

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
    wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
    exit;
});
