<?php
/**
 * Production WordPress HTTP transport contract for the gateway helper.
 */

$transport_calls = array();
$transport_response = array('response' => array('code' => 201), 'body' => '{"ok":true}');

final class SUPCheckout_Transport_Error {}

function wp_remote_request($url, $args = array()) {
    global $transport_calls, $transport_response;
    $transport_calls[] = array('url' => $url, 'args' => $args);
    return $transport_response;
}
function is_wp_error($value) {
    return $value instanceof SUPCheckout_Transport_Error;
}
function wp_remote_retrieve_response_code($response) {
    return is_array($response) && isset($response['response']['code'])
        ? (int) $response['response']['code']
        : 0;
}
function wp_remote_retrieve_body($response) {
    return is_array($response) && isset($response['body']) ? (string) $response['body'] : '';
}

require __DIR__ . '/_bootstrap.php';

class SUPCheckout_Production_Transport_Testable extends WC_Upayments {
    public function __construct() {}
    public function call_transport($route, $method, $body = null) {
        return parent::execute_upayments_request($route, $method, $body);
    }
    public function getAPIUrl($route = '') {
        return 'https://sandboxapi.upayments.com/api/v1/' . ltrim((string) $route, '/');
    }
    public function getUserAgent() {
        return 'SUPCheckoutTransportTest/1';
    }
}

$pass = 0;
$fail = 0;
function sut_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

$gateway_source = file_get_contents(dirname(__DIR__, 2) . '/supcheckout.php');
sut_assert(
    is_string($gateway_source)
        && strpos($gateway_source, 'wp_remote_request(') !== false
        && !preg_match('/\\bcurl_(?:init|setopt|exec|errno|error|getinfo|close)\\s*\\(/', $gateway_source),
    'production gateway transport is WordPress HTTP API before behavioral dispatch'
);
if ($fail !== 0) {
    echo "\nSUPCheckout Production HTTP Transport: {$pass} PASS / {$fail} FAIL\n";
    exit(1);
}

$gateway = new SUPCheckout_Production_Transport_Testable();
$gateway->apiKey = 'test-secret';

$transport_calls = array();
$transport_response = array('response' => array('code' => 201), 'body' => '{"status":true}');
$result = $gateway->call_transport('charge', 'POST', '{"amount":1}');
sut_assert(count($transport_calls) === 1, 'POST dispatches exactly one WordPress HTTP request');
if (isset($transport_calls[0])) {
    $call = $transport_calls[0];
    $args = isset($call['args']) && is_array($call['args']) ? $call['args'] : array();
    sut_assert($call['url'] === 'https://sandboxapi.upayments.com/api/v1/charge', 'POST targets exact provider route');
    sut_assert(isset($args['method']) && $args['method'] === 'POST', 'POST method is explicit');
    sut_assert(isset($args['timeout']) && (int) $args['timeout'] === 15, 'transport timeout remains bounded at 15 seconds');
    sut_assert(isset($args['limit_response_size']) && (int) $args['limit_response_size'] === 1048576, 'provider response body remains bounded to 1 MiB');
    sut_assert(isset($args['redirection']) && (int) $args['redirection'] === 0, 'redirect following remains disabled');
    sut_assert(isset($args['sslverify']) && $args['sslverify'] === true, 'TLS certificate verification remains enabled');
    sut_assert(isset($args['user-agent']) && $args['user-agent'] === 'SUPCheckoutTransportTest/1', 'user agent is preserved');
    sut_assert(isset($args['headers']['Accept']) && $args['headers']['Accept'] === 'application/json', 'Accept header is preserved');
    sut_assert(isset($args['headers']['Content-Type']) && $args['headers']['Content-Type'] === 'application/json', 'Content-Type header is preserved');
    sut_assert(isset($args['headers']['Authorization']) && $args['headers']['Authorization'] === 'Bearer test-secret', 'Bearer authorization is preserved');
    sut_assert(isset($args['body']) && $args['body'] === '{"amount":1}', 'POST body bytes are preserved');
}
sut_assert(is_array($result) && $result['transport_ok'] === true, '201 response is transport-ok');
sut_assert(isset($result['http_status']) && $result['http_status'] === 201, 'HTTP status is preserved');
sut_assert(isset($result['body']) && $result['body'] === '{"status":true}', 'response body is preserved');
sut_assert(isset($result['curl_errno']) && $result['curl_errno'] === 0, 'legacy transport error field remains zero on success');

$transport_calls = array();
$transport_response = array('response' => array('code' => 200), 'body' => '{}');
$result = $gateway->call_transport('check-payment-button-status', 'GET');
sut_assert(count($transport_calls) === 1, 'GET dispatches exactly one WordPress HTTP request');
sut_assert(isset($transport_calls[0]['args']['method']) && $transport_calls[0]['args']['method'] === 'GET', 'GET method is explicit');
sut_assert(!isset($transport_calls[0]['args']['body']), 'GET request sends no body');
sut_assert($result['transport_ok'] === true && $result['http_status'] === 200, 'GET 200 succeeds');

$transport_calls = array();
$transport_response = new SUPCheckout_Transport_Error();
$result = $gateway->call_transport('charge', 'POST', '{}');
sut_assert(count($transport_calls) === 1, 'WP_Error still represents one attempted request');
sut_assert($result['transport_ok'] === false && $result['http_status'] === 0, 'WP_Error fails closed with zero HTTP status');
sut_assert($result['body'] === null && $result['curl_errno'] !== 0, 'WP_Error exposes no body and nonzero compatibility transport code');

$transport_calls = array();
$transport_response = array('response' => array('code' => 500), 'body' => '{"error":true}');
$result = $gateway->call_transport('charge', 'POST', '{}');
sut_assert($result['transport_ok'] === false && $result['http_status'] === 500, 'non-2xx HTTP response is not transport-ok');
sut_assert($result['body'] === '{"error":true}', 'non-2xx response body remains available for bounded caller classification');

$transport_calls = array();
$transport_response = array('response' => array('code' => 201), 'body' => str_repeat('x', 1048576));
$result = $gateway->call_transport('charge', 'POST', '{}');
sut_assert(count($transport_calls) === 1, 'response-cap case dispatches exactly one request');
sut_assert($result['transport_ok'] === false, 'response body at the 1 MiB cap fails closed');
sut_assert($result['http_status'] === 201, 'response-cap failure preserves HTTP status for diagnostics');
sut_assert($result['body'] === null, 'response-cap failure does not expose a potentially truncated body');
sut_assert($result['curl_errno'] === 0, 'response-cap failure is not misclassified as a raw transport error');

$transport_calls = array();
$result = $gateway->call_transport('charge', 'DELETE', null);
sut_assert(count($transport_calls) === 0, 'unsupported HTTP method performs no request');
sut_assert($result['transport_ok'] === false && $result['http_status'] === 0, 'unsupported HTTP method fails closed');

echo "\nSUPCheckout Production HTTP Transport: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
