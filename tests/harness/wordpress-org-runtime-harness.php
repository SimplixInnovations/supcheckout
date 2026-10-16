<?php
/**
 * Static regression guard for WordPress.org blocking runtime patterns.
 *
 * Official Plugin Check remains authoritative; this harness prevents the
 * specific blocker families already reproduced from silently returning.
 */

$root = dirname(__DIR__, 2);
$pass = 0;
$fail = 0;

function wporgr_assert($condition, $message) {
    global $pass, $fail;
    if ($condition) {
        ++$pass;
        echo "PASS: {$message}\n";
        return;
    }
    ++$fail;
    echo "FAIL: {$message}\n";
}

function wporgr_read($root, $path) {
    $value = @file_get_contents($root . '/' . $path);
    return is_string($value) ? $value : '';
}

$gateway = wporgr_read($root, 'supcheckout.php');
$scheduler = wporgr_read($root, 'includes/Subscription/Cron/Scheduler.php');
$status = wporgr_read($root, 'src/Payment/StatusVerifier.php');
$payload = wporgr_read($root, 'src/Payment/CheckoutPayload.php');
$token = wporgr_read($root, 'includes/Token/CustomerTokenIdentity.php');
$new_template = wporgr_read($root, 'templates/new-design-form.php');
$old_template = wporgr_read($root, 'templates/old-design-form.php');
$product_type = wporgr_read($root, 'src/Subscription/WCProductCustomType.php');

wporgr_assert(!preg_match('/\\bcurl_(?:init|setopt|exec|errno|error|getinfo|close)\\s*\\(/', $gateway), 'gateway runtime contains no direct cURL transport');
wporgr_assert(!preg_match('/\\bcurl_(?:init|setopt|exec|errno|error|getinfo|close)\\s*\\(/', $scheduler), 'subscription scheduler contains no direct cURL transport');
wporgr_assert(strpos($gateway, 'wp_remote_request(') !== false, 'gateway transport uses WordPress HTTP API');
wporgr_assert(strpos($scheduler, 'wp_remote_request(') !== false, 'subscription transport uses WordPress HTTP API');

wporgr_assert(!preg_match('/(?<!wp_)\\bparse_url\\s*\\(/', $status) && strpos($status, 'wp_parse_url(') !== false, 'status URL validation uses wp_parse_url');
wporgr_assert(!preg_match('/(?<!wp_)\\bparse_url\\s*\\(/', $payload) && strpos($payload, 'wp_parse_url(') !== false, 'checkout redirect validation uses wp_parse_url');

wporgr_assert(strpos($product_type, "defined( 'ABSPATH' ) || exit;") !== false, 'product-type compatibility file blocks direct access');

foreach (array($new_template, $old_template) as $index => $template) {
    $label = $index === 0 ? 'new' : 'legacy';
    wporgr_assert(strpos($template, '$gateway->domain') === false, $label . ' template has no dynamic translation domain');
    wporgr_assert(!preg_match('/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\\s*\\([^)]*[\'\"]upayments[\'\"]/', $template), $label . ' template has no retired translation domain');
}

wporgr_assert(strpos($token, '$wpdb->get_col(null)') === false, 'token provenance query does not consume an implicit prior SQL result');

echo "\nWordPress.org Runtime Guard: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
