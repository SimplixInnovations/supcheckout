<?php
/**
 * Real WordPress certification of the takeover's cache purge (0c Task 2).
 *
 * Runs in the E3 cache-optimizer coexistence cells with the real, pinned plugin active.
 * SUPCHECKOUT_CACHE_PLUGIN names the cell (the plugin slug). The purge must reach the
 * plugin's own public entry point, report it by name and never fail.
 */

require_once __DIR__ . '/bootstrap.php';

use Simplixi\SUPCheckout\Takeover\CachePurger;

$supcheckout_cache_plugin = (string) getenv('SUPCHECKOUT_CACHE_PLUGIN');
supcheckout_cert_assert('' !== $supcheckout_cache_plugin, 'the E3 cell names its cache plugin in SUPCHECKOUT_CACHE_PLUGIN');
supcheckout_cert_assert(
    in_array($supcheckout_cache_plugin, array('litespeed-cache', 'w3-total-cache', 'breeze', 'sg-cachepress'), true),
    'the cache plugin cell is one this test knows: ' . $supcheckout_cache_plugin
);

$supcheckout_detected = CachePurger::detected();
supcheckout_cert_note('cache layers detected: ' . implode(',', $supcheckout_detected));

$supcheckout_report = CachePurger::purge_all();
supcheckout_cert_note('cache purge report: ' . wp_json_encode($supcheckout_report));

supcheckout_cert_assert(array() === $supcheckout_report['failed'], 'no cache layer reports a failed purge');
supcheckout_cert_assert(in_array('object_cache', $supcheckout_report['ran'], true), 'the object cache is always flushed');

if ('litespeed-cache' === $supcheckout_cache_plugin) {
    supcheckout_cert_assert(in_array('litespeed', $supcheckout_report['ran'], true), 'LiteSpeed Cache is purged through litespeed_purge_all');
    supcheckout_cert_assert(did_action('litespeed_purged_all') >= 1, 'LiteSpeed Cache confirms the purge with litespeed_purged_all');
}

if ('w3-total-cache' === $supcheckout_cache_plugin) {
    supcheckout_cert_assert(in_array('w3tc', $supcheckout_report['ran'], true), 'W3 Total Cache is purged through w3tc_flush_all');
}

if ('breeze' === $supcheckout_cache_plugin) {
    supcheckout_cert_assert(array('breeze', 'object_cache') === $supcheckout_report['ran'], 'Breeze is purged through breeze_clear_all_cache, then the object cache');
}

if ('sg-cachepress' === $supcheckout_cache_plugin) {
    // Off a SiteGround host the function only purges what that host enables, so a no-op is
    // still a clean run.
    supcheckout_cert_assert(array('sg_optimizer', 'object_cache') === $supcheckout_report['ran'], 'SiteGround Speed Optimizer is purged through sg_cachepress_purge_everything, then the object cache');
}

supcheckout_cert_note('real cache purge certification complete');
