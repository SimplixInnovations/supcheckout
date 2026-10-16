<?php
/**
 * Classic (new design) payment box rendering.
 *
 * Renders templates/new-design-form.php in child processes with a fake gateway and
 * asserts what the customer actually gets: a clear message when methods could not
 * be loaded, an accessible name on the hosted-payment button, and the order's own
 * total and currency on the pay-for-order page (the cart is empty there).
 */

$root = dirname(__DIR__, 2);

if (isset($argv[1]) && $argv[1] === '--child') {
    template_child($root, (string) $argv[2]);
    exit(0);
}

$pass = 0;
$fail = 0;
function template_assert($condition, $label) {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS: {$label}\n";
        return;
    }
    $fail++;
    echo "FAIL: {$label}\n";
}

function template_render($scenario) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --child ' . escapeshellarg($scenario) . ' 2>&1';
    $output = array();
    $code = 0;
    exec($command, $output, $code);
    return array('code' => $code, 'html' => implode("\n", $output));
}

$unavailable = template_render('unavailable');
template_assert($unavailable['code'] === 0, 'unavailable methods render without error');
template_assert(strpos($unavailable['html'], 'upay-payment-method') === false, 'no method button renders without valid availability');
template_assert(strpos($unavailable['html'], 'role="alert"') !== false, 'customers are told that payment methods could not be loaded');

$hosted = template_render('hosted');
template_assert($hosted['code'] === 0, 'hosted (non-white-label) button renders without error');
template_assert(preg_match('/<button[^>]*class="upay-payment-method"[^>]*aria-label="[^"]+"/', $hosted['html']) === 1
    || preg_match('/<button[^>]*aria-label="[^"]+"[^>]*class="upay-payment-method"/', $hosted['html']) === 1,
    'the hosted-payment button has an accessible name');
template_assert(strpos($hosted['html'], '12.500 KWD') !== false, 'checkout shows the cart total in the store\'s price format');

$order_pay = template_render('order_pay');
template_assert($order_pay['code'] === 0, 'pay-for-order renders without error');
template_assert(strpos($order_pay['html'], '7.250 SAR') !== false, 'pay-for-order shows the order\'s own total and currency, not the empty cart');

echo "\nCheckout template: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);

function template_child($root, $scenario) {
    define('ABSPATH', __DIR__ . '/');
    define('UP_PLUGIN_URL', 'https://merchant.example.test/wp-content/plugins/supcheckout/');
    $GLOBALS['template_scenario'] = $scenario;
    eval(<<<'PHP'
function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($text, $domain = 'default') { echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return (string) $url; }
function wp_kses($text, $allowed) { return strip_tags((string) $text); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function get_locale() { return 'en_US'; }
function get_woocommerce_currency() { return 'KWD'; }
function get_woocommerce_currency_symbol($currency = '') { return 'د.ك'; }
function wc_get_price_decimals() { return 3; }
function wc_format_decimal($number, $dp = false) { return number_format((float) $number, $dp === false ? 3 : (int) $dp, '.', ''); }
function is_wc_endpoint_url($endpoint = '') { return $GLOBALS['template_scenario'] === 'order_pay' && $endpoint === 'order-pay'; }
function get_query_var($var, $default = '') { return ($var === 'order-pay' && $GLOBALS['template_scenario'] === 'order_pay') ? '77' : $default; }
function absint($value) { return abs((int) $value); }
function is_user_logged_in() { return false; }
class TemplateCart { public function get_total($context = 'view') { return $GLOBALS['template_scenario'] === 'order_pay' ? '0' : '12.5'; } }
class TemplateWC { public $cart; public function __construct() { $this->cart = new TemplateCart(); } }
function WC() { static $wc = null; return $wc ?: ($wc = new TemplateWC()); }
class TemplateOrder { public function get_total($context = 'view') { return '7.25'; } public function get_currency($context = 'view') { return 'SAR'; } }
function wc_get_order($id) { return (int) $id === 77 ? new TemplateOrder() : false; }
class TemplateGateway {
    public $paymentData = null;
    public $apiKey = 'key';
    public function getMode() { return true; }
    public function get_option($key, $default = '') { return 'no'; }
    public function getSavedCardsForCurrentUser($availability = null) { return null; }
    public function getPaymentIcons() {
        if ($GLOBALS['template_scenario'] === 'unavailable') { return null; }
        return array('payment' => array('knet' => 'KNET', 'cc' => 'Credit Card'), 'whitelabled' => false);
    }
}
PHP
    );
    $gateway = new TemplateGateway();
    $save_card_enabled = false;
    include $root . '/templates/new-design-form.php';
}
