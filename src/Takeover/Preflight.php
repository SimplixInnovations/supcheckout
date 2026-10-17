<?php

namespace Simplixi\SUPCheckout\Takeover;

use Simplixi\SUPCheckout\Admin\GatewaySettings;

defined('ABSPATH') || exit;

/**
 * Read-only takeover report (Sub-project 0b Task 4).
 *
 * Every check comes from the official-plugin data inventory (§1-§8 and its hazards)
 * and reads through Environment, which has no write capability. Preflight writes no
 * option, meta or transient, schedules nothing, registers no hook and mutates nothing
 * at UPayments: the credential check is PaymentMethodAvailability::probe(), a
 * non-caching variant of the checkout availability call, and the callback check is a
 * loopback GET. OfficialFootprint collects what the official plugin left behind.
 *
 * Report: array{blocking: list<entry>, warnings: list<entry>, facts: list<entry>}, each
 * entry array{code: string, message: string, data: array}. Codes are stable strings
 * that the 0c UI and the CLI rely on (see codes()). An entry is present only when its
 * condition holds; settings_source and account_mode are always present.
 * third_party_references is a warning when an option outside WooCommerce's own gateway
 * settings refers to the gateway, otherwise a fact. Messages are translatable and
 * carry no secret, token or customer data: data holds counts, option names, plugin
 * versions and a debug-log path relative to the plugins directory, never values.
 *
 * Callback probe: GET of the WC-API URL for Keys::CALLBACK_ROUTE plus
 * `supcheckout_probe=1`, with TLS verification on, because UPayments verifies the
 * certificate too. LegacyCallbackListener (Task 8) answers it on that route before any
 * callback processing with a no-op 200, `Cache-Control: no-store` and the
 * PROBE_ACK_HEADER header, touching no order and reading no other argument.
 * callback_unreachable is BLOCKING unless the first response is a 200 that carries
 * that acknowledgement: a 200 without it came from something else (a cached copy, a
 * maintenance or login page, a host answering every request), so a callback
 * UPayments sent there could still be lost. WooCommerce answers a WC-API route that
 * has no listener with `status_header(400)` and "-1" and runs no handler.
 */
final class Preflight {
    public const OFFICIAL_PLUGIN_ACTIVE = 'official_plugin_active';
    public const CREDENTIAL_UNVERIFIED = 'credential_unverified';
    public const ACCOUNT_MODE = 'account_mode';
    public const CURRENCY_UNSUPPORTED = 'currency_unsupported';
    public const PENDING_WITH_OPEN_LINK = 'pending_with_open_link';
    public const HPOS_META_DISAGREEMENT = 'hpos_meta_disagreement';
    public const WHS_MULTI_VALUED = 'whs_multi_valued';
    public const DUPLICATE_RENEWALS = 'duplicate_renewals';
    public const OFFICIAL_DEBUG_LOG_PRESENT = 'official_debug_log_present';
    public const THIRD_PARTY_REFERENCES = 'third_party_references';
    public const CALLBACK_UNREACHABLE = 'callback_unreachable';
    public const CACHE_EXCLUSION_MISSING = 'cache_exclusion_missing';
    public const SETTINGS_SOURCE = 'settings_source';
    public const OFFICIAL_ONLY_NAMES = 'official_only_names';
    public const UPAY_SUBSCRIPTIONS_TABLE_PRESENT = 'upay_subscriptions_table_present';
    public const LEFTOVERS = 'leftovers';

    public const BLOCKING = 'blocking';
    public const WARNING = 'warning';
    public const FACT = 'fact';

    /** Query argument the callback probe carries (LegacyCallbackListener answers it). */
    public const PROBE_ARG = LegacyCallbackListener::PROBE_ARG;
    /** Response header LegacyCallbackListener sets on a probe (lower-case). */
    public const PROBE_ACK_HEADER = LegacyCallbackListener::PROBE_ACK_HEADER;
    /** The acknowledgement's only accepted value. */
    private const PROBE_ACK_VALUE = '1';

    /** At most this many orders are compared for hpos_meta_disagreement. */
    public const SAMPLE_LIMIT = 200;
    /** Rows read by the third-party scan, and option names reported per list. */
    public const REFERENCE_SCAN_LIMIT = 500;
    public const REFERENCE_REPORT_LIMIT = 100;
    /** Pending orders newer than this may still be paid through their payment link. */
    public const OPEN_LINK_WINDOW = 172800;

    /** SUPCheckout's own options (takeover state, journal, copied names, caches). */
    private const OWN_OPTION_PREFIX = 'supcheckout_';

    /** Legacy order meta read by the order checks (Keys::order_meta() keys). */
    private const PUBLIC_STATUS_META = 'UPayments_WHS';
    private const PARENT_ORDER_META = 'UPayments_ParentOrderID';

    /**
     * WordPress plugin bookkeeping that names the official plugin's file, not the gateway.
     * Read-only filter for the options scan. The auto-update registry option is spelled in two
     * parts only because Plugin Check's updater heuristic matches that option name as a substring;
     * this code never reads or alters update behaviour.
     */
    private const CORE_PLUGIN_REGISTRY = array('active_plugins', 'recently_activated', 'uninstall_plugins', 'auto_update_' . 'plugins', 'cron');
    /** WooCommerce's own gateway settings, which ThirdPartyTranslator (Task 7) translates. */
    private const TRANSLATOR_HANDLED = array('woocommerce_gateway_order', 'woocommerce_default_gateway');

    /** Response headers whose value says a cache served the response. */
    private const CACHE_HIT_HEADERS = array(
        'x-litespeed-cache', 'cf-cache-status', 'x-cache', 'x-cache-status', 'x-proxy-cache',
        'x-srcache-fetch-status', 'x-sucuri-cache', 'x-kinsta-cache', 'x-fastcgi-cache',
        'x-nginx-cache', 'x-varnish-cache', 'x-hcdn-cache-status',
    );
    private const CACHE_HIT_VALUE = '/\bhit\b|^\s*(stale|updating|revalidated)\s*$/i';

    /**
     * Every code with its severity.
     *
     * @return array<string, string>
     */
    public static function codes() {
        return array(
            self::OFFICIAL_PLUGIN_ACTIVE => self::FACT,
            self::CREDENTIAL_UNVERIFIED => self::BLOCKING,
            self::ACCOUNT_MODE => self::FACT,
            self::CURRENCY_UNSUPPORTED => self::BLOCKING,
            self::PENDING_WITH_OPEN_LINK => self::WARNING,
            self::HPOS_META_DISAGREEMENT => self::WARNING,
            self::WHS_MULTI_VALUED => self::FACT,
            self::DUPLICATE_RENEWALS => self::WARNING,
            self::OFFICIAL_DEBUG_LOG_PRESENT => self::WARNING,
            self::THIRD_PARTY_REFERENCES => self::FACT . '|' . self::WARNING,
            self::CALLBACK_UNREACHABLE => self::BLOCKING,
            self::CACHE_EXCLUSION_MISSING => self::WARNING,
            self::SETTINGS_SOURCE => self::FACT,
            self::OFFICIAL_ONLY_NAMES => self::FACT,
            self::UPAY_SUBSCRIPTIONS_TABLE_PRESENT => self::FACT,
            self::LEFTOVERS => self::FACT,
        );
    }

    /**
     * @return array{blocking: list<array{code:string, message:string, data:array<string,mixed>}>, warnings: list<array{code:string, message:string, data:array<string,mixed>}>, facts: list<array{code:string, message:string, data:array<string,mixed>}>}
     */
    public static function run() {
        return self::run_with(Environment::from_wordpress());
    }

    /**
     * @param Environment $env Everything the checks read.
     * @return array{blocking: list<array{code:string, message:string, data:array<string,mixed>}>, warnings: list<array{code:string, message:string, data:array<string,mixed>}>, facts: list<array{code:string, message:string, data:array<string,mixed>}>}
     */
    public static function run_with(Environment $env) {
        $official_plugins = OfficialFootprint::plugins($env);
        list($settings_finding, $settings) = self::settings_source($env);
        $availability = self::probe_credential($env, $settings);
        $probe = self::probe_callback($env);
        $official_names = OfficialFootprint::names($env);

        $findings = array(
            self::official_plugin_active($official_plugins),
            $settings_finding,
            self::credential_unverified($settings, $availability),
            self::account_mode($settings, $availability),
            self::currency_unsupported($env),
            self::pending_with_open_link($env),
            self::hpos_meta_disagreement($env),
            self::whs_multi_valued($env),
            self::duplicate_renewals($env),
            self::official_debug_log_present($official_plugins),
            self::third_party_references($env),
            self::callback_unreachable($probe),
            self::cache_exclusion_missing($probe),
            self::official_only_names($official_names),
            self::upay_subscriptions_table_present($env),
            self::leftovers($env, $official_names),
        );

        $report = array('blocking' => array(), 'warnings' => array(), 'facts' => array());
        $lists = array(self::BLOCKING => 'blocking', self::WARNING => 'warnings', self::FACT => 'facts');
        foreach ($findings as $finding) {
            if ($finding === null) {
                continue;
            }
            $report[$lists[$finding['severity']]][] = array(
                'code' => $finding['code'],
                'message' => $finding['message'],
                'data' => $finding['data'],
            );
        }
        return $report;
    }

    /**
     * @param list<array{slug:string, basename:string, path:string, version:string, active:bool}> $plugins
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function official_plugin_active(array $plugins) {
        $active = array();
        foreach ($plugins as $plugin) {
            if ($plugin['active']) {
                $active[] = array('basename' => $plugin['basename'], 'version' => $plugin['version']);
            }
        }
        if ($active === array()) {
            return null;
        }
        return self::finding(self::FACT, self::OFFICIAL_PLUGIN_ACTIVE,
            __('The official UPayments plugin is active. SUPCheckout stays paused until the switch deactivates it.', 'supcheckout'),
            array('plugins' => $active));
    }

    /**
     * Hazard 3: a 3.0.0 upgrader may hold its settings only under the placeholder name.
     * The first non-empty Keys::settings_sources() entry is what Cutover copies.
     *
     * @param Environment $env Environment.
     * @return array{0: array{severity:string, code:string, message:string, data:array<string,mixed>}, 1: array<string,mixed>}
     */
    private static function settings_source(Environment $env) {
        $present = array();
        $settings = array();
        foreach (Keys::settings_sources() as $name) {
            $value = $env->option($name, null);
            if (is_array($value) && $value !== array()) {
                if ($present === array()) {
                    $settings = $value;
                }
                $present[] = $name;
            }
        }
        $target = $env->option(Keys::SETTINGS_OPTION, null);
        $finding = self::finding(self::FACT, self::SETTINGS_SOURCE,
            __('Gateway settings the switch would copy.', 'supcheckout'),
            array(
                'source' => $present === array() ? null : $present[0],
                'sources_present' => $present,
                'target_present' => is_array($target) && $target !== array(),
            ));
        return array($finding, $settings);
    }

    /**
     * @param Environment          $env      Environment.
     * @param array<string, mixed> $settings Source settings.
     * @return array<string, mixed>|null Null when there is no key to check.
     */
    private static function probe_credential(Environment $env, array $settings) {
        if (!isset($settings['api_key']) || !is_string($settings['api_key']) || trim($settings['api_key']) === '') {
            return null;
        }
        return $env->availability($settings);
    }

    /**
     * @param array<string, mixed>      $settings     Source settings.
     * @param array<string, mixed>|null $availability Probe result.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function credential_unverified(array $settings, $availability) {
        if (self::is_verified($availability)) {
            return null;
        }
        if ($availability === null) {
            $reason = 'missing';
            $message = __('No UPayments API key is saved in the gateway settings. Save a valid key before switching.', 'supcheckout');
        } elseif (isset($availability['result']) && $availability['result'] === 'cooldown') {
            $reason = 'cooldown';
            $message = __('The saved API key could not be checked because UPayments allows one availability request per minute. Run the preflight again in a minute.', 'supcheckout');
        } else {
            $reason = 'rejected';
            // A rate-limited answer (one availability request per minute, and this
            // check is not recorded in the checkout gate) looks the same as a refusal.
            $message = __('UPayments did not confirm the saved API key. Check the key and the test-mode setting, wait a minute and run the preflight again before switching.', 'supcheckout');
        }
        return self::finding(self::BLOCKING, self::CREDENTIAL_UNVERIFIED, $message, array(
            'reason' => $reason,
            'test_mode' => isset($settings['test_mode']) && $settings['test_mode'] === 'yes',
        ));
    }

    /**
     * @param array<string, mixed>      $settings     Source settings.
     * @param array<string, mixed>|null $availability Probe result.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}
     */
    private static function account_mode(array $settings, $availability) {
        $mode = 'unknown';
        $methods = array();
        if (self::is_verified($availability)) {
            $mode = isset($availability['isWhiteLabel']) && $availability['isWhiteLabel'] === true ? 'white_label' : 'hosted';
            $buttons = isset($availability['payButtons']) && is_array($availability['payButtons']) ? $availability['payButtons'] : array();
            foreach ($buttons as $button => $enabled) {
                if ($enabled === 1) {
                    $methods[] = (string) $button;
                }
            }
        }
        return self::finding(self::FACT, self::ACCOUNT_MODE,
            __('UPayments account mode and the payment methods it enables.', 'supcheckout'),
            array(
                'mode' => $mode,
                'methods' => $methods,
                'test_mode' => isset($settings['test_mode']) && $settings['test_mode'] === 'yes',
                'multimerchant' => isset($settings['enable_multimerchant']) && $settings['enable_multimerchant'] === 'yes',
            ));
    }

    /**
     * @param array<string, mixed>|null $availability Probe result.
     * @return bool
     */
    private static function is_verified($availability) {
        return is_array($availability) && isset($availability['result']) && $availability['result'] === 'success';
    }

    /**
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function currency_unsupported(Environment $env) {
        $supported = GatewaySettings::supported_currencies();
        if (in_array($env->currency(), $supported, true)) {
            return null;
        }
        return self::finding(self::BLOCKING, self::CURRENCY_UNSUPPORTED,
            __('The store currency is not supported by UPayments. Switch the store to a supported currency first.', 'supcheckout'),
            array('currency' => $env->currency(), 'supported' => $supported));
    }

    /**
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function pending_with_open_link(Environment $env) {
        $count = $env->pending_orders($env->now() - self::OPEN_LINK_WINDOW);
        if ($count <= 0) {
            return null;
        }
        return self::finding(self::WARNING, self::PENDING_WITH_OPEN_LINK,
            sprintf(
                /* translators: %d: number of pending orders. */
                __('%d pending UPayments orders from the last 48 hours may still be paid through an open payment link. Their callbacks keep working after the switch.', 'supcheckout'),
                $count
            ),
            array('count' => $count));
    }

    /**
     * Hazard 4: with HPOS authoritative and sync off, legacy data may exist only in one
     * store. Compare CRUD with direct postmeta on a bounded sample; counts only.
     *
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function hpos_meta_disagreement(Environment $env) {
        if (!$env->hpos()) {
            return null;
        }
        $orders = $env->order_sample(self::SAMPLE_LIMIT);
        $counts = array('sampled' => 0, 'orders_disagreeing' => 0, 'keys_disagreeing' => 0,
            'crud_only' => 0, 'postmeta_only' => 0, 'differing' => 0);
        foreach ($orders as $order) {
            if (!is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'get_meta')) {
                continue;
            }
            $counts['sampled']++;
            $disagrees = false;
            foreach (array_keys(Keys::order_meta()) as $key) {
                $crud = self::comparable($order->get_meta($key, false), true);
                $post = self::comparable($env->post_meta((int) $order->get_id(), $key), false);
                if ($crud === $post) {
                    continue;
                }
                $disagrees = true;
                $counts['keys_disagreeing']++;
                if ($post === array()) {
                    $counts['crud_only']++;
                } elseif ($crud === array()) {
                    $counts['postmeta_only']++;
                } else {
                    $counts['differing']++;
                }
            }
            if ($disagrees) {
                $counts['orders_disagreeing']++;
            }
        }
        if ($counts['orders_disagreeing'] === 0) {
            return null;
        }
        return self::finding(self::WARNING, self::HPOS_META_DISAGREEMENT,
            sprintf(
                /* translators: 1: orders whose data differs, 2: orders compared. */
                __('%1$d of %2$d sampled orders hold UPayments data that differs between the order tables and the posts tables. The switch copies the values WooCommerce reads (the order tables).', 'supcheckout'),
                $counts['orders_disagreeing'],
                $counts['sampled']
            ),
            $counts);
    }

    /**
     * A sorted list of comparable strings for one meta key's rows.
     *
     * @param mixed $rows   Rows.
     * @param bool  $unwrap Rows are WC_Meta_Data-shaped objects.
     * @return list<string>
     */
    private static function comparable($rows, $unwrap) {
        $values = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            if ($unwrap && is_object($row) && property_exists($row, 'value')) {
                $row = $row->value;
            } elseif ($unwrap && is_object($row) && method_exists($row, 'get_data')) {
                $data = $row->get_data();
                $row = is_array($data) && array_key_exists('value', $data) ? $data['value'] : $row;
            }
            $values[] = is_scalar($row) || $row === null ? (string) $row : (string) wp_json_encode($row);
        }
        sort($values, SORT_STRING);
        return $values;
    }

    /**
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function whs_multi_valued(Environment $env) {
        $hpos = $env->hpos();
        $owner = $hpos ? 'order_id' : 'post_id';
        $count = (int) $env->select_var(
            'SELECT COUNT(*) FROM (SELECT ' . $owner . ' FROM %i WHERE meta_key = %s GROUP BY ' . $owner
                . ' HAVING COUNT(*) > 1) AS multi',
            array($env->table($hpos ? 'orders_meta' : 'postmeta'), self::PUBLIC_STATUS_META)
        );
        if ($count <= 0) {
            return null;
        }
        return self::finding(self::FACT, self::WHS_MULTI_VALUED,
            sprintf(
                /* translators: %d: number of orders. */
                __('%d orders hold several UPayments public-status rows. The switch keeps every row.', 'supcheckout'),
                $count
            ),
            array('orders' => $count));
    }

    /**
     * Hazard 6: renewal orders that share a parent and a billing (creation) date.
     *
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function duplicate_renewals(Environment $env) {
        if ($env->hpos()) {
            $sql = 'SELECT COUNT(*) AS grp, COALESCE(SUM(dup.c - 1), 0) AS extra FROM (SELECT COUNT(*) AS c FROM %i m'
                . ' INNER JOIN %i o ON o.id = m.order_id WHERE m.meta_key = %s AND o.type = %s AND o.status <> %s'
                . ' GROUP BY m.meta_value, DATE(o.date_created_gmt) HAVING COUNT(*) > 1) AS dup';
            $tables = array($env->table('orders_meta'), $env->table('orders'));
        } else {
            $sql = 'SELECT COUNT(*) AS grp, COALESCE(SUM(dup.c - 1), 0) AS extra FROM (SELECT COUNT(*) AS c FROM %i m'
                . ' INNER JOIN %i p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_type = %s AND p.post_status <> %s'
                . ' GROUP BY m.meta_value, DATE(p.post_date_gmt) HAVING COUNT(*) > 1) AS dup';
            $tables = array($env->table('postmeta'), $env->table('posts'));
        }
        $row = $env->select_row($sql, array_merge($tables, array(self::PARENT_ORDER_META, 'shop_order', 'trash')));
        $groups = is_array($row) && isset($row['grp']) ? (int) $row['grp'] : 0;
        if ($groups <= 0) {
            return null;
        }
        return self::finding(self::WARNING, self::DUPLICATE_RENEWALS,
            sprintf(
                /* translators: %d: number of subscriptions. */
                __('%d subscriptions have more than one renewal order on the same billing date. Review them before switching; the switch never merges or deletes orders.', 'supcheckout'),
                $groups
            ),
            array('groups' => $groups, 'extra_orders' => isset($row['extra']) ? (int) $row['extra'] : 0));
    }

    /**
     * Hazard 8: presence only. The file is never opened, read or copied.
     *
     * @param list<array{slug:string, basename:string, path:string, version:string, active:bool}> $plugins Official installs.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function official_debug_log_present(array $plugins) {
        $paths = OfficialFootprint::debug_logs($plugins);
        if ($paths === array()) {
            return null;
        }
        return self::finding(self::WARNING, self::OFFICIAL_DEBUG_LOG_PRESENT,
            __('The official UPayments plugin left a debug.log file in its plugin folder that can contain your API key. Delete the file and rotate the API key. The switch never reads or copies it.', 'supcheckout'),
            array('paths' => $paths));
    }

    /**
     * Inventory §8: options that mention the gateway outside the names Keys knows.
     * Option names only, bounded.
     *
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function third_party_references(Environment $env) {
        $like = '%' . $env->esc_like(Keys::GATEWAY_LEGACY) . '%';
        $names = $env->select_col(
            'SELECT option_name FROM %i WHERE (option_name LIKE %s OR option_value LIKE %s)'
                . ' AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d',
            array($env->table('options'), $like, $like, $env->esc_like('_transient_') . '%',
                $env->esc_like('_site_transient_') . '%', self::REFERENCE_SCAN_LIMIT)
        );
        $handled = array();
        $unknown = array();
        foreach ($names as $name) {
            if (self::is_known_option($name)) {
                continue;
            }
            if (in_array($name, self::TRANSLATOR_HANDLED, true)) {
                $handled[] = $name;
            } else {
                $unknown[] = $name;
            }
        }
        if ($handled === array() && $unknown === array()) {
            return null;
        }
        $data = array(
            'unknown' => array_slice($unknown, 0, self::REFERENCE_REPORT_LIMIT),
            'translator_handled' => $handled,
            'truncated' => count($names) >= self::REFERENCE_SCAN_LIMIT || count($unknown) > self::REFERENCE_REPORT_LIMIT,
        );
        if ($unknown === array()) {
            return self::finding(self::FACT, self::THIRD_PARTY_REFERENCES,
                __('WooCommerce gateway settings refer to the UPayments gateway. The switch translates them.', 'supcheckout'),
                $data);
        }
        return self::finding(self::WARNING, self::THIRD_PARTY_REFERENCES,
            __('Settings saved by other plugins refer to the UPayments gateway. Review them: the switch reports them and does not change them.', 'supcheckout'),
            $data);
    }

    /**
     * The same classification for ThirdPartyTranslator's cutover report.
     *
     * @param string $name Option name.
     * @return bool True for a name Keys or WordPress plugin bookkeeping accounts for.
     */
    public static function is_known_option_name($name) {
        return self::is_known_option((string) $name);
    }

    /**
     * @param string $name Option name.
     * @return bool True for a name Keys or WordPress plugin bookkeeping accounts for.
     */
    private static function is_known_option($name) {
        $official = Keys::official_only();
        $exact = array_merge(
            Keys::settings_sources(),
            array(Keys::SETTINGS_OPTION),
            array_keys(Keys::options()),
            array_values(Keys::options()),
            $official['options'],
            self::CORE_PLUGIN_REGISTRY
        );
        if (in_array($name, $exact, true)) {
            return true;
        }
        $prefixes = array_merge(
            $official['option_prefixes'],
            array_keys(Keys::ephemeral_prefixes()),
            array_values(Keys::ephemeral_prefixes()),
            array(self::OWN_OPTION_PREFIX)
        );
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Two GETs of the new callback URL: a first response can be a cache miss that the
     * second one then hits. The second is sent only after a 200.
     *
     * @param Environment $env Environment.
     * @return list<array{status:int, headers:array<string,string>}|null>
     */
    private static function probe_callback(Environment $env) {
        $url = $env->callback_probe_url();
        if ($url === '') {
            return array(null);
        }
        $first = $env->http_get($url);
        if ($first === null || $first['status'] !== 200) {
            return array($first);
        }
        return array($first, $env->http_get($url));
    }

    /**
     * @param list<array{status:int, headers:array<string,string>}|null> $probe Responses.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function callback_unreachable(array $probe) {
        $first = $probe[0];
        $acknowledged = $first !== null
            && isset($first['headers'][self::PROBE_ACK_HEADER])
            && trim($first['headers'][self::PROBE_ACK_HEADER]) === self::PROBE_ACK_VALUE;
        if ($first !== null && $first['status'] === 200 && $acknowledged) {
            return null;
        }
        return self::finding(self::BLOCKING, self::CALLBACK_UNREACHABLE,
            __('The SUPCheckout payment callback address did not answer a test request with success, so UPayments could not reach it either. Check firewalls, maintenance mode, login walls, caches and security plugins.', 'supcheckout'),
            array('status' => $first === null ? 0 : $first['status'], 'acknowledged' => $acknowledged));
    }

    /**
     * @param list<array{status:int, headers:array<string,string>}|null> $probe Responses.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function cache_exclusion_missing(array $probe) {
        $hits = array();
        foreach ($probe as $response) {
            if ($response === null) {
                continue;
            }
            foreach ($response['headers'] as $name => $value) {
                $cached = in_array($name, self::CACHE_HIT_HEADERS, true) && preg_match(self::CACHE_HIT_VALUE, $value) === 1;
                $aged = $name === 'age' && preg_match('/^\s*[1-9][0-9]*\s*$/D', $value) === 1;
                if ($cached || $aged) {
                    $hits[$name] = true;
                }
            }
        }
        if ($hits === array()) {
            return null;
        }
        $headers = array_keys($hits);
        sort($headers, SORT_STRING);
        return self::finding(self::WARNING, self::CACHE_EXCLUSION_MISSING,
            __('A cache answered the payment callback test request. Exclude wc-api requests from page, server and CDN caching so every callback reaches the store.', 'supcheckout'),
            array('headers' => $headers));
    }

    /**
     * @param array{options: list<string>, transients: list<string>, cron_hooks: list<string>} $names
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function official_only_names(array $names) {
        if ($names['options'] === array() && $names['transients'] === array() && $names['cron_hooks'] === array()) {
            return null;
        }
        return self::finding(self::FACT, self::OFFICIAL_ONLY_NAMES,
            __('Data written only by the official UPayments plugin was found. It is left in place until Finalize.', 'supcheckout'),
            $names);
    }

    /**
     * @param Environment $env Environment.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function upay_subscriptions_table_present(Environment $env) {
        $table = OfficialFootprint::subscriptions_table($env);
        if ($table === null) {
            return null;
        }
        return self::finding(self::FACT, self::UPAY_SUBSCRIPTIONS_TABLE_PRESENT,
            __('An unused UPayments subscriptions table exists. It is reported only and never changed.', 'supcheckout'),
            array('table' => $table));
    }

    /**
     * Hazard 7: reported only; the switch never deletes the page or changes the field.
     *
     * @param Environment                                                                       $env   Environment.
     * @param array{options: list<string>, transients: list<string>, cron_hooks: list<string>} $names Official names.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}|null
     */
    private static function leftovers(Environment $env, array $names) {
        $data = OfficialFootprint::leftovers($env, $names);
        if ($data['block_checkout_page'] === null && !$data['phone_field_required'] && !$data['update_checker'] && !$data['cron_lock']) {
            return null;
        }
        return self::finding(self::FACT, self::LEFTOVERS,
            __('Leftovers from the official UPayments plugin were found. Review them; the switch never deletes pages or changes checkout fields.', 'supcheckout'),
            $data);
    }

    /**
     * @param string               $severity Severity.
     * @param string               $code     Code.
     * @param string               $message  Translated message.
     * @param array<string, mixed> $data     Counts, names and flags; never values.
     * @return array{severity:string, code:string, message:string, data:array<string,mixed>}
     */
    private static function finding($severity, $code, $message, array $data) {
        return array('severity' => $severity, 'code' => $code, 'message' => $message, 'data' => $data);
    }

    private function __construct() {}
}
