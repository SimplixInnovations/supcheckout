<?php
/**
 * TEST-ONLY mu-plugin for the A4-01 early-translation-loading guard (hardening U7).
 *
 * CI copies it into wp-content/mu-plugins. It does nothing unless a run opts in:
 * - WP-CLI: the SUPCHECKOUT_I18N_EARLY_PROBE=1 environment variable
 *   (tests/integration/I18nEarlyLoadingTest.php reads the recorder after the boot);
 * - HTTP: the `supcheckout_i18n_early=1` query argument
 *   (tests/integration/checkout-request-context-http.sh reads the response header).
 *
 * When opted in, at `muplugins_loaded` (before any plugin loads) it forces the Arabic
 * locale, so the bundled languages/supcheckout-ar.mo is the only translation source, and
 * records every `_doing_it_wrong('_load_textdomain_just_in_time')` notice WordPress 6.7+
 * raises for the `supcheckout` domain. That notice means a supcheckout string was
 * translated before `after_setup_theme`, which loads the translation too early.
 *
 * SUPCHECKOUT_I18N_EARLY_CANARY=1 (WP-CLI only) adds one deliberate early
 * `__('x', 'supcheckout')` at `plugins_loaded`, so CI proves the recorder catches one.
 */

defined('ABSPATH') || exit;

/** @return bool */
function supcheckout_cert_i18n_early_enabled() {
    if ('1' === getenv('SUPCHECKOUT_I18N_EARLY_PROBE')) {
        return true;
    }
    // Test-only switch on a disposable CI site; no state is changed by it.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return isset($_GET['supcheckout_i18n_early']) && '1' === $_GET['supcheckout_i18n_early'];
}

$GLOBALS['supcheckout_cert_i18n_early_calls'] = array();

add_action('muplugins_loaded', static function () {
    if (!supcheckout_cert_i18n_early_enabled()) {
        return;
    }
    $GLOBALS['supcheckout_cert_i18n_early_enabled'] = true;

    add_filter('pre_determine_locale', static function () {
        return 'ar';
    });

    add_action('doing_it_wrong_run', static function ($function_name, $message) {
        if ('_load_textdomain_just_in_time' === $function_name && false !== strpos((string) $message, '<code>supcheckout</code>')) {
            $GLOBALS['supcheckout_cert_i18n_early_calls'][] = array(
                'message'                => (string) $message,
                // The running hooks, outermost first, without this doing_it_wrong_run itself.
                'hooks'                  => array_values(array_diff((array) ($GLOBALS['wp_current_filter'] ?? array()), array('doing_it_wrong_run'))),
                'after_setup_theme_done' => (bool) did_action('after_setup_theme'),
            );
        }
    }, 10, 2);

    if ('1' === getenv('SUPCHECKOUT_I18N_EARLY_CANARY')) {
        add_action('plugins_loaded', static function () {
            // The deliberate canary: a supcheckout string translated before after_setup_theme.
            __('x', 'supcheckout');
        });
    }

    // HTTP variant: report the count before any output. send_headers runs after `wp()`,
    // long after after_setup_theme, so every early load has been recorded by then.
    add_action('send_headers', static function () {
        if (!headers_sent()) {
            header('X-SUPCheckout-I18n-Early: ' . count($GLOBALS['supcheckout_cert_i18n_early_calls']));
            header('X-SUPCheckout-I18n-Locale: ' . determine_locale());
        }
    });
}, 0);
