<?php
/**
 * Helpers shared by the takeover certifications on real WordPress/WooCommerce
 * (tests/integration/TakeoverCliTest.php and TakeoverRestTest.php). Never shipped.
 */

if (!defined('ABSPATH')) {
    throw new RuntimeException('Takeover certification helpers must run inside WordPress.');
}

/** The official-plugin stand-in the compatibility workflow installs. */
const SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL = 'upayments/UPayments.php';

/**
 * Run `wp <command>` in a fresh process, so it boots exactly as an operator's would.
 *
 * @param string $command Command after `wp`.
 * @return object{stdout:string, stderr:string, return_code:int}
 */
function supcheckout_takeover_cert_wp($command) {
    return WP_CLI::runcommand($command, array('return' => 'all', 'launch' => true, 'exit_error' => false));
}
