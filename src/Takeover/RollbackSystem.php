<?php

namespace Simplixi\SUPCheckout\Takeover;

defined('ABSPATH') || exit;

/**
 * What Rollback (Sub-project 0b Task 10) needs beyond CutoverSystem: removing a WP-Cron
 * event and reactivating the official plugin. WordPressCutoverSystem binds it to WordPress.
 */
interface RollbackSystem extends CutoverSystem {
    /**
     * @param int          $timestamp Event time.
     * @param string       $hook      Hook.
     * @param array<mixed> $args      Arguments.
     * @return bool True when the event is gone afterwards.
     */
    public function unschedule_event($timestamp, $hook, array $args);

    /**
     * @return list<string> Basenames of every installed official plugin (another plugin
     *                      directory's `UPayments.php`), active or not.
     */
    public function installed_official_plugins();

    /**
     * Activate a plugin on this site only (never network-wide).
     *
     * @param string $basename Plugin basename.
     * @return bool True when it is active afterwards.
     */
    public function activate_plugin($basename);
}
