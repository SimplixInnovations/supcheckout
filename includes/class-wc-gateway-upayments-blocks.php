<?php
// Use the necessary Blocks Interfaces
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Simplixi\SUPCheckout\Admin\GatewaySettings;

/**
 * UPayments Blocks Integration Class
 *
 * This class handles the integration of the UPayments gateway with the 
 * WooCommerce Block Checkout (including data and assets registration).
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Protected legacy WooCommerce Blocks integration identity retained for compatibility.
class WCGatewayUPaymentsBlocks extends AbstractPaymentMethodType {

    protected $name = 'upayments';
    protected $gateway;
    protected $pluginFile;

    public function __construct( $pluginFile ) {
        $this->pluginFile = $pluginFile;
    }

    public function get_name() {
        return 'upayments';
    }

    public function initialize() {
        $this->settings = get_option( 'woocommerce_upayments_settings', [] );
        if ( class_exists( 'WC_Upayments' ) ) {
            $this->gateway = new WC_Upayments();
        } elseif ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->warning(
                'UPayments gateway class not found during Blocks initialization.',
                array( 'source' => 'upayments' )
            );
        }
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
                'wc-blocks-data-store',
            ],
            \Simplixi\SUPCheckout\Release\Identity::VERSION,
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
            $availability = $this->gateway->getPaymentIcons();
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
                    $ctx = \UPayments\Token\CustomerTokenIdentity::read_existing_identity_context(
                        $api_key,
                        $is_test_mode
                    );
                    if ( is_array( $ctx )
                        && isset( $ctx['state'] ) && $ctx['state'] === 'valid'
                        && is_string( $ctx['scope'] ) && $ctx['scope'] !== ''
                        && is_string( $ctx['generation_id'] ) && $ctx['generation_id'] !== ''
                    ) {
                        $provenance = \UPayments\Token\CustomerTokenIdentity::read_provenance(
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

            if ( $can_retrieve_saved_cards ) {
                $savedCards = $this->gateway->getSavedCardsForCurrentUser( $availability );
                if (is_array($savedCards)
                    && isset($savedCards['result'])
                    && $savedCards['result'] === 'success'
                    && isset($savedCards['data'])
                    && is_array($savedCards['data'])
                ) {
                    // Saved-card presentation is normalized server-side so
                    // provider number/last4 source fields never enter Blocks settings.
                    $sanitized = array();
                    foreach ($savedCards['data'] as $card) {
                        if (!is_array($card)) {
                            continue;
                        }
                        $provider_token = \Simplixi\SUPCheckout\Payment\SavedCardPresentation::token($card);
                        if ($provider_token === null) {
                            continue;
                        }
                        $selection = \Simplixi\SUPCheckout\Payment\SavedCardSelection::create(
                            $provider_token,
                            (int) $user_id,
                            $api_key,
                            $is_test_mode
                        );
                        if ($selection === null) {
                            continue;
                        }
                        $presented = \Simplixi\SUPCheckout\Payment\SavedCardPresentation::for_blocks(
                            $card,
                            __('Saved card', 'supcheckout'),
                            $selection
                        );
                        if ($presented === null) {
                            continue;
                        }
                        $sanitized[] = $presented;
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
            'plugin_url'                => plugin_dir_url( dirname( __FILE__ ) ),
            'translation'               => [
                'save_card_label'       => __('For faster and more secure checkout. Save your card details.', 'supcheckout'),
                'saved_cards_label'     => __('Saved Cards', 'supcheckout'),
                'saved_card_fallback'   => __('Saved card', 'supcheckout'),
                'other_options_label'   => __('Other Options', 'supcheckout'),
            ],
            'supports'    => [ 'products' ],
        ];
    }
}
