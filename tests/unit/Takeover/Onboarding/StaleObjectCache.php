<?php

namespace Simplixi\SUPCheckout\Tests\Takeover\Onboarding;

/**
 * A stale persistent object cache on the in-memory option store: get_option($name) answers
 * the planted stale value until the product drops the option's cache entry
 * (OrderLock::flush_option_cache() → wp_cache_delete($name, 'options')). After that the
 * cache is refilled from the database, so every later read sees the stored value.
 *
 * This is the cross-process case the Action Scheduler driver and a REST poll hit: another
 * process wrote the option, and this process still holds the old cached copy.
 */
trait StaleObjectCache {
    /**
     * @param string $name  Option name.
     * @param mixed  $stale Cached value; null means the cache remembers the option as absent.
     * @return void
     */
    private static function plant_stale_cache_entry(string $name, $stale): void {
        $GLOBALS['supcheckout_test_cache_deletes'] = array();
        $GLOBALS['supcheckout_test_get_option_filter'] = static function ($option, $value, $default) use ($name, $stale) {
            if ($option !== $name || in_array(array($name, 'options'), $GLOBALS['supcheckout_test_cache_deletes'], true)) {
                return $value;
            }
            return $stale === null ? $default : $stale;
        };
    }
}
