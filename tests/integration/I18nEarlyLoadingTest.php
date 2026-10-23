<?php
/**
 * A4-01 (hardening U7) early-translation-loading guard on a real WordPress boot.
 *
 * Once a supcheckout translation exists for the active locale (the bundled
 * languages/supcheckout-ar.mo), WordPress 6.7+ raises
 * `_doing_it_wrong('_load_textdomain_just_in_time')` for every supcheckout string translated
 * before `after_setup_theme`. The fixture mu-plugin (fixtures/i18n-early-mu-plugin.php) forces
 * the `ar` locale at `muplugins_loaded` and records those notices for the whole boot that
 * WP-CLI ran before this file.
 *
 * SUPCHECKOUT_CERT_PHASE=canary runs with SUPCHECKOUT_I18N_EARLY_CANARY=1: the fixture makes
 * one deliberate early `__('x', 'supcheckout')` at `plugins_loaded`, and the recorder must catch
 * it (the permanent RED proof). SUPCHECKOUT_CERT_PHASE=verify runs without the canary: the
 * recorder must be empty, so no SUPCheckout code translates a string too early.
 */

require_once __DIR__ . '/bootstrap.php';

$phase = (string) getenv('SUPCHECKOUT_CERT_PHASE');
supcheckout_cert_assert(in_array($phase, array('canary', 'verify'), true), 'SUPCHECKOUT_CERT_PHASE is canary or verify: ' . $phase);
supcheckout_cert_assert('1' === getenv('SUPCHECKOUT_I18N_EARLY_PROBE'), 'the early-loading probe is opted in for this boot');
supcheckout_cert_assert(!empty($GLOBALS['supcheckout_cert_i18n_early_enabled']), 'the i18n-early fixture mu-plugin ran at muplugins_loaded');
supcheckout_cert_assert('ar' === determine_locale(), 'the boot ran under the ar locale');
// A real Arabic store: the site locale agrees, so WP-CLI's post-boot admin.php include
// (load_textdomain(..., get_locale())) keeps WP_Translation_Controller on ar.
supcheckout_cert_assert('ar' === get_locale(), 'the site locale is ar too, as on a real Arabic store');
supcheckout_cert_assert('ar' === WP_Translation_Controller::get_instance()->get_locale(), 'the translation controller is on the ar locale after the WP-CLI boot');
supcheckout_cert_assert(
    !is_readable(WP_LANG_DIR . '/plugins/supcheckout-ar.mo') && is_readable(dirname(SUPCHECKOUT_PLUGIN_FILE) . '/languages/supcheckout-ar.mo'),
    'only the bundled Arabic file exists, so the bundled-translation fallback is what loads'
);
supcheckout_cert_assert(did_action('after_setup_theme') > 0, 'the boot ran past after_setup_theme');

$calls = $GLOBALS['supcheckout_cert_i18n_early_calls'];
$canary = '1' === getenv('SUPCHECKOUT_I18N_EARLY_CANARY');

if ('canary' === $phase) {
    supcheckout_cert_assert($canary, 'the canary phase runs with SUPCHECKOUT_I18N_EARLY_CANARY=1');
    $caught = false;
    foreach ($calls as $call) {
        if (in_array('plugins_loaded', $call['hooks'], true) && false === $call['after_setup_theme_done']) {
            $caught = true;
        }
    }
    supcheckout_cert_assert($caught, 'the recorder catches the deliberate early supcheckout translation at plugins_loaded: ' . wp_json_encode($calls));
    supcheckout_cert_note('early-translation recorder proven (canary caught)');
    return;
}

supcheckout_cert_assert(!$canary, 'the verify phase runs without the canary');
supcheckout_cert_assert(
    array() === $calls,
    'no supcheckout string is translated before after_setup_theme on an Arabic boot: ' . wp_json_encode($calls)
);
supcheckout_cert_assert('ادفع بأمان عبر' === __('Pay securely with', 'supcheckout'), 'after the boot, the bundled Arabic translation loads normally');
supcheckout_cert_note('no early supcheckout translation loading on a full Arabic WP-CLI boot');
