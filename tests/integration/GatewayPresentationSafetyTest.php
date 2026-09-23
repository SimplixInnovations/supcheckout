<?php
/**
 * Real-runtime gateway presentation and accessibility certification.
 *
 * Characterizes WooCommerce checkbox semantics at the gateway/template seam
 * without executing provider transport: wc_get_template is redirected to a
 * test-only fixture that records the selected template and extracted argument.
 * Also ratchets structural accessibility invariants for both inherited Classic
 * checkout templates.
 */

require_once __DIR__ . '/bootstrap.php';

$settings_key = 'woocommerce_upayments_settings';
$settings_before_probe = get_option($settings_key);
$fixture = __DIR__ . '/fixtures/payment-fields-probe.php';

supcheckout_cert_assert(is_file($fixture), 'payment-fields probe fixture exists');

add_filter(
    'wc_get_template',
    function ($template, $template_name) use ($fixture) {
        if ($template_name === 'new-design-form.php' || $template_name === 'old-design-form.php') {
            $GLOBALS['supcheckout_payment_fields_probe']['template'] = $template_name;
            return $fixture;
        }
        return $template;
    },
    999,
    2
);

$gateway = new WC_Upayments();

$cases = array(
    'explicit-enabled' => array(
        'settings' => array('enable_save_card' => 'yes', 'use_new_design' => 'yes'),
        'template' => 'new-design-form.php',
        'save_card_enabled' => true,
    ),
    'explicit-disabled' => array(
        'settings' => array('enable_save_card' => 'no', 'use_new_design' => 'no'),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
    'missing-preserves-declared-defaults' => array(
        'settings' => array(),
        'template' => 'new-design-form.php',
        'save_card_enabled' => true,
    ),
    'malformed-boolean-true' => array(
        'settings' => array('enable_save_card' => true, 'use_new_design' => true),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
    'malformed-boolean-false' => array(
        'settings' => array('enable_save_card' => false, 'use_new_design' => false),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
    'malformed-string-one' => array(
        'settings' => array('enable_save_card' => '1', 'use_new_design' => '1'),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
    'malformed-integer-one' => array(
        'settings' => array('enable_save_card' => 1, 'use_new_design' => 1),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
    'malformed-blank' => array(
        'settings' => array('enable_save_card' => '', 'use_new_design' => ''),
        'template' => 'old-design-form.php',
        'save_card_enabled' => false,
    ),
);

foreach ($cases as $name => $case) {
    $gateway->settings = $case['settings'];
    $GLOBALS['supcheckout_payment_fields_probe'] = array();

    ob_start();
    $gateway->payment_fields();
    ob_end_clean();

    supcheckout_cert_assert(
        isset($GLOBALS['supcheckout_payment_fields_probe']['template'])
            && $GLOBALS['supcheckout_payment_fields_probe']['template'] === $case['template'],
        'payment template selection uses exact checkbox semantics for case ' . $name
    );
    supcheckout_cert_assert(
        array_key_exists('save_card_enabled', $GLOBALS['supcheckout_payment_fields_probe'])
            && $GLOBALS['supcheckout_payment_fields_probe']['save_card_enabled'] === $case['save_card_enabled'],
        'save-card template argument uses exact checkbox semantics for case ' . $name
    );
}

remove_all_filters('wc_get_template');
supcheckout_cert_store_option_raw($settings_key, $settings_before_probe);
unset($GLOBALS['supcheckout_payment_fields_probe']);

$new_template_path = UP_PLUGIN_PATH . 'templates/new-design-form.php';
$old_template_path = UP_PLUGIN_PATH . 'templates/old-design-form.php';
$new_template = is_file($new_template_path) ? file_get_contents($new_template_path) : false;
$old_template = is_file($old_template_path) ? file_get_contents($old_template_path) : false;

supcheckout_cert_assert(is_string($new_template), 'new-design checkout template is readable for accessibility certification');
supcheckout_cert_assert(is_string($old_template), 'old-design checkout template is readable for accessibility certification');

$toast_tag = '';
if (is_string($new_template)
    && preg_match('/<div\b[^>]*\bid="wc-toast"[^>]*>/', $new_template, $toast_match) === 1
) {
    $toast_tag = $toast_match[0];
}
supcheckout_cert_assert($toast_tag !== '', 'new-design toast element exists');
supcheckout_cert_assert(
    strpos($toast_tag, 'role="status"') !== false
        && strpos($toast_tag, 'aria-live="polite"') !== false
        && strpos($toast_tag, 'aria-atomic="true"') !== false,
    'new-design toast exposes one polite atomic status live region'
);

supcheckout_cert_assert(
    is_string($new_template)
        && strpos($new_template, '<label class="switch-border" for="chkSaveCard">') !== false,
    'save-card switch has an explicit text-bearing label association'
);
supcheckout_cert_assert(
    is_string($new_template)
        && strpos($new_template, '<label class="switch">') === false
        && strpos($new_template, '<span class="switch">') !== false,
    'save-card switch contains no nested label element'
);
supcheckout_cert_assert(
    is_string($old_template)
        && preg_match('/<ul\b[^>]*>\s*<p\b/s', $old_template) !== 1,
    'old-design payment list has no paragraph as a direct ul child'
);

supcheckout_cert_note('gateway presentation and accessibility certification complete');
