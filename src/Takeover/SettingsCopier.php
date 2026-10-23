<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Gateway settings and SUPCheckout-owned options (Sub-project 0b Task 7, Cutover step 1).
 *
 * - Settings: the first non-empty Keys::settings_sources() option is copied into
 *   Keys::SETTINGS_OPTION (woocommerce_supcheckout_settings), which WooCommerce reads once
 *   the gateway id is `supcheckout`.
 * - Keys::options(): each present legacy option is copied verbatim (the identical value,
 *   so the token-identity secret keeps every H12 provenance hash valid), non-autoloaded.
 *
 * Copy, never move: the sources stay untouched and are the restore source of each write.
 * Every write is journaled first (Journal::record() keeps only fingerprints, so neither
 * the API key in the settings nor the secret ever reaches the journal).
 *
 * All or nothing: the whole plan is computed first, and nothing is written while any
 * target is a mismatch. Before cutover the legacy names are authoritative (reads are
 * legacy-only), so:
 * - an absent target is created, an identical one skipped;
 * - a differing target is refreshed only when the journal shows the takeover wrote its
 *   current value (an earlier, interrupted cutover); anything else is a foreign value,
 *   reported by name and kept;
 * - a new option present while its legacy option is absent is reported: after cutover it
 *   would become visible although nothing pre-cutover reads it.
 * No settings source at all is reported as `settings_missing` (fail closed).
 *
 * foreign_targets() runs the same plan read-only, so Preflight can refuse a switch whose
 * Cutover would refuse the targets (A5-04: a `settings_diverged` rollback keeps the new
 * settings) before the copy runs and before the official plugin is deactivated.
 */
final class SettingsCopier {
    public const MISSING_SETTINGS = 'settings_missing';
    public const FOREIGN_SETTINGS = 'settings_foreign';
    private const TARGET = 'option';

    /**
     * @return array{ok:bool, written:list<string>, mismatches:list<string>}
     * @throws \RuntimeException When a journal entry cannot be stored (nothing more is written).
     */
    public static function copy() {
        $plan = self::plan(self::wordpress_reader());
        if ($plan['mismatches'] !== array()) {
            return array('ok' => false, 'written' => array(), 'mismatches' => $plan['mismatches']);
        }
        $written = array();
        $failed = array();
        foreach ($plan['writes'] as $write) {
            Journal::record(self::TARGET, $write['name'], $write['before'], $write['value'], 'option:' . $write['source']);
            if ($write['exists']) {
                update_option($write['name'], $write['value']);
            } else {
                add_option($write['name'], $write['value'], '', $write['autoload']);
            }
            if (get_option($write['name'], self::missing()) !== $write['value']) {
                $failed[] = $write['name'];
                continue;
            }
            $written[] = $write['name'];
        }
        return array('ok' => $failed === array(), 'written' => $written, 'mismatches' => $failed);
    }

    /**
     * Every target equals its source (and an absent legacy option has no new copy).
     *
     * @return array{ok:bool, mismatches:list<string>}
     */
    public static function verify() {
        $mismatches = array();
        $source = self::settings_source(self::wordpress_reader());
        if ($source === null) {
            $mismatches[] = self::MISSING_SETTINGS;
        } elseif (get_option(Keys::SETTINGS_OPTION, self::missing()) !== $source['value']) {
            $mismatches[] = Keys::SETTINGS_OPTION;
        }
        foreach (Keys::options() as $legacy => $new_name) {
            $legacy_value = get_option($legacy, self::missing());
            if (get_option($new_name, self::missing()) !== $legacy_value) {
                $mismatches[] = $new_name;
            }
        }
        return array('ok' => $mismatches === array(), 'mismatches' => $mismatches);
    }

    /**
     * The targets copy() would refuse to write, by option name (the gateway settings as
     * Keys::SETTINGS_OPTION). Writes nothing. A missing settings source is not a target:
     * Preflight reports it as credential_unverified.
     *
     * @param callable|null $read_option function (string $name, mixed $default): mixed; get_option() by default.
     *                                   The journal is read through Journal::entries().
     * @return list<string>
     * @throws \RuntimeException When the journal is unreadable.
     */
    public static function foreign_targets($read_option = null) {
        $plan = self::plan($read_option === null ? self::wordpress_reader() : $read_option);
        $targets = array();
        foreach ($plan['mismatches'] as $mismatch) {
            if ($mismatch === self::FOREIGN_SETTINGS) {
                $targets[] = Keys::SETTINGS_OPTION;
            } elseif ($mismatch !== self::MISSING_SETTINGS) {
                $targets[] = $mismatch;
            }
        }
        return $targets;
    }

    /**
     * @param callable $read function (string $name, mixed $default): mixed.
     * @return array{writes:list<array{name:string, source:string, value:mixed, before:mixed, exists:bool, autoload:string}>, mismatches:list<string>}
     * @throws \RuntimeException When the journal is unreadable.
     */
    private static function plan($read) {
        $writes = array();
        $mismatches = array();
        $source = self::settings_source($read);
        if ($source === null) {
            $mismatches[] = self::MISSING_SETTINGS;
        } else {
            $step = self::step($read, Keys::SETTINGS_OPTION, $source['name'], $source['value'], 'yes');
            if ($step === false) {
                $mismatches[] = self::FOREIGN_SETTINGS;
            } elseif ($step !== null) {
                $writes[] = $step;
            }
        }
        foreach (Keys::options() as $legacy => $new_name) {
            $legacy_value = call_user_func($read, $legacy, self::missing());
            if ($legacy_value === self::missing()) {
                if (call_user_func($read, $new_name, self::missing()) !== self::missing()) {
                    $mismatches[] = $new_name;
                }
                continue;
            }
            $step = self::step($read, $new_name, $legacy, $legacy_value, 'no');
            if ($step === false) {
                $mismatches[] = $new_name;
            } elseif ($step !== null) {
                $writes[] = $step;
            }
        }
        return array('writes' => $writes, 'mismatches' => $mismatches);
    }

    /**
     * @param callable $read     Option reader.
     * @param string   $name     Target option.
     * @param string   $source   Source option.
     * @param mixed    $value    Source value.
     * @param string   $autoload Autoload flag for a created target.
     * @return array{name:string, source:string, value:mixed, before:mixed, exists:bool, autoload:string}|null|false
     *         The write, null when already equal, false for a foreign target.
     */
    private static function step($read, $name, $source, $value, $autoload) {
        $current = call_user_func($read, $name, self::missing());
        if ($current === $value) {
            return null;
        }
        $exists = $current !== self::missing();
        if ($exists && !Journal::wrote_current(self::TARGET, $name, $current)) {
            return false;
        }
        return array('name' => $name, 'source' => $source, 'value' => $value, 'before' => $exists ? $current : null, 'exists' => $exists, 'autoload' => $autoload);
    }

    /**
     * @param callable $read Option reader.
     * @return array{name:string, value:array<mixed>}|null The first non-empty settings source.
     */
    private static function settings_source($read) {
        foreach (Keys::settings_sources() as $name) {
            $value = call_user_func($read, $name, null);
            if (is_array($value) && $value !== array()) {
                return array('name' => $name, 'value' => $value);
            }
        }
        return null;
    }

    /** @return callable get_option(). */
    private static function wordpress_reader() {
        return static function ($name, $default) {
            return get_option($name, $default);
        };
    }

    /** @var \stdClass|null */
    private static $missing = null;

    /** @return \stdClass Private "no stored value" sentinel. */
    private static function missing() {
        if (self::$missing === null) {
            self::$missing = new \stdClass();
        }
        return self::$missing;
    }

    private function __construct() {}
}
