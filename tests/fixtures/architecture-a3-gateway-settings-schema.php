<?php

/*
 * Frozen characterization of the inherited WooCommerce gateway field schema.
 *
 * Keep this independent from GatewaySettings::fields(): every nested key and
 * value is intentional so the A3 architecture gate detects presentation or
 * behavior drift, not merely changes to field order and selected defaults.
 */
return array(
    'enabled' => array(
        'title' => 'Active',
        'type' => 'checkbox',
        'label' => ' ',
        'default' => 'yes',
    ),
    'make_default_gateway' => array(
        'title' => 'Default Gateway',
        'type' => 'checkbox',
        'label' => 'Make UPayments the default payment method at checkout',
        'default' => 'no',
        'description' => 'If enabled, UPayments will be preselected at checkout. Merchants can still reorder gateways.',
    ),
    'title' => array(
        'title' => 'Title',
        'type' => 'text',
        'description' => 'This controls the title which the user sees during checkout.',
        'default' => 'UPayments',
        'desc_tip' => true,
    ),
    'description' => array(
        'title' => 'Description',
        'type' => 'textarea',
        'description' => 'Instructions that the customer will see on your checkout.',
        // U6 (A4-07): deliberately changed from the passed-in method description, which is
        // merchant-facing admin copy, to a short customer-facing default.
        'default' => 'Choose your payment method and complete your payment on the secure UPayments page.',
        'desc_tip' => true,
    ),
    'api_key' => array(
        'title' => 'Api Key',
        'type' => 'password',
        'description' => 'Copy/paste values from UPayments dashboard',
        'default' => '',
        'desc_tip' => true,
    ),
    'debug' => array(
        'title' => 'Debug logging',
        'type' => 'checkbox',
        'label' => 'Log non-sensitive UPayments diagnostic events to WooCommerce logs.',
        'default' => 'no',
    ),
    'test_mode' => array(
        'title' => 'Test Mode',
        'type' => 'checkbox',
        'label' => ' ',
        'default' => 'no',
    ),
    'is_order_complete' => array(
        'title' => 'Show paid orders as "Completed"?',
        'type' => 'checkbox',
        'label' => ' ',
        'default' => 'yes',
    ),
    'save_card_section_title' => array(
        'title' => 'Card Tokenization & Design',
        'type' => 'title',
        'description' => '',
    ),
    'use_new_design' => array(
        'title' => 'Use New Design',
        'type' => 'checkbox',
        'label' => 'Use the modern design (if unchecked uses classic design)',
        'default' => 'yes',
    ),
    'enable_save_card' => array(
        'title' => 'Enable Save Card',
        'type' => 'checkbox',
        'label' => 'Allow customers to save card details (Tokenization)',
        'default' => 'yes',
    ),
    'multimerchant_section_title' => array(
        'title' => 'Multimerchant Configuration',
        'type' => 'title',
    ),
    'enable_multimerchant' => array(
        'title' => 'Enable Multimerchant',
        'type' => 'checkbox',
        'label' => 'Handle Merchant Account & Charges',
        'default' => 'no',
    ),
    'iban_number' => array(
        'type' => 'text',
        'css' => 'display:none;',
    ),
    'cc_charge' => array(
        'type' => 'text',
        'css' => 'display:none;',
    ),
    'cc_charge_type' => array(
        'type' => 'text',
        'css' => 'display:none;',
    ),
    'knet_charge' => array(
        'type' => 'text',
        'css' => 'display:none;',
    ),
    'knet_charge_type' => array(
        'type' => 'text',
        'css' => 'display:none;',
    ),
    'multimerchant_accounts' => array(
        'title' => 'Multimerchant Accounts',
        'type' => 'multimerchant_repeater',
        'description' => 'Manage IBAN and charges for Main-Merchant.',
    ),
    'autodeduction_section_title' => array(
        'title' => 'Subscription Configuration',
        'type' => 'title',
    ),
    'enable_subscriptions' => array(
        'title' => 'Enable Subscriptions',
        'type' => 'checkbox',
        'label' => 'Enable subscription payments',
        'default' => 'no',
        // Fix A1-01 (owner decision 2026-10-22): the description states that automatic
        // renewal charges are paused, visibly under the field (review M5), not in a tooltip.
        'desc_tip' => false,
        'description' => 'Only Subscription Products are allowed at checkout If Subscription is enabled. Automatic renewal charges are paused. SUPCheckout does not send automatic renewal charges until a verified UPayments capture contract is approved. Collect renewals manually or by payment link.',
    ),
);
