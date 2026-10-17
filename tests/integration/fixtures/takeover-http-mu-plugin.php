<?php
/**
 * Certification-only must-use plugin for the takeover CLI steps
 * (tests/integration/TakeoverCliTest.php). Never shipped.
 *
 * Preflight makes two HTTP requests, both answered here so CI sends no provider traffic
 * and needs no web server:
 * - the read-only availability GET (`check-payment-button-status`): a documented-shape
 *   success for the certification key, so `credential_unverified` does not block;
 * - the loopback GET of the new callback URL with `supcheckout_probe=1`: the 200 and the
 *   acknowledgement header LegacyCallbackListener::answer_probe() sends. What the paused
 *   boot registers for a real probe request is proven by
 *   tests/harness/official-plugin-coexistence-harness.php (`paused_probe`).
 *
 * Every other request passes through. Copied into wp-content/mu-plugins by the workflow
 * and removed by its cleanup step.
 */

defined('ABSPATH') || exit;

add_filter('pre_http_request', static function ($preempt, $args, $url) {
    $url = (string) $url;
    if (strpos($url, 'supcheckout_probe=1') !== false) {
        return array(
            'headers' => array('x-supcheckout-probe' => '1', 'cache-control' => 'no-cache, must-revalidate, max-age=0, no-store, private'),
            'body' => '',
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }
    if (strpos($url, 'check-payment-button-status') !== false) {
        return array(
            'headers' => array(),
            'body' => (string) wp_json_encode(array('status' => true, 'data' => array('isWhiteLabel' => false, 'payButtons' => array()))),
            'response' => array('code' => 201, 'message' => 'Created'),
            'cookies' => array(),
            'filename' => null,
        );
    }
    return $preempt;
}, 10, 3);
