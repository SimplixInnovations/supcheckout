<?php
/**
 * Sub-project 0b Task 9: `wp supcheckout takeover` on real WordPress/WooCommerce while the
 * official UPayments plugin is active and SUPCheckout is therefore paused.
 *
 * The compatibility workflow installs tests/integration/fixtures/official-upayments-stub.php
 * as wp-content/plugins/upayments/UPayments.php (activated) and
 * tests/integration/fixtures/takeover-http-mu-plugin.php as a must-use plugin, then runs:
 *
 *   SUPCHECKOUT_CERT_PHASE=paused  this file: the paused boot registered only the CLI;
 *                                  seeds two orders and the settings; runs `preflight`
 *                                  and `run --yes --deactivate-official` as real WP-CLI
 *                                  commands in fresh (paused) processes;
 *   SUPCHECKOUT_CERT_PHASE=verify  this file again, in a fresh process after the switch:
 *                                  state cut_over, new keys present, legacy keys intact,
 *                                  historical payment_method unchanged, the official
 *                                  plugin deactivated and SUPCheckout fully booted;
 *   SUPCHECKOUT_CERT_PHASE=rollback    (Task 10) seeds an order taken through SUPCheckout
 *                                  after the switch, then runs `rollback --yes
 *                                  --reactivate-official` as a real WP-CLI command;
 *   SUPCHECKOUT_CERT_PHASE=rolled_back in a fresh process: preflight_ok, legacy keys and
 *                                  settings intact, copies and cron twins removed, the
 *                                  post-cutover order back on upayments and still paid,
 *                                  the official plugin active again and SUPCheckout paused.
 *   SUPCHECKOUT_CERT_PHASE=second_cycle abort out of verified, then a rollback with a later
 *                                  edit (exit 8);
 *   SUPCHECKOUT_CERT_PHASE=finalize    (Task 11) cuts over a third time, then `finalize`:
 *                                  refused before 30 days (exit 6), a read-only dry run, the
 *                                  irreversible warning without a terminal (exit 1), and
 *                                  `finalize --yes --force-after-review`;
 *   SUPCHECKOUT_CERT_PHASE=finalized   in a fresh process: finalized, verified-equal legacy
 *                                  keys removed and still read through the new names, the
 *                                  legacy callback route served, rollback/abort/run refused.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

const SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL = 'upayments/UPayments.php';
const SUPCHECKOUT_TAKEOVER_CERT_ORDERS = 'supcheckout_takeover_cert_orders';
const SUPCHECKOUT_TAKEOVER_CERT_POST_CUTOVER = 'supcheckout_takeover_cert_post_cutover_order';

/**
 * Every hook name on which a SUPCheckout class has a callback.
 *
 * @return list<string>
 */
function supcheckout_takeover_cert_own_hooks() {
    global $wp_filter;
    $hooks = array();
    foreach ((array) $wp_filter as $hook => $registry) {
        $callbacks = is_object($registry) && isset($registry->callbacks) ? $registry->callbacks : array();
        foreach ($callbacks as $by_priority) {
            foreach ($by_priority as $entry) {
                $function = isset($entry['function']) ? $entry['function'] : null;
                $owner = '';
                if (is_array($function) && isset($function[0])) {
                    $owner = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
                } elseif (is_string($function)) {
                    $owner = $function;
                } elseif ($function instanceof Closure) {
                    $reflection = new ReflectionFunction($function);
                    $owner = (string) $reflection->getFileName();
                }
                if (strpos($owner, 'Simplixi\\SUPCheckout\\') === 0 || strpos(str_replace('\\', '/', $owner), '/supcheckout/') !== false) {
                    $hooks[] = (string) $hook;
                }
            }
        }
    }
    $hooks = array_values(array_unique($hooks));
    sort($hooks);
    return $hooks;
}

/**
 * Run `wp <command>` in a fresh process, so it boots exactly as an operator's would.
 *
 * @param string $command Command after `wp`.
 * @return object{stdout:string, stderr:string, return_code:int}
 */
function supcheckout_takeover_cert_wp($command) {
    return WP_CLI::runcommand($command, array('return' => 'all', 'launch' => true, 'exit_error' => false));
}

/**
 * The step names a takeover command printed on its `takeover <command> steps:` line.
 *
 * @param string $stdout  Command output.
 * @param string $command finalize, rollback, ...
 * @return list<string>
 */
function supcheckout_takeover_cert_steps($stdout, $command) {
    if (preg_match('/^takeover ' . preg_quote($command, '/') . ' steps: (.*)$/m', $stdout, $match) !== 1) {
        return array();
    }
    return array_map('trim', explode(',', $match[1]));
}

/**
 * Independently of Finalize: how many (order, legacy key) pairs hold a new key with the
 * identical value list, i.e. exactly what finalize removes as `order_meta`.
 *
 * @return int
 */
function supcheckout_takeover_cert_equal_order_meta() {
    $values = static function ($order, $key) {
        // get_meta() keys the list by its position in the order's meta array, so compare
        // the ordered values only.
        return array_values(array_map(static function ($meta) {
            return $meta->value;
        }, $order->get_meta($key, false, 'edit')));
    };
    $count = 0;
    foreach (wc_get_orders(array('limit' => -1, 'return' => 'ids', 'type' => 'shop_order')) as $order_id) {
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof WC_Order) {
            continue;
        }
        foreach (Keys::order_meta() as $legacy => $new_key) {
            $legacy_values = $values($order, $legacy);
            if ($legacy_values !== array() && $legacy_values === $values($order, $new_key)) {
                ++$count;
            }
        }
    }
    return $count;
}

$phase = (string) getenv('SUPCHECKOUT_CERT_PHASE');

if ($phase === 'paused') {
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is active');
    supcheckout_cert_assert(!defined('SUPCHECKOUT_VERSION'), 'SUPCheckout is paused: Plugin::boot() did not run');
    // before_woocommerce_init carries the full boot's WooCommerce feature declarations:
    // without them WooCommerce shows the paused plugin as "Incompatible with WooCommerce
    // features" (owner staging rehearsal).
    supcheckout_cert_assert(
        supcheckout_takeover_cert_own_hooks() === array('admin_notices', 'before_woocommerce_init'),
        'the paused boot registered no gateway, Blocks, WC-API route, cron or other hook (only the conflict notice and the WooCommerce feature declarations): ' . wp_json_encode(supcheckout_takeover_cert_own_hooks())
    );
    $own_basename = '';
    foreach ((array) get_option('active_plugins', array()) as $active_plugin) {
        if (is_string($active_plugin) && basename($active_plugin) === 'supcheckout.php') {
            $own_basename = $active_plugin;
        }
    }
    $paused_features = $own_basename === '' ? array() : Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_features_for_plugin($own_basename);
    supcheckout_cert_assert(
        isset($paused_features['compatible'], $paused_features['incompatible'])
            && in_array('custom_order_tables', $paused_features['compatible'], true)
            && in_array('cart_checkout_blocks', $paused_features['compatible'], true)
            && !in_array('custom_order_tables', $paused_features['incompatible'], true)
            && !in_array('cart_checkout_blocks', $paused_features['incompatible'], true),
        'paused, SUPCheckout still declares HPOS and Cart & Checkout blocks compatibility in the real WooCommerce registry: ' . wp_json_encode($paused_features)
    );
    supcheckout_cert_assert(has_action('woocommerce_api_wc_upayments') !== false, 'the legacy callback route is the official plugin\'s');
    // A plugin's `supcheckout takeover` command has no registered parent, so WP-CLI defers it
    // until `before_run_command`. eval-file loads WordPress after that hook, so the command is
    // never resolvable in this process; check it the way an operator runs it, in a fresh one.
    $help = supcheckout_takeover_cert_wp('help supcheckout takeover');
    supcheckout_cert_assert(
        $help->return_code === 0 && strpos($help->stdout, 'preflight') !== false && strpos($help->stdout, 'release-stale-claims') !== false && strpos($help->stdout, 'rollback') !== false && strpos($help->stdout, 'abort') !== false,
        '`wp supcheckout takeover` (preflight, release-stale-claims, rollback, abort) is registered by the paused boot: ' . trim($help->stderr)
    );
    $crons = function_exists('_get_cron_array') ? (array) _get_cron_array() : array();
    $own_crons = array();
    foreach ($crons as $hooks) {
        foreach (array_keys((array) $hooks) as $hook) {
            if (strpos((string) $hook, 'supcheckout_') === 0) {
                $own_crons[] = $hook;
            }
        }
    }
    supcheckout_cert_assert($own_crons === array(), 'no supcheckout_* cron event is scheduled while paused');
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::IDLE, 'the takeover starts idle');

    // Seed: the official plugin's settings, a supported currency and two historical orders.
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Certification fixture: clear the availability rate gate earlier steps may have set.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%payment\\_methods\\_rate\\_gate\\_%'");
    // Earlier steps of this shared cell may leave fresh claimed/dispatching billing rows,
    // which Cutover's drain would wait on and refuse (exit 4). This disposable cell's rows
    // are fixture data: clear the in-flight ones and prove none is left.
    $billing_table = $wpdb->prefix . 'upayments_billing_attempts';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($billing_table))) === $billing_table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Certification fixture cleanup on a disposable runtime.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE state IN (%s, %s)', $billing_table, 'claimed', 'dispatching'));
        $open = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE state IN (%s, %s)', $billing_table, 'claimed', 'dispatching'));
        supcheckout_cert_assert($open === 0, 'no open billing claim is left before the takeover');
    }
    update_option('woocommerce_currency', 'KWD');
    update_option('woocommerce_upayments_settings', array('enabled' => 'yes', 'test_mode' => 'yes', 'api_key' => 'takeover-cli-certification-key-' . wp_generate_password(12, false)));
    $order_ids = array();
    for ($i = 1; $i <= 2; $i++) {
        $order = wc_create_order();
        $order->set_payment_method('upayments');
        $order->set_total('10.000');
        $order->update_meta_data('UPayments_PaymentID', 'takeover-cert-pay-' . $i);
        $order->save();
        $order_ids[] = $order->get_id();
    }
    update_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, $order_ids, false);

    $preflight = supcheckout_takeover_cert_wp('supcheckout takeover preflight --format=json');
    $report = json_decode(trim($preflight->stdout), true);
    supcheckout_cert_assert(is_array($report) && isset($report['blocking'], $report['warnings'], $report['facts']), 'preflight runs in a fresh paused process and prints its JSON report');
    supcheckout_cert_assert($preflight->return_code === CliCommands::OK && $report['blocking'] === array(), 'preflight reports nothing blocking: ' . $preflight->stdout . $preflight->stderr);
    $official_fact = null;
    foreach ($report['facts'] as $fact) {
        if ($fact['code'] === 'official_plugin_active') {
            $official_fact = $fact;
        }
    }
    supcheckout_cert_assert(is_array($official_fact), 'preflight reports the active official plugin');

    // The same Preflight in this paused process: it must load no runtime that registers
    // payment hooks (Release/Identity.php would bootstrap the lifecycle on wc_upayments).
    $in_process = \Simplixi\SUPCheckout\Takeover\Preflight::run();
    supcheckout_cert_assert($in_process['blocking'] === array(), 'an in-process paused Preflight reports nothing blocking');
    supcheckout_cert_assert(
        supcheckout_takeover_cert_own_hooks() === array('admin_notices', 'before_woocommerce_init'),
        'after a paused Preflight, SUPCheckout still has no woocommerce_api_wc_upayments, reconcile cron or other hook (only the conflict notice and the WooCommerce feature declarations): ' . wp_json_encode(supcheckout_takeover_cert_own_hooks())
    );
    supcheckout_cert_assert(!class_exists('Simplixi\\SUPCheckout\\Payment\\PaymentLifecycle', false), 'the payment lifecycle was never loaded while paused');

    $status = supcheckout_takeover_cert_wp('supcheckout takeover status --format=json');
    $status_json = json_decode(trim($status->stdout), true);
    supcheckout_cert_assert($status->return_code === 0 && is_array($status_json) && $status_json['status'] === 'idle', 'status runs and reports idle');

    // Billing claims earlier steps of this cell left `claimed` past 600 s would refuse the
    // cutover (exit 5); the release command clears exactly those, under the lease.
    $release = supcheckout_takeover_cert_wp('supcheckout takeover release-stale-claims --yes');
    supcheckout_cert_assert($release->return_code === 0, 'release-stale-claims runs: ' . $release->stdout . $release->stderr);
    $status = supcheckout_takeover_cert_wp('supcheckout takeover status --format=json');
    $status_json = json_decode(trim($status->stdout), true);
    supcheckout_cert_assert(is_array($status_json) && $status_json['stale_claims'] === array(), 'no stale claim is left');

    delete_option('supcheckout_cert_official_stub_fired');
    // No terminal and no --yes: aborted, exit 1, nothing changed.
    $unattended = supcheckout_takeover_cert_wp('supcheckout takeover run --deactivate-official');
    supcheckout_cert_assert($unattended->return_code === CliCommands::ERROR && strpos($unattended->stdout, 'aborted; nothing changed') !== false, 'run without --yes and without a terminal exits 1: ' . $unattended->stdout . $unattended->stderr);
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the aborted run deactivated nothing');
    // Without consent the copy runs (read-only towards legacy) but the store is never cut
    // over while the official plugin is active.
    $refused = supcheckout_takeover_cert_wp('supcheckout takeover run --yes');
    supcheckout_cert_assert($refused->return_code === CliCommands::BLOCKED, 'run without --deactivate-official exits 2 while the official plugin is active: ' . $refused->stdout . $refused->stderr);
    supcheckout_cert_assert(strpos($refused->stdout, 'takeover copy: verified') !== false, 'the copy itself still ran and verified');
    $status = supcheckout_takeover_cert_wp('supcheckout takeover status --format=json');
    $status_json = json_decode(trim($status->stdout), true);
    supcheckout_cert_assert(is_array($status_json) && $status_json['status'] === 'verified', 'the store stays verified, not cut over');

    $run = supcheckout_takeover_cert_wp('supcheckout takeover run --yes --deactivate-official');
    supcheckout_cert_note('run output: ' . $run->stdout);
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'rerun with --deactivate-official exits 0: ' . $run->stdout . $run->stderr);
    $deactivated_at = strpos($run->stdout, 'official plugin deactivated');
    $quiesced_at = strpos($run->stdout, 'takeover cutover: quiesced');
    supcheckout_cert_assert($deactivated_at !== false && $quiesced_at !== false && $deactivated_at < $quiesced_at, 'the official plugin is deactivated before the cutover takes the lease and runs its final verify');
    supcheckout_cert_assert(strpos($run->stdout, 'takeover cutover: cut_over') !== false, 'the store is cut over');
    return;
}

if ($phase === 'verify') {
    supcheckout_cert_assert(!is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is deactivated');
    supcheckout_cert_assert(defined('SUPCHECKOUT_VERSION'), 'SUPCheckout boots fully on the next request');
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::CUT_OVER, 'state is cut_over');
    supcheckout_cert_assert(TakeoverState::is_cut_over(), 'the runtime sees the cutover');
    $order_ids = (array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array());
    supcheckout_cert_assert(count($order_ids) === 2, 'both seeded orders are known');
    foreach ($order_ids as $index => $order_id) {
        $order = wc_get_order((int) $order_id);
        $expected = 'takeover-cert-pay-' . ($index + 1);
        supcheckout_cert_assert($order instanceof WC_Order, 'seeded order ' . $order_id . ' exists');
        supcheckout_cert_assert($order->get_meta('_supcheckout_payment_id') === $expected, 'order ' . $order_id . ': the new key holds the payment id');
        supcheckout_cert_assert($order->get_meta('UPayments_PaymentID') === $expected, 'order ' . $order_id . ': the legacy key is intact');
        supcheckout_cert_assert($order->get_payment_method() === 'upayments', 'order ' . $order_id . ': historical payment_method is never rewritten');
    }
    supcheckout_cert_assert(get_option(Keys::SETTINGS_OPTION) === get_option('woocommerce_upayments_settings'), 'settings copied verbatim, legacy option kept');
    supcheckout_cert_assert(get_option('supcheckout_cert_official_stub_fired', array()) === array(), 'no hook of the still-loaded official plugin fired in the run process after its deactivation: ' . wp_json_encode(get_option('supcheckout_cert_official_stub_fired')));
    $status = supcheckout_takeover_cert_wp('supcheckout takeover status --format=json');
    $status_json = json_decode(trim($status->stdout), true);
    supcheckout_cert_assert($status->return_code === 0 && is_array($status_json) && $status_json['status'] === 'cut_over' && !in_array('official_still_active', $status_json['warnings'], true), 'status reports cut_over with no official_still_active warning');
    $again = supcheckout_takeover_cert_wp('supcheckout takeover run --yes');
    supcheckout_cert_assert($again->return_code === 0, 'run is rerunnable after cutover (nothing to do)');
    return;
}

if ($phase === 'rollback') {
    // Sub-project 0b Task 10: the switched store took an order through SUPCheckout (new
    // names only, payment_method supcheckout); `rollback --yes --reactivate-official` then
    // runs as a real WP-CLI command in a fresh, fully booted process.
    supcheckout_cert_assert(TakeoverState::get()['status'] === TakeoverState::CUT_OVER, 'the store is cut over before the rollback');
    $order = wc_create_order();
    $order->set_payment_method('supcheckout');
    $order->set_total('12.500');
    $order->update_meta_data('_supcheckout_payment_id', 'takeover-cert-post-cutover');
    $order->set_status('processing');
    $order->save();
    update_option(SUPCHECKOUT_TAKEOVER_CERT_POST_CUTOVER, $order->get_id(), false);

    $unattended = supcheckout_takeover_cert_wp('supcheckout takeover rollback --reactivate-official');
    supcheckout_cert_assert($unattended->return_code === CliCommands::ERROR, 'rollback without --yes and without a terminal exits 1: ' . $unattended->stdout . $unattended->stderr);
    $abort = supcheckout_takeover_cert_wp('supcheckout takeover abort --yes');
    supcheckout_cert_assert($abort->return_code === CliCommands::STATE, 'abort refuses a cut-over store (exit 6): ' . $abort->stdout . $abort->stderr);

    $rollback = supcheckout_takeover_cert_wp('supcheckout takeover rollback --yes --reactivate-official');
    supcheckout_cert_note('rollback output: ' . $rollback->stdout);
    supcheckout_cert_assert($rollback->return_code === CliCommands::OK, 'rollback --yes --reactivate-official exits 0: ' . $rollback->stdout . $rollback->stderr);
    foreach (array('rolled_back', 'copies_removed', 'preflight_ok', 'official_reactivated') as $step) {
        supcheckout_cert_assert(strpos($rollback->stdout, $step) !== false, 'the rollback reached ' . $step);
    }
    supcheckout_cert_assert(strpos($rollback->stdout, 'rolled_back') < strpos($rollback->stdout, 'copies_removed'), 'the transition happens before the copies are removed');
    supcheckout_cert_assert(strpos($rollback->stdout . $rollback->stderr, 'Cannot redeclare') === false, 'the reactivation never loaded the official main file into the booted process');
    // The next fresh process loads the official stand-in (which declares the official
    // globals) and SUPCheckout pauses itself first.
    $next = supcheckout_takeover_cert_wp('eval "echo defined(\'SUPCHECKOUT_VERSION\') ? \'booted\' : \'paused\'; echo function_exists(\'woocommerceUpaymentsInit\') ? \' official\' : \' none\';"');
    supcheckout_cert_assert($next->return_code === 0 && trim($next->stdout) === 'paused official', 'the next fresh wp process runs the official plugin with SUPCheckout paused: ' . $next->stdout . $next->stderr);
    return;
}

if ($phase === 'second_cycle') {
    // Abort out of `verified`, then a rollback with a later edit (exit 8), on the rolled-back
    // store with the official stand-in (global symbols) active again.
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is active');
    $blocked = supcheckout_takeover_cert_wp('supcheckout takeover run --yes');
    supcheckout_cert_assert($blocked->return_code === CliCommands::BLOCKED && strpos($blocked->stdout, 'takeover copy: verified') !== false, 'a second run without consent copies and stops at verified (exit 2): ' . $blocked->stdout . $blocked->stderr);
    $abort = supcheckout_takeover_cert_wp('supcheckout takeover abort --yes');
    supcheckout_cert_note('abort output: ' . $abort->stdout);
    supcheckout_cert_assert($abort->return_code === CliCommands::OK && strpos($abort->stdout, 'copies_removed') !== false, 'abort exits 0 and removes the copies: ' . $abort->stdout . $abort->stderr);
    $status = json_decode(trim(supcheckout_takeover_cert_wp('supcheckout takeover status --format=json')->stdout), true);
    supcheckout_cert_assert(is_array($status) && $status['status'] === 'preflight_ok' && $status['rollback']['mode'] === 'abort' && !$status['rollback']['removal_pending'], 'the aborted store is preflight_ok with its removal complete');

    $order = supcheckout_takeover_cert_wp('eval "update_option(\'woocommerce_gateway_order\', array(\'bacs\' => 0, \'upayments\' => 1));"');
    supcheckout_cert_assert($order->return_code === 0, 'gateway order seeded');
    $run = supcheckout_takeover_cert_wp('supcheckout takeover run --yes --deactivate-official');
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'run after the abort cuts over again: ' . $run->stdout . $run->stderr);
    $edit = supcheckout_takeover_cert_wp('eval "update_option(\'woocommerce_gateway_order\', array(\'supcheckout\' => 0, \'bacs\' => 1, \'upayments\' => 2));"');
    supcheckout_cert_assert($edit->return_code === 0, 'the merchant reorders the gateways after the cutover');
    $rollback = supcheckout_takeover_cert_wp('supcheckout takeover rollback --yes --reactivate-official');
    supcheckout_cert_note('conflict rollback output: ' . $rollback->stdout);
    supcheckout_cert_assert($rollback->return_code === CliCommands::CONFLICTS, 'a later edit makes the rollback exit 8: ' . $rollback->stdout . $rollback->stderr);
    supcheckout_cert_assert(preg_match('/conflict: seq [0-9]+ option woocommerce_gateway_order/', $rollback->stdout) === 1, 'the conflict is printed');
    supcheckout_cert_assert(strpos($rollback->stdout, 'official_reactivated') !== false, 'the recorded official install is reactivated');
    $kept = supcheckout_takeover_cert_wp('option get woocommerce_gateway_order --format=json');
    supcheckout_cert_assert(json_decode(trim($kept->stdout), true) === array('supcheckout' => 0, 'bacs' => 1, 'upayments' => 2), 'the newer gateway order is kept: ' . $kept->stdout);
    $status = json_decode(trim(supcheckout_takeover_cert_wp('supcheckout takeover status --format=json')->stdout), true);
    supcheckout_cert_assert(is_array($status) && $status['status'] === 'preflight_ok' && in_array('rollback_conflicts', $status['warnings'], true), 'status reports the completed rollback and its conflict');
    return;
}

if ($phase === 'rolled_back') {
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is active again');
    supcheckout_cert_assert(!defined('SUPCHECKOUT_VERSION'), 'SUPCheckout is paused again');
    $state = TakeoverState::get();
    supcheckout_cert_assert($state['status'] === TakeoverState::PREFLIGHT_OK, 'rolled_back → preflight_ok once the copies are removed');
    supcheckout_cert_assert(isset($state['rolled_back_at']) && $state['rolled_back_at'] > 0, 'the rollback time is recorded');
    supcheckout_cert_assert(!\Simplixi\SUPCheckout\Takeover\Rollback::removal_pending($state), 'no removal is pending');
    supcheckout_cert_assert(!TakeoverState::is_cut_over(), 'the runtime no longer sees a cutover');

    foreach ((array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array()) as $index => $order_id) {
        $order = wc_get_order((int) $order_id);
        supcheckout_cert_assert($order instanceof WC_Order, 'seeded order ' . $order_id . ' exists');
        supcheckout_cert_assert($order->get_meta('UPayments_PaymentID') === 'takeover-cert-pay-' . ($index + 1), 'order ' . $order_id . ': the legacy key is intact');
        supcheckout_cert_assert($order->get_meta('_supcheckout_payment_id') === '', 'order ' . $order_id . ': the new-name copy is removed');
        supcheckout_cert_assert($order->get_meta(Keys::TAKEOVER_COPY_MARKER) === '', 'order ' . $order_id . ': the takeover marker is removed');
        supcheckout_cert_assert($order->get_payment_method() === 'upayments', 'order ' . $order_id . ': historical payment_method unchanged');
    }
    $paid = wc_get_order((int) get_option(SUPCHECKOUT_TAKEOVER_CERT_POST_CUTOVER, 0));
    supcheckout_cert_assert($paid instanceof WC_Order, 'the post-cutover order exists');
    supcheckout_cert_assert($paid->get_payment_method() === 'upayments', 'the post-cutover order is rewritten to upayments, so the official plugin recognises it');
    supcheckout_cert_assert($paid->get_status() === 'processing', 'the post-cutover order stays paid (processing)');
    supcheckout_cert_assert($paid->get_meta('UPayments_PaymentID') === 'takeover-cert-post-cutover', 'its payment ID was back-synced to the legacy key');
    supcheckout_cert_assert($paid->get_meta('_supcheckout_payment_id') === '', 'its new-name copy is removed');

    supcheckout_cert_assert(get_option(Keys::SETTINGS_OPTION, null) === null, 'the new settings copy is removed');
    supcheckout_cert_assert(is_array(get_option('woocommerce_upayments_settings')), 'the legacy settings are intact');
    $crons = function_exists('_get_cron_array') ? (array) _get_cron_array() : array();
    foreach ($crons as $hooks) {
        foreach (array_keys((array) $hooks) as $hook) {
            supcheckout_cert_assert(!in_array($hook, Keys::cron_hooks(), true), 'no new-name cron event is left: ' . $hook);
        }
    }
    global $wpdb;
    $new_table = $wpdb->prefix . 'supcheckout_billing_attempts';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new_table))) === $new_table) {
        supcheckout_cert_assert((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $new_table)) === 0, 'the new billing table holds no copies');
    }
    $journal = \Simplixi\SUPCheckout\Takeover\Journal::entries();
    $rewrites = array_filter($journal, static function ($entry) use ($paid) {
        return $entry['target'] === 'order' && $entry['path'] === $paid->get_id() . '/payment_method';
    });
    supcheckout_cert_assert(count($rewrites) === 1, 'the payment_method rewrite is journaled once');
    $status = supcheckout_takeover_cert_wp('supcheckout takeover status --format=json');
    $status_json = json_decode(trim($status->stdout), true);
    supcheckout_cert_assert($status->return_code === 0 && is_array($status_json) && $status_json['status'] === 'preflight_ok'
        && is_array($status_json['rollback']) && $status_json['rollback']['mode'] === 'rollback' && !in_array('removal_pending', $status_json['warnings'], true), 'status reports the completed rollback: ' . $status->stdout);
    return;
}

if ($phase === 'finalize') {
    // Sub-project 0b Task 11: a third cutover of the rolled-back store, then the guarded,
    // irreversible finalize as real WP-CLI commands (forced after review: the CI cell cannot
    // wait 30 days).
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is active after the second cycle');
    $help = supcheckout_takeover_cert_wp('help supcheckout takeover finalize');
    supcheckout_cert_assert($help->return_code === 0 && strpos($help->stdout, '--force-after-review') !== false && strpos($help->stdout, '--dry-run') !== false, '`finalize` is registered with --force-after-review and --dry-run: ' . $help->stderr);
    $run = supcheckout_takeover_cert_wp('supcheckout takeover run --yes --deactivate-official');
    supcheckout_cert_assert($run->return_code === CliCommands::OK, 'the store is cut over again: ' . $run->stdout . $run->stderr);

    $status = json_decode(trim(supcheckout_takeover_cert_wp('supcheckout takeover status --format=json')->stdout), true);
    supcheckout_cert_assert(is_array($status) && $status['status'] === 'cut_over' && $status['finalize']['days_left'] === 30 && $status['finalize']['allowed'] === false, 'status shows 30 days until finalize is allowed: ' . wp_json_encode($status['finalize'] ?? null));
    supcheckout_cert_assert($status['finalize']['planned'] === null && strpos($status['finalize']['hint'], 'finalize --dry-run') !== false, 'status stays cheap and points at the dry run for the plan');

    $early = supcheckout_takeover_cert_wp('supcheckout takeover finalize --yes');
    supcheckout_cert_assert($early->return_code === CliCommands::STATE && strpos($early->stdout, '30 day(s)') !== false, 'finalize before 30 days exits 6: ' . $early->stdout . $early->stderr);

    global $wpdb;
    // Everything a finalize could write, read past the object cache (the dry run runs in
    // another process): the takeover state, journal and lease; every Keys::options() name;
    // the cron option; every Keys::official_only() option (exact and by prefix) and
    // transient; every legacy ephemeral row (options and transients) and takeover backup;
    // the takeover's user meta (legacy and new names) and product meta; every meta row of the
    // seeded orders in either order store; and both billing-attempts tables.
    $snapshot = static function () use ($wpdb) {
        $official = Keys::official_only();
        $names = array_merge(array(TakeoverState::OPTION, \Simplixi\SUPCheckout\Takeover\Quiesce::OPTION, 'cron'), array_keys(Keys::options()), array_values(Keys::options()), $official['options']);
        foreach ($official['transients'] as $transient) {
            $names[] = '_transient_' . $transient;
            $names[] = '_transient_timeout_' . $transient;
        }
        $prefixes = array_merge(array(\Simplixi\SUPCheckout\Takeover\Journal::OPTION, \Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator::BACKUP_PREFIX), $official['option_prefixes']);
        foreach (Keys::ephemeral_storage() as $prefix => $storage) {
            if ($storage === 'option') {
                $prefixes[] = $prefix;
            } elseif ($storage === 'transient') {
                $prefixes[] = '_transient_' . $prefix;
                $prefixes[] = '_transient_timeout_' . $prefix;
            }
        }
        $likes = array_map(static function ($prefix) use ($wpdb) {
            return $wpdb->esc_like($prefix) . '%';
        }, $prefixes);
        $options = $wpdb->get_results($wpdb->prepare(
            'SELECT option_name, option_value FROM %i WHERE option_name IN (' . implode(', ', array_fill(0, count($names), '%s')) . ')'
                . str_repeat(' OR option_name LIKE %s', count($likes)) . ' ORDER BY option_name',
            array_merge(array($wpdb->options), $names, $likes)
        ), ARRAY_A);
        $user_keys = array_merge(array_keys(Keys::user_meta()), array_values(Keys::user_meta()));
        $user_likes = array();
        foreach (Keys::user_meta_prefixes() as $legacy_prefix => $new_prefix) {
            $user_likes[] = $wpdb->esc_like($legacy_prefix) . '%';
            $user_likes[] = $wpdb->esc_like($new_prefix) . '%';
        }
        $users = $wpdb->get_results($wpdb->prepare(
            'SELECT umeta_id, user_id, meta_key, meta_value FROM %i WHERE meta_key IN (' . implode(', ', array_fill(0, count($user_keys), '%s')) . ')'
                . str_repeat(' OR meta_key LIKE %s', count($user_likes)) . ' ORDER BY umeta_id',
            array_merge(array($wpdb->usermeta), $user_keys, $user_likes)
        ), ARRAY_A);
        $product_keys = array_merge(array_keys(Keys::product_meta()), array_values(Keys::product_meta()), Keys::takeover_markers());
        $products = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE meta_key IN (' . implode(', ', array_fill(0, count($product_keys), '%s')) . ') ORDER BY meta_id',
            array_merge(array($wpdb->postmeta), $product_keys)
        ), ARRAY_A);
        $ids = array_map('intval', (array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array()));
        $meta = array();
        foreach (array($wpdb->postmeta => 'post_id', $wpdb->prefix . 'wc_orders_meta' => 'order_id') as $table => $column) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                continue;
            }
            $meta[$table] = $wpdb->get_results($wpdb->prepare(
                'SELECT %i AS object_id, meta_key, meta_value FROM %i WHERE %i IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ') ORDER BY meta_id',
                array_merge(array($column, $table, $column), $ids)
            ), ARRAY_A);
        }
        $tables = array();
        foreach (Keys::tables() as $legacy => $new) {
            foreach (array($wpdb->prefix . $legacy, $wpdb->prefix . $new) as $table) {
                $tables[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table
                    ? $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY cycle_key', $table), ARRAY_A)
                    : null;
            }
        }
        return wp_json_encode(array($options, $users, $products, $meta, $tables));
    };
    // An expired official transient: get_transient() would delete both rows, so a dry run
    // that read it that way would write.
    update_option('_transient_timeout_upayments_cron_lock', time() - 60, false);
    update_option('_transient_upayments_cron_lock', '1', false);
    $before = $snapshot();
    supcheckout_cert_assert(strpos($before, '_transient_timeout_upayments_cron_lock') !== false, 'the snapshot covers the expired official transient');
    $dry = supcheckout_takeover_cert_wp('supcheckout takeover finalize --dry-run --format=json');
    $plan = json_decode(trim($dry->stdout), true);
    supcheckout_cert_note('dry-run plan: ' . $dry->stdout . $dry->stderr);
    $equal = supcheckout_takeover_cert_equal_order_meta();
    $diagnosis = array();
    foreach ((array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array()) as $order_id) {
        $order = wc_get_order((int) $order_id);
        $diagnosis[(int) $order_id] = $order instanceof WC_Order
            ? array(
                'type' => $order->get_type(),
                'legacy' => $order->get_meta('UPayments_PaymentID', false, 'edit') ? array_map(static function ($meta) { return $meta->value; }, $order->get_meta('UPayments_PaymentID', false, 'edit')) : array(),
                'new' => $order->get_meta(Keys::order_meta()['UPayments_PaymentID'], false, 'edit') ? array_map(static function ($meta) { return $meta->value; }, $order->get_meta(Keys::order_meta()['UPayments_PaymentID'], false, 'edit')) : array(),
            )
            : 'missing';
    }
    supcheckout_cert_assert($equal >= 2, 'the seeded orders hold at least their two verified-equal payment IDs (' . $equal . '): ' . wp_json_encode($diagnosis) . ' all-orders=' . wp_json_encode(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'type' => 'shop_order'))));
    supcheckout_cert_assert($dry->return_code === CliCommands::OK && is_array($plan) && $plan['would_refuse'] === array('too_early') && $plan['removed']['order_meta'] === $equal, 'the dry run predicts exactly the ' . $equal . ' verified-equal legacy order keys (and still exits 0): ' . $dry->stdout . $dry->stderr);
    supcheckout_cert_assert($snapshot() === $before, 'the dry run wrote nothing (takeover, official, ephemeral and cron options, user, product and order meta, both billing tables)');
    supcheckout_cert_note('dry-run plan: ' . $dry->stdout);

    // Deterministic outcome: exactly what the dry run predicted for this cell.
    $expected_kept = array();
    foreach ($plan['kept'] as $group) {
        $expected_kept[] = $group['kind'] . ' ' . $group['name'] . ' ' . $group['reason'];
    }
    sort($expected_kept);
    $seeded = array_map('intval', (array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array()));
    foreach ($plan['kept'] as $group) {
        supcheckout_cert_assert($group['kind'] !== 'order_meta' || array_intersect($seeded, $group['ids']) === array(), 'no seeded order is kept: ' . wp_json_encode($group));
    }
    update_option('supcheckout_takeover_cert_finalize_kept', $expected_kept, false);
    update_option('supcheckout_takeover_cert_finalize_table', (string) $plan['table'], false);

    $unattended = supcheckout_takeover_cert_wp('supcheckout takeover finalize --force-after-review');
    supcheckout_cert_assert($unattended->return_code === CliCommands::ERROR && strpos($unattended->stdout . $unattended->stderr, 'IRREVERSIBLE') !== false, 'finalize without --yes and without a terminal warns and exits 1: ' . $unattended->stdout . $unattended->stderr);
    $finalize = supcheckout_takeover_cert_wp('supcheckout takeover finalize --yes --force-after-review');
    supcheckout_cert_note('finalize output: ' . $finalize->stdout);
    $expected_code = $expected_kept === array() ? CliCommands::OK : CliCommands::CONFLICTS;
    supcheckout_cert_assert($finalize->return_code === $expected_code, 'finalize --yes --force-after-review exits ' . $expected_code . ' as the dry run predicted: ' . $finalize->stdout . $finalize->stderr);
    preg_match_all('/^kept: (\S+ \S+ \S+) x[0-9]+/m', $finalize->stdout, $matches);
    $kept = $matches[1];
    sort($kept);
    supcheckout_cert_assert($kept === $expected_kept, 'finalize kept exactly the predicted names: ' . wp_json_encode($kept) . ' vs ' . wp_json_encode($expected_kept));
    supcheckout_cert_assert(strpos($finalize->stdout . $finalize->stderr, 'removal_unsettled') === false, 'the removal settled (no late writer kept recreating legacy names)');
    $steps = supcheckout_takeover_cert_steps($finalize->stdout, 'finalize');
    foreach (array('quiesced', 'verified', 'finalized', 'caches_flushed', 'orders_removed', 'legacy_swept', 'settling', 'rescanned', 'legacy_removed') as $step) {
        supcheckout_cert_assert(in_array($step, $steps, true), 'finalize reached ' . $step . ': ' . wp_json_encode($steps));
    }
    supcheckout_cert_assert(array_search('finalized', $steps, true) < array_search('orders_removed', $steps, true), 'the transition happens before the legacy names are removed');
    supcheckout_cert_assert(array_search('legacy_swept', $steps, true) < array_search('rescanned', $steps, true) && end($steps) === 'legacy_removed', 'settle and rescan come after the sweep, completion last');
    supcheckout_cert_assert(preg_match('/^removed: .*\border_meta=' . $equal . '\b/m', $finalize->stdout) === 1, 'finalize removed exactly the ' . $equal . ' predicted legacy order keys');
    $legacy_table = $wpdb->prefix . (string) array_keys(Keys::tables())[0];
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy_table))) === $legacy_table;
    supcheckout_cert_assert($exists === ($plan['table'] === 'keep'), 'the legacy billing table outcome is the predicted one (' . $plan['table'] . ')');
    supcheckout_cert_assert(in_array($plan['table'] === 'drop' ? 'table_retired' : ($plan['table'] === 'keep' ? 'table_kept' : 'table_absent'), $steps, true), 'the table step matches the prediction');
    return;
}

if ($phase === 'finalized') {
    supcheckout_cert_assert(!is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stays deactivated');
    supcheckout_cert_assert(defined('SUPCHECKOUT_VERSION'), 'SUPCheckout boots fully on a finalized store');
    $state = TakeoverState::get();
    supcheckout_cert_assert($state['status'] === TakeoverState::FINALIZED && $state['finalize_forced'] === true, 'state is finalized, forced after review');
    supcheckout_cert_assert(TakeoverState::is_cut_over(), 'the runtime still sees the switch');
    supcheckout_cert_assert(!\Simplixi\SUPCheckout\Takeover\Finalize::pending($state), 'no removal is pending');
    foreach ((array) get_option(SUPCHECKOUT_TAKEOVER_CERT_ORDERS, array()) as $index => $order_id) {
        $order = wc_get_order((int) $order_id);
        $expected = 'takeover-cert-pay-' . ($index + 1);
        supcheckout_cert_assert($order instanceof WC_Order, 'seeded order ' . $order_id . ' exists');
        supcheckout_cert_assert($order->get_meta('UPayments_PaymentID') === '', 'order ' . $order_id . ': the verified-equal legacy key is removed');
        supcheckout_cert_assert($order->get_meta('_supcheckout_payment_id') === $expected, 'order ' . $order_id . ': the new key keeps the payment id');
        supcheckout_cert_assert(Keys::read_order_meta($order, 'UPayments_PaymentID') === $expected, 'order ' . $order_id . ': the runtime still reads it');
        supcheckout_cert_assert($order->get_meta(Keys::TAKEOVER_COPY_MARKER) === '', 'order ' . $order_id . ': the takeover marker is removed');
        supcheckout_cert_assert($order->get_payment_method() === 'upayments', 'order ' . $order_id . ': historical payment_method unchanged');
    }
    supcheckout_cert_assert(has_action('woocommerce_api_' . Keys::CALLBACK_ROUTE_LEGACY) !== false, 'the legacy callback route is still served');
    supcheckout_cert_assert(is_array(get_option(Keys::SETTINGS_OPTION)) && is_array(get_option('woocommerce_upayments_settings')), 'the settings and their protected legacy source are kept');
    global $wpdb;
    $backups = (array) $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like(\Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator::BACKUP_PREFIX) . '%'));
    supcheckout_cert_assert(preg_grep('/^' . preg_quote(\Simplixi\SUPCheckout\Takeover\ThirdPartyTranslator::BACKUP_PREFIX, '/') . '[a-f0-9]{64}$/D', $backups) === array(), 'the takeover backups are removed: ' . wp_json_encode($backups));
    $crons = function_exists('_get_cron_array') ? (array) _get_cron_array() : array();
    foreach ($crons as $hooks) {
        foreach (array_keys((array) $hooks) as $hook) {
            supcheckout_cert_assert(!in_array($hook, Keys::official_only()['cron_hooks'], true), 'no official cron event is left: ' . $hook);
        }
    }
    foreach (array('rollback --yes', 'abort --yes', 'run --yes') as $command) {
        $refused = supcheckout_takeover_cert_wp('supcheckout takeover ' . $command);
        supcheckout_cert_assert($refused->return_code === CliCommands::STATE, $command . ' is refused after finalize (exit 6): ' . $refused->stdout . $refused->stderr);
    }
    $status = json_decode(trim(supcheckout_takeover_cert_wp('supcheckout takeover status --format=json')->stdout), true);
    supcheckout_cert_assert(is_array($status) && $status['status'] === 'finalized' && $status['finalize']['pending'] === false && $status['finalize']['forced'] === true, 'status reports the completed finalize: ' . wp_json_encode($status));
    $expected_kept = (array) get_option('supcheckout_takeover_cert_finalize_kept', array());
    $sweep = supcheckout_takeover_cert_wp('supcheckout takeover finalize --yes');
    supcheckout_cert_note('sweep output: ' . $sweep->stdout);
    $sweep_steps = supcheckout_takeover_cert_steps($sweep->stdout, 'finalize');
    supcheckout_cert_assert($sweep->return_code === ($expected_kept === array() ? CliCommands::OK : CliCommands::CONFLICTS) && $sweep_steps !== array() && $sweep_steps[0] === 'sweep', 'finalize on a finalized store only sweeps again, with the same outcome: ' . $sweep->stdout . $sweep->stderr);
    preg_match_all('/^kept: (\S+ \S+ \S+) x[0-9]+/m', $sweep->stdout, $matches);
    $kept = $matches[1];
    sort($kept);
    supcheckout_cert_assert($kept === $expected_kept, 'the sweep keeps the same names: ' . wp_json_encode($kept));
    supcheckout_cert_assert(strpos($sweep->stdout . $sweep->stderr, 'removal_unsettled') === false, 'the sweep settled');
    supcheckout_cert_assert(!in_array('settling', $sweep_steps, true) && in_array('rescanned', $sweep_steps, true), 'a sweep long after the transition rescans without waiting again');
    return;
}

throw new RuntimeException('Set SUPCHECKOUT_CERT_PHASE to paused, verify, rollback, rolled_back, second_cycle, finalize or finalized.');
