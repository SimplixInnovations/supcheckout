<?php

namespace Simplixi\SUPCheckout\Blocks;

defined('ABSPATH') || exit;

// Use the necessary Blocks Interfaces
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Simplixi\SUPCheckout\Admin\GatewaySettings;
use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * UPayments Blocks Integration Class
 *
 * This class handles the integration of the UPayments gateway with the 
 * WooCommerce Block Checkout (including data and assets registration).
 */
class PaymentMethodType extends AbstractPaymentMethodType {

    protected $name = 'upayments';
    protected $gateway;
    protected $pluginFile;

    public function __construct( $pluginFile ) {
        $this->pluginFile = $pluginFile;
        // The Blocks / Store API identity is the gateway id: 'upayments' until the takeover
        // cutover, 'supcheckout' after (0b Task 7).
        $this->name = Keys::gateway_id();
    }

    public function get_name() {
        return $this->name;
    }

    public function initialize() {
        $this->settings = Keys::get_settings( [] );
        // Reuse WooCommerce's registered gateway. The gateway registers its hooks in its
        // constructor, so a second instance would render the admin payment box, the
        // thank-you section and the order-totals rows twice.
        // Without a registered gateway, Blocks does not offer the method at all
        // (is_active() is false); it never builds an unregistered second instance.
        $registered = self::registered_gateway();
        if ( null !== $registered ) {
            $this->gateway = $registered;
        } elseif ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'UPayments gateway is not registered with WooCommerce during Blocks initialization.',
                array( 'source' => 'upayments' )
            );
        }
    }

    /**
     * The gateway instance WooCommerce already registered, or null before it exists.
     *
     * @return \Simplixi\SUPCheckout\Gateway\Gateway|null
     */
    private static function registered_gateway() {
        if ( ! function_exists( 'WC' ) || ! class_exists( \Simplixi\SUPCheckout\Gateway\Gateway::class ) ) {
            return null;
        }
        $woocommerce = WC();
        if ( ! is_object( $woocommerce ) || ! method_exists( $woocommerce, 'payment_gateways' ) ) {
            return null;
        }
        $registry = $woocommerce->payment_gateways();
        if ( ! is_object( $registry ) || ! method_exists( $registry, 'payment_gateways' ) ) {
            return null;
        }
        $gateway = Keys::find_gateway( $registry->payment_gateways() );
        return $gateway instanceof \Simplixi\SUPCheckout\Gateway\Gateway ? $gateway : null;
    }

    public function is_active() {
        /** @var mixed $settings Runtime option storage may be malformed despite the upstream PHPDoc. */
        $settings = $this->settings;

        return is_object($this->gateway)
            && GatewaySettings::is_runtime_eligible($settings, get_woocommerce_currency());
    }

    public function get_supported_features() {
        return [ 'products' ];
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'supcheckout-block-checkout',
            plugins_url( 'assets/js/upayments-block.js', $this->pluginFile ),
            [
                // 'wc-blocks-checkout-blocks',
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-data',
                'wp-i18n',
                'wp-html-entities',
                'wc-blocks-data-store',
            ],
            \Simplixi\SUPCheckout\Gateway\CheckoutAssets::asset_version('assets/js/upayments-block.js'),
            true
        );
        return [ 'supcheckout-block-checkout' ];
    }

    public function get_payment_method_script_handles_for_admin() {
        return $this->get_payment_method_script_handles();
    }

    /**
     * Returns an array of key/value pairs that are passed to the JS frontend.
     *
     * @return array
     */
    public function get_payment_method_data() {
        // Basic settings and flags
        $whitelabled = false;
        $icons = [];
        $saved_cards = [];
        $is_logged_in = false;
        $user_id = null;
        $product_details = null;
        $availability_valid = false;

        // Safety check: ensure the gateway instance exists before calling methods
        $save_card_enabled = $this->gateway ? ($this->gateway->get_option('enable_save_card') === 'yes') : false;

        // Check if Subscription is Enabled or not
        $is_subscription_enabled = $this->gateway ? ($this->gateway->get_option('enable_subscriptions') === 'yes') : false;

        // 1. Get payment icons and whitelabeled status — produce ONE canonical availability state.
        $availability = null;
        if ( $this->gateway ) {
            // Blocks payment data is display-only: availability may come from the recent
            // ten-minute display copy (A3-02); the saved-card listing waits at most 5 s.
            $gateway = $this->gateway;
            $availability = $gateway->read_for_display( function () use ( $gateway ) {
                return $gateway->getPaymentIcons();
            } );
            if ( is_array( $availability )
                && isset( $availability['payment'] )
                && is_array( $availability['payment'] )
                && array_key_exists( 'whitelabled', $availability )
                && is_bool( $availability['whitelabled'] )
            ) {
                $icons = $availability['payment'];
                $whitelabled = $availability['whitelabled'];
                $availability_valid = !$whitelabled || !empty( $icons );
            }

            // 2. Saved-card retrieval gated on a single normalized availability state.
            $user_id = get_current_user_id();
            $is_logged_in = $user_id > 0;
            $api_key = '';
            $is_test_mode = false;

            // Normalize availability once, validate once, then pass the EXACT state
            // into the saved-card helper. Retrieve is allowed only when ALL are true:
            //   - valid normalized availability (array)
            //   - Whitelabel === true (boolean)
            //   - CC explicitly enabled
            //   - logged in
            //   - Save Card feature enabled
            //   - existing read-only identity secret/scope/generation
            //   - valid current provenance
            // Otherwise ZERO Retrieve call.
            $can_retrieve_saved_cards = false;
            if ( $is_logged_in && $save_card_enabled && is_array( $availability ) ) {
                $whitelabel_ok = ( isset( $availability['whitelabled'] ) && $availability['whitelabled'] === true );
                $cc_enabled = (
                    isset( $availability['payment'] )
                    && is_array( $availability['payment'] )
                    && array_key_exists( 'cc', $availability['payment'] )
                    && is_scalar( $availability['payment']['cc'] )
                    && (string) $availability['payment']['cc'] !== ''
                );
                if ( $whitelabel_ok && $cc_enabled ) {
                    // Residual Correction #15: single atomic read of the canonical
                    // identity context (api_key + is_test_mode), then pass the
                    // captured generation into read_provenance(). The previous
                    // implementation observed scope and generation via two
                    // separate reads of the secret option, which enabled torn
                    // scope(A)+generation(B) snapshots.
                    $api_key = is_string( $this->gateway->apiKey ) ? $this->gateway->apiKey : '';
                    $is_test_mode = (bool) $this->gateway->getMode();
                    $ctx = \Simplixi\SUPCheckout\Token\CustomerTokenIdentity::read_existing_identity_context(
                        $api_key,
                        $is_test_mode
                    );
                    if ( is_array( $ctx )
                        && isset( $ctx['state'] ) && $ctx['state'] === 'valid'
                        && is_string( $ctx['scope'] ) && $ctx['scope'] !== ''
                        && is_string( $ctx['generation_id'] ) && $ctx['generation_id'] !== ''
                    ) {
                        $provenance = \Simplixi\SUPCheckout\Token\CustomerTokenIdentity::read_provenance(
                            $user_id,
                            $ctx['scope'],
                            $ctx['generation_id']
                        );
                        if ( is_array( $provenance ) && isset( $provenance['state'] ) && $provenance['state'] === 'valid' ) {
                            $can_retrieve_saved_cards = true;
                        }
                    }
                }
            }

            // The Cart block builds this data too but never shows saved cards; skip the
            // blocking provider retrieval anywhere but the checkout page.
            if ( $can_retrieve_saved_cards && function_exists( 'is_checkout' ) && ! is_checkout() ) {
                $can_retrieve_saved_cards = false;
            }

            if ( $can_retrieve_saved_cards ) {
                // Display-safe views only (opaque handle, last four, brand), reused for
                // two minutes across checkout loads (A3-01). Provider number/last4 source
                // fields and provider tokens never enter Blocks settings.
                $views = $this->gateway->getSavedCardViewsForCurrentUser( $availability );
                if ( is_array( $views ) ) {
                    $sanitized = array();
                    foreach ( $views as $view ) {
                        if ( ! is_array( $view )
                            || ! isset( $view['selection'], $view['last4'], $view['brand'] )
                            || ! is_string( $view['selection'] )
                            || ! is_string( $view['last4'] )
                            || ! is_string( $view['brand'] )
                        ) {
                            continue;
                        }
                        $sanitized[] = array(
                            'selection' => $view['selection'],
                            'label'     => \Simplixi\SUPCheckout\Payment\SavedCardPresentation::label(
                                array( 'last4' => $view['last4'] ),
                                __( 'Saved card', 'supcheckout' )
                            ),
                            'brand'     => $view['brand'],
                        );
                    }
                    $saved_cards = $sanitized;
                }
            }

        }
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $product_details[] = [
                    'type' => $product->get_type(), 
                ];
            }
        }

        // 3. Return all data to the block. WooCommerce owns live cart totals;
        // SUPCheckout passes only gateway-specific state and a compatibility
        // fallback product-type snapshot for environments without live props.
        return [
            // The merchant's title and description, through the woocommerce_gateway_title /
            // woocommerce_gateway_description filters where WPML/WCML translate them, as
            // plain text: the client decodes entities and renders text nodes only.
            'title'                      => $this->gateway ? wp_strip_all_tags( (string) $this->gateway->get_title() ) : '',
            'description'                => $this->gateway ? wp_strip_all_tags( (string) $this->gateway->get_description() ) : '',
            'availability_valid'         => $availability_valid,
            'supported_currencies'       => GatewaySettings::supported_currencies(),
            'is_whitelabled'            => $whitelabled,
            'payment_icons'             => $icons,
            'saved_cards'               => $saved_cards,
            'is_logged_in'              => $is_logged_in,
            'save_card_enabled'         => $save_card_enabled,
            'save_card_toggle_on'       => false,
            'is_subscription_enabled'   => $is_subscription_enabled,
            'product_type'              => $product_details,
            'upay_subscription_plan'     => 'one_time', // Default value
            'upay_subscription_interval' => '0',        // Default value
            'plugin_url'                => plugin_dir_url( dirname( __FILE__, 2 ) ),
            'translation'               => [
                'purchase_type_label'   => __('Purchase Type', 'supcheckout'),
                'billing_interval_label' => __('Billing Interval', 'supcheckout'),
                'one_time'              => __('One-time', 'supcheckout'),
                'plan_labels'           => [
                    'daily'     => __('Daily Subscription', 'supcheckout'),
                    'weekly'    => __('Weekly Subscription', 'supcheckout'),
                    'monthly'   => __('Monthly Subscription', 'supcheckout'),
                    'quarterly' => __('Quarterly Subscription', 'supcheckout'),
                    'yearly'    => __('Yearly Subscription', 'supcheckout'),
                ],
                'select_interval'        => __('Select interval', 'supcheckout'),
                'interval_labels'        => [
                    'daily' => [
                        '1' => __('Every Day', 'supcheckout'),
                    ],
                    'weekly' => [
                        '1' => __('Every Week', 'supcheckout'),
                        '2' => __('Every 2 Weeks', 'supcheckout'),
                        '3' => __('Every 3 Weeks', 'supcheckout'),
                    ],
                    'monthly' => [
                        '1' => __('Every Month', 'supcheckout'),
                        '2' => __('Every 2 Months', 'supcheckout'),
                    ],
                    'quarterly' => [
                        '1' => __('Every Quarter', 'supcheckout'),
                        '2' => __('Every 2 Quarters', 'supcheckout'),
                        '3' => __('Every 3 Quarters', 'supcheckout'),
                    ],
                    'yearly' => [
                        '1' => __('Every Year', 'supcheckout'),
                    ],
                ],
                'saved_card_selected'   => __('Saved card selected', 'supcheckout'),
                'save_card_label'       => __('For faster and more secure checkout. Save your card details.', 'supcheckout'),
                'saved_cards_label'     => __('Saved Cards', 'supcheckout'),
                'saved_card_fallback'   => __('Saved card', 'supcheckout'),
                'choose_payment_method' => __('Choose Payment Method', 'supcheckout'),
                'other_options_label'   => __('Other Options', 'supcheckout'),
            ],
            'supports'    => [ 'products' ],
        ] + self::takeover_identity();
    }

    /**
     * After cutover the client registers under the current gateway id and posts its
     * checkout data under the current Store API extensions namespace; both are localized
     * here. Before cutover nothing is added and the client keeps its `upayments` defaults.
     *
     * @return array<string, string>
     */
    private static function takeover_identity() {
        if ( Keys::gateway_id() === Keys::GATEWAY_LEGACY ) {
            return [];
        }
        return [
            'payment_method_name' => Keys::gateway_id(),
            'extension_namespace' => Keys::extension_namespace(),
        ];
    }
}
