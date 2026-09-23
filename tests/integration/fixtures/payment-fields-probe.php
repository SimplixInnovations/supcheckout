<?php
/**
 * Test-only template target for payment_fields() presentation certification.
 *
 * WooCommerce extracts the template argument array before including this file,
 * so this captures the exact value supplied by the gateway without executing
 * provider transport or production template markup.
 */

$GLOBALS['supcheckout_payment_fields_probe']['save_card_enabled'] = isset($save_card_enabled)
    ? $save_card_enabled
    : null;
