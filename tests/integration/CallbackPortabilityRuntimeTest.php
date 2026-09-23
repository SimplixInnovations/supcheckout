<?php
/**
 * R2 real WooCommerce callback portability certification.
 *
 * Proves WC()->api_request_url('wc_upayments') remains governed by the configured
 * WordPress/WooCommerce public URL across home/site divergence and permalink
 * layouts, without trusting raw forwarded/host request headers.
 */

require_once __DIR__ . '/bootstrap.php';

$original_home = get_option('home');
$original_siteurl = get_option('siteurl');
$original_permalink = get_option('permalink_structure');

try {
    // Case A — plain permalinks + home/site divergence.
    update_option('home', 'https://shop.example.test/store');
    update_option('siteurl', 'https://shop.example.test/wp');
    update_option('permalink_structure', '');
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }

    $plain_url = WC()->api_request_url('wc_upayments');
    supcheckout_cert_note('plain api_request_url=' . $plain_url);
    supcheckout_cert_assert(is_string($plain_url) && $plain_url !== '', 'plain WC-API URL is produced');
    supcheckout_cert_assert(
        strpos($plain_url, 'https://shop.example.test/store') === 0,
        'plain WC-API URL uses configured home/public origin, not siteurl /wp'
    );
    supcheckout_cert_assert(
        strpos($plain_url, 'https://shop.example.test/wp') === false,
        'plain WC-API URL does not use WordPress siteurl path'
    );
    supcheckout_cert_assert(
        strpos($plain_url, 'wc-api=wc_upayments') !== false,
        'plain WC-API URL retains wc-api=wc_upayments'
    );

    // Case B — pretty permalinks.
    update_option('permalink_structure', '/%postname%/');
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }

    $pretty_url = WC()->api_request_url('wc_upayments');
    supcheckout_cert_note('pretty api_request_url=' . $pretty_url);
    supcheckout_cert_assert(is_string($pretty_url) && $pretty_url !== '', 'pretty WC-API URL is produced');
    supcheckout_cert_assert(
        strpos($pretty_url, 'https://shop.example.test/store') === 0,
        'pretty WC-API URL uses configured home/public origin'
    );
    supcheckout_cert_assert(
        strpos($pretty_url, '/wc-api/wc_upayments') !== false,
        'pretty WC-API URL includes /wc-api/wc_upayments route'
    );

    // Case C — index permalinks.
    update_option('permalink_structure', '/index.php/%postname%/');
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }

    $index_url = WC()->api_request_url('wc_upayments');
    supcheckout_cert_note('index api_request_url=' . $index_url);
    supcheckout_cert_assert(is_string($index_url) && $index_url !== '', 'index WC-API URL is produced');
    supcheckout_cert_assert(
        strpos($index_url, '/index.php/wc-api/wc_upayments') !== false
        || strpos($index_url, '/index.php') === 0,
        'index WC-API URL retains /index.php layout'
    );
    supcheckout_cert_assert(
        strpos($index_url, 'https://shop.example.test/store') === 0,
        'index WC-API URL uses configured home/public origin'
    );

    // Case D — HTTPS configured public origin.
    update_option('home', 'https://secure-shop.example.test');
    update_option('siteurl', 'https://secure-shop.example.test/wp');
    update_option('permalink_structure', '');
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }

    $https_url = WC()->api_request_url('wc_upayments');
    supcheckout_cert_note('https api_request_url=' . $https_url);
    supcheckout_cert_assert(
        strpos($https_url, 'https://secure-shop.example.test') === 0,
        'HTTPS configured public origin remains HTTPS'
    );

    // Case E — forwarded-header spoof resistance.
    // SUPCheckout must not construct callback origins from raw client headers.
    // The WC-API abstraction remains governed by configured WordPress URLs.
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
    $_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.example.test';
    $_SERVER['HTTP_FORWARDED'] = 'host=attacker.example.test;proto=http';
    $_SERVER['HTTP_HOST'] = 'attacker.example.test';
    $_SERVER['REQUEST_SCHEME'] = 'http';

    $spoofed_url = WC()->api_request_url('wc_upayments');
    supcheckout_cert_note('spoofed-header api_request_url=' . $spoofed_url);
    supcheckout_cert_assert(
        strpos($spoofed_url, 'https://secure-shop.example.test') === 0,
        'configured public origin survives spoofed forwarded/host headers'
    );
    supcheckout_cert_assert(
        strpos($spoofed_url, 'attacker.example.test') === false,
        'generated WC-API URL rejects spoofed host influence from raw request headers'
    );

    $orchestrator_source = file_get_contents(dirname(__DIR__, 2) . '/src/Payment/CheckoutOrchestrator.php');
    supcheckout_cert_assert(is_string($orchestrator_source), 'CheckoutOrchestrator source is readable');
    supcheckout_cert_assert(
        strpos($orchestrator_source, "api_request_url('wc_upayments')") !== false
        || strpos($orchestrator_source, 'api_request_url("wc_upayments")') !== false
        || strpos($orchestrator_source, "api_request_url(\$callback_route") !== false
        || preg_match('/api_request_url\s*\(/', $orchestrator_source) === 1,
        'CheckoutOrchestrator consumes WooCommerce api_request_url abstraction'
    );
    foreach (array('HTTP_X_FORWARDED', 'HTTP_FORWARDED', 'FORWARDED_HOST', 'FORWARDED_PROTO', 'HTTP_HOST') as $header_token) {
        supcheckout_cert_assert(
            strpos($orchestrator_source, $header_token) === false,
            'CheckoutOrchestrator does not read raw forwarded/host header ' . $header_token
        );
    }
    supcheckout_cert_assert(
        strpos($orchestrator_source, "site_url() . \"/?wc-api=wc_upayments") === false,
        'CheckoutOrchestrator no longer concatenates site_url() WC-API callbacks'
    );
} finally {
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = null;
    $_SERVER['HTTP_X_FORWARDED_HOST'] = null;
    $_SERVER['HTTP_FORWARDED'] = null;
    $_SERVER['HTTP_HOST'] = null;
    $_SERVER['REQUEST_SCHEME'] = null;
    unset(
        $_SERVER['HTTP_X_FORWARDED_PROTO'],
        $_SERVER['HTTP_X_FORWARDED_HOST'],
        $_SERVER['HTTP_FORWARDED'],
        $_SERVER['HTTP_HOST'],
        $_SERVER['REQUEST_SCHEME']
    );

    update_option('home', $original_home);
    update_option('siteurl', $original_siteurl);
    update_option('permalink_structure', $original_permalink);
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules(false);
    }
}
