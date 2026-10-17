<?php
namespace Simplixi\SUPCheckout\Migration;

defined('ABSPATH') || exit;

use Simplixi\SUPCheckout\Takeover\Keys;

/**
 * Read-only resolver for the existing WooCommerce UPayments settings.
 *
 * Migration tools never accept or persist API keys of their own. They consume
 * the exact existing gateway credential/mode so scope derivation remains
 * identical to runtime H12 behavior.
 */
final class MigrationSettings {
    const OPTION_KEY = 'woocommerce_upayments_settings';

    public static function resolve() {
        $settings = Keys::get_settings(null);
        if (!is_array($settings)) {
            return self::failure('settings_missing');
        }

        $api_key = isset($settings['api_key']) ? $settings['api_key'] : null;
        if (!is_string($api_key) || $api_key === '' || trim($api_key) === '') {
            return self::failure('api_key_missing');
        }

        $test_mode = array_key_exists('test_mode', $settings) ? $settings['test_mode'] : 'no';
        if ($test_mode !== 'yes' && $test_mode !== 'no') {
            return self::failure('test_mode_invalid');
        }

        return array(
            'ok' => true,
            'reason' => 'settings_resolved',
            'api_key' => $api_key,
            'is_test_mode' => ($test_mode === 'yes'),
            'mode' => ($test_mode === 'yes') ? 'test' : 'live',
        );
    }

    public static function redact($resolved) {
        if (!is_array($resolved)) {
            return array('ok' => false, 'reason' => 'settings_malformed');
        }

        $reason = isset($resolved['reason']) && is_string($resolved['reason'])
            ? $resolved['reason']
            : null;
        $mode = isset($resolved['mode']) && is_string($resolved['mode'])
            ? $resolved['mode']
            : null;

        if (
            isset($resolved['ok'])
            && $resolved['ok'] === true
            && $reason === 'settings_resolved'
            && ($mode === 'test' || $mode === 'live')
            && isset($resolved['api_key'])
            && is_string($resolved['api_key'])
            && $resolved['api_key'] !== ''
            && trim($resolved['api_key']) !== ''
            && array_key_exists('is_test_mode', $resolved)
            && is_bool($resolved['is_test_mode'])
            && $resolved['is_test_mode'] === ($mode === 'test')
        ) {
            return array(
                'ok' => true,
                'reason' => 'settings_resolved',
                'mode' => $mode,
            );
        }

        if (
            array_key_exists('ok', $resolved)
            && $resolved['ok'] === false
            && in_array($reason, array('settings_missing', 'api_key_missing', 'test_mode_invalid'), true)
            && array_key_exists('api_key', $resolved)
            && $resolved['api_key'] === null
            && array_key_exists('is_test_mode', $resolved)
            && $resolved['is_test_mode'] === null
            && array_key_exists('mode', $resolved)
            && $resolved['mode'] === null
        ) {
            return array(
                'ok' => false,
                'reason' => $reason,
                'mode' => null,
            );
        }

        return array(
            'ok' => false,
            'reason' => 'settings_malformed',
            'mode' => null,
        );
    }

    private static function failure($reason) {
        return array(
            'ok' => false,
            'reason' => $reason,
            'api_key' => null,
            'is_test_mode' => null,
            'mode' => null,
        );
    }

    private function __construct() {
    }
}
