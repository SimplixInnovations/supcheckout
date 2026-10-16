<?php
/**
 * Staging guard + sanitizer preflight harness.
 *
 * The guard is a must-use plugin installed on a staging copy of a production store. It
 * must do nothing outside an opted-in staging environment, and inside one it must block
 * mail, non-allowlisted HTTP (live UPayments unless explicitly allowed), webhooks,
 * indexing and side-effect plugins. Run without arguments; it re-invokes itself in an
 * "active" child process because the guard registers its hooks at include time.
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['sg_env'] = 'production';
$GLOBALS['sg_filters'] = array();
$GLOBALS['sg_home'] = 'https://staging.example.test';

function wp_get_environment_type() { return $GLOBALS['sg_env']; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['sg_filters'][$hook] = $callback; return true; }
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['sg_filters'][$hook] = $callback; return true; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url() { return $GLOBALS['sg_home']; }
function __return_false() { return false; }
class WP_Error {
    public $code;
    public function __construct($code = '', $message = '') { $this->code = $code; }
}

$pass = 0;
$fail = 0;
function sg_ok($condition, $label) {
    global $pass, $fail;
    if ($condition) { $pass++; echo "PASS: $label\n"; } else { $fail++; echo "FAIL: $label\n"; }
}

$mode = isset($argv[1]) ? $argv[1] : 'inactive';

if ('active' === $mode) {
    $GLOBALS['sg_env'] = 'staging';
    define('SUPCHECKOUT_STAGING_GUARD', true);
    require __DIR__ . '/../staging/supcheckout-staging-guard.php';

    foreach (array('option_active_plugins', 'pre_wp_mail', 'pre_http_request', 'woocommerce_webhook_should_deliver', 'pre_option_blog_public', 'admin_notices') as $hook) {
        sg_ok(isset($GLOBALS['sg_filters'][$hook]), "active guard hooks $hook");
    }
    sg_ok(false === call_user_func($GLOBALS['sg_filters']['pre_wp_mail']), 'active guard blocks every email');
    sg_ok('0' === call_user_func($GLOBALS['sg_filters']['pre_option_blog_public']), 'active guard asks search engines not to index');

    $http = $GLOBALS['sg_filters']['pre_http_request'];
    sg_ok(false === $http(false, array(), 'https://sandboxapi.upayments.com/api/v1/charge'), 'UPayments sandbox is allowed');
    sg_ok(false === $http(false, array(), 'https://staging.example.test/wp-cron.php'), 'own site is allowed');
    sg_ok($http(false, array(), 'https://apiv2api.upayments.com/api/v1/charge') instanceof WP_Error, 'live UPayments is blocked by default');
    sg_ok($http(false, array(), 'https://api.zeptomail.com/v1.1/email') instanceof WP_Error, 'mail API is blocked');
    sg_ok($http(false, array(), 'https://merchants.google.com/x') instanceof WP_Error, 'Google Merchant sync is blocked');
    sg_ok($http(false, array(), 'https://myorganicworld.net/') instanceof WP_Error, 'production site is blocked');

    $plugins = supcheckout_staging_guard_filter_plugins(array(
        'woocommerce/woocommerce.php',
        'zoho-zeptomail/zeptomail.php',
        'transmail/transmail.php',
        'wp-marketing-automations/wp-marketing-automations.php',
        'wp-marketing-automations-pro/wp-marketing-automations-pro.php',
        'google-listings-and-ads/google-listings-and-ads.php',
        'modular-connector/init.php',
        'litespeed-cache/litespeed-cache.php',
        'upayments/UPayments.php',
        'supcheckout/supcheckout.php',
        'funnel-builder-pro/funnel-builder-pro.php',
    ));
    sg_ok($plugins === array('woocommerce/woocommerce.php', 'upayments/UPayments.php', 'supcheckout/supcheckout.php', 'funnel-builder-pro/funnel-builder-pro.php'),
        'side-effect plugins stop; WooCommerce, both UPayments plugins and the FunnelKit checkout keep running');
    // A guard file and constant copied to production by mistake must stay inert.
    $GLOBALS['sg_env'] = 'production';
    sg_ok(!supcheckout_staging_guard_is_active(), 'constant present on a production environment is not active');
    echo "ACTIVE: $pass PASS / $fail FAIL\n";
    exit($fail === 0 ? 0 : 1);
}

// Inactive: production environment, constant undefined.
require __DIR__ . '/../staging/supcheckout-staging-guard.php';
sg_ok(array() === $GLOBALS['sg_filters'], 'on production the guard registers nothing');
sg_ok(!supcheckout_staging_guard_is_active(), 'production environment is never active');
$GLOBALS['sg_env'] = 'staging';
sg_ok(!supcheckout_staging_guard_is_active(), 'staging without SUPCHECKOUT_STAGING_GUARD is not active');

sg_ok(supcheckout_staging_guard_host_allowed('sandboxapi.upayments.com', 'stg.test', false), 'host allowlist: sandbox');
sg_ok(!supcheckout_staging_guard_host_allowed('apiv2api.upayments.com', 'stg.test', false), 'host allowlist: live provider blocked by default');
sg_ok(supcheckout_staging_guard_host_allowed('apiv2api.upayments.com', 'stg.test', true), 'host allowlist: live provider only when explicitly allowed');
sg_ok(!supcheckout_staging_guard_host_allowed('evil-apiv2api.upayments.com.attacker.test', 'stg.test', true), 'host allowlist: exact host match only');
sg_ok(!supcheckout_staging_guard_host_allowed('', 'stg.test', true), 'host allowlist: empty host blocked');
sg_ok(supcheckout_staging_guard_host_allowed('STG.TEST.', 'stg.test', false), 'host allowlist: case and trailing dot normalized');

// Sanitizer preflight refusals.
require __DIR__ . '/../staging/lib/staging-sanitizer.php';
$ok = supcheckout_sanitizer_preflight('staging', true, 'staging.myorganicworld.net', 'myorganicworld.net');
sg_ok($ok['ok'], 'sanitizer allows an opted-in staging copy on another host');
sg_ok(!supcheckout_sanitizer_preflight('production', true, 'staging.example', 'myorganicworld.net')['ok'], 'sanitizer refuses production environment');
sg_ok(!supcheckout_sanitizer_preflight('staging', null, 'staging.example', 'myorganicworld.net')['ok'], 'sanitizer refuses without the guard constant');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'myorganicworld.net', 'myorganicworld.net')['ok'], 'sanitizer refuses the production host');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'MyOrganicWorld.net', 'myorganicworld.net')['ok'], 'sanitizer production-host check ignores case');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'myorganicworld.net', 'www.myorganicworld.net')['ok'], 'sanitizer refuses the apex of a www production host');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'staging.example', '')['ok'], 'sanitizer refuses when the production host is not given');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'www.myorganicworld.net', 'myorganicworld.net')['ok'], 'sanitizer refuses a www production site given its apex');
sg_ok(!supcheckout_sanitizer_preflight('staging', true, 'myorganicworld.net.', 'MYORGANICWORLD.NET')['ok'], 'sanitizer production-host check ignores trailing dot and case');
sg_ok(supcheckout_sanitizer_preflight('staging', true, 'staging.myorganicworld.net', 'www.myorganicworld.net')['ok'], 'a staging subdomain is not the production host');

$child = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' active';
exec($child, $child_output, $child_rc);
echo implode("\n", $child_output) . "\n";
sg_ok(0 === $child_rc, 'active-guard child process passes');

echo "Staging guard harness: $pass PASS / $fail FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);
