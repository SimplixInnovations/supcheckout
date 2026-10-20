<?php

$GLOBALS['supcheckout_test_gateway_settings'] = array();

function supcheckout_test_reset_gateway_settings() {
    $GLOBALS['supcheckout_test_gateway_settings'] = array(
        'styles'        => array(),
        'scripts'       => array(),
        'inline_styles' => array(),
    );
}

function __($text, $domain = 'default') {
    return (string) $text;
}

function sanitize_text_field($value) {
    return trim(strip_tags((string) $value));
}

function wc_clean($value) {
    return sanitize_text_field($value);
}

function esc_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_attr($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post($value) {
    return strip_tags((string) $value, '<a><abbr><b><br><code><em><i><strong>');
}

function sanitize_title($value) {
    $value = strtolower(trim((string) $value));
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
}

function esc_html_e($text, $domain = 'default') {
    echo esc_html($text);
}

function selected($selected, $current = true, $display = true) {
    $result = (string) $selected === (string) $current ? ' selected="selected"' : '';
    if ($display) {
        echo $result;
    }
    return $result;
}

function wp_enqueue_style($handle, $source = '', $dependencies = array(), $version = false, $media = 'all') {
    $GLOBALS['supcheckout_test_gateway_settings']['styles'][] = array(
        'handle'       => $handle,
        'source'       => $source,
        'dependencies' => $dependencies,
        'version'      => $version,
        'media'        => $media,
    );
}

function wp_enqueue_script($handle, $source = '', $dependencies = array(), $version = false, $in_footer = false) {
    $GLOBALS['supcheckout_test_gateway_settings']['scripts'][] = array(
        'handle'       => $handle,
        'source'       => $source,
        'dependencies' => $dependencies,
        'version'      => $version,
        'in_footer'    => $in_footer,
    );
}

function wp_add_inline_style($handle, $css) {
    $GLOBALS['supcheckout_test_gateway_settings']['inline_styles'][] = array(
        'handle' => $handle,
        'css'    => $css,
    );
    return true;
}

supcheckout_test_reset_gateway_settings();
