<?php
/**
 * Payment form template for the Old Design (V2.0.8 Look).
 *
 * This file is included in WC_Upayments::payment_fields().
 *
 * @var WC_Upayments $gateway           The gateway instance.
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled (unused in this design).
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce template locals are intentionally scoped to the template include.
?>
<div class="supcheckout form-row form-row-wide">
    <?php 
    echo wp_kses_post($gateway->description);
    $icons = array();
    $whitelabled = false;
    
    if ($gateway->paymentData == null) {
        $payment_data = $gateway->getPaymentIcons();
    } else {
        $payment_data = $gateway->paymentData;
    }
    
    $payment_data_valid = (
        is_array($payment_data)
        && isset($payment_data['payment'])
        && is_array($payment_data['payment'])
        && array_key_exists('whitelabled', $payment_data)
        && is_bool($payment_data['whitelabled'])
    );
    if ($payment_data_valid) {
        $icons = $payment_data['payment'];
        $whitelabled = $payment_data['whitelabled'];
    }
    
    if ($payment_data_valid && $whitelabled)
    {
    ?>
        <p style="display: inline"><?php esc_html_e('Select Payment Type:', 'supcheckout'); ?></p>
        <ul style="list-style: none outside;">
            <?php 
            foreach ($icons as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $key_string = (string) $key;
                $value_string = (string) $value;
                if ($key_string != "both") {
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
                    $value_text = esc_html($value_string);
                    if ($key_string == 'apple-pay') {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />
                        <img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/cc.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    } elseif ($key_string == 'apple-pay-knet') {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />
                        <img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/knet.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    } else {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    }
                        
            ?>
                <li>
                    <span class="<?php echo esc_attr($key_string); ?>-upayments-button">
                    <input id="upayment_payment_type_<?php echo esc_attr($key_string); ?>" type="radio" class="input-radio"
                            name="upayment_payment_type" value="<?php echo esc_attr($key_string); ?>"/>
                    <label for="upayment_payment_type_<?php echo esc_attr($key_string); ?>"
                            style='display: inline-block; font-family: -apple-system,blinkmacsystemfont,"Helvetica Neue",helvetica,sans-serif;'>
                        <span class="upayment_payment_type_label_text"><?php echo esc_html($value_string); ?></span>
                        <span class="upayment_payment_type_label_logo"><?php echo wp_kses_post($icon); ?></span>
                    </label>
                    </span>
                </li>
            <?php
                }
            } 
            ?>
        </ul>
    <?php
    }
    ?>
</div>
