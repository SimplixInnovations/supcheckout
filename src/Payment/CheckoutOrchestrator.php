<?php

namespace Simplixi\SUPCheckout\Payment;

use Simplixi\SUPCheckout\Provider\MultiMerchantContract;
use UPayments\Token\CustomerTokenIdentity;

/**
 * Canonical WooCommerce checkout-to-Charge orchestration.
 *
 * The legacy gateway remains the compatibility adapter for transport and
 * request-body seams; all checkout workflow ordering lives here.
 */
class CheckoutOrchestrator {
    private $gateway;
    private $requestBodyReader;
    private $requestExecutor;
    private $requestBodyLoaded = false;
    private $requestBodyCache = null;

    public function __construct($gateway, callable $request_body_reader, callable $request_executor) {
        $this->gateway = $gateway;
        $this->requestBodyReader = $request_body_reader;
        $this->requestExecutor = $request_executor;
    }

    private function read_request_body() {
        if (!$this->requestBodyLoaded) {
            $this->requestBodyCache = call_user_func($this->requestBodyReader);
            $this->requestBodyLoaded = true;
        }

        return $this->requestBodyCache;
    }

    private function execute_request($route, $method, $body = null) {
        return call_user_func($this->requestExecutor, $route, $method, $body);
    }

        public function process($order_id) {
            $gateway = $this->gateway;
            global $woocommerce;

            // Section Y: Defensive order boundary.
            $parsed_order_id = self::parse_order_id($order_id);
            if ($parsed_order_id === null) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            $order_id = $parsed_order_id;

            $order = wc_get_order($order_id);
            if (!$order || !($order instanceof \WC_Order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Woo owns the canonical payability decision (status + positive total,
            // including extension filters). process_payment() can be replayed or
            // called outside the normal checkout UI, so reject non-payable orders
            // before availability lookup, token work, or non-idempotent Charge.
            if (!$order->needs_payment()) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $whitelabled = false;
            $order_data = $order->get_data();
            $order_total = $order->get_total();

            $success_url = site_url() . "/?wc-api=wc_upayments&page=success&wc_order_id=" . $order_id;
            $error_url = site_url() . "/?wc-api=wc_upayments&page=error&wc_order_id=" . $order_id;
            $ipn_url = site_url() . "/?wc-api=wc_upayments&wc_order_id=" . $order_id;

            $unique_order_id = md5(wp_generate_uuid4());
            $product_name = [];
            $product_price = [];
            $product_qty = [];
            $product_type = [];

            $productArrayNew = [];
            $product_price_tokens = [];
            $product_descriptors_available = true;
            $cart_has_custom_product = false;
            $order_has_subscription_product = false;
            $order_has_normal_product = false;
            $order_has_subscription_restricted_product = false;

            $i=0;

            foreach ($order->get_items('line_item') as $item)
            {
                // Section J: Product boundary — fail, do not skip.
                if (!$item || !($item instanceof \WC_Order_Item_Product)) {
                    $gateway->log('Invalid line item in order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                /** @var \WC_Order_Item_Product $item */
                $product = $item->get_product();
                if (!$product || !($product instanceof \WC_Product)) {
                    $gateway->log('Unloadable product in order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Subscription composition is authoritative order classification,
                // independent of whether optional provider product descriptors can
                // represent this line's unit economics exactly.
                if($product->get_type() === 'custom_type'){
                    $cart_has_custom_product = true;
                    $order_has_subscription_product = true;
                    $product_id = (int) $item->get_product_id();
                    if ($product_id > 0
                        && get_post_meta($product_id, '_upay_disable_subscription', true) === 'yes'
                    ) {
                        $order_has_subscription_restricted_product = true;
                    }
                } else {
                    $order_has_normal_product = true;
                }

                // Once any line cannot be represented exactly, products[] is
                // omitted wholesale. Continue classifying later lines for payment
                // safety, but do not rebuild a partial descriptive ledger.
                if (!$product_descriptors_available) {
                    continue;
                }

                // Section D: Use order-line values, not current catalog price.
                // Provider product descriptors require a positive integer quantity,
                // but products[] is optional descriptive data. If Woo/extension
                // order data cannot be represented exactly, omit products[] instead
                // of vetoing the finalized Woo order economics.
                $qty = $item->get_quantity();
                if (!is_int($qty) || $qty <= 0 || $qty > 9999999) {
                    $gateway->log('Product descriptors omitted: quantity is not provider-representable.', 'warning');
                    $productArrayNew = array();
                    $product_price_tokens = array();
                    $product_descriptors_available = false;
                    continue;
                }

                // Section D2: Pure deterministic decimal handling.
                // Product line economics are descriptive only. Never derive Charge
                // authority from them and never coerce a float into an exact decimal.
                $raw_line_total = $item->get_total();
                if (is_float($raw_line_total)) {
                    $gateway->log('Product descriptors omitted: float line total is not exact.', 'warning');
                    $productArrayNew = array();
                    $product_price_tokens = array();
                    $product_descriptors_available = false;
                    continue;
                }
                $line_total_canonical = CheckoutPayload::canonicalize_provider_decimal_string($raw_line_total);
                $line_total_validation = CheckoutPayload::validate_provider_nonnegative_decimal($line_total_canonical, 'line_total');
                if ($line_total_validation === null) {
                    $gateway->log('Product descriptors omitted: line total is not provider-representable.', 'warning');
                    $productArrayNew = array();
                    $product_price_tokens = array();
                    $product_descriptors_available = false;
                    continue;
                }
                $line_total = $line_total_validation;

                // Derive provider-compatible unit price from order line as a
                // deterministic decimal string. No round()/float math. Quantization
                // uses string-based decimal division by the integer quantity.
                $unit_price = CheckoutPayload::compute_provider_unit_price_decimal($line_total, $qty);
                if ($unit_price === null) {
                    // products[] is optional descriptive data. If one line cannot
                    // be expressed exactly, omit the entire descriptor array rather
                    // than rounding it or vetoing the authoritative Woo order total.
                    $gateway->log('Product descriptors omitted: exact unit price unavailable.', 'warning');
                    $productArrayNew = array();
                    $product_price_tokens = array();
                    $product_descriptors_available = false;
                    continue;
                }

                // Section F: UTF-8 safe truncation.
                $normalized_name = CheckoutPayload::truncate_provider_text($item->get_name(), 255);
                $normalized_description = CheckoutPayload::truncate_provider_text($item->get_name(), 255);

                // Section C: Use normalized values in payload.
                // 'type' is intentionally omitted — provider does not document a
                // contract for this key, so we send only the documented keys.
                //
                // The 'price' field is built as a per-product indexed sentinel so
                // the injection step can replace it with a JSON NUMBER (not quoted).
                // We track every product's validated price token so the injector
                // can verify each one against its corresponding sentinel.
                $productArrayNew[$i] = array(
                    'name'        => $normalized_name,
                    'description' => $normalized_description,
                    'price'       => '__UPAY_PRODUCT_PRICE_SENTINEL_' . $i . '__',
                    'quantity'    => $qty,
                );
                $product_price_tokens[] = $unit_price;
                $i++;
            }

            if ($product_descriptors_available && empty($productArrayNew)) {
                // A positive finalized Woo order can legitimately contain only fees
                // or other non-product adjustments. products[] is descriptive only;
                // absence of product descriptors must not veto authoritative order
                // economics. Subscription validation below still requires a real
                // subscription product for every non-one_time plan.
                $gateway->log('Product descriptors omitted: order has no product line descriptors.', 'warning');
                $product_descriptors_available = false;
            }

            // Q19: product-level subscription opt-out is authoritative order data.
            // A valid non-one_time subscription selection for a restricted product
            // must be rejected before availability lookup, because a cold cache can
            // make getPaymentIcons() perform provider transport. The full canonical
            // request parser below remains authoritative for every other path.
            if ($order_has_subscription_restricted_product) {
                $preflight_subscription_plan = 'one_time';

                if (CheckoutPayload::is_store_api_checkout_request()) {
                    $preflight_raw_input = $this->read_request_body();
                    $preflight_request_data = is_string($preflight_raw_input) && $preflight_raw_input !== ''
                        ? json_decode($preflight_raw_input, true)
                        : null;

                    if (is_array($preflight_request_data)
                        && isset($preflight_request_data['extensions'])
                        && is_array($preflight_request_data['extensions'])
                        && isset($preflight_request_data['extensions']['upayments'])
                        && is_array($preflight_request_data['extensions']['upayments'])
                    ) {
                        $preflight_extension_data = $preflight_request_data['extensions']['upayments'];
                        if (CheckoutPayload::field_present($preflight_extension_data, 'upay_subscription_plan')) {
                            $preflight_parsed_plan = CheckoutPayload::parse_subscription_plan_strict(
                                $preflight_extension_data['upay_subscription_plan']
                            );
                            if ($preflight_parsed_plan !== null
                                && CheckoutPayload::is_valid_subscription_plan($preflight_parsed_plan)
                            ) {
                                $preflight_subscription_plan = $preflight_parsed_plan;
                            }
                        }
                    }
                } else {
                    $preflight_classic_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification -- Woo checkout owns nonce verification.
                    if (CheckoutPayload::field_present($preflight_classic_post, 'upay_subscription_plan')
                        && is_scalar($preflight_classic_post['upay_subscription_plan'])
                    ) {
                        $preflight_parsed_plan = CheckoutPayload::parse_subscription_plan_strict(
                            wp_unslash($preflight_classic_post['upay_subscription_plan'])
                        );
                        if ($preflight_parsed_plan !== null
                            && CheckoutPayload::is_valid_subscription_plan($preflight_parsed_plan)
                        ) {
                            $preflight_subscription_plan = $preflight_parsed_plan;
                        }
                    }
                }

                if ($preflight_subscription_plan !== 'one_time') {
                    $gateway->log('Subscription plan rejected: product-level opt-out.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid payment type.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            }

            if($gateway->paymentData == null ) {
                $payment_data = $gateway->getPaymentIcons();
            } else {
                $payment_data = $gateway->paymentData;
            }

            // Availability state must not fail open to KNET.
            // Require valid payment_data with boolean whitelabled key.
            if (!is_array($payment_data)
                || !array_key_exists('whitelabled', $payment_data)
                || !is_bool($payment_data['whitelabled'])
            ) {
                $gateway->log('Payment methods availability unavailable or malformed.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", 'supcheckout'), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            $whitelabled = $payment_data['whitelabled'];

            // Central checkout defaults — initialized before Classic/Blocks branching.
            $src                   = null; // Section O: Non-Whitelabel uses hosted checkout, no specific source.
            $cardToken             = null;
            $isSaveCard            = false;
            $isSaveCardRequested   = false;
            $subscription_plan     = 'one_time';
            $subscription_interval = 0;
            $user_id               = get_current_user_id();

            // Section AN: Detect Store API/REST independently.
            // Must use authoritative Store API namespace detection, not REST_REQUEST alone.
            $is_store_api = CheckoutPayload::is_store_api_checkout_request();
            $is_blocks_request = false;
            $request_data = null;
            $extension_data = array();

            if ($is_store_api) {
                // Store API: parse JSON only, never consume Classic POST.
                $raw_input = $this->read_request_body();
                if (is_string($raw_input) && $raw_input !== '') {
                    $request_data = json_decode($raw_input, true);
                }
                if (!is_array($request_data)) {
                    // Malformed JSON in Store API context — reject.
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                if (array_key_exists('extensions', $request_data)) {
                    if (!is_array($request_data['extensions'])) {
                        // Malformed extensions container — fail closed.
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if (array_key_exists('upayments', $request_data['extensions'])) {
                        if (!is_array($request_data['extensions']['upayments'])) {
                            // Malformed upayments extension data — fail closed.
                            wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $extension_data = $request_data['extensions']['upayments'];
                    }
                    // Missing upayments namespace (or explicit null) => still Blocks,
                    // not Classic fallback. The request shape itself is Store API.
                }
                $is_blocks_request = true;
            }
            // Classic POST path is only for actual Classic checkout (not Store API).

            if ($is_blocks_request) {
                // Blocks path: read save_card and card_token only.
                // Section AC: Reject non-scalar security-sensitive fields.
                // Presence is decided by array_key_exists so explicit JSON null cannot
                // masquerade as absence.
                if (CheckoutPayload::field_present($extension_data, 'card_token')) {
                    $raw = $extension_data['card_token'];
                    if ($raw === null) {
                        $cardToken = null;
                    } elseif (is_string($raw)) {
                        // Section AT: Strict no-trim card token handling.
                        // The card token is a security identifier — we accept it
                        // exactly as supplied and validate without trimming.
                        // Whitespace anywhere (including leading/trailing) is invalid
                        // and is rejected as a malformed value (not coerced).
                        if ($raw === '') {
                            $cardToken = null;
                        } elseif (preg_match('/\s/', $raw)) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        } else {
                            $cardToken = $raw;
                        }
                    } elseif (is_int($raw)) {
                        // Strict: integer token not supported by the existing frozen contract.
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    } else {
                        // Arrays, objects, bools, floats are invalid.
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                }

                if (CheckoutPayload::field_present($extension_data, 'save_card')) {
                    // Presence-aware: parse only when explicitly supplied.
                    // The parser itself rejects null, '', booleans, floats, 'yes',
                    // 'true', 2, arrays, objects, etc.
                    $parsed_save = CheckoutPayload::parse_save_card_strict($extension_data['save_card']);
                    if ($parsed_save === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $isSaveCardRequested = $parsed_save;
                }

                if (CheckoutPayload::field_present($extension_data, 'upay_subscription_plan')) {
                    $parsed_plan = CheckoutPayload::parse_subscription_plan_strict($extension_data['upay_subscription_plan']);
                    if ($parsed_plan === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if (!CheckoutPayload::is_valid_subscription_plan($parsed_plan)) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_plan = $parsed_plan;
                }
                if (CheckoutPayload::field_present($extension_data, 'upay_subscription_interval')) {
                    if (!is_scalar($extension_data['upay_subscription_interval'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_interval = CheckoutPayload::parse_interval($extension_data['upay_subscription_interval']);
                }
            } else {
                // Classic path: require scalar + wp_unslash before sanitizing.
                // Section AC: Reject non-scalar security-sensitive fields.
                // Presence-aware: $_POST is treated as array for field presence.
                $gateway->log("Whitelabled: " . ($whitelabled ? "true" : "false"));
                // WooCommerce validates the checkout request before invoking gateway process_payment().
                $classic_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification -- Nonce ownership is upstream in Woo checkout.

                if (CheckoutPayload::field_present($classic_post, 'save_card')) {
                    if (!is_scalar($classic_post['save_card'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $parsed_save = CheckoutPayload::parse_save_card_strict(wp_unslash($classic_post['save_card']));
                    if ($parsed_save === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $isSaveCardRequested = $parsed_save;
                }

                if (CheckoutPayload::field_present($classic_post, 'card_token')) {
                    // Section #14: Reject int/float/bool/array/object outright.
                    // A scalar coercion of a non-string into a string token would
                    // accept a security identifier that the Blocks path already
                    // rejects, and would mask a malformed request. Strings only,
                    // no leading/trailing whitespace.
                    if (!is_string($classic_post['card_token'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $raw_card = wp_unslash($classic_post['card_token']);
                    if (!is_string($raw_card) || preg_match('/\s/', $raw_card)) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    // Section AT: Strict no-trim card token handling (Classic path too).
                    $cardToken = $raw_card;
                }

                if (CheckoutPayload::field_present($classic_post, 'upay_subscription_plan')) {
                    $plan_raw = wp_unslash($classic_post['upay_subscription_plan']);
                    $parsed_plan = CheckoutPayload::parse_subscription_plan_strict($plan_raw);
                    if ($parsed_plan === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if (!CheckoutPayload::is_valid_subscription_plan($parsed_plan)) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_plan = $parsed_plan;
                }
                if (CheckoutPayload::field_present($classic_post, 'upay_subscription_interval')) {
                    if (!is_scalar($classic_post['upay_subscription_interval'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_interval = CheckoutPayload::parse_interval(wp_unslash($classic_post['upay_subscription_interval']));
                }
            }

            // === PAYMENT SOURCE RESOLUTION ===
            // Determine payment source based on whitelabel state.
            // Non-whitelabel: source is always 'knet' (client input ignored).
            // Whitelabel: source must be explicitly provided by client.
            if ($whitelabled) {
                // Whitelabel: read client-supplied source via presence-aware detection.
                // Source must be explicitly supplied (not null, not '', not absent).
                $raw_src = null;
                if ($is_blocks_request) {
                    if (CheckoutPayload::field_present($extension_data, 'upayment_payment_type')) {
                        if (!is_scalar($extension_data['upayment_payment_type'])) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $raw_src = CheckoutPayload::parse_payment_source_strict($extension_data['upayment_payment_type']);
                        if ($raw_src === null) {
                            WC()->session->set("refresh_totals", true);
                            wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                            return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                        }
                    }
                } else {
                    if (CheckoutPayload::field_present($classic_post, 'upayment_payment_type')) {
                        if (!is_scalar($classic_post['upayment_payment_type'])) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $raw_src = CheckoutPayload::parse_payment_source_strict(wp_unslash($classic_post['upayment_payment_type']));
                        if ($raw_src === null) {
                            WC()->session->set("refresh_totals", true);
                            wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                            return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                        }
                    }
                }

                // Whitelabel source must be explicit: missing/empty/array → reject.
                if ($raw_src === null || $raw_src === '') {
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                $src = $raw_src;
            }
            // Non-whitelabel: $src remains null (hosted checkout).

            // Derive selected-card state after both Blocks/Classic paths resolved.
            $has_selected_card = is_string($cardToken) && $cardToken !== '';

            // === CROSS-PATH VALIDATION (applies to both Classic and Blocks) ===

            // Section C: Source validation only for Whitelabel.
            if ($whitelabled) {
                // Payment source server allowlist.
                if (!CheckoutPayload::is_valid_payment_source($src)) {
                    $gateway->log('Invalid payment source rejected.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // Whitelabel enabled-method check: fail closed if payment map unavailable.
                if (!isset($payment_data['payment'])
                    || !is_array($payment_data['payment'])
                ) {
                    $gateway->log('Whitelabel: payment method map unavailable.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                if (!isset($payment_data['payment'][$src])) {
                    $gateway->log('Disabled payment source rejected: ' . $src, 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            }

            // Subscription plan allowlist.
            if (!CheckoutPayload::is_valid_subscription_plan($subscription_plan)) {
                $gateway->log('Invalid subscription plan rejected: ' . $subscription_plan, 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Please select a valid payment type.", 'supcheckout'), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            // Section N: Subscription-context enforcement with mixed-order rejection.
            // Uses order-derived composition from the authoritative line-item pass above.
            if ($subscription_plan !== 'one_time') {
                if ($order_has_subscription_restricted_product) {
                    $gateway->log('Subscription plan rejected: product-level opt-out.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid payment type.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                if ($gateway->autoDeduction !== 'yes'
                    || !$order_has_subscription_product
                    || $order_has_normal_product
                ) {
                    $gateway->log('Subscription plan rejected: mixed order or invalid context.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid payment type.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Guest subscriptions must fail server-side.
                if (!is_user_logged_in()) {
                    $gateway->log('Subscription checkout rejected for guest.');
                    wc_add_notice(__("Please log in to purchase a subscription.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Subscription checkout requires cc.
                if ($src !== 'cc') {
                    $gateway->log('Subscription checkout requires cc payment source.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Subscription payments require Credit Card.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Save-card feature must be enabled for subscriptions.
                if ($gateway->saveCardEnabled !== 'yes') {
                    $gateway->log('Subscription checkout requires save-card feature enabled.');
                    wc_add_notice(__("Please select a valid payment type.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // For new card (no existing saved card token), require explicit save-card opt-in.
                // For existing saved card (cardToken present), do NOT require save-card toggle.
                if (!$has_selected_card && !$isSaveCardRequested) {
                    $gateway->log("Subscription checkout with new card requires save-card opt-in.");
                    wc_add_notice(__("Please Enable Save Card Toggle to Proceed with Subscription Purchase.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            }

            // Interval validation (uses strict parser).
            if (!CheckoutPayload::is_valid_subscription_interval($subscription_plan, $subscription_interval)) {
                wc_add_notice(__("Please select a valid Billing Interval.", 'supcheckout'), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            // === POST-VALIDATION METADATA ===
            $customer_unq_token = null;
            $credit_card_token = $cardToken;

            // === SERVER-SIDE SAVE-CARD REQUEST CONTRACT (Section T) ===
            // An explicit Save Card request is valid ONLY for:
            // logged-in, Save Card enabled, whitelabel, CC source, NEW card.
            if ($isSaveCardRequested) {
                if ($user_id <= 0
                    || $gateway->saveCardEnabled !== 'yes'
                    || !$whitelabled
                    || $src !== 'cc'
                    || $has_selected_card
                ) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // === EFFECTIVE SAVE CARD (Section U) ===
            // After contract validation: only new CC + explicit opt-in.
            // A validated save-card request has already rejected an existing selected card.
            $isSaveCard = $isSaveCardRequested;

            // Read phone through WC Order API (works for both Classic and Blocks).
            $billing_phone_raw = $order->get_billing_phone();
            $phone = is_scalar($billing_phone_raw) ? (string) $billing_phone_raw : '';
            // Preserve legacy normalization used by existing token flow.
            $phone = str_replace(' ', '', $phone);
            $phone = preg_replace('/[^A-Za-z0-9\-]/', '', $phone);

            // Provider mobile: separate representation for API customer.mobile.
            // Only send when explicit international format can be safely established.
            $provider_mobile = '';
            if (is_scalar($billing_phone_raw)) {
                $raw = trim((string) $billing_phone_raw);
                if (strlen($raw) > 1 && $raw[0] === '+') {
                    $candidate = preg_replace('/[\s\-\(\)]+/', '', $raw);
                    if (preg_match('/^\+[0-9]+$/', $candidate) && strlen($candidate) <= 15) {
                        $provider_mobile = $candidate;
                    }
                }
            }

            // Determine if this transaction requires a customer token.
            $requires_token = $isSaveCard || $subscription_plan !== 'one_time';
            $canonical_token = null;
            $token_kind = null;
            $token_scope = null;
            $token_generation = null;

            // Compute customer.uniqueId using legacy compatibility behavior.
            $billing_phone_raw = $order->get_billing_phone();
            $customer_unique_id = '';
            if (is_scalar($billing_phone_raw)) {
                $phone_normalized = str_replace(' ', '', (string) $billing_phone_raw);
                $phone_normalized = preg_replace('/[^A-Za-z0-9\-]/', '', $phone_normalized);
                if ($user_id > 0 && !empty($phone_normalized)) {
                    $customer_unique_id = $phone_normalized . $user_id;
                } elseif (!empty($phone_normalized)) {
                    $customer_unique_id = $phone_normalized;
                }
                if (substr($customer_unique_id, 0, 1) === '0') {
                    $customer_unique_id = '1' . substr($customer_unique_id, 1);
                }
            }

            // === PHASE A: DETERMINISTIC CHARGE PREFLIGHT (BEFORE TOKEN WORK) ===
            // Build and validate the complete deterministic base payload.
            // Nothing related to token identity may happen before all of this passes.

            // Order description.
            $order_description = 'WooCommerce order #' . $order_id;
            if (strlen($order_description) > 500) {
                $order_description = substr($order_description, 0, 500);
            }

            // Currency.
            $currency = $gateway->getCurrencyCode($order_data["currency"]);
            if (!is_string($currency)) {
                $currency = strtoupper((string) $order_data["currency"]);
            }
            if (!preg_match('/^[A-Z]{3}\\z/', $currency)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Amount: strict positive plain-decimal grammar.
            // No IEEE-754 float conversion: the decimal string itself is the source of truth.
            // Reject all-zero numerics (e.g. '0', '00', '0.0', '000.000').
            $amount_str = (string) $order_total;
            if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $amount_str)
                || strlen($amount_str) > 22
            ) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            // Positivity check via decimal-string semantics: at least one character
            // in the string (excluding the dot) must be a non-zero digit.
            $is_positive = false;
            for ($i = 0; $i < strlen($amount_str); $i++) {
                $c = $amount_str[$i];
                if ($c >= '1' && $c <= '9') {
                    $is_positive = true;
                    break;
                }
            }
            if (!$is_positive) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // === SAFE AMOUNT-TO-JSON ENCODING ===
            // The provider-bound JSON token for order.amount must be a JSON number,
            // must remain a positive plain decimal, must contain no exponent, and must
            // not represent a numerically different value due to float rounding.
            //
            // PHP's json_encode() can emit exponent notation for large floats, and any
            // IEEE 754 conversion at integer-part length > 17 loses precision. To
            // guarantee no exponent and no precision loss, we serialize the amount as
            // the validated plain decimal string and inject it into the final JSON.
            // The injected token is a JSON number (digits + optional '.') and bypasses
            // PHP's float formatting entirely.
            $amount_json_token = CheckoutPayload::build_amount_json_token($amount_str);
            if ($amount_json_token === null) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Reference.
            $reference_id = (string) $order_id;
            if ($reference_id === '' || strlen($reference_id) > 35) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Callback URLs.
            $callback_urls = array(
                'returnUrl'       => $success_url,
                'cancelUrl'       => $error_url,
                'notificationUrl' => $ipn_url,
            );
            foreach ($callback_urls as $cb_url) {
                if (strlen($cb_url) > 250) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $parsed = wp_parse_url($cb_url);
                if (!$parsed
                    || !isset($parsed['scheme'])
                    || !isset($parsed['host'])
                    || ($parsed['scheme'] !== 'http' && $parsed['scheme'] !== 'https')
                    || $parsed['host'] === ''
                ) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Customer fields.
            $customer_name = CheckoutPayload::truncate_provider_text(
                trim(($order_data["billing"]["first_name"] ?? '') . ' ' . ($order_data["billing"]["last_name"] ?? '')),
                50
            );
            $customer_data = array();
            if ($customer_name !== '') {
                $customer_data['name'] = $customer_name;
            }
            $email = isset($order_data["billing"]["email"]) && is_scalar($order_data["billing"]["email"]) ? (string) $order_data["billing"]["email"] : '';
            if ($email !== '' && strlen($email) <= 50 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $customer_data['email'] = $email;
            }
            if ($customer_unique_id !== '' && strlen($customer_unique_id) <= 50) {
                $customer_data['uniqueId'] = $customer_unique_id;
            }
            if ($provider_mobile !== '') {
                $customer_data['mobile'] = $provider_mobile;
            }

            // MultiMerchant validation.
            // FAIL CLOSED: when multiMerchant is enabled, the entire provider-bound
            // structure must validate before any token work. Invalid enabled
            // MultiMerchant configuration produces ZERO Create Token, ZERO
            // Retrieve, ZERO Charge, ZERO provenance/identity writes.
            $extraMerchantData = null;
            if ($gateway->multiMerchant === 'yes') {
                // === Read raw values; do NOT trim/sanitize. ===
                $iban = isset($gateway->ibanNumber) && is_string($gateway->ibanNumber) ? $gateway->ibanNumber : '';
                $knet_charge_raw = isset($gateway->knetCharge) && is_string($gateway->knetCharge) ? $gateway->knetCharge : '';
                $cc_charge_raw = isset($gateway->ccCharge) && is_string($gateway->ccCharge) ? $gateway->ccCharge : '';
                $knet_charge_type = isset($gateway->knetChargeType) && is_string($gateway->knetChargeType) ? $gateway->knetChargeType : '';
                $cc_charge_type = isset($gateway->ccChargeType) && is_string($gateway->ccChargeType) ? $gateway->ccChargeType : '';

                // IBAN structural validation: conservative lexical check.
                // Country code: 2 letters. Check digits: 2 digits. BBAN: 11-30 alphanumeric.
                // Provider documentation states 25 chars, but observed real-world
                // values reach 30 (e.g. Kuwait IBAN); we accept 15-34 to avoid
                // over-rejecting while still catching wholesale garbage.
                if (!MultiMerchantContract::is_valid_iban($iban)) {
                    $gateway->log('MultiMerchant: invalid IBAN format.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Canonical JSON number grammar: no exponent/sign/whitespace/comma
                // or leading-zero ambiguity. UPayments permits a main-merchant
                // commission of exactly zero, so both zero and positive canonical
                // decimals are valid at this field-specific boundary.
                if (!MultiMerchantContract::is_valid_commission_lexeme($knet_charge_raw)) {
                    $gateway->log('MultiMerchant: invalid knetCharge format.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                if (!MultiMerchantContract::is_valid_commission_lexeme($cc_charge_raw)) {
                    $gateway->log('MultiMerchant: invalid ccCharge format.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                // Reject non-canonical charge-type forms exactly.
                if (!MultiMerchantContract::is_valid_charge_type($knet_charge_type)) {
                    $gateway->log('MultiMerchant: invalid knetChargeType.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                if (!MultiMerchantContract::is_valid_charge_type($cc_charge_type)) {
                    $gateway->log('MultiMerchant: invalid ccChargeType.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $knet_charge = $knet_charge_raw;
                $cc_charge = $cc_charge_raw;

                // All checks passed: build the deterministic provider-bound structure.
                // The amount field uses a sentinel that is later replaced by the validated
                // JSON number token. No float conversion happens here.
                // Per the provider contract, the sum of extraMerchantData.amount values
                // must equal order.amount. For this plugin's single-entry implementation,
                // we assign the same validated amount token to both order.amount and
                // extraMerchantData[0].amount, and the post-injection verification
                // in inject_amount_token_into_payload_json() enforces exact equality.
                $extraMerchantData = array(
                    array(
                        'amount'         => '__UPAY_MM_AMOUNT_SENTINEL__',
                        'knetCharge'     => '__UPAY_MM_KNET_CHARGE_SENTINEL__',
                        'knetChargeType' => $knet_charge_type,
                        'ccCharge'       => '__UPAY_MM_CC_CHARGE_SENTINEL__',
                        'ccChargeType'   => $cc_charge_type,
                        'ibanNumber'     => $iban,
                    ),
                );
                $mm_amount_token = $amount_json_token;
                $mm_knet_charge_token = CheckoutPayload::build_nonnegative_json_number_token($knet_charge);
                $mm_cc_charge_token = CheckoutPayload::build_nonnegative_json_number_token($cc_charge);
                if ($mm_knet_charge_token === null || $mm_cc_charge_token === null) {
                    $gateway->log('MultiMerchant: invalid charge JSON encoding.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $mm_amount_for_injection = $mm_amount_token;
                $mm_knet_charge_for_injection = $mm_knet_charge_token;
                $mm_cc_charge_for_injection = $mm_cc_charge_token;
            } else {
                $mm_amount_for_injection = null;
                $mm_knet_charge_for_injection = null;
                $mm_cc_charge_for_injection = null;
            }

            // Build deterministic base payload (token fields are null placeholders).
            // The order.amount field uses a placeholder so we can inject the
            // validated plain decimal JSON token without float conversion.
            // extraMerchantData is a real PHP array (not a string sentinel) so it is
            // encoded naturally by wp_json_encode(); only its 'amount' value is replaced
            // by the validated amount token via the MM amount sentinel below.
            $payload = array(
                'returnUrl'       => $success_url,
                'cancelUrl'       => $error_url,
                'notificationUrl' => $ipn_url,
                'products'        => $productArrayNew,
                'order'           => array(
                    'id'          => $unique_order_id,
                    'description' => $order_description,
                    'currency'    => $currency,
                    'amount'      => '__UPAY_ORDER_AMOUNT_SENTINEL__',
                ),
                'reference'       => array(
                    'id' => $reference_id,
                ),
                'customer'        => $customer_data,
                'plugin'          => array(
                    'src' => 'woocommerce',
                ),
                'is_whitelabled'  => $whitelabled,
                'language'        => 'en',
                'isSaveCard'      => $isSaveCard,
                'tokens'          => array(
                    'creditCard'          => null,
                    'customerUniqueToken' => null,
                ),
                'device'          => array(
                    'browser'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36 OPR/93.0.0.0',
                    'browserDetails'   => array(
                        'screenWidth'                 => '1920',
                        'screenHeight'                => '1080',
                        'colorDepth'                  => '24',
                        'javaEnabled'                 => 'false',
                        'language'                    => 'en',
                        'timeZone'                    => '-180',
                        '3DSecureChallengeWindowSize' => '500_X_600',
                    ),
                ),
                'extraMerchantData' => $extraMerchantData,
            );

            if (!$product_descriptors_available) {
                unset($payload['products']);
            }

            // Whitelabel: add paymentGateway.
            if ($whitelabled) {
                // Section AS: Source string is sent verbatim — no invented length
                // ceiling. Provider documents the field but does not bound it.
                // Future invariants can be added explicitly if documentation confirms a bound.
                $payload['paymentGateway'] = array('src' => $src);
            }

            // The MM amount equality (per provider contract) is enforced after
            // sentinel injection in the raw JSON (not by trying to add sentinel
            // strings). The injection substitutes the same validated amount token
            // for both order.amount and extraMerchantData[0].amount, so the raw-JSON
            // equality check in inject_amount_token_into_payload_json() is the
            // authoritative verification.

            // Pre-token JSON dry-run: encode the deterministic payload and
            // inject the validated amount JSON tokens in place of the sentinels.
            $preflight_raw = wp_json_encode($payload);
            if (!is_string($preflight_raw) || $preflight_raw === '') {
                $gateway->log('Deterministic payload encoding failed.', 'warning');
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            // The MM amount sentinel is only present when MultiMerchant is enabled and valid.
            $mm_amount_for_injection = ($extraMerchantData !== null) ? $amount_json_token : null;
            $preflight_json = CheckoutPayload::inject_amount_token_into_payload_json(
                $preflight_raw,
                array(
                    '__UPAY_ORDER_AMOUNT_SENTINEL__' => $amount_json_token,
                    '__UPAY_MM_AMOUNT_SENTINEL__' => $mm_amount_for_injection,
                    '__UPAY_MM_KNET_CHARGE_SENTINEL__' => $mm_knet_charge_for_injection,
                    '__UPAY_MM_CC_CHARGE_SENTINEL__' => $mm_cc_charge_for_injection,
                ),
                array(
                    'product_price_sent_substring' => '__UPAY_PRODUCT_PRICE_SENTINEL_',
                    'product_price_tokens' => $product_price_tokens,
                )
            );
            if (!is_string($preflight_json) || $preflight_json === '') {
                $gateway->log('Deterministic amount injection failed.', 'warning');
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // === PHASE B: TOKEN / SELECTED-CARD IDENTITY WORK ===
            // Only after Phase A passes.

            // Clear stale token-attempt metadata before token work.
            // Preserve legacy/unscoped evidence for Phase 9I migration.
            if (!CustomerTokenIdentity::clear_stale_attempt_metadata($order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section I: Force-fresh current order metadata after cleanup.
            if (!CustomerTokenIdentity::force_refresh_order_meta($order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section K: Check for residual migration evidence on current order (cardinality helper).
            // Token-dependent operations must not proceed alongside preserved legacy/corrupt evidence.
            $token_dependent_operation = $isSaveCard || $subscription_plan !== 'one_time' || $has_selected_card;

            if ($token_dependent_operation) {
                $residual_keys = array(
                    '_upay_customer_unique_token',
                    '_upay_customer_token_kind_v1',
                    '_upay_customer_token_scope_v1',
                    '_upay_customer_token_generation_v1',
                    '_upay_credit_card_token',
                );
                $has_residual_evidence = false;
                foreach ($residual_keys as $rkey) {
                    $r_card = CustomerTokenIdentity::get_historical_meta_cardinality($order, $rkey);
                    if ($r_card['status'] === CustomerTokenIdentity::META_EXACTLY_ONE) {
                        // Empty string card token is not usable evidence.
                        if ($rkey === '_upay_credit_card_token' && (string) $r_card['value'] === '') {
                            continue;
                        }
                        $has_residual_evidence = true;
                        break;
                    }
                    if ($r_card['status'] === CustomerTokenIdentity::META_DUPLICATE_OR_INVALID) {
                        $has_residual_evidence = true;
                        break;
                    }
                }
                if ($has_residual_evidence) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Section J: Stage UPayments_Checkout_Selected AFTER cleanup/refresh/residual gate.
            if ($whitelabled) {
                $order->delete_meta_data("UPayments_Checkout_Selected");
                $order->add_meta_data("UPayments_Checkout_Selected", $src);
            }

            // CASE: Selected saved card requires membership validation.
            if ($has_selected_card) {
                if ($user_id <= 0) {
                    wc_add_notice(__('Please log in to use a saved card.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if ($gateway->saveCardEnabled !== 'yes') {
                    wc_add_notice(__('Please select a valid payment type.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if (!$whitelabled || $src !== 'cc') {
                    wc_add_notice(__('Please select a valid payment method.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Section Q: Use read-only identity scope for selected-card path.
// Section #14: SINGLE atomic context read — derive scope AND generation
// from the same secret-option snapshot. The previous implementation did
// two independent reads (scope, then generation), which produced a torn
// scope(A)+generation(B) snapshot when a credential rotated in between.
                $selected_ctx = CustomerTokenIdentity::read_existing_identity_context(
                    $gateway->apiKey,
                    $gateway->getMode()
                );
                if ($selected_ctx['state'] !== CustomerTokenIdentity::SECRET_VALID
                    || $selected_ctx['scope'] === null
                    || $selected_ctx['generation_id'] === null
                ) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $scope = $selected_ctx['scope'];
                $existing_generation = $selected_ctx['generation_id'];

                $provenance = CustomerTokenIdentity::read_provenance($user_id, $scope, $existing_generation);
                if ($provenance['state'] !== CustomerTokenIdentity::STATE_VALID) {
                    wc_add_notice(__('Please log in to use a saved card.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $canonical_token = $provenance['record']['token'];
                $token_kind = $provenance['record']['kind'];
                $token_scope = $scope;
                $token_generation = isset($provenance['record']['secret_generation_id'])
                    ? $provenance['record']['secret_generation_id']
                    : null;

                $gateway = $this->gateway;

                // Browser-facing saved-card controls carry an opaque selection
                // handle. Transitional legacy clients may still submit a provider
                // token directly. In both cases authorize from ONE fresh Retrieve:
                // handle resolution first, exact legacy membership second.
                $saved_cards_result = $gateway->getSavedCards($canonical_token);
                if (!is_array($saved_cards_result)
                    || !isset($saved_cards_result['result'])
                    || $saved_cards_result['result'] !== 'success'
                    || !isset($saved_cards_result['data'])
                    || !is_array($saved_cards_result['data'])
                ) {
                    wc_add_notice(__('Please select a valid payment method.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $resolved_card_token = SavedCardSelection::resolve_submission(
                    $credit_card_token,
                    $saved_cards_result['data'],
                    $user_id,
                    $gateway->apiKey,
                    (bool) $gateway->getMode()
                );
                if ($resolved_card_token === null) {
                    wc_add_notice(__('Please select a valid payment method.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Only the real provider token crosses the server-side persistence
                // and Charge boundaries. The browser handle never does.
                $credit_card_token = $resolved_card_token;
                $isSaveCard = false;
            }
            // CASE: Save Card or subscription requires canonical token.
            elseif ($requires_token) {
                if ($user_id <= 0) {
                    wc_add_notice(__('Please log in to save a card or purchase a subscription.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if ($gateway->saveCardEnabled !== 'yes') {
                    wc_add_notice(__('Please select a valid payment type.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $gateway = $this->gateway;
                $token_result = CustomerTokenIdentity::get_or_establish_token(
                    $user_id,
                    $gateway->apiKey,
                    $gateway->getMode(),
                    function($candidate) {
                        $params = wp_json_encode(array('customerUniqueToken' => $candidate));
                        return $this->execute_request('create-customer-unique-token', 'POST', $params);
                    }
                );

                if (!$token_result['success']) {
                    $gateway->log('Token establishment failed: ' . $token_result['reason'], 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $canonical_token = $token_result['token'];
                $token_kind = isset($token_result['kind']) ? $token_result['kind'] : null;
                $token_scope = isset($token_result['scope']) ? $token_result['scope'] : null;
                $token_generation = isset($token_result['secret_generation_id']) ? $token_result['secret_generation_id'] : null;
            }
            // CASE: Ordinary payment — no canonical token required.
            else {
                $canonical_token = null;
                $token_kind = null;
                $token_scope = null;
                $token_generation = null;
                $isSaveCard = false;
            }

            // Write current attempt snapshots.
            // Section J: Ordinary payment (null tuple) must NOT initialize token identity.
            $is_ordinary_payment = ($canonical_token === null && $token_kind === null && $token_scope === null && $token_generation === null);

            if (!$is_ordinary_payment) {
                // Section S: Use read-only authoritative expected scope/generation.
                // For freshly established tokens, these are already known from the result.
                // For selected-card tokens, they were already validated above.
                // Section #14: single atomic read of scope + generation so they
                // cannot drift relative to each other.
                $expected_ctx = CustomerTokenIdentity::read_existing_identity_context(
                    $gateway->apiKey,
                    $gateway->getMode()
                );
                if ($expected_ctx['state'] !== CustomerTokenIdentity::SECRET_VALID
                    || $expected_ctx['scope'] === null
                    || $expected_ctx['generation_id'] === null
                ) {
                    $gateway->log('Runtime token context: identity context not in SECRET_VALID state.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $expected_scope = $expected_ctx['scope'];
                $expected_generation = $expected_ctx['generation_id'];

                if (!CustomerTokenIdentity::validate_token_runtime_context(
                    $canonical_token,
                    $token_kind,
                    $token_scope,
                    $token_generation,
                    $expected_scope,
                    $expected_generation
                )) {
                    $gateway->log('Runtime token context validation failed.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Section H: Unique snapshot writes.
            if ($has_selected_card) {
                $order->delete_meta_data("_upay_credit_card_token");
                $order->add_meta_data("_upay_credit_card_token", $credit_card_token, true);
            }

            if (!$is_ordinary_payment) {
                $order->delete_meta_data("_upay_customer_unique_token");
                $order->delete_meta_data("_upay_customer_token_kind_v1");
                $order->delete_meta_data("_upay_customer_token_scope_v1");
                $order->delete_meta_data("_upay_customer_token_generation_v1");
                $order->add_meta_data("_upay_customer_unique_token", $canonical_token, true);
                $order->add_meta_data("_upay_customer_token_kind_v1", $token_kind, true);
                $order->add_meta_data("_upay_customer_token_scope_v1", $token_scope, true);
                $order->add_meta_data("_upay_customer_token_generation_v1", $token_generation, true);
            }

            $order->save_meta_data();

            // Section M: Durable persistence verification before Charge.
            if (!$is_ordinary_payment || $has_selected_card) {
                $verify_order = wc_get_order($order_id);
                if (!$verify_order) {
                    $gateway->log('Persistence verification: unable to reload order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if (!CustomerTokenIdentity::force_refresh_order_meta($verify_order)) {
                    $gateway->log('Persistence verification: force refresh failed.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $verify_keys = array();
                if (!$is_ordinary_payment) {
                    $verify_keys['_upay_customer_unique_token'] = $canonical_token;
                    $verify_keys['_upay_customer_token_kind_v1'] = $token_kind;
                    $verify_keys['_upay_customer_token_scope_v1'] = $token_scope;
                    $verify_keys['_upay_customer_token_generation_v1'] = $token_generation;
                }
                if ($has_selected_card) {
                    $verify_keys['_upay_credit_card_token'] = $credit_card_token;
                }

                foreach ($verify_keys as $vkey => $expected_value) {
                    $v_card = CustomerTokenIdentity::get_historical_meta_cardinality($verify_order, $vkey);
                    if ($v_card['status'] !== CustomerTokenIdentity::META_EXACTLY_ONE) {
                        $gateway->log('Persistence verification failed: ' . $vkey, 'warning');
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if ((string) $v_card['value'] !== (string) $expected_value) {
                        $gateway->log('Persistence verification value mismatch: ' . $vkey, 'warning');
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                }
            }

            // === PHASE D: FINAL CHARGE PAYLOAD COMPLETION ===
            // MultiMerchant structure was already authoritatively constructed during
            // the deterministic pre-token phase above. It is provider payload data
            // here, not a re-derivable input. Inject token-dependent fields into the
            // already-validated deterministic payload.
            $payload['tokens'] = array(
                'creditCard'          => $credit_card_token,
                'customerUniqueToken' => $canonical_token,
            );

            // Final JSON encode (re-encode + re-inject amount token).
            $final_raw = wp_json_encode($payload);
            if (!is_string($final_raw) || $final_raw === '') {
                $gateway->log('Final payload encoding failed.', 'warning');
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            $params = CheckoutPayload::inject_amount_token_into_payload_json(
                $final_raw,
                array(
                    '__UPAY_ORDER_AMOUNT_SENTINEL__' => $amount_json_token,
                    '__UPAY_MM_AMOUNT_SENTINEL__' => $mm_amount_for_injection,
                    '__UPAY_MM_KNET_CHARGE_SENTINEL__' => $mm_knet_charge_for_injection,
                    '__UPAY_MM_CC_CHARGE_SENTINEL__' => $mm_cc_charge_for_injection,
                ),
                array(
                    // Each product's price sentinel is indexed. Identity is preserved
                    // so the injection step can verify each per-product token.
                    'product_price_sent_substring' => '__UPAY_PRODUCT_PRICE_SENTINEL_',
                    'product_price_tokens' => $product_price_tokens,
                )
            );
            if (!is_string($params) || $params === '') {
                $gateway->log('Final amount injection failed.', 'warning');
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // === PHASE C+: FINAL IDENTITY CONTEXT GATE ===
            // For non-ordinary payments, revalidate the atomic identity context
            // one last time and require exact match against the scope+generation
            // that authorized token establishment. Any identity rotation that
            // landed between the token establishment and the Charge call must
            // fail closed rather than persist a Charge under the wrong root.
            if (!$is_ordinary_payment) {
                $final_ctx = CustomerTokenIdentity::read_existing_identity_context($gateway->apiKey, $gateway->getMode());
                if ($final_ctx['state'] !== CustomerTokenIdentity::SECRET_VALID
                    || $final_ctx['scope'] === null
                    || $final_ctx['generation_id'] === null
                ) {
                    $gateway->log('Charge: identity context invalidated before Charge.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                if (!hash_equals((string) $final_ctx['scope'], (string) $expected_scope)
                    || !hash_equals((string) $final_ctx['generation_id'], (string) $expected_generation)
                ) {
                    $gateway->log('Charge: identity context changed between token establish and Charge.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'supcheckout'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            $gateway->log(__("Create payment request prepared.", 'supcheckout'));

            // === PHASE E: CHARGE ===
            $transport = $this->execute_request('charge', 'POST', $params);

            // Section S: Strict HTTP 201 check for Charge.
            if (!is_array($transport)
                || !isset($transport['transport_ok']) || !$transport['transport_ok']
                || !isset($transport['http_status']) || (int) $transport['http_status'] !== 201
                || !isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0
                || !isset($transport['body']) || !is_scalar($transport['body'])
            ) {
                $gateway->log('UPayments charge request failed.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            $response = (string) $transport['body'];
            $gateway->log('Create payment HTTP response received.');

            // Charge response processing — hardened structural validation.
            // Use \Throwable to catch TypeError from PHP 8+ malformed structures.
            try
            {
                if (!$response){
                    $gateway->log('Charge response: empty body.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                $result = json_decode($response, true);
                $gateway->log(__("Create payment response received.", 'supcheckout'));

                // A. json_decode result MUST be array.
                if (!is_array($result)){
                    $gateway->log('Charge response: malformed JSON.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // B. Status must be boolean true/false. Reject non-boolean.
                if (!array_key_exists('status', $result) || !is_bool($result['status'])) {
                    $gateway->log('Charge response: status not boolean.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                if ($result['status'] === false) {
                    $gateway->log('Charge response: provider declared failure.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // C/D. Status=true is the only remaining boolean state.
                // Require data to be an array.
                    if (!isset($result['data']) || !is_array($result['data'])) {
                        $gateway->log('Charge response: status=true but data missing/invalid.', 'warning');
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                    }

                    // E. Determine redirect URL: prefer data.link, fallback to data.transactionData.redirect_url.
                    $redirect_url = null;

                    if (isset($result['data']['link']) && is_string($result['data']['link'])) {
                        $redirect_url = CheckoutPayload::normalize_upayments_redirect_url($result['data']['link']);
                    }

                    if ($redirect_url === null
                        && isset($result['data']['transactionData'])
                        && is_array($result['data']['transactionData'])
                        && isset($result['data']['transactionData']['redirect_url'])
                        && is_string($result['data']['transactionData']['redirect_url'])
                    ) {
                        $redirect_url = CheckoutPayload::normalize_upayments_redirect_url($result['data']['transactionData']['redirect_url']);
                    }

                    // Require a valid redirect URL.
                    if ($redirect_url === null) {
                        $gateway->log('Charge response: no valid redirect URL found.', 'warning');
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                    }

                    // Valid success path.
                    if($subscription_plan && $subscription_plan !== 'one_time') {
                        $order->delete_meta_data('_upay_subscription_plan');
                        $order->add_meta_data('_upay_subscription_plan', $subscription_plan);
                        $order->delete_meta_data('_upay_subscription_interval');
                        $order->add_meta_data('_upay_subscription_interval', $subscription_interval);
                        $order->delete_meta_data('_upay_subscription_status');
                        $order->add_meta_data('_upay_subscription_status', 'active');
                        $order->delete_meta_data('UPayments_AutoDeduction');
                        $order->add_meta_data('UPayments_AutoDeduction', 'no');
                        $order->save_meta_data();
                    }

                    $order->delete_meta_data("UPayments_order_id");
                    $order->add_meta_data("UPayments_order_id", $unique_order_id);
                    $order->save_meta_data();

                    return ["result" => "success", "redirect" => $redirect_url];

            } catch (\Throwable $e) {
                // Fail-closed: catch TypeError (PHP 8+) and Exception (PHP 7.2+).
                // Do NOT log $e->getMessage() — may contain internal/provider details.
                $gateway->log('Charge response: unexpected error during processing.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment request could not be completed. Please try again.", 'supcheckout'), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }
        }

        private static function parse_order_id($value) {
            if (is_int($value)) {
                return $value > 0 ? $value : null;
            }
            if (!is_string($value) || strlen($value) > 18 || !preg_match('/^[1-9][0-9]*\\z/', $value)) {
                return null;
            }
            $order_id = (int) $value;
            return $order_id > 0 ? $order_id : null;
        }
}
