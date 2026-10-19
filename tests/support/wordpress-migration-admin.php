<?php

function supcheckout_test_reset_migration_admin() {
    $GLOBALS['supcheckout_test_subscription_presentation_active'] = false;
    $GLOBALS['supcheckout_test_migration_admin'] = array(
        'capability_allowed' => true,
        // Capabilities the current user lacks even while capability_allowed is true (a Shop
        // Manager: manage_woocommerce without activate_plugins).
        'denied_capabilities' => array(),
        'capability_calls' => array(),
        'submenu_calls' => array(),
        'nonce_checks' => array(),
        'nonce_valid' => true,
        'die_messages' => array(),
        'nonce_fields' => array(),
        'submit_buttons' => array(),
    );
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

function current_user_can($capability, $object_id = null) {
    if (!empty($GLOBALS['supcheckout_test_subscription_presentation_active'])) {
        $GLOBALS['supcheckout_test_subscription_presentation']['capability_calls'][] = array($capability, $object_id);
        return $GLOBALS['supcheckout_test_subscription_presentation']['capability_allowed'] === true;
    }
    $GLOBALS['supcheckout_test_migration_admin']['capability_calls'][] = $capability;
    $denied = isset($GLOBALS['supcheckout_test_migration_admin']['denied_capabilities']) ? $GLOBALS['supcheckout_test_migration_admin']['denied_capabilities'] : array();
    if (in_array($capability, $denied, true)) {
        return false;
    }
    return $GLOBALS['supcheckout_test_migration_admin']['capability_allowed'] === true;
}

function wp_die($message) {
    $message = (string) $message;
    $GLOBALS['supcheckout_test_migration_admin']['die_messages'][] = $message;
    throw new RuntimeException($message);
}

function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback) {
    $GLOBALS['supcheckout_test_migration_admin']['submenu_calls'][] = array(
        $parent_slug,
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        $callback,
    );
    return $parent_slug . '_page_' . $menu_slug;
}

function check_admin_referer($action, $field) {
    $GLOBALS['supcheckout_test_migration_admin']['nonce_checks'][] = array($action, $field);
    if ($GLOBALS['supcheckout_test_migration_admin']['nonce_valid'] !== true) {
        throw new RuntimeException('nonce_invalid');
    }
    return 1;
}

function sanitize_key($value) {
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}

function esc_html__($text, $domain = 'default') {
    return esc_html(__($text, $domain));
}

function esc_textarea($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function checked($checked, $current = true, $echo = true) {
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) {
        echo $result;
    }
    return $result;
}

function wp_nonce_field($action, $name, $referer = true) {
    if (!empty($GLOBALS['supcheckout_test_subscription_presentation_active'])) {
        $GLOBALS['supcheckout_test_subscription_presentation']['nonce_fields'][] = array($action, $name, $referer);
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
        return;
    }
    $GLOBALS['supcheckout_test_migration_admin']['nonce_fields'][] = array($action, $name);
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
}

function submit_button($text) {
    $GLOBALS['supcheckout_test_migration_admin']['submit_buttons'][] = $text;
    echo '<button type="submit">' . esc_html($text) . '</button>';
}

supcheckout_test_reset_migration_admin();
