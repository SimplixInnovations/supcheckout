<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * Purges the page-cache layers a store switch leaves stale (Sub-project 0c Task 2; the
 * staging rehearsal had to purge LiteSpeed by hand after every switch). Runs at the
 * transition's `caches_flushed` step, after the compare-and-swap: it only calls the
 * public purge entry points each vendor documents, catches everything and reports the
 * outcome by layer name, so a failing or absent cache plugin can never fail a switch,
 * rollback or finalize that already happened. It never rethrows and never reports a
 * message, argument or value, only names.
 *
 * Layers, in order: `litespeed`, `wp_rocket`, `w3tc`, `wp_super_cache`, `breeze`,
 * `sg_optimizer`, `cloudflare` (reported `manual`: the official plugin has no public
 * purge API, so it is detected and never called), then `object_cache` (always).
 */
final class CachePurger {
    /** Reason handed to LiteSpeed's purge action. */
    public const REASON = 'SUPCheckout takeover';
    /** The official Cloudflare plugin's hooks class; detected, never autoloaded. */
    private const CLOUDFLARE_CLASS = 'Cloudflare\\APO\\WordPress\\Hooks';

    /**
     * Purge every layer that is present, then the object cache.
     *
     * @return array{ran:list<string>, manual:list<string>, failed:list<string>}
     */
    public static function purge_all() {
        $report = array('ran' => array(), 'manual' => array(), 'failed' => array());
        foreach (self::detected() as $name) {
            if ($name === 'cloudflare') {
                $report['manual'][] = $name;
                continue;
            }
            $report[self::attempt($name) ? 'ran' : 'failed'][] = $name;
        }
        $report[self::attempt('object_cache') ? 'ran' : 'failed'][] = 'object_cache';
        return $report;
    }

    /**
     * The cache layers present on this site (the names purge_all() uses, without
     * `object_cache`), in purge order.
     *
     * @return list<string>
     */
    public static function detected() {
        $present = array(
            'litespeed' => self::has_listener('litespeed_purge_all'),
            'wp_rocket' => function_exists('rocket_clean_domain'),
            'w3tc' => function_exists('w3tc_flush_all'),
            'wp_super_cache' => function_exists('wp_cache_clear_cache'),
            'breeze' => self::has_listener('breeze_clear_all_cache'),
            'sg_optimizer' => function_exists('sg_cachepress_purge_everything'),
            'cloudflare' => class_exists(self::CLOUDFLARE_CLASS, false),
        );
        return array_keys(array_filter($present));
    }

    /**
     * Purge through a CutoverSystem; a system that throws is reported, never rethrown.
     *
     * @param CutoverSystem $system System.
     * @return array{ran:list<string>, manual:list<string>, failed:list<string>}
     */
    public static function purge_through(CutoverSystem $system) {
        try {
            return $system->purge_caches();
        } catch (\Throwable $e) {
            return array('ran' => array(), 'manual' => array(), 'failed' => array('system'));
        }
    }

    /**
     * @param string $hook Action name.
     * @return bool
     */
    private static function has_listener($hook) {
        return function_exists('has_action') && (bool) has_action($hook);
    }

    /**
     * @param string $name Layer name.
     * @return bool True when the vendor call returned without throwing.
     */
    private static function attempt($name) {
        try {
            self::call($name);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The vendor calls, verified against each plugin's own source (see the 0c plan).
     *
     * @param string $name Layer name.
     * @return void
     */
    private static function call($name) {
        switch ($name) {
            case 'litespeed':
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's documented public purge API (docs.litespeedtech.com/lscache/lscwp/api/).
                do_action('litespeed_purge_all', self::REASON);
                return;
            case 'wp_rocket':
                // An empty language purges every language URI (WPML included).
                rocket_clean_domain();
                return;
            case 'w3tc':
                w3tc_flush_all();
                return;
            case 'wp_super_cache':
                // 0 clears everything; a blog ID only that blog (multisite).
                wp_cache_clear_cache(is_multisite() ? get_current_blog_id() : 0);
                return;
            case 'breeze':
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's public clear-all-cache action, registered by Breeze_Admin and recommended by its author.
                do_action('breeze_clear_all_cache');
                return;
            case 'sg_optimizer':
                sg_cachepress_purge_everything();
                return;
            case 'object_cache':
                wp_cache_flush();
                return;
        }
    }
}
