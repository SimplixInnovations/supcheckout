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
 *                                  plugin deactivated and SUPCheckout fully booted.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Takeover\CliCommands;
use Simplixi\SUPCheckout\Takeover\Keys;
use Simplixi\SUPCheckout\Takeover\TakeoverState;

const SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL = 'upayments/UPayments.php';
const SUPCHECKOUT_TAKEOVER_CERT_ORDERS = 'supcheckout_takeover_cert_orders';

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

$phase = (string) getenv('SUPCHECKOUT_CERT_PHASE');

if ($phase === 'paused') {
    supcheckout_cert_assert(is_plugin_active(SUPCHECKOUT_TAKEOVER_CERT_OFFICIAL), 'the official plugin stand-in is active');
    supcheckout_cert_assert(!defined('SUPCHECKOUT_VERSION'), 'SUPCheckout is paused: Plugin::boot() did not run');
    supcheckout_cert_assert(
        supcheckout_takeover_cert_own_hooks() === array('admin_notices'),
        'the paused boot registered no gateway, Blocks, WC-API route, cron or other hook (only the conflict notice): ' . wp_json_encode(supcheckout_takeover_cert_own_hooks())
    );
    supcheckout_cert_assert(has_action('woocommerce_api_wc_upayments') !== false, 'the legacy callback route is the official plugin\'s');
    $runner = WP_CLI::get_runner();
    supcheckout_cert_assert(is_array($runner->find_command_to_run(array('supcheckout', 'takeover', 'preflight'))), '`wp supcheckout takeover preflight` is registered by the paused boot');
    supcheckout_cert_assert(is_array($runner->find_command_to_run(array('supcheckout', 'takeover', 'release-stale-claims'))), '`wp supcheckout takeover release-stale-claims` is registered');
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
        supcheckout_takeover_cert_own_hooks() === array('admin_notices'),
        'after a paused Preflight, SUPCheckout still has no woocommerce_api_wc_upayments, reconcile cron or other hook: ' . wp_json_encode(supcheckout_takeover_cert_own_hooks())
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

throw new RuntimeException('Set SUPCHECKOUT_CERT_PHASE to paused or verify.');
