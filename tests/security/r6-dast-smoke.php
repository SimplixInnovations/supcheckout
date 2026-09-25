<?php
/**
 * R6 automated local security smoke (bounded DAST-like probes).
 *
 * Does not replace a professional penetration test. No provider mutation.
 * Usage: php tests/security/r6-dast-smoke.php <base-url>
 */

$base = rtrim($argv[1] ?? getenv('R6_BASE_URL') ?? '', '/');
if ($base === '' || !preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "Usage: php r6-dast-smoke.php <base-url>  (or set R6_BASE_URL)\n");
    exit(64);
}

$urls = array(
    'classic'  => getenv('R6_CLASSIC_CHECKOUT_URL') ?: "$base/",
    'blocks'   => getenv('R6_BLOCKS_CHECKOUT_URL') ?: "$base/",
    'callback' => getenv('R6_CALLBACK_URL') ?: "$base/wc-api/wc_upayments/",
    'status'   => getenv('R6_STATUS_URL') ?: "$base/",
);

$fail = 0;
function r6_req(string $url, array $headers = array()): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOBODY => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return array(
        'ok' => $errno === 0,
        'status' => $status,
        'headers' => substr((string) $raw, 0, $headerSize),
        'body' => substr((string) $raw, $headerSize),
        'errno' => $errno,
    );
}

function r6_assert(bool $cond, string $label) {
    global $fail;
    if ($cond) {
        echo "PASS: $label\n";
        return;
    }
    echo "FAIL: $label\n";
    $fail = 1;
}

// 1. Checkout page reachable without crashing
$checkout = r6_req($urls['classic']);
r6_assert($checkout['ok'] && $checkout['status'] > 0 && $checkout['status'] < 500, 'checkout page responds without transport failure');

// 2. Public callback route does not leak order key on unauthenticated GET
$cb = r6_req($urls['callback'] . (strpos($urls['callback'], '?') === false ? '?' : '&') . 'wc_order_id=1&track_id=x&requested_order_id=x');
r6_assert(
    $cb['ok'] && stripos($cb['body'], 'order-received') === false,
    'callback without verified provider status does not expose order-success URL'
);
r6_assert(
    stripos($cb['headers'], 'Set-Cookie: wordpress_logged_in') === false,
    'callback does not mint auth cookies'
);

// 3. Public status surface rejects unknown/guest without privilege
$st = r6_req($urls['status']);
r6_assert(
    $st['status'] === 401 || $st['status'] === 403 || $st['status'] === 404 || $st['status'] === 200,
    'public status surface responds'
);

// 4. XSS reflection probe on common query surfaces (must not reflect raw payload)
$xss = '"><script>window.__r6xss=1</script>';
$ref = r6_req("$base/index.php?wc-ajax=checkout&xss=" . rawurlencode($xss));
r6_assert(strpos($ref['body'], 'window.__r6xss=1') === false, 'XSS payload not reflected raw into response body');

// 5. SQL-ish probe does not 500
$sqli = "1' OR '1'='1";
$sq = r6_req("$base/index.php?rest_route=/wc/store/v1/cart&x=" . rawurlencode($sqli));
r6_assert($sq['ok'] && $sq['status'] !== 500, 'SQL-ish probe does not cause 500');

// 6. Directory traversal probe does not serve wp-config
$trav = r6_req("$base/wp-content/plugins/supcheckout/../../wp-config.php");
r6_assert(strpos($trav['body'], 'DB_PASSWORD') === false, 'traversal does not serve wp-config credentials');

// 7. HTTP method / header trust: forged Host/forwarded headers must not change payment identity claims
$forged = r6_req($urls['callback'], array(
    'Host: evil.example',
    'X-Forwarded-Host: evil.example',
    'X-Forwarded-Proto: https',
));
r6_assert($forged['ok'], 'forged forwarded headers do not crash the app');
r6_assert(stripos($forged['body'], 'evil.example') === false, 'forged Host not reflected into callback body');

if ($fail !== 0) {
    echo "R6 DAST smoke: FAIL\n";
    exit(1);
}
echo "R6 DAST smoke: PASS (bounded; not a professional pentest)\n";
