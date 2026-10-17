<?php

namespace Simplixi\SUPCheckout\Gateway;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Plugin;
use Simplixi\SUPCheckout\Admin\GatewaySettings;
use Simplixi\SUPCheckout\Gateway\CheckoutAssets;
use Simplixi\SUPCheckout\Gateway\OrderPresentation;
use Simplixi\SUPCheckout\Provider\EndpointResolver;
use Simplixi\SUPCheckout\Provider\PaymentMethodAvailability;
use Simplixi\SUPCheckout\Payment\CheckoutPayload;
use Simplixi\SUPCheckout\Payment\CheckoutOrchestrator;
use Simplixi\SUPCheckout\Subscription\Composition as SubscriptionComposition;
use Simplixi\SUPCheckout\Subscription\Presentation as SubscriptionPresentation;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\LegacyCallbackListener;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

/**
 * The WooCommerce payment gateway WooCommerce registers as 'upayments'.
 *
 * Moved verbatim from the main file in Sub-project 0a Task 3. WooCommerce owns the
 * one instance (GatewayRegistration hands it this class name); the protected
 * identities (gateway ID, settings option, wc_upayments callback route) are unchanged.
 */
class Gateway extends \WC_Payment_Gateway {
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
        return parent::is_available() && GatewaySettings::is_runtime_eligible(Keys::get_settings(), get_woocommerce_currency());
    }

    public function __construct() {
        // Define ID, title, description, and settings.
        $this->id                 = 'upayments';
        $this->icon = SUPCHECKOUT_PLUGIN_URL . "assets/images/upayment.png";
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
        LegacyCallbackListener::register_gateway($this);
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
            echo '<a href="' . esc_url($account_url) . '" target="_blank" rel="noopener noreferrer">';
            esc_html_e('Go to My Account', 'supcheckout');
            echo '</a>';
            echo '</div>';

            $gateways = WC()->payment_gateways()->get_available_payment_gateways();

            $upay = Keys::find_gateway($gateways);
            if ($upay === null) {
                return;
            }

            if (Keys::is_our_gateway(WC()->session->get('chosen_payment_method')) && $upay->get_option('make_default_gateway') === 'no') {
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
                return Keys::gateway_id();
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
        if (!$order instanceof \WC_Order) {
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
     * by PaymentLifecycle StatusVerifier binding (R5) or the Scheduler
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
     * Public browser compatibility entrypoint (T3: direct callers may omit GET page).
     * Explicit browser mode — never spoofed via superglobals.
     */
    public function return_from_upayments()
    {
        if (function_exists('wc_nocache_headers')) {
            wc_nocache_headers();
        } elseif (function_exists('nocache_headers')) {
            nocache_headers();
        }
        // Historical browser callers populate GET callback fields (page marker optional).
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External UPayments browser return cannot carry a WordPress nonce; identifiers are sanitized and paid-state authority requires authenticated provider status verification.
        $get_bag = (isset($_GET) && is_array($_GET)) ? $_GET : array();
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_compat_callback('browser', $get_bag);
        exit();
    }

    /**
     * Public webhook compatibility entrypoint.
     * Explicit webhook mode even if request globals contradict.
     */
    public function web_hook_handler()
    {
        if (function_exists('wc_nocache_headers')) {
            wc_nocache_headers();
        } elseif (function_exists('nocache_headers')) {
            nocache_headers();
        }
        // Historical direct webhooks populated $_REQUEST only (often not $_POST).
        // Forward only canonical callback keys — never cookies or the raw bag.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- External provider webhook cannot carry a WordPress nonce; identifiers are sanitized and paid-state authority requires authenticated provider status verification.
        $request = (isset($_REQUEST) && is_array($_REQUEST)) ? $_REQUEST : array();
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $primary = array();
        foreach (array('wc_order_id', 'track_id', 'requested_order_id') as $key) {
            if (array_key_exists($key, $request)) {
                $primary[$key] = $request[$key];
            }
        }
        \Simplixi\SUPCheckout\Payment\PaymentLifecycle::handle_compat_callback('webhook', $primary);
        exit();
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
        // R2 platform edge: WooCommerce owns public WC-API URL construction.
        $callback_url_resolver = static function () {
            if (!function_exists('WC')) {
                return null;
            }

            $woocommerce = WC();
            if (!is_object($woocommerce) || !method_exists($woocommerce, 'api_request_url')) {
                return null;
            }

            $url = $woocommerce->api_request_url(Keys::callback_route());
            return is_string($url) ? $url : null;
        };

        return (new CheckoutOrchestrator(
            $gateway,
            $request_body_reader,
            $request_executor,
            $callback_url_resolver
        ))->process($order_id);
    }

    // Frontend payment fields (must use feature flags for design)
    /**
     * Classic pre-check before WooCommerce creates the order.
     */
    public function validate_fields() {
        // Blocks (Store API) sends the method in the JSON body; process_payment() checks it.
        if (CheckoutPayload::is_store_api_checkout_request()) {
            return true;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before calling validate_fields().
        if (CheckoutPayload::classic_method_selected($this->getPaymentIcons(), wp_unslash($_POST))) {
            return true;
        }
        wc_add_notice(__('Please choose how you would like to pay.', 'supcheckout'), 'error');
        return false;
    }

    public function payment_fields() {
        $save_card_enabled = ($this->get_option('enable_save_card') === 'yes');
        $template_args = array('gateway' => $this, 'save_card_enabled' => $save_card_enabled);
        // Check setting for design toggle
        $use_new_design = ($this->get_option('use_new_design') === 'yes');

        wc_get_template(
            $use_new_design ? 'new-design-form.php' : 'old-design-form.php',
            $template_args,
            '', // Preserve WooCommerce's default theme template override path.
            untrailingslashit( plugin_dir_path( Plugin::main_file() ) ) . '/templates/'
        );
    }

    /**
     * enqueue_scripts
     *
     * @return void
     */
    public function enqueue_scripts() {
        $plugin_url = plugin_dir_url( Plugin::main_file() );
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
                wp_localize_script('supcheckout-checkout-new-script', 'supCheckoutI18n', CheckoutAssets::new_design_i18n());
            }

            if ($this->autoDeduction === 'yes'
                && \Simplixi\SUPCheckout\Subscription\Helpers\Utils::cartHasCustomType()
            ) {
                wp_enqueue_script('supcheckout-subscription-checkout', $plugin_url . 'assets/js/subscription-checkout.js', array('jquery'), SUPCHECKOUT_VERSION, true);
                wp_localize_script('supcheckout-subscription-checkout', 'wcUser', array(
                    'isLoggedIn' => is_user_logged_in(),
                    'i18n' => CheckoutAssets::subscription_i18n(),
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
            plugin_dir_url(Plugin::main_file()),
            $this->id,
            $query,
            $screen ? $screen->id : ''
        );
    }

    public function admin_order_details($order)
    {
        if (Keys::is_our_gateway($order->get_payment_method()))
        {
            $read_single = function ($key) use ($order) {
                return $order->get_meta($key, true);
            };
            $payment_status_raw = Keys::read($read_single, 'UPayments_Result');
            $payment_status = is_scalar($payment_status_raw) ? (string) $payment_status_raw : '';

            $upayment_id_raw = Keys::read($read_single, 'UPayments_PaymentID');
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
        if (Keys::is_our_gateway($gateway_id)){
            $icon = '<span>' . esc_html__('Pay securely with', 'supcheckout') . ' <img src="' . esc_url(SUPCHECKOUT_PLUGIN_URL . 'assets/images/upayment.png') . '" alt="" style="height: 24px !important; padding-left:4px;"/></span>';
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
            \WC_Admin_Settings::add_error($prepared['api_key_missing']
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
        $hasSubscriptionProduct = \Simplixi\SUPCheckout\Subscription\Helpers\Utils::cartHasCustomType();
        $hasNormalProduct      = \Simplixi\SUPCheckout\Subscription\Helpers\Utils::cartHasNormalProduct();

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
     * @param \WC_Order $order
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

        $plan = Keys::read_order_meta($order, '_upay_subscription_plan');

        if ($plan && $plan !== 'one_time') {

            $interval = (int) Keys::read_order_meta($order, '_upay_subscription_interval');

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
     * @param \WC_Order $order
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
