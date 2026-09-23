<?php
/**
 * Payment form template for the New Design (V2.1.5/V2.2.1).
 *
 * This file is included in WC_Upayments::payment_fields().
 *
 * @var WC_Upayments $gateway           The gateway instance (now renamed to $this in the original context).
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce template locals are intentionally scoped to the template include.
?>
<div id="wc-toast" class="wc-toast" role="status" aria-live="polite" aria-atomic="true"></div>
<div class="supcheckout form-row form-row-wide">
    <?php
        $icons = array();
        $total = WC()->cart->get_total('');
        $language = get_locale();
        $currency = get_woocommerce_currency_symbol();
        if (strpos($language, 'en') === 0) {
            $currency = get_woocommerce_currency();
        }
        $whitelabled = false;
        $payment_data = $gateway->getPaymentIcons();
        $payment_data_valid = (
            is_array($payment_data)
            && isset($payment_data['payment'])
            && is_array($payment_data['payment'])
            && array_key_exists('whitelabled', $payment_data)
            && is_bool($payment_data['whitelabled'])
        );
        if ($payment_data_valid) {
            $gateway->paymentData = $payment_data;
            $icons = $payment_data['payment'];
            $whitelabled = $payment_data['whitelabled'];
        }
        $isSubscriptionEnabled = $gateway->get_option('enable_subscriptions') === 'yes' ? true : false;
        if ($payment_data_valid && $whitelabled) {
    ?>
        <div class="payment-buttons">
            <?php
            $user_id = get_current_user_id();
            $is_logged_in = $user_id > 0;
            // Classic Retrieve gating:
            //   - valid normalized availability (already validated above)
            //   - Whitelabel exact true
            //   - CC explicitly enabled
            //   - logged in
            //   - Save Card feature enabled
            //   - existing read-only identity secret/scope/generation
            //   - valid current provenance
            // Otherwise ZERO Retrieve call. The gateway helper now requires the
            // exact already-normalized state and refuses null defaults.
            $can_retrieve_saved_cards_classic = false;
            if ($is_logged_in && $save_card_enabled && $payment_data_valid) {
                $cc_enabled_classic = (
                    isset($payment_data['payment'])
                    && is_array($payment_data['payment'])
                    && array_key_exists('cc', $payment_data['payment'])
                    && is_scalar($payment_data['payment']['cc'])
                    && (string) $payment_data['payment']['cc'] !== ''
                );
                if ($whitelabled === true && $cc_enabled_classic) {
                    // Residual Correction #15: single atomic read of the canonical
                    // identity context (api_key + is_test_mode), then pass the
                    // captured generation into read_provenance(). The previous
                    // implementation observed scope and generation via two
                    // separate reads of the secret option, which enabled torn
                    // scope(A)+generation(B) snapshots.
                    $api_key_classic = isset($gateway->apiKey) && is_string($gateway->apiKey) ? $gateway->apiKey : '';
                    $is_test_mode_classic = (bool) $gateway->getMode();
                    $ctx_classic = \UPayments\Token\CustomerTokenIdentity::read_existing_identity_context(
                        $api_key_classic,
                        $is_test_mode_classic
                    );
                    if (is_array($ctx_classic)
                        && isset($ctx_classic['state']) && $ctx_classic['state'] === 'valid'
                        && is_string($ctx_classic['scope']) && $ctx_classic['scope'] !== ''
                        && is_string($ctx_classic['generation_id']) && $ctx_classic['generation_id'] !== ''
                    ) {
                        $provenance_classic = \UPayments\Token\CustomerTokenIdentity::read_provenance(
                            $user_id,
                            $ctx_classic['scope'],
                            $ctx_classic['generation_id']
                        );
                        if (is_array($provenance_classic) && isset($provenance_classic['state']) && $provenance_classic['state'] === 'valid') {
                            $can_retrieve_saved_cards_classic = true;
                        }
                    }
                }
            }
            if ($can_retrieve_saved_cards_classic) {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
                <?php
                $savedCards = $gateway->getSavedCardsForCurrentUser($payment_data);

                if (is_array($savedCards) && isset($savedCards['result']) && $savedCards['result'] === 'success' && isset($savedCards['data']) && is_array($savedCards['data']))
                {
                    $cardList = $savedCards['data'];
                ?>
                    <span class="payment-method-label"><?php esc_html_e('Saved Cards', 'supcheckout'); ?></span>
                    <?php
                    foreach ($cardList as $cardkey => $cardValue) {
                        if (!is_array($cardValue)) {
                            continue;
                        }
                        $provider_card_token = \Simplixi\SUPCheckout\Payment\SavedCardPresentation::token($cardValue);
                        if ($provider_card_token === null) {
                            continue;
                        }
                        $card_selection = \Simplixi\SUPCheckout\Payment\SavedCardSelection::create(
                            $provider_card_token,
                            (int) $user_id,
                            $api_key_classic,
                            $is_test_mode_classic
                        );
                        if ($card_selection === null) {
                            continue;
                        }
                        $card_display_label = \Simplixi\SUPCheckout\Payment\SavedCardPresentation::label($cardValue, __('Saved card', 'supcheckout'));
                    ?>

                        <button type="button" value="<?php echo esc_attr($card_selection); ?>" onclick="if(window.supCheckout&amp;&amp;typeof window.supCheckout.submitSavedCard==='function'){window.supCheckout.submitSavedCard(this);}else{window.supcheckoutPendingAction={type:'saved_card',value:this.value};}" class="upay-payment-method">
                        <span class="payment-method-icon"><img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt=""/></span>
                        <span class="payment-method-label"><?php echo esc_html($card_display_label); ?></span>
                        <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
                        <span class="payment-method-icon2"><span class="upay-chevron" aria-hidden="true">&#8250;</span></span>
                        </button>

                    <?php
                    }
                    ?>
                    <span class="payment-method-label"><?php esc_html_e('Other Options', 'supcheckout'); ?></span>
                <?php
                }
            } else {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
            <?php
            }
                foreach ($icons as $key => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $key_string = (string) $key;
                    $value_string = (string) $value;
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
                    $value_text = esc_html($value_string);
                    $key_js = wp_json_encode($key_string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $onclick = 'if(window.supCheckout&&typeof window.supCheckout.submitPaymentMethod==="function"){window.supCheckout.submitPaymentMethod(' . $key_js . ');}else{window.supcheckoutPendingAction={type:"payment_method",value:' . $key_js . '};}';
            ?>
                <button type="button" onclick="<?php echo esc_attr($onclick); ?>" class="upay-payment-method" id="upay-button-<?php echo esc_attr($key_string); ?>">
                    <span class="payment-method-icon">
                        <?php
                            if ($key_string == 'apple-pay-knet') {
                                ?>
                                <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/knet.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/>
                                <?php
                            } elseif ($key_string == 'apple-pay') {
                                ?>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/>
                                <?php
                            } else {
                                ?>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/>
                                <?php
                            }
                        ?>
                    </span>
                    <span class="payment-method-label"><?php echo esc_html($value_string); ?></span>
                    <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
                    <span class="payment-method-icon2"><span class="upay-chevron" aria-hidden="true">&#8250;</span></span>
                </button>
            
            <?php if ($key_string == 'cc' && $save_card_enabled && $is_logged_in) { ?>
                <label class="switch-border" for="chkSaveCard">For faster and more secure checkout. Save your card details.
                    <span class="switch">
                        <?php
                            $checked = false;
                        ?>
                        <input
                            type="checkbox"
                            id="chkSaveCard"
                            onclick="if(window.supCheckout&amp;&amp;typeof window.supCheckout.toggleSaveCard==='function'){window.supCheckout.toggleSaveCard(true);}else{window.supcheckoutPendingAction={type:'toggle_save_card',value:this.checked,loggedUser:true};}"
                        >
                        <span class="slider round"></span>
                    </span>
                </label>
            <?php
                    }
                }
            ?>
        </div>
    <?php
        } elseif ($payment_data_valid && !$whitelabled) {
    ?>
        <div class="payment-buttons">
            <button type="button" onclick="if(window.supCheckout&amp;&amp;typeof window.supCheckout.submitPaymentMethod==='function'){window.supCheckout.submitPaymentMethod('knet');}else{window.supcheckoutPendingAction={type:'payment_method',value:'knet'};}" class="upay-payment-method">
    <?php
            foreach ($icons as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $key_string = (string) $key;
                $value_string = (string) $value;
                if ($key_string != 'apple-pay-knet') {
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
    ?>
                <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo esc_attr($key_string); ?>"><img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png'); ?>" alt="<?php echo esc_attr($value_string); ?>"  title="<?php echo esc_attr($value_string); ?>"/></span>
    <?php
                }
            }
    ?>
            <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
            <span class="payment-method-icon2"><span class="upay-chevron" aria-hidden="true">&#8250;</span></span>
            </button>
        </div>
    <?php
        }
        // If $payment_data_valid is false, render NO payment buttons.
    ?>
        <input id="upayment_payment_type" type="hidden" name="upayment_payment_type" value="upayments"/>
        <input id="card_token" type="hidden" name="card_token" value=""/>
</div>