<?php
/**
 * Classic payment box and Blocks presentation rendering.
 *
 * Renders templates/new-design-form.php and templates/old-design-form.php in child
 * processes with a fake gateway and asserts what the customer actually gets: a clear
 * message when methods could not be loaded, an accessible name on the hosted-payment
 * button, the order's own total and currency on the pay-for-order page (the cart is
 * empty there), prices in the store's own format (U6, A4-06), and the merchant's
 * description with bidi isolation in both designs (U6, A4-05, A4-07).
 *
 * The Blocks scenario runs the real PaymentMethodType::get_payment_method_data() and
 * asserts that it localizes the gateway's filtered title and description (U6, A4-03).
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

/** The inner HTML of every payment-method-price span. */
function template_prices($html) {
    preg_match_all('/<span class="payment-method-price">(.*?)<\/span>\s*<span class="payment-method-icon2">/s', $html, $matches);
    return $matches[1];
}

/** The hosted-payment button's aria-label, entity-decoded the way a browser reads it. */
function template_hosted_aria_label($html) {
    if (preg_match('/<button[^>]*class="upay-payment-method"[^>]*aria-label="([^"]*)"/', $html, $match) !== 1) {
        return null;
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

// A4-06: the buttons print the total exactly as WooCommerce prints it everywhere else
// on the page (wc_price): the store's separators and currency position, inside <bdi>.
$hosted_prices = template_prices($hosted['html']);
template_assert(count($hosted_prices) === 1, 'the hosted button shows one price');
template_assert(
    isset($hosted_prices[0])
        && strpos($hosted_prices[0], '<span class="woocommerce-Price-amount amount"><bdi>12.500&nbsp;<span class="woocommerce-Price-currencySymbol" translate="no" dir="auto">&#x62f;.&#x643;</span></bdi></span>') !== false,
    'checkout shows the cart total through wc_price in the store\'s price format, bidi-isolated in <bdi>'
);
// Review round 1 (I-1): the escaping allowlist keeps the attributes wc_price() emits.
template_assert(
    isset($hosted_prices[0]) && strpos($hosted_prices[0], 'translate="no"') !== false,
    'the currency symbol keeps wc_price()\'s translate="no", so page translators leave it alone'
);
template_assert(
    isset($hosted_prices[0]) && preg_match('/<span class="woocommerce-Price-currencySymbol"[^>]*\bdir="auto"/', $hosted_prices[0]) === 1,
    'the currency symbol keeps wc_price()\'s dir="auto"'
);
$hosted_label = template_hosted_aria_label($hosted['html']);
template_assert(
    is_string($hosted_label)
        && strpos($hosted_label, "Continue to secure payment, 12.500\u{00A0}د.ك") === 0
        && strpos($hosted_label, '<') === false
        && strpos($hosted_label, '&') === false,
    'the hosted button\'s accessible name reads the same formatted total as plain text'
);

$comma = template_render('comma_store');
template_assert($comma['code'] === 0, 'a store with "," as the decimal separator renders without error');
$comma_prices = template_prices($comma['html']);
template_assert(
    isset($comma_prices[0]) && strpos($comma_prices[0], '<bdi>1,500&nbsp;') !== false && strpos($comma_prices[0], '1.500') === false,
    'a "," decimal-separator store shows 1,500, not the raw 1.500 that reads as a different amount'
);

$order_pay = template_render('order_pay');
template_assert($order_pay['code'] === 0, 'pay-for-order renders without error');
$order_prices = template_prices($order_pay['html']);
template_assert(
    isset($order_prices[0])
        && strpos($order_prices[0], '<bdi>7.250&nbsp;<span class="woocommerce-Price-currencySymbol" translate="no" dir="auto">&#x631;.&#x633;</span></bdi>') !== false,
    'pay-for-order shows the order\'s own total in the order\'s own currency, not the empty cart'
);

// A4-07 + A4-05: the new (default) design renders the merchant's description, which
// WooCommerce leaves to payment_fields(), bidi-isolated so an English sentence keeps its
// punctuation on an Arabic (RTL) checkout.
template_assert(
    preg_match('/<div class="supcheckout-description" dir="auto">Pay securely with KNET\.<\/div>/', $hosted['html']) === 1,
    'the new design renders the gateway description inside dir="auto"'
);
$no_description = template_render('no_description');
template_assert($no_description['code'] === 0, 'an empty description renders without error');
template_assert(strpos($no_description['html'], 'supcheckout-description') === false, 'an empty description renders no description element');
$hostile = template_render('hostile_description');
template_assert(
    strpos($hostile['html'], '<script') === false && strpos($hostile['html'], '<strong>Bold</strong>') !== false,
    'the merchant description keeps the existing wp_kses_post contract (allowed tags kept, scripts removed)'
);

$old = template_render('old_design');
template_assert($old['code'] === 0, 'the old design renders without error');
template_assert(
    preg_match('/<div class="supcheckout-description" dir="auto">Pay securely with KNET\.<\/div>/', $old['html']) === 1,
    'the old design wraps the gateway description in dir="auto" (owner observation O3)'
);
$old_empty = template_render('old_design_no_description');
template_assert(strpos($old_empty['html'], 'supcheckout-description') === false, 'the old design renders no description element for an empty description');

// A4-05 (description scope): the "Pay securely with" logo spacing is logical, so it sits
// between the text and the logo in RTL too.
$gateway_source = (string) file_get_contents($root . '/src/Gateway/Gateway.php');
$icon_start = strpos($gateway_source, 'public function custom_payment_gateway_icons');
$icon_source = $icon_start === false ? '' : substr($gateway_source, $icon_start, 600);
template_assert(
    $icon_source !== ''
        && strpos($icon_source, 'padding-inline-start:4px') !== false
        && strpos($icon_source, 'padding-left') === false,
    'the "Pay securely with" logo uses logical (RTL-safe) spacing'
);

// A4-03: Blocks receives the gateway's filtered title and description.
$blocks = template_render('blocks');
$blocks_data = json_decode($blocks['html'], true);
template_assert($blocks['code'] === 0 && is_array($blocks_data), 'Blocks payment data builds without error');
template_assert(
    is_array($blocks_data) && isset($blocks_data['title']) && $blocks_data['title'] === 'ادفع بالبطاقة &amp; كي نت',
    'Blocks data carries the gateway title as filtered by woocommerce_gateway_title (WCML)'
);
template_assert(
    is_array($blocks_data) && isset($blocks_data['description']) && $blocks_data['description'] === 'Pay securely. Bold &amp; fast.',
    'Blocks data carries the filtered gateway description as plain text'
);
$blocks_none = template_render('blocks_no_gateway');
$blocks_none_data = json_decode($blocks_none['html'], true);
template_assert(
    is_array($blocks_none_data) && ($blocks_none_data['title'] ?? null) === '' && ($blocks_none_data['description'] ?? null) === '',
    'Blocks data without a registered gateway carries an empty title and description (client falls back to UPayments)'
);

echo "\nCheckout template: {$pass} PASS / {$fail} FAIL\n";
echo 'HARNESS-COMPLETE: ' . basename(__FILE__) . "\n";
exit($fail === 0 ? 0 : 1);

function template_child($root, $scenario) {
    define('ABSPATH', __DIR__ . '/');
    define('SUPCHECKOUT_PLUGIN_URL', 'https://merchant.example.test/wp-content/plugins/supcheckout/');
    $GLOBALS['template_scenario'] = $scenario;
    eval(<<<'PHP'
function __($text, $domain = 'default') { return $text; }
function esc_html__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr__($text, $domain = 'default') { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html_e($text, $domain = 'default') { echo htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return (string) $url; }
// Stand-in for kses: keep only the allowed elements, and on them only the allowed attributes.
function wp_kses($text, $allowed) {
    $allowed = (array) $allowed;
    $text = strip_tags((string) $text, array_keys($allowed));
    return preg_replace_callback('/<(\/?)([a-z0-9]+)([^>]*)>/i', function ($m) use ($allowed) {
        if ($m[1] === '/') { return '</' . $m[2] . '>'; }
        $keep = isset($allowed[strtolower($m[2])]) ? (array) $allowed[strtolower($m[2])] : array();
        preg_match_all('/\s([a-z-]+)="([^"]*)"/i', $m[3], $attrs, PREG_SET_ORDER);
        $out = '<' . $m[2];
        foreach ($attrs as $attr) {
            if (!empty($keep[strtolower($attr[1])])) { $out .= ' ' . $attr[1] . '="' . $attr[2] . '"'; }
        }
        return $out . '>';
    }, $text);
}
function wp_kses_post($text) { return strip_tags((string) $text, array('a', 'b', 'br', 'em', 'i', 'p', 'span', 'strong')); }
function wp_strip_all_tags($text, $remove_breaks = false) { return trim(strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text))); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function get_locale() { return 'en_US'; }
function get_woocommerce_currency() { return 'KWD'; }
// WooCommerce stores currency symbols as HTML entities.
function get_woocommerce_currency_symbol($currency = '') {
    $symbols = array('KWD' => '&#x62f;.&#x643;', 'SAR' => '&#x631;.&#x633;');
    $currency = $currency === '' ? get_woocommerce_currency() : $currency;
    return isset($symbols[$currency]) ? $symbols[$currency] : $currency;
}
function wc_get_price_decimals() { return 3; }
function wc_get_price_decimal_separator() { return $GLOBALS['template_scenario'] === 'comma_store' ? ',' : '.'; }
function wc_get_price_thousand_separator() { return $GLOBALS['template_scenario'] === 'comma_store' ? '.' : ','; }
function get_woocommerce_price_format() { return '%2$s&nbsp;%1$s'; }
function wc_format_decimal($number, $dp = false) { return number_format((float) $number, $dp === false ? 3 : (int) $dp, '.', ''); }
// Mirrors the markup of WooCommerce's wc_price(), including the translate="no" (WooCommerce
// 11.1+) and dir="auto" (WooCommerce trunk) attributes on the currency symbol.
function wc_price($price, $args = array()) {
    $currency = isset($args['currency']) && $args['currency'] !== '' ? $args['currency'] : get_woocommerce_currency();
    $decimals = isset($args['decimals']) ? (int) $args['decimals'] : wc_get_price_decimals();
    $formatted = number_format((float) $price, $decimals, wc_get_price_decimal_separator(), wc_get_price_thousand_separator());
    return '<span class="woocommerce-Price-amount amount"><bdi>'
        . sprintf(get_woocommerce_price_format(), '<span class="woocommerce-Price-currencySymbol" translate="no" dir="auto">' . get_woocommerce_currency_symbol($currency) . '</span>', $formatted)
        . '</bdi></span>';
}
function is_wc_endpoint_url($endpoint = '') { return $GLOBALS['template_scenario'] === 'order_pay' && $endpoint === 'order-pay'; }
function get_query_var($var, $default = '') { return ($var === 'order-pay' && $GLOBALS['template_scenario'] === 'order_pay') ? '77' : $default; }
function absint($value) { return abs((int) $value); }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function is_checkout() { return true; }
function plugin_dir_url($file) { return 'https://merchant.example.test/wp-content/plugins/supcheckout/'; }
class TemplateCart {
    public function get_total($context = 'view') {
        if ($GLOBALS['template_scenario'] === 'order_pay') { return '0'; }
        return $GLOBALS['template_scenario'] === 'comma_store' ? '1.5' : '12.5';
    }
    public function get_cart() { return array(); }
}
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
    public function read_for_display($read) { return $read(); }
    // WC_Payment_Gateway::get_title()/get_description() apply the woocommerce_gateway_*
    // filters, where WCML translates; the fake returns the filtered values.
    public function get_title() { return 'ادفع بالبطاقة &amp; كي نت'; }
    public function get_description() {
        switch ($GLOBALS['template_scenario']) {
            case 'no_description':
            case 'old_design_no_description':
                return '';
            case 'hostile_description':
                return '<script>alert(1)</script><strong>Bold</strong>';
            case 'blocks':
                return 'Pay securely. <strong>Bold</strong> &amp; fast.';
            default:
                return 'Pay securely with KNET.';
        }
    }
    public function getPaymentIcons() {
        if ($GLOBALS['template_scenario'] === 'unavailable') { return null; }
        return array('payment' => array('knet' => 'KNET', 'cc' => 'Credit Card'), 'whitelabled' => false);
    }
}
PHP
    );

    if ($scenario === 'blocks' || $scenario === 'blocks_no_gateway') {
        template_blocks_child($root, $scenario === 'blocks' ? new TemplateGateway() : null);
        return;
    }

    $gateway = new TemplateGateway();
    $save_card_enabled = false;
    $template = ($scenario === 'old_design' || $scenario === 'old_design_no_description') ? 'old-design-form.php' : 'new-design-form.php';
    include $root . '/templates/' . $template;
}

function template_blocks_child($root, $gateway) {
    eval('namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; abstract class AbstractPaymentMethodType { protected $settings = array(); }');
    require_once $root . '/src/Takeover/TakeoverState.php';
    require_once $root . '/src/Takeover/Keys.php';
    require_once $root . '/src/Admin/GatewaySettings.php';
    require_once $root . '/src/Blocks/PaymentMethodType.php';
    eval('class TemplateBlocksProbe extends \\Simplixi\\SUPCheckout\\Blocks\\PaymentMethodType {
        public function __construct($gateway) { parent::__construct(""); $this->gateway = $gateway; }
    }');
    $probe = new TemplateBlocksProbe($gateway);
    $data = $probe->get_payment_method_data();
    echo json_encode(array(
        'title' => array_key_exists('title', $data) ? $data['title'] : null,
        'description' => array_key_exists('description', $data) ? $data['description'] : null,
    ), JSON_UNESCAPED_UNICODE);
}
