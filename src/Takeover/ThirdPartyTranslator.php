<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Gateway references outside SUPCheckout's own options (Sub-project 0b Task 7, Cutover
 * step 2).
 *
 * Translated (WooCommerce core, shapes evidenced from WooCommerce source):
 * - `woocommerce_gateway_order`: WC_Payment_Gateways::init() reads
 *   `(array) get_option('woocommerce_gateway_order')`, a map gateway id => integer
 *   position (PaymentsProviders::PROVIDERS_ORDER_OPTION writes the same map). When it holds
 *   a numeric position for `upayments` and none for `supcheckout`, `supcheckout` gets the
 *   same position. `upayments` stays (copy, never move): after cutover only one of the two
 *   gateways is ever registered (SUPCheckout no longer registers `upayments`, and it is
 *   paused while the official plugin is active), so the shared position never makes
 *   init() overwrite one gateway with the other. An existing `supcheckout` position is
 *   kept.
 * - `woocommerce_default_gateway`: a gateway-id string (WooCommerce 2.x; current core no
 *   longer reads it, the plugin's own `woocommerce_default_gateway` filter and other
 *   plugins may). Only the exact string `upayments` becomes `supcheckout`.
 *
 * Each write: the original goes into a takeover-owned, content-addressed backup option
 * (BACKUP_PREFIX . fingerprint, non-autoloaded, insert-if-absent; an existing backup must
 * hold the identical value), then Journal::record() with both values' fingerprints and
 * `option:<backup>` as restore source, then the write, read back.
 *
 * Reported only: every other option that mentions `upayments` and is not a name Preflight
 * already accounts for (WCML gateway rules, FunnelKit gateway lists, anything else). Until
 * a real install evidences a third-party option's exact shape (plan Task 12 matrix), it is
 * never written and never journaled; the report labels likely owners by option-name prefix.
 */
final class ThirdPartyTranslator {
    public const GATEWAY_ORDER = 'woocommerce_gateway_order';
    public const DEFAULT_GATEWAY = 'woocommerce_default_gateway';
    public const BACKUP_PREFIX = 'supcheckout_takeover_backup_v1_';
    /** Options scanned for the report at most. */
    public const REPORT_SCAN_LIMIT = 500;

    /** Option-name prefixes => integration label (labels only; nothing is parsed). */
    private const INTEGRATION_PREFIXES = array(
        '_wcml_' => 'wcml',
        'wcml_' => 'wcml',
        'icl_' => 'wpml',
        'wfacp_' => 'funnelkit',
        'wffn_' => 'funnelkit',
        'bwf_' => 'funnelkit',
    );

    /**
     * @param callable|null $reference_names function (): list<string>, option names that
     *                                       mention the gateway; a bounded options-table
     *                                       scan when omitted.
     * @return array{ok:bool, translated:list<string>, reported:list<array{option:string, integration:string, action:string}>, mismatches:list<string>}
     * @throws \RuntimeException When a journal entry cannot be stored.
     */
    public static function translate($reference_names = null) {
        $result = array('ok' => true, 'translated' => array(), 'reported' => self::report($reference_names), 'mismatches' => array());
        $order = get_option(self::GATEWAY_ORDER, null);
        if (is_array($order)
            && isset($order[Keys::GATEWAY_LEGACY])
            && is_numeric($order[Keys::GATEWAY_LEGACY])
            && !array_key_exists(Keys::GATEWAY, $order)
        ) {
            $after = $order;
            $after[Keys::GATEWAY] = $order[Keys::GATEWAY_LEGACY];
            $result = self::apply($result, self::GATEWAY_ORDER, $order, $after);
        }
        if (get_option(self::DEFAULT_GATEWAY, null) === Keys::GATEWAY_LEGACY) {
            $result = self::apply($result, self::DEFAULT_GATEWAY, Keys::GATEWAY_LEGACY, Keys::GATEWAY);
        }
        return $result;
    }

    /**
     * Whether every translatable path holds its translation (nothing left to do).
     *
     * @return bool
     */
    public static function is_translated() {
        $order = get_option(self::GATEWAY_ORDER, null);
        $order_pending = is_array($order) && isset($order[Keys::GATEWAY_LEGACY])
            && is_numeric($order[Keys::GATEWAY_LEGACY]) && !array_key_exists(Keys::GATEWAY, $order);
        return !$order_pending && get_option(self::DEFAULT_GATEWAY, null) !== Keys::GATEWAY_LEGACY;
    }

    /**
     * @param array{ok:bool, translated:list<string>, reported:list<array{option:string, integration:string, action:string}>, mismatches:list<string>} $result
     * @param string $path   Option name.
     * @param mixed  $before Current value.
     * @param mixed  $after  Translated value.
     * @return array{ok:bool, translated:list<string>, reported:list<array{option:string, integration:string, action:string}>, mismatches:list<string>}
     */
    private static function apply(array $result, $path, $before, $after) {
        $backup = self::BACKUP_PREFIX . Journal::fingerprint($before);
        $missing = new \stdClass();
        if (!add_option($backup, $before, '', 'no') && get_option($backup, $missing) !== $before) {
            $result['ok'] = false;
            $result['mismatches'][] = $path;
            return $result;
        }
        Journal::record('option', $path, $before, $after, 'option:' . $backup);
        update_option($path, $after);
        if (get_option($path, $missing) !== $after) {
            $result['ok'] = false;
            $result['mismatches'][] = $path;
            return $result;
        }
        $result['translated'][] = $path;
        return $result;
    }

    /**
     * @param callable|null $reference_names Name source.
     * @return list<array{option:string, integration:string, action:string}>
     */
    private static function report($reference_names) {
        $names = is_callable($reference_names) ? call_user_func($reference_names) : self::scan();
        $reported = array();
        foreach (is_array($names) ? $names : array() as $name) {
            if (!is_string($name)
                || in_array($name, array(self::GATEWAY_ORDER, self::DEFAULT_GATEWAY), true)
                || strpos($name, self::BACKUP_PREFIX) === 0
                || Preflight::is_known_option_name($name)
            ) {
                continue;
            }
            $reported[] = array('option' => $name, 'integration' => self::integration($name), 'action' => 'report_only');
        }
        return $reported;
    }

    /**
     * @param string $name Option name.
     * @return string
     */
    private static function integration($name) {
        foreach (self::INTEGRATION_PREFIXES as $prefix => $label) {
            if (strpos($name, $prefix) === 0) {
                return $label;
            }
        }
        return 'unknown';
    }

    /**
     * Bounded scan of option names (never values) that mention the gateway id.
     *
     * @return list<string>
     */
    private static function scan() {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_col')) {
            return array();
        }
        $like = '%' . $wpdb->esc_like(Keys::GATEWAY_LEGACY) . '%';
        $prepared = $wpdb->prepare(
            'SELECT option_name FROM %i WHERE (option_name LIKE %s OR option_value LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d',
            $wpdb->options,
            $like,
            $like,
            $wpdb->esc_like('_transient_') . '%',
            $wpdb->esc_like('_site_transient_') . '%',
            self::REPORT_SCAN_LIMIT
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared above; a one-off cutover report must see live option names.
        $names = $wpdb->get_col($prepared);
        return is_array($names) ? array_values(array_filter($names, 'is_string')) : array();
    }

    private function __construct() {}
}
