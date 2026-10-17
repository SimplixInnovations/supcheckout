<?php
/**
 * Certification-only must-use plugin for the Sub-project 0b Task 12 migration matrix
 * (.github/workflows/takeover-migration-matrix.yml, tests/integration/TakeoverMatrixTest.php).
 * Never shipped. Copied into wp-content/mu-plugins by the workflow.
 *
 * Provider traffic: NONE. Every WordPress HTTP API request is decided here, before any
 * transport runs (pre_http_request, priority 1):
 * - loopback (127.0.0.1 / localhost): passes through, so Preflight's callback probe reaches
 *   the real CI web server that serves this store;
 * - UPayments hosts: answered only from the fixture the test stored in the
 *   OPTION_FIXTURES option (the read-only availability call is always answered with a
 *   documented-shape hosted success). A provider route without a fixture is refused with a
 *   WP_Error and recorded in OPTION_DENIED, which the test asserts stays empty. Every
 *   answered call is counted in OPTION_CALLS, so the test can assert exactly-once;
 * - every other host (update checkers, wordpress.org, GitHub): refused, never sent.
 * The official plugin's own provider calls use raw cURL, which this filter cannot see; the
 * workflow sinkholes every UPayments host in /etc/hosts so those fail before any packet
 * leaves the runner.
 *
 * Also, test hooks that only act when the workflow sets them:
 * - SUPCHECKOUT_MATRIX_KILL_AFTER_COPIES=N (environment): after the N-th order save that
 *   carries the takeover copy marker in this process, SIGKILL this process. That is a kill
 *   in the middle of a copy batch, after an order's single save() and before the runner
 *   persists its cursor (plan Review Focus 4);
 * - every woocommerce_payment_complete is counted per order in OPTION_PAYMENT_COMPLETE, in
 *   whichever process (web server or WP-CLI) it fires.
 */

defined('ABSPATH') || exit;

const SUPCHECKOUT_MATRIX_OPTION_FIXTURES = 'supcheckout_matrix_provider_fixtures';
const SUPCHECKOUT_MATRIX_OPTION_CALLS = 'supcheckout_matrix_provider_calls';
const SUPCHECKOUT_MATRIX_OPTION_DENIED = 'supcheckout_matrix_provider_denied';
const SUPCHECKOUT_MATRIX_OPTION_PAYMENT_COMPLETE = 'supcheckout_matrix_payment_complete';

/**
 * @param string $option Option holding a name => count map.
 * @param string $name   Counter name.
 * @return void
 */
function supcheckout_matrix_count($option, $name) {
    wp_cache_delete($option, 'options');
    $counts = get_option($option, array());
    $counts = is_array($counts) ? $counts : array();
    $counts[$name] = isset($counts[$name]) ? (int) $counts[$name] + 1 : 1;
    update_option($option, $counts, false);
}

/**
 * @param int    $code HTTP status.
 * @param string $body Body.
 * @return array<string, mixed>
 */
function supcheckout_matrix_response($code, $body) {
    return array(
        'headers' => array(),
        'body' => $body,
        'response' => array('code' => (int) $code, 'message' => 'Matrix fixture'),
        'cookies' => array(),
        'filename' => null,
    );
}

add_filter('pre_http_request', static function ($preempt, $args, $url) {
    $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    if ($host === '127.0.0.1' || $host === 'localhost') {
        return $preempt;
    }
    if ($host !== 'upayments.com' && substr($host, -strlen('.upayments.com')) !== '.upayments.com') {
        return new WP_Error('supcheckout_matrix_egress_denied', 'Migration matrix: no external HTTP (' . $host . ')');
    }
    $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
    $route = (string) preg_replace('#^/api/v1/#', '', $path);
    if ($route === 'check-payment-button-status') {
        supcheckout_matrix_count(SUPCHECKOUT_MATRIX_OPTION_CALLS, $route);
        return supcheckout_matrix_response(201, (string) wp_json_encode(array(
            'status' => true,
            'data' => array('isWhiteLabel' => false, 'payButtons' => array('knet' => 1, 'cc' => 1)),
        )));
    }
    wp_cache_delete(SUPCHECKOUT_MATRIX_OPTION_FIXTURES, 'options');
    $fixtures = get_option(SUPCHECKOUT_MATRIX_OPTION_FIXTURES, array());
    $fixtures = is_array($fixtures) ? $fixtures : array();
    if (isset($fixtures[$route]) && is_array($fixtures[$route]) && isset($fixtures[$route]['code'], $fixtures[$route]['body'])) {
        supcheckout_matrix_count(SUPCHECKOUT_MATRIX_OPTION_CALLS, $route);
        return supcheckout_matrix_response((int) $fixtures[$route]['code'], (string) $fixtures[$route]['body']);
    }
    supcheckout_matrix_count(SUPCHECKOUT_MATRIX_OPTION_DENIED, $route);
    return new WP_Error('supcheckout_matrix_provider_denied', 'Migration matrix: no fixture for provider route ' . $route);
}, 1, 3);

add_action('woocommerce_payment_complete', static function ($order_id) {
    supcheckout_matrix_count(SUPCHECKOUT_MATRIX_OPTION_PAYMENT_COMPLETE, (string) (int) $order_id);
}, 1);

$supcheckout_matrix_kill_after = (int) getenv('SUPCHECKOUT_MATRIX_KILL_AFTER_COPIES');
if ($supcheckout_matrix_kill_after > 0) {
    add_action('woocommerce_after_order_object_save', static function ($order) use ($supcheckout_matrix_kill_after) {
        static $copies = 0;
        if (!is_object($order) || !method_exists($order, 'get_meta') || $order->get_meta('_supcheckout_takeover_copy_v1') === '') {
            return;
        }
        ++$copies;
        if ($copies < $supcheckout_matrix_kill_after) {
            return;
        }
        if (!function_exists('posix_kill') || !function_exists('getmypid')) {
            throw new RuntimeException('Migration matrix: posix_kill is required to kill the copy runner');
        }
        // Mirrors a host killing the worker: no shutdown handler, no cleanup, no unlock.
        fwrite(STDERR, 'MATRIX: SIGKILL after copy save ' . $copies . ' of order ' . (int) $order->get_id() . PHP_EOL);
        posix_kill(getmypid(), 9);
    }, PHP_INT_MAX);
}
unset($supcheckout_matrix_kill_after);
