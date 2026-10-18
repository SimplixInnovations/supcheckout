<?php
/**
 * Plugin Name: SUPCheckout for UPayments
 * Plugin URI: https://github.com/SimplixInnovations/supcheckout
 * Description: Independently engineered UPayments payment integration for WooCommerce by Simplix Innovations.
 * Version: 0.1.0
 * Author: Simplix Innovations
 * Author URI: https://simplixi.com
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 10.8
 * WC tested up to: 11.1
 * License: MIT
 * Text Domain: supcheckout
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/src/Autoloader.php';
\Simplixi\SUPCheckout\Autoloader::register(__DIR__ . '/src');

// While another plugin owns the 'upayments' gateway identity, stay paused (0b removes
// the shared identity; the guard stays for the official plugin's own globals). Paused,
// only the takeover entry points boot (CLI, the takeover REST routes and the onboarding
// driver): never a gateway, payment route or payment cron hook.
if (\Simplixi\SUPCheckout\Gateway\OfficialPluginConflict::pause_if_active(__FILE__)) {
    \Simplixi\SUPCheckout\Takeover\PausedBoot::boot(__FILE__);
    return;
}

\Simplixi\SUPCheckout\Plugin::boot(__FILE__);
