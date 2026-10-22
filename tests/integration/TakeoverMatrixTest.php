<?php
/**
 * Sub-project 0b Task 12: the real-WordPress migration matrix.
 *
 * Driven by .github/workflows/takeover-migration-matrix.yml, one SUPCHECKOUT_MATRIX_PHASE
 * per step, each in a fresh WP-CLI process. Takeover commands always run as launched `wp`
 * processes (a plugin's deferred command cannot run inside eval-file).
 *
 * Official-plugin cells (the real official plugin at a pinned upaymentskwt/woocommerce
 * commit, HPOS or legacy storage, WCML gateway-restriction shapes seeded or not):
 *   seed          official plugin active, SUPCheckout inactive: seed the store (plan step 2);
 *   paused        SUPCheckout activated and paused (step 3); preflight (step 4); constants;
 *                 the pre-switch snapshot is written;
 *   killed        after `run` was SIGKILLed in the middle of the second copy batch (step 7);
 *   verify        after the resumed `run --yes --deactivate-official` (steps 5-6);
 *   webhook       after two legacy-route webhook deliveries and one on the new route;
 *   rollback      `rollback --yes --reactivate-official` (step 8);
 *   rolled_back   pre-switch values restored byte for byte, paid orders still paid;
 *   rerun         `run` again (step 9); rerun_verify: the same result, in a fresh process;
 *   finalize      (guarded: only when src/Takeover/Finalize.php exists, 0b Task 11) the irreversible
 *                 finalize forced after review, with one legacy value made to differ;
 *   finalized     legacy names removed only where they verified equal.
 * Pre-0b SUPCheckout lane: pre0b_seed, pre0b_switch, pre0b_rollback, pre0b_rerun.
 * Large-store cell: large_seed, large_run.
 *
 * Provider traffic: none. tests/integration/fixtures/takeover-matrix-mu-plugin.php answers
 * every UPayments request from a fixture this test stores, refuses anything else, and the
 * workflow sinkholes the UPayments hosts for the official plugin's raw cURL.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/takeover-matrix-lib.php';
require_once __DIR__ . '/fixtures/official-plugin-seed.php';

use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\Journal;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\OrderCopier;
use Simplixi\SUPCheckout\Takeover\TakeoverState;
use Simplixi\SUPCheckout\Token\CustomerTokenIdentity;

$phase = (string) getenv('SUPCHECKOUT_MATRIX_PHASE');
$version = (string) getenv('SUPCHECKOUT_MATRIX_OFFICIAL_VERSION');
$storage = (string) getenv('SUPCHECKOUT_MATRIX_STORAGE');
$wcml = getenv('SUPCHECKOUT_MATRIX_WCML') === 'yes';

/** @return array<string, mixed> */
function supcheckout_matrix_manifest() {
    wp_cache_delete(SUPCHECKOUT_MATRIX_MANIFEST, 'options');
    $manifest = get_option(SUPCHECKOUT_MATRIX_MANIFEST, null);
    supcheckout_cert_assert(is_array($manifest), 'the seed manifest exists');
    return $manifest;
}

/**
 * @param string $storage legacy or hpos.
 * @return void
 */
function supcheckout_matrix_assert_storage($storage) {
    supcheckout_cert_assert(supcheckout_matrix_hpos() === ($storage === 'hpos'), 'the authoritative order store is ' . $storage);
    supcheckout_cert_assert(get_option('woocommerce_custom_orders_table_data_sync_enabled') !== 'yes', 'compatibility sync is off');
}

/**
 * @param array<string, mixed> $status `status --format=json`.
 * @return int Orders the copy runner processed.
 */
function supcheckout_matrix_copied_orders(array $status) {
    return isset($status['totals']['orders']) ? (int) $status['totals']['orders'] : -1;
}

/**
 * The fixed shape of the rest of an official cell, shared by the verify and rerun phases.
 *
 * @param array<string, mixed> $manifest Manifest.
 * @return array<string, array<string, list<string>>> Order id => new-name lists.
 */
function supcheckout_matrix_assert_cut_over_store(array $manifest) {
    supcheckout_matrix_flush();
    supcheckout_cert_assert(!is_plugin_active(SUPCHECKOUT_MATRIX_OFFICIAL), 'the official plugin is deactivated');
    supcheckout_cert_assert(defined('SUPCHECKOUT_VERSION'), 'SUPCheckout boots fully');
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::CUT_OVER, 'state is cut_over');
    $lists = array();
    foreach ($manifest['orders'] as $order_id) {
        $order = wc_get_order((int) $order_id);
        supcheckout_cert_assert($order instanceof WC_Order, 'order ' . $order_id . ' loads');
        $verify = OrderCopier::verify($order);
        if (!$verify['ok']) {
            throw new RuntimeException('FAIL: order ' . $order_id . ' does not verify: ' . wp_json_encode($verify['mismatches']));
        }
        $lists[(string) $order_id] = supcheckout_matrix_assert_copied($order);
        if ($order->get_payment_method() !== 'upayments') {
            throw new RuntimeException('FAIL: order ' . $order_id . ': historical payment_method was rewritten to ' . $order->get_payment_method());
        }
    }
    supcheckout_cert_assert(true, 'every seeded order keeps payment_method upayments');
    supcheckout_cert_note(count($manifest['orders']) . ' seeded orders verify, legacy and new row lists equal');
    $whs = wc_get_order((int) $manifest['roles']['whs_multi']);
    $whs_legacy = OrderCopier::values($whs, 'UPayments_WHS');
    $whs_new = OrderCopier::values($whs, '_supcheckout_public_status');
    supcheckout_cert_assert(is_array($whs_legacy) && count($whs_legacy) >= 2 && $whs_new === $whs_legacy, 'the multi-valued UPayments_WHS kept every row, in order: legacy ' . wp_json_encode($whs_legacy) . ' new ' . wp_json_encode($whs_new));
    supcheckout_cert_assert(get_option(Keys::SETTINGS_OPTION) === get_option('woocommerce_upayments_settings'), 'settings copied verbatim from woocommerce_upayments_settings, the legacy option kept');
    supcheckout_cert_assert(has_action('woocommerce_api_wc_upayments') !== false && has_action('woocommerce_api_supcheckout') !== false, 'both callback routes are served');
    foreach (array('UP_PLUGIN_URL', 'UP_PLUGIN_PATH', 'UPAYMENTS_PLUGIN_FILE', 'UPayments_PLUGIN_URL', 'UPayments_PLUGIN_PATH') as $constant) {
        supcheckout_cert_assert(!defined($constant), 'the booted SUPCheckout defines no official constant: ' . $constant);
    }
    supcheckout_matrix_assert_wcml_untouched($manifest);
    return $lists;
}

/**
 * WCML gateway rules are report-only: never written, journaled or restored.
 *
 * @param array<string, mixed> $manifest Manifest.
 * @return void
 */
function supcheckout_matrix_assert_wcml_untouched(array $manifest) {
    if ($manifest['wcml'] === array()) {
        return;
    }
    $seeded = supcheckout_matrix_load('wcml-seeded.json');
    foreach ($manifest['wcml'] as $name) {
        supcheckout_cert_assert(supcheckout_matrix_raw_option($name) === $seeded[$name], 'WCML option ' . $name . ' is byte-for-byte untouched (report-only contract)');
    }
    supcheckout_cert_assert(get_option('wcml_payment_gateway_supcheckout', null) === null, 'no WCML rule was invented under supcheckout');
    $journaled = array();
    foreach (Journal::entries() as $entry) {
        if (in_array($entry['path'], $manifest['wcml'], true)) {
            $journaled[] = $entry['path'];
        }
    }
    supcheckout_cert_assert($journaled === array(), 'no journal entry for a WCML option: ' . wp_json_encode($journaled));
}

/**
 * Place an order through the registered gateway after the cutover (stubbed hosted Charge).
 *
 * @param array<string, mixed> $manifest Manifest.
 * @return int Order id.
 */
function supcheckout_matrix_place_new_order(array $manifest) {
    $gateways = WC()->payment_gateways()->payment_gateways();
    supcheckout_cert_assert(isset($gateways['supcheckout']) && !isset($gateways['upayments']), 'after cutover WooCommerce registers the gateway as supcheckout only: ' . wp_json_encode(array_keys($gateways)));
    $gateway = $gateways['supcheckout'];
    if (!WC()->session) {
        WC()->session = new WC_Session_Handler();
        WC()->session->init();
    }
    $product = new WC_Product_Simple();
    $product->set_name('Matrix post-cutover product');
    $product->set_regular_price('7.500');
    $product->set_price('7.500');
    $product->save();
    $order = wc_create_order(array('customer_id' => (int) $manifest['user_id']));
    $order->add_product($product, 1);
    $order->set_payment_method($gateway);
    $order->set_currency('KWD');
    $order->set_billing_first_name('Matrix');
    $order->set_billing_last_name('Customer');
    $order->set_billing_email('matrix-customer@example.invalid');
    $order->set_billing_phone('96512345678');
    $order->set_billing_country('KW');
    $order->calculate_totals(false);
    $order->set_status('pending');
    $order->save();

    supcheckout_matrix_fixtures(array('charge' => array('code' => 201, 'body' => (string) wp_json_encode(array(
        'status' => true,
        'data' => array('link' => 'https://sandbox.upayments.com/?session_id=matrix-post-cutover'),
    )))));
    $charge_body = null;
    add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$charge_body) {
        if (strpos((string) $url, '/api/v1/charge') !== false) {
            $charge_body = isset($args['body']) ? (string) $args['body'] : '';
        }
        return $preempt;
    }, 0, 3);
    $_POST = array();
    $result = $gateway->process_payment($order->get_id());
    supcheckout_cert_assert(is_array($result) && $result['result'] === 'success', 'the gateway takes a new order to the hosted payment page: ' . wp_json_encode($result) . ' ' . wp_json_encode(wc_get_notices()));
    $charge = json_decode((string) $charge_body, true);
    foreach (array('returnUrl', 'cancelUrl', 'notificationUrl') as $field) {
        $url = isset($charge[$field]) ? (string) $charge[$field] : (isset($charge['order'][$field]) ? (string) $charge['order'][$field] : '');
        if ($url === '' && is_array($charge)) {
            array_walk_recursive($charge, static function ($value, $key) use ($field, &$url) {
                if ($key === $field) {
                    $url = (string) $value;
                }
            });
        }
        supcheckout_cert_assert(strpos($url, 'wc-api=supcheckout') !== false, 'the new Charge carries the supcheckout callback route in ' . $field . ': ' . $url);
    }
    supcheckout_matrix_flush();
    $placed = wc_get_order($order->get_id());
    supcheckout_cert_assert($placed->get_payment_method() === 'supcheckout', 'the order placed after cutover has payment_method supcheckout');
    supcheckout_cert_assert(Keys::read_order_meta($placed, 'UPayments_order_id') !== '' && $placed->get_meta('_supcheckout_provider_order_id') !== '', 'its provider order identity is stored under the new name');
    return (int) $placed->get_id();
}

if ($phase === 'seed') {
    supcheckout_matrix_assert_storage($storage);
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_MATRIX_OFFICIAL) && !is_plugin_active('supcheckout/supcheckout.php'), 'the official plugin is active and SUPCheckout is not');
    $headers = get_plugin_data(WP_PLUGIN_DIR . '/' . SUPCHECKOUT_MATRIX_OFFICIAL, false, false);
    supcheckout_cert_assert($headers['Version'] === $version, 'the official plugin header is version ' . $version . ': ' . $headers['Version']);
    $gateways = WC()->payment_gateways()->payment_gateways();
    supcheckout_cert_assert(isset($gateways['upayments']) && strpos(get_class($gateways['upayments']), 'Simplixi') === false, 'the real official plugin registers the upayments gateway: ' . (isset($gateways['upayments']) ? get_class($gateways['upayments']) : 'none'));
    $manifest = supcheckout_matrix_seed_official($version, $wcml);
    if ($wcml) {
        $raw = array();
        foreach ($manifest['wcml'] as $name) {
            $raw[$name] = supcheckout_matrix_raw_option($name);
        }
        supcheckout_matrix_save('wcml-seeded.json', $raw);
    }
    if ($version === '3.0.0') {
        $page = (int) get_option('upayments_block_checkout_page_id');
        supcheckout_cert_assert($page > 0 && get_post_status($page) === 'publish', 'the real 3.0.0 activation published its "Checkout (Block)" page');
        supcheckout_matrix_save('block-page.json', array('page' => $page));
    }
    supcheckout_cert_assert(count($manifest['orders']) === SUPCHECKOUT_MATRIX_ORDER_TARGET, 'seeded ' . count($manifest['orders']) . ' official orders');
    $whs_rows = supcheckout_matrix_raw_order_meta((int) $manifest['roles']['whs_multi'], array('UPayments_WHS'));
    supcheckout_cert_assert(count($whs_rows) >= 2, 'the WHS order holds several UPayments_WHS rows in storage: ' . wp_json_encode($whs_rows));
    return;
}

if ($phase === 'paused') {
    $manifest = supcheckout_matrix_manifest();
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_MATRIX_OFFICIAL) && is_plugin_active('supcheckout/supcheckout.php'), 'both plugins are active');
    supcheckout_cert_assert(!defined('SUPCHECKOUT_VERSION') && !defined('SUPCHECKOUT_PLUGIN_URL') && !defined('SUPCHECKOUT_PLUGIN_PATH'), 'SUPCheckout is paused and defined none of its runtime constants');
    $official_dir = wp_normalize_path(WP_PLUGIN_DIR . '/upayments/');
    foreach (array('UP_PLUGIN_URL', 'UP_PLUGIN_PATH', 'UPAYMENTS_PLUGIN_FILE', 'UPayments_PLUGIN_URL', 'UPayments_PLUGIN_PATH') as $constant) {
        if (defined($constant)) {
            $value = (string) constant($constant);
            supcheckout_cert_assert(strpos(wp_normalize_path($value), $official_dir) === 0 || strpos($value, '/plugins/upayments/') !== false, $constant . ' is the official plugin\'s own (no shared constant): ' . $value);
        }
    }
    supcheckout_cert_assert(has_action('woocommerce_api_supcheckout') === false, 'the paused SUPCheckout registers no callback route');
    $report = supcheckout_matrix_wp_json('supcheckout takeover preflight --format=json');
    supcheckout_cert_assert($report['blocking'] === array(), 'preflight reports nothing blocking: ' . wp_json_encode($report['blocking']));
    $official = supcheckout_matrix_finding($report, 'facts', 'official_plugin_active');
    supcheckout_cert_assert(is_array($official) && $official['data']['plugins'][0]['version'] === $version, 'preflight reports the official plugin ' . $version);
    $source = supcheckout_matrix_finding($report, 'facts', 'settings_source');
    supcheckout_cert_assert($source['data']['source'] === 'woocommerce_upayments_settings', 'settings come from woocommerce_upayments_settings');
    if ($version === '3.0.0') {
        supcheckout_cert_assert(in_array('woocommerce_your_gateway_id_settings', $source['data']['sources_present'], true), 'the real 3.0.0 activation left its placeholder settings (hazard 3), reported as a second source');
    }
    supcheckout_cert_assert(is_array(supcheckout_matrix_finding($report, 'facts', 'whs_multi_valued')), 'preflight reports the multi-valued UPayments_WHS');
    supcheckout_cert_assert(is_array(supcheckout_matrix_finding($report, 'warnings', 'pending_with_open_link')), 'preflight warns about the pending order with an open link');
    supcheckout_cert_assert(is_array(supcheckout_matrix_finding($report, 'warnings', 'duplicate_renewals')), 'preflight warns about the duplicate renewals (hazard 6)');
    supcheckout_cert_assert(is_array(supcheckout_matrix_finding($report, 'warnings', 'official_debug_log_present')), 'preflight reports the debug.log the official archive ships, by path only');
    if ($storage === 'hpos') {
        supcheckout_cert_assert(is_array(supcheckout_matrix_finding($report, 'warnings', 'hpos_meta_disagreement')), 'HPOS with sync off: preflight reports values that exist only in the order tables');
    }
    $third = supcheckout_matrix_finding($report, 'warnings', 'third_party_references');
    if ($wcml) {
        foreach ($manifest['wcml'] as $name) {
            supcheckout_cert_assert(is_array($third) && in_array($name, $third['data']['unknown'], true), 'preflight reports the WCML option ' . $name . ' for review (report-only)');
        }
    } else {
        $unknown = is_array($third) ? $third['data']['unknown'] : array();
        supcheckout_cert_assert(preg_grep('/wcml/', $unknown) === array(), 'no WCML reference is reported without WCML data');
    }
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::IDLE, 'the takeover starts idle');
    supcheckout_matrix_save('snapshot-pre-switch.json', supcheckout_matrix_snapshot($manifest['orders'], array((int) $manifest['user_id']), $manifest['wcml']));
    return;
}

if ($phase === 'killed') {
    $manifest = supcheckout_matrix_manifest();
    supcheckout_matrix_flush();
    $state = TakeoverState::get();
    supcheckout_cert_assert($state['status'] === TakeoverState::COPYING && $state['phase'] === 'orders', 'the killed run left the store copying orders');
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_MATRIX_OFFICIAL), 'the killed run deactivated nothing');
    $orders = $manifest['orders'];
    $cursor = (int) $state['cursor'];
    supcheckout_cert_note('cursor after the kill: ' . $cursor);
    supcheckout_cert_assert($cursor >= (int) $orders[49] && $cursor < (int) $orders[56], 'the kill hit the second batch of 50: the cursor stopped before the order being copied');
    $in_flight = wc_get_order((int) $orders[56]);
    supcheckout_cert_assert($in_flight->get_meta('_supcheckout_provider_order_id') !== '', 'the order being copied at the kill was already saved (it is re-processed on resume)');
    $lock = get_option('supcheckout_takeover_copy_runner_v1', '');
    supcheckout_cert_assert(is_string($lock) && preg_match('/^([0-9]+):[a-z0-9]+$/', $lock, $m) === 1 && (int) $m[1] >= time(), 'the killed runner still holds its lock; the resumed run must outwait it');
    return;
}

if ($phase === 'verify') {
    $manifest = supcheckout_matrix_manifest();
    $lists = supcheckout_matrix_assert_cut_over_store($manifest);
    $status = supcheckout_matrix_wp_json('supcheckout takeover status --format=json');
    supcheckout_cert_assert(supcheckout_matrix_copied_orders($status) === count($manifest['orders']), 'the interrupted and resumed copy processed every order exactly once in its totals: ' . wp_json_encode($status['totals']));
    supcheckout_cert_assert(array_sum(array_map(static function ($group) {
        return (int) $group['count'];
    }, $status['mismatches'])) === 0, 'no mismatch was recorded');
    supcheckout_cert_assert(Journal::entries() !== array(), 'the cutover is journaled');
    supcheckout_matrix_save('first-run.json', array('orders' => supcheckout_matrix_copied_orders($status), 'lists' => $lists));

    $new_order = supcheckout_matrix_place_new_order($manifest);
    update_option('supcheckout_matrix_post_cutover_order', $new_order, false);

    $parent_id = (int) $manifest['roles']['subscription_parent'];
    $parent = wc_get_order($parent_id);
    supcheckout_matrix_renewal_fixtures(SUPCHECKOUT_MATRIX_CUSTOMER_TOKEN, SUPCHECKOUT_MATRIX_CARD_TOKEN, wc_format_decimal($parent->get_total(), 3), 'matrix-ref-parent');
    supcheckout_matrix_assert_renewal_once($parent_id, 2);

    // The legacy-route webhook for the pending pre-switch order: the provider status it
    // gets back is authenticated (Bearer, provider host), bound to the order, amount and currency.
    $pending = wc_get_order((int) $manifest['roles']['pending_open_link']);
    supcheckout_matrix_fixtures(array('get-payment-status/matrix-webhook-track' => array('code' => 201, 'body' => (string) wp_json_encode(array(
        'status' => true,
        'data' => array('transaction' => array(
            'result' => 'CAPTURED',
            'track_id' => 'matrix-webhook-track',
            'merchant_requested_order_id' => (string) Keys::read_order_meta($pending, 'UPayments_order_id'),
            'total_price' => wc_format_decimal($pending->get_total(), 3),
            'currency_type' => 'KWD',
            'reference' => (string) $pending->get_id(),
            'payment_id' => 'matrix-webhook-pay',
            'payment_type' => 'knet',
        )),
    )))));
    supcheckout_matrix_assert_no_denied_provider_call();
    return;
}

if ($phase === 'webhook') {
    $manifest = supcheckout_matrix_manifest();
    supcheckout_matrix_flush();
    $pending = wc_get_order((int) $manifest['roles']['pending_open_link']);
    supcheckout_cert_assert($pending->is_paid() && $pending->get_status() === 'processing', 'the legacy-route webhook paid the pending pre-switch order');
    supcheckout_cert_assert($pending->get_transaction_id() === 'matrix-webhook-pay', 'its transaction is the provider payment id');
    supcheckout_cert_assert($pending->get_meta('_supcheckout_payment_id') === 'matrix-webhook-pay', 'the payment id is written under the new name');
    supcheckout_cert_assert($pending->get_payment_method() === 'upayments', 'its historical payment_method is unchanged');
    supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_PAYMENT_COMPLETE, (string) $pending->get_id()) === 1, 'payment_complete fired exactly once over three deliveries (exactly-once)');
    supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'get-payment-status/matrix-webhook-track') === 1, 'one authenticated status lookup; the repeated deliveries were answered locally');
    supcheckout_matrix_assert_no_denied_provider_call();
    return;
}

if ($phase === 'rollback') {
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::CUT_OVER, 'the store is cut over before the rollback');
    $rollback = supcheckout_matrix_wp('supcheckout takeover rollback --yes --reactivate-official');
    supcheckout_cert_note('rollback output: ' . $rollback->stdout);
    supcheckout_cert_assert($rollback->return_code === CliCommands::OK, 'rollback --yes --reactivate-official exits 0: ' . $rollback->stdout . $rollback->stderr);
    foreach (array('rolled_back', 'copies_removed', 'preflight_ok', 'official_reactivated') as $step) {
        supcheckout_cert_assert(strpos($rollback->stdout, $step) !== false, 'the rollback reached ' . $step);
    }
    supcheckout_cert_assert(stripos($rollback->stdout . $rollback->stderr, 'Cannot redeclare') === false && stripos($rollback->stderr, 'Fatal error') === false, 'the real official plugin was never loaded into the booted rollback process');
    $next = supcheckout_matrix_wp('eval "echo defined(\'SUPCHECKOUT_VERSION\') ? \'booted\' : \'paused\';"');
    supcheckout_cert_assert($next->return_code === 0 && trim($next->stdout) === 'paused', 'the next request runs the real official plugin with SUPCheckout paused: ' . $next->stdout . $next->stderr);
    return;
}

if ($phase === 'rolled_back') {
    $manifest = supcheckout_matrix_manifest();
    supcheckout_matrix_flush();
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_MATRIX_OFFICIAL) && !defined('SUPCHECKOUT_VERSION'), 'the official plugin is active again and SUPCheckout paused');
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::PREFLIGHT_OK, 'rolled_back -> preflight_ok once the copies are removed');
    $pending_id = (int) $manifest['roles']['pending_open_link'];
    $before = supcheckout_matrix_load('snapshot-pre-switch.json');
    $after = supcheckout_matrix_snapshot($manifest['orders'], array((int) $manifest['user_id']), $manifest['wcml']);
    supcheckout_matrix_assert_restored($before, $after, array($pending_id));
    foreach ($manifest['orders'] as $order_id) {
        $order = wc_get_order((int) $order_id);
        foreach (array_merge(array_values(Keys::order_meta()), Keys::takeover_markers()) as $new) {
            if ($order->get_meta($new, false) !== array()) {
                throw new RuntimeException('FAIL: order ' . $order_id . ': the new-name copy ' . $new . ' is left after the rollback');
            }
        }
    }
    supcheckout_cert_assert(true, 'no new-name copy or takeover marker is left on any seeded order');
    supcheckout_cert_assert(get_option(Keys::SETTINGS_OPTION, null) === null, 'the settings copy is removed');
    $paid = wc_get_order($pending_id);
    supcheckout_cert_assert($paid->is_paid() && $paid->get_transaction_id() === 'matrix-webhook-pay' && $paid->get_meta('UPayments_PaymentID') === 'matrix-webhook-pay', 'the order the webhook paid after cutover is still paid, its payment id on the legacy name');
    foreach (array('processing', 'completed') as $status) {
        supcheckout_cert_assert(wc_get_order((int) $manifest['roles']['status_' . $status])->is_paid(), 'the pre-switch ' . $status . ' order is still paid');
    }
    $placed = wc_get_order((int) get_option('supcheckout_matrix_post_cutover_order'));
    supcheckout_cert_assert($placed->get_payment_method() === 'upayments' && $placed->get_meta('UPayments_order_id') !== '', 'the order placed after cutover is back on upayments with its provider identity on the legacy name');
    $legacy_claims = supcheckout_matrix_claims('upayments_billing_attempts', (int) $manifest['roles']['subscription_parent']);
    supcheckout_cert_assert(count($legacy_claims) === 1 && $legacy_claims[0]['state'] === 'held', 'the held renewal cycle is back-synced to the legacy billing table, so it can never be claimed again');
    supcheckout_matrix_assert_wcml_untouched($manifest);
    return;
}

if ($phase === 'rerun') {
    // This process booted with the official plugin active (SUPCheckout paused); the
    // switched store is verified by the next phase, in a fresh process.
    $run = supcheckout_matrix_wp('supcheckout takeover run --yes --deactivate-official');
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'run again after the rollback cuts over: ' . $run->stdout . $run->stderr);
    return;
}

if ($phase === 'rerun_verify') {
    $manifest = supcheckout_matrix_manifest();
    $lists = supcheckout_matrix_assert_cut_over_store($manifest);
    $first = supcheckout_matrix_load('first-run.json');
    $status = supcheckout_matrix_wp_json('supcheckout takeover status --format=json');
    supcheckout_cert_assert(supcheckout_matrix_copied_orders($status) === $first['orders'] + 1, 'the second copy processed the same orders plus the one placed after the first cutover');
    $changed = array((string) $manifest['roles']['pending_open_link']);
    foreach ($first['lists'] as $order_id => $order_lists) {
        if (!in_array((string) $order_id, $changed, true)) {
            supcheckout_cert_assert($lists[$order_id] === $order_lists, 'order ' . $order_id . ': the second copy equals the first');
        }
    }
    $claims = supcheckout_matrix_claims('supcheckout_billing_attempts', (int) $manifest['roles']['subscription_parent']);
    supcheckout_cert_assert(count($claims) === 1 && $claims[0]['state'] === 'held', 'the held cycle was copied back to supcheckout_billing_attempts');
    $outcome = supcheckout_matrix_renew((int) $manifest['roles']['subscription_parent']);
    supcheckout_cert_assert($outcome === 'not_eligible' && supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === 1, 'automatic renewal charges stay blocked after rollback and run: no auto-deduct request');
    // Fix A1-01: the engine behind the gate, forced open, still refuses the held cycle.
    $outcome = supcheckout_matrix_renew_gate_open((int) $manifest['roles']['subscription_parent']);
    supcheckout_cert_assert($outcome !== 'held' && $outcome !== 'dispatching_or_ambiguous' && supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === 1, 'gate forced open: the held cycle is never charged again after rollback and run');
    return;
}

/**
 * Step 9 runs exactly when this checkout ships Finalize (Sub-project 0b Task 11). The guard
 * is the source file, never the CLI's answer: a renamed command, a boot failure or a help
 * quirk must fail the step, not skip it.
 *
 * @return bool
 */
function supcheckout_matrix_has_finalize() {
    $has = is_file(dirname(__DIR__, 2) . '/src/Takeover/Finalize.php');
    if (!$has) {
        supcheckout_cert_note('this checkout has no src/Takeover/Finalize.php (Sub-project 0b Task 11 not merged): step 9 finalize is skipped');
    }
    return $has;
}

if ($phase === 'finalize') {
    if (!supcheckout_matrix_has_finalize()) {
        return;
    }
    $help = supcheckout_matrix_wp('help supcheckout takeover finalize');
    supcheckout_cert_assert($help->return_code === 0 && strpos($help->stdout, '--force-after-review') !== false, 'the build ships Finalize, so `wp supcheckout takeover finalize` is registered with --force-after-review: ' . $help->stdout . $help->stderr);
    $manifest = supcheckout_matrix_manifest();
    $early = supcheckout_matrix_wp('supcheckout takeover finalize --yes');
    supcheckout_cert_assert($early->return_code === CliCommands::STATE, 'finalize inside the 30 days without --force-after-review exits 6: ' . $early->stdout);
    // One legacy value differs from its copy (a third party wrote it): it must be kept.
    global $wpdb;
    $diverged = (int) $manifest['roles']['status_completed'];
    if (supcheckout_matrix_hpos()) {
        $wpdb->query($wpdb->prepare('UPDATE %i SET meta_value = %s WHERE order_id = %d AND meta_key = %s', $wpdb->prefix . 'wc_orders_meta', 'matrix-diverged', $diverged, 'UPayments_Auth'));
    } else {
        $wpdb->query($wpdb->prepare('UPDATE %i SET meta_value = %s WHERE post_id = %d AND meta_key = %s', $wpdb->postmeta, 'matrix-diverged', $diverged, 'UPayments_Auth'));
    }
    update_option('supcheckout_matrix_diverged_order', $diverged, false);
    $finalize = supcheckout_matrix_wp('supcheckout takeover finalize --yes --force-after-review');
    supcheckout_cert_note('finalize output: ' . $finalize->stdout);
    supcheckout_cert_assert($finalize->return_code === CliCommands::CONFLICTS, 'finalize --yes --force-after-review finalizes and exits 8 for the kept value: ' . $finalize->stdout . $finalize->stderr);
    supcheckout_cert_assert(preg_match('/kept: \S+ UPayments_Auth /', $finalize->stdout) === 1, 'the differing UPayments_Auth is reported as kept');
    return;
}

if ($phase === 'finalized') {
    if (!supcheckout_matrix_has_finalize()) {
        return;
    }
    $manifest = supcheckout_matrix_manifest();
    supcheckout_matrix_flush();
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::FINALIZED, 'state is finalized');
    $diverged = (int) get_option('supcheckout_matrix_diverged_order');
    foreach ($manifest['orders'] as $order_id) {
        $order = wc_get_order((int) $order_id);
        foreach (Keys::order_meta() as $legacy => $new) {
            $legacy_rows = OrderCopier::values($order, $legacy);
            if ((int) $order_id === $diverged && $legacy === 'UPayments_Auth') {
                supcheckout_cert_assert($legacy_rows === array('matrix-diverged') && OrderCopier::values($order, $new) !== array(), 'the differing legacy value is kept beside its copy');
                continue;
            }
            if ($legacy_rows !== array()) {
                throw new RuntimeException('FAIL: order ' . $order_id . ': legacy ' . $legacy . ' was kept although it verified equal');
            }
        }
        if ($order->get_meta(Keys::TAKEOVER_COPY_MARKER, false) !== array()) {
            throw new RuntimeException('FAIL: order ' . $order_id . ': the takeover marker survived finalize');
        }
    }
    supcheckout_cert_assert(true, 'finalize removed every verified-equal legacy order key and every takeover marker');
    supcheckout_cert_assert(has_action('woocommerce_api_wc_upayments') !== false, 'the legacy callback route is kept forever');
    supcheckout_cert_assert(!wp_next_scheduled('upay_hourly_cron_job'), 'the official hourly cron is cleared');
    if ($version === '3.0.0') {
        $page = (int) supcheckout_matrix_load('block-page.json')['page'];
        supcheckout_cert_assert($page > 0 && get_post_status($page) === 'publish', 'the official "Checkout (Block)" page is reported, never removed');
    }
    supcheckout_matrix_assert_wcml_untouched($manifest);
    return;
}

// ---------------------------------------------------------------------------------------
// Pre-0b SUPCheckout lane (no official plugin).
// ---------------------------------------------------------------------------------------

/**
 * Saved-card access for the seeded customer, read with the real CustomerTokenIdentity.
 *
 * @param array<string, mixed> $manifest Manifest.
 * @return array<string, mixed>|null
 */
function supcheckout_matrix_saved_cards(array $manifest) {
    $calls = 0;
    $cards = CustomerTokenIdentity::get_saved_cards_for_current_user((int) $manifest['user_id'], SUPCHECKOUT_MATRIX_API_KEY, true, static function ($token) use (&$calls, $manifest) {
        ++$calls;
        supcheckout_cert_assert($token === $manifest['token'], 'saved-card retrieval uses the H12 customer token');
        return array('result' => 'success', 'data' => array(array('token' => SUPCHECKOUT_MATRIX_CARD_TOKEN)));
    });
    supcheckout_cert_assert($calls <= 1, 'at most one bounded retrieval');
    return $cards;
}

/**
 * Hide every legacy H12 name inside a database transaction, read saved cards, roll back:
 * proves the new names alone verify.
 *
 * @param array<string, mixed> $manifest Manifest.
 * @return void
 */
function supcheckout_matrix_assert_cards_under_new_names_only(array $manifest) {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE user_id = %d AND meta_key LIKE %s', $wpdb->usermeta, (int) $manifest['user_id'], $wpdb->esc_like('_upay_customer_token_v2_b') . '%'));
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, 'upayments_token_identity_secret_v2'));
        wp_cache_flush();
        supcheckout_cert_assert(get_user_meta((int) $manifest['user_id'], '_upay_customer_token_v2_b1_' . $manifest['scope'], true) === '', 'inside the probe the legacy provenance row is gone');
        $cards = supcheckout_matrix_saved_cards($manifest);
        supcheckout_cert_assert(is_array($cards) && $cards['result'] === 'success', 'saved-card access verifies under the new names alone (provenance and secret copied)');
    } finally {
        $wpdb->query('ROLLBACK');
        wp_cache_flush();
    }
    supcheckout_cert_assert(supcheckout_matrix_raw_option('upayments_token_identity_secret_v2') !== null, 'the probe rolled back: the legacy secret is intact');
}

if ($phase === 'pre0b_seed') {
    supcheckout_cert_assert(defined('SUPCHECKOUT_VERSION') && !class_exists('Simplixi\\SUPCheckout\\Takeover\\TakeoverState'), 'the pre-0b SUPCheckout package is active (no takeover engine)');
    update_option('woocommerce_currency', 'KWD');
    update_option('woocommerce_upayments_settings', supcheckout_matrix_official_settings('3.1.1'));
    delete_option(CustomerTokenIdentity::SECRET_OPTION);
    $user_id = (int) wp_insert_user(array('user_login' => 'matrix-h12-customer', 'user_pass' => wp_generate_password(24, true, true), 'user_email' => 'matrix-h12@example.invalid', 'role' => 'customer'));
    // The real H12 path: the provider confirms the submitted canonical token with a 201.
    $identity = CustomerTokenIdentity::get_or_establish_token($user_id, SUPCHECKOUT_MATRIX_API_KEY, true, static function ($candidate) {
        return array('transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => wp_json_encode(array('status' => true, 'data' => array('customerUniqueToken' => $candidate))));
    });
    supcheckout_cert_assert($identity['success'] === true && $identity['established'] === true, 'the pre-0b runtime established an H12-provenanced customer token');
    $scope = (string) $identity['scope'];
    $key = '_upay_customer_token_v2_b1_' . $scope;
    supcheckout_cert_assert(is_array(get_user_meta($user_id, $key, true)), 'the provenance lives at ' . $key);
    update_user_meta($user_id, 'simplixpay_upayments_migration_v1', array('version' => 1, 'status' => 'migrated', 'token_digest' => hash('sha256', $identity['token']), 'scope' => $scope, 'generation_id' => (string) $identity['generation_id'], 'completed_at_gmt' => time()));
    update_user_meta($user_id, '_simplixpay_upayments_migration_result_v1', array('version' => 1, 'batch_digest' => hash('sha256', 'matrix'), 'position' => 0, 'next_offset' => 1, 'input_count' => 1, 'dry_run' => false, 'mode' => 'test'));

    \Simplixi\SUPCheckout\Subscription\Presentation::register_product_class();
    $product = new WCProductCustomType();
    $product->set_name('Matrix H12 subscription');
    $product->set_regular_price('10.000');
    $product->set_price('10.000');
    $product->save();
    $parent = supcheckout_matrix_order(array('customer_id' => $user_id), 'processing', array(
        'UPayments_order_id' => md5('matrix-h12-parent'),
        'UPayments_PaymentID' => 'matrix-h12-pay',
        'UPayments_Ref' => 'matrix-h12-ref',
        'UPayments_AutoDeduction' => 'no',
        '_upay_customer_unique_token' => $identity['token'],
        '_upay_credit_card_token' => SUPCHECKOUT_MATRIX_CARD_TOKEN,
        '_upay_subscription_plan' => 'monthly',
        '_upay_subscription_interval' => '1',
        '_upay_subscription_status' => 'active',
        '_upay_last_billed_at' => gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS),
        '_upay_verified_capture' => '1',
    ), array($product));

    // The billing-attempts table with history: one resolved and one held earlier cycle.
    supcheckout_cert_assert(\Simplixi\SUPCheckout\Subscription\Cron\CycleClaim::maybe_install(), 'the pre-0b billing-attempts table is installed');
    global $wpdb;
    foreach (array('resolved' => 'a', 'held' => 'b') as $state => $fill) {
        $wpdb->insert($wpdb->prefix . 'upayments_billing_attempts', array(
            'cycle_key' => str_repeat($fill, 64), 'parent_order_id' => $parent->get_id(), 'owner_token' => 'matrix-owner-' . $fill,
            'state' => $state, 'cycle_due_gmt' => gmdate('Y-m-d H:i:s', time() - 70 * DAY_IN_SECONDS), 'created_gmt' => gmdate('Y-m-d H:i:s', time() - 70 * DAY_IN_SECONDS),
            'updated_gmt' => gmdate('Y-m-d H:i:s', time() - 69 * DAY_IN_SECONDS), 'expected_amount' => '10', 'expected_currency' => 'KWD',
        ));
    }
    $manifest = array('user_id' => $user_id, 'token' => $identity['token'], 'scope' => $scope, 'orders' => array($parent->get_id()), 'roles' => array('subscription_parent' => $parent->get_id()), 'wcml' => array());
    update_option(SUPCHECKOUT_MATRIX_MANIFEST, $manifest, false);
    $cards = supcheckout_matrix_saved_cards($manifest);
    supcheckout_cert_assert(is_array($cards) && $cards['result'] === 'success', 'saved-card access verifies on the pre-0b runtime');
    supcheckout_matrix_save('snapshot-pre-switch.json', supcheckout_matrix_snapshot($manifest['orders'], array($user_id), array()));
    return;
}

if ($phase === 'pre0b_switch' || $phase === 'pre0b_rerun') {
    $manifest = supcheckout_matrix_manifest();
    supcheckout_cert_assert(class_exists(TakeoverState::class), 'the 0b package was updated in place over the pre-0b one');
    if ($phase === 'pre0b_switch') {
        $before = supcheckout_matrix_load('snapshot-pre-switch.json');
        supcheckout_matrix_assert_restored($before, supcheckout_matrix_snapshot($manifest['orders'], array((int) $manifest['user_id']), array()), array());
        supcheckout_cert_note('the in-place update changed no persisted value');
        $report = supcheckout_matrix_wp_json('supcheckout takeover preflight --format=json');
        supcheckout_cert_assert($report['blocking'] === array(), 'preflight reports nothing blocking: ' . wp_json_encode($report['blocking']));
    }
    $run = supcheckout_matrix_wp('supcheckout takeover run --yes');
    supcheckout_cert_note('run output: ' . $run->stdout);
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'run --yes cuts the pre-0b store over: ' . $run->stdout . $run->stderr);
    supcheckout_matrix_flush();
    supcheckout_cert_assert(TakeoverState::is_cut_over(), 'state is cut_over');
    $user_id = (int) $manifest['user_id'];
    supcheckout_cert_assert(get_user_meta($user_id, '_supcheckout_customer_token_v2_b1_' . $manifest['scope'], true) === get_user_meta($user_id, '_upay_customer_token_v2_b1_' . $manifest['scope'], true), 'the H12 provenance row is copied verbatim to its new name');
    supcheckout_cert_assert(get_option('supcheckout_token_identity_secret_v2') === get_option('upayments_token_identity_secret_v2'), 'the token-identity secret is copied verbatim');
    foreach (Keys::user_meta() as $legacy => $new) {
        supcheckout_cert_assert(get_user_meta($user_id, $new, true) === get_user_meta($user_id, $legacy, true) && get_user_meta($user_id, $new, true) !== '', 'migration ledger ' . $legacy . ' copied to ' . $new);
    }
    supcheckout_cert_assert(CustomerTokenIdentity::get_user_meta_key('1', $manifest['scope']) === '_supcheckout_customer_token_v2_b1_' . $manifest['scope'], 'after cutover the provenance key in use is the new name');
    supcheckout_matrix_assert_cards_under_new_names_only($manifest);
    $parent_id = (int) $manifest['roles']['subscription_parent'];
    $history = supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id);
    $states = array_column($history, 'state');
    sort($states);
    $parent = wc_get_order($parent_id);
    supcheckout_matrix_renewal_fixtures((string) $manifest['token'], SUPCHECKOUT_MATRIX_CARD_TOKEN, wc_format_decimal($parent->get_total(), 3), 'matrix-h12-ref');
    if ($phase === 'pre0b_switch') {
        supcheckout_cert_assert($states === array('held', 'resolved'), 'the billing history is copied to supcheckout_billing_attempts: ' . wp_json_encode($states));
        // Fix A1-01: automatic renewal charges are blocked: the due cycle sends nothing and
        // claims nothing.
        $calls_before = supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct');
        supcheckout_cert_assert(supcheckout_matrix_renew($parent_id) === 'not_eligible', 'the due renewal is not charged while its success cannot be verified');
        supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === $calls_before, 'zero auto-deduct requests');
        supcheckout_cert_assert(count(supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id)) === 2, 'no claim row is written for the due cycle');
        supcheckout_cert_assert(supcheckout_matrix_paused_notes($parent_id) === 1, 'exactly one paused-renewal note on the parent');
        // Review I1: the parent's billing history holds a held cycle (a possible earlier debit).
        supcheckout_matrix_assert_paused_note_kind($parent_id, true);
        // Gate forced open: the due cycle claims in the new table, once, and is never charged twice.
        supcheckout_cert_assert(supcheckout_matrix_renew_gate_open($parent_id) === 'held', 'gate forced open: the renewal claims its cycle and is held (auto-deduct capture stays fail-closed)');
        supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === $calls_before + 1, 'gate forced open: exactly one auto-deduct request');
        supcheckout_cert_assert(count(supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id)) === 3, 'the new cycle is claimed in supcheckout_billing_attempts');
        supcheckout_cert_assert(count(supcheckout_matrix_claims('upayments_billing_attempts', $parent_id)) === 2, 'the legacy table is not written while cut over');
        supcheckout_matrix_save('pre0b-first.json', array('claims' => supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id)));
    } else {
        $first = supcheckout_matrix_load('pre0b-first.json');
        supcheckout_cert_assert(supcheckout_matrix_claims('supcheckout_billing_attempts', $parent_id) === $first['claims'], 'rollback and run again leave exactly the same billing rows in supcheckout_billing_attempts');
        $calls_before = supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct');
        supcheckout_cert_assert(supcheckout_matrix_renew($parent_id) === 'not_eligible', 'automatic renewal charges stay blocked after rollback and run');
        supcheckout_cert_assert(supcheckout_matrix_paused_notes($parent_id) === 1, 'still exactly one paused-renewal note on the parent');
        supcheckout_matrix_renew_gate_open($parent_id);
        supcheckout_cert_assert(supcheckout_matrix_counter(SUPCHECKOUT_MATRIX_CALLS, 'auto-deduct') === $calls_before, 'gate forced open: the cycle held before the rollback is never charged again');
    }
    supcheckout_matrix_assert_no_denied_provider_call();
    return;
}

if ($phase === 'pre0b_rollback') {
    $manifest = supcheckout_matrix_manifest();
    $rollback = supcheckout_matrix_wp('supcheckout takeover rollback --yes');
    supcheckout_cert_note('rollback output: ' . $rollback->stdout);
    supcheckout_cert_assert($rollback->return_code === CliCommands::OK, 'rollback --yes exits 0: ' . $rollback->stdout . $rollback->stderr);
    supcheckout_matrix_flush();
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::PREFLIGHT_OK, 'rolled back to preflight_ok');
    $before = supcheckout_matrix_load('snapshot-pre-switch.json');
    $after = supcheckout_matrix_snapshot($manifest['orders'], array((int) $manifest['user_id']), array());
    $before_rows = $before['billing_legacy'];
    $before['billing_legacy'] = null;
    supcheckout_matrix_assert_restored($before, $after, array());
    $now = array();
    foreach ($after['billing_legacy'] as $row) {
        $now[$row['cycle_key']] = $row;
    }
    foreach ($before_rows as $row) {
        supcheckout_cert_assert($now[$row['cycle_key']] === $row, 'pre-switch billing row restored byte for byte');
    }
    supcheckout_cert_assert(count($now) === count($before_rows) + 1, 'the cycle claimed after cutover is back-synced to the legacy table');
    supcheckout_cert_assert(get_user_meta((int) $manifest['user_id'], '_supcheckout_customer_token_v2_b1_' . $manifest['scope'], false) === array(), 'the new provenance copy is removed');
    $cards = supcheckout_matrix_saved_cards($manifest);
    supcheckout_cert_assert(is_array($cards) && $cards['result'] === 'success', 'saved-card access verifies under the legacy names after the rollback');
    return;
}

// ---------------------------------------------------------------------------------------
// Large-store cell.
// ---------------------------------------------------------------------------------------

if ($phase === 'large_seed') {
    supcheckout_matrix_assert_storage($storage);
    $count = (int) getenv('SUPCHECKOUT_MATRIX_LARGE_ORDERS');
    supcheckout_cert_assert($count >= 10000, 'the large cell seeds at least 10,000 orders');
    $started = time();
    supcheckout_matrix_seed_large($count);
    supcheckout_cert_note('seeded ' . $count . ' orders in ' . (time() - $started) . ' s');
    return;
}

if ($phase === 'large_run') {
    $manifest = supcheckout_matrix_manifest();
    $budget = (int) getenv('SUPCHECKOUT_MATRIX_COPY_BUDGET');
    $started = microtime(true);
    $run = supcheckout_matrix_wp('supcheckout takeover run --yes --deactivate-official');
    $elapsed = microtime(true) - $started;
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'the large store cuts over: ' . substr($run->stdout, -2000) . $run->stderr);
    supcheckout_cert_note(sprintf('copy + verify + cutover of %d orders: %.1f s (budget %d s)', $manifest['orders_count'], $elapsed, $budget));
    supcheckout_cert_assert($elapsed <= $budget, 'the copy completes within the CI budget');
    preg_match_all('/takeover copy: phase=orders cursor=[0-9]+ orders=([0-9]+)/', $run->stdout, $matches);
    $totals = array_map('intval', $matches[1]);
    supcheckout_cert_assert(count($totals) >= (int) ceil($manifest['orders_count'] / 50), 'one progress line per batch: ' . count($totals));
    $previous = 0;
    $largest = 0;
    foreach ($totals as $total) {
        $largest = max($largest, $total - $previous);
        $previous = $total;
    }
    supcheckout_cert_assert($largest <= 50, 'no copy batch processed more than 50 orders (largest ' . $largest . ')');
    $status = supcheckout_matrix_wp_json('supcheckout takeover status --format=json');
    supcheckout_cert_assert($status['status'] === 'cut_over' && supcheckout_matrix_copied_orders($status) === (int) $manifest['orders_count'], 'every order was copied and verified: ' . wp_json_encode($status['totals']));
    return;
}

throw new RuntimeException('Unknown SUPCHECKOUT_MATRIX_PHASE: ' . $phase);
