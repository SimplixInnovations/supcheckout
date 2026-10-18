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
    /** State field recording the transition whose purge finished (see purge_once()). */
    public const MARKER = 'caches_purged';
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
     * Purge once per transition: when the state does not yet record this transition's
     * purge, purge through $system and record it. Every engine calls it right after its
     * transition, and again from each post-transition resume path, so a worker killed
     * between the transition and the purge still purges exactly once on the rerun.
     * Never throws; a failed purge is reported (not retried) and recorded as done.
     *
     * @param CutoverSystem        $system  System.
     * @param array<string, mixed> $state   TakeoverState::get(), read after the transition.
     * @param bool                 $aborted True for an abort (verified → preflight_ok).
     * @return array{ran:list<string>, manual:list<string>, failed:list<string>}|null The report, or null when already recorded.
     */
    public static function purge_once(CutoverSystem $system, array $state, $aborted = false) {
        $key = self::transition_key($state, (bool) $aborted);
        if ($key === null || (isset($state[self::MARKER]) && $state[self::MARKER] === $key)) {
            return null;
        }
        $report = self::purge_through($system);
        try {
            TakeoverState::patch(array(self::MARKER => $key));
        } catch (\Throwable $e) {
            // Unrecorded: the next resume purges again, which is harmless.
            unset($e);
        }
        return $report;
    }

    /**
     * Identifies the transition a purge belongs to (status plus its own timestamp), so a
     * marker left by an earlier transition never satisfies a later one.
     *
     * @param array<string, mixed> $state   State.
     * @param bool                 $aborted Abort mode.
     * @return string|null Null for a status that has no post-transition purge.
     */
    private static function transition_key(array $state, $aborted) {
        $status = isset($state['status']) ? (string) $state['status'] : '';
        $int = static function ($field) use ($state) {
            return isset($state[$field]) && is_int($state[$field]) ? $state[$field] : 0;
        };
        switch ($status) {
            case TakeoverState::CUT_OVER:
                return 'cut_over@' . $int('cut_over_at');
            case TakeoverState::FINALIZED:
                return 'finalized@' . $int('updated_at');
            case TakeoverState::ROLLED_BACK:
                return 'rolled_back@' . $int('rolled_back_at');
            case TakeoverState::PREFLIGHT_OK:
                return $aborted ? 'aborted@' . $int('updated_at') : 'rolled_back@' . $int('rolled_back_at');
        }
        return null;
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
